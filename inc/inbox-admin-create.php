<?php
/**
 * Member Inbox: admin "Add new inbox" REST endpoint (slice 2ss).
 *
 * Admin-only path that creates a new WP user + provisions them as
 * the owner of an inbox address. Used when an operator wants to
 * pre-create a mailbox before the first inbound message arrives.
 *
 * Two modes:
 *   - new_user (default): create a WP user whose user_email == the
 *     inbox address. em_inbox_address user_meta is stamped to the same
 *     value. A password is auto-generated; if send_invite=true the
 *     user gets the WP "set your password" email.
 *   - existing_user: assigns the inbox address to an existing user
 *     (looked up by email) via em_inbox_address user_meta only — does
 *     NOT change their user_email.
 *
 * Endpoint:
 *   POST /em/v1/inbox/admin/inboxes
 *   body: { email, display_name?, send_invite?, role?, mode? }
 *
 * @package EmailManager
 * @since   1.5.0
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/admin/inboxes', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_admin_create_inbox',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ));
});

function em_inbox_admin_create_inbox(WP_REST_Request $r) {
    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();

    $email        = strtolower(trim((string) ($body['email'] ?? '')));
    $display_name = trim((string) ($body['display_name'] ?? ''));
    $send_invite  = ! empty($body['send_invite']);
    $role         = (string) ($body['role'] ?? 'subscriber');
    $mode         = (string) ($body['mode'] ?? 'new_user');

    if (! is_email($email)) {
        return new WP_Error('em_addinbox_bad_email', 'A valid email is required', array('status' => 400));
    }
    if (! in_array($role, array('subscriber', 'contributor', 'author', 'editor', 'administrator'), true)) {
        return new WP_Error('em_addinbox_bad_role', 'role must be a standard WP role', array('status' => 400));
    }
    if (! in_array($mode, array('new_user', 'existing_user'), true)) {
        return new WP_Error('em_addinbox_bad_mode', 'mode must be new_user or existing_user', array('status' => 400));
    }

    $existing = get_user_by('email', $email);

    if ($mode === 'existing_user') {
        if (! $existing) {
            return new WP_Error('em_addinbox_no_user',
                'No WP user with email ' . $email . '. Switch to mode=new_user to create one.',
                array('status' => 404));
        }
        update_user_meta($existing->ID, 'em_inbox_address', $email);
        return rest_ensure_response(array(
            'ok'      => true,
            'user_id' => (int) $existing->ID,
            'email'   => $email,
            'mode'    => 'existing_user',
            'created' => false,
        ));
    }

    // mode === 'new_user' — delegate to the shared in-process provisioner
    // (single source of truth, reused by trusted server-side callers).
    $res = em_inbox_provision_user($email, $display_name, $role);
    if (is_wp_error($res)) {
        return $res;
    }

    // Idempotent: an admin re-running with the same email just gets the
    // existing user back rather than an error — the provisioner already
    // ensured the inbox address meta is set.
    if (empty($res['created'])) {
        return rest_ensure_response(array(
            'ok'              => true,
            'user_id'         => (int) $res['user_id'],
            'email'           => $email,
            'mode'            => 'new_user',
            'created'         => false,
            'already_existed' => true,
        ));
    }

    if ($send_invite) {
        // Use the WP "new user notification" email which contains a
        // password-reset link. The 'user' kind sends only to the new
        // user (not the admin).
        wp_new_user_notification($res['user_id'], null, 'user');
    }

    return rest_ensure_response(array(
        'ok'      => true,
        'user_id' => (int) $res['user_id'],
        'email'   => $email,
        'login'   => $res['login'],
        'mode'    => 'new_user',
        'created' => true,
        'invite_sent' => (bool) $send_invite,
    ));
}
