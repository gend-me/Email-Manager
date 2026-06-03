<?php
/**
 * Member Inbox: drafts (slice 2kk).
 *
 * Drafts live in their own table (wp_gdc_inbox_drafts) — they do NOT
 * flow through the inbound threading pipeline, do NOT show in the
 * thread feed, and do NOT get a message_id (one is synthesized when
 * the user finally hits Send).
 *
 * The Composer auto-saves to /drafts every ~2s on state change while
 * editing. On Send, the draft row is deleted server-side. On Close-
 * without-send, the draft persists. The Drafts feed tab lists them
 * with To / Subject / updated_at.
 *
 * Schema deliberately mirrors a subset of the /send payload so a
 * draft can be loaded directly into the composer with no transform.
 *
 * @package EmailManager
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_DRAFTS_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_drafts_maybe_create_table() {
    if (get_option('em_inbox_drafts_db_version') === EM_INBOX_DRAFTS_DB_VERSION) return;
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'gdc_inbox_drafts';
    $sql = "CREATE TABLE $table (
        id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id              BIGINT UNSIGNED NOT NULL,
        from_address         VARCHAR(254) NOT NULL DEFAULT '',
        to_json              LONGTEXT NULL,
        cc_json              LONGTEXT NULL,
        bcc_json             LONGTEXT NULL,
        subject              VARCHAR(998) NOT NULL DEFAULT '',
        body_plain           LONGTEXT NULL,
        body_html            LONGTEXT NULL,
        thread_id            BIGINT UNSIGNED NULL,
        in_reply_to_msg_id   VARCHAR(998) NULL,
        attachments_json     LONGTEXT NULL,
        track_open           TINYINT(1) NOT NULL DEFAULT 0,
        created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_updated (user_id, updated_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_drafts_db_version', EM_INBOX_DRAFTS_DB_VERSION);
}
add_action('admin_init',    'em_inbox_drafts_maybe_create_table');
add_action('rest_api_init', 'em_inbox_drafts_maybe_create_table');

/* -------------------------------------------------------------------------
 * REST
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/drafts', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_drafts_list',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_drafts_upsert',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
    register_rest_route('em/v1', '/inbox/drafts/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_drafts_get',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => array('PUT', 'PATCH', 'POST'),
            'callback'            => 'em_inbox_drafts_upsert',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'em_inbox_drafts_delete',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
});

function em_inbox_drafts_list(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_drafts_no_user', 'Login required', array('status' => 401));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, from_address, to_json, subject, body_plain, thread_id, updated_at
         FROM {$wpdb->prefix}gdc_inbox_drafts
         WHERE user_id = %d
         ORDER BY updated_at DESC
         LIMIT 200",
        (int) $u->ID
    ), ARRAY_A);
    foreach ($rows as &$row) {
        $row['to']      = json_decode((string) $row['to_json'], true) ?: array();
        unset($row['to_json']);
        $row['snippet'] = mb_substr((string) $row['body_plain'], 0, 140);
        unset($row['body_plain']);
    }
    unset($row);
    return rest_ensure_response(array('items' => $rows ?: array()));
}

function em_inbox_drafts_get(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_drafts_no_user', 'Login required', array('status' => 401));
    $id = (int) $r['id'];
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}gdc_inbox_drafts WHERE id = %d AND user_id = %d",
        $id, (int) $u->ID
    ), ARRAY_A);
    if (! $row) return new WP_Error('em_drafts_404', 'Draft not found', array('status' => 404));
    $row['to']          = json_decode((string) $row['to_json'],          true) ?: array();
    $row['cc']          = json_decode((string) $row['cc_json'],          true) ?: array();
    $row['bcc']         = json_decode((string) $row['bcc_json'],         true) ?: array();
    $row['attachments'] = json_decode((string) $row['attachments_json'], true) ?: array();
    unset($row['to_json'], $row['cc_json'], $row['bcc_json'], $row['attachments_json']);
    return rest_ensure_response($row);
}

function em_inbox_drafts_upsert(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_drafts_no_user', 'Login required', array('status' => 401));

    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();

    $id = isset($r['id']) ? (int) $r['id'] : 0;
    if (! $id && isset($body['id'])) $id = (int) $body['id'];

    $now = current_time('mysql', 1);
    $data = array(
        'user_id'            => (int) $u->ID,
        'from_address'       => mb_substr((string) ($body['from'] ?? ''), 0, 254),
        'to_json'            => wp_json_encode(is_array($body['to']  ?? null) ? $body['to']  : array()),
        'cc_json'            => wp_json_encode(is_array($body['cc']  ?? null) ? $body['cc']  : array()),
        'bcc_json'           => wp_json_encode(is_array($body['bcc'] ?? null) ? $body['bcc'] : array()),
        'subject'            => mb_substr((string) ($body['subject'] ?? ''), 0, 998),
        'body_plain'         => (string) ($body['body_plain'] ?? ''),
        'body_html'          => (string) ($body['body_html']  ?? ''),
        'thread_id'          => ! empty($body['thread_id']) ? (int) $body['thread_id'] : null,
        'in_reply_to_msg_id' => isset($body['in_reply_to_msg_id']) ? mb_substr((string) $body['in_reply_to_msg_id'], 0, 998) : null,
        'attachments_json'   => wp_json_encode(is_array($body['attachments'] ?? null) ? $body['attachments'] : array()),
        'track_open'         => ! empty($body['track_open']) ? 1 : 0,
        'updated_at'         => $now,
    );

    $table = $wpdb->prefix . 'gdc_inbox_drafts';
    if ($id > 0) {
        $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE id = %d", $id));
        if ($owner === 0)            return new WP_Error('em_drafts_404', 'Draft not found', array('status' => 404));
        if ($owner !== (int) $u->ID) return new WP_Error('em_drafts_forbidden', 'Not your draft', array('status' => 403));
        $wpdb->update($table, $data, array('id' => $id));
    } else {
        $data['created_at'] = $now;
        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
    }
    return rest_ensure_response(array('ok' => true, 'id' => $id, 'updated_at' => $now));
}

function em_inbox_drafts_delete(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_drafts_no_user', 'Login required', array('status' => 401));
    $id = (int) $r['id'];
    $table = $wpdb->prefix . 'gdc_inbox_drafts';
    $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE id = %d", $id));
    if (! $owner)                return new WP_Error('em_drafts_404', 'Draft not found', array('status' => 404));
    if ($owner !== (int) $u->ID) return new WP_Error('em_drafts_forbidden', 'Not your draft', array('status' => 403));
    $wpdb->delete($table, array('id' => $id), array('%d'));
    return rest_ensure_response(array('ok' => true, 'id' => $id, 'deleted' => true));
}
