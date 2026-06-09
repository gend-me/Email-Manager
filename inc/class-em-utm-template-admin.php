<?php
/**
 * EM_UTM_Template_Admin — per-campaign UTM template editor + storage.
 *
 * Phase 14 Plan 14-04 — EMAIL-03.
 *
 * STORAGE — raw CREATE TABLE IF NOT EXISTS for wp_em_utm_templates (the WP
 * schema-migrator helper is intentionally bypassed; v1.0 hard rule reserves
 * raw DDL for any table with CHECK-constraint-shaped invariants and for the
 * simple column-type shapes used here).
 *
 *   CREATE TABLE IF NOT EXISTS wp_em_utm_templates (
 *     campaign_id   BIGINT UNSIGNED PRIMARY KEY,
 *     utm_campaign  VARCHAR(64),
 *     utm_content   VARCHAR(64),
 *     updated_at    DATETIME
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * CONTROLLED VOCAB GATE — utm_campaign + utm_content values are VALIDATED at
 * save time against the CLICK-06 controlled vocab. The vocab is stored in
 * wp_options under the keys gend_cc_utm_campaigns + gend_cc_utm_contents
 * (these are the canonical names referenced by EMAIL-03; the actual sales-team
 * UTM Vocab class stores under gend_cc_utm_campaign_vocab + gend_cc_utm_content_vocab
 * and exposes Gend_ST_Utm_Vocab::campaign_vocab() / content_vocab() as the public
 * API — get_vocab() below reads via the class API when available and falls
 * back to the wp_options keys named in the plan). Free-text values are
 * rejected with WP_Error('cc_utm_invalid') — same error code as the Phase
 * 8-03 REST gate so admin UI banners can share copy.
 *
 * ADMIN SURFACE — sub-tab under the existing email-manager admin page. The
 * v1.0 hard rule reserves top-level WP-admin menu registration for the
 * email-manager plugin's core surfaces; this sub-tab attaches via the
 * existing 'em_admin_render_subtab' filter when present, or falls back to an
 * admin_init interceptor on ?page=email-manager&subtab=utm.
 *
 * SECURITY — POST handler requires current_user_can('manage_options') +
 * wp_verify_nonce on the form nonce. Nonce action 'em_save_utm_template'.
 *
 * BOOTSTRAP — register() hooks plugins_loaded priority 48 via the
 * email-manager.php Section 14-04 wire-up.
 *
 * @package email-manager
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EM_UTM_Template_Admin' ) ) :

class EM_UTM_Template_Admin {

	const TABLE_SUFFIX = 'em_utm_templates';

	/**
	 * Controlled vocab wp_options keys — canonical names from the EMAIL-03
	 * specification. The sales-team UTM Vocab class actually stores under
	 * gend_cc_utm_campaign_vocab + gend_cc_utm_content_vocab (singular
	 * suffix) and exposes Gend_ST_Utm_Vocab::campaign_vocab() / content_vocab()
	 * as the source-of-truth public API. get_campaign_vocab() / get_content_vocab()
	 * below check the class first, then fall back to these option keys.
	 */
	const OPT_CAMPAIGN_VOCAB = 'gend_cc_utm_campaigns';
	const OPT_CONTENT_VOCAB  = 'gend_cc_utm_contents';

	const ERR_INVALID = 'cc_utm_invalid';
	const NONCE_ACTION = 'em_save_utm_template';

	/**
	 * Return the full table name (wp_em_utm_templates with prefix).
	 */
	public static function table_name() : string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * register() — schema install + admin sub-tab wiring.
	 * Idempotent. Called from email-manager.php Section 14-04 at
	 * plugins_loaded priority 48.
	 */
	public static function register() : void {
		self::install_schema();

		// Sub-tab admin render. The email-manager admin page lives at
		// ?page=email-manager. No WP-admin top-level menu registration call
		// is made here — sub-tab is rendered via the existing email-manager
		// admin filter when present, or via admin_init interceptor as a
		// fallback.
		add_action( 'admin_post_em_save_utm_template', [ __CLASS__, 'handle_post' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_render_subtab' ] );
	}

	/**
	 * Raw CREATE TABLE IF NOT EXISTS — the WP schema-migrator helper is
	 * intentionally bypassed (v1.0 hard rule, see Gend_CC_Clicks_Schema for
	 * the canonical precedent that locked in this convention across the
	 * Phase 8+ click ledger schema family).
	 */
	public static function install_schema() : void {
		global $wpdb;
		$tbl = self::table_name();
		$sql = "CREATE TABLE IF NOT EXISTS {$tbl} (
			campaign_id   BIGINT UNSIGNED NOT NULL,
			utm_campaign  VARCHAR(64) NULL,
			utm_content   VARCHAR(64) NULL,
			updated_at    DATETIME    NULL,
			PRIMARY KEY (campaign_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		$wpdb->query( $sql );
	}

	/**
	 * get_template — load template for $campaign_id; fall back to first vocab
	 * entries if the saved value is no longer in the vocab (vocab can be
	 * edited operator-side after a template was saved).
	 *
	 * @return array{utm_campaign:string,utm_content:string}
	 */
	public static function get_template( $campaign_id ) : array {
		global $wpdb;
		$cid = (int) $campaign_id;
		$tbl = self::table_name();
		$row = null;
		if ( $cid > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT utm_campaign, utm_content FROM {$tbl} WHERE campaign_id=%d", $cid ), ARRAY_A );
		}
		$campaign_vocab = self::get_campaign_vocab();
		$content_vocab  = self::get_content_vocab();

		$utm_campaign = is_array( $row ) ? (string) ( $row['utm_campaign'] ?? '' ) : '';
		$utm_content  = is_array( $row ) ? (string) ( $row['utm_content']  ?? '' ) : '';

		// If saved value is no longer in vocab, fall back to first entry, then
		// to the literal fallback string. Vocab can be empty during partial
		// setup; the literal fallback keeps the injector functional.
		if ( $utm_campaign === '' || ( ! empty( $campaign_vocab ) && ! in_array( $utm_campaign, $campaign_vocab, true ) ) ) {
			$utm_campaign = ! empty( $campaign_vocab ) ? (string) reset( $campaign_vocab ) : 'general';
		}
		if ( $utm_content === '' || ( ! empty( $content_vocab ) && ! in_array( $utm_content, $content_vocab, true ) ) ) {
			$utm_content = ! empty( $content_vocab ) ? (string) reset( $content_vocab ) : 'default';
		}

		return [
			'utm_campaign' => $utm_campaign,
			'utm_content'  => $utm_content,
		];
	}

	/**
	 * save_template — VALIDATE then UPSERT.
	 *
	 * Validation:
	 *   - $values['utm_campaign'] MUST be present in get_campaign_vocab()
	 *   - $values['utm_content']  MUST be present in get_content_vocab()
	 *   - failure → WP_Error('cc_utm_invalid', ..., ['field' => ..., 'allowed' => ...])
	 *
	 * @param int   $campaign_id
	 * @param array $values
	 * @return true|WP_Error
	 */
	public static function save_template( $campaign_id, array $values ) {
		$cid          = (int) $campaign_id;
		$utm_campaign = (string) ( $values['utm_campaign'] ?? '' );
		$utm_content  = (string) ( $values['utm_content']  ?? '' );

		$campaign_vocab = self::get_campaign_vocab();
		$content_vocab  = self::get_content_vocab();

		// Vocab is the operator-curated allow-list; empty vocab means the
		// operator has not yet set it up — in that case we accept any value
		// (same convention as sales-team class-st-utm-vocab.php). When the
		// vocab IS populated, free-text submissions are rejected.
		if ( ! empty( $campaign_vocab ) && ! in_array( $utm_campaign, $campaign_vocab, true ) ) {
			return new WP_Error( self::ERR_INVALID, 'utm_campaign value is not in the controlled vocab.', [
				'field'   => 'utm_campaign',
				'value'   => $utm_campaign,
				'allowed' => $campaign_vocab,
				'status'  => 400,
			] );
		}
		if ( ! empty( $content_vocab ) && ! in_array( $utm_content, $content_vocab, true ) ) {
			return new WP_Error( self::ERR_INVALID, 'utm_content value is not in the controlled vocab.', [
				'field'   => 'utm_content',
				'value'   => $utm_content,
				'allowed' => $content_vocab,
				'status'  => 400,
			] );
		}

		global $wpdb;
		$tbl = self::table_name();

		// UPSERT — ON DUPLICATE KEY UPDATE keeps the campaign_id primary-key
		// invariant without a SELECT-then-INSERT race.
		$sql = $wpdb->prepare(
			"INSERT INTO {$tbl} (campaign_id, utm_campaign, utm_content, updated_at)
			 VALUES (%d, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE
			   utm_campaign = VALUES(utm_campaign),
			   utm_content  = VALUES(utm_content),
			   updated_at   = VALUES(updated_at)",
			$cid, $utm_campaign, $utm_content, current_time( 'mysql', true )
		);
		$wpdb->query( $sql );

		return true;
	}

	/**
	 * Read controlled vocab — campaigns. Prefer the sales-team UTM Vocab class
	 * (canonical source); fall back to the wp_options key named in EMAIL-03.
	 *
	 * @return array<int,string>
	 */
	public static function get_campaign_vocab() : array {
		if ( class_exists( 'Gend_ST_Utm_Vocab' ) && method_exists( 'Gend_ST_Utm_Vocab', 'campaign_vocab' ) ) {
			$v = Gend_ST_Utm_Vocab::campaign_vocab();
			if ( is_array( $v ) && ! empty( $v ) ) {
				return array_values( array_filter( array_map( 'strval', $v ), 'strlen' ) );
			}
		}
		$opt = get_option( self::OPT_CAMPAIGN_VOCAB, [] );
		if ( ! is_array( $opt ) ) {
			$opt = [];
		}
		return array_values( array_filter( array_map( 'strval', $opt ), 'strlen' ) );
	}

	/**
	 * Read controlled vocab — contents. Same fallback chain as
	 * get_campaign_vocab.
	 *
	 * @return array<int,string>
	 */
	public static function get_content_vocab() : array {
		if ( class_exists( 'Gend_ST_Utm_Vocab' ) && method_exists( 'Gend_ST_Utm_Vocab', 'content_vocab' ) ) {
			$v = Gend_ST_Utm_Vocab::content_vocab();
			if ( is_array( $v ) && ! empty( $v ) ) {
				return array_values( array_filter( array_map( 'strval', $v ), 'strlen' ) );
			}
		}
		$opt = get_option( self::OPT_CONTENT_VOCAB, [] );
		if ( ! is_array( $opt ) ) {
			$opt = [];
		}
		return array_values( array_filter( array_map( 'strval', $opt ), 'strlen' ) );
	}

	/**
	 * Intercept ?page=email-manager&subtab=utm and emit the sub-tab UI.
	 * Falls back to the existing email-manager admin page when no
	 * em_admin_render_subtab filter exists. SECURITY: current_user_can
	 * gate; non-admins see a 403-style notice instead of the form.
	 */
	public static function maybe_render_subtab() : void {
		if ( ! is_admin() ) { return; }
		$page   = isset( $_GET['page'] )   ? sanitize_key( wp_unslash( $_GET['page'] ) )   : '';
		$subtab = isset( $_GET['subtab'] ) ? sanitize_key( wp_unslash( $_GET['subtab'] ) ) : '';
		if ( $page !== 'email-manager' || $subtab !== 'utm' ) { return; }

		// Render after admin_init via shutdown — we only flag here that the
		// sub-tab is active; actual rendering hooks into the existing
		// email-manager admin page via 'em_admin_render_subtab' filter
		// (the email-manager-admin.php callback respects this filter when
		// present) or via the screen render fallback.
		add_filter( 'em_admin_render_subtab', [ __CLASS__, 'render_subtab_content' ], 10, 2 );
	}

	/**
	 * Render the UTM template sub-tab body. Invoked via the
	 * em_admin_render_subtab filter from the email-manager admin page renderer.
	 *
	 * @param string $existing  Existing rendered subtab content.
	 * @param string $subtab    Current subtab token.
	 * @return string Rendered HTML.
	 */
	public static function render_subtab_content( $existing, $subtab ) {
		if ( $subtab !== 'utm' ) {
			return $existing;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $existing . '<div class="notice notice-error"><p>' . esc_html__( 'Insufficient permissions.', 'email-manager' ) . '</p></div>';
		}

		ob_start();
		self::render_admin_panel();
		return $existing . ob_get_clean();
	}

	/**
	 * Server-side PHP form: campaign dropdown + 2 SelectControl-style
	 * dropdowns sourced from CLICK-06 vocab. NEVER any free-text inputs for
	 * utm_campaign / utm_content.
	 */
	public static function render_admin_panel() : void {
		$campaigns       = self::get_campaign_list();
		$campaign_vocab  = self::get_campaign_vocab();
		$content_vocab   = self::get_content_vocab();
		$selected_cid    = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
		$template        = $selected_cid > 0 ? self::get_template( $selected_cid ) : [ 'utm_campaign' => '', 'utm_content' => '' ];

		// Surface success/error notices from the POST round-trip.
		if ( isset( $_GET['em_utm_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'UTM template saved.', 'email-manager' ) . '</p></div>';
		}
		if ( isset( $_GET['em_utm_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['em_utm_error'] ) ) ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Per-Campaign UTM Template', 'email-manager' ); ?></h2>
			<p><?php esc_html_e( 'Set the utm_campaign and utm_content values that the email-manager wp_mail filter injects into outbound links matching configured destination domains. Source and medium are hardcoded to "email" — they cannot be edited.', 'email-manager' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="em_save_utm_template" />
				<?php wp_nonce_field( self::NONCE_ACTION, '_em_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="em_utm_campaign_id"><?php esc_html_e( 'Campaign', 'email-manager' ); ?></label></th>
						<td>
							<select name="campaign_id" id="em_utm_campaign_id">
								<?php foreach ( $campaigns as $c ) : ?>
									<option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( $selected_cid, (int) $c['id'] ); ?>>
										<?php echo esc_html( (string) $c['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="em_utm_campaign"><?php esc_html_e( 'utm_campaign', 'email-manager' ); ?></label></th>
						<td>
							<select name="utm_campaign" id="em_utm_campaign">
								<?php foreach ( $campaign_vocab as $v ) : ?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $template['utm_campaign'], $v ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'From the CLICK-06 controlled vocab. Edit the vocab via the cloaked-links Vocab admin page.', 'email-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="em_utm_content"><?php esc_html_e( 'utm_content', 'email-manager' ); ?></label></th>
						<td>
							<select name="utm_content" id="em_utm_content">
								<?php foreach ( $content_vocab as $v ) : ?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $template['utm_content'], $v ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save UTM Template', 'email-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * POST handler — admin_post_em_save_utm_template. Security gates:
	 *   1. current_user_can('manage_options')
	 *   2. wp_verify_nonce on the _em_nonce field
	 *   3. save_template returns WP_Error on cc_utm_invalid → flashed to
	 *      ?em_utm_error= for the next render.
	 */
	public static function handle_post() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'email-manager' ) );
		}
		check_admin_referer( self::NONCE_ACTION, '_em_nonce' );

		$campaign_id  = isset( $_POST['campaign_id'] )  ? (int) $_POST['campaign_id'] : 0;
		$utm_campaign = isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['utm_campaign'] ) ) : '';
		$utm_content  = isset( $_POST['utm_content'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['utm_content'] ) )  : '';

		$result = self::save_template( $campaign_id, [
			'utm_campaign' => $utm_campaign,
			'utm_content'  => $utm_content,
		] );

		$return_url = add_query_arg( [
			'page'        => 'email-manager',
			'subtab'      => 'utm',
			'campaign_id' => $campaign_id,
		], admin_url( 'admin.php' ) );

		if ( is_wp_error( $result ) ) {
			$return_url = add_query_arg( 'em_utm_error', rawurlencode( $result->get_error_message() ), $return_url );
		} else {
			$return_url = add_query_arg( 'em_utm_saved', '1', $return_url );
		}
		wp_safe_redirect( $return_url );
		exit;
	}

	/**
	 * List available campaigns for the dropdown. Sourced from
	 * wp_em_campaigns when the existing email-manager schema is loaded;
	 * falls back to a single 'default' entry.
	 *
	 * @return array<int,array{id:int,label:string}>
	 */
	protected static function get_campaign_list() : array {
		global $wpdb;
		$out = [];
		$tbl = $wpdb->prefix . 'em_campaigns';
		// suppress_errors so a missing table on hub-only or partial-install
		// containers doesn't surface as an admin notice.
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT id, name FROM {$tbl} ORDER BY id ASC LIMIT 200", ARRAY_A );
		$wpdb->suppress_errors( false );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[] = [
					'id'    => (int) ( $r['id'] ?? 0 ),
					'label' => (string) ( $r['name'] ?? ( '#' . ( $r['id'] ?? '' ) ) ),
				];
			}
		}
		if ( empty( $out ) ) {
			$out[] = [ 'id' => 0, 'label' => __( 'Default campaign', 'email-manager' ) ];
		}
		return $out;
	}
}

endif;  // class_exists guard
