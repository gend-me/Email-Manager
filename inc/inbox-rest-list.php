<?php
/**
 * Member Inbox: REST routes consumed by the React inbox UI.
 *
 *   GET /em/v1/inbox/inboxes             — list of distinct recipient addresses
 *                                          the current user can read (admin sees all)
 *   GET /em/v1/inbox/threads?inbox=…     — paged thread list for one inbox
 *   GET /em/v1/inbox/threads/{id}        — single thread + ordered messages
 *
 * Permission gate matches inbox-attachments.php: admins see everything;
 * other logged-in users see only threads where inbox_address ==
 * current_user->user_email (case-insensitive).
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {

    register_rest_route('em/v1', '/inbox/inboxes', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_list_inboxes',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));

    register_rest_route('em/v1', '/inbox/threads', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_list_threads',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'inbox'    => array('type' => 'string'),
            'page'     => array('type' => 'integer', 'default' => 1),
            'per_page' => array('type' => 'integer', 'default' => 25),
        ),
    ));

    register_rest_route('em/v1', '/inbox/threads/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_get_thread',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'id' => array('type' => 'integer', 'required' => true),
        ),
    ));
});

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

function em_inbox_user_can_read_inbox($inbox_address) {
    if (function_exists('em_inbox_current_user_can_read_address')) {
        return em_inbox_current_user_can_read_address($inbox_address);
    }
    // 2c fallback if user-provisioning module not loaded.
    if (current_user_can('manage_options')) return true;
    $u = wp_get_current_user();
    return $u && $u->user_email && strcasecmp($u->user_email, $inbox_address) === 0;
}

/* -------------------------------------------------------------------------
 * /inbox/inboxes
 * ------------------------------------------------------------------------- */

function em_inbox_list_inboxes(WP_REST_Request $request) {
    global $wpdb;
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $part    = $wpdb->prefix . 'gdc_inbox_participants';
    $user    = wp_get_current_user();
    $uid     = ($user && $user->ID) ? (int) $user->ID : 0;
    // Per-row unread aggregate: only counts threads the current user
    // hasn't read AND hasn't archived. Admins viewing other users'
    // inboxes get NULL for unread (they're not the owner — no concept
    // of "unread for the admin").
    $unread_subquery = $uid > 0
        ? $wpdb->prepare(
            "(SELECT COUNT(*) FROM $threads t2
              LEFT JOIN $part p2 ON p2.thread_id = t2.id AND p2.user_id = %d
              WHERE t2.inbox_address = inbox_address
                AND COALESCE(p2.is_read,1) = 0
                AND COALESCE(p2.is_archived,0) = 0)",
            $uid
        )
        : 'NULL';

    if (current_user_can('manage_options')) {
        $rows = $wpdb->get_results(
            "SELECT inbox_address,
                    COUNT(*) AS thread_count,
                    MAX(updated_at) AS last_received,
                    $unread_subquery AS unread_count
             FROM $threads GROUP BY inbox_address ORDER BY last_received DESC",
            ARRAY_A
        );
    } else {
        // Non-admin: union of inboxes by owner_user_id stamp (post-2e) and
        // the user's user_email + em_inbox_address (slice 2c fallback that
        // still picks up legacy threads which never got stamped).
        $u = wp_get_current_user();
        if (! $u || $u->ID === 0) return rest_ensure_response(array());
        $candidates = array();
        if ($u->user_email) $candidates[] = $u->user_email;
        $meta_addr = get_user_meta($u->ID, 'em_inbox_address', true);
        if ($meta_addr) $candidates[] = $meta_addr;
        $candidates = array_unique(array_filter($candidates));

        if (! empty($candidates)) {
            $ph = implode(',', array_fill(0, count($candidates), '%s'));
            $sql_args = array_merge($candidates, array($u->ID));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT inbox_address,
                        COUNT(*) AS thread_count,
                        MAX(updated_at) AS last_received,
                        $unread_subquery AS unread_count
                 FROM $threads
                 WHERE inbox_address IN ($ph) OR owner_user_id = %d
                 GROUP BY inbox_address",
                ...$sql_args
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT inbox_address,
                        COUNT(*) AS thread_count,
                        MAX(updated_at) AS last_received,
                        $unread_subquery AS unread_count
                 FROM $threads WHERE owner_user_id = %d GROUP BY inbox_address",
                $u->ID
            ), ARRAY_A);
        }

        // Slice 2ee: append inboxes the user has been granted access to.
        if (function_exists('em_inbox_grants_received_by')) {
            $grants = em_inbox_grants_received_by((int) $u->ID);
            $existing_addrs = array();
            foreach ((array) $rows as $r) $existing_addrs[strtolower($r['inbox_address'])] = true;
            foreach ($grants as $g) {
                // The grant's owner_email IS the inbox address (owners use
                // their email as inbox address by default).
                $addr = strtolower((string) $g['owner_email']);
                if ($addr === '' || isset($existing_addrs[$addr])) continue;
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT inbox_address,
                            COUNT(*) AS thread_count,
                            MAX(updated_at) AS last_received
                     FROM $threads
                     WHERE inbox_address = %s OR owner_user_id = %d
                     GROUP BY inbox_address",
                    $addr, (int) $g['owner_user_id']
                ), ARRAY_A);
                if (! $row) {
                    $row = array('inbox_address' => $addr, 'thread_count' => 0, 'last_received' => null);
                }
                $row['unread_count'] = null;  // delegate's unread is owner-scoped; out of scope for v1
                $row['shared']       = true;
                $row['scope']        = $g['scope'];
                $row['granted_by']   = (int) $g['owner_user_id'];
                $rows[] = $row;
                $existing_addrs[$addr] = true;
            }
        }
    }
    return rest_ensure_response($rows ?: array());
}

