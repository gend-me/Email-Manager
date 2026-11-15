<?php
/**
 * Talk Flows › Applications › Applicants (owner request 2026-11-11): one place to manage every application across all
 * postings, under the posting cards. List and pipeline views, status filters, search, sorting, bulk actions, CSV export,
 * and an applicant drawer (progress, profile checklist, answers, notes, rating, activity, email confirmation).
 *
 * Moving and approving use the postings' own endpoints (em_advance_posting_applicant, em_complete_posting_step,
 * em_bulk_advance_posting_applicants). This file adds the cross-posting list, the detail, and the actions the postings
 * don't have: reject / restore (with an optional email), notes, rating, resend or confirm the email address.
 * Every endpoint: manage_options + the em_app_support nonce.
 *
 * Also words the launch flow's email-confirmation pop-up for applicants and sends a confirmed applicant to their
 * Applications tab (filters added to vendor-app-manager's Customer_Manager).
 *
 * @package email-manager
 */
if (!defined('ABSPATH')) exit;

class EM_Postings_ATS
{
    const META_NOTES  = '_em_ats_notes';
    const META_RATING = '_em_ats_rating';

    /** Group this request is limited to (the group's Careers tab), 0 = every posting (Talk Flows). */
    private static $group = 0;

    public function __construct()
    {
        add_action('em_postings_panel_after_grid', [$this, 'render']);
        add_action('wp_ajax_em_ats_list', [$this, 'ajax_list']);
        add_action('wp_ajax_em_ats_detail', [$this, 'ajax_detail']);
        add_action('wp_ajax_em_ats_action', [$this, 'ajax_action']);
        add_filter('gdc_email_verify_notice_copy', [$this, 'notice_copy'], 10, 2);
        add_filter('gdc_email_verified_redirect', [$this, 'verified_redirect'], 10, 2);
    }

    private function guard()
    {
        check_ajax_referer('em_app_support', 'nonce');
        // 2026-11-14: from a group's Careers tab, its managers see and act on that group's postings only.
        if (!empty($_POST['em_group'])) {
            self::$group = function_exists('em_ats_group_scope') ? em_ats_group_scope() : 0;
            if (!self::$group) wp_send_json_error(['message' => __('You do not have permission to manage this group\'s applications.', 'email-manager')], 403);
            return;
        }
        if (!current_user_can('manage_options') && !current_user_can('gs_dash_talk_flows_use')) wp_send_json_error(['message' => __('You do not have permission to manage applications.', 'email-manager')], 403);
    }

    private static function in_scope($posting_id)
    {
        return !self::$group || em_posting_group_id($posting_id) === self::$group;
    }

    /* ---------------- data ---------------- */

    public static function postings_meta()
    {
        $out = [];
        foreach (EM_Postings::get_postings() as $p) {
            if (!self::in_scope($p->ID)) continue;
            $landing = EM_Postings::get_landing($p->ID);
            $steps = [];
            foreach (EM_Postings::get_process($p->ID) as $i => $s) {
                $steps[] = [
                    'title'      => $s['title'] !== '' ? $s['title'] : sprintf(__('Step %d', 'email-manager'), $i + 1),
                    'final'      => !empty($s['final']),
                    'completion' => (string) ($s['completion'] ?? ''),
                    'mode'       => (string) ($s['action_mode'] ?? ''),
                ];
            }
            $out[] = [
                'id'     => (int) $p->ID,
                'title'  => $p->post_title,
                'status' => get_post_meta($p->ID, EM_Postings::META_STATUS, true) ?: 'open',
                'accent' => $landing['accent'] ?: '#6366f1',
                'url'    => EM_Postings::landing_url($p),
                'steps'  => $steps,
                'group_safe' => function_exists('em_posting_group_safe') ? em_posting_group_safe($p->ID) : true,
            ];
        }
        return $out;
    }

