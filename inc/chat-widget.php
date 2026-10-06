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
 *   POST   /agent-send                  server-to-server agent DM  body: {group_id?, agent_slug, content, subject?}
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
    // Server-to-server: the desktop's sequence runner (local runs AND
    // Phase-39 device runs) posts each step's prompt/response here so it
    // becomes visible in the caller's real em-chat DM thread with that
    // agent, not just the Project-task write-back. Same bearer/permission
    // posture as every other route in this file.
    register_rest_route('em/v1', '/chat/agent-send', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_chat_rest_agent_send',
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
    // Profile-messages parity actions (star / mark-unread / delete / notices).
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)/star', array(
        'methods'             => 'POST',
        'callback'            => 'em_chat_rest_star_thread',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)/unread', array(
        'methods'             => 'POST',
        'callback'            => 'em_chat_rest_unread_thread',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)/delete', array(
        'methods'             => 'POST',
        'callback'            => 'em_chat_rest_delete_thread',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/notices', array(
        'methods'             => 'GET',
        'callback'            => 'em_chat_rest_notices',
        'permission_callback' => 'em_chat_perm_logged_in',
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

// v8.2 Phase 45: classify a thread participant as an agent so the chat
// widget can split conversations into Members / Agents / Projects tabs
// (mirroring the hub Messages page). Prefer gend-society's canonical
// gs_user_is_agent() when present; otherwise fall back to the meta + role
// the agent system sets directly (NO hard cross-plugin dependency — the
// widget must keep working if gend-society is inactive on a container).
function em_chat_user_is_agent($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;
    if (function_exists('gs_user_is_agent')) {
        return (bool) gs_user_is_agent($user_id);
    }
    if (get_user_meta($user_id, '_aipa_is_agent', true)) return true;
    $u = get_user_by('id', $user_id);
    if ($u && is_array($u->roles) && in_array('ai_agent', $u->roles, true)) return true;
    return false;
}

