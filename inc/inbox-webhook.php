<?php
/**
 * Member Inbox: inbound webhook ingestion
 *
 * Receives parsed inbound mail from the per-container Haraka MTA pod.
 * HMAC-SHA256 verified, replay-protected, idempotent on Message-ID.
 *
 * Slice 1: raw payload only — JWZ threading, GCS attachment offload,
 * and the React inbox UI are follow-on slices.
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_DB_VERSION',     '1.0.0');
define('EM_INBOX_REPLAY_WINDOW',  300);  // seconds — reject older timestamps
define('EM_INBOX_HMAC_HEADER',    'HTTP_X_EM_INBOX_SIGNATURE');
define('EM_INBOX_TS_HEADER',      'HTTP_X_EM_INBOX_TIMESTAMP');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_raw';

    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id varchar(255) NOT NULL,
        recipient varchar(255) NOT NULL,
        sender varchar(255) NOT NULL,
        subject varchar(998) DEFAULT '',
        raw_headers longtext,
        body_plain longtext,
        body_html longtext,
        attachments_json longtext,
        size_bytes bigint(20) UNSIGNED DEFAULT 0,
        received_at datetime NOT NULL,
        processed tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_message_id (message_id),
        KEY idx_recipient (recipient),
        KEY idx_received (received_at),
        KEY idx_processed (processed)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('em_inbox_db_version', EM_INBOX_DB_VERSION);
}

function em_inbox_maybe_create_tables() {
    if (get_option('em_inbox_db_version') !== EM_INBOX_DB_VERSION) {
        em_inbox_create_tables();
    }
}
add_action('admin_init', 'em_inbox_maybe_create_tables');

/* -------------------------------------------------------------------------
 * HMAC secret (per-container)
 * ------------------------------------------------------------------------- */

/**
 * Lazily provision a 64-char hex secret. Operator copies this into the
 * matching MTA pod's K8s Secret. Retrieve via:
 *   wp option get em_inbox_hmac_secret
 */
function em_inbox_get_or_create_hmac_secret() {
    $secret = get_option('em_inbox_hmac_secret');
    if (! $secret) {
        $secret = bin2hex(random_bytes(32));
        update_option('em_inbox_hmac_secret', $secret, false);
    }
    return $secret;
}

/* -------------------------------------------------------------------------
 * REST route
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/receive', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_receive_handler',
        'permission_callback' => 'em_inbox_verify_signature',
    ));
});

/**
 * Permission callback — verifies HMAC and replay window.
 * Runs before the body callback, so signature failure short-circuits.
 */
function em_inbox_verify_signature(WP_REST_Request $request) {
    $timestamp = isset($_SERVER[EM_INBOX_TS_HEADER]) ? (int) $_SERVER[EM_INBOX_TS_HEADER] : 0;
    $signature = isset($_SERVER[EM_INBOX_HMAC_HEADER]) ? (string) $_SERVER[EM_INBOX_HMAC_HEADER] : '';

    if ($timestamp <= 0 || $signature === '') {
        return new WP_Error('em_inbox_missing_auth', 'Missing signature headers', array('status' => 401));
    }

    if (abs(time() - $timestamp) > EM_INBOX_REPLAY_WINDOW) {
        return new WP_Error('em_inbox_stale', 'Timestamp outside replay window', array('status' => 401));
    }

    $secret = em_inbox_get_or_create_hmac_secret();
    $body   = $request->get_body();
    $expect = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    if (! hash_equals($expect, $signature)) {
        return new WP_Error('em_inbox_bad_sig', 'Invalid signature', array('status' => 401));
    }

    return true;
}

function em_inbox_receive_handler(WP_REST_Request $request) {
    global $wpdb;
    em_inbox_maybe_create_tables();

    $payload = $request->get_json_params();
    if (! is_array($payload)) {
        return new WP_Error('em_inbox_bad_payload', 'Body must be JSON', array('status' => 400));
    }

    $message_id = isset($payload['message_id']) ? (string) $payload['message_id'] : '';
    $recipient  = isset($payload['recipient'])  ? (string) $payload['recipient']  : '';
    $sender     = isset($payload['sender'])     ? (string) $payload['sender']     : '';

    if ($message_id === '' || $recipient === '' || $sender === '') {
        return new WP_Error('em_inbox_missing_fields', 'message_id, recipient, sender required', array('status' => 400));
    }

    $table = $wpdb->prefix . 'gdc_inbox_raw';

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE message_id = %s LIMIT 1",
        $message_id
    ));
    if ($existing) {
        return rest_ensure_response(array(
            'ok'        => true,
            'duplicate' => true,
            'id'        => (int) $existing,
        ));
    }

    $body_plain = isset($payload['body_plain']) ? (string) $payload['body_plain'] : '';
    $body_html  = isset($payload['body_html'])  ? (string) $payload['body_html']  : '';
    $size       = strlen($body_plain) + strlen($body_html);

    $inserted = $wpdb->insert(
        $table,
        array(
            'message_id'       => $message_id,
            'recipient'        => $recipient,
            'sender'           => $sender,
            'subject'          => isset($payload['subject']) ? mb_substr((string) $payload['subject'], 0, 998) : '',
            'raw_headers'      => isset($payload['headers']) ? wp_json_encode($payload['headers']) : null,
            'body_plain'       => $body_plain,
            'body_html'        => $body_html,
            'attachments_json' => isset($payload['attachments']) ? wp_json_encode($payload['attachments']) : null,
            'size_bytes'       => $size,
            'received_at'      => current_time('mysql', 1),
            'processed'        => 0,
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d')
    );

    if ($inserted === false) {
        return new WP_Error('em_inbox_db_error', $wpdb->last_error ?: 'insert failed', array('status' => 500));
    }

    $raw_id = (int) $wpdb->insert_id;

    // Synchronous threading — zero-latency path. The cron fallback handles
    // rows where this call fatally errors (PHP timeout, etc.); leaving
    // processed=0 here is the recovery hook.
    if (function_exists('em_inbox_thread_one')) {
        em_inbox_thread_one($raw_id);
    }

    return rest_ensure_response(array(
        'ok' => true,
        'id' => $raw_id,
    ));
}
