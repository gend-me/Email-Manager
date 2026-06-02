<?php
/**
 * Member Inbox: outbound open-tracking pixel (slice 2s).
 *
 * When the sender enables "Track when opened" on a compose, we inject
 * a 1×1 transparent <img> at the end of the body_html pointing at
 *   GET /wp-json/em/v1/inbox/track/open?t={raw_id}.{hmac16}
 * served by the SENDER'S container WP. The token is HMAC-SHA256 of
 * the raw_id keyed by em_inbox_track_secret (auto-generated, 32
 * bytes); first 16 hex chars are enough to defeat brute-force given
 * the raw_id is already an unguessable integer.
 *
 * Each pixel hit records:
 *   wp_gdc_inbox_opens  raw_id, opened_at, ip_hash, ua
 *
 * Privacy posture:
 *   - DEFAULT OFF in the composer; per-message opt-in.
 *   - Reciprocally: inbound mail from other senders is image-blocked
 *     by default (slice 2q), so OUR users aren't tracked unless they
 *     allowlist the sender. Two-sided correctness.
 *   - IP is hashed (sha256(ip + track_secret)) before storage so the
 *     raw IP doesn't sit in our DB; opens are still correlatable
 *     across hits by the same client.
 *
 * Sender's UI surfaces an "Opened N times — first M ago" indicator
 * on their sent message card.
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_TRACK_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema + per-install secret
 * ------------------------------------------------------------------------- */

function em_inbox_track_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_opens';

    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        raw_id bigint(20) UNSIGNED NOT NULL,
        opened_at datetime NOT NULL,
        ip_hash varchar(64) DEFAULT NULL,
        user_agent varchar(255) DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_raw (raw_id, opened_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_track_db_version', EM_INBOX_TRACK_DB_VERSION);
}

function em_inbox_track_maybe_create() {
    if (get_option('em_inbox_track_db_version') !== EM_INBOX_TRACK_DB_VERSION) {
        em_inbox_track_create_table();
    }
}
add_action('admin_init',    'em_inbox_track_maybe_create');
add_action('rest_api_init', 'em_inbox_track_maybe_create');

function em_inbox_track_get_or_create_secret() {
    $s = get_option('em_inbox_track_secret');
    if (! $s) {
        $s = bin2hex(random_bytes(32));
        update_option('em_inbox_track_secret', $s, false);
    }
    return $s;
}

/* -------------------------------------------------------------------------
 * Token helpers
 * ------------------------------------------------------------------------- */

function em_inbox_track_make_token($raw_id) {
    $raw_id = (int) $raw_id;
    if ($raw_id <= 0) return '';
    $sig = substr(hash_hmac('sha256', (string) $raw_id, em_inbox_track_get_or_create_secret()), 0, 16);
    return $raw_id . '.' . $sig;
}

function em_inbox_track_verify_token($token) {
    if (! is_string($token) || strpos($token, '.') === false) return null;
    list($raw_id, $sig) = array_pad(explode('.', $token, 2), 2, '');
    $raw_id = (int) $raw_id;
    if ($raw_id <= 0 || strlen($sig) !== 16) return null;
    $expected = substr(hash_hmac('sha256', (string) $raw_id, em_inbox_track_get_or_create_secret()), 0, 16);
    return hash_equals($expected, $sig) ? $raw_id : null;
}

/**
 * Inject the tracking pixel at the end of the body_html. Idempotent —
 * if a pixel for this raw_id is already there, returns the html as-is.
 */
function em_inbox_track_inject_pixel($html, $raw_id) {
    $token = em_inbox_track_make_token($raw_id);
    if ($token === '') return $html;
    $url = home_url('/wp-json/em/v1/inbox/track/open?t=' . rawurlencode($token));
    $marker = ' data-em-track="' . esc_attr($token) . '"';
    if ($html !== '' && strpos($html, $marker) !== false) return $html;
    $pixel = '<img src="' . esc_url($url) . '" width="1" height="1" alt=""' . $marker . ' style="display:none;border:0">';
    if ($html === '') return $pixel;
    return $html . $pixel;
}