    /** One applicant row from EM_Postings::get_applicants() plus what the manager shows. */
    private static function enrich(array $a, $posting_id)
    {
        $sid  = (int) $a['id'];
        $uid  = (int) $a['user_id'];
        $hist = get_post_meta($sid, EM_Applications::APPLICANT_HISTORY_META, true);
        $last = is_array($hist) && $hist ? end($hist) : null;
        $notes = get_post_meta($sid, self::META_NOTES, true);
        $rej = get_post_meta($sid, EM_Postings::META_REJECTED, true);
        $user = $uid ? get_userdata($uid) : null;
        if ($user && ($a['name'] === '' || $a['name'] === __('(unnamed)', 'email-manager'))) $a['name'] = $user->display_name;
        if ($user && $a['email'] === '') $a['email'] = $user->user_email;
        return $a + [
            'posting_id'  => (int) $posting_id,
            'rating'      => (int) get_post_meta($sid, self::META_RATING, true),
            'notes_n'     => is_array($notes) ? count($notes) : 0,
            'avatar'      => $uid ? (string) get_avatar_url($uid, ['size' => 96]) : '',
            'profile'     => ($uid && function_exists('bp_core_get_user_domain')) ? trailingslashit(bp_core_get_user_domain($uid)) : '',
            'last_ts'     => $last && !empty($last['time']) ? (int) mysql2date('U', $last['time']) : (int) ($a['ts'] ?? 0),
            'reject_note' => is_array($rej) ? (string) ($rej['reason'] ?? '') : '',
        ];
    }

    public static function all_applicants()
    {
        $rows = [];
        foreach (EM_Postings::get_postings() as $p) {
            if (!self::in_scope($p->ID)) continue;
            foreach (EM_Postings::get_applicants($p->ID) as $a) $rows[] = self::enrich($a, $p->ID);
        }
        return $rows;
    }

    private static function find($sid)
    {
        $pid = (int) get_post_meta($sid, EM_Postings::SUBMISSION_TAG, true);
        if (!$pid || !self::in_scope($pid)) return null;
        foreach (EM_Postings::get_applicants($pid) as $a) {
            if ((int) $a['id'] === (int) $sid) return self::enrich($a, $pid);
        }
        return null;
    }

    private static function answers($answers)
    {
        $out = [];
        foreach (is_array($answers) ? $answers : [] as $k => $v) {
            $q = is_string($k) ? $k : '';
            $a = $v;
            if (is_array($v)) {
                $q = (string) ($v['question'] ?? $v['label'] ?? $q);
                $a = $v['answer'] ?? $v['value'] ?? wp_json_encode($v);
            }
            $a = is_scalar($a) ? (string) $a : wp_json_encode($a);
            if ($a !== '' && $a[0] === '{') {
                $d = json_decode($a, true);
                if (is_array($d) && in_array($d['action'] ?? '', ['registered', 'logged_in'], true)) {
                    $q = $q !== '' ? $q : __('Account', 'email-manager');
                    $a = ($d['action'] === 'registered' ? __('Created a gend.me account', 'email-manager') : __('Signed in with their gend.me account', 'email-manager'))
                        . (!empty($d['username']) ? ' · @' . $d['username'] : '') . (!empty($d['email']) ? ' · ' . $d['email'] : '');
                }
            }
            $out[] = ['q' => $q !== '' ? $q : sprintf(__('Answer %d', 'email-manager'), count($out) + 1), 'a' => $a];
        }
        return $out;
    }

    /* ---------------- AJAX ---------------- */

    public function ajax_list()
    {
        $this->guard();
        wp_send_json_success(['postings' => self::postings_meta(), 'applicants' => self::all_applicants()]);
    }

    public function ajax_detail()
    {
        $this->guard();
        $sid = absint($_POST['id'] ?? 0);
        $row = $sid ? self::find($sid) : null;
        if (!$row) wp_send_json_error(['message' => __('Application not found.', 'email-manager')], 404);
        $uid  = (int) $row['user_id'];
        $hist = get_post_meta($sid, EM_Applications::APPLICANT_HISTORY_META, true);
        $events = [];
        foreach (is_array($hist) ? $hist : [] as $h) {
            $by = !empty($h['by']) ? get_userdata((int) $h['by']) : null;
            $events[] = [
                'stage'  => (string) ($h['stage'] ?? ''),
                'event'  => (string) ($h['event'] ?? ''),
                'method' => (string) ($h['method'] ?? ''),
                'ts'     => !empty($h['time']) ? (int) mysql2date('U', $h['time']) : 0,
                'by'     => $by ? $by->display_name : '',
            ];
        }
        $notes = [];
        foreach ((array) get_post_meta($sid, self::META_NOTES, true) as $n) {
            if (!is_array($n)) continue;
            $u = !empty($n['by']) ? get_userdata((int) $n['by']) : null;
            $notes[] = ['text' => (string) $n['text'], 'ts' => (int) $n['t'], 'by' => $u ? $u->display_name : ''];
        }
        $user = $uid ? get_userdata($uid) : null;
        $rej = get_post_meta($sid, EM_Postings::META_REJECTED, true);
        $rb = is_array($rej) && !empty($rej['by']) ? get_userdata((int) $rej['by']) : null;
        wp_send_json_success([
            'row'       => $row,
            'answers'   => self::answers(get_post_meta($sid, '_chat_submission_data', true)),
            'events'    => $events,
            'notes'     => $notes,
            'checks'    => ($uid && function_exists('em_member_apps_profile_checks')) ? em_member_apps_profile_checks($uid) : [],
            'member'    => $user ? ['login' => $user->user_login, 'since' => (int) strtotime($user->user_registered . ' UTC'), 'roles' => array_values((array) $user->roles)] : null,
            'rejection' => is_array($rej) ? ['ts' => (int) ($rej['at'] ?? 0), 'by' => $rb ? $rb->display_name : '', 'reason' => (string) ($rej['reason'] ?? ''), 'emailed' => !empty($rej['emailed'])] : null,
        ]);
    }

