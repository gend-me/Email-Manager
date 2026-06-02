<?php
/**
 * Member Inbox: custom labels (slice 2r.1).
 *
 * Free-form user-created labels with auto-assigned colors. Per-user
 * (a label is owned by the user who created it; users can't see
 * each other's labels — admins can see and act on any).
 *
 * Two tables:
 *   wp_gdc_inbox_labels         id, user_id, name, color, created_at
 *   wp_gdc_inbox_thread_labels  thread_id, label_id, user_id (denormalized
 *                               for the cross-user permission check)
 *
 * REST:
 *   GET    /em/v1/inbox/labels                    list current user's labels
 *   POST   /em/v1/inbox/labels  {name,color?}     create
 *   DELETE /em/v1/inbox/labels/{id}               delete (cascades thread_labels)
 *   PUT    /em/v1/inbox/threads/{id}/labels {label_ids:[…]}  set (replace) thread labels
 *
 * Feed query supports ?label_id=N — filter to threads tagged with
 * that label. Permission gate ensures the label belongs to the
 * current user (or admin).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_LABELS_DB_VERSION', '1.0.0');

// Default rotating color palette for new labels — chosen to be readable
// on both light and dark wp-admin themes.
function em_inbox_label_palette() {
    return array('#2271b1', '#00692b', '#b32d2e', '#8a5a00', '#7c3aed', '#1c64f2', '#d6336c', '#0891b2');
}

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_labels_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $labels    = $wpdb->prefix . 'gdc_inbox_labels';
    $thread_l  = $wpdb->prefix . 'gdc_inbox_thread_labels';

    $sql_labels = "CREATE TABLE $labels (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        name varchar(64) NOT NULL,
        color varchar(7) NOT NULL DEFAULT '#646970',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_user_name (user_id, name),
        KEY idx_user (user_id)
    ) $charset_collate;";

    $sql_thread_l = "CREATE TABLE $thread_l (
        thread_id bigint(20) UNSIGNED NOT NULL,
        label_id bigint(20) UNSIGNED NOT NULL,
        user_id bigint(20) UNSIGNED NOT NULL,
        PRIMARY KEY  (thread_id, label_id),
        KEY idx_user_label (user_id, label_id),
        KEY idx_thread (thread_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_labels);
    dbDelta($sql_thread_l);
    update_option('em_inbox_labels_db_version', EM_INBOX_LABELS_DB_VERSION);
}

function em_inbox_labels_maybe_create() {
    if (get_option('em_inbox_labels_db_version') !== EM_INBOX_LABELS_DB_VERSION) {
        em_inbox_labels_create_tables();
    }
}
add_action('admin_init',    'em_inbox_labels_maybe_create');
add_action('rest_api_init', 'em_inbox_labels_maybe_create');

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

function em_inbox_labels_for_user($user_id) {
    global $wpdb;
    if (! $user_id) return array();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, color FROM {$wpdb->prefix}gdc_inbox_labels WHERE user_id = %d ORDER BY name ASC",
        $user_id
    ), ARRAY_A) ?: array();
}

function em_inbox_labels_for_thread($thread_id, $user_id) {
    global $wpdb;
    if (! $thread_id || ! $user_id) return array();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT l.id, l.name, l.color
         FROM {$wpdb->prefix}gdc_inbox_thread_labels tl
         JOIN {$wpdb->prefix}gdc_inbox_labels l ON l.id = tl.label_id
         WHERE tl.thread_id = %d AND tl.user_id = %d
         ORDER BY l.name ASC",
        $thread_id, $user_id
    ), ARRAY_A) ?: array();
}

/* -------------------------------------------------------------------------
 * REST routes
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/labels', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_labels_list',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_labels_create',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));

    register_rest_route('em/v1', '/inbox/labels/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => 'em_inbox_labels_delete',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));

    register_rest_route('em/v1', '/inbox/threads/(?P<id>\d+)/labels', array(
        'methods'             => array('PUT', 'POST'),
        'callback'            => 'em_inbox_labels_set_for_thread',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_labels_list(WP_REST_Request $r) {
    $u = wp_get_current_user();
    return rest_ensure_response(em_inbox_labels_for_user($u ? $u->ID : 0));
}

function em_inbox_labels_create(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_no_user', 'Login required', array('status' => 401));
    $body  = $r->get_json_params() ?: array();
    $name  = trim((string) ($body['name']  ?? ''));
    $color = trim((string) ($body['color'] ?? ''));
    if ($name === '') return new WP_Error('em_label_no_name', 'name required', array('status' => 400));
    if (mb_strlen($name) > 64) return new WP_Error('em_label_long', 'name max 64 chars', array('status' => 400));
    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        // Auto-assign from palette based on existing label count.
        $palette = em_inbox_label_palette();
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}gdc_inbox_labels WHERE user_id = %d", $u->ID));
        $color = $palette[$count % count($palette)];
    }
    $row = array(
        'user_id'    => $u->ID,
        'name'       => $name,
        'color'      => strtolower($color),
        'created_at' => current_time('mysql', 1),
    );
    $ok = $wpdb->insert($wpdb->prefix . 'gdc_inbox_labels', $row, array('%d','%s','%s','%s'));
    if ($ok === false) {
        // UNIQUE collision on (user_id, name) — return the existing row instead.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, color FROM {$wpdb->prefix}gdc_inbox_labels WHERE user_id = %d AND name = %s",
            $u->ID, $name
        ), ARRAY_A);
        if ($existing) return rest_ensure_response($existing);
        return new WP_Error('em_label_dup', $wpdb->last_error ?: 'insert failed', array('status' => 400));
    }
    return rest_ensure_response(array('id' => (int) $wpdb->insert_id, 'name' => $name, 'color' => $color));
}

function em_inbox_labels_delete(WP_REST_Request $r) {
    global $wpdb;
    $u  = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_no_user', 'Login required', array('status' => 401));
    $id = (int) $r['id'];
    $is_admin = current_user_can('manage_options');
    $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}gdc_inbox_labels WHERE id = %d", $id));
    if (! $owner) return new WP_Error('em_label_404', 'Not found', array('status' => 404));
    if (! ($is_admin || $owner === (int) $u->ID)) return new WP_Error('em_label_forbidden', 'Not authorized', array('status' => 403));
    $wpdb->delete($wpdb->prefix . 'gdc_inbox_thread_labels', array('label_id' => $id), array('%d'));
    $wpdb->delete($wpdb->prefix . 'gdc_inbox_labels',        array('id'       => $id), array('%d'));
    return rest_ensure_response(array('ok' => true, 'id' => $id, 'deleted' => true));
}

function em_inbox_labels_set_for_thread(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_no_user', 'Login required', array('status' => 401));
    $tid  = (int) $r['id'];
    $body = $r->get_json_params() ?: array();
    $ids  = is_array($body['label_ids'] ?? null) ? array_map('intval', $body['label_ids']) : array();
    $ids  = array_values(array_unique(array_filter($ids, function ($n) { return $n > 0; })));

    // Permission: user must own the thread (or admin).
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $thread  = $wpdb->get_row($wpdb->prepare("SELECT id, inbox_address, owner_user_id FROM $threads WHERE id = %d", $tid), ARRAY_A);
    if (! $thread) return new WP_Error('em_label_thread_404', 'Thread not found', array('status' => 404));
    $is_admin   = current_user_can('manage_options');
    $is_owner   = (int) $thread['owner_user_id'] === (int) $u->ID;
    $meta_addr  = get_user_meta($u->ID, 'em_inbox_address', true);
    $owns_addr  = ($u->user_email && strcasecmp($u->user_email, $thread['inbox_address']) === 0)
               || ($meta_addr   && strcasecmp($meta_addr,        $thread['inbox_address']) === 0);
    if (! ($is_admin || $is_owner || $owns_addr)) return new WP_Error('em_label_forbidden', 'Not authorized', array('status' => 403));

    // Validate each label belongs to current user.
    if (! empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        $sql_args = array_merge($ids, array($u->ID));
        $valid_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}gdc_inbox_labels WHERE id IN ($ph) AND user_id = %d",
            ...$sql_args
        ));
        $ids = array_map('intval', $valid_ids);
    }

    $tl_table = $wpdb->prefix . 'gdc_inbox_thread_labels';
    $wpdb->delete($tl_table, array('thread_id' => $tid, 'user_id' => $u->ID), array('%d','%d'));
    foreach ($ids as $lid) {
        $wpdb->insert($tl_table, array(
            'thread_id' => $tid, 'label_id' => $lid, 'user_id' => $u->ID,
        ), array('%d','%d','%d'));
    }
    return rest_ensure_response(array('ok' => true, 'thread_id' => $tid, 'labels' => em_inbox_labels_for_thread($tid, $u->ID)));
}