/* -------------------------------------------------------------------------
 * REST routes
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    // Public — called from recipient's browser when they open the email.
    register_rest_route('em/v1', '/inbox/track/open', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_track_open_pixel',
        'permission_callback' => '__return_true',
        'args' => array('t' => array('type' => 'string', 'required' => true)),
    ));

    // Authenticated — sender views their own message's open log.
    register_rest_route('em/v1', '/inbox/message/(?P<id>\d+)/opens', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_track_message_opens',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array('id' => array('type' => 'integer', 'required' => true)),
    ));
});

function em_inbox_track_open_pixel(WP_REST_Request $request) {
    global $wpdb;
    $token  = (string) $request->get_param('t');
    $raw_id = em_inbox_track_verify_token($token);
    if ($raw_id) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
        $wpdb->insert($wpdb->prefix . 'gdc_inbox_opens', array(
            'raw_id'     => $raw_id,
            'opened_at'  => current_time('mysql', 1),
            'ip_hash'    => $ip !== '' ? hash('sha256', $ip . em_inbox_track_get_or_create_secret()) : null,
            'user_agent' => $ua,
        ), array('%d', '%s', '%s', '%s'));
    }
    // Always serve a transparent 1×1 GIF, even on invalid token, so
    // we don't leak validity to an observer. Returning a WP REST
    // response would emit JSON; bypass to send the binary directly.
    $gif = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
    header('Content-Type: image/gif');
    header('Content-Length: ' . strlen($gif));
    header('Cache-Control: no-store, must-revalidate');
    header('Pragma: no-cache');
    echo $gif;
    exit;
}

function em_inbox_track_message_opens(WP_REST_Request $request) {
    global $wpdb;
    $msg_id = (int) $request['id'];
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_track_no_user', 'Login required', array('status' => 401));

    // Permission: sender must be the current user (or admin). Reuse the
    // existing thread-permission check by resolving the message → raw row
    // → recipient (== sender for outbound mirrors).
    $msg_table = $wpdb->prefix . 'gdc_inbox_messages';
    $raw_table = $wpdb->prefix . 'gdc_inbox_raw';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT m.raw_id, r.sender, r.kind FROM $msg_table m
         JOIN $raw_table r ON r.id = m.raw_id
         WHERE m.id = %d", $msg_id
    ), ARRAY_A);
    if (! $row) return new WP_Error('em_track_404', 'Message not found', array('status' => 404));
    if ($row['kind'] !== 'outbound') {
        return new WP_Error('em_track_inbound', 'Tracking data is only available for outbound messages', array('status' => 400));
    }
    $is_admin = current_user_can('manage_options');
    $meta_addr = get_user_meta($u->ID, 'em_inbox_address', true);
    $is_sender = ($u->user_email && strcasecmp($u->user_email, $row['sender']) === 0)
              || ($meta_addr   && strcasecmp($meta_addr,        $row['sender']) === 0);
    if (! ($is_admin || $is_sender)) return new WP_Error('em_track_forbidden', 'Not authorized', array('status' => 403));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT opened_at, ip_hash, user_agent FROM {$wpdb->prefix}gdc_inbox_opens WHERE raw_id = %d ORDER BY opened_at ASC",
        (int) $row['raw_id']
    ), ARRAY_A);
    return rest_ensure_response(array(
        'message_id' => $msg_id,
        'raw_id'     => (int) $row['raw_id'],
        'count'      => count($rows),
        'opens'      => $rows ?: array(),
    ));
}

/* -------------------------------------------------------------------------
 * Expose per-message open count on inbox/threads/{id}
 * ------------------------------------------------------------------------- */

add_filter('em_inbox_thread_message_view', 'em_inbox_track_decorate_message', 10, 1);
function em_inbox_track_decorate_message($msg) {
    if (empty($msg) || ($msg['kind'] ?? '') !== 'outbound') return $msg;
    global $wpdb;
    // Resolve raw_id for this message
    $raw_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT raw_id FROM {$wpdb->prefix}gdc_inbox_messages WHERE id = %d", (int) $msg['id']
    ));
    if (! $raw_id) return $msg;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS n, MAX(opened_at) AS last_at FROM {$wpdb->prefix}gdc_inbox_opens WHERE raw_id = %d", $raw_id
    ), ARRAY_A);
    $msg['open_count']   = (int) ($row['n'] ?? 0);
    $msg['last_open_at'] = $row['last_at'] ?? null;
    return $msg;
}
