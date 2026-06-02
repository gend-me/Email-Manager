<?php
/**
 * Member Inbox: vacation responder (slice 2z).
 *
 * Per-user config in user_meta `em_inbox_vacation` (JSON):
 *   { enabled: bool, start_at: "YYYY-MM-DD", end_at: "YYYY-MM-DD",
 *     subject: "Out of office", body_html: "...", body_plain: "..." }
 *
 * On every inbound em_inbox_message_inserted, if the thread's
 * owner has an enabled vacation in the current date range AND the
 * sender qualifies AND we haven't already auto-replied to them
 * today, queue an outbound auto-reply via the existing submit
 * helper.
 *
 * Dedup table: wp_gdc_inbox_vacation_log
 *   user_id, sender_email, replied_at  UNIQUE (user_id, sender_email, day)
 *
 * Mail-loop guards (industry-standard, RFC 3834 + practice):
 *   - skip if sender == owner (self-send)
 *   - skip if Auto-Submitted header is present and not "no"
 *   - skip if Precedence: bulk / list / junk
 *   - skip noreply / postmaster / mailer-daemon / abuse / etc.
 *
 * Outbound auto-replies include Auto-Submitted: auto-replied so
 * downstream vacation responders won't bounce-loop us.
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_VACATION_DB_VERSION', '1.0.0');
define('EM_INBOX_VACATION_META',        'em_inbox_vacation');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_vacation_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_vacation_log';

    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        sender_email varchar(255) NOT NULL,
        day date NOT NULL,
        replied_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_user_sender_day (user_id, sender_email, day)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_vacation_db_version', EM_INBOX_VACATION_DB_VERSION);
}

function em_inbox_vacation_maybe_create() {
    if (get_option('em_inbox_vacation_db_version') !== EM_INBOX_VACATION_DB_VERSION) {
        em_inbox_vacation_create_table();
    }
}
add_action('admin_init',    'em_inbox_vacation_maybe_create');
add_action('rest_api_init', 'em_inbox_vacation_maybe_create');

/* -------------------------------------------------------------------------
 * REST routes
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/vacation', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_vacation_get',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_vacation_save',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
});

function em_inbox_vacation_default() {
    return array(
        'enabled'    => false,
        'start_at'   => '',
        'end_at'     => '',
        'subject'    => 'Out of office',
        'body_html'  => '',
        'body_plain' => '',
    );
}

function em_inbox_vacation_get_for_user($user_id) {
    $cfg = get_user_meta($user_id, EM_INBOX_VACATION_META, true);
    if (! is_array($cfg)) $cfg = array();
    return array_merge(em_inbox_vacation_default(), $cfg);
}

function em_inbox_vacation_get(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return rest_ensure_response(em_inbox_vacation_default());
    return rest_ensure_response(em_inbox_vacation_get_for_user($u->ID));
}

function em_inbox_vacation_save(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_vac_no_user', 'Login required', array('status' => 401));
    $body = $r->get_json_params() ?: array();
    // Coerce + sanitize.
    $cfg = array_merge(em_inbox_vacation_default(), array(
        'enabled'    => ! empty($body['enabled']),
        'start_at'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($body['start_at'] ?? '')) ? $body['start_at'] : '',
        'end_at'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($body['end_at']   ?? '')) ? $body['end_at']   : '',
        'subject'    => sanitize_text_field((string) ($body['subject'] ?? 'Out of office')),
        'body_html'  => function_exists('em_inbox_sanitize_html')
            ? em_inbox_sanitize_html((string) ($body['body_html'] ?? ''))
            : wp_kses_post((string) ($body['body_html'] ?? '')),
        'body_plain' => sanitize_textarea_field((string) ($body['body_plain'] ?? '')),
    ));
    update_user_meta($u->ID, EM_INBOX_VACATION_META, $cfg);
    return rest_ensure_response($cfg);
}

/* -------------------------------------------------------------------------
 * Auto-reply firing on every inbound message
 * ------------------------------------------------------------------------- */

add_action('em_inbox_message_inserted', 'em_inbox_vacation_on_message', 40, 3);

