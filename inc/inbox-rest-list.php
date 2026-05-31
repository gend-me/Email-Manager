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

    if (current_user_can('manage_options')) {
        // Admin sees every inbox address with at least one thread.
        $rows = $wpdb->get_results(
            "SELECT inbox_address, COUNT(*) AS thread_count, MAX(updated_at) AS last_received
             FROM $threads GROUP BY inbox_address ORDER BY last_received DESC",
            ARRAY_A
        );
    } else {
        $u = wp_get_current_user();
        if (! $u || ! $u->user_email) return rest_ensure_response(array());
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT inbox_address, COUNT(*) AS thread_count, MAX(updated_at) AS last_received
             FROM $threads WHERE inbox_address = %s
             GROUP BY inbox_address",
            $u->user_email
        ), ARRAY_A);
    }
    return rest_ensure_response($rows ?: array());
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

    if ($inbox !== '' && ! em_inbox_user_can_read_inbox($inbox)) {
        return new WP_Error('em_inbox_forbidden', 'Cannot read this inbox', array('status' => 403));
    }

    if ($inbox !== '') {
        $where = $wpdb->prepare('WHERE t.inbox_address = %s', $inbox);
    } elseif (! current_user_can('manage_options')) {
        $u = wp_get_current_user();
        if (! $u || ! $u->user_email) return rest_ensure_response(array('items' => array(), 'total' => 0));
        $where = $wpdb->prepare('WHERE t.inbox_address = %s', $u->user_email);
    } else {
        $where = '';
    }

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $threads_table t $where");

    // Single-table query for the feed (denormalized last_message_id keeps
    // this a clean ORDER BY updated_at DESC). Pull the latest message's
    // sender + subject inline so the feed row is self-contained.
    $sql = $wpdb->prepare(
        "SELECT t.id, t.inbox_address, t.subject_first, t.message_count, t.updated_at,
                m.sender AS last_sender, m.subject AS last_subject, m.received_at AS last_received_at
         FROM $threads_table t
         LEFT JOIN $messages_table m ON m.id = t.last_message_id
         $where
         ORDER BY t.updated_at DESC
         LIMIT %d OFFSET %d",
        $per_page, $offset
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);

    return rest_ensure_response(array(
        'items'    => $rows ?: array(),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
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

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT m.id, m.message_id, m.in_reply_to, m.sender, m.recipient, m.subject,
                m.body_plain, m.body_html, m.received_at,
                r.attachments_json
         FROM $messages_table m
         LEFT JOIN {$wpdb->prefix}gdc_inbox_raw r ON r.id = m.raw_id
         WHERE m.thread_id = %d
         ORDER BY m.received_at ASC, m.id ASC",
        $id
    ), ARRAY_A);

    // Sanitize HTML bodies + decode attachment JSON.
    $allowed_html = em_inbox_allowed_html_for_message();
    foreach ($messages as &$msg) {
        if (! empty($msg['body_html'])) {
            $msg['body_html'] = wp_kses($msg['body_html'], $allowed_html);
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
