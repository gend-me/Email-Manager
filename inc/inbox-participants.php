<?php
/**
 * Member Inbox: per-user thread state — read/unread + archived (slice 2g).
 *
 * Schema (one row per (thread, user)):
 *   wp_gdc_inbox_participants
 *     thread_id, user_id, is_read, is_archived, last_read_at, …
 *
 * Lifecycle:
 *   - inbox-threading.php fires em_inbox_thread_created / em_inbox_message_inserted.
 *     On either, this module upserts a participant row for the thread's owner
 *     and stamps is_read=0 (a new message arrived → unread for the owner).
 *   - The owner's view of /em/v1/inbox/threads/{id} flips is_read=1 implicitly.
 *
 * REST surface (POST endpoints, current-user-only state changes):
 *   POST /em/v1/inbox/threads/{id}/read
 *   POST /em/v1/inbox/threads/{id}/unread
 *   POST /em/v1/inbox/threads/{id}/archive
 *   POST /em/v1/inbox/threads/{id}/unarchive
 *
 * Outbound mail (sent by the inbox owner themselves) does NOT mark the
 * thread unread — em_inbox_message_inserted listener checks the
 * recipient against the message's sender and skips when they match.
 *
 * Backfill is wp-cli: `wp em-inbox backfill-participants` — creates rows
 * for every existing thread + its owner_user_id (where set), with
 * is_read=1 (don't surface a sea of "unread" badges on first load).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_PART_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_part_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_participants';

    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        thread_id bigint(20) UNSIGNED NOT NULL,
        user_id bigint(20) UNSIGNED NOT NULL,
        is_read tinyint(1) NOT NULL DEFAULT 0,
        is_archived tinyint(1) NOT NULL DEFAULT 0,
        last_read_at datetime DEFAULT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_thread_user (thread_id, user_id),
        KEY idx_user_state (user_id, is_archived, is_read),
        KEY idx_thread (thread_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_part_db_version', EM_INBOX_PART_DB_VERSION);
}

function em_inbox_part_maybe_create_table() {
    if (get_option('em_inbox_part_db_version') !== EM_INBOX_PART_DB_VERSION) {
        em_inbox_part_create_table();
    }
}
add_action('admin_init',    'em_inbox_part_maybe_create_table');
add_action('rest_api_init', 'em_inbox_part_maybe_create_table');

/* -------------------------------------------------------------------------
 * Auto-upsert on new messages — fires from inbox-threading.php
 * ------------------------------------------------------------------------- */

add_action('em_inbox_thread_created',  'em_inbox_part_on_thread_created', 20, 2);
add_action('em_inbox_message_inserted','em_inbox_part_on_message_inserted', 20, 3);

function em_inbox_part_on_thread_created($thread_id, $raw_row) {
    // owner_user_id is stamped by the slice-2e listener, which fires at
    // priority 10. We fire at 20 so the column is already populated.
    em_inbox_part_upsert_for_thread((int) $thread_id, /*read=*/ 0);
}

function em_inbox_part_on_message_inserted($msg_id, $thread_id, $raw_row) {
    // If this message was SENT BY the inbox owner (mirrored outbound),
    // don't flip is_read=0. Owners shouldn't see their own sends as
    // unread.
    $owner_is_sender = false;
    if (! empty($raw_row['recipient']) && ! empty($raw_row['sender'])) {
        $owner_is_sender = strcasecmp($raw_row['recipient'], $raw_row['sender']) === 0;
    }
    em_inbox_part_upsert_for_thread((int) $thread_id, $owner_is_sender ? 1 : 0);
}

/**
 * INSERT or refresh the participant row for a thread's owner. Pure
 * upsert — caller decides whether the new state should mark unread.
 */
function em_inbox_part_upsert_for_thread($thread_id, $is_read_on_new_arrival) {
    global $wpdb;
    if (! $thread_id) return;
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $owner_user_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT owner_user_id FROM $threads WHERE id = %d", $thread_id
    ));
    if ($owner_user_id <= 0) return;

    $now   = current_time('mysql', 1);
    $part  = $wpdb->prefix . 'gdc_inbox_participants';
    $row   = $wpdb->get_row($wpdb->prepare(
        "SELECT id, is_read FROM $part WHERE thread_id = %d AND user_id = %d",
        $thread_id, $owner_user_id
    ), ARRAY_A);

    if ($row) {
        // If the new arrival should mark unread (i.e. arrived from external),
        // flip is_read=0 even if it was previously 1. Outbound mirror keeps
        // the existing read state intact.
        if (! $is_read_on_new_arrival) {
            $wpdb->update($part,
                array('is_read' => 0, 'updated_at' => $now),
                array('id' => (int) $row['id']),
                array('%d', '%s'), array('%d')
            );
        } else {
            $wpdb->update($part,
                array('updated_at' => $now),
                array('id' => (int) $row['id']),
                array('%s'), array('%d')
            );
        }
    } else {
        $wpdb->insert($part, array(
            'thread_id'    => $thread_id,
            'user_id'      => $owner_user_id,
            'is_read'      => $is_read_on_new_arrival ? 1 : 0,
            'is_archived'  => 0,
            'last_read_at' => $is_read_on_new_arrival ? $now : null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ), array('%d','%d','%d','%d','%s','%s','%s'));
    }
}