/* -------------------------------------------------------------------------
 * /inbox/unread-count (slice 2ii) — minimal-payload poll for the
 * notification bell. Returns unread + total for ONE inbox in a single
 * indexed COUNT(*). Read-permission gated.
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/unread-count', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_unread_count',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'inbox' => array('type' => 'string', 'required' => true),
        ),
    ));
});

function em_inbox_unread_count(WP_REST_Request $r) {
    global $wpdb;
    $inbox = trim((string) $r->get_param('inbox'));
    if ($inbox === '') return new WP_Error('em_unread_no_inbox', 'inbox required', array('status' => 400));
    $u   = wp_get_current_user();
    $uid = ($u && $u->ID) ? (int) $u->ID : 0;
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $part    = $wpdb->prefix . 'gdc_inbox_participants';

    // Slice 2oo: inbox='*' sums across every inbox the user can read.
    $is_all = ($inbox === '*' || $inbox === '__all__');
    if ($is_all) {
        if (current_user_can('manage_options')) {
            $where = '';
        } else {
            if ($uid <= 0) return rest_ensure_response(array('inbox' => '*', 'unread' => 0, 'total' => 0, 'latest_at' => null));
            $addrs = array();
            if ($u->user_email) $addrs[] = strtolower($u->user_email);
            $meta = strtolower((string) get_user_meta($uid, 'em_inbox_address', true));
            if ($meta) $addrs[] = $meta;
            if (function_exists('em_inbox_grants_received_by')) {
                foreach (em_inbox_grants_received_by($uid) as $g) {
                    if (! empty($g['owner_email'])) $addrs[] = strtolower($g['owner_email']);
                }
            }
            $addrs = array_values(array_unique($addrs));
            if (empty($addrs)) return rest_ensure_response(array('inbox' => '*', 'unread' => 0, 'total' => 0, 'latest_at' => null));
            $ph = implode(',', array_fill(0, count($addrs), '%s'));
            $where = $wpdb->prepare("WHERE LOWER(t.inbox_address) IN ($ph)", ...$addrs);
        }
        $sql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN COALESCE(p.is_read,1) = 0
                 AND COALESCE(p.is_archived,0) = 0
                 AND COALESCE(p.is_trashed,0) = 0
                 AND (p.snoozed_until IS NULL OR p.snoozed_until <= UTC_TIMESTAMP())
                THEN 1 ELSE 0 END) AS unread,
            MAX(t.updated_at) AS latest_at
         FROM $threads t
         LEFT JOIN $part p ON p.thread_id = t.id AND p.user_id = " . $uid . "
         $where";
        $row = $wpdb->get_row($sql, ARRAY_A);
        return rest_ensure_response(array(
            'inbox'    => '*',
            'unread'   => (int) ($row['unread'] ?? 0),
            'total'    => (int) ($row['total']  ?? 0),
            'latest_at'=> $row['latest_at'] ?? null,
        ));
    }

    if (function_exists('em_inbox_user_can_read_inbox') && ! em_inbox_user_can_read_inbox($inbox)) {
        return new WP_Error('em_unread_forbidden', 'Cannot read this inbox', array('status' => 403));
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN COALESCE(p.is_read,1) = 0
                 AND COALESCE(p.is_archived,0) = 0
                 AND COALESCE(p.is_trashed,0) = 0
                 AND (p.snoozed_until IS NULL OR p.snoozed_until <= UTC_TIMESTAMP())
                THEN 1 ELSE 0 END) AS unread,
            MAX(t.updated_at) AS latest_at
         FROM $threads t
         LEFT JOIN $part p ON p.thread_id = t.id AND p.user_id = %d
         WHERE t.inbox_address = %s",
        $uid, $inbox
    ), ARRAY_A);
    return rest_ensure_response(array(
        'inbox'    => $inbox,
        'unread'   => (int) ($row['unread'] ?? 0),
        'total'    => (int) ($row['total']  ?? 0),
        'latest_at'=> $row['latest_at'] ?? null,
    ));
}

/* -------------------------------------------------------------------------
 * /inbox/threads
 * ------------------------------------------------------------------------- */

