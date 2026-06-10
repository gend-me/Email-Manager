<?php
/**
 * Member Inbox: recovery email + password-reset routing (slice 2zz).
 *
 * When a member's WP user_email IS their inbox address (the default
 * for self-hosted MTA owners), a forgotten password becomes a
 * chicken-and-egg: the reset link is sent to the inbox they can't
 * read because they can't log in. This module lets every member
 * register a SEPARATE, verified recovery address (their personal
 * Gmail, etc.) where system emails get redirected.
 *
 * Storage (user_meta on each WP user):
 *   em_inbox_recovery_email             confirmed recovery address (string)
 *   em_inbox_recovery_email_pending     awaiting verification (string)
 *   em_inbox_recovery_email_token       sha256 of the verification token
 *   em_inbox_recovery_email_token_exp   unix ts when the token expires
 *
 * Verification flow:
 *   1. POST /em/v1/inbox/recovery-email {recovery_email: "..."} writes
 *      _pending + token. We send a click-link to the new address.
 *   2. User clicks https://<site>/wp-json/em/v1/inbox/recovery-email/verify
 *      ?uid=<id>&token=<plaintext>. Server hashes token, matches, promotes
 *      pending → confirmed, clears the pending/token meta.
 *   3. DELETE /em/v1/inbox/recovery-email clears the confirmed value.
 *      (No verification round-trip needed to REMOVE.)
 *
 * Email routing:
 *   - retrieve_password_notification_email filter rewrites $email_args
 *     ['to'] when the recipient is an inbox owner with a recovery
 *     email. Adds [Recovery] prefix to the subject so the user can
 *     spot it in their Gmail. (WP 5.3+ filter.)
 *   - wp_mail filter as a fallback for older WP installs and any
 *     other transactional mail that names password reset patterns
 *     in subject — covers wp_password_change_notification, new_user
 *     onboarding, email-change-confirmation.
 *
 * @package EmailManager
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

const EM_INBOX_REC_META         = 'em_inbox_recovery_email';
const EM_INBOX_REC_PENDING_META = 'em_inbox_recovery_email_pending';
const EM_INBOX_REC_TOKEN_META   = 'em_inbox_recovery_email_token';
const EM_INBOX_REC_TOKEN_EXP    = 'em_inbox_recovery_email_token_exp';
const EM_INBOX_REC_TOKEN_TTL    = 86400;   // 24h

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

function em_inbox_recovery_email_for_user($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return '';
    return strtolower(trim((string) get_user_meta($user_id, EM_INBOX_REC_META, true)));
}

function em_inbox_recovery_email_state($user_id) {
    $user_id = (int) $user_id;
    return array(
        'confirmed' => em_inbox_recovery_email_for_user($user_id),
        'pending'   => strtolower(trim((string) get_user_meta($user_id, EM_INBOX_REC_PENDING_META, true))),
        'pending_expires_at' => (int) get_user_meta($user_id, EM_INBOX_REC_TOKEN_EXP, true) ?: null,
    );
}

/* -------------------------------------------------------------------------
 * REST routes
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/recovery-email', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_recovery_email_rest_get',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_recovery_email_rest_set',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'em_inbox_recovery_email_rest_delete',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
    // Verification: public path (the user has clicked a link from
    // their inbox without being logged in to our system).
    register_rest_route('em/v1', '/inbox/recovery-email/verify', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_recovery_email_rest_verify',
        'permission_callback' => '__return_true',
        'args' => array(
            'uid'   => array('type' => 'integer', 'required' => true),
            'token' => array('type' => 'string',  'required' => true),
        ),
    ));
    register_rest_route('em/v1', '/inbox/recovery-email/resend', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_recovery_email_rest_resend',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_recovery_email_rest_get(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_rec_no_user', 'Login required', array('status' => 401));
    return rest_ensure_response(em_inbox_recovery_email_state((int) $u->ID));
}

function em_inbox_recovery_email_rest_set(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_rec_no_user', 'Login required', array('status' => 401));

    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();
    $new  = strtolower(trim((string) ($body['recovery_email'] ?? '')));
    if (! is_email($new)) {
        return new WP_Error('em_rec_bad_email', 'A valid email is required', array('status' => 400));
    }
    // Don't allow recovery == primary (defeats the purpose).
    if ($new === strtolower((string) $u->user_email)) {
        return new WP_Error('em_rec_same_as_primary',
            'Recovery email must be different from your account email — the whole point is that you can read it when locked out.',
            array('status' => 400));
    }
    $meta_addr = strtolower((string) get_user_meta($u->ID, 'em_inbox_address', true));
    if ($meta_addr && $new === $meta_addr) {
        return new WP_Error('em_rec_same_as_inbox',
            'Recovery email cannot match your inbox address.',
            array('status' => 400));
    }

    em_inbox_recovery_email_send_token((int) $u->ID, $new);
    return rest_ensure_response(em_inbox_recovery_email_state((int) $u->ID));
}

function em_inbox_recovery_email_rest_delete(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_rec_no_user', 'Login required', array('status' => 401));
    delete_user_meta($u->ID, EM_INBOX_REC_META);
    delete_user_meta($u->ID, EM_INBOX_REC_PENDING_META);
    delete_user_meta($u->ID, EM_INBOX_REC_TOKEN_META);
    delete_user_meta($u->ID, EM_INBOX_REC_TOKEN_EXP);
    return rest_ensure_response(em_inbox_recovery_email_state((int) $u->ID));
}

function em_inbox_recovery_email_rest_resend(WP_REST_Request $r) {
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_rec_no_user', 'Login required', array('status' => 401));
    $pending = strtolower(trim((string) get_user_meta($u->ID, EM_INBOX_REC_PENDING_META, true)));
    if (! is_email($pending)) {
        return new WP_Error('em_rec_no_pending', 'No pending recovery email to resend', array('status' => 400));
    }
    em_inbox_recovery_email_send_token((int) $u->ID, $pending);
    return rest_ensure_response(em_inbox_recovery_email_state((int) $u->ID));
}

function em_inbox_recovery_email_rest_verify(WP_REST_Request $r) {
    $uid   = (int) $r->get_param('uid');
    $token = (string) $r->get_param('token');
    if ($uid <= 0 || $token === '') {
        return new WP_Error('em_rec_bad_link', 'Invalid verification link', array('status' => 400));
    }
    $stored_hash = (string) get_user_meta($uid, EM_INBOX_REC_TOKEN_META, true);
    $exp         = (int)    get_user_meta($uid, EM_INBOX_REC_TOKEN_EXP, true);
    $pending     = strtolower(trim((string) get_user_meta($uid, EM_INBOX_REC_PENDING_META, true)));
    if ($stored_hash === '' || $pending === '') {
        return new WP_Error('em_rec_no_pending', 'No pending verification on this account', array('status' => 404));
    }
    if ($exp > 0 && $exp < time()) {
        return new WP_Error('em_rec_expired', 'Verification link expired. Please re-submit your recovery email.', array('status' => 410));
    }
    if (! hash_equals($stored_hash, hash('sha256', $token))) {
        return new WP_Error('em_rec_bad_token', 'Invalid verification link', array('status' => 403));
    }
    // Promote pending → confirmed.
    update_user_meta($uid, EM_INBOX_REC_META, $pending);
    delete_user_meta($uid, EM_INBOX_REC_PENDING_META);
    delete_user_meta($uid, EM_INBOX_REC_TOKEN_META);
    delete_user_meta($uid, EM_INBOX_REC_TOKEN_EXP);
    return rest_ensure_response(array(
        'ok' => true,
        'verified_email' => $pending,
        'next_url' => home_url('/'),
    ));
}

/* -------------------------------------------------------------------------
 * Send the verification email
 * ------------------------------------------------------------------------- */

