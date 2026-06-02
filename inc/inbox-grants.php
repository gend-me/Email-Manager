<?php
/**
 * Member Inbox: delegation / shared inboxes (slice 2ee).
 *
 * An owner can grant another WP user read or read+send access to their
 * inbox. The grantee then sees that owner's inbox alongside their own
 * in the inbox switcher and can act on it.
 *
 * Scopes:
 *   read       — list/read threads, mark read/unread/snooze, label
 *   read_send  — everything in read + send-as-owner via from_override
 *
 * Permission integration:
 *   em_inbox_current_user_can_read_address($addr) already gates list,
 *   read, search, attachments. We hook the em_inbox_grant_read filter
 *   inside it so the grant check is one extra path.
 *
 *   em_inbox_current_user_can_send_as($addr) is new and checks both
 *   ownership and read_send grants. Used by inbox-send.php to validate
 *   from_override outside the admin-only path.
 *
 * @package EmailManager
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_GRANTS_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_grants_maybe_create_table() {
    if (get_option('em_inbox_grants_db_version') === EM_INBOX_GRANTS_DB_VERSION) return;
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'gdc_inbox_grants';
    $sql = "CREATE TABLE $table (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_user_id   BIGINT UNSIGNED NOT NULL,
        grantee_user_id BIGINT UNSIGNED NOT NULL,
        scope           ENUM('read','read_send') NOT NULL DEFAULT 'read',
        expires_at      DATETIME NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_pair (owner_user_id, grantee_user_id),
        KEY idx_grantee (grantee_user_id, expires_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_grants_db_version', EM_INBOX_GRANTS_DB_VERSION);
}
add_action('admin_init',    'em_inbox_grants_maybe_create_table');
add_action('rest_api_init', 'em_inbox_grants_maybe_create_table');

/* -------------------------------------------------------------------------
 * Core lookups
 * ------------------------------------------------------------------------- */

/**
 * Return active (non-expired) grants where $user_id is the GRANTEE.
 * Each row: { id, owner_user_id, scope, expires_at, owner_email }.
 */
function em_inbox_grants_received_by($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    if ($user_id <= 0) return array();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT g.id, g.owner_user_id, g.scope, g.expires_at, u.user_email AS owner_email
         FROM {$wpdb->prefix}gdc_inbox_grants g
         JOIN {$wpdb->users} u ON u.ID = g.owner_user_id
         WHERE g.grantee_user_id = %d
           AND (g.expires_at IS NULL OR g.expires_at > UTC_TIMESTAMP())
         ORDER BY g.id DESC",
        $user_id
    ), ARRAY_A);
    return $rows ?: array();
}

/**
 * Return active grants where $user_id is the OWNER.
 * Each row: { id, grantee_user_id, scope, expires_at, grantee_email }.
 */
function em_inbox_grants_given_by($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    if ($user_id <= 0) return array();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT g.id, g.grantee_user_id, g.scope, g.expires_at, u.user_email AS grantee_email
         FROM {$wpdb->prefix}gdc_inbox_grants g
         JOIN {$wpdb->users} u ON u.ID = g.grantee_user_id
         WHERE g.owner_user_id = %d
         ORDER BY g.id DESC",
        $user_id
    ), ARRAY_A);
    return $rows ?: array();
}

/**
 * Does $user_id have a (non-expired) grant on the inbox owned by $address?
 * @param int    $user_id grantee
 * @param string $address inbox address whose owner we're checking against
 * @param string $required_scope 'read' (any grant) or 'read_send'
 */
function em_inbox_grants_user_has_grant_on_address($user_id, $address, $required_scope = 'read') {
    global $wpdb;
    $user_id = (int) $user_id;
    $address = trim((string) $address);
    if ($user_id <= 0 || $address === '') return false;

    // Resolve address → owner WP user. Prefer the helper from
    // user-provisioning; fall back to direct lookup.
    $owner = null;
    if (function_exists('em_inbox_user_by_address')) {
        $owner = em_inbox_user_by_address($address);
    }
    if (! $owner) {
        $owner = get_user_by('email', $address);
    }
    if (! $owner) return false;

    $scope_filter = $required_scope === 'read_send'
        ? "AND scope = 'read_send'"
        : '';  // 'read' satisfied by either scope
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, scope FROM {$wpdb->prefix}gdc_inbox_grants
         WHERE owner_user_id = %d AND grantee_user_id = %d
           AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
           $scope_filter
         LIMIT 1",
        (int) $owner->ID, $user_id
    ), ARRAY_A);
    return $row ? true : false;
}

/* -------------------------------------------------------------------------
 * Hook into the existing read-permission check
 * ------------------------------------------------------------------------- */

add_filter('em_inbox_can_read_address', 'em_inbox_grants_filter_read', 10, 3);

/**
 * @param bool   $allowed result so far
 * @param string $address inbox address being checked
 * @param int    $user_id current user ID
 */