function em_inbox_list_threads(WP_REST_Request $request) {
    global $wpdb;
    $threads_table  = $wpdb->prefix . 'gdc_inbox_threads';
    $messages_table = $wpdb->prefix . 'gdc_inbox_messages';

    $inbox     = trim((string) $request->get_param('inbox'));
    $page      = max(1, (int) $request->get_param('page'));
    $per_page  = max(1, min(100, (int) $request->get_param('per_page')));
    $offset    = ($page - 1) * $per_page;

    // Slice 2oo: inbox='*' means "every inbox the current user can
    // read" — own + delegated (read or read_send). Counts are summed
    // across the union; rows carry their inbox_address so the UI can
    // tag origin.
    $is_all = ($inbox === '*' || $inbox === '__all__');
    if (! $is_all && $inbox !== '' && ! em_inbox_user_can_read_inbox($inbox)) {
        return new WP_Error('em_inbox_forbidden', 'Cannot read this inbox', array('status' => 403));
    }

    if ($is_all) {
        // Build the readable set.
        if (current_user_can('manage_options')) {
            $where = '';  // admin sees all
        } else {
            $u = wp_get_current_user();
            if (! $u || ! $u->ID) return rest_ensure_response(array('items' => array(), 'total' => 0));
            $addrs = array();
            if ($u->user_email) $addrs[] = strtolower($u->user_email);
            $meta = strtolower((string) get_user_meta($u->ID, 'em_inbox_address', true));
            if ($meta) $addrs[] = $meta;
            if (function_exists('em_inbox_grants_received_by')) {
                foreach (em_inbox_grants_received_by((int) $u->ID) as $g) {
                    if (! empty($g['owner_email'])) $addrs[] = strtolower($g['owner_email']);
                }
            }
            $addrs = array_values(array_unique($addrs));
            if (empty($addrs)) return rest_ensure_response(array('items' => array(), 'total' => 0));
            $ph = implode(',', array_fill(0, count($addrs), '%s'));
            $where = $wpdb->prepare("WHERE LOWER(t.inbox_address) IN ($ph)", ...$addrs);
        }
    } elseif ($inbox !== '') {
        $where = $wpdb->prepare('WHERE t.inbox_address = %s', $inbox);
    } elseif (! current_user_can('manage_options')) {
        $u = wp_get_current_user();
        if (! $u || ! $u->user_email) return rest_ensure_response(array('items' => array(), 'total' => 0));
        $where = $wpdb->prepare('WHERE t.inbox_address = %s', $u->user_email);
    } else {
        $where = '';
    }

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $threads_table t $where");

    // Filters: ?unread=1, ?archived=1, ?trashed=1, ?starred=1, ?snoozed=1, ?label_id=N
    $only_unread   = (int) $request->get_param('unread')   === 1;
    $only_archived = (int) $request->get_param('archived') === 1;
    $only_trashed  = (int) $request->get_param('trashed')  === 1;
    $only_starred  = (int) $request->get_param('starred')  === 1;
    $only_snoozed  = (int) $request->get_param('snoozed')  === 1;
    $only_label_id = (int) $request->get_param('label_id');
    $part_table    = $wpdb->prefix . 'gdc_inbox_participants';
    $user          = wp_get_current_user();
    $user_id       = ($user && $user->ID) ? (int) $user->ID : 0;

    // Build optional WHERE additions for the active filter. Trash takes
    // precedence; archived/default both exclude trashed rows.
    $extra_where = '';
    $label_join  = '';
    if ($user_id > 0 && $only_label_id > 0) {
        // Filter to threads carrying this label for the current user.
        $label_join = $wpdb->prepare(
            " JOIN {$wpdb->prefix}gdc_inbox_thread_labels tl ON tl.thread_id = t.id AND tl.user_id = %d AND tl.label_id = %d",
            $user_id, $only_label_id
        );
        $extra_where = ' AND COALESCE(p.is_trashed, 0) = 0 ';
    } elseif ($user_id > 0 && $only_trashed) {
        $extra_where = ' AND COALESCE(p.is_trashed, 0) = 1 ';
    } elseif ($user_id > 0 && $only_snoozed) {
        $extra_where = ' AND p.snoozed_until IS NOT NULL AND p.snoozed_until > UTC_TIMESTAMP() AND COALESCE(p.is_trashed, 0) = 0 ';
    } elseif ($user_id > 0 && $only_starred) {
        $extra_where = ' AND COALESCE(p.is_starred, 0) = 1 AND COALESCE(p.is_trashed, 0) = 0 AND (p.snoozed_until IS NULL OR p.snoozed_until <= UTC_TIMESTAMP()) ';
    } elseif ($user_id > 0 && $only_unread) {
        $extra_where = ' AND COALESCE(p.is_read, 0) = 0 AND COALESCE(p.is_archived, 0) = 0 AND COALESCE(p.is_trashed, 0) = 0 AND (p.snoozed_until IS NULL OR p.snoozed_until <= UTC_TIMESTAMP()) ';
    } elseif ($user_id > 0 && $only_archived) {
        $extra_where = ' AND COALESCE(p.is_archived, 0) = 1 AND COALESCE(p.is_trashed, 0) = 0 ';
    } elseif ($user_id > 0) {
        // Default: hide archived, trashed, AND not-yet-resurfaced
        // snoozed threads from the main feed.
        $extra_where = ' AND COALESCE(p.is_archived, 0) = 0 AND COALESCE(p.is_trashed, 0) = 0 AND (p.snoozed_until IS NULL OR p.snoozed_until <= UTC_TIMESTAMP()) ';
    }

    $part_join = $user_id > 0
        ? $wpdb->prepare("LEFT JOIN $part_table p ON p.thread_id = t.id AND p.user_id = %d", $user_id)
        : '';

    $sql = $wpdb->prepare(
        "SELECT t.id, t.inbox_address, t.subject_first, t.message_count, t.updated_at,
                m.sender AS last_sender, m.subject AS last_subject, m.received_at AS last_received_at,
                COALESCE(p.is_read, 1)     AS is_read,
                COALESCE(p.is_archived, 0) AS is_archived,
                COALESCE(p.is_trashed, 0)  AS is_trashed,
                COALESCE(p.is_starred, 0)  AS is_starred,
                p.snoozed_until            AS snoozed_until
         FROM $threads_table t
         LEFT JOIN $messages_table m ON m.id = t.last_message_id
         $part_join
         $label_join
         $where $extra_where
         ORDER BY t.updated_at DESC
         LIMIT %d OFFSET %d",
        $per_page, $offset
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);

    // Batch-load labels for every visible thread (1 query regardless
    // of row count). Only meaningful for logged-in users; admins see
    // their own labels only (this is by design — labels are private).
    if ($user_id > 0 && $rows) {
        $tids = array_map('intval', array_column($rows, 'id'));
        $ph   = implode(',', array_fill(0, count($tids), '%d'));
        $args = array_merge($tids, array($user_id));
        $lbl_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tl.thread_id, l.id, l.name, l.color
             FROM {$wpdb->prefix}gdc_inbox_thread_labels tl
             JOIN {$wpdb->prefix}gdc_inbox_labels l ON l.id = tl.label_id
             WHERE tl.thread_id IN ($ph) AND tl.user_id = %d",
            ...$args
        ), ARRAY_A);
        $by_tid = array();
        foreach ($lbl_rows as $lr) {
            $by_tid[(int) $lr['thread_id']][] = array(
                'id' => (int) $lr['id'], 'name' => $lr['name'], 'color' => $lr['color'],
            );
        }
        foreach ($rows as &$r) {
            $r['labels'] = $by_tid[(int) $r['id']] ?? array();
        }
        unset($r);
    }

    // Per-inbox aggregate: unread + archived + trashed counts for the current user.
    // Slice 2oo: when inbox='*' (unified view), reuse the WHERE clause we
    // built above so counts span every readable inbox.
    $counts = array();
    if ($user_id > 0 && ($inbox !== '' || $is_all)) {
        if ($is_all) {
            $count_where = $where;  // already built earlier, includes WHERE keyword
            $count_sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN COALESCE(p.is_read,1) = 0 AND COALESCE(p.is_archived,0) = 0 AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS unread,
                SUM(CASE WHEN COALESCE(p.is_archived,0) = 1 AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS archived,
                SUM(CASE WHEN COALESCE(p.is_trashed,0)  = 1 THEN 1 ELSE 0 END) AS trashed,
                SUM(CASE WHEN COALESCE(p.is_starred,0)  = 1 AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS starred,
                SUM(CASE WHEN p.snoozed_until IS NOT NULL AND p.snoozed_until > UTC_TIMESTAMP() AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS snoozed
             FROM $threads_table t
             LEFT JOIN $part_table p ON p.thread_id = t.id AND p.user_id = " . (int) $user_id . " $count_where";
            $counts = $wpdb->get_row($count_sql, ARRAY_A);
        } else {
            $counts = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(p.is_read,1) = 0 AND COALESCE(p.is_archived,0) = 0 AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN COALESCE(p.is_archived,0) = 1 AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS archived,
                    SUM(CASE WHEN COALESCE(p.is_trashed,0)  = 1 THEN 1 ELSE 0 END) AS trashed,
                    SUM(CASE WHEN COALESCE(p.is_starred,0)  = 1 AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS starred,
                    SUM(CASE WHEN p.snoozed_until IS NOT NULL AND p.snoozed_until > UTC_TIMESTAMP() AND COALESCE(p.is_trashed,0) = 0 THEN 1 ELSE 0 END) AS snoozed
                 FROM $threads_table t
                 LEFT JOIN $part_table p ON p.thread_id = t.id AND p.user_id = %d
                 WHERE t.inbox_address = %s",
                $user_id, $inbox
            ), ARRAY_A);
        }
    }

    return rest_ensure_response(array(
        'items'    => $rows ?: array(),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'counts'   => $counts ?: null,
    ));
}

/* -------------------------------------------------------------------------
 * /inbox/threads/{id}
 * ------------------------------------------------------------------------- */

function em_inbox_get_thread(WP_REST_Request $request) {
    global $wpdb;
    $threads_table  = $wpdb->prefix . 'gdc_inbox_threads';
    $messages_table = $wpdb->prefix . 'gdc_inbox_messages';
    $id = (int) $request['id'];

    $thread = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $threads_table WHERE id = %d", $id
    ), ARRAY_A);
    if (! $thread) return new WP_Error('em_inbox_thread_404', 'Not found', array('status' => 404));

    if (! em_inbox_user_can_read_inbox($thread['inbox_address'])) {
        return new WP_Error('em_inbox_forbidden', 'Not authorized', array('status' => 403));
    }

    // Implicit mark-as-read on open. Only for the logged-in user; admins
    // viewing someone else's inbox don't flip the owner's read flag.
    $u = wp_get_current_user();
    if ($u && $u->ID && function_exists('em_inbox_part_apply')) {
        $owner_user_id = (int) $thread['owner_user_id'];
        if ($owner_user_id === (int) $u->ID) {
            em_inbox_part_apply((int) $thread['id'], (int) $u->ID, /*is_read=*/ true, /*is_archived=*/ null);
        }
    }

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT m.id, m.message_id, m.in_reply_to, m.sender, m.recipient, m.subject,
                m.body_plain, m.body_html, m.received_at,
                r.attachments_json,
                r.kind                  AS kind,
                r.delivery_status       AS delivery_status,
                r.delivery_attempts     AS delivery_attempts,
                r.delivery_last_error   AS delivery_last_error
         FROM $messages_table m
         LEFT JOIN {$wpdb->prefix}gdc_inbox_raw r ON r.id = m.raw_id
         WHERE m.thread_id = %d
         ORDER BY m.received_at ASC, m.id ASC",
        $id
    ), ARRAY_A);

    // Pull starred state + labels for the current viewer onto the
    // thread object so the ThreadView header can render them.
    if ($u && $u->ID) {
        $starred = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(is_starred,0) FROM {$wpdb->prefix}gdc_inbox_participants WHERE thread_id = %d AND user_id = %d",
            (int) $thread['id'], (int) $u->ID
        ));
        $thread['is_starred'] = $starred;
        $thread['labels'] = function_exists('em_inbox_labels_for_thread')
            ? em_inbox_labels_for_thread((int) $thread['id'], (int) $u->ID)
            : array();
    }

    // Sanitize HTML bodies + decode attachment JSON. Slice 2q hardens
    // the wp_kses pass and applies default-off remote-image blocking
    // unless the user has allowlisted this sender.
    $current_user = wp_get_current_user();
    $current_uid  = $current_user ? (int) $current_user->ID : 0;
    // Helper closure: pull X-EM-Auth-* synthetic headers out of the
    // raw_headers blob (set by webhook_forwarder, slice 2w).
    $auth_from_raw = function ($msg_id) use ($wpdb) {
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT r.raw_headers FROM {$wpdb->prefix}gdc_inbox_raw r
             JOIN {$wpdb->prefix}gdc_inbox_messages m ON m.raw_id = r.id
             WHERE m.id = %d", $msg_id
        ));
        if (! $raw) return null;
        $headers = json_decode((string) $raw, true);
        if (! is_array($headers)) return null;
        $out = array('summary' => null, 'spf' => null, 'dkim' => null, 'dmarc' => null);
        foreach ($headers as $h) {
            if (! isset($h['name'])) continue;
            $n = strtolower($h['name']);
            if ($n === 'x-em-auth-summary') $out['summary'] = $h['value'];
            elseif ($n === 'x-em-auth-spf')   $out['spf']   = $h['value'];
            elseif ($n === 'x-em-auth-dkim')  $out['dkim']  = $h['value'];
            elseif ($n === 'x-em-auth-dmarc') $out['dmarc'] = $h['value'];
        }
        return $out['summary'] ? $out : null;
    };

    foreach ($messages as &$msg) {
        // Slice 2s — decorate outbound messages with open_count, etc.
        $msg = apply_filters('em_inbox_thread_message_view', $msg);
        // Slice 2w — surface SPF/DKIM/DMARC verdict on inbound.
        if (($msg['kind'] ?? 'inbound') === 'inbound') {
            $msg['auth'] = $auth_from_raw((int) $msg['id']);
        }
        if (! empty($msg['body_html'])) {
            if (function_exists('em_inbox_sanitize_html')) {
                $msg['body_html'] = em_inbox_sanitize_html($msg['body_html']);
            } else {
                $msg['body_html'] = wp_kses($msg['body_html'], em_inbox_allowed_html_for_message());
            }
            // Outbound mirrors (sender == current user) never get image-
            // blocked — they're the user's own sends.
            $is_self = $current_user && $current_user->user_email
                && strcasecmp($msg['sender'], $current_user->user_email) === 0;
            $show_images = $is_self || em_inbox_user_shows_images_from($current_uid, $msg['sender']);
            $blocked = 0;
            if (! $show_images && function_exists('em_inbox_block_remote_images')) {
                list($msg['body_html'], $blocked) = em_inbox_block_remote_images($msg['body_html']);
            }
            $msg['images_blocked']    = $blocked;
            $msg['images_show_for_sender'] = $show_images;
        } else {
            $msg['images_blocked']    = 0;
            $msg['images_show_for_sender'] = true;
        }
        $msg['attachments'] = $msg['attachments_json']
            ? array_map('em_inbox_strip_attachment_secrets',
                json_decode($msg['attachments_json'], true) ?: array())
            : array();
        unset($msg['attachments_json']);
    }
    unset($msg);

    return rest_ensure_response(array(
        'thread'   => $thread,
        'messages' => $messages,
    ));
}