/* -------------------------------------------------------------------------
 * REST routes — state flips
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    $args = array('id' => array('type' => 'integer', 'required' => true));
    foreach (array('read','unread','archive','unarchive') as $action) {
        register_rest_route('em/v1', '/inbox/threads/(?P<id>\d+)/' . $action, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_part_action_' . $action,
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => $args,
        ));
    }
});

function em_inbox_part_action_read($r)      { return em_inbox_part_set_state($r, true,  null); }
function em_inbox_part_action_unread($r)    { return em_inbox_part_set_state($r, false, null); }
function em_inbox_part_action_archive($r)   { return em_inbox_part_set_state($r, null,  true); }
function em_inbox_part_action_unarchive($r) { return em_inbox_part_set_state($r, null,  false); }

function em_inbox_part_set_state(WP_REST_Request $request, $is_read, $is_archived) {
    global $wpdb;
    $tid = (int) $request['id'];
    $user = wp_get_current_user();
    if (! $user || $user->ID === 0) {
        return new WP_Error('em_inbox_part_no_user', 'Login required', array('status' => 401));
    }

    // Permission: user must own the thread (or be admin).
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $thread  = $wpdb->get_row($wpdb->prepare(
        "SELECT id, inbox_address, owner_user_id FROM $threads WHERE id = %d",
        $tid
    ), ARRAY_A);
    if (! $thread) return new WP_Error('em_inbox_part_404', 'Thread not found', array('status' => 404));

    $is_admin   = current_user_can('manage_options');
    $is_owner   = (int) $thread['owner_user_id'] === $user->ID;
    $email_owns = $user->user_email && strcasecmp($user->user_email, $thread['inbox_address']) === 0;
    $meta_addr  = get_user_meta($user->ID, 'em_inbox_address', true);
    $meta_owns  = $meta_addr && strcasecmp($meta_addr, $thread['inbox_address']) === 0;
    if (! ($is_admin || $is_owner || $email_owns || $meta_owns)) {
        return new WP_Error('em_inbox_part_forbidden', 'Not authorized', array('status' => 403));
    }

    em_inbox_part_apply($tid, $user->ID, $is_read, $is_archived);
    return rest_ensure_response(array('ok' => true, 'thread_id' => $tid));
}

function em_inbox_part_apply($thread_id, $user_id, $is_read, $is_archived) {
    global $wpdb;
    $part = $wpdb->prefix . 'gdc_inbox_participants';
    $now  = current_time('mysql', 1);
    $row  = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $part WHERE thread_id = %d AND user_id = %d",
        $thread_id, $user_id
    ), ARRAY_A);

    $set = array('updated_at' => $now);
    $fmt = array('%s');
    if ($is_read !== null) {
        $set['is_read']      = $is_read ? 1 : 0;
        $set['last_read_at'] = $is_read ? $now : null;
        $fmt[] = '%d'; $fmt[] = '%s';
    }
    if ($is_archived !== null) {
        $set['is_archived'] = $is_archived ? 1 : 0;
        $fmt[] = '%d';
    }

    if ($row) {
        $wpdb->update($part, $set, array('id' => (int) $row['id']), $fmt, array('%d'));
    } else {
        $insert = array_merge(array(
            'thread_id' => $thread_id, 'user_id' => $user_id,
            'is_read' => 0, 'is_archived' => 0, 'last_read_at' => null,
            'created_at' => $now,
        ), $set);
        $wpdb->insert($part, $insert);
    }
}

/* -------------------------------------------------------------------------
 * wp-cli backfill
 * ------------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('em-inbox backfill-participants', 'em_inbox_part_cli_backfill');
}

function em_inbox_part_cli_backfill($args, $assoc) {
    global $wpdb;
    $dry      = isset($assoc['dry-run']);
    $threads  = $wpdb->get_results(
        "SELECT id, owner_user_id, updated_at FROM {$wpdb->prefix}gdc_inbox_threads
         WHERE owner_user_id IS NOT NULL",
        ARRAY_A
    );
    $created = 0;
    foreach ($threads as $t) {
        $tid = (int) $t['id']; $uid = (int) $t['owner_user_id'];
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}gdc_inbox_participants
             WHERE thread_id = %d AND user_id = %d",
            $tid, $uid
        ));
        if ($exists) continue;
        if (! $dry) {
            $wpdb->insert($wpdb->prefix . 'gdc_inbox_participants', array(
                'thread_id'    => $tid,
                'user_id'      => $uid,
                'is_read'      => 1,                           // don't flood the UI with backfilled unreads
                'is_archived'  => 0,
                'last_read_at' => $t['updated_at'],
                'created_at'   => $t['updated_at'],
                'updated_at'   => $t['updated_at'],
            ), array('%d','%d','%d','%d','%s','%s','%s'));
        }
        $created++;
    }
    \WP_CLI::success("Participants created: $created");
}
