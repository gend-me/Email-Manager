<?php
/**
 * Member Inbox: outbound retry queue (slice 2f.2).
 *
 * Adds delivery-state columns to wp_gdc_inbox_raw so a failed relay
 * doesn't drop the message:
 *
 *   delivery_status        pending|sent|failed|retrying
 *   delivery_attempts      int
 *   delivery_last_error    text
 *   delivery_next_attempt_at  datetime — when the cron worker should
 *                             try again
 *   delivery_completed_at  datetime — when status flipped to 'sent'
 *
 * The send endpoint now ALWAYS inserts the mirror row (with the
 * appropriate initial state), so the user sees their sent message
 * in their thread regardless of relay outcome. A cron worker retries
 * 'retrying' rows on an exponential backoff (2m / 5m / 15m / 30m /
 * 1h / 3h / 12h, capped at 8 attempts → 'failed').
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_OUTQ_DB_VERSION', '1.0.0');
define('EM_INBOX_OUTQ_MAX_ATTEMPTS', 8);

/* -------------------------------------------------------------------------
 * Schema migration
 * ------------------------------------------------------------------------- */

function em_inbox_outq_maybe_migrate() {
    if (get_option('em_inbox_outq_db_version') === EM_INBOX_OUTQ_DB_VERSION) return;
    global $wpdb;
    $raw = $wpdb->prefix . 'gdc_inbox_raw';

    $cols = array_column($wpdb->get_results("DESCRIBE $raw"), 'Field');
    $needed = array(
        'delivery_status'         => "ENUM('pending','sent','failed','retrying') NOT NULL DEFAULT 'sent'",
        'delivery_attempts'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'delivery_last_error'     => 'TEXT NULL',
        'delivery_next_attempt_at'=> 'DATETIME NULL',
        'delivery_completed_at'   => 'DATETIME NULL',
    );
    $adds = array();
    foreach ($needed as $col => $type) {
        if (! in_array($col, $cols, true)) {
            $adds[] = "ADD COLUMN $col $type";
        }
    }
    if ($adds) {
        $wpdb->query("ALTER TABLE $raw " . implode(', ', $adds)
            . ", ADD KEY idx_delivery_retry (delivery_status, delivery_next_attempt_at)");
        // Backfill: inbound rows are 'sent' by default; nothing to do for them.
        // Existing outbound mirrors (from before 2f.2) are also 'sent' since
        // they only got mirrored on relay success.
    }
    update_option('em_inbox_outq_db_version', EM_INBOX_OUTQ_DB_VERSION);
}
add_action('admin_init',    'em_inbox_outq_maybe_migrate');
add_action('rest_api_init', 'em_inbox_outq_maybe_migrate');

/* -------------------------------------------------------------------------
 * Backoff schedule
 * ------------------------------------------------------------------------- */

/**
 * Return the next-attempt timestamp for an outbound row that just
 * failed its N-th attempt (1-indexed). Caller is responsible for
 * checking attempts < EM_INBOX_OUTQ_MAX_ATTEMPTS first.
 */
function em_inbox_outq_next_attempt_at($attempts) {
    // 2m, 5m, 15m, 30m, 1h, 3h, 12h, then 12h thereafter.
    $minutes = array(2, 5, 15, 30, 60, 180, 720);
    $idx     = max(0, min((int) $attempts - 1, count($minutes) - 1));
    return gmdate('Y-m-d H:i:s', time() + 60 * $minutes[$idx]);
}

/* -------------------------------------------------------------------------
 * Submission helper — called by both inbox-send.php and the cron worker.
 *
 * Returns ['ok' => bool, 'http' => int, 'error' => string|null].
 * ------------------------------------------------------------------------- */

function em_inbox_outq_submit_one($from, $to, $subject, $body_plain, $body_html,
                                  $headers, $message_id, $attachments, $extras = array()) {
    $secret = get_option('em_inbox_hmac_secret');
    if (! $secret) return array('ok' => false, 'http' => 500, 'error' => 'HMAC secret missing');

    $cc  = is_array($extras['cc']  ?? null) ? $extras['cc']  : array();
    $bcc = is_array($extras['bcc'] ?? null) ? $extras['bcc'] : array();

    $body_json = wp_json_encode(array(
        'from'        => $from,
        'to'          => $to,
        'cc'          => $cc,
        'bcc'         => $bcc,
        'subject'     => $subject,
        'body_plain'  => $body_plain,
        'body_html'   => $body_html,
        'headers'     => $headers,
        'message_id'  => $message_id,
        'attachments' => $attachments,
    ));
    $ts  = (string) time();
    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body_json, $secret);

    $url = apply_filters('em_inbox_submit_url', defined('EM_INBOX_SUBMIT_URL_DEFAULT') ? EM_INBOX_SUBMIT_URL_DEFAULT : 'http://email-mta-submit:8080/submit');
    $resp = wp_remote_post($url, array(
        'headers' => array(
            'Content-Type'             => 'application/json',
            'X-EM-Submit-Timestamp'    => $ts,
            'X-EM-Submit-Signature'    => $sig,
        ),
        'body'    => $body_json,
        'timeout' => 30,
    ));
    if (is_wp_error($resp)) {
        return array('ok' => false, 'http' => 0, 'error' => $resp->get_error_message(), 'relay' => null);
    }
    $code  = wp_remote_retrieve_response_code($resp);
    $rbody = wp_remote_retrieve_body($resp);
    $relay = json_decode($rbody, true);
    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'http' => $code, 'error' => null, 'relay' => $relay);
    }
    $err = is_array($relay) && ! empty($relay['error']) ? $relay['error'] : ('HTTP ' . $code);
    return array('ok' => false, 'http' => $code, 'error' => substr($err, 0, 500), 'relay' => $relay);
}

