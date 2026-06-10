<?php
/**
 * Member Inbox: customer card data aggregator (slice 2uu).
 *
 * When a thread is open in the reader, the left rail collapses the
 * filter list and replaces it with a card about the OTHER party in the
 * conversation. This endpoint serves the data for that card.
 *
 * Every section is wrapped in a function_exists / class_exists guard so
 * a site without WooCommerce / contracts-and-payments / chat-forms /
 * mycred just gets null for that section rather than a 500.
 *
 * Endpoint:
 *   GET /em/v1/inbox/customer-card?email=foo@bar.com
 *   → {
 *       user:     { exists, id?, display_name?, registered? }
 *       forms:    { total, last_at }
 *       contracts:{ active, escrowed_dgen, last_at }
 *       orders:   { total, total_spent, last_status, last_at }
 *       wallet:   { dgen, mycred, points_balance }
 *     }
 *
 * @package EmailManager
 * @since   1.5.0
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/customer-card', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_customer_card',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'email' => array('type' => 'string', 'required' => true),
        ),
    ));
});

function em_inbox_customer_card(WP_REST_Request $r) {
    global $wpdb;
    $email = strtolower(trim((string) $r->get_param('email')));
    if (! is_email($email)) {
        return new WP_Error('em_card_bad_email', 'A valid email is required', array('status' => 400));
    }

    // Slice 2yy: 60s transient cache. The slow sections (wc_get_orders,
    // mycred balance lookups, chat_submission LIKE-on-postmeta) take
    // hundreds of ms on a populated site and the same email gets
    // requested every time the user clicks a different thread from the
    // same sender. ?refresh=1 bypasses the cache for ops debugging.
    $key = 'em_inbox_card_' . md5($email);
    $force = (int) $r->get_param('refresh') === 1;
    if (! $force) {
        $cached = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);
    }

    $out = array(
        'email'     => $email,
        'user'      => em_inbox_card_user($email),
        'forms'     => em_inbox_card_forms($email),
        'contracts' => em_inbox_card_contracts($email),
        'orders'    => em_inbox_card_orders($email),
        'wallet'    => em_inbox_card_wallet($email),
        '_cached_at'=> gmdate('c'),
    );
    set_transient($key, $out, 60);
    return rest_ensure_response($out);
}

// Slice 2yy: bust the customer-card cache when anything that affects
// it changes — new order, new wallet entry, new form submission for
// the email. Errs on the side of being aggressive; the cache is only
// 60s anyway so over-busting is cheap.
add_action('woocommerce_new_order',        'em_inbox_card_bust_for_order', 10, 1);
add_action('woocommerce_order_status_changed', 'em_inbox_card_bust_for_order', 10, 1);
function em_inbox_card_bust_for_order($order_id) {
    if (! class_exists('WooCommerce')) return;
    try {
        $order = wc_get_order($order_id);
        if (! $order || ! method_exists($order, 'get_billing_email')) return;
        $email = strtolower($order->get_billing_email());
        if ($email) delete_transient('em_inbox_card_' . md5($email));
    } catch (\Throwable $e) { /* skip */ }
}
// mycred fires hooks with this name as both an action (7 args) AND a
// filter (3 args, e.g. apply_filters('mycred_add', true, $log_entry,
// $settings)). Use variadic so we accept either shape without an
// ArgumentCountError, and pull user_id by position.
add_action('mycred_add', function () {
    $args = func_get_args();
    // 7-arg form: (ref, user_id, amount, entry, ref_id, data, ctype)
    if (count($args) >= 2 && is_numeric($args[1])) {
        $u = get_user_by('id', (int) $args[1]);
        if ($u && $u->user_email) delete_transient('em_inbox_card_' . md5(strtolower($u->user_email)));
    }
    // 3-arg filter form: (return, log_entry, settings). Just return the
    // first arg untouched so we don't break the filter chain.
    return $args[0] ?? null;
}, 10, 7);

/* -------------------------------------------------------------------------
 * Per-section gatherers — each returns null when its data source isn't
 * available, an array of stats when it is. Errors are swallowed.
 * ------------------------------------------------------------------------- */

function em_inbox_card_user($email) {
    $u = get_user_by('email', $email);
    if (! $u) return array('exists' => false);
    $last_login = get_user_meta($u->ID, 'em_last_login', true);
    return array(
        'exists'       => true,
        'id'           => (int) $u->ID,
        'display_name' => $u->display_name,
        'username'     => $u->user_login,
        'registered'   => $u->user_registered,
        'last_login'   => $last_login ?: null,
        'avatar_url'   => get_avatar_url($u->ID, array('size' => 80)),
    );
}

