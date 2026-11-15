<?php
/**
 * Applications (owner 2026-11-13):
 *  1. One open application per member per posting. A signed-in member who already applied sees a link to that
 *     application instead of the form, and a second submission is refused (rejected applications may re-apply).
 *  2. Welcome AI Builder Tokens: a member whose account was created through one of the gend.me application forms
 *     (Project Consultant, Network Sales, Web Developer) gets 300 AI Builder Tokens once, paid by gend.me, after they
 *     confirm their email address, so they can build their profile page.
 */
if (!defined('ABSPATH')) exit;

/* ── 1. one application per posting ─────────────────────────────────────────── */

/** The member's open (not rejected) application for a posting that uses this form, or null. */
function em_app_open_application_for_form($user_id, $form_id)
{
    $user_id = (int) $user_id;
    $form_id = (int) $form_id;
    if ($user_id < 1 || $form_id < 1 || !class_exists('EM_Postings')) return null;
    $postings = array_map('intval', get_posts([
        'post_type' => EM_Postings::CPT, 'post_status' => ['publish', 'draft'], 'numberposts' => -1, 'fields' => 'ids',
        'meta_key' => EM_Postings::META_FORM_ID, 'meta_value' => $form_id,
    ]));
    if (!$postings) return null;
    foreach (EM_Postings::member_applications($user_id) as $app) {
        if (in_array((int) $app['posting_id'], $postings, true) && empty($app['rejected'])) return $app;
    }
    return null;
}

function em_app_applications_url($user_id)
{
    return function_exists('em_member_apps_url') ? em_member_apps_url($user_id) : home_url('/');
}

/** Server side: refuse a second application (chat_form_pre_submission filter in class-chat-forms-ajax.php). */
add_filter('chat_form_pre_submission', function ($pre, $form_id, $answers) {
    if ($pre !== null) return $pre;
    $uid = get_current_user_id();
    $app = $uid ? em_app_open_application_for_form($uid, $form_id) : null;
    if (!$app) return $pre;
    wp_send_json_error(sprintf(
        /* translators: %s: posting title */
        __('You already applied for %s. Open your application from your profile: %s', 'email-manager'),
        $app['title'], em_app_applications_url($uid)
    ));
}, 10, 3);

/** Apply page: a member who already applied sees their application instead of the form. */
add_filter('pre_do_shortcode_tag', function ($out, $tag, $attr) {
    if ($out !== false || !in_array($tag, ['chat_form', 'basic_form'], true) || !is_user_logged_in()) return $out;
    $uid = get_current_user_id();
    $app = em_app_open_application_for_form($uid, (int) ($attr['id'] ?? 0));
    if (!$app) return $out;
    $step = ($app['index'] >= 0 && isset($app['steps'][$app['index']])) ? $app['steps'][$app['index']]['title'] : '';
    return '<div class="em-app-dup" style="max-width:640px;margin:0 auto;padding:28px 30px;border-radius:22px;border:1px solid rgba(95,227,208,.4);'
        . 'background:radial-gradient(420px 160px at 0% 0%,rgba(95,227,208,.16),transparent 70%),#0b0f1c;color:#e2e8f0;text-align:center;font-family:Inter,system-ui,sans-serif;">'
        . '<p style="margin:0 0 6px;color:#5fe3d0;font-size:.75rem;font-weight:800;letter-spacing:2px;text-transform:uppercase;">' . esc_html__('Already applied', 'email-manager') . '</p>'
        . '<h3 style="margin:0 0 10px;color:#fff;font-size:1.4rem;line-height:1.3;">' . esc_html(sprintf(__('Your %s application is in progress', 'email-manager'), $app['title'])) . '</h3>'
        . '<p style="margin:0 0 20px;color:#94a3b8;line-height:1.6;">'
        . esc_html(sprintf(__('Applied %s.', 'email-manager'), $app['submitted']))
        . ($step !== '' ? ' ' . esc_html(sprintf(__('Current step: %s.', 'email-manager'), $step)) : '')
        . '</p>'
        . '<a href="' . esc_url(em_app_applications_url($uid)) . '" style="display:inline-block;padding:14px 26px;border-radius:999px;font-weight:800;color:#05070a;text-decoration:none;background:linear-gradient(135deg,#5fe3d0,#a7f3e7);">'
        . esc_html__('Open my application', 'email-manager') . '</a></div>';
}, 10, 3);

/* ── 2. welcome AI Builder Tokens ───────────────────────────────────────────── */

const EM_APP_WELCOME_META = '_em_app_welcome_tokens';   // user meta: the grant record (also the once-only claim)

