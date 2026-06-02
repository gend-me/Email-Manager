<?php
/**
 * Member Inbox: contacts auto-extract + autocomplete (slice 2t).
 *
 * One table per WP user that accumulates everyone they've ever
 * received from or sent to:
 *
 *   wp_gdc_inbox_contacts
 *     user_id, email, display_name, source, message_count,
 *     first_seen_at, last_seen_at
 *
 * Upserted by the slice-2a/2e threading actions:
 *   em_inbox_thread_created    inbound  → contact = sender
 *   em_inbox_message_inserted  inbound  → contact = sender
 *                              outbound → contacts = each To address
 *
 * The thread's owner_user_id is the contact "owner". Admins viewing
 * other users' inboxes don't pollute that user's contact list.
 *
 * REST:
 *   GET /em/v1/inbox/contacts?q=…  — top-N autocomplete by last_seen
 *                                    + LIKE prefix on email/display_name
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_CONTACTS_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_contacts_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_contacts';

    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        email varchar(255) NOT NULL,
        display_name varchar(255) DEFAULT NULL,
        source varchar(32) NOT NULL DEFAULT 'auto',
        message_count int(10) UNSIGNED NOT NULL DEFAULT 1,
        first_seen_at datetime NOT NULL,
        last_seen_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_user_email (user_id, email),
        KEY idx_user_last_seen (user_id, last_seen_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_contacts_db_version', EM_INBOX_CONTACTS_DB_VERSION);
}

function em_inbox_contacts_maybe_create() {
    if (get_option('em_inbox_contacts_db_version') !== EM_INBOX_CONTACTS_DB_VERSION) {
        em_inbox_contacts_create_table();
    }
}
add_action('admin_init',    'em_inbox_contacts_maybe_create');
add_action('rest_api_init', 'em_inbox_contacts_maybe_create');

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/**
 * Parse "Name <email@host>" or just "email@host" into [email, name].
 * Returns null on invalid email.
 */
function em_inbox_contacts_parse_addr($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    if (preg_match('/^\s*(.*?)\s*<\s*([^<>]+)\s*>\s*$/', $raw, $m)) {
        $name  = trim($m[1], " \"'");
        $email = strtolower(trim($m[2]));
        return is_email($email) ? array($email, $name !== '' ? $name : null) : null;
    }
    $email = strtolower($raw);
    return is_email($email) ? array($email, null) : null;
}

/**
 * UPSERT contact for $user_id observing $email (with optional name).
 * Increments message_count + bumps last_seen_at on collision.
 */
function em_inbox_contacts_upsert($user_id, $email, $display_name = null, $source = 'auto') {
    global $wpdb;
    $user_id = (int) $user_id;
    if ($user_id <= 0 || ! is_email($email)) return;
    $table = $wpdb->prefix . 'gdc_inbox_contacts';
    $now   = current_time('mysql', 1);
    $email = strtolower($email);

    // The INSERT ... ON DUPLICATE KEY UPDATE pattern is the cheapest
    // atomic upsert MySQL gives us. Names are only updated when we
    // actually observe one (so an inbound "Frank <frank@…>" doesn't
    // get wiped by a later "<frank@…>" with no name).
    $sql = $wpdb->prepare(
        "INSERT INTO $table (user_id, email, display_name, source, message_count, first_seen_at, last_seen_at)
         VALUES (%d, %s, %s, %s, 1, %s, %s)
         ON DUPLICATE KEY UPDATE
            message_count = message_count + 1,
            last_seen_at = VALUES(last_seen_at),
            display_name = COALESCE(VALUES(display_name), display_name)",
        $user_id, $email, $display_name, $source, $now, $now
    );
    $wpdb->query($sql);
}

/**
 * Extract a To-list from a raw_headers JSON array (slice-1 webhook
 * stores headers as [{name,value},…]).
 */