// v8.2 Phase 45: resolve a chat thread to the project it's attached to
// (Phase-44 linkage rows in wp_pm_meta: entity_type='pm_chat_thread',
// meta_key='thread_id'). $wpdb-direct — no projects-plugin class call.
// The pm_meta table only exists where the projects plugin is installed
// (the hub); a one-shot cached SHOW TABLES guard keeps this silent on
// containers that lack it. Returns 0 when unattached / unavailable.
function em_chat_thread_project_id($thread_id) {
    global $wpdb;
    $thread_id = (int) $thread_id;
    if ($thread_id <= 0 || ! isset($wpdb) || ! is_object($wpdb)) return 0;

    static $table = null; // null = unchecked, '' = absent, else the name.
    if ($table === null) {
        $name  = $wpdb->prefix . 'pm_meta';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name));
        $table = $found ? $name : '';
    }
    if ($table === '') return 0;

    $pid = $wpdb->get_var($wpdb->prepare(
        "SELECT project_id FROM `{$table}` WHERE entity_type='pm_chat_thread' AND meta_key='thread_id' AND meta_value=%d LIMIT 1",
        $thread_id
    ));
    return (int) $pid;
}

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

    // Other recipients (not us). Track whether ANY other participant is an
    // agent (v8.2 Phase 45) so the widget can route this thread to the
    // Agents tab + tint its live-dock chip.
    $others   = array();
    $is_agent = false;
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
            if (! $is_agent && em_chat_user_is_agent($u->ID)) $is_agent = true;
        }
    }

    // Project linkage (Phase 44) → drives the Projects tab.
    $project_id = em_chat_thread_project_id($thread_id);

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

    // Starred flag (profile-messages parity): a thread counts as starred
    // for this user when ANY of its messages is starred. Messages are
    // already loaded on the thread object; per-message meta reads ride the
    // meta cache.
    $starred = false;
    if (function_exists('bp_messages_is_message_starred')) {
        foreach ((array) $thread->messages as $m) {
            if (bp_messages_is_message_starred((int) $m->id, (int) $for_user_id)) { $starred = true; break; }
        }
    }

    return array(
        'id'          => (int) $thread_id,
        'starred'     => $starred,
        'subject'     => $last ? wp_strip_all_tags((string) $last->subject) : '',
        'last_message_excerpt' => $last ? mb_substr(wp_strip_all_tags((string) $last->message), 0, 140) : '',
        'last_sender' => $last ? (int) $last->sender_id : 0,
        'last_at'     => $last ? mysql_to_rfc3339($last->date_sent) : null,
        'message_count' => count($thread->messages),
        'unread'      => $thread_unread,
        'others'      => $others,
        'is_agent'    => (bool) $is_agent,
        'project_id'  => $project_id ?: null,
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
    // Box selector (profile-messages parity): inbox (default) | sentbox |
    // starred. 'sent' is accepted as an alias; anything else → inbox.
    $box = sanitize_key((string) $r->get_param('box'));
    if ($box === 'sent') $box = 'sentbox';
    if (! in_array($box, array('inbox', 'sentbox', 'starred'), true)) $box = 'inbox';
    $res = BP_Messages_Thread::get_current_threads_for_user(array(
        'user_id' => $uid,
        'box'     => $box,
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

/**
 * POST /em/v1/chat/agent-send { group_id?, agent_slug, content, subject? }
 *
 * Resolves agent_slug -> the hidden `agent-<slug>` WP user (projects
 * plugin's psoo_resolve_agent_by_slug(), guarded — cross-plugin call), then
 * finds the caller's EXISTING 1:1 thread with that agent via the same
 * BP_Messages_Thread::get_current_threads_for_user() lookup
 * em_chat_rest_list_threads() already uses, and appends to it.
 * messages_new_message() always starts a brand-new thread unless a
 * thread_id is passed — without this lookup, every sequence step (this
 * route's only caller) would spawn its own separate thread instead of one
 * ongoing conversation.
 */
function em_chat_rest_agent_send(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    $uid = (int) $u->ID;

    $body     = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();
    $group_id = (int) ($body['group_id'] ?? 0);
    $slug     = function_exists('sanitize_title') ? sanitize_title((string) ($body['agent_slug'] ?? '')) : '';
    $content  = trim((string) ($body['content'] ?? ''));
    $subject  = trim((string) ($body['subject'] ?? '')) ?: 'Sequence run';

    if ($slug === '') return new WP_Error('em_chat_bad_agent', 'agent_slug required', array('status' => 400));
    if ($content === '') return new WP_Error('em_chat_empty', 'message body required', array('status' => 400));
    // The desktop's "Device run — step N" echo of a step that a gend.me sequence run already recorded in its project
    // and reports in the chat itself (leo's aipa_seq_swallow_device_echo()): don't post a duplicate as the user.
    if (function_exists('aipa_seq_swallow_device_echo') && aipa_seq_swallow_device_echo($slug, $group_id, $content)) {
        return rest_ensure_response(array('success' => true, 'skipped' => 'tracked-sequence-run'));
    }
    if (! function_exists('psoo_resolve_agent_by_slug')) {
        return new WP_Error('em_chat_no_bridge', 'Agent resolver unavailable', array('status' => 500));
    }
    $agent_uid = psoo_resolve_agent_by_slug($slug);
    if (! $agent_uid) return new WP_Error('em_chat_agent_missing', 'Agent not found', array('status' => 404));
    if ($group_id > 0 && function_exists('groups_is_user_member') && ! groups_is_user_member($agent_uid, $group_id)) {
        return new WP_Error('em_chat_agent_group_mismatch', 'Agent is not a member of this group', array('status' => 409));
    }
    if (! function_exists('messages_new_message') || ! class_exists('BP_Messages_Thread')
        || ! method_exists('BP_Messages_Thread', 'get_current_threads_for_user')) {
        return new WP_Error('em_chat_no_bp', 'BuddyPress messaging not active', array('status' => 500));
    }

    $thread_id = 0;
    $res = BP_Messages_Thread::get_current_threads_for_user(array(
        'user_id' => $uid,
        'box'     => 'inbox',
        'limit'   => 100,
        'page'    => 1,
    ));
    if (! empty($res['threads'])) {
        foreach ($res['threads'] as $th) {
            $sum = em_chat_thread_summary($th->thread_id, $uid);
            if (! $sum || count($sum['others']) !== 1) continue;
            if ((int) $sum['others'][0]['user_id'] === $agent_uid) {
                $thread_id = (int) $th->thread_id;
                break;
            }
        }
    }

    if ($thread_id > 0) {
        $sent = messages_new_message(array(
            'thread_id' => $thread_id,
            'sender_id' => $uid,
            'content'   => $content,
        ));
    } else {
        $sent = messages_new_message(array(
            'sender_id'  => $uid,
            'subject'    => $subject,
            'content'    => $content,
            'recipients' => array($agent_uid),
        ));
        $thread_id = is_wp_error($sent) ? 0 : (int) $sent;
    }
    if (! $sent || is_wp_error($sent)) {
        return new WP_Error('em_chat_send_failed', is_wp_error($sent) ? $sent->get_error_message() : 'send failed', array('status' => 500));
    }

    return rest_ensure_response(array('ok' => true, 'thread_id' => $thread_id, 'agent_user_id' => $agent_uid));
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

/** Shared access gate for the thread-action routes. */
function em_chat_thread_action_guard($thread_id, $uid) {
    if (! function_exists('messages_check_thread_access') || ! messages_check_thread_access($thread_id, $uid)) {
        return new WP_Error('em_chat_forbidden', 'No access to this thread', array('status' => 403));
    }
    return true;
}

function em_chat_rest_star_thread(WP_REST_Request $r) {
    $uid = get_current_user_id();
    $id  = (int) $r['id'];
    $g   = em_chat_thread_action_guard($id, $uid);
    if (is_wp_error($g)) return $g;
    if (! function_exists('bp_messages_star_set_action')) {
        return new WP_Error('em_chat_no_star', 'Message starring is not active', array('status' => 500));
    }
    $starred = (string) $r->get_param('starred');
    $on = ($starred === '1' || $starred === 'true');
    bp_messages_star_set_action(array(
        'user_id'   => $uid,
        'thread_id' => $id,
        'action'    => $on ? 'star' : 'unstar',
        'bulk'      => true,
    ));
    return rest_ensure_response(array('ok' => true, 'thread_id' => $id, 'starred' => $on));
}

function em_chat_rest_unread_thread(WP_REST_Request $r) {
    $uid = get_current_user_id();
    $id  = (int) $r['id'];
    $g   = em_chat_thread_action_guard($id, $uid);
    if (is_wp_error($g)) return $g;
    if (function_exists('messages_mark_thread_unread')) {
        messages_mark_thread_unread($id, $uid);
    }
    return rest_ensure_response(array('ok' => true, 'thread_id' => $id));
}

function em_chat_rest_delete_thread(WP_REST_Request $r) {
    $uid = get_current_user_id();
    $id  = (int) $r['id'];
    $g   = em_chat_thread_action_guard($id, $uid);
    if (is_wp_error($g)) return $g;
    if (! function_exists('messages_delete_thread')) {
        return new WP_Error('em_chat_no_bp', 'BuddyPress messaging not active', array('status' => 500));
    }
    // Deletes the thread FOR THIS USER (BP semantics — other participants
    // keep their copy), same as the profile page's trash action.
    messages_delete_thread($id, $uid);
    return rest_ensure_response(array('ok' => true, 'thread_id' => $id));
}

function em_chat_rest_notices(WP_REST_Request $r) {
    if (! class_exists('BP_Messages_Notice')) {
        return rest_ensure_response(array('items' => array()));
    }
    $items = array();
    foreach ((array) BP_Messages_Notice::get_notices(array('pag_num' => 20, 'pag_page' => 1)) as $n) {
        if (! is_object($n)) continue;
        $items[] = array(
            'id'        => (int) $n->id,
            'subject'   => wp_strip_all_tags((string) $n->subject),
            'message'   => wp_strip_all_tags((string) $n->message),
            'date'      => (string) $n->date_sent,
            'is_active' => ! empty($n->is_active),
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
                'is_agent'     => ! empty($summary['is_agent']),
                'project_id'   => isset($summary['project_id']) ? $summary['project_id'] : null,
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

/**
 * v8.4.4: per-file mtime asset versions. EMAIL_MANAGER_VERSION is
 * time()-based, which mints a NEW asset URL on every request — the
 * browser could never cache the widget or the inbox suite, re-downloading
 * hundreds of KB per page view ("chat is really slow to load"). filemtime
 * keeps assets cached until the file on disk actually changes (kubectl cp
 * deploys update the mtime, so cache-busting still works).
 */
function em_asset_ver($rel) {
    $p = EMAIL_MANAGER_PATH . $rel;
    $t = file_exists($p) ? filemtime($p) : 0;
    return $t ? (string) $t : '1';
}

add_action('wp_enqueue_scripts', 'em_chat_widget_enqueue', 20);
function em_chat_widget_enqueue() {
    if (! is_user_logged_in()) return;
    if (! function_exists('bp_is_active') || ! bp_is_active('messages')) return;

    wp_enqueue_style(
        'em-chat-widget',
        EMAIL_MANAGER_URL . 'assets/chat-widget.css',
        array(),
        em_asset_ver('assets/chat-widget.css')
    );
    wp_enqueue_script(
        'em-chat-widget',
        EMAIL_MANAGER_URL . 'assets/chat-widget.js',
        array('wp-element', 'wp-i18n', 'wp-api-fetch'),
        em_asset_ver('assets/chat-widget.js'),
        true
    );
    $u = wp_get_current_user();

    // v8.3.1: the widget's Email tab mounts the member inbox SPA natively
    // (window.emInboxMount(el) — no iframe), so its assets + config ride
    // along on every page for users who can access an inbox. WP dedupes
    // the handles with the messages-screen enqueue automatically.
    $has_inbox = function_exists('em_inbox_user_has_inbox_access')
        && em_inbox_user_has_inbox_access(get_current_user_id());
    if ($has_inbox) {
        wp_enqueue_style(
            'em-inbox-app',
            EMAIL_MANAGER_URL . 'assets/inbox-app.css',
            array('wp-components'),
            em_asset_ver('assets/inbox-app.css')
        );
        wp_enqueue_script(
            'em-inbox-app',
            EMAIL_MANAGER_URL . 'assets/inbox-app.js',
            array('wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch'),
            em_asset_ver('assets/inbox-app.js'),
            true
        );
        $tz_str = function_exists('wp_timezone_string') ? wp_timezone_string() : (get_option('timezone_string') ?: 'UTC');
        wp_localize_script('em-inbox-app', 'EM_INBOX_CONFIG', array(
            'restRoot'         => esc_url_raw(rest_url('em/v1/inbox/')),
            'nonce'            => wp_create_nonce('wp_rest'),
            'isAdmin'          => current_user_can('manage_options'),
            'currentUserEmail' => $u ? $u->user_email : '',
            'userTimezone'     => $tz_str ?: 'UTC',
            'frontend'         => true,
        ));
    }

    wp_localize_script('em-chat-widget', 'EM_CHAT_CONFIG', array(
        'restRoot'         => esc_url_raw(rest_url('em/v1/chat/')),
        'nonce'            => wp_create_nonce('wp_rest'),
        'currentUserId'    => (int) $u->ID,
        'currentUserName'  => $u->display_name ?: $u->user_login,
        'currentUserAvatar'=> get_avatar_url($u->ID, array('size' => 64)),
        'hasInbox'         => $has_inbox,
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

// ─── The widget on EVERY wp-admin page too (operator directive
// 2026-08-24) — same gates as the frontend, skipping the block editor
// (a floating widget fights its UI) and the stripped embed surfaces. ───
function em_chat_widget_admin_ok() {
    if (! empty($_GET['gdc_tab_only']) || ! empty($_GET['gend_embed'])) return false;
    if (function_exists('get_current_screen')) {
        $scr = get_current_screen();
        if ($scr && method_exists($scr, 'is_block_editor') && $scr->is_block_editor()) return false;
    }
    return true;
}
add_action('admin_enqueue_scripts', 'em_chat_widget_admin_enqueue', 20);
function em_chat_widget_admin_enqueue() {
    if (! em_chat_widget_admin_ok()) return;
    em_chat_widget_enqueue();
}
add_action('admin_footer', 'em_chat_widget_admin_mount', 50);
function em_chat_widget_admin_mount() {
    if (! em_chat_widget_admin_ok()) return;
    em_chat_widget_mount();
}
