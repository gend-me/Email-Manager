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
    register_rest_route('em/v1', '/chat/email-unread', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_chat_rest_email_unread',
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
            if (! $u) {
                // The account was deleted: keep the conversation readable (2026-11-12).
                $others[] = array(
                    'user_id'      => (int) $rcp->user_id,
                    'display_name' => __('Deleted member', 'email-manager'),
                    'avatar_url'   => get_avatar_url(0, array('size' => 96, 'default' => 'mystery', 'force_default' => true)),
                    'profile_url'  => '',
                    'deleted'      => true,
                );
                continue;
            }
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
    // Workspace group chat (2026-11-12, projects plugin psoo_pm_ws_*): one thread for all the workspace's members.
    $group = em_chat_thread_group_info($thread_id);

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
        'is_agent'    => (bool) $is_agent && ! $group,
        'project_id'  => $project_id ?: null,
        'is_group'       => (bool) $group,
        'muted'          => in_array((int) $thread_id, em_chat_muted_threads((int) $for_user_id), true),
        'project_title'  => $group ? $group['title'] : '',
        'project_avatar' => $group ? $group['avatar'] : '',
    );
}

/** Workspace group-chat details for a thread, or null (2026-11-12). */
function em_chat_thread_group_info($thread_id) {
    if (! function_exists('psoo_pm_ws_thread_project') || ! class_exists('PSOO_PM_Projects')) return null;
    $pid = (int) psoo_pm_ws_thread_project((int) $thread_id);
    if ($pid <= 0) return null;
    $p      = PSOO_PM_Projects::get($pid);
    $gid    = (int) PSOO_PM_Projects::get_group_id($pid);
    $avatar = ($gid && function_exists('bp_core_fetch_avatar')) ? (string) bp_core_fetch_avatar(array('item_id' => $gid, 'object' => 'group', 'type' => 'thumb', 'html' => false)) : '';
    return array('project_id' => $pid, 'title' => ($p && ! empty($p->title)) ? (string) $p->title : __('Workspace chat', 'email-manager'), 'avatar' => $avatar);
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
    if (function_exists('em_chat_thread_other') && ($em_other = em_chat_thread_other($id, $uid)) && ($em_guard = em_chat_send_guard($uid, $em_other))) {
        return $em_guard; // one-to-one chats: connected members (or consultants / admins / agents) only (2026-11-12)
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
    if (function_exists('em_chat_send_guard') && ($em_guard = em_chat_send_guard($uid, $to))) {
        return $em_guard; // connected members (or consultants / admins / agents) only (2026-11-12)
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
            $is_group = ! empty($summary['is_group']);
            $items[] = array(
                'thread_id'    => (int) $summary['id'],
                'user_id'      => (int) $other['user_id'],
                'display_name' => $is_group ? (string) $summary['project_title'] : (string) $other['display_name'],
                'avatar_url'   => $is_group ? (string) $summary['project_avatar'] : (string) $other['avatar_url'],
                'is_group'     => $is_group,
                'muted'        => ! empty($summary['muted']),
                'last_sender'  => (int) $summary['last_sender'],
                'unread'       => (int) $summary['unread'],
                'last_at'      => $summary['last_at'],
                'excerpt'      => (string) $summary['last_message_excerpt'],
                'is_agent'     => ! empty($summary['is_agent']),
                'project_id'   => isset($summary['project_id']) ? $summary['project_id'] : null,
            );
        }
    }

    // Per-tab unread conversations for the widget's Members / Projects / Agents tabs (2026-11-12).
    $by_tab = array('members' => 0, 'projects' => 0, 'agents' => 0);
    if ($count > 0 && class_exists('BP_Messages_Thread') && method_exists('BP_Messages_Thread', 'get_current_threads_for_user')) {
        $all = BP_Messages_Thread::get_current_threads_for_user(array('user_id' => $uid, 'box' => 'inbox', 'type' => 'unread', 'limit' => 100, 'page' => 1));
        foreach ((array) (isset($all['threads']) ? $all['threads'] : array()) as $t) {
            $tid = (int) $t->thread_id;
            if (em_chat_thread_group_info($tid)) { $by_tab['projects']++; continue; }
            $agent = false;
            foreach ((array) (isset($t->recipients) ? $t->recipients : array()) as $rcp) {
                if ((int) $rcp->user_id !== $uid && em_chat_user_is_agent((int) $rcp->user_id)) { $agent = true; break; }
            }
            $by_tab[$agent ? 'agents' : 'members']++;
        }
    }

    return rest_ensure_response(array(
        'unread' => $count,
        'items'  => $items,
        'unread_by_tab' => $by_tab,
    ));
}

