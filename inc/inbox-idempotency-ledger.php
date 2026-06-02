<?php
/**
 * Member Inbox: webhook idempotency ledger (slice 2o).
 *
 * The receive endpoint already dedupes inserts via the
 * wp_gdc_inbox_raw.message_id UNIQUE key, but that's a per-message
 * guarantee, not a per-delivery one. A duplicate webhook POST (MTA
 * retried after a network blip, or a provider's at-least-once
 * delivery semantics) does a full insert attempt + parse + cron-
 * triggering work before the UNIQUE constraint kicks in.
 *
 * This ledger gives short-circuit dedupe at the FIRST byte of the
 * handler: the MTA stamps a UUID into X-EM-Event-ID; the receive
 * handler inserts into this table BEFORE any other work. A duplicate
 * event_id collides on the PK and the handler returns 200 with
 * {duplicate: true, original_raw_id: N} without touching anything else.
 *
 * Retention: a daily cron prunes entries older than 7 days
 * (replay window is 5 min; the rest is for ops/diagnostics history).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_LEDGER_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_ledger_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_event_ledger';

    $sql = "CREATE TABLE $table (
        event_id varchar(64) NOT NULL,
        received_at datetime NOT NULL,
        raw_id bigint(20) UNSIGNED DEFAULT NULL,
        source varchar(32) DEFAULT NULL,
        PRIMARY KEY  (event_id),
        KEY idx_received (received_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_ledger_db_version', EM_INBOX_LEDGER_DB_VERSION);
}

function em_inbox_ledger_maybe_create_table() {
    if (get_option('em_inbox_ledger_db_version') !== EM_INBOX_LEDGER_DB_VERSION) {
        em_inbox_ledger_create_table();
    }
}
add_action('admin_init',    'em_inbox_ledger_maybe_create_table');
add_action('rest_api_init', 'em_inbox_ledger_maybe_create_table');

/* -------------------------------------------------------------------------
 * Helpers — used by inbox-webhook.php
 * ------------------------------------------------------------------------- */

/**
 * Try to record this event. Returns:
 *   ['duplicate' => false]                       — first time we've seen it
 *   ['duplicate' => true, 'original_raw_id' => N] — seen before
 *
 * The INSERT IGNORE pattern atomically discriminates between the two
 * without a separate SELECT-then-INSERT race.
 */
function em_inbox_ledger_record($event_id, $source = null) {
    global $wpdb;
    if (empty($event_id)) return array('duplicate' => false);
    $table = $wpdb->prefix . 'gdc_inbox_event_ledger';
    $event_id = substr((string) $event_id, 0, 64);

    $now = current_time('mysql', 1);
    $sql = $wpdb->prepare(
        "INSERT IGNORE INTO $table (event_id, received_at, source) VALUES (%s, %s, %s)",
        $event_id, $now, $source
    );
    $wpdb->query($sql);
    if ($wpdb->rows_affected > 0) {
        return array('duplicate' => false);
    }
    $original = $wpdb->get_var($wpdb->prepare(
        "SELECT raw_id FROM $table WHERE event_id = %s", $event_id
    ));
    return array('duplicate' => true, 'original_raw_id' => $original ? (int) $original : null);
}

/**
 * Stamp the freshly-inserted raw row id back onto the ledger entry so
 * a future duplicate can be told what the original delivery produced.
 */
function em_inbox_ledger_stamp_raw_id($event_id, $raw_id) {
    global $wpdb;
    if (empty($event_id) || ! $raw_id) return;
    $wpdb->update(
        $wpdb->prefix . 'gdc_inbox_event_ledger',
        array('raw_id' => (int) $raw_id),
        array('event_id' => substr((string) $event_id, 0, 64)),
        array('%d'), array('%s')
    );
}

/* -------------------------------------------------------------------------
 * Daily prune
 * ------------------------------------------------------------------------- */

add_action('init', function () {
    if (! wp_next_scheduled('em_inbox_ledger_prune')) {
        wp_schedule_event(time() + 3600, 'daily', 'em_inbox_ledger_prune');
    }
});
add_action('em_inbox_ledger_prune', function () {
    global $wpdb;
    $cutoff = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}gdc_inbox_event_ledger WHERE received_at < %s", $cutoff
    ));
});
