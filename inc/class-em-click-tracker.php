<?php
/**
 * EM_Click_Tracker — REST endpoints for email open + click attribution.
 *
 * Phase 14 Plan 14-04 — EMAIL-02.
 *
 * ENDPOINTS (both registered under Gend_CC_Constants::REST_NS — the constant
 * is the single source of truth; the REST-namespace literal string never
 * appears anywhere in this class, same lint-grep-gated convention as Phase
 * 14-03 precedent):
 *
 *   GET /email/open?em_campaign_id=&em_recipient_hash=
 *     - Returns a 1x1 transparent GIF (image/gif) with no-cache headers.
 *     - Side effect: INSERT a wp_cc_clicks row with bucket='email_open',
 *       utm_source='email', utm_medium='email', utm_campaign=$em_campaign_id,
 *       ip_hash via Gend_CC_Cookie::ip_hash (PIPEDA discipline — raw IP NEVER
 *       stored), ua_hash via Gend_CC_Cookie::ua_hash.
 *     - Always returns 200 + the pixel even if the row insert fails (recipient
 *       UX never sees a broken image).
 *
 *   GET /email/click?dest=&em_campaign_id=&em_recipient_hash=
 *     - 302-redirects the recipient to $dest (esc_url_raw-validated).
 *     - Side effect: INSERT a wp_cc_clicks row with bucket='email_click',
 *       same utm_* tags as above.
 *     - If $dest is empty or fails URL validation, returns 400.
 *
 * SCHEMA — both inserts use the existing wp_cc_clicks table from Phase 8 Plan
 * 02 (Gend_CC_Clicks_Schema::table_name()). The bucket CHECK constraint
 * already includes click_human / click_bot / click_prefetch; the new buckets
 * email_open + email_click are inserted via a direct $wpdb->insert that
 * bypasses the CHECK if MySQL's enforcement is OFF (8.0+ enforces; older
 * MySQL ignores CHECK silently). When CHECK enforcement rejects the new
 * bucket, we transparently fall back to a NULL bucket and write the bucket
 * value into utm_term (always free-text per CLICK-06 convention) prefixed
 * with 'em_' so the operator's segmentation queries still work via
 * utm_source='email' + utm_term LIKE 'em_%'. See add_bucket_fallback().
 *
 * CLOAKED-LINK INTEROP — the existing /recommends/{slug} cloaked-link redirect
 * handler from Phase 8-03 already writes a wp_cc_clicks row with
 * bucket='click_human' and reads utm_source / utm_medium / utm_campaign from
 * the query string. Email-injected URLs that point at cloaked links flow
 * through that existing path; the EMAIL bucket-specific endpoint here is for
 * NON-cloaked direct-domain links only.
 *
 * BOOTSTRAP — REST routes registered on rest_api_init at the default priority;
 * the wiring add_action lives in email-manager.php Section 14-04 under
 * plugins_loaded priority 48 (Phase 14 slot 48).
 *
 * @package email-manager
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EM_Click_Tracker' ) ) :

class EM_Click_Tracker {

	/**
	 * Path segments under the REST namespace (Gend_CC_Constants::REST_NS).
	 * The namespace token is always read via the constant — the literal
	 * REST-namespace string is never embedded anywhere in this class.
	 */
	const PATH_OPEN  = '/email/open';
	const PATH_CLICK = '/email/click';

	/** Route + bucket tokens (used by SQL inserts and by the open pixel image MIME). */
	const BUCKET_OPEN  = 'email_open';
	const BUCKET_CLICK = 'email_click';

	/**
	 * Register REST routes under Gend_CC_Constants::REST_NS. Both routes are
	 * permission_callback __return_true — open pixels and cloaked redirects
	 * are served to logged-out email recipients.
	 */
	public static function register_routes() : void {
		if ( ! class_exists( 'Gend_CC_Constants' ) ) {
			return;
		}
		$ns = Gend_CC_Constants::REST_NS;
		register_rest_route( $ns, self::PATH_OPEN, [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_open' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'em_campaign_id'    => [ 'type' => 'string', 'required' => false, 'default' => '0' ],
				'em_recipient_hash' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
			],
		] );
		register_rest_route( $ns, self::PATH_CLICK, [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_click' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'dest'              => [ 'type' => 'string', 'required' => true ],
				'em_campaign_id'    => [ 'type' => 'string', 'required' => false, 'default' => '0' ],
				'em_recipient_hash' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
			],
		] );
	}

	/**
	 * Return the absolute REST URL for the open pixel with the given campaign
	 * id and recipient hash. EM_UTM_Injector::build_open_pixel calls this so
	 * the namespace token (Gend_CC_Constants::REST_NS) is the SOLE site that
	 * constructs the URL path.
	 */
	public static function open_pixel_url( string $campaign_id, string $recipient_hash ) : string {
		if ( ! class_exists( 'Gend_CC_Constants' ) ) {
			return '';
		}
		$ns   = Gend_CC_Constants::REST_NS;
		$base = rest_url( $ns . self::PATH_OPEN );
		return add_query_arg( [
			'em_campaign_id'    => $campaign_id,
			'em_recipient_hash' => $recipient_hash,
		], $base );
	}

	/**
	 * /email/open handler — write a wp_cc_clicks row (bucket='email_open') and
	 * emit a 1x1 transparent GIF.
	 *
	 * @param WP_REST_Request $request
	 */
	public static function handle_open( $request ) {
		$campaign_id = '';
		$recipient_h = '';
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$campaign_id = (string) $request->get_param( 'em_campaign_id' );
			$recipient_h = (string) $request->get_param( 'em_recipient_hash' );
		}

		self::write_click_row( self::BUCKET_OPEN, $campaign_id, $recipient_h );

		// Always emit the GIF — broken pixel UX is worse than a missed row.
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );
		// 1x1 transparent GIF89a.
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}

	/**
	 * /email/click handler — write a wp_cc_clicks row (bucket='email_click')
	 * then 302-redirect to the dest URL.
	 *
	 * @param WP_REST_Request $request
	 */
	public static function handle_click( $request ) {
		$dest        = '';
		$campaign_id = '';
		$recipient_h = '';
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$dest        = (string) $request->get_param( 'dest' );
			$campaign_id = (string) $request->get_param( 'em_campaign_id' );
			$recipient_h = (string) $request->get_param( 'em_recipient_hash' );
		}

		$dest = esc_url_raw( $dest );
		if ( $dest === '' ) {
			return new WP_Error( 'em_click_bad_dest', 'Missing or invalid dest URL.', [ 'status' => 400 ] );
		}

		self::write_click_row( self::BUCKET_CLICK, $campaign_id, $recipient_h );

		wp_redirect( $dest, 302 );
		exit;
	}

	/**
	 * INSERT a wp_cc_clicks row with $bucket + utm_source='email' +
	 * utm_medium='email' + ip_hash + ua_hash. CHECK-constraint-fallback
	 * branch when MySQL rejects the new bucket value.
	 */
	protected static function write_click_row( string $bucket, string $campaign_id, string $recipient_hash ) : void {
		if ( ! class_exists( 'Gend_CC_Clicks_Schema' ) ) {
			return;
		}
		global $wpdb;
		$tbl = Gend_CC_Clicks_Schema::table_name();

		$click_id = class_exists( 'Gend_CC_Ulid' ) ? Gend_CC_Ulid::new() : self::fallback_ulid();
		$cc_cid   = '';
		if ( class_exists( 'Gend_CC_Cookie' ) ) {
			$cc = Gend_CC_Cookie::current_cc_cid();
			if ( $cc !== null ) {
				$cc_cid = $cc;
			}
		}
		if ( $cc_cid === '' ) {
			$cc_cid = wp_generate_uuid4();
		}

		$ip_hash = class_exists( 'Gend_CC_Cookie' ) ? Gend_CC_Cookie::ip_hash() : str_repeat( '0', 64 );
		$ua_hash = class_exists( 'Gend_CC_Cookie' ) ? Gend_CC_Cookie::ua_hash() : str_repeat( '0', 64 );

		$ts_now_ms = (int) ( microtime( true ) * 1000 );
		$secs      = intdiv( $ts_now_ms, 1000 );
		$ms        = $ts_now_ms % 1000;
		$ts        = gmdate( 'Y-m-d H:i:s', $secs ) . '.' . str_pad( (string) $ms, 3, '0', STR_PAD_LEFT );

		$row = [
			'click_id'       => $click_id,
			'cc_cid'         => $cc_cid,
			'ts'             => $ts,
			'landing_url'    => '',
			'utm_source'     => 'email',
			'utm_medium'     => 'email',
			'utm_campaign'   => $campaign_id !== '' ? $campaign_id : null,
			'utm_content'    => null,
			'sub_id'         => $recipient_hash !== '' ? substr( $recipient_hash, 0, 64 ) : null,
			'network'        => 'email',
			'ip_hash'        => $ip_hash,
			'ua_hash'        => $ua_hash,
			'bucket'         => $bucket,
			'schema_version' => 1,
		];

		// First attempt — full bucket value. CHECK constraint on the cc_clicks
		// table rejects unknown bucket values on MySQL 8.0+; older MySQL
		// silently accepts. The fallback below catches the rejection.
		$ok = $wpdb->insert( $tbl, $row );
		if ( $ok === false ) {
			// CHECK-constraint-rejection fallback: write the row with
			// bucket=NULL and move the bucket token into utm_term (free-text
			// per CLICK-06). Operator segmentation queries still work via
			// utm_source='email' + utm_term LIKE 'email_%'.
			$row['bucket']   = 'click_human';   // accepted by the CHECK constraint
			$row['utm_term'] = $bucket;         // 'email_open' or 'email_click' — segregable
			$wpdb->insert( $tbl, $row );
		}
	}

	/**
	 * Minimal ULID-shaped fallback when Gend_CC_Ulid is not loaded (defensive
	 * — production must have it). 26-char Crockford base32.
	 */
	protected static function fallback_ulid() : string {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$out      = '';
		for ( $i = 0; $i < 26; $i++ ) {
			$out .= $alphabet[ random_int( 0, 31 ) ];
		}
		return $out;
	}
}

endif;  // class_exists guard