/**
 * Unread EMAIL for the widget launcher: the viewer's own unread, un-archived, un-trashed, un-snoozed inbox threads
 * (their participant rows, so it is exactly the number the inbox itself shows) plus the newest few with a
 * sender / subject / snippet preview for the "new email" popup. Chat (BuddyPress) unread stays on /chat/unread-count.
 */
function em_chat_rest_email_unread(WP_REST_Request $r) {
    global $wpdb;
    $uid = get_current_user_id();
    $out = array('unread' => 0, 'items' => array());
    if (! $uid || ! function_exists('em_inbox_user_has_inbox_access') || ! em_inbox_user_has_inbox_access($uid)) {
        return rest_ensure_response($out);
    }
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $part    = $wpdb->prefix . 'gdc_inbox_participants';
    $msgs    = $wpdb->prefix . 'gdc_inbox_messages';
    $from_where = "FROM $threads t
         JOIN $part p ON p.thread_id = t.id AND p.user_id = %d
         LEFT JOIN $msgs m ON m.id = t.last_message_id
         WHERE COALESCE(p.is_read, 1) = 0 AND COALESCE(p.is_archived, 0) = 0 AND COALESCE(p.is_trashed, 0) = 0
           AND (p.snoozed_until IS NULL OR p.snoozed_until <= UTC_TIMESTAMP())";
    $out['unread'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $from_where", $uid));
    if ($out['unread'] < 1) return rest_ensure_response($out);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.id, t.inbox_address, t.subject_first, t.updated_at,
                m.sender AS last_sender, m.subject AS last_subject, m.received_at, m.body_plain
         $from_where ORDER BY t.updated_at DESC LIMIT 5",
        $uid
    ), ARRAY_A);
    foreach ((array) $rows as $row) {
        $sender = trim((string) $row['last_sender']);
        $name = $sender; $addr = $sender;
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $sender, $mm)) {
            $addr = trim($mm[2]);
            $name = trim($mm[1]) !== '' ? trim($mm[1]) : $addr;
        }
        if ($name === '') $name = 'Unknown sender';
        $body = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $row['body_plain'])));
        $out['items'][] = array(
            'thread_id'  => (int) $row['id'],
            'inbox'      => (string) $row['inbox_address'],
            'from_name'  => $name,
            'from_email' => $addr,
            'avatar_url' => $addr !== '' && is_email($addr) ? get_avatar_url($addr, array('size' => 80)) : '',
            'subject'    => (string) ($row['last_subject'] !== null && $row['last_subject'] !== '' ? $row['last_subject'] : $row['subject_first']),
            'excerpt'    => wp_html_excerpt($body, 140, '…'),
            'at'         => (string) ($row['received_at'] ?: $row['updated_at']),
        );
    }
    return rest_ensure_response($out);
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
    // Never inside the widget's own embedded pages (calendar / connections / invite frames): no widget in a widget.
    if (! empty($_GET['gdc_tab_only']) || ! empty($_GET['gs_embed']) || ! empty($_GET['gend_embed'])) return;

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

    // The member's personal calendar (GenD Society's member-calendar tab, chrome-stripped embed) for the widget's Calendar tab.
    $cal_url = '';
    if (function_exists('gend_society_calendar_profile_screen') && function_exists('bp_loggedin_user_domain')) {
        $cal_url = add_query_arg(
            array('gdc_tab_only' => '1', 'gs_embed' => 'calendar'),
            trailingslashit(bp_loggedin_user_domain()) . 'member-calendar/'
        );
    }
    wp_localize_script('em-chat-widget', 'EM_CHAT_CONFIG', array(
        'calendarUrl'      => esc_url_raw($cal_url),
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
    if (! empty($_GET['gdc_tab_only']) || ! empty($_GET['gs_embed']) || ! empty($_GET['gend_embed'])) return;
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

/**
 * Messages → Members → "＋ Invite": the member's Commissions → Referrals → Tracking URLs content ("Grow your
 * network": invite link, Invite by email, Share on social) as its own small page, shown in an iframe by the chat
 * widget. A real document (not a grafted fragment) so the email composer's TinyMCE assets always load.
 * admin-ajax counts as is_admin(), so wp_editor() registers its init on the ADMIN footer action.
 */
add_action('wp_ajax_em_invite_frame', 'em_invite_frame_ajax');
function em_invite_frame_ajax() {
    $tracking = shortcode_exists('affiliate_link');
    if (! is_user_logged_in() || (! $tracking && ! function_exists('gend_society_invite_render_panel'))) {
        status_header(403);
        exit;
    }
    $content = '';
    if ($tracking) {
        if (defined('AAS_PLUGIN_URL')) {
            $css = defined('AAS_PLUGIN_DIR') ? AAS_PLUGIN_DIR . 'assets/dashboard-gend.css' : '';
            wp_enqueue_style('aas-dashboard-gend', AAS_PLUGIN_URL . 'assets/dashboard-gend.css', array(), ($css && file_exists($css)) ? (string) filemtime($css) : '1');
        }
        // Rendered BEFORE the head prints so the styles/scripts it enqueues land in the right place.
        $content = '<div class="aas-affiliate-dashboard gs-invite-tracking">' . do_shortcode('[affiliate_link]') . '</div>';
        // The tracking block never uses these (only the Social-post composer, which is its own iframe).
        wp_dequeue_script('fullcalendar');
        wp_dequeue_script('chartjs');
    }
    wp_enqueue_style('dashicons');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<style>html,body{margin:0;padding:0;background:transparent;color:#e8edf5;font-family:Inter,"Segoe UI",system-ui,sans-serif}body{padding:4px 4px 24px}</style>';
    wp_print_styles();
    wp_print_head_scripts();
    echo '</head><body class="em-invite-frame">';
    if ($tracking) {
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the shortcode escapes its own output
    } else {
        gend_society_invite_render_panel(array(), array('embedded' => false));
    }
    if (is_admin()) {
        do_action('admin_print_footer_scripts');
    } else {
        wp_print_footer_scripts();
    }
    echo '</body></html>';
    exit;
}

/* Workspace chats for the widget's Projects tab (2026-11-12). Needs the projects plugin (hub); elsewhere it is empty. */
add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/chat/projects', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_chat_rest_projects',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
    register_rest_route('em/v1', '/chat/projects/(?P<id>\d+)/send', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_chat_rest_project_send',
        'permission_callback' => 'em_chat_perm_logged_in',
    ));
});

