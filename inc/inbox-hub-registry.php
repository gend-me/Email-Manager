<?php
/**
 * Member Inbox: hub-side domain → container registry.
 *
 * One table on the hub (gend.me) mapping recipient_domain → container
 * namespace + MTA static IP. This is a PROVISIONING aid, not a runtime
 * routing surface — per-container MTAs already POST to their own
 * in-namespace WordPress service, so no hot-path lookup is needed.
 *
 * What the registry buys us:
 *   - Single source of truth for "which customer owns which inbox domain"
 *   - The data backing future provisioning automation (one command to
 *     spin up an MTA + LB IP + DNS records + WP HMAC secret)
 *   - An ops dashboard surface so the operator can see every provisioned
 *     inbox in one place
 *
 * DEPLOYMENT: file ships in every email-manager install, but the table +
 * REST routes activate only when `em_inbox_registry_enabled` is truthy
 * (set via `wp option update em_inbox_registry_enabled 1` on the hub
 * site). Customer containers see the file but it does nothing.
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_REGISTRY_DB_VERSION', '1.0.0');

function em_inbox_registry_enabled() {
    return (bool) get_option('em_inbox_registry_enabled', false);
}

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_registry_create_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'gdc_inbox_domain_registry';

    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        recipient_domain varchar(253) NOT NULL,
        container_namespace varchar(63) NOT NULL,
        container_url varchar(255) NOT NULL,
        mta_static_ip varchar(45) NOT NULL,
        mta_static_ip_name varchar(63) DEFAULT NULL,
        gcs_key_prefix varchar(255) NOT NULL,
        owner_user_id bigint(20) UNSIGNED DEFAULT NULL,
        notes text,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_domain (recipient_domain),
        KEY idx_namespace (container_namespace)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_registry_db_version', EM_INBOX_REGISTRY_DB_VERSION);
}

function em_inbox_registry_maybe_create_table() {
    if (! em_inbox_registry_enabled()) return;
    if (get_option('em_inbox_registry_db_version') !== EM_INBOX_REGISTRY_DB_VERSION) {
        em_inbox_registry_create_table();
    }
}
add_action('admin_init', 'em_inbox_registry_maybe_create_table');

/* -------------------------------------------------------------------------
 * REST routes — admin-only (`manage_options`)
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    if (! em_inbox_registry_enabled()) return;

    $admin = function () { return current_user_can('manage_options'); };

    register_rest_route('em/v1', '/inbox/registry', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_registry_list',
            'permission_callback' => $admin,
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_registry_upsert',
            'permission_callback' => $admin,
        ),
    ));

    register_rest_route('em/v1', '/inbox/registry/(?P<domain>[A-Za-z0-9._-]+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_registry_get',
            'permission_callback' => $admin,
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'em_inbox_registry_delete',
            'permission_callback' => $admin,
        ),
    ));
});

function em_inbox_registry_list(WP_REST_Request $r) {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}gdc_inbox_domain_registry ORDER BY created_at DESC",
        ARRAY_A
    );
    return rest_ensure_response($rows ?: array());
}

function em_inbox_registry_get(WP_REST_Request $r) {
    global $wpdb;
    $domain = strtolower((string) $r['domain']);
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}gdc_inbox_domain_registry WHERE recipient_domain = %s",
        $domain
    ), ARRAY_A);
    if (! $row) return new WP_Error('em_inbox_registry_404', 'No registry entry for that domain', array('status' => 404));
    return rest_ensure_response($row);
}

function em_inbox_registry_upsert(WP_REST_Request $r) {
    global $wpdb;
    $body = $r->get_json_params() ?: array();

    $required = array('recipient_domain', 'container_namespace', 'container_url', 'mta_static_ip', 'gcs_key_prefix');
    foreach ($required as $f) {
        if (empty($body[$f])) {
            return new WP_Error('em_inbox_registry_missing', "Field {$f} is required", array('status' => 400));
        }
    }

    $domain = strtolower(trim($body['recipient_domain']));
    if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
        return new WP_Error('em_inbox_registry_bad_domain', 'recipient_domain not a valid hostname', array('status' => 400));
    }

    $row = array(
        'recipient_domain'    => $domain,
        'container_namespace' => sanitize_key($body['container_namespace']),
        'container_url'       => esc_url_raw($body['container_url']),
        'mta_static_ip'       => sanitize_text_field($body['mta_static_ip']),
        'mta_static_ip_name'  => isset($body['mta_static_ip_name']) ? sanitize_text_field($body['mta_static_ip_name']) : null,
        'gcs_key_prefix'      => sanitize_text_field($body['gcs_key_prefix']),
        'owner_user_id'       => isset($body['owner_user_id']) ? (int) $body['owner_user_id'] : null,
        'notes'               => isset($body['notes']) ? sanitize_textarea_field($body['notes']) : null,
        'updated_at'          => current_time('mysql', 1),
    );

    $table = $wpdb->prefix . 'gdc_inbox_domain_registry';
    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE recipient_domain = %s", $domain
    ));

    if ($existing_id) {
        $wpdb->update($table, $row, array('id' => $existing_id),
            array('%s','%s','%s','%s','%s','%s','%d','%s','%s'), array('%d'));
        $row['id'] = $existing_id;
    } else {
        $row['created_at'] = $row['updated_at'];
        $wpdb->insert($table, $row,
            array('%s','%s','%s','%s','%s','%s','%d','%s','%s','%s'));
        $row['id'] = (int) $wpdb->insert_id;
    }
    return rest_ensure_response($row);
}

function em_inbox_registry_delete(WP_REST_Request $r) {
    global $wpdb;
    $domain = strtolower((string) $r['domain']);
    $n = $wpdb->delete($wpdb->prefix . 'gdc_inbox_domain_registry',
        array('recipient_domain' => $domain), array('%s'));
    if (! $n) return new WP_Error('em_inbox_registry_404', 'No entry to delete', array('status' => 404));
    return rest_ensure_response(array('deleted' => true, 'domain' => $domain));
}