function em_inbox_recovery_email_send_token($user_id, $candidate_email) {
    $candidate_email = strtolower(trim((string) $candidate_email));
    if (! is_email($candidate_email)) return false;

    $token = bin2hex(random_bytes(16));
    $hash  = hash('sha256', $token);
    update_user_meta($user_id, EM_INBOX_REC_PENDING_META, $candidate_email);
    update_user_meta($user_id, EM_INBOX_REC_TOKEN_META,   $hash);
    update_user_meta($user_id, EM_INBOX_REC_TOKEN_EXP,    time() + EM_INBOX_REC_TOKEN_TTL);

    $verify_url = add_query_arg(array(
        'uid'   => (int) $user_id,
        'token' => rawurlencode($token),
    ), rest_url('em/v1/inbox/recovery-email/verify'));

    $u = get_user_by('id', $user_id);
    $site = get_bloginfo('name');
    $subject = sprintf(__('[%s] Confirm your recovery email', 'email-manager'), $site);
    $body  = sprintf(
        __("Hi %s,\n\nYou (or someone with access to your %s account) asked to register %s as the recovery address for your account.\n\nClick the link below within 24 hours to confirm:\n\n%s\n\nIf you didn't request this, you can safely ignore this email.\n", 'email-manager'),
        $u ? ($u->display_name ?: $u->user_login) : 'there',
        $site,
        $candidate_email,
        $verify_url
    );
    // Send via wp_mail; this bypasses our recovery-rewrite filter
    // because the To: IS the recovery candidate, not the user's
    // primary address.
    return wp_mail($candidate_email, $subject, $body);
}