function em_chat_rest_projects(WP_REST_Request $r) {
    $uid = get_current_user_id();
    if (! $uid) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    if (! function_exists('psoo_pm_ws_user_projects')) return rest_ensure_response(array('items' => array()));
    $items = array();
    foreach (psoo_pm_ws_user_projects($uid) as $p) {
        $sum = null;
        if (! empty($p['thread_id'])) {
            if (function_exists('psoo_pm_ws_thread_sync')) psoo_pm_ws_thread_sync((int) $p['project_id'], (int) $p['thread_id']);
            $sum = em_chat_thread_summary((int) $p['thread_id'], $uid);
        }
        $p['thread'] = $sum;
        $items[] = $p;
    }
    usort($items, function ($a, $b) {
        $ta = $a['thread'] && $a['thread']['last_at'] ? strtotime($a['thread']['last_at']) : 0;
        $tb = $b['thread'] && $b['thread']['last_at'] ? strtotime($b['thread']['last_at']) : 0;
        return $tb <=> $ta;
    });
    return rest_ensure_response(array('items' => $items));
}

function em_chat_rest_project_send(WP_REST_Request $r) {
    $uid = get_current_user_id();
    if (! $uid) return new WP_Error('em_chat_no_user', 'Login required', array('status' => 401));
    if (! function_exists('psoo_pm_ws_thread_send')) return new WP_Error('em_chat_no_projects', 'Workspace chat is not available here.', array('status' => 404));
    $content = trim((string) $r->get_param('content'));
    if ($content === '') return new WP_Error('em_chat_empty', 'Message body required', array('status' => 400));
    $res = psoo_pm_ws_thread_send((int) $r['id'], $uid, wp_kses_post($content));
    if (is_wp_error($res)) {
        return new WP_Error($res->get_error_code(), $res->get_error_message(), array('status' => 'not_member' === $res->get_error_code() ? 403 : 400));
    }
    return rest_ensure_response(array('ok' => true, 'thread' => em_chat_thread_summary((int) $res['thread_id'], $uid)));
}

