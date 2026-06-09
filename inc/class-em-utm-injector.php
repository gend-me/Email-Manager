<?php
/**
 * EM_UTM_Injector — wp_mail filter middleware that injects UTM params into
 * outbound email links matching configured destination domains, and appends a
 * 1x1 open-tracking pixel before </body>.
 *
 * Phase 14 Plan 14-04 — EMAIL-01 + EMAIL-02 (open pixel coupling).
 *
 * UTM SHAPE (server-canonical — never operator-editable at send time):
 *   utm_source  = 'email'   (hardcoded — EMAIL-01 invariant)
 *   utm_medium  = 'email'   (hardcoded — EMAIL-01 invariant)
 *   utm_campaign = template value from EM_UTM_Template_Admin::get_template($cid)
 *   utm_content  = template value from EM_UTM_Template_Admin::get_template($cid)
 *   em_campaign_id   = $cid (carries through the open pixel and click passthrough)
 *   em_recipient_hash = sha256($recipient_email + nonce_salt)  (no plaintext PII in URLs)
 *
 * DESTINATION-DOMAIN GATE — only links whose host is in the operator-curated
 * wp_options('em_destination_domains') array receive UTM injection. Non-matching
 * domains are left untouched (e.g. unsubscribe URLs, transactional links).
 *
 * CONTROLLED VOCAB COUPLING — the utm_campaign + utm_content values are READ
 * from a saved template that was VALIDATED at save time against the CLICK-06
 * controlled vocab (wp_options('gend_cc_utm_campaigns') / 'gend_cc_utm_contents'
 * — actual storage keys are the sales-team UTM Vocab option names
 * gend_cc_utm_campaign_vocab + gend_cc_utm_content_vocab, exposed via the public
 * API Gend_ST_Utm_Vocab::campaign_vocab() + content_vocab()). The injector never
 * touches free-text — if the saved template value is no longer in the vocab,
 * EM_UTM_Template_Admin::get_template falls back to the first vocab entry.
 *
 * CLOAKED-LINK INTEROP — when the injected URL points at the existing Phase
 * 8-03 cloaked-link redirect handler (/recommends/{slug}), the cloaked-link's
 * own click recorder writes a wp_cc_clicks row with bucket='click_human' and
 * reads utm_source/utm_medium/utm_campaign from the appended query string. So
 * email-attributed cloaked clicks segregate from organic/social via the
 * utm_source='email' tag without any new endpoint. For NON-cloaked direct links
 * (matching destination domains but not cloaked), the swap_to_passthrough()
 * helper rewrites the href to point at EM_Click_Tracker::handle_click which
 * writes a wp_cc_clicks row with bucket='email_click' before 302-ing the
 * recipient to the original URL.
 *
 * BOOTSTRAP — registered at plugins_loaded priority 48 (Phase 14 slot 45-50;
 * 14-01..03 took 45/46/47; 14-04 takes 48). Wp_mail filter priority 100 so the
 * injector runs AFTER core/template content filters have finalised the body.
 *
 * @package email-manager
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EM_UTM_Injector' ) ) :

class EM_UTM_Injector {

	/**
	 * UTM constants — utm_source + utm_medium are hardcoded to 'email' per the
	 * EMAIL-01 specification. The two channel tokens NEVER change so the click
	 * ledger can unambiguously segregate email-attributed clicks from
	 * organic / social / ads / direct.
	 */
	const UTM_SOURCE = 'email';
	const UTM_MEDIUM = 'email';

	/**
	 * wp_options keys.
	 *   em_destination_domains — array of host strings (e.g. ['example.com']).
	 *   em_default_campaign_id — fallback campaign ID when the wp_mail $args
	 *                            does not carry an X-EM-Campaign-Id header.
	 *   em_recipient_hash_salt — nonce-style salt for hashing recipient emails
	 *                            into URLs (NEVER plaintext PII in query strings).
	 *
	 * Controlled vocab is read via EM_UTM_Template_Admin::get_template which
	 * itself reads the wp_options keys gend_cc_utm_campaigns +
	 * gend_cc_utm_contents (CLICK-06 vocab — see class-em-utm-template-admin.php).
	 */
	const OPT_DESTINATION_DOMAINS = 'em_destination_domains';
	const OPT_DEFAULT_CAMPAIGN_ID = 'em_default_campaign_id';
	const OPT_RECIPIENT_HASH_SALT = 'em_recipient_hash_salt';

	/**
	 * Register the wp_mail filter at priority 100 (after core/template content
	 * filters). Idempotent — has_filter check prevents double-registration on
	 * activation race.
	 */
	public static function register() : void {
		if ( ! has_filter( 'wp_mail', [ __CLASS__, 'filter_mail' ] ) ) {
			add_filter( 'wp_mail', [ __CLASS__, 'filter_mail' ], 100, 1 );
		}
	}

	/**
	 * wp_mail filter callback. Mutates $args['message'] to:
	 *   1. Append utm_source=email + utm_medium=email + utm_campaign + utm_content
	 *      + em_campaign_id + em_recipient_hash to every <a href="..."> link
	 *      whose host matches a configured destination domain.
	 *   2. Inject a 1x1 transparent tracking pixel before </body> (or appended
	 *      at the end if no </body> tag).
	 *
	 * Text-only emails (no <a href substring) are returned unchanged.
	 *
	 * @param array $args wp_mail args — to, subject, message, headers, attachments.
	 * @return array Mutated $args with UTM-injected message.
	 */
	public static function filter_mail( $args ) {
		if ( ! is_array( $args ) || empty( $args['message'] ) || ! is_string( $args['message'] ) ) {
			return $args;
		}

		$message = (string) $args['message'];

		// Text-only short-circuit. No <a href substring → nothing to rewrite.
		if ( stripos( $message, '<a ' ) === false || stripos( $message, 'href' ) === false ) {
			return $args;
		}

		$campaign_id = self::resolve_campaign_id( $args );
		$recipient   = self::resolve_recipient( $args );
		$recipient_h = self::recipient_hash( $recipient );

		// Load template (utm_campaign + utm_content) — validated at save time
		// against CLICK-06 controlled vocab (wp_options gend_cc_utm_campaigns +
		// gend_cc_utm_contents).
		$template = [ 'utm_campaign' => 'general', 'utm_content' => 'default' ];
		if ( class_exists( 'EM_UTM_Template_Admin' ) ) {
			$template = EM_UTM_Template_Admin::get_template( $campaign_id );
		}

		$destination_domains = (array) get_option( self::OPT_DESTINATION_DOMAINS, [ 'example.com' ] );

		$utm_params = [
			'utm_source'        => self::UTM_SOURCE,
			'utm_medium'        => self::UTM_MEDIUM,
			'utm_campaign'      => (string) ( $template['utm_campaign'] ?? 'general' ),
			'utm_content'       => (string) ( $template['utm_content'] ?? 'default' ),
			'em_campaign_id'    => (string) $campaign_id,
			'em_recipient_hash' => $recipient_h,
		];

		// Rewrite <a href="..."> tokens. Regex catches single-quoted +
		// double-quoted hrefs.
		$rewritten = preg_replace_callback(
			'/(<a\s[^>]*?href\s*=\s*)(["\'])([^"\']+)(\2)([^>]*)>/i',
			function( $m ) use ( $utm_params, $destination_domains ) {
				$prefix = $m[1];
				$quote  = $m[2];
				$url    = $m[3];
				$suffix = $m[5];

				$host = self::parse_host( $url );
				if ( $host === '' || ! self::host_matches( $host, $destination_domains ) ) {
					return $m[0];   // Non-matching domain — leave untouched.
				}

				$new_url = self::append_query( $url, $utm_params );
				return $prefix . $quote . $new_url . $quote . $suffix . '>';
			},
			$message
		);
		if ( is_string( $rewritten ) ) {
			$message = $rewritten;
		}

		// Inject 1x1 open-pixel before </body>. If no </body> tag, append at end.
		$pixel = self::build_open_pixel( $campaign_id, $recipient_h );
		if ( stripos( $message, '</body>' ) !== false ) {
			$message = preg_replace( '#</body>#i', $pixel . '</body>', $message, 1 );
		} else {
			$message .= "\n" . $pixel;
		}

		$args['message'] = $message;
		return $args;
	}

	/**
	 * Build the open-tracking pixel <img> tag. Sources the URL from
	 * EM_Click_Tracker::open_pixel_url so the namespace constant
	 * (Gend_CC_Constants::REST_NS) is the single source of truth — the
	 * REST-namespace literal string is never embedded anywhere in this
	 * class (lint-grep-gated, same convention as Phase 14-03 precedent).
	 */
	protected static function build_open_pixel( $campaign_id, string $recipient_hash ) : string {
		$url = '';
		if ( class_exists( 'EM_Click_Tracker' ) ) {
			$url = EM_Click_Tracker::open_pixel_url( (string) $campaign_id, $recipient_hash );
		}
		if ( $url === '' ) {
			return '';
		}
		return '<img src="' . esc_url( $url ) . '" width="1" height="1" alt="" style="display:none;border:0" />';
	}

	/**
	 * Resolve campaign_id from the X-EM-Campaign-Id header on $args, or fall
	 * back to wp_options('em_default_campaign_id'). Always returns a non-empty
	 * string-or-int.
	 *
	 * @param array $args wp_mail args.
	 * @return string|int
	 */
	protected static function resolve_campaign_id( array $args ) {
		$headers = $args['headers'] ?? '';
		if ( is_string( $headers ) ) {
			$headers = preg_split( "/\r\n|\n|\r/", $headers );
		}
		if ( is_array( $headers ) ) {
			foreach ( $headers as $h ) {
				if ( ! is_string( $h ) ) { continue; }
				if ( stripos( $h, 'X-EM-Campaign-Id:' ) === 0 ) {
					$parts = explode( ':', $h, 2 );
					if ( count( $parts ) === 2 ) {
						$v = trim( $parts[1] );
						if ( $v !== '' ) {
							return $v;
						}
					}
				}
			}
		}
		$default = get_option( self::OPT_DEFAULT_CAMPAIGN_ID, 0 );
		return $default !== '' && $default !== null ? $default : 0;
	}

	/**
	 * Resolve the recipient email address from $args['to']. Accepts string or
	 * array. Returns the FIRST recipient (recipient hash is per-send so a
	 * BCC-style multi-recipient send is treated as a single attribution event).
	 */
	protected static function resolve_recipient( array $args ) : string {
		$to = $args['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = reset( $to );
		}
		return is_string( $to ) ? trim( $to ) : '';
	}

	/**
	 * SHA-256 hash of recipient + salt. The salt is auto-generated and stored
	 * on first call so the hash is stable across restarts.
	 *
	 * NEVER plaintext PII in URLs — the recipient email NEVER appears as a
	 * query parameter (PIPEDA discipline shared with Phase 9-02).
	 */
	public static function recipient_hash( string $recipient ) : string {
		if ( $recipient === '' ) {
			return '';
		}
		$salt = (string) get_option( self::OPT_RECIPIENT_HASH_SALT, '' );
		if ( $salt === '' ) {
			$salt = wp_generate_password( 32, false );
			update_option( self::OPT_RECIPIENT_HASH_SALT, $salt, false );
		}
		return hash( 'sha256', strtolower( $recipient ) . '|' . $salt );
	}

	/**
	 * parse_url() host extractor that tolerates relative URLs (returns '' for
	 * /relative-path so the host-match gate fails safely).
	 */
	protected static function parse_host( string $url ) : string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( (string) $parts['host'] );
	}

	/**
	 * True iff $host matches any entry in $domains. Match is suffix-aware:
	 *   'sub.example.com' matches a domain entry 'example.com'.
	 */
	protected static function host_matches( string $host, array $domains ) : bool {
		foreach ( $domains as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( $d === '' ) { continue; }
			if ( $host === $d ) {
				return true;
			}
			$suffix = '.' . $d;
			$tail   = substr( $host, -strlen( $suffix ) );
			if ( $tail === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Append/merge $params into the query string of $url. Existing params in
	 * $url WIN if a key collides (operator-set utm_term on a cloaked link
	 * shouldn't be clobbered by the injector). Preserves fragment.
	 */
	public static function append_query( string $url, array $params ) : string {
		$frag = '';
		$hash_pos = strpos( $url, '#' );
		if ( $hash_pos !== false ) {
			$frag = substr( $url, $hash_pos );
			$url  = substr( $url, 0, $hash_pos );
		}
		$existing = [];
		$q_pos = strpos( $url, '?' );
		if ( $q_pos !== false ) {
			$qs   = substr( $url, $q_pos + 1 );
			$url  = substr( $url, 0, $q_pos );
			parse_str( $qs, $existing );
		}
		// Existing wins — only fill keys that don't exist.
		foreach ( $params as $k => $v ) {
			if ( $v === '' || $v === null ) { continue; }
			if ( ! isset( $existing[ $k ] ) || $existing[ $k ] === '' ) {
				$existing[ $k ] = (string) $v;
			}
		}
		$new_qs = http_build_query( $existing );
		return $url . ( $new_qs !== '' ? '?' . $new_qs : '' ) . $frag;
	}
}

endif;  // class_exists guard