/* -------------------------------------------------------------------------
 * Cron worker — drains the retry queue
 * ------------------------------------------------------------------------- */

add_action('cron_schedules', function ($schedules) {
    if (! isset($schedules['em_inbox_minute'])) {
        $schedules['em_inbox_minute'] = array('interval' => 60, 'display' => 'EM Inbox — every minute');
    }
    return $schedules;
});
add_action('init', function () {
    if (! wp_next_scheduled('em_inbox_outq_cron')) {
        wp_schedule_event(time() + 60, 'em_inbox_minute', 'em_inbox_outq_cron');
    }
});
add_action('em_inbox_outq_cron', 'em_inbox_outq_drain');

function em_inbox_outq_drain($limit = 10) {
    global $wpdb;
    $raw = $wpdb->prefix . 'gdc_inbox_raw';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $raw
         WHERE kind = 'outbound'
           AND delivery_status = 'retrying'
           AND (delivery_next_attempt_at IS NULL OR delivery_next_attempt_at <= UTC_TIMESTAMP())
         ORDER BY delivery_next_attempt_at ASC, id ASC
         LIMIT %d", $limit
    ), ARRAY_A);

    $now = current_time('mysql', 1);
    foreach ($rows as $row) {
        $headers     = json_decode((string) $row['raw_headers'], true);
        $attachments = json_decode((string) $row['attachments_json'], true);
        if (! is_array($headers))     $headers     = array();
        if (! is_array($attachments)) $attachments = array();

        $to_addrs = array();
        foreach ($headers as $h) {
            if (isset($h['name']) && strcasecmp($h['name'], 'To') === 0 && isset($h['value'])) {
                foreach (preg_split('/[,;\s]+/', (string) $h['value'], -1, PREG_SPLIT_NO_EMPTY) as $a) {
                    if (is_email($a)) $to_addrs[] = strtolower($a);
                }
            }
        }
        if (empty($to_addrs)) {
            // Can't retry without a recipient — give up.
            $wpdb->update($raw, array(
                'delivery_status'     => 'failed',
                'delivery_last_error' => 'No To: header on retry',
            ), array('id' => (int) $row['id']), array('%s', '%s'), array('%d'));
            continue;
        }

        $result = em_inbox_outq_submit_one(
            $row['sender'], $to_addrs, $row['subject'],
            $row['body_plain'], $row['body_html'],
            $headers, $row['message_id'], $attachments
        );

        $attempts = (int) $row['delivery_attempts'] + 1;
        if ($result['ok']) {
            $wpdb->update($raw, array(
                'delivery_status'       => 'sent',
                'delivery_attempts'     => $attempts,
                'delivery_completed_at' => $now,
                'delivery_last_error'   => null,
                'delivery_next_attempt_at' => null,
            ), array('id' => (int) $row['id']), array('%s','%d','%s','%s','%s'), array('%d'));
        } elseif ($attempts >= EM_INBOX_OUTQ_MAX_ATTEMPTS) {
            $wpdb->update($raw, array(
                'delivery_status'     => 'failed',
                'delivery_attempts'   => $attempts,
                'delivery_last_error' => (string) $result['error'],
                'delivery_next_attempt_at' => null,
            ), array('id' => (int) $row['id']), array('%s','%d','%s','%s'), array('%d'));
        } else {
            $wpdb->update($raw, array(
                'delivery_status'        => 'retrying',
                'delivery_attempts'      => $attempts,
                'delivery_last_error'    => (string) $result['error'],
                'delivery_next_attempt_at' => em_inbox_outq_next_attempt_at($attempts),
            ), array('id' => (int) $row['id']), array('%s','%d','%s','%s'), array('%d'));
        }
    }
    return count($rows);
}