/* ---------------------------------------------------------------------------------------------------------------------
 * Who can message whom + connection / report / block / mute from the chat (owner 2026-11-12).
 * Rule: a one-to-one chat needs the two members to be connected (BuddyPress friends), unless the recipient is a Project
 * Consultant or a site admin, the sender is a site admin, or the other side is an AI agent. Blocks (Youzify block
 * members: yz_blocked_members) stop messages both ways. Reports go to the moderation queue (YZM_Query). Mute is per
 * thread (user meta _em_chat_muted): a muted chat never pops open by itself.
 * ------------------------------------------------------------------------------------------------------------------ */

function em_chat_user_is_consultant($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;
    if (function_exists('psoo_dash_user_types') && function_exists('psoo_dash_norm_type')) {
        $types = (array) psoo_dash_user_types($user_id);
        if (isset($types[psoo_dash_norm_type('Project Consultants')])) return true;
    }
    if (function_exists('bp_get_member_type')) {
        foreach ((array) bp_get_member_type($user_id, false) as $t) {
            if (strpos((string) $t, 'consultant') !== false) return true;
        }
    }
    return (bool) apply_filters('em_chat_user_is_consultant', false, $user_id);
}

function em_chat_user_is_site_admin($user_id) {
    return (int) $user_id > 0 && (is_super_admin((int) $user_id) || user_can((int) $user_id, 'manage_options'));
}

function em_chat_is_blocked($blocker, $blocked) {
    global $wpdb;
    $t = $wpdb->prefix . 'yz_blocked_members';
    static $has = null;
    if ($has === null) $has = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
    if (! $has) return false;
    return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE user_id=%d AND blocked_user=%d AND status=1 LIMIT 1", (int) $blocker, (int) $blocked));
}

/** Everything the chat needs to know about the viewer and one other member. */
function em_chat_relationship($me, $other) {
    $me = (int) $me; $other = (int) $other;
    $u  = $other > 0 ? get_userdata($other) : null;
    $r  = array(
        'user_id' => $other, 'exists' => (bool) $u,
        'display_name' => $u ? ($u->display_name ?: $u->user_login) : __('Deleted member', 'email-manager'),
        'avatar_url' => $u ? get_avatar_url($u->ID, array('size' => 96)) : get_avatar_url(0, array('size' => 96, 'default' => 'mystery', 'force_default' => true)),
        'profile_url' => ($u && function_exists('bp_members_get_user_url')) ? bp_members_get_user_url($u->ID) : '',
        'friend' => 'not_friends', 'friendship_id' => 0,
        'blocked' => false, 'blocked_by' => false,
        'is_agent' => $u ? em_chat_user_is_agent($other) : false,
        'is_consultant' => $u ? em_chat_user_is_consultant($other) : false,
        'is_admin' => $u ? em_chat_user_is_site_admin($other) : false,
        'can_block' => $u && ! em_chat_user_is_site_admin($other) && $other !== $me,
        'can_message' => false, 'reason' => '',
    );
    if (! $u) { $r['reason'] = 'deleted'; return $r; }
    if (function_exists('bp_is_active') && bp_is_active('friends') && function_exists('friends_check_friendship_status')) {
        $r['friend'] = (string) friends_check_friendship_status($me, $other);
        if ($r['friend'] === 'awaiting_response' || $r['friend'] === 'pending') {
            $r['friendship_id'] = (int) (function_exists('friends_get_friendship_id') ? friends_get_friendship_id($me, $other) : 0);
        }
    }
    $r['blocked']    = em_chat_is_blocked($me, $other);
    $r['blocked_by'] = em_chat_is_blocked($other, $me);
    if ($r['blocked']) { $r['reason'] = 'blocked'; return $r; }
    if ($r['blocked_by']) { $r['reason'] = 'unavailable'; return $r; }
    $open = $r['is_agent'] || $r['is_consultant'] || $r['is_admin'] || em_chat_user_is_site_admin($me) || $r['friend'] === 'is_friend';
    $r['can_message'] = (bool) apply_filters('em_chat_can_message', $open, $me, $other);
    if (! $r['can_message']) $r['reason'] = 'not_connected';
    return $r;
}