    /** POST op + ids[] (+ fields). Ops: reject, restore, note, rate, resend_email, confirm_email. */
    public function ajax_action()
    {
        $this->guard();
        $op  = sanitize_key($_POST['op'] ?? '');
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        if (!$ids || count($ids) > 500) wp_send_json_error(['message' => __('Choose at least one application.', 'email-manager')], 400);
        $me = get_current_user_id();
        $done = 0;
        $skipped = 0;
        foreach ($ids as $sid) {
            $pid = (int) get_post_meta($sid, EM_Postings::SUBMISSION_TAG, true);
            if (get_post_type($sid) !== 'chat_submission' || !$pid || !self::in_scope($pid)) { $skipped++; continue; }
            if ($op === 'confirm_email' && !current_user_can('manage_options')) { $skipped++; continue; } // admins only (group managers and Talk Flows levels skip it)
            $uid = EM_Postings::applicant_user_id($sid, get_post_meta($sid, '_chat_submission_data', true));
            switch ($op) {
                case 'reject':
                    if (EM_Postings::is_rejected($sid)) { $skipped++; break; }
                    $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
                    $emailed = false;
                    if (!empty($_POST['notify'])) $emailed = $this->send_rejection($sid, $pid, $uid);
                    update_post_meta($sid, EM_Postings::META_REJECTED, ['at' => time(), 'by' => $me, 'reason' => $reason, 'emailed' => $emailed]);
                    $this->log($sid, __('Not selected', 'email-manager'), 'rejected', $me);
                    $done++;
                    break;
                case 'restore':
                    if (!EM_Postings::is_rejected($sid)) { $skipped++; break; }
                    delete_post_meta($sid, EM_Postings::META_REJECTED);
                    $this->log($sid, __('Restored', 'email-manager'), 'restored', $me);
                    $done++;
                    break;
                case 'note':
                    $text = sanitize_textarea_field(wp_unslash($_POST['text'] ?? ''));
                    if ($text === '') { $skipped++; break; }
                    $notes = get_post_meta($sid, self::META_NOTES, true);
                    $notes = is_array($notes) ? $notes : [];
                    $notes[] = ['t' => time(), 'by' => $me, 'text' => mb_substr($text, 0, 4000)];
                    update_post_meta($sid, self::META_NOTES, array_slice($notes, -200));
                    $done++;
                    break;
                case 'rate':
                    update_post_meta($sid, self::META_RATING, max(0, min(5, (int) ($_POST['rating'] ?? 0))));
                    $done++;
                    break;
                case 'resend_email':
                    if ($uid && EM_Postings::email_status($uid) !== 'verified' && EM_Postings::request_email_confirmation($uid, true)) $done++; else $skipped++;
                    break;
                case 'confirm_email':
                    if ($uid && !empty($GLOBALS['em_postings'])) { $GLOBALS['em_postings']->admin_confirm_email($uid); $this->log($sid, __('Email confirmed by an admin', 'email-manager'), 'email_confirmed', $me); $done++; } else $skipped++;
                    break;
                default:
                    wp_send_json_error(['message' => __('Unknown action.', 'email-manager')], 400);
            }
        }
        wp_send_json_success(['done' => $done, 'skipped' => $skipped, 'applicants' => self::all_applicants()]);
    }