function em_inbox_card_forms($email) {
    global $wpdb;
    // chat_submission rows store answers serialized in the
    // _chat_submission_data postmeta. A meta_value LIKE on the email
    // is good enough for a count + the latest submission date.
    if (! post_type_exists('chat_submission')) return null;
    try {
        $like = '%' . $wpdb->esc_like($email) . '%';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS n, MAX(p.post_date_gmt) AS last_at
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'chat_submission' AND p.post_status = 'publish'
               AND pm.meta_key = '_chat_submission_data'
               AND pm.meta_value LIKE %s",
            $like
        ), ARRAY_A);
        return array(
            'total'   => (int) ($row['n'] ?? 0),
            'last_at' => $row['last_at'] ?? null,
        );
    } catch (\Throwable $e) { return null; }
}

function em_inbox_card_contracts($email) {
    global $wpdb;
    // contracts-and-payments uses mycred logs for task-contract escrow
    // events: type='task_contract_escrow' (negative on offerer),
    // type='task_contract_payout' (positive on recipient). We summarize
    // by looking up the WP user for this email, then counting active
    // escrow rows (escrow not yet released).
    $u = get_user_by('email', $email);
    if (! $u) return null;
    if (! class_exists('mycred_query_log') && ! function_exists('mycred_get_users_balance')) {
        return null;
    }
    try {
        $log_table = $wpdb->prefix . 'mycred_log';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $log_table)) !== $log_table) {
            return null;
        }
        // Active = positive escrow lines minus their matched release.
        // Approx: count contracts where user appears as either offerer
        // or escrow holder and the release counter-entry hasn't fired.
        $offered = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $log_table
             WHERE user_id = %d AND ctype LIKE %s AND ref LIKE %s",
            $u->ID, '%dgen%', 'task_contract_escrow'
        ));
        $awarded = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $log_table
             WHERE user_id = %d AND ref = 'task_contract_payout'",
            $u->ID
        ));
        $last_at = $wpdb->get_var($wpdb->prepare(
            "SELECT FROM_UNIXTIME(MAX(time)) FROM $log_table
             WHERE user_id = %d AND ref LIKE 'task_contract%%'",
            $u->ID
        ));
        return array(
            'active'        => max(0, $offered - $awarded),
            'offered_total' => $offered,
            'awarded_total' => $awarded,
            'last_at'       => $last_at,
        );
    } catch (\Throwable $e) { return null; }
}

function em_inbox_card_orders($email) {
    if (! class_exists('WooCommerce')) return null;
    try {
        $orders = wc_get_orders(array(
            'billing_email' => $email,
            'limit'         => 100,
            'orderby'       => 'date',
            'order'         => 'DESC',
        ));
        if (! is_array($orders)) return null;
        $total = count($orders);
        $total_spent = 0.0;
        $last_status = null;
        $last_at = null;
        foreach ($orders as $i => $o) {
            if (! is_object($o)) continue;
            if (method_exists($o, 'get_total')) $total_spent += (float) $o->get_total();
            if ($i === 0) {
                if (method_exists($o, 'get_status'))      $last_status = $o->get_status();
                if (method_exists($o, 'get_date_created')) {
                    $d = $o->get_date_created();
                    if ($d) $last_at = $d->date('Y-m-d H:i:s');
                }
            }
        }
        return array(
            'total'       => $total,
            'total_spent' => round($total_spent, 2),
            'currency'    => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'last_status' => $last_status,
            'last_at'     => $last_at,
        );
    } catch (\Throwable $e) { return null; }
}

function em_inbox_card_wallet($email) {
    $u = get_user_by('email', $email);
    if (! $u) return null;
    $out = array('user_id' => (int) $u->ID);
    // MyCred default point type balance.
    if (function_exists('mycred_get_users_balance')) {
        try {
            $out['mycred'] = (float) mycred_get_users_balance($u->ID);
        } catch (\Throwable $e) { $out['mycred'] = null; }
    }
    // DGEN balance — separate ctype in contracts-and-payments.
    if (function_exists('mycred_get_users_balance')) {
        try {
            $dgen = mycred_get_users_balance($u->ID, 'dgen');
            $out['dgen'] = $dgen !== null ? (float) $dgen : null;
        } catch (\Throwable $e) { $out['dgen'] = null; }
    }
    // Task credit balance.
    if (function_exists('mycred_get_users_balance')) {
        try {
            $tc = mycred_get_users_balance($u->ID, 'task_credit');
            $out['task_credit'] = $tc !== null ? (float) $tc : null;
        } catch (\Throwable $e) { $out['task_credit'] = null; }
    }
    return $out;
}