/** Message-permission error for a one-to-one send, or null when allowed. */
function em_chat_send_guard($me, $other) {
    $rel = em_chat_relationship($me, $other);
    if ($rel['can_message']) return null;
    $msg = array(
        'deleted'       => __('This member no longer has an account.', 'email-manager'),
        'blocked'       => __('You blocked this member. Unblock them to send a message.', 'email-manager'),
        'unavailable'   => __('You can’t message this member.', 'email-manager'),
        'not_connected' => sprintf(__('Connect with %s first. Only Project Consultants and site admins can be messaged without a connection.', 'email-manager'), $rel['display_name']),
    );
    return new WP_Error('em_chat_' . $rel['reason'], $msg[$rel['reason']] ?? $msg['unavailable'], array('status' => 403, 'relationship' => $rel));
}

/** The single other participant of a one-to-one thread (0 for group / multi-person threads). */
function em_chat_thread_other($thread_id, $me) {
    global $wpdb;
    if (em_chat_thread_group_info($thread_id) || ! function_exists('buddypress')) return 0;
    $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . buddypress()->messages->table_name_recipients . ' WHERE thread_id=%d AND user_id<>%d', (int) $thread_id, (int) $me)));
    return count($ids) === 1 ? $ids[0] : 0;
}

add_action('rest_api_init', function () {
    $p = 'em_chat_perm_logged_in';
    register_rest_route('em/v1', '/chat/relationship', array('methods' => 'GET', 'callback' => 'em_chat_rest_relationship', 'permission_callback' => $p));
    register_rest_route('em/v1', '/chat/connect', array('methods' => 'POST', 'callback' => 'em_chat_rest_connect', 'permission_callback' => $p));
    register_rest_route('em/v1', '/chat/block', array('methods' => 'POST', 'callback' => 'em_chat_rest_block', 'permission_callback' => $p));
    register_rest_route('em/v1', '/chat/report', array('methods' => 'POST', 'callback' => 'em_chat_rest_report', 'permission_callback' => $p));
    register_rest_route('em/v1', '/chat/threads/(?P<id>\d+)/mute', array('methods' => 'POST', 'callback' => 'em_chat_rest_mute', 'permission_callback' => $p));
    register_rest_route('em/v1', '/chat/projects/(?P<id>\d+)/members', array('methods' => 'GET', 'callback' => 'em_chat_rest_project_members', 'permission_callback' => $p));
});

function em_chat_rest_relationship(WP_REST_Request $r) {
    $me = get_current_user_id();
    $other = (int) $r->get_param('user_id');
    if ($other <= 0 || $other === $me) return new WP_Error('em_chat_bad_user', 'Unknown member', array('status' => 400));
    return rest_ensure_response(em_chat_relationship($me, $other));
}