    private function log($sid, $label, $event, $by)
    {
        $h = get_post_meta($sid, EM_Applications::APPLICANT_HISTORY_META, true);
        $h = is_array($h) ? $h : [];
        $h[] = ['stage' => $label, 'event' => $event, 'method' => 'admin', 'time' => current_time('mysql'), 'by' => (int) $by];
        update_post_meta($sid, EM_Applications::APPLICANT_HISTORY_META, $h);
    }

    private function send_rejection($sid, $pid, $uid)
    {
        $user = $uid ? get_userdata($uid) : null;
        $email = $user ? $user->user_email : EM_Applications::extract_email_from_submission(get_post_meta($sid, '_chat_submission_data', true));
        if (!$email || !is_email($email)) return false;
        $name = $user ? ($user->first_name ?: $user->display_name) : __('there', 'email-manager');
        $tokens = ['{applicant_name}' => $name, '{posting_title}' => get_the_title($pid), '{site_name}' => get_bloginfo('name')];
        $subject = strtr(sanitize_text_field(wp_unslash($_POST['subject'] ?? '')), $tokens);
        $body = strtr(wp_kses_post(wp_unslash($_POST['body'] ?? '')), $tokens);
        if ($subject === '' || trim(wp_strip_all_tags($body)) === '') return false;
        $html = wpautop($body);
        if (function_exists('gend_email_wrap') && function_exists('gend_email_brand_on') && gend_email_brand_on()) $html = gend_email_wrap($html);
        return (bool) wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /* ---------------- the launch flow's email-confirmation pop-up, for applicants ---------------- */

    public function notice_copy($copy, $customer)
    {
        $uid = is_object($customer) && method_exists($customer, 'get_user_id') ? (int) $customer->get_user_id() : 0;
        if (!$uid || !EM_Postings::email_held_submissions($uid)) return $copy;
        return [
            'title' => __('Confirm your email to send your application', 'email-manager'),
            'intro' => __('Your application is saved and waiting for this one step. We sent a confirmation link to:', 'email-manager'),
            'items' => [
                __('Check your inbox and spam folder for the email from gend.me.', 'email-manager'),
                __('Click the link: your application moves on straight away, and you land on your Applications tab.', 'email-manager'),
            ],
        ];
    }

    /**
     * After the email confirmation an applicant returns to the thank-you page of the role they applied for (its next
     * steps), not the home page: the newest application from the last 7 days decides which one. (2026-11-12)
     */
    public function verified_redirect($url, $customer)
    {
        $uid = is_object($customer) && method_exists($customer, 'get_user_id') ? (int) $customer->get_user_id() : 0;
        $apps = $uid ? EM_Postings::member_applications($uid) : [];
        if (!$apps) return $url;
        $app = $apps[0];   // newest first
        if (time() - (int) $app['ts'] > 7 * DAY_IN_SECONDS && method_exists($customer, 'get_memberships') && $customer->get_memberships()) return $url;
        $page = (int) get_post_meta((int) $app['posting_id'], EM_Postings::META_THANKYOU_PAGE, true);
        $dest = ($page && get_post_status($page) === 'publish') ? get_permalink($page) : '';
        if (!$dest) {
            $dest = function_exists('bp_members_get_user_url') ? bp_members_get_user_url($uid) : (function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($uid) : '');
            if (!$dest) return $url;
            return add_query_arg('gdc_email_verified', 1, trailingslashit($dest)) . '#applications';
        }
        return add_query_arg('email_confirmed', 1, $dest);
    }

    /* ---------------- render ---------------- */

    /** The Applicants manager on a group's Careers tab, limited to that group (2026-11-14). */
    public static function render_group($gid)
    {
        $gid = (int) $gid;
        $ver = defined('EMAIL_MANAGER_VERSION') ? EMAIL_MANAGER_VERSION : '1';
        wp_enqueue_style('dashicons');
        wp_enqueue_style('em-ats', EMAIL_MANAGER_URL . 'assets/em-ats.css', [], $ver . '-a1');
        wp_enqueue_script('em-ats', EMAIL_MANAGER_URL . 'assets/em-ats.js', [], $ver . '-g1', true);
        $cfg = [
            'ajax'   => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('em_app_support'),
            'group'  => $gid,
            'gnonce' => wp_create_nonce('em_ats_group_' . $gid),
            'site'   => get_bloginfo('name'),
            'reject' => [
                'subject' => __('Your application for {posting_title}', 'email-manager'),
                'body'    => __("Hi {applicant_name},\n\nThank you for applying for {posting_title} and for the time you put into your profile. After reviewing it, we have decided not to move forward with your application right now.\n\nYour gend.me account stays active, and you are welcome to apply again as your experience grows.\n\nThe {site_name} team", 'email-manager'),
            ],
        ];
        ob_start();
        ?>
        <section class="em-ats em-ats--group" id="em-ats" data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
            <div class="em-ats-head">
                <div>
                    <h3><?php esc_html_e('Applicants', 'email-manager'); ?></h3>
                    <p class="description"><?php esc_html_e('Every application to this group\'s roles. Review profiles, move people through each step, approve, keep notes and rate them.', 'email-manager'); ?></p>
                </div>
                <div class="em-ats-head__tools">
                    <div class="em-ats-seg" role="group" aria-label="<?php esc_attr_e('View', 'email-manager'); ?>">
                        <button type="button" class="is-on" data-view="list"><span class="dashicons dashicons-list-view"></span><?php esc_html_e('List', 'email-manager'); ?></button>
                        <button type="button" data-view="board"><span class="dashicons dashicons-columns"></span><?php esc_html_e('Pipeline', 'email-manager'); ?></button>
                    </div>
                    <button type="button" class="em-ats-btn is-ghost" data-act="export"><span class="dashicons dashicons-download"></span><?php esc_html_e('Export CSV', 'email-manager'); ?></button>
                    <button type="button" class="em-ats-btn is-ghost" data-act="refresh" aria-label="<?php esc_attr_e('Refresh', 'email-manager'); ?>"><span class="dashicons dashicons-update"></span></button>
                </div>
            </div>
            <div class="em-ats-body" data-ats-body><div class="em-ats-loading"><span></span><?php esc_html_e('Loading applicants…', 'email-manager'); ?></div></div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function render()
    {
        $ver = defined('EMAIL_MANAGER_VERSION') ? EMAIL_MANAGER_VERSION : '1';
        wp_enqueue_style('em-ats', EMAIL_MANAGER_URL . 'assets/em-ats.css', [], $ver . '-a1');
        wp_enqueue_script('em-ats', EMAIL_MANAGER_URL . 'assets/em-ats.js', [], $ver . '-a2', true);
        $cfg = [
            'ajax'   => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('em_app_support'),
            'site'   => get_bloginfo('name'),
            'reject' => [
                'subject' => __('Your application for {posting_title}', 'email-manager'),
                'body'    => __("Hi {applicant_name},\n\nThank you for applying for {posting_title} and for the time you put into your profile. After reviewing it, we have decided not to move forward with your application right now.\n\nYour gend.me account stays active: your referral link keeps working, and you are welcome to apply again as your experience grows.\n\nThe {site_name} team", 'email-manager'),
            ],
        ];
        ?>
        <section class="gdc-email-panel em-ats" id="em-ats" data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
            <div class="em-ats-head">
                <div>
                    <h3><?php esc_html_e('Applicants', 'email-manager'); ?></h3>
                    <p class="description"><?php esc_html_e('Every application across your postings. Filter, review, move people through each step, and keep notes in one place.', 'email-manager'); ?></p>
                </div>
                <div class="em-ats-head__tools">
                    <div class="em-ats-seg" role="group" aria-label="<?php esc_attr_e('View', 'email-manager'); ?>">
                        <button type="button" class="is-on" data-view="list"><span class="dashicons dashicons-list-view"></span><?php esc_html_e('List', 'email-manager'); ?></button>
                        <button type="button" data-view="board"><span class="dashicons dashicons-columns"></span><?php esc_html_e('Pipeline', 'email-manager'); ?></button>
                    </div>
                    <button type="button" class="em-ats-btn is-ghost" data-act="export"><span class="dashicons dashicons-download"></span><?php esc_html_e('Export CSV', 'email-manager'); ?></button>
                    <button type="button" class="em-ats-btn is-ghost" data-act="refresh" aria-label="<?php esc_attr_e('Refresh', 'email-manager'); ?>"><span class="dashicons dashicons-update"></span></button>
                </div>
            </div>
            <div class="em-ats-body" data-ats-body><div class="em-ats-loading"><span></span><?php esc_html_e('Loading applicants…', 'email-manager'); ?></div></div>
        </section>
        <?php
    }
}

new EM_Postings_ATS();

// Postings belong to groups; the group Careers tab (owner 2026-11-14).
require_once __DIR__ . '/postings-groups.php';