function em_inbox_vacation_on_message($msg_id, $thread_id, $raw_row) {
    global $wpdb;
    if (! is_array($raw_row) || ($raw_row['kind'] ?? 'inbound') !== 'inbound') return;

    $owner = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT owner_user_id FROM {$wpdb->prefix}gdc_inbox_threads WHERE id = %d", (int) $thread_id
    ));
    if ($owner <= 0) return;

    $cfg = em_inbox_vacation_get_for_user($owner);
    if (empty($cfg['enabled'])) return;

    $today = current_time('Y-m-d');
    if ($cfg['start_at'] && $today < $cfg['start_at']) return;
    if ($cfg['end_at']   && $today > $cfg['end_at'])   return;

    $sender    = strtolower(trim((string) ($raw_row['sender'] ?? '')));
    $recipient = strtolower(trim((string) ($raw_row['recipient'] ?? '')));
    if ($sender === '' || $recipient === '') return;
    if ($sender === $recipient) return;             // don't self-respond
    if (em_inbox_vacation_is_noreply($sender))    return;
    if (em_inbox_vacation_is_auto_loop($raw_row)) return;

    // Dedup: at most once per (owner, sender, day).
    $log = $wpdb->prefix . 'gdc_inbox_vacation_log';
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $log WHERE user_id = %d AND sender_email = %s AND day = %s",
        $owner, $sender, $today
    ));
    if ($exists) return;

    // Build the auto-reply.
    $headers = array(
        array('name' => 'Auto-Submitted', 'value' => 'auto-replied (vacation)'),
        array('name' => 'X-Auto-Response-Suppress', 'value' => 'All'),  // outlook
        array('name' => 'Precedence',     'value' => 'auto_reply'),
        array('name' => 'In-Reply-To',    'value' => (string) ($raw_row['message_id'] ?? '')),
    );
    $subject_in = (string) ($raw_row['subject'] ?? '');
    $subject_out = $cfg['subject'] ?: 'Out of office';
    if (stripos($subject_in, 'Re:') !== 0 && $subject_in !== '') {
        $subject_out .= ': ' . $subject_in;
    }
    $reply_message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(6)), explode('@', $recipient)[1] ?? 'mail.local');

    // Fire and forget — em_inbox_outq_submit_one returns ok/error; the
    // cron worker handles retries if relay fails this attempt. We don't
    // mirror auto-replies into the user's own thread (would be noisy).
    if (function_exists('em_inbox_outq_submit_one')) {
        em_inbox_outq_submit_one(
            $recipient, array($sender), $subject_out,
            $cfg['body_plain'] ?: wp_strip_all_tags($cfg['body_html']),
            $cfg['body_html'],
            $headers, $reply_message_id, array(),
            array('cc' => array(), 'bcc' => array())
        );
    }
    // Log the reply (idempotent — UNIQUE collision is harmless).
    $wpdb->insert($log, array(
        'user_id'      => $owner,
        'sender_email' => $sender,
        'day'          => $today,
        'replied_at'   => current_time('mysql', 1),
    ), array('%d', '%s', '%s', '%s'));
}

function em_inbox_vacation_is_noreply($sender) {
    static $blockers = array('noreply', 'no-reply', 'donotreply', 'do-not-reply',
        'postmaster', 'mailer-daemon', 'mail-daemon', 'abuse', 'bounces');
    $local = strtolower(strstr($sender, '@', true) ?: $sender);
    foreach ($blockers as $b) {
        if (strpos($local, $b) !== false) return true;
    }
    return false;
}

function em_inbox_vacation_is_auto_loop($raw_row) {
    $headers = is_array($raw_row['raw_headers'] ?? null)
        ? $raw_row['raw_headers']
        : json_decode((string) ($raw_row['raw_headers'] ?? ''), true);
    if (! is_array($headers)) return false;
    foreach ($headers as $h) {
        if (! isset($h['name'])) continue;
        $n = strtolower($h['name']);
        $v = strtolower((string) ($h['value'] ?? ''));
        if ($n === 'auto-submitted' && $v !== 'no' && $v !== '') return true;
        if ($n === 'precedence' && in_array(trim($v), array('bulk','list','junk'), true)) return true;
        if ($n === 'x-auto-response-suppress' && stripos($v, 'all') !== false) return true;
        if ($n === 'list-id' || $n === 'list-unsubscribe') return true;
    }
    return false;
}
