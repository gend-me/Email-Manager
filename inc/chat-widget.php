<?php
/**
 * Member Chat Widget (slice 3a).
 *
 * Site-wide messaging UI: a floating button bottom-right (mobile) or
 * a thread switcher + stack of chat boxes (desktop) backed by the
 * BuddyPress private messages component.
 *
 * REST surface (em/v1/chat/*):
 *   GET    /threads?limit=&offset=     list current user's threads
 *   GET    /threads/{id}                fetch one thread + its messages
 *   POST   /threads/{id}/read           mark a thread read
 *   POST   /threads/{id}/send           reply  body: {content}
 *   POST   /threads/new                 start  body: {to_user_id, content}
 *   GET    /users/search?q=             type-ahead member search
 *
 * Frontend assets enqueued via wp_footer on every page so the floating
 * widget is reachable from anywhere on the site for logged-in users.
 *
 * @package EmailManager
 * @since   1.7.0
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * REST routes
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/chat/threads', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_chat_rest_list_threads',
            'permission_callback' => 'em_chat_perm_logged_in',
        ),
    ));
    register_rest_route('em/v1', '/chat/threads/new', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_chat_rest_new_thread',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_chat_rest_get_thread',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)/read', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_chat_rest_mark_read',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)/send', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_chat_rest_send_reply',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/users/search', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_chat_rest_search_users',
        'permission_callback' => 'em_chat_perm_logged_in',
        'args' => array(
            'q'     => array('type' => 'string', 'required' => true),
            'limit' => array('type' => 'integer', 'default' => 10),
        ),
    ));
    register_rest_route('em/v1', '/chat/unread-count', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_chat_rest_unread_count',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
});

function em_chat_perm_logged_in() {
    return is_user_logged_in() && function_exists('bp_is_active') && bp_is_active('messages');
}

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

// Slice 3e.5: accept a pre-loaded BP_Messages_Thread to skip a second
// full DB read when the caller already has the object (e.g.
// em_chat_rest_get_thread). Pass null and we'll instantiate it.
function em_chat_thread_summary($thread_id, $for_user_id, $thread = null) {
    if (! $thread) {
        if (! class_exists('BP_Messages_Thread')) return null;
        try {
            $thread = new BP_Messages_Thread((int) $thread_id, 'ASC');
        } catch (\Throwable $e) { return null; }
    }
    if (! $thread || empty($thread->messages)) return null;

    // Other recipients (not us).
    $others = array();
    if (is_array($thread->recipients)) {
        foreach ($thread->recipients as $rcp) {
            if ((int) $rcp->user_id === (int) $for_user_id) continue;
            $u = get_user_by('id', $rcp->user_id);
            if (! $u) continue;
            $others[] = array(
                'user_id'      => (int) $u->ID,
                'display_name' => $u->display_name ?: $u->user_login,
                'avatar_url'   => get_avatar_url($u->ID, array('size' => 96)),
                'profile_url'  => function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($u->ID) : '',
            );
        }
    }

    $last = end($thread->messages);
    // Per-thread unread count lives on the recipient row — read it
    // there rather than calling a method that varies across BP versions.
    $thread_unread = 0;
    if (is_array($thread->recipients)) {
        foreach ($thread->recipients as $rcp) {
            if ((int) $rcp->user_id === (int) $for_user_id) {
                $thread_unread = (int) $rcp->unread_count;
                break;
            }
        }
    }

    return array(
        'id'          => (int) $thread_id,
        'subject'     => $last ? wp_strip_all_tags((string) $last->subject) : '',
        'last_message_excerpt' => $last ? mb_substr(wp_strip_all_tags((string) $last->message), 0, 140) : '',
        'last_sender' => $last ? (int) $last->sender_id : 0,
        'last_at'     => $last ? mysql_to_rfc3339($last->date_sent) : null,
        'message_count' => count($thread->messages),
        'unread'      => $thread_unread,
        'others'      => $others,
    );
}

function em_chat_message_to_array($message, $for_user_id) {
    $sender = get_user_by('id', $message->sender_id);
    return array(
        'id'             => (int) $message->id,
        'sender_id'      => (int) $message->sender_id,
        'sender_name'    => $sender ? ($sender->display_name ?: $sender->user_login) : '',
        'sender_avatar'  => $sender ? get_avatar_url($sender->ID, array('size' => 64)) : '',
        'is_self'        => ((int) $message->sender_id === (int) $for_user_id),
        'subject'        => wp_strip_all_tags((string) $message->subject),
        'message'        => apply_filters('bp_get_the_thread_message_content', (string) $message->message),
        'date_sent'      => mysql_to_rfc3339($message->date_sent),
    );
}

/* -------------------------------------------------------------------------
 * REST callbacks
 * ------------------------------------------------------------------------- */

