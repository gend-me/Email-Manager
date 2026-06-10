<?php
/**
 * Member Inbox: bootstrap endpoint (slice 2yy).
 *
 * Combines the 5 mount-time round-trips the React SPA used to make
 * (/inboxes, /labels, /vacation, /signature, /grants) into a single
 * GET /em/v1/inbox/bootstrap call. Cuts initial load from ~5 sequential
 * REST hits to 1, which dominates perceived "open the inbox" latency
 * since browsers can't pipeline these calls due to per-origin nonce
 * cache warmup.
 *
 * Each section is wrapped in try/catch so a transient failure in one
 * (say, vacation table not migrated yet) doesn't break the rest.
 *
 * @package EmailManager
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/bootstrap', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_bootstrap',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_bootstrap(WP_REST_Request $r) {
    $out = array(
        'inboxes'   => array(),
        'labels'    => array(),
        'vacation'  => null,
        'signature' => null,
        'grants'    => array('given' => array(), 'received' => array()),
        'recovery_email' => null,
    );

    // /inboxes
    try {
        if (function_exists('em_inbox_list_inboxes')) {
            $req = new WP_REST_Request('GET', '/em/v1/inbox/inboxes');
            $res = em_inbox_list_inboxes($req);
            if (! is_wp_error($res)) $out['inboxes'] = is_object($res) ? $res->get_data() : $res;
        }
    } catch (\Throwable $e) { /* skip */ }

    // /labels
    try {
        if (function_exists('em_inbox_labels_for_user')) {
            $u = wp_get_current_user();
            $out['labels'] = em_inbox_labels_for_user($u ? $u->ID : 0);
        }
    } catch (\Throwable $e) { /* skip */ }

    // /vacation
    try {
        if (function_exists('em_inbox_vacation_get')) {
            $req = new WP_REST_Request('GET', '/em/v1/inbox/vacation');
            $res = em_inbox_vacation_get($req);
            if (! is_wp_error($res)) $out['vacation'] = is_object($res) ? $res->get_data() : $res;
        }
    } catch (\Throwable $e) { /* skip */ }

    // /signature
    try {
        if (function_exists('em_inbox_signature_get')) {
            $req = new WP_REST_Request('GET', '/em/v1/inbox/signature');
            $res = em_inbox_signature_get($req);
            if (! is_wp_error($res)) $out['signature'] = is_object($res) ? $res->get_data() : $res;
        }
    } catch (\Throwable $e) { /* skip */ }

    // /grants
    try {
        if (function_exists('em_inbox_grants_received_by') && function_exists('em_inbox_grants_given_by')) {
            $u = wp_get_current_user();
            if ($u && $u->ID) {
                $out['grants'] = array(
                    'given'    => em_inbox_grants_given_by((int) $u->ID),
                    'received' => em_inbox_grants_received_by((int) $u->ID),
                );
            }
        }
    } catch (\Throwable $e) { /* skip */ }

    // Slice 2zz: recovery email state
    try {
        if (function_exists('em_inbox_recovery_email_state')) {
            $u = wp_get_current_user();
            if ($u && $u->ID) {
                $out['recovery_email'] = em_inbox_recovery_email_state((int) $u->ID);
            }
        }
    } catch (\Throwable $e) { /* skip */ }

    return rest_ensure_response($out);
}
