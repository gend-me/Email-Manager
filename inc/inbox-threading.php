<?php
/**
 * Member Inbox: JWZ conversational threading.
 *
 * Reads unprocessed rows from wp_gdc_inbox_raw, walks the JWZ algorithm
 * (Message-ID + In-Reply-To + References — NEVER subject line, which is
 * unreliable across locales and user edits), and materializes them into
 * wp_gdc_inbox_threads + wp_gdc_inbox_messages.
 *
 * Triggered synchronously by inbox-webhook.php after each insert (for
 * zero-latency in the happy path), plus a one-minute cron fallback that
 * scoops up any rows the synchronous path missed (e.g. fatal PHP error
 * during the worker call).
 *
 * The raw table remains the source of truth — threads/messages can be
 * rebuilt at any time from raw without data loss.
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_THREAD_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_thread_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $threads  = $wpdb->prefix . 'gdc_inbox_threads';
    $messages = $wpdb->prefix . 'gdc_inbox_messages';

    $sql_threads = "CREATE TABLE $threads (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        inbox_address varchar(255) NOT NULL,
        subject_first varchar(998) DEFAULT '',
        first_message_id bigint(20) UNSIGNED DEFAULT NULL,
        last_message_id bigint(20) UNSIGNED DEFAULT NULL,
        message_count int(10) UNSIGNED NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_inbox_updated (inbox_address, updated_at),
        KEY idx_last_message (last_message_id)
    ) $charset_collate;";

    $sql_messages = "CREATE TABLE $messages (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        thread_id bigint(20) UNSIGNED NOT NULL,
        raw_id bigint(20) UNSIGNED NOT NULL,
        message_id varchar(255) NOT NULL,
        in_reply_to varchar(255) DEFAULT NULL,
        refs_json longtext,
        sender varchar(255) NOT NULL,
        recipient varchar(255) NOT NULL,
        subject varchar(998) DEFAULT '',
        body_plain longtext,
        body_html longtext,
        received_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_message_id (message_id),
        UNIQUE KEY uniq_raw_id (raw_id),
        KEY idx_thread (thread_id),
        KEY idx_in_reply_to (in_reply_to),
        KEY idx_recipient_received (recipient, received_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_threads);
    dbDelta($sql_messages);

    update_option('em_inbox_thread_db_version', EM_INBOX_THREAD_DB_VERSION);
}

function em_inbox_thread_maybe_create_tables() {
    if (get_option('em_inbox_thread_db_version') !== EM_INBOX_THREAD_DB_VERSION) {
        em_inbox_thread_create_tables();
    }
}

/* -------------------------------------------------------------------------
 * Header extraction
 * ------------------------------------------------------------------------- */

/**
 * Pull the named header out of the raw_headers JSON array (case-insensitive).
 * The Haraka MTA serializes headers as [{name,value},...] preserving order.
 */
function em_inbox_thread_header($headers_array, $name) {
    if (! is_array($headers_array)) return '';
    $needle = strtolower($name);
    foreach ($headers_array as $h) {
        if (isset($h['name']) && strtolower($h['name']) === $needle) {
            return isset($h['value']) ? (string) $h['value'] : '';
        }
    }
    return '';
}

/**
 * Parse References / In-Reply-To header values into normalized Message-ID
 * tokens. Each token is the form `<...@...>`. RFC 5322 says References is
 * whitespace-separated; In-Reply-To is technically one msg-id but real
 * clients sometimes pile multiple in. Returns deduped, order-preserved.
 */