/* -------------------------------------------------------------------------
 * Password-reset routing — the actual fix for the chicken-and-egg
 * ------------------------------------------------------------------------- */

/**
 * Reroute the password-reset email (WP 5.3+ filter).
 * $email_args = ['to', 'subject', 'message', 'headers'].
 */
add_filter('retrieve_password_notification_email', 'em_inbox_recovery_email_route_password_reset', 10, 4);
function em_inbox_recovery_email_route_password_reset($email_args, $key, $user_login, $user_data) {
    if (! is_object($user_data) || ! isset($user_data->ID)) return $email_args;
    $rec = em_inbox_recovery_email_for_user((int) $user_data->ID);
    if (! $rec) return $email_args;
    $email_args['to']      = $rec;
    $email_args['subject'] = '[Recovery] ' . ($email_args['subject'] ?? '');
    return $email_args;
}

/**
 * Last-resort safety net: catch any wp_mail going to a user's primary
 * email when that user has a recovery address AND the subject looks
 * like a credential/account email. Covers paths that don't use the
 * above filter (older WP, custom forgot-password flows, etc.).
 */
add_filter('wp_mail', 'em_inbox_recovery_email_wp_mail_fallback', 10, 1);
function em_inbox_recovery_email_wp_mail_fallback($args) {
    if (! is_array($args) || empty($args['to']) || empty($args['subject'])) return $args;
    $to_list = is_array($args['to']) ? $args['to'] : array($args['to']);
    $subject = (string) $args['subject'];
    // Conservative subject patterns — don't reroute every wp_mail.
    if (! preg_match('/password|reset|account|login|verify|confirm/i', $subject)) return $args;

    $rewritten = false;
    foreach ($to_list as $i => $to) {
        $to = trim((string) $to);
        $u  = get_user_by('email', $to);
        if (! $u) continue;
        $rec = em_inbox_recovery_email_for_user((int) $u->ID);
        if (! $rec) continue;
        // Don't double-route if a previous filter already swapped to
        // the recovery address.
        if (strtolower($to) === strtolower($rec)) continue;
        $to_list[$i] = $rec;
        $rewritten = true;
    }
    if ($rewritten) {
        $args['to'] = $to_list;
        if (strpos($subject, '[Recovery]') !== 0) {
            $args['subject'] = '[Recovery] ' . $subject;
        }
    }
    return $args;
}