/**
 * Strip server-side fields from attachment refs before exposing to the
 * client. The UI only needs filename + content_type + size + idx (we
 * inject idx based on array position so the React component can build
 * the existing /inbox/message/{id}/attachment/{idx} URL).
 */
function em_inbox_strip_attachment_secrets($att) {
    return array(
        'filename'     => $att['filename']     ?? null,
        'content_type' => $att['content_type'] ?? null,
        'size'         => $att['size']         ?? 0,
        'content_id'   => $att['content_id']   ?? null,
        // Never expose gcs_bucket / gcs_object / content_b64 — the
        // download endpoint resolves those server-side.
    );
}

/**
 * Conservative HTML allowlist for inbound message bodies — strict
 * superset of safe email-rendering tags, no <script>/<iframe>/<object>/
 * <embed>/<form>/<style>. Inline event handlers stripped by wp_kses.
 */
function em_inbox_allowed_html_for_message() {
    return array(
        'a'      => array('href' => true, 'title' => true, 'rel' => true, 'target' => true),
        'b'      => array(), 'strong' => array(),
        'i'      => array(), 'em'     => array(),
        'u'      => array(), 'br'     => array(), 'p' => array(),
        'div'    => array('class' => true, 'style' => true),
        'span'   => array('class' => true, 'style' => true),
        'ul'     => array(), 'ol' => array(), 'li' => array(),
        'blockquote' => array('cite' => true),
        'pre'    => array(), 'code' => array(),
        'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
        'img'    => array('src' => true, 'alt' => true, 'width' => true, 'height' => true),
        'table'  => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(),
        'td'     => array('colspan' => true, 'rowspan' => true),
        'th'     => array('colspan' => true, 'rowspan' => true),
        'hr'     => array(),
    );
}