function em_inbox_grants_filter_read($allowed, $address, $user_id) {
    if ($allowed) return true;
    if ($user_id <= 0) return false;
    return em_inbox_grants_user_has_grant_on_address($user_id, $address, 'read');
}

/**
 * Send-side check used by inbox-send.php when a non-admin requests
 * from_override. Returns true if the user is the owner OR has a
 * read_send grant on the target address.
 */
function em_inbox_current_user_can_send_as($address) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return false;
    if (current_user_can('manage_options')) return true;
    $assigned = get_user_meta($u->ID, 'em_inbox_address', true);
    if ($assigned && strcasecmp($assigned, $address) === 0) return true;
    if ($u->user_email && strcasecmp($u->user_email, $address) === 0) return true;
    return em_inbox_grants_user_has_grant_on_address((int) $u->ID, $address, 'read_send');
}

/* -------------------------------------------------------------------------
 * REST CRUD
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/grants', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_grants_rest_list',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_grants_rest_create',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
    register_rest_route('em/v1', '/inbox/grants/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => 'em_inbox_grants_rest_delete',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_grants_rest_list(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_grants_no_user', 'Login required', array('status' => 401));
    return rest_ensure_response(array(
        'given'    => em_inbox_grants_given_by((int) $u->ID),
        'received' => em_inbox_grants_received_by((int) $u->ID),
    ));
}

function em_inbox_grants_rest_create(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_grants_no_user', 'Login required', array('status' => 401));

    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();

    $grantee_email = strtolower(trim((string) ($body['grantee_email'] ?? '')));
    $scope         = strtolower(trim((string) ($body['scope'] ?? 'read')));
    $expires_at    = trim((string) ($body['expires_at'] ?? ''));

    if (! is_email($grantee_email)) {
        return new WP_Error('em_grants_bad_email', 'grantee_email must be a valid email', array('status' => 400));
    }
    if (! in_array($scope, array('read', 'read_send'), true)) {
        return new WP_Error('em_grants_bad_scope', 'scope must be "read" or "read_send"', array('status' => 400));
    }
    $exp_dt = null;
    if ($expires_at !== '') {
        $ts = strtotime($expires_at);
        if (! $ts || $ts <= time()) {
            return new WP_Error('em_grants_bad_expiry', 'expires_at must be a future ISO 8601 timestamp', array('status' => 400));
        }
        $exp_dt = gmdate('Y-m-d H:i:s', $ts);
    }

    $grantee = get_user_by('email', $grantee_email);
    if (! $grantee) {
        return new WP_Error('em_grants_grantee_404',
            'No WP user with email ' . $grantee_email . '. The grantee must have a member account first.',
            array('status' => 404));
    }
    if ((int) $grantee->ID === (int) $u->ID) {
        return new WP_Error('em_grants_self', 'You cannot grant access to yourself', array('status' => 400));
    }

    $table = $wpdb->prefix . 'gdc_inbox_grants';
    // Upsert: if a grant already exists for this pair, UPDATE the scope.
    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE owner_user_id = %d AND grantee_user_id = %d",
        (int) $u->ID, (int) $grantee->ID
    ));
    $now = current_time('mysql', 1);
    if ($existing) {
        $wpdb->update($table,
            array('scope' => $scope, 'expires_at' => $exp_dt, 'updated_at' => $now),
            array('id' => $existing),
            array('%s', '%s', '%s'), array('%d')
        );
        return rest_ensure_response(array('ok' => true, 'id' => $existing, 'updated' => true));
    }
    $wpdb->insert($table, array(
        'owner_user_id'   => (int) $u->ID,
        'grantee_user_id' => (int) $grantee->ID,
        'scope'           => $scope,
        'expires_at'      => $exp_dt,
        'created_at'      => $now,
        'updated_at'      => $now,
    ));
    return rest_ensure_response(array('ok' => true, 'id' => (int) $wpdb->insert_id, 'created' => true));
}

function em_inbox_grants_rest_delete(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_grants_no_user', 'Login required', array('status' => 401));
    $id = (int) $r['id'];
    $table = $wpdb->prefix . 'gdc_inbox_grants';
    $row = $wpdb->get_row($wpdb->prepare("SELECT owner_user_id, grantee_user_id FROM $table WHERE id = %d", $id), ARRAY_A);
    if (! $row) return new WP_Error('em_grants_404', 'Grant not found', array('status' => 404));
    $is_admin = current_user_can('manage_options');
    // Either party (or admin) can revoke.
    if (! $is_admin && (int) $row['owner_user_id'] !== (int) $u->ID && (int) $row['grantee_user_id'] !== (int) $u->ID) {
        return new WP_Error('em_grants_forbidden', 'Not your grant', array('status' => 403));
    }
    $wpdb->delete($table, array('id' => $id), array('%d'));
    return rest_ensure_response(array('ok' => true, 'id' => $id, 'deleted' => true));
}