function em_chat_rest_list_threads(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid    = (int) $u->ID;
    $limit  = max(1, min(50, (int) $r->get_param('limit') ?: 25));
    $offset = max(0, (int) $r->get_param('offset'));

    if (! class_exists('BP_Messages_Thread') || ! method_exists('BP_Messages_Thread', 'get_current_threads_for_user')) {
        return new WP_Error('em_chat_no_bp', 'BuddyPress messaging not active', array('status' => 500));
    }
    $res = BP_Messages_Thread::get_current_threads_for_user(array(
        'user_id' => $uid,
        'box'     => 'inbox',
        'limit'   => $limit,
        'page'    => max(1, intval($offset / $limit) + 1),
    ));
    $items = array();
    if (! empty($res['threads'])) {
        foreach ($res['threads'] as $th) {
            $sum = em_chat_thread_summary($th->thread_id, $uid);
            if ($sum) $items[] = $sum;
        }
    }
    return rest_ensure_response(array(
        'items' => $items,
        'total' => (int) ($res['total'] ?? count($items)),
    ));
}

function em_chat_rest_get_thread(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid = (int) $u->ID;
    $id  = (int) $r['id'];

    if (! class_exists('BP_Messages_Thread')) {
        return new WP_Error('em_chat_no_bp', 'BuddyPress messaging not active', array('status' => 500));
    }
    if (! function_exists('messages_check_thread_access') || ! messages_check_thread_access($id, $uid)) {
        return new WP_Error('em_chat_forbidden', 'No access to this thread', array('status' => 403));
    }
    try {
        $thread = new BP_Messages_Thread($id, 'ASC');
    } catch (\Throwable $e) {
        return new WP_Error('em_chat_404', 'Thread not found', array('status' => 404));
    }
    $messages = array();
    foreach ((array) $thread->messages as $m) {
        $messages[] = em_chat_message_to_array($m, $uid);
    }
    // Reuse the BP_Messages_Thread we just loaded — avoids a second
    // full thread+recipients+messages query (slice 3e.5).
    $summary = em_chat_thread_summary($id, $uid, $thread);
    return rest_ensure_response(array(
        'thread'   => $summary,
        'messages' => $messages,
    ));
}

function em_chat_rest_mark_read(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid = (int) $u->ID;
    $id  = (int) $r['id'];
    if (function_exists('messages_check_thread_access') && ! messages_check_thread_access($id, $uid)) {
        return new WP_Error('em_chat_forbidden', 'No access to this thread', array('status' => 403));
    }
    if (function_exists('messages_mark_thread_read')) {
        messages_mark_thread_read($id);
    }
    return rest_ensure_response(array('ok' => true));
}

function em_chat_rest_send_reply(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid = (int) $u->ID;
    $id  = (int) $r['id'];

    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();
    $content = trim((string) ($body['content'] ?? ''));
    if ($content === '') {
        return new WP_Error('em_chat_empty', 'Message body required', array('status' => 400));
    }
    if (function_exists('messages_check_thread_access') && ! messages_check_thread_access($id, $uid)) {
        return new WP_Error('em_chat_forbidden', 'No access to this thread', array('status' => 403));
    }
    if (! function_exists('messages_new_message')) {
        return new WP_Error('em_chat_no_bp', 'BuddyPress messaging not active', array('status' => 500));
    }
    $msg_id = messages_new_message(array(
        'thread_id' => $id,
        'sender_id' => $uid,
        'content'   => $content,
    ));
    if (! $msg_id || is_wp_error($msg_id)) {
        return new WP_Error('em_chat_send_failed', is_wp_error($msg_id) ? $msg_id->get_error_message() : 'send failed', array('status' => 500));
    }
    return rest_ensure_response(array('ok' => true, 'message_id' => (int) $msg_id));
}

function em_chat_rest_new_thread(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid = (int) $u->ID;

    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();
    $to = (int) ($body['to_user_id'] ?? 0);
    $content = trim((string) ($body['content'] ?? ''));
    $subject = trim((string) ($body['subject'] ?? '')) ?: 'Chat';
    if ($to <= 0 || $to === $uid) {
        return new WP_Error('em_chat_bad_recipient', 'recipient required', array('status' => 400));
    }
    if ($content === '') {
        return new WP_Error('em_chat_empty', 'message body required', array('status' => 400));
    }
    if (! function_exists('messages_new_message')) {
        return new WP_Error('em_chat_no_bp', 'BuddyPress messaging not active', array('status' => 500));
    }
    $thread_id = messages_new_message(array(
        'sender_id'  => $uid,
        'subject'    => $subject,
        'content'    => $content,
        'recipients' => array($to),
    ));
    if (! $thread_id || is_wp_error($thread_id)) {
        return new WP_Error('em_chat_send_failed', is_wp_error($thread_id) ? $thread_id->get_error_message() : 'send failed', array('status' => 500));
    }
    // messages_new_message returns the THREAD id when starting a new
    // conversation. Return the thread summary so the widget can render
    // it without an extra round trip.
    $summary = em_chat_thread_summary((int) $thread_id, $uid);
    return rest_ensure_response(array('ok' => true, 'thread' => $summary));
}

