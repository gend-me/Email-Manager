<?php
/**
 * Member Inbox: REST output guard (slice 2zz.2 hotfix).
 *
 * Defensive output buffering for every em/v1 route. Sites that load
 * 3rd-party plugins which echo a stray PHP notice or warning during a
 * REST request — e.g. an upstream filter that touches an uninitialized
 * BP component on `rest_api_init` — would otherwise produce a response
 * body like:
 *
 *   <b>Notice</b>: Undefined property ...
 *   {"inboxes":[],"labels":[],...}
 *
 * which wp.apiFetch rejects with "The response is not a valid JSON
 * response" and the SPA shows a red error banner.
 *
 * Strategy: hook rest_pre_dispatch to start an output buffer when the
 * matched route is in em/v1, then rest_post_dispatch to discard
 * whatever ended up in that buffer before WP writes the JSON body.
 *
 * @package EmailManager
 * @since   1.6.1
 */

defined('ABSPATH') || exit;

add_filter('rest_pre_dispatch', 'em_inbox_rest_output_guard_open', 5, 3);
function em_inbox_rest_output_guard_open($result, $server, $request) {
    if (! $request instanceof WP_REST_Request) return $result;
    $route = (string) $request->get_route();
    if (strpos($route, '/em/v1/') === 0) {
        // Only one of our buffers active at a time. The post_dispatch
        // hook below closes it. If we somehow bail before
        // post_dispatch (e.g. fatal in the handler) WP's own
        // shutdown will flush anyway; we just won't get to discard.
        ob_start();
    }
    return $result;
}

add_filter('rest_post_dispatch', 'em_inbox_rest_output_guard_close', 5, 3);
function em_inbox_rest_output_guard_close($response, $server, $request) {
    if (! $request instanceof WP_REST_Request) return $response;
    $route = (string) $request->get_route();
    if (strpos($route, '/em/v1/') !== 0) return $response;
    // Discard anything that snuck into stdout during the handler. If
    // WP_DEBUG is on and there WAS garbage, log it so ops can chase
    // the offender.
    $junk = ob_get_clean();
    if ($junk !== false && $junk !== '' && defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[em-inbox] REST output guard caught ' . strlen($junk) . ' bytes of pre-json output on ' . $route . ': ' . mb_substr($junk, 0, 200));
    }
    return $response;
}
