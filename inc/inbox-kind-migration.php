<?php
/**
 * Member Inbox: schema bump — add kind column to raw (slice 2k).
 *
 * Replaces the slice-2g/2f sender==recipient hack for distinguishing
 * inbound from outbound messages. With a real `kind` column, the
 * participant logic in inbox-participants.php can stop guessing, the
 * React UI can render outbound messages differently without the
 * "is my email address my email address" check, and future "Sent"
 * folders / IMAP-style mapping become straightforward.
 *
 * Migration: ALTER TABLE adds kind ENUM. On upgrade, existing rows
 * are classified from sender==recipient (the previous heuristic) so
 * historical sent messages don't suddenly look inbound.
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_KIND_DB_VERSION', '1.0.0');

function em_inbox_kind_maybe_migrate() {
    if (get_option('em_inbox_kind_db_version') === EM_INBOX_KIND_DB_VERSION) return;
    global $wpdb;
    $raw = $wpdb->prefix . 'gdc_inbox_raw';

    $has = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM $raw LIKE %s", 'kind'
    ));
    if (! $has) {
        $wpdb->query("ALTER TABLE $raw
            ADD COLUMN kind ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
            ADD KEY idx_kind_recipient (recipient, kind)");
        // Classify the backfill: rows where sender == recipient came
        // from the slice-2f mirror (outbound).
        $wpdb->query("UPDATE $raw SET kind = 'outbound'
                      WHERE LOWER(sender) = LOWER(recipient)");
    }
    update_option('em_inbox_kind_db_version', EM_INBOX_KIND_DB_VERSION);
}
add_action('admin_init',    'em_inbox_kind_maybe_migrate');
add_action('rest_api_init', 'em_inbox_kind_maybe_migrate');