function em_chat_rest_connect(WP_REST_Request $r) {
    $me = get_current_user_id();
    $other = (int) $r->get_param('user_id');
    $act = sanitize_key((string) $r->get_param('do'));
    if ($other <= 0 || $other === $me || ! get_userdata($other)) return new WP_Error('em_chat_bad_user', 'Unknown member', array('status' => 400));
    if (! function_exists('bp_is_active') || ! bp_is_active('friends')) return new WP_Error('em_chat_no_friends', 'Connections are not available.', array('status' => 400));
    $rel = em_chat_relationship($me, $other);
    if ($rel['blocked'] || $rel['blocked_by']) return new WP_Error('em_chat_blocked', 'Not available for this member.', array('status' => 403));
    if ($act === 'add' && $rel['friend'] === 'not_friends') {
        friends_add_friend($me, $other);
    } elseif ($act === 'accept' && $rel['friend'] === 'awaiting_response' && $rel['friendship_id']) {
        friends_accept_friendship($rel['friendship_id']);
    } elseif ($act === 'cancel' && $rel['friend'] === 'pending') {
        friends_withdraw_friendship($me, $other);
    } elseif ($act === 'remove' && $rel['friend'] === 'is_friend') {
        friends_remove_friend($me, $other);
    }
    return rest_ensure_response(em_chat_relationship($me, $other));
}

function em_chat_rest_block(WP_REST_Request $r) {
    global $wpdb;
    $me = get_current_user_id();
    $other = (int) $r->get_param('user_id');
    $block = (string) $r->get_param('block') !== '0';
    if ($other <= 0 || $other === $me || ! get_userdata($other)) return new WP_Error('em_chat_bad_user', 'Unknown member', array('status' => 400));
    if (! (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'yz_blocked_members'))) return new WP_Error('em_chat_no_block', 'Blocking is not available.', array('status' => 400));
    if ($block) {
        if (em_chat_user_is_site_admin($other)) return new WP_Error('em_chat_cant_block', __('Site admins can’t be blocked.', 'email-manager'), array('status' => 403));
        if (! em_chat_is_blocked($me, $other)) {
            $args = array('user_id' => $me, 'blocked_user' => $other, 'description' => sanitize_textarea_field((string) $r->get_param('reason')), 'status' => 1, 'blocking_date' => function_exists('bp_core_current_time') ? bp_core_current_time() : current_time('mysql', true));
            $wpdb->insert($wpdb->prefix . 'yz_blocked_members', $args);
            if (function_exists('yzbbm_update_blocked_users_list')) yzbbm_update_blocked_users_list($me, $other, 'block');
            if (function_exists('yzbbm_notify_admin') && function_exists('yzbbm_option') && 'on' === yzbbm_option('yzbbm_notify_admin_on_block', 'on')) yzbbm_notify_admin('yzbbm-user-blocked', $args);
        }
    } else {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}yz_blocked_members SET status=0, unblocking_date=%s WHERE user_id=%d AND blocked_user=%d AND status=1", current_time('mysql', true), $me, $other));
        if (function_exists('yzbbm_update_blocked_users_list')) yzbbm_update_blocked_users_list($me, $other, 'unblock');
    }
    return rest_ensure_response(em_chat_relationship($me, $other));
}

function em_chat_rest_report(WP_REST_Request $r) {
    $me = get_current_user_id();
    $other = (int) $r->get_param('user_id');
    if ($other <= 0 || $other === $me || ! get_userdata($other)) return new WP_Error('em_chat_bad_user', 'Unknown member', array('status' => 400));
    if (! class_exists('YZM_Query')) return new WP_Error('em_chat_no_reports', 'Reporting is not available.', array('status' => 400));
    if (get_option('yz_block_reports_' . $me)) return new WP_Error('em_chat_reports_off', __('You can’t send reports right now.', 'email-manager'), array('status' => 403));
    $cats = array('spam', 'harassment', 'inappropriate', 'scam', 'other');
    $cat = sanitize_key((string) $r->get_param('category'));
    $data = array(
        'status' => 'open', 'component' => 'user', 'reporter' => $me, 'author' => $other, 'item_id' => $other,
        'category' => in_array($cat, $cats, true) ? $cat : 'other',
        'description' => mb_substr(sanitize_textarea_field((string) $r->get_param('description')), 0, 2000),
    );
    $q = new YZM_Query();
    if (method_exists($q, 'is_user_already_reported_item') && $q->is_user_already_reported_item($me, $other, 'user')) {
        return rest_ensure_response(array('ok' => true, 'already' => true));
    }
    $already = method_exists($q, 'is_item_already_reported') ? $q->is_item_already_reported($other, 'user') : true;
    $id = $q->add_report(apply_filters('yz_user_report_form_data', $data));
    if ($id && ! $already && function_exists('yzm_notify_admin') && function_exists('youzify_moderation_option') && 'on' === youzify_moderation_option('yz_notify_admin_on_new_reports', 'on')) {
        yzm_notify_admin('yz-admin-new-report', $id);
    }
    if ($id) do_action('yzm_after_adding_user_report', $id, $data);
    return rest_ensure_response(array('ok' => (bool) $id));
}

