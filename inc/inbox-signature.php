<?php
/**
 * Member Inbox: per-user email signatures (slice 2v).
 *
 * Each user stores an HTML signature in user_meta. On compose, the
 * React composer fetches it and appends to the body unless the
 * "don't include signature" toggle is set. Plain-text derivation
 * (for the multipart/alternative text/plain part) is done by the
 * existing htmlToPlain helper on the React side.
 *
 * REST:
 *   GET  /em/v1/inbox/signature        return current user's signature
 *   POST /em/v1/inbox/signature  {html} save (HTML sanitized via wp_kses
 *                                       with the same allowlist as
 *                                       inbound message bodies)
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_SIG_META_KEY', 'em_inbox_signature_html');

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/signature', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_signature_get',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_signature_save',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
});

function em_inbox_signature_get(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return rest_ensure_response(array('html' => ''));
    $html = (string) get_user_meta($u->ID, EM_INBOX_SIG_META_KEY, true);
    return rest_ensure_response(array('html' => $html));
}

function em_inbox_signature_save(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_sig_no_user', 'Login required', array('status' => 401));
    $body = $r->get_json_params() ?: array();
    $html = (string) ($body['html'] ?? '');
    if (mb_strlen($html) > 32768) {
        return new WP_Error('em_sig_too_long', 'Signature too long (32KB max)', array('status' => 400));
    }
    // Apply the same sanitizer as inbound — strict allowlist, javascript:
    // and data: scrubbed.
    $clean = function_exists('em_inbox_sanitize_html')
        ? em_inbox_sanitize_html($html)
        : wp_kses_post($html);
    update_user_meta($u->ID, EM_INBOX_SIG_META_KEY, $clean);
    return rest_ensure_response(array('ok' => true, 'html' => $clean));
}