/** Postings whose sign-ups get the tokens. Defaults to gend.me's three applications on the gend.me network only. */
function em_app_welcome_postings()
{
    $default = (wp_parse_url(network_home_url(), PHP_URL_HOST) === 'gend.me') ? [153620, 153626, 154337] : [];
    return array_filter(array_map('intval', (array) apply_filters('em_app_welcome_postings', get_site_option('em_app_welcome_postings', $default))));
}

function em_app_welcome_amount()
{
    return max(0, (float) apply_filters('em_app_welcome_tokens', get_site_option('em_app_welcome_tokens', 300)));
}

/** The application submission through which this account was created (Account Registration answer "registered"), or 0. */
function em_app_welcome_signup_submission($user_id)
{
    $postings = em_app_welcome_postings();
    if (!$postings) return 0;
    $ids = get_posts([
        'post_type' => 'chat_submission', 'post_status' => ['publish', 'draft', 'pending', 'private'], 'author' => (int) $user_id,
        'numberposts' => 20, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
        'meta_query' => [['key' => '_em_posting_id', 'value' => $postings, 'compare' => 'IN']],
    ]);
    foreach ($ids as $sid) {
        foreach ((array) get_post_meta($sid, '_chat_submission_data', true) as $a) {
            $s = is_array($a) ? ($a['answer'] ?? '') : $a;
            if (!is_string($s) || strpos($s, '"registered"') === false) continue;
            $d = json_decode($s, true);
            if (is_array($d) && ($d['action'] ?? '') === 'registered' && (int) ($d['user_id'] ?? 0) === (int) $user_id) return (int) $sid;
        }
    }
    return 0;
}

/** Grant once when the account came from an application and its email is confirmed. Returns tokens granted (0 = none). */
function em_app_welcome_maybe_grant($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id < 1 || !class_exists('AIPA_Usage') || !class_exists('EM_Postings')) return 0;
    if (get_user_meta($user_id, EM_APP_WELCOME_META, true)) return 0;
    $user = get_userdata($user_id);
    if (!$user || get_user_meta($user_id, '_aipa_is_agent', true) || in_array('ai_agent', (array) $user->roles, true)) return 0;
    $amount = em_app_welcome_amount();
    if ($amount <= 0 || EM_Postings::email_status($user_id) !== 'verified') return 0;
    $sid = em_app_welcome_signup_submission($user_id);
    if (!$sid) return 0;
    $posting = get_post((int) get_post_meta($sid, '_em_posting_id', true));
    $title   = $posting ? $posting->post_title : __('application', 'email-manager');
    $record  = ['tokens' => $amount, 'at' => time(), 'submission' => $sid, 'posting' => $posting ? (int) $posting->ID : 0, 'paid_by' => 'gend.me'];
    if (!add_user_meta($user_id, EM_APP_WELCOME_META, $record, true)) return 0;   // claimed by another request
    if (function_exists('aipa_token_log_context')) {
        aipa_token_log_context('gend_app_welcome_tokens', sprintf(__('Welcome AI Builder Tokens from gend.me (%s application)', 'email-manager'), $title), $sid);
    }
    AIPA_Usage::add_credits($user_id, $amount);
    unset($GLOBALS['aipa_token_log_ctx']);
    // gend.me's ledger of what it has given away (Network Admin can total it; newest first, last 500 kept).
    $ledger = (array) get_site_option('em_app_welcome_ledger', []);
    array_unshift($ledger, $record + ['user_id' => $user_id]);
    update_site_option('em_app_welcome_ledger', array_slice($ledger, 0, 500));
    update_site_option('em_app_welcome_total', (float) get_site_option('em_app_welcome_total', 0) + $amount);
    return $amount;
}

// After the application is saved (email already confirmed, e.g. an existing verified member) ...
add_action('chat_form_submission', function ($form_id, $answers, $submission_id) {
    $uid = (int) get_post_field('post_author', (int) $submission_id);
    if ($uid > 0) em_app_welcome_maybe_grant($uid);
}, 30, 3);
// ... or when they confirm their email (EM_Postings::release_email_holds writes gdc_email_verified_at) ...
add_action('added_user_meta', function ($mid, $uid, $key) {
    if ($key === 'gdc_email_verified_at') em_app_welcome_maybe_grant((int) $uid);
}, 30, 3);
// ... or WP Ultimo marks the customer verified.
add_action('wu_transition_customer_email_verification', function ($old, $new, $customer_id) {
    if ($new !== 'verified' || !function_exists('wu_get_customer')) return;
    $c = wu_get_customer($customer_id);
    if ($c) em_app_welcome_maybe_grant((int) $c->get_user_id());
}, 30, 3);