function em_inbox_contacts_extract_tos($headers) {
    if (! is_array($headers)) return array();
    $out = array();
    foreach ($headers as $h) {
        if (! isset($h['name']) || strcasecmp($h['name'], 'To') !== 0) continue;
        $value = isset($h['value']) ? (string) $h['value'] : '';
        // To: can have multiple comma-separated addresses (each
        // optionally "Name <addr>"). preg_split on commas outside angle
        // brackets is overkill; comma-split with a careful regex works.
        foreach (preg_split('/,\s*(?![^<]*>)/', $value) as $piece) {
            $parsed = em_inbox_contacts_parse_addr($piece);
            if ($parsed) $out[] = $parsed;
        }
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Hooks — fire on every inserted message
 * ------------------------------------------------------------------------- */

add_action('em_inbox_message_inserted', 'em_inbox_contacts_on_message', 30, 3);

function em_inbox_contacts_on_message($msg_id, $thread_id, $raw_row) {
    global $wpdb;
    if (! is_array($raw_row)) return;
    $owner = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT owner_user_id FROM {$wpdb->prefix}gdc_inbox_threads WHERE id = %d", $thread_id
    ));
    if ($owner <= 0) return;

    $headers = is_array($raw_row['raw_headers'] ?? null)
        ? $raw_row['raw_headers']
        : json_decode((string) ($raw_row['raw_headers'] ?? ''), true);
    $kind = $raw_row['kind'] ?? 'inbound';

    if ($kind === 'outbound') {
        // Owner sent this — extract every To: address.
        foreach (em_inbox_contacts_extract_tos($headers) as $addr) {
            list($email, $name) = $addr;
            // Skip self-addressed messages (the mirror sender == recipient).
            if (strcasecmp($email, $raw_row['recipient'] ?? '') === 0) continue;
            em_inbox_contacts_upsert($owner, $email, $name, 'outbound_recipient');
        }
    } else {
        // Owner received this — extract the sender.
        // Sender can be "Name <addr>" too; prefer the From: header when present
        // (Haraka stores both stripped sender + header values).
        $from = null;
        if (is_array($headers)) {
            foreach ($headers as $h) {
                if (isset($h['name']) && strcasecmp($h['name'], 'From') === 0) {
                    $from = em_inbox_contacts_parse_addr($h['value'] ?? '');
                    break;
                }
            }
        }
        if (! $from) $from = em_inbox_contacts_parse_addr($raw_row['sender'] ?? '');
        if ($from) {
            list($email, $name) = $from;
            em_inbox_contacts_upsert($owner, $email, $name, 'inbound_sender');
        }
    }
}

/* -------------------------------------------------------------------------
 * REST: autocomplete
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/contacts', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_contacts_list',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'q'     => array('type' => 'string'),
            'limit' => array('type' => 'integer', 'default' => 10),
        ),
    ));
});

function em_inbox_contacts_list(WP_REST_Request $request) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return rest_ensure_response(array());
    $q     = trim((string) $request->get_param('q'));
    $limit = max(1, min(50, (int) $request->get_param('limit')));

    $table = $wpdb->prefix . 'gdc_inbox_contacts';
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT email, display_name, message_count, last_seen_at
             FROM $table
             WHERE user_id = %d AND (email LIKE %s OR display_name LIKE %s)
             ORDER BY last_seen_at DESC
             LIMIT %d",
            $u->ID, $like, $like, $limit
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT email, display_name, message_count, last_seen_at
             FROM $table
             WHERE user_id = %d
             ORDER BY last_seen_at DESC
             LIMIT %d",
            $u->ID, $limit
        ), ARRAY_A);
    }
    return rest_ensure_response($rows ?: array());
}

/* -------------------------------------------------------------------------
 * Backfill (wp-cli)
 * ------------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('em-inbox backfill-contacts', 'em_inbox_contacts_cli_backfill');
}

function em_inbox_contacts_cli_backfill($args, $assoc) {
    global $wpdb;
    $dry = isset($assoc['dry-run']);
    $rows = $wpdb->get_results(
        "SELECT r.id, r.recipient, r.sender, r.raw_headers, r.kind, t.owner_user_id
         FROM {$wpdb->prefix}gdc_inbox_raw r
         JOIN {$wpdb->prefix}gdc_inbox_messages m ON m.raw_id = r.id
         JOIN {$wpdb->prefix}gdc_inbox_threads  t ON t.id = m.thread_id
         WHERE t.owner_user_id IS NOT NULL",
        ARRAY_A
    );
    $n = 0;
    foreach ($rows as $raw) {
        if (! $dry) {
            // Re-fire the same hook the live path uses.
            em_inbox_contacts_on_message(0, (int) $wpdb->get_var($wpdb->prepare(
                "SELECT thread_id FROM {$wpdb->prefix}gdc_inbox_messages WHERE raw_id = %d", $raw['id']
            )), $raw);
        }
        $n++;
    }
    \WP_CLI::success("Backfilled $n message rows into contacts.");
}