function em_chat_muted_threads($uid) {
    $m = get_user_meta((int) $uid, '_em_chat_muted', true);
    return is_array($m) ? array_map('intval', $m) : array();
}

function em_chat_rest_mute(WP_REST_Request $r) {
    $me = get_current_user_id();
    $tid = (int) $r['id'];
    if (! function_exists('messages_check_thread_access') || ! messages_check_thread_access($tid, $me)) return new WP_Error('em_chat_forbidden', 'No access to this thread', array('status' => 403));
    $m = em_chat_muted_threads($me);
    if ((string) $r->get_param('muted') === '0') $m = array_values(array_diff($m, array($tid)));
    elseif (! in_array($tid, $m, true)) $m[] = $tid;
    update_user_meta($me, '_em_chat_muted', array_slice($m, -500));
    return rest_ensure_response(array('ok' => true, 'muted' => in_array($tid, $m, true)));
}

function em_chat_rest_project_members(WP_REST_Request $r) {
    global $wpdb;
    $me = get_current_user_id();
    $pid = (int) $r['id'];
    if (! class_exists('PSOO_PM_Projects') || ! function_exists('psoo_pm_ws_member_user_ids')) return new WP_Error('em_chat_no_projects', 'Not available here.', array('status' => 404));
    if (! PSOO_PM_Projects::user_is_project_member($pid, $me) && ! em_chat_user_is_site_admin($me)) return new WP_Error('em_chat_forbidden', 'Not a member of this workspace', array('status' => 403));
    $in_chat = psoo_pm_ws_member_user_ids($pid);
    $rows = array();
    foreach (array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}pm_role_user WHERE project_id=%d", $pid))) as $uid) {
        $u = get_userdata($uid);
        if (! $u) continue;
        $rows[] = array('user_id' => $uid, 'display_name' => $u->display_name, 'avatar_url' => get_avatar_url($uid, array('size' => 64)),
            'in_chat' => in_array($uid, $in_chat, true), 'is_agent' => em_chat_user_is_agent($uid), 'is_me' => $uid === $me);
    }
    usort($rows, function ($a, $b) { return ($b['in_chat'] <=> $a['in_chat']) ?: strcasecmp($a['display_name'], $b['display_name']); });
    $can = class_exists('PSOO_PM_Members') && method_exists('PSOO_PM_Members', 'can_admin_members') && PSOO_PM_Members::can_admin_members($pid, $me);
    $base = function_exists('bp_members_get_user_url') ? bp_members_get_user_url($me) : home_url('/');
    return rest_ensure_response(array(
        'members' => $rows,
        'can_invite' => $can,
        'invite_url' => $can ? add_query_arg(array('pm_open' => $pid, 'pm_members' => 1), trailingslashit($base) . 'groups/') : '',
        'muted' => ($tid = (function_exists('psoo_pm_ws_thread_id') ? psoo_pm_ws_thread_id($pid) : 0)) ? in_array($tid, em_chat_muted_threads($me), true) : false,
    ));
}

/* Direct messages live in the chat widget (its own unread badges and pop-ups), so they don't also go into the member's
 * notifications menu (owner 2026-11-12). Only BuddyPress's "sent you a new private message" notification is removed;
 * the new-message e-mail and the chat's unread counts are unchanged. */
add_action('bp_init', function () {
    remove_action('messages_message_sent', 'bp_messages_message_sent_add_notification', 10);
}, 99);