function em_chat_rest_search_users(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $q     = trim((string) $r->get_param('q'));
    $limit = max(1, min(20, (int) $r->get_param('limit') ?: 10));
    if (mb_strlen($q) < 1) return rest_ensure_response(array('items' => array()));

    $users = get_users(array(
        'search'         => '*' . esc_attr($q) . '*',
        'search_columns' => array('user_login', 'user_nicename', 'user_email', 'display_name'),
        'number'         => $limit,
        'exclude'        => array((int) $u->ID),
        'fields'         => array('ID', 'display_name', 'user_login', 'user_email'),
    ));
    $items = array();
    foreach ($users as $row) {
        $items[] = array(
            'user_id'      => (int) $row->ID,
            'display_name' => $row->display_name ?: $row->user_login,
            'username'     => $row->user_login,
            'avatar_url'   => get_avatar_url($row->ID, array('size' => 96)),
        );
    }
    return rest_ensure_response(array('items' => $items));
}

function em_chat_rest_unread_count(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid   = (int) $u->ID;
    $count = function_exists('messages_get_unread_count')
        ? (int) messages_get_unread_count($uid)
        : 0;

    // Slice 3e: also return per-sender unread items so the chat widget
    // can render a stack of "live notification" avatars left of the
    // launcher. Each item drives one avatar with a badge + click-to-open.
    $items = array();
    if ($count > 0 && class_exists('BP_Messages_Thread') && method_exists('BP_Messages_Thread', 'get_current_threads_for_user')) {
        $res = BP_Messages_Thread::get_current_threads_for_user(array(
            'user_id' => $uid,
            'box'     => 'inbox',
            'type'    => 'unread',
            'limit'   => 6,
            'page'    => 1,
        ));
        $threads = isset($res['threads']) ? $res['threads'] : array();
        foreach ($threads as $t) {
            $summary = em_chat_thread_summary((int) $t->thread_id, $uid);
            if (! $summary || empty($summary['unread'])) continue;
            $other = isset($summary['others'][0]) ? $summary['others'][0] : null;
            if (! $other) continue;
            $items[] = array(
                'thread_id'    => (int) $summary['id'],
                'user_id'      => (int) $other['user_id'],
                'display_name' => (string) $other['display_name'],
                'avatar_url'   => (string) $other['avatar_url'],
                'unread'       => (int) $summary['unread'],
                'last_at'      => $summary['last_at'],
                'excerpt'      => (string) $summary['last_message_excerpt'],
            );
        }
    }

    return rest_ensure_response(array(
        'unread' => $count,
        'items'  => $items,
    ));
}

/* -------------------------------------------------------------------------
 * Frontend asset enqueue — site-wide
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'em_chat_widget_enqueue', 20);
function em_chat_widget_enqueue() {
    if (! is_user_logged_in()) return;
    if (! function_exists('bp_is_active') || ! bp_is_active('messages')) return;

    wp_enqueue_style(
        'em-chat-widget',
        EMAIL_MANAGER_URL . 'assets/chat-widget.css',
        array(),
        EMAIL_MANAGER_VERSION
    );
    wp_enqueue_script(
        'em-chat-widget',
        EMAIL_MANAGER_URL . 'assets/chat-widget.js',
        array('wp-element', 'wp-i18n', 'wp-api-fetch'),
        EMAIL_MANAGER_VERSION,
        true
    );
    $u = wp_get_current_user();
    wp_localize_script('em-chat-widget', 'EM_CHAT_CONFIG', array(
        'restRoot'         => esc_url_raw(rest_url('em/v1/chat/')),
        'nonce'            => wp_create_nonce('wp_rest'),
        'currentUserId'    => (int) $u->ID,
        'currentUserName'  => $u->display_name ?: $u->user_login,
        'currentUserAvatar'=> get_avatar_url($u->ID, array('size' => 64)),
    ));
}

// Render the mount point in the footer of EVERY frontend page so the
// floating widget appears anywhere on the site.
add_action('wp_footer', 'em_chat_widget_mount', 50);
function em_chat_widget_mount() {
    if (! is_user_logged_in()) return;
    if (! function_exists('bp_is_active') || ! bp_is_active('messages')) return;
    echo '<div id="em-chat-widget-root" data-loading="1"></div>';
}