function em_inbox_thread_extract_msgids($value) {
    $value = (string) $value;
    if ($value === '') return array();
    if (! preg_match_all('/<[^<>\s]+>/', $value, $m)) return array();
    $seen = array();
    $out  = array();
    foreach ($m[0] as $mid) {
        if (! isset($seen[$mid])) { $out[] = $mid; $seen[$mid] = true; }
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * JWZ-style threading
 * ------------------------------------------------------------------------- */

/**
 * Process exactly one raw row into thread/message tables. Idempotent —
 * safe to call repeatedly with the same raw_id (UNIQUE KEY on raw_id
 * makes the duplicate INSERT a no-op). Returns the message row id on
 * success, false on hard error (the raw row stays processed=0 so the
 * cron fallback can retry).
 */
function em_inbox_thread_one($raw_id) {
    global $wpdb;
    $raw_table     = $wpdb->prefix . 'gdc_inbox_raw';
    $threads_table = $wpdb->prefix . 'gdc_inbox_threads';
    $msg_table     = $wpdb->prefix . 'gdc_inbox_messages';

    $raw = $wpdb->get_row($wpdb->prepare("SELECT * FROM $raw_table WHERE id = %d", $raw_id), ARRAY_A);
    if (! $raw) return false;

    // Short-circuit if this raw row was already threaded by an earlier call.
    $already = $wpdb->get_var($wpdb->prepare("SELECT id FROM $msg_table WHERE raw_id = %d", $raw_id));
    if ($already) {
        $wpdb->update($raw_table, array('processed' => 1), array('id' => $raw_id), array('%d'), array('%d'));
        return (int) $already;
    }

    $headers     = json_decode((string) $raw['raw_headers'], true);
    $in_reply_to = trim(em_inbox_thread_header($headers, 'In-Reply-To'));
    $references  = em_inbox_thread_extract_msgids(em_inbox_thread_header($headers, 'References'));

    // Lineage candidates: most-recent-parent (In-Reply-To) first, then
    // walk back through References. We take the FIRST match — JWZ proper
    // would also merge two threads that share a common ancestor, but in
    // practice that's a rare edge case best handled by a periodic
    // consolidation pass (Slice 2a+).
    $candidates = array();
    if ($in_reply_to !== '') {
        foreach (em_inbox_thread_extract_msgids($in_reply_to) as $mid) $candidates[] = $mid;
    }
    foreach ($references as $mid) $candidates[] = $mid;

    $thread_id = null;
    if (! empty($candidates)) {
        $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
        $thread_id = $wpdb->get_var($wpdb->prepare(
            "SELECT thread_id FROM $msg_table WHERE message_id IN ($placeholders) LIMIT 1",
            $candidates
        ));
    }

    // Out-of-order rescue: if this message is itself referenced by a
    // future message already in the table, adopt that thread.
    if (! $thread_id) {
        $thread_id = $wpdb->get_var($wpdb->prepare(
            "SELECT thread_id FROM $msg_table WHERE in_reply_to = %s LIMIT 1",
            $raw['message_id']
        ));
    }

    $now = current_time('mysql', 1);

    if (! $thread_id) {
        $wpdb->insert($threads_table, array(
            'inbox_address'   => $raw['recipient'],
            'subject_first'   => em_inbox_thread_strip_re_prefix((string) $raw['subject']),
            'first_message_id'=> null,
            'last_message_id' => null,
            'message_count'   => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ), array('%s', '%s', '%d', '%d', '%d', '%s', '%s'));
        $thread_id = (int) $wpdb->insert_id;
    }

    $inserted = $wpdb->insert($msg_table, array(
        'thread_id'   => $thread_id,
        'raw_id'      => $raw_id,
        'message_id'  => $raw['message_id'],
        'in_reply_to' => $in_reply_to !== '' ? $in_reply_to : null,
        'refs_json'   => ! empty($references) ? wp_json_encode($references) : null,
        'sender'      => $raw['sender'],
        'recipient'   => $raw['recipient'],
        'subject'     => (string) $raw['subject'],
        'body_plain'  => $raw['body_plain'],
        'body_html'   => $raw['body_html'],
        'received_at' => $raw['received_at'],
    ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'));

    if ($inserted === false) {
        // Either a UNIQUE violation (race) or a real DB error. Re-fetch and
        // bail — leaving processed=0 lets the cron retry if it was real.
        return false;
    }
    $msg_id = (int) $wpdb->insert_id;

    // Roll forward the thread aggregates. message_count + last_message
    // are denormalized so the inbox-list view can be a single-table query.
    $update = array(
        'last_message_id' => $msg_id,
        'message_count'   => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $msg_table WHERE thread_id = %d", $thread_id
        )),
        'updated_at'      => $raw['received_at'],
    );
    // Adopt first_message_id on the very first message (or recompute if
    // an earlier-arriving message was actually older).
    $earliest = $wpdb->get_row($wpdb->prepare(
        "SELECT id, received_at FROM $msg_table WHERE thread_id = %d ORDER BY received_at ASC, id ASC LIMIT 1",
        $thread_id
    ), ARRAY_A);
    if ($earliest) {
        $update['first_message_id'] = (int) $earliest['id'];
    }
    $wpdb->update($threads_table, $update, array('id' => $thread_id),
        array('%d', '%d', '%s', '%d'), array('%d'));

    // Mark raw processed.
    $wpdb->update($raw_table, array('processed' => 1), array('id' => $raw_id), array('%d'), array('%d'));

    return $msg_id;
}

/**
 * Strip leading Re:/Fwd:/etc. and assorted localized variants for the
 * subject_first display field. NOT used for threading decisions.
 */
function em_inbox_thread_strip_re_prefix($subject) {
    $subject = trim((string) $subject);
    // Strip up to ~5 levels of nested Re:/Fwd: prefixes in any locale.
    for ($i = 0; $i < 5; $i++) {
        $stripped = preg_replace('/^\s*(re|fw|fwd|aw|tr|sv|odp|rv|res|antw)(\[\d+\])?\s*:\s*/i', '', $subject);
        if ($stripped === $subject) break;
        $subject = $stripped;
    }
    return mb_substr($subject, 0, 998);
}

/* -------------------------------------------------------------------------
 * Batch + cron fallback
 * ------------------------------------------------------------------------- */

/**
 * Walk all unprocessed raw rows. Used by the cron fallback and the
 * one-shot rebuilder. Caps per-call to keep cron tick under a second.
 */
function em_inbox_thread_process_pending($limit = 100) {
    global $wpdb;
    $raw_table = $wpdb->prefix . 'gdc_inbox_raw';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $raw_table WHERE processed = 0 ORDER BY id ASC LIMIT %d",
        $limit
    ));
    $done = 0;
    foreach ($ids as $rid) {
        if (em_inbox_thread_one((int) $rid) !== false) $done++;
    }
    return $done;
}

// Cron fallback — every minute. Kept tight so a thread-processing bug
// doesn't snowball into a multi-thousand-row backlog before someone notices.
add_action('em_inbox_thread_cron', 'em_inbox_thread_process_pending');
add_filter('cron_schedules', function ($schedules) {
    if (! isset($schedules['em_inbox_minute'])) {
        $schedules['em_inbox_minute'] = array('interval' => 60, 'display' => 'EM Inbox — every minute');
    }
    return $schedules;
});
add_action('init', function () {
    if (! wp_next_scheduled('em_inbox_thread_cron')) {
        wp_schedule_event(time() + 60, 'em_inbox_minute', 'em_inbox_thread_cron');
    }
});
