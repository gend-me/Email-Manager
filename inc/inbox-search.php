<?php
/**
 * Member Inbox: full-text search across messages (slice 2h).
 *
 * Adds a FULLTEXT key on wp_gdc_inbox_messages(subject, sender,
 * body_plain) and exposes GET /em/v1/inbox/search?q=…&inbox=…&limit=N.
 *
 * MySQL/MariaDB FULLTEXT requires the table engine to support it
 * (InnoDB since 5.6 — every modern WP install). Migration is gated so
 * the index is added once + skipped on later boots.
 *
 * Permission gate matches the rest of the inbox surface: admin can
 * search any inbox; non-admins are scoped to addresses they own
 * (em_inbox_address or matching user_email).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_SEARCH_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema: add FULLTEXT key
 * ------------------------------------------------------------------------- */

function em_inbox_search_maybe_index() {
    if (get_option('em_inbox_search_db_version') === EM_INBOX_SEARCH_DB_VERSION) return;
    global $wpdb;
    $table = $wpdb->prefix . 'gdc_inbox_messages';
    $existing = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'ft_inbox_search'");
    if (empty($existing)) {
        $wpdb->query("ALTER TABLE $table ADD FULLTEXT KEY ft_inbox_search (subject, sender, body_plain)");
    }
    update_option('em_inbox_search_db_version', EM_INBOX_SEARCH_DB_VERSION);
}
add_action('admin_init',    'em_inbox_search_maybe_index');
add_action('rest_api_init', 'em_inbox_search_maybe_index');

/* -------------------------------------------------------------------------
 * REST: GET /em/v1/inbox/search
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/search', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_search_handler',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'q'     => array('type' => 'string', 'required' => true),
            'inbox' => array('type' => 'string'),
            'limit' => array('type' => 'integer', 'default' => 25),
        ),
    ));
});

function em_inbox_search_handler(WP_REST_Request $request) {
    global $wpdb;
    $q     = trim((string) $request->get_param('q'));
    $inbox = trim((string) $request->get_param('inbox'));
    $limit = max(1, min(100, (int) $request->get_param('limit')));
    if (mb_strlen($q) < 2) {
        return rest_ensure_response(array('items' => array(), 'query' => $q));
    }

    // Inbox scoping
    if ($inbox !== '' && function_exists('em_inbox_user_can_read_inbox') && ! em_inbox_user_can_read_inbox($inbox)) {
        return new WP_Error('em_inbox_search_forbidden', 'Cannot search that inbox', array('status' => 403));
    }
    $inbox_clause = '';
    $args         = array($q, $q);  // MATCH used twice (SELECT score + WHERE)
    if ($inbox !== '') {
        $inbox_clause = ' AND m.recipient = %s';
        $args[]       = $inbox;
    } elseif (! current_user_can('manage_options')) {
        // Non-admin without explicit inbox: limit to addresses they own.
        $u = wp_get_current_user();
        if (! $u || ! $u->ID) return rest_ensure_response(array('items' => array(), 'query' => $q));
        $candidates = array();
        if ($u->user_email) $candidates[] = $u->user_email;
        $meta = get_user_meta($u->ID, 'em_inbox_address', true);
        if ($meta) $candidates[] = $meta;
        $candidates = array_unique(array_filter($candidates));
        if (empty($candidates)) return rest_ensure_response(array('items' => array(), 'query' => $q));
        $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
        $inbox_clause = " AND m.recipient IN ($placeholders)";
        $args = array_merge($args, $candidates);
    }
    $args[] = $limit;

    $messages = $wpdb->prefix . 'gdc_inbox_messages';
    $threads  = $wpdb->prefix . 'gdc_inbox_threads';
    $sql = $wpdb->prepare(
        "SELECT
            m.id          AS message_id,
            m.thread_id   AS thread_id,
            m.subject     AS subject,
            m.sender      AS sender,
            m.recipient   AS recipient,
            m.received_at AS received_at,
            LEFT(m.body_plain, 200) AS snippet,
            t.subject_first AS thread_subject,
            t.message_count AS thread_message_count,
            MATCH(m.subject, m.sender, m.body_plain) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
         FROM $messages m
         LEFT JOIN $threads t ON t.id = m.thread_id
         WHERE MATCH(m.subject, m.sender, m.body_plain) AGAINST (%s IN NATURAL LANGUAGE MODE)
           $inbox_clause
         ORDER BY score DESC, m.received_at DESC
         LIMIT %d",
        ...$args
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);

    return rest_ensure_response(array(
        'items' => $rows ?: array(),
        'query' => $q,
    ));
}
