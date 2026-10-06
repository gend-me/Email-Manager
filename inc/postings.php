<?php
/**
 * Postings module — application "postings": each posting bundles a public
 * landing page (a real WordPress page you edit in the WP editor), its own linked
 * form (searchable / created inline), a thank-you experience (page, redirect, or
 * message), transactional emails, and per-posting analytics (views, applications,
 * conversion, daily series). Replaces the old flat "Forms" sub-tab inside the
 * Applications tab.
 *
 * A posting is an `em_posting` CPT. Its landing + (optional) thank-you pages are
 * real `page` posts owned by the posting (meta _em_posting_owner), edited inline
 * via an embedded WP editor. The landing is served at /apply/{slug} (with
 * ?em_posting={slug} as a no-rewrite fallback) inside a lightweight animated
 * shell that renders the landing page's content (where the form shortcode is
 * placed). Thank-you / redirect are mirrored onto the form meta so the existing
 * chat-frontend JS handles them; emails + attribution run here on the shared
 * `chat_form_submission` action.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Postings
{
    const CPT              = 'em_posting';
    const META_FORM_ID     = '_em_posting_form_id';
    const META_STATUS      = '_em_posting_status';
    const META_LANDING     = '_em_posting_landing';
    const META_THANKYOU    = '_em_posting_thankyou';
    const META_PROCESS     = '_em_posting_process';
    const META_CONTRACT    = '_em_posting_contract';
    const META_VIEWS       = '_em_posting_views';
    const META_SUBMISSIONS = '_em_posting_submissions';
    const META_DAILY       = '_em_posting_daily';
    const META_LANDING_PAGE  = '_em_posting_landing_page_id';
    const META_THANKYOU_PAGE = '_em_posting_thankyou_page_id';
    const PAGE_OWNER_META    = '_em_posting_owner';
    const PAGE_ROLE_META     = '_em_posting_page_role';
    const SUBMISSION_TAG    = '_em_posting_id';
    const META_BOARDS       = '_em_posting_boards';       // job board postings attached to this posting
    const META_BOARD_STATS  = '_em_posting_board_stats';  // board entry id => ['v' views, 's' applications, 'last' ts]
    const SUBMISSION_SRC    = '_em_posting_src';          // submission meta: board entry id it came from
    const SRC_PARAM         = 'em_src';                   // tracked-link query arg identifying a board entry
    const MAX_BOARDS        = 30;                          // per kind (job boards / affiliate sites)
    const KIND_JOB          = 'job';                       // a posting on a job board
    const KIND_AFFILIATE    = 'affiliate';                 // a listing of this application's affiliate program (its commission contracts) on an affiliate site
    const META_ENROLL_ROLE  = '_em_posting_enroll_role';   // a role that, once earned, enrolls the member in this posting's contract
    const USER_ENROLL_META  = '_em_contract_enrollments';  // user meta: posting id => ['at' => ts, 'source' => 'approval' | 'role:<slug>']
    const META_STEP_INDEX   = '_em_posting_step_index';
    const META_STEP_STATE   = '_em_posting_step_state';   // 'pending' | 'completed'
    const META_STEP_SINCE   = '_em_posting_step_since';   // unix ts: only actions after this count
    const META_ACTION_LOG   = '_em_action_log';            // user meta: action key => last unix ts
    const META_WATCH        = '_em_pending_action_subs';   // user meta: applications waiting on this member
    const TRACK_OPTION      = 'em_postings_tracking';      // '1' while any applicant awaits tracked actions
    const CRON_HOOK         = 'em_postings_check_actions';
    const MAX_APPROVER_EMAILS = 50;
    const REWRITE_VERSION   = '_em_postings_rw_v';
    const REWRITE_VERSION_N = '2';

    public function __construct()
    {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'maybe_flush_rewrite'], 99);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'maybe_render_landing']);

        // Owned landing/thank-you pages get the full block editor (same as
        // any normal page-edit screen — operator directive) and surface the
        // form shortcode in their sidebar. A use_block_editor_for_post
        // override used to force the classic editor here "so they iframe
        // cleanly" — removed; the iframe (render_page_editor_area()) already
        // points straight at the real post.php?action=edit screen, so it
        // gets Gutenberg exactly like the native editor would.
        add_action('add_meta_boxes', [$this, 'register_shortcode_metabox'], 10, 2);

        // Earning a role (by any means) can enrol the member in a posting's contract.
        add_action('add_user_role', [$this, 'on_role_earned'], 10, 2);
        add_action('set_user_role', [$this, 'on_role_earned'], 10, 2);

        // Attribution + emails fire after any chat-form submission.
        add_action('chat_form_submission', [$this, 'on_form_submission'], 10, 3);

        // Admin AJAX.
        add_action('wp_ajax_em_save_posting',          [$this, 'ajax_save_posting']);
        add_action('wp_ajax_em_get_posting',           [$this, 'ajax_get_posting']);
        add_action('wp_ajax_em_delete_posting',        [$this, 'ajax_delete_posting']);
        add_action('wp_ajax_em_create_posting_form',   [$this, 'ajax_create_posting_form']);
        add_action('wp_ajax_em_search_forms',          [$this, 'ajax_search_forms']);
        add_action('wp_ajax_em_get_form_fields',       [$this, 'ajax_get_form_fields']);
        add_action('wp_ajax_em_create_list',           [$this, 'ajax_create_list']);
        add_action('wp_ajax_em_create_role',           [$this, 'ajax_create_role']);
        add_action('wp_ajax_em_update_role_access',    [$this, 'ajax_update_role_access']);
        add_action('wp_ajax_em_get_posting_analytics', [$this, 'ajax_get_analytics']);
        add_action('wp_ajax_em_advance_posting_applicant', [$this, 'ajax_advance_applicant']);
        add_action('wp_ajax_em_bulk_advance_posting_applicants', [$this, 'ajax_bulk_advance_applicants']);
        add_action('wp_ajax_em_complete_posting_step',     [$this, 'ajax_complete_step']);
        add_action('wp_ajax_em_check_posting_actions',     [$this, 'ajax_check_actions']);
        add_action('wp_ajax_em_search_members',            [$this, 'ajax_search_members']);

        // One-click approval links emailed to a step's designated approvers
        // (login required; the handler re-checks they're an eligible approver).
        add_action('admin_post_em_posting_approve',        [$this, 'handle_approval_link']);
        add_action('admin_post_nopriv_em_posting_approve', [$this, 'handle_approval_link']);

        // "User action" steps (e.g. add a cover photo) are re-checked hourly
        // so applicants advance on their own once the action is detected.
        add_action('init', [$this, 'schedule_action_checks']);
        add_action(self::CRON_HOOK, [$this, 'cron_check_actions']);

        // "Action update" steps: record what members do as it happens (hooks
        // verified against the bundled BuddyPress). Each handler is a cheap
        // no-op unless some applicant is currently awaiting tracked actions.
        add_action('bp_members_avatar_uploaded',   [$this, 'hook_avatar'], 10, 1);
        add_action('members_cover_image_uploaded', [$this, 'hook_cover'], 10, 4);
        add_action('xprofile_updated_profile',     [$this, 'hook_profile'], 10, 1);
        add_action('bp_core_general_settings_after_save',      [$this, 'hook_settings'], 10, 0);
        add_action('bp_core_notification_settings_after_save', [$this, 'hook_settings'], 10, 0);
        add_action('personal_options_update',      [$this, 'hook_settings'], 10, 0);
        add_action('bp_activity_posted_update',    [$this, 'hook_activity'], 10, 3);
        add_action('groups_created_group',         [$this, 'hook_group'], 10, 2);
        add_action('transition_post_status',       [$this, 'hook_content'], 10, 3);
        // The member's cover profile page (a `gdc_profile_page` post they build
        // in the block editor under Cover on their profile).
        add_action('save_post_gdc_profile_page',   [$this, 'hook_cover_page'], 20, 2);

        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /* ================================================================
       CPT + rewrite
       ================================================================ */

    public function register_cpt()
    {
        register_post_type(self::CPT, [
            'label'               => __('Postings', 'email-manager'),
            'labels'              => [
                'name'          => __('Postings', 'email-manager'),
                'singular_name' => __('Posting', 'email-manager'),
            ],
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'supports'            => ['title'],
            'rewrite'             => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
        ]);

        add_rewrite_rule('^apply/([^/]+)/?$', 'index.php?em_posting=$matches[1]', 'top');
    }

    public function register_query_var($vars)
    {
        $vars[] = 'em_posting';
        return $vars;
    }

    public function maybe_flush_rewrite()
    {
        if (get_option(self::REWRITE_VERSION) !== self::REWRITE_VERSION_N) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_VERSION, self::REWRITE_VERSION_N);
        }
    }

    /* ================================================================
       Owned pages — editor + shortcode sidebar
       ================================================================ */

    public function register_shortcode_metabox($post_type, $post)
    {
        if (!$post || !get_post_meta($post->ID, self::PAGE_OWNER_META, true)) return;
        add_meta_box(
            'em_posting_shortcode_box',
            __('Application Form', 'email-manager'),
            [$this, 'render_shortcode_metabox'],
            null,
            'side',
            'high'
        );
    }

    public function render_shortcode_metabox($post)
    {
        $owner = (int) get_post_meta($post->ID, self::PAGE_OWNER_META, true);
        $shortcode = $owner ? self::form_shortcode($owner) : '';
        echo '<p style="margin-top:0;color:#646970;">' . esc_html__('Paste this where the application form should appear:', 'email-manager') . '</p>';
        if ($shortcode) {
            echo '<input type="text" readonly value="' . esc_attr($shortcode) . '" style="width:100%;" onclick="this.select();" />';
        } else {
            echo '<em>' . esc_html__('Link a form to this posting first.', 'email-manager') . '</em>';
        }
    }

    /** Ensure an owned landing/thank-you page exists; returns its ID. */
    private function ensure_owned_page($posting_id, $role, $title)
    {
        $meta_key = $role === 'landing' ? self::META_LANDING_PAGE : self::META_THANKYOU_PAGE;
        $pid = (int) get_post_meta($posting_id, $meta_key, true);
        if ($pid && ($p = get_post($pid)) && $p->post_status !== 'trash') {
            return $pid;
        }

        $form_id   = (int) get_post_meta($posting_id, self::META_FORM_ID, true);
        $shortcode = $form_id ? self::form_shortcode($posting_id) : '';

        if ($role === 'landing') {
            $status  = 'draft';
            $content = "<!-- wp:paragraph -->\n<p>" . esc_html__('Describe this opportunity, then drop in the application form below.', 'email-manager') . "</p>\n<!-- /wp:paragraph -->\n";
            if ($shortcode) $content .= "\n" . $shortcode . "\n";
        } else {
            $status  = 'publish';
            $content = "<h2>" . esc_html__('Thank you!', 'email-manager') . "</h2>\n<p>" . esc_html__("Your application has been received — we'll be in touch shortly.", 'email-manager') . "</p>\n";
        }

        $postarr = [
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_content' => $content,
        ];
        // Landing pages are rendered through render_landing()'s own standalone
        // shell (never through the theme's page template), and when viewed by
        // their own permalink or in the editor's "Frontend" live-view tab they
        // should match that full-width, chrome-free presentation rather than
        // the site's normal header/nav/footer template — the "blank" block
        // template (already used by other system pages like the OAuth Bridge)
        // gives exactly that.
        if ($role === 'landing') {
            $postarr['page_template'] = 'blank';
        }

        $pid = wp_insert_post($postarr);
        if (is_wp_error($pid) || !$pid) return 0;

        update_post_meta($pid, self::PAGE_OWNER_META, $posting_id);
        update_post_meta($pid, self::PAGE_ROLE_META, $role);
        update_post_meta($posting_id, $meta_key, $pid);
        return $pid;
    }

    /* ================================================================
       Defaults / accessors
       ================================================================ */

    public static function default_landing()
    {
        return [
            'accent'  => '#6366f1',
            'accent2' => '#8b5cf6',
        ];
    }

    public static function default_thankyou()
    {
        return [
            'mode'         => 'message',
            'message'      => __("Thank you — your application has been received. We'll be in touch shortly.", 'email-manager'),
            'redirect_url' => '',
            'page_id'      => 0,
        ];
    }

    /** Just the two landing colours a posting currently has (the defaults for a new posting). */
    private static function landing_colours($posting_id)
    {
        $cur = $posting_id ? self::get_landing($posting_id) : self::default_landing();
        return ['accent' => $cur['accent'], 'accent2' => $cur['accent2']];
    }

    public static function get_landing($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_LANDING, true), self::default_landing());
    }

    public static function get_thankyou($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_THANKYOU, true), self::default_thankyou());
    }

    /** How a step (after step 1) gets completed so the applicant moves on:
     *  a designated approver signs off ('approval'), or the applicant does
     *  something themselves ('action') — optionally auto-detected. */
    public static function completion_defaults()
    {
        return [
            'completion'    => 'approval',
            'approver_type' => 'role',
            'approver_role' => 'administrator',
            'approver_user' => 0,
            'action_label'  => '',
            'action_url'    => '',
            'action_mode'   => 'manual',
            'action_updates' => [],
            'approver_user_name' => '',
        ];
    }

    /** How a user-action step is confirmed. */
    public static function action_mode_choices()
    {
        return [
            'manual' => __('Team confirms manually', 'email-manager'),
            'update' => __('Action update', 'email-manager'),
        ];
    }

    /** Member actions that can be tracked automatically for an "Action
     *  update" step. The step is achieved once the member has done every
     *  one that's ticked — counting only what they do after reaching it. */
    public static function action_update_choices()
    {
        return [
            'avatar'          => __('Upload a profile picture', 'email-manager'),
            'cover_image'     => __('Upload a profile banner (cover image)', 'email-manager'),
            'cover_page'      => __('Build their cover profile page (draft or published)', 'email-manager'),
            'cover_page_published' => __('Build and publish their cover profile page', 'email-manager'),
            'profile_update'  => __('Update their profile information', 'email-manager'),
            'settings_update' => __('Update their account settings', 'email-manager'),
            'activity_post'   => __('Post an activity update', 'email-manager'),
            'group_created'   => __('Create a group', 'email-manager'),
            'content_created' => __('Publish content (a post, page, etc.)', 'email-manager'),
        ];
    }

    /** Does this step track member actions automatically? */
    public static function step_tracks_actions(array $step)
    {
        return ($step['completion'] ?? '') === 'action'
            && ($step['action_mode'] ?? 'manual') === 'update'
            && !empty($step['action_updates']);
    }

    /** Sanitizes one step's completion settings (from stored meta or a
     *  save request). Unknown values fall back to safe defaults. Steps saved
     *  by the earlier single "auto-check" version are migrated. */
    public static function sanitize_completion(array $raw)
    {
        $updates_in = isset($raw['action_updates']) && is_array($raw['action_updates']) ? $raw['action_updates'] : [];
        $raw = array_map(function ($v) { return is_scalar($v) ? (string) $v : ''; }, $raw);
        $d = self::completion_defaults();
        $role = sanitize_key((string) ($raw['approver_role'] ?? ''));

        $mode = in_array($raw['action_mode'] ?? '', ['manual', 'update'], true) ? $raw['action_mode'] : $d['action_mode'];
        $updates = [];
        foreach ($updates_in as $u) {
            $u = is_scalar($u) ? sanitize_key((string) $u) : '';
            if ($u !== '' && array_key_exists($u, self::action_update_choices())) $updates[$u] = $u;
        }
        // Migrate the earlier single auto-check ('avatar' / 'cover_image').
        if (!$updates && in_array($raw['action_check'] ?? '', ['avatar', 'cover_image'], true)) {
            $updates[$raw['action_check']] = $raw['action_check'];
            $mode = 'update';
        }

        return [
            'completion'    => in_array($raw['completion'] ?? '', ['approval', 'action'], true) ? $raw['completion'] : $d['completion'],
            'approver_type' => in_array($raw['approver_type'] ?? '', ['role', 'member'], true) ? $raw['approver_type'] : $d['approver_type'],
            'approver_role' => $role !== '' ? $role : $d['approver_role'],
            'approver_user' => absint($raw['approver_user'] ?? 0),
            'action_label'  => sanitize_text_field((string) ($raw['action_label'] ?? '')),
            'action_url'    => esc_url_raw((string) ($raw['action_url'] ?? '')),
            'action_mode'   => $mode,
            'action_updates' => array_values($updates),
        ];
    }

    /** Each step doubles as a status stage: it can grant (or create) a WP
     *  role, subscribe the applicant to a mailing list, and send any number
     *  of rich-HTML emails (to the applicant, extra team addresses, or
     *  both) — replacing the old fixed "Applicant Email" / "Team Email"
     *  tabs with per-step configuration. Step 1 runs when the form is
     *  submitted; every later step is completed by approval or user action
     *  (see completion_defaults()). */
    public static function default_process()
    {
        $c = self::completion_defaults();
        return [
            array_merge(['title' => __('Apply', 'email-manager'),    'desc' => __('Submit your application through the form below.', 'email-manager'),
             'role' => '', 'auto_create' => 0, 'list_id' => 0, 'emails' => [
                ['to_applicant' => 1, 'to_emails' => '', 'subject' => __('We received your application', 'email-manager'),
                 'body' => wpautop(__("Hi {applicant_name},\n\nThanks for applying to {posting_title}. We've received your application and our team will review it shortly.\n\n— {site_name}", 'email-manager'))],
             ]], $c),
            array_merge(['title' => __('Review', 'email-manager'),   'desc' => __('Our team reviews every application carefully.', 'email-manager'),
             'role' => '', 'auto_create' => 0, 'list_id' => 0, 'emails' => []], $c),
            self::default_final_step(),
        ];
    }

    /**
     * The last step of every process: what happens when an applicant is
     * approved. Reaching it IS the approval — it never waits on anyone — so
     * its role grant / mailing list / emails run and the process completes.
     */
    public static function default_final_step()
    {
        return array_merge([
            'title' => __('Approved', 'email-manager'),
            'desc'  => __("You're in! We'll be in touch with your next steps.", 'email-manager'),
            'role' => '', 'auto_create' => 0, 'list_id' => 0, 'emails' => [],
        ], self::completion_defaults(), ['final' => 1]);
    }

    /**
     * Normalise a process so it always ends with exactly one "approved" step
     * and starts with a submission step. A process with no steps at all stays
     * empty — no steps means the feature is off, and adding an approval step
     * there would silently approve every applicant.
     */
    public static function finalize_process(array $steps, $strip_role = false)
    {
        $steps = array_values(array_filter($steps, 'is_array'));
        if (!$steps) return [];

        $last = -1;
        foreach ($steps as $i => $s) {
            if (!empty($s['final'])) $last = $i;   // if several are flagged, the last one is the real one
        }
        $rest  = [];
        $final = null;
        foreach ($steps as $i => $s) {
            if ($i === $last) { $final = $s; continue; }
            $s['final'] = 0;
            $rest[] = $s;
        }
        if ($final === null) $final = self::default_final_step();
        // The approved step has no "completed by" — drop anything left over.
        $final = array_merge($final, self::completion_defaults(), ['final' => 1]);
        // The role members get on approval is set on the Contract tab, so it is
        // cleared here when saving (reads keep it, to carry older postings over).
        if ($strip_role) $final['role'] = '';

        if (!$rest) {
            $apply = self::default_process()[0];
            $apply['emails'] = [];
            array_unshift($rest, $apply);
        }
        $rest[] = $final;
        return $rest;
    }

    public static function get_process($posting_id)
    {
        $stored = get_post_meta($posting_id, self::META_PROCESS, true);
        if (!is_array($stored)) return self::default_process();
        $clean = [];
        foreach ($stored as $s) {
            if (!is_array($s)) continue;
            $title = isset($s['title']) ? $s['title'] : '';
            $desc  = isset($s['desc']) ? $s['desc'] : '';
            if ($title === '' && $desc === '') continue;

            $emails = [];
            if (!empty($s['emails']) && is_array($s['emails'])) {
                foreach ($s['emails'] as $e) {
                    if (!is_array($e)) continue;
                    $emails[] = [
                        'to_applicant' => !empty($e['to_applicant']) ? 1 : 0,
                        'to_emails'    => isset($e['to_emails']) ? $e['to_emails'] : '',
                        'subject'      => isset($e['subject']) ? $e['subject'] : '',
                        'body'         => isset($e['body']) ? $e['body'] : '',
                    ];
                }
            }

            $completion = self::sanitize_completion($s);
            $approver   = $completion['approver_user'] ? get_userdata($completion['approver_user']) : false;

            $clean[] = array_merge([
                'title'       => $title,
                'desc'        => $desc,
                'role'        => isset($s['role']) ? sanitize_key($s['role']) : '',
                'auto_create' => !empty($s['auto_create']) ? 1 : 0,
                'list_id'     => isset($s['list_id']) ? (int) $s['list_id'] : 0,
                'emails'      => $emails,
                'final'       => !empty($s['final']) ? 1 : 0,
            ], $completion, [
                'approver_user_name' => $approver ? $approver->display_name : '',
            ]);
        }
        return self::finalize_process($clean);
    }

    /** Roles an Apply Process step can grant, for the step's role picker. */
    /** Roles with what they can do and see: each entry is {slug, name, caps, dashboard, locked}. */
    public static function get_role_choices()
    {
        $roles = function_exists('get_editable_roles') ? get_editable_roles() : (wp_roles() ? wp_roles()->roles : []);
        $out = [];
        foreach ((array) $roles as $key => $data) {
            $name = is_array($data) && isset($data['name']) ? translate_user_role($data['name']) : ucfirst($key);
            $out[] = self::role_entry($key, $name);
        }
        return $out;
    }

    /** One role as the editor needs it: its name, the listed capabilities it has, its dashboard sections, and whether it is locked. */
    public static function role_entry($slug, $name = null)
    {
        if ($name === null) {
            $data = wp_roles()->roles[$slug] ?? [];
            $name = isset($data['name']) ? translate_user_role($data['name']) : ucfirst($slug);
        }
        return [
            'slug'      => $slug,
            'name'      => $name,
            'caps'      => self::role_capabilities($slug),
            'dashboard' => self::role_dashboard_access($slug),
            'locked'    => self::role_is_locked($slug),
        ];
    }

    /** The capabilities from capability_choices() that this role currently has. */
    public static function role_capabilities($slug)
    {
        $role = get_role($slug);
        $have = $role ? (array) $role->capabilities : [];
        $out  = [];
        foreach (array_keys(self::capability_choices()) as $cap) {
            if (!empty($have[$cap])) $out[] = $cap;
        }
        return $out;
    }

    /** Administrator-level roles are never edited from here — a slip could lock everyone out. */
    public static function role_is_locked($slug)
    {
        if ($slug === 'administrator') return true;
        $role = get_role($slug);
        $caps = $role ? (array) $role->capabilities : [];
        return !empty($caps['manage_options']) || !empty($caps['manage_network']);
    }

    const ROLE_ACCESS_OPTION = 'em_role_dashboard_access';   // role slug => dashboard section slugs

    public static function role_dashboard_access($slug)
    {
        $all = get_option(self::ROLE_ACCESS_OPTION, []);
        return is_array($all) ? array_values(array_filter((array) ($all[$slug] ?? []), 'is_string')) : [];
    }

    private static function set_role_dashboard_access($slug, array $sections)
    {
        $all = get_option(self::ROLE_ACCESS_OPTION, []);
        $all = is_array($all) ? $all : [];
        if ($sections) $all[$slug] = $sections; else unset($all[$slug]);
        update_option(self::ROLE_ACCESS_OPTION, $all);
    }

    /**
     * The "feature access" a role can have, grouped for the role popups.
     * Only these capabilities are ever changed from the editor — nothing
     * that manages the site, plugins or other users.
     */
    public static function capability_groups()
    {
        return [
            __('Access', 'email-manager') => [
                'read' => __('Log in & access the dashboard', 'email-manager'),
            ],
            __('Posts', 'email-manager') => [
                'edit_posts'           => __('Write & edit their own posts', 'email-manager'),
                'publish_posts'        => __('Publish posts', 'email-manager'),
                'edit_published_posts' => __('Edit their published posts', 'email-manager'),
                'delete_posts'         => __('Delete their own posts', 'email-manager'),
                'edit_others_posts'    => __("Edit other members' posts", 'email-manager'),
                'read_private_posts'   => __('Read private posts', 'email-manager'),
            ],
            __('Pages', 'email-manager') => [
                'edit_pages'           => __('Write & edit pages', 'email-manager'),
                'publish_pages'        => __('Publish pages', 'email-manager'),
                'edit_published_pages' => __('Edit their published pages', 'email-manager'),
                'delete_pages'         => __('Delete their own pages', 'email-manager'),
            ],
            __('Media & community', 'email-manager') => [
                'upload_files'      => __('Upload media files', 'email-manager'),
                'moderate_comments' => __('Moderate comments', 'email-manager'),
                'manage_categories' => __('Manage categories & tags', 'email-manager'),
            ],
        ];
    }

    /** Flat capability => label map of everything in capability_groups(). */
    public static function capability_choices()
    {
        $flat = [];
        foreach (self::capability_groups() as $caps) $flat += $caps;
        return $flat;
    }

    /**
     * Save a role's access: set or clear each listed capability (others are
     * untouched, and 'read' always stays) and store its dashboard sections.
     * Returns the updated role entry, or ['error' => message, 'status' => code].
     * $dashboard_raw null keeps the stored sections.
     */
    public static function apply_role_access($slug, array $requested_caps, $dashboard_raw)
    {
        $slug = sanitize_key((string) $slug);
        $role = $slug !== '' ? get_role($slug) : null;
        if (!$role) return ['error' => 'Role not found', 'status' => 404];
        if (self::role_is_locked($slug)) return ['error' => "This role's access can't be edited here.", 'status' => 403];

        $wanted = [];
        foreach ($requested_caps as $cap) {
            if (is_scalar($cap)) $wanted[sanitize_key((string) $cap)] = true;
        }
        foreach (array_keys(self::capability_choices()) as $cap) {
            if ($cap === 'read' || !empty($wanted[$cap])) $role->add_cap($cap); else $role->remove_cap($cap);
        }

        $sections = self::sanitize_dashboard_access($dashboard_raw, self::role_dashboard_access($slug));
        self::set_role_dashboard_access($slug, $sections);
        // Adds the sections to members who already hold the role (never removes anything from them).
        if ($sections) {
            foreach ((array) get_users(['role' => $slug, 'fields' => 'ID', 'number' => 1000]) as $u) {
                self::merge_feature_access(is_object($u) ? (int) $u->ID : (int) $u, $sections);
            }
        }
        return self::role_entry($slug);
    }

    /** Add dashboard sections to a member's Feature Access — only ever adds. */
    private static function merge_feature_access($user_id, array $slugs)
    {
        if (!$slugs || !$user_id) return;
        $current = get_user_meta($user_id, self::FEATURE_ACCESS_META, true);
        $current = is_array($current) ? $current : [];
        $merged  = array_values(array_unique(array_merge($current, $slugs)));
        if ($merged !== $current) update_user_meta($user_id, self::FEATURE_ACCESS_META, $merged);
    }

    /** Decodes + sanitizes one Apply Process step's email list, sent from
     *  the JS email editor as a JSON string of {to_applicant, to_emails,
     *  subject, body} entries. Body allows full post-level HTML (tables,
     *  styles, etc.) via wp_kses_post — the same trust level as normal
     *  post content — rather than raw unsanitized markup. */
    private static function sanitize_step_emails($json)
    {
        $decoded = json_decode(wp_unslash((string) $json), true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $e) {
            if (!is_array($e)) continue;
            $out[] = [
                'to_applicant' => !empty($e['to_applicant']) ? 1 : 0,
                'to_emails'    => sanitize_text_field((string) ($e['to_emails'] ?? '')),
                'subject'      => sanitize_text_field((string) ($e['subject'] ?? '')),
                'body'         => wp_kses_post((string) ($e['body'] ?? '')),
            ];
        }
        return $out;
    }

    public static function default_contract()
    {
        return [
            'enabled'        => 0,
            'title'          => __('Applicant Agreement', 'email-manager'),
            'body'           => '',
            'require_accept' => 1,
            'commissions'    => [],
            'service'        => '',
            'dashboard_access' => [],
            'role'           => '',
        ];
    }

    public static function get_contract($posting_id)
    {
        $stored = (array) get_post_meta($posting_id, self::META_CONTRACT, true);
        $c      = wp_parse_args($stored, self::default_contract());
        // Postings saved before several commission contracts could be attached
        // stored a single ref under 'commission'.
        if (empty($c['commissions']) && !empty($stored['commission'])) {
            $c['commissions'] = [(string) $stored['commission']];
        }
        unset($c['commission']);
        $c['commissions']      = array_values((array) $c['commissions']);
        $c['dashboard_access'] = array_values(array_filter((array) $c['dashboard_access'], 'is_string'));
        // The role granted on approval used to be set on the approved step. A
        // posting that has never stored one here still uses that step's role,
        // until it is next saved (which moves it).
        if (!array_key_exists('role', $stored)) {
            $c['role'] = '';
            foreach ((array) get_post_meta($posting_id, self::META_PROCESS, true) as $step) {
                if (is_array($step) && !empty($step['final']) && !empty($step['role'])) $c['role'] = (string) $step['role'];
            }
        }
        $c['role'] = sanitize_key((string) $c['role']);
        return $c;
    }

    /* ---------------- Contract tab: Commissions + Services ----------------
     * A posting can point at one commission contract (Sales Team → Commission
     * Contracts: a referral program, a role contract or a member contract)
     * and one service contract (Projects → Service Contract → Service
     * Execution → Role Contracts). The picks live in the same contract meta
     * as 'commission' (a "type:key" ref) and 'service' (the role contract
     * id). New contracts are created through those plugins' own save
     * handlers (see em-postings.js), so they show up on their admin pages. */

    const CONTRACT_REF_PATTERN = '/^(referral|role|member):[A-Za-z0-9_.\-]+$/';
    const MAX_COMMISSIONS      = 20;

    /** Which contract sources are available (their plugins are active). */
    public static function contract_sources()
    {
        $sales = class_exists('ST_Contract_Settings') && function_exists('aas_get_referral_programs');
        return ['commission' => $sales, 'service' => class_exists('ST_Contract_Settings')];
    }

    /** Nonces + flags the editor JS needs to create contracts. */
    public static function contracts_config()
    {
        $src = self::contract_sources();
        $can = current_user_can('manage_options');
        return [
            'canAdd'   => $can,
            'accNonce' => ($can && $src['commission']) ? wp_create_nonce('aas_commission_contracts') : '',
            'stcNonce' => ($can && $src['service']) ? wp_create_nonce(ST_Contract_Settings::NONCE_KEY) : '',
        ];
    }

    /** Existing commission contracts as [ref, name, group] rows. */
    public static function commission_choices()
    {
        $out = [];
        if (function_exists('aas_get_referral_programs')) {
            foreach (aas_get_referral_programs() as $p) {
                $out[] = ['ref' => 'referral:' . $p['id'], 'name' => (string) $p['name'], 'group' => 'referral'];
            }
        }
        if (class_exists('ST_Contract_Settings')) {
            $cs = ST_Contract_Settings::get();
            foreach ((array) ($cs['role_commissions'] ?? []) as $rc) {
                $key = (string) ($rc['role'] ?? '');
                if ($key === '') continue;
                $out[] = ['ref' => 'role:' . $key, 'name' => (string) (($rc['name'] ?? '') ?: $key), 'group' => 'role'];
            }
            foreach ((array) ($cs['member_commissions'] ?? []) as $mc) {
                $u = get_userdata((int) ($mc['user_id'] ?? 0));
                if (!$u) continue;
                $out[] = ['ref' => 'member:' . $u->ID, 'name' => (string) (($mc['name'] ?? '') ?: $u->display_name), 'group' => 'member'];
            }
        }
        return $out;
    }

    /** Existing service (role) contracts as [id, name, member_type] rows. */
    public static function service_choices()
    {
        if (!class_exists('ST_Contract_Settings')) return [];
        $types = self::member_type_labels();
        $out   = [];
        foreach ((array) (ST_Contract_Settings::get()['role_contracts'] ?? []) as $rc) {
            $id = (string) ($rc['id'] ?? '');
            if ($id === '') continue;
            $mt    = (string) ($rc['member_type'] ?? '');
            $label = $types[$mt] ?? $mt;
            $out[] = ['id' => $id, 'name' => (string) (($rc['name'] ?? '') ?: $label), 'member_type' => $label];
        }
        return $out;
    }

    /** BuddyPress member types, key => label. */
    private static function member_type_labels()
    {
        $out = [];
        if (function_exists('bp_get_member_types')) {
            foreach (bp_get_member_types([], 'objects') as $key => $obj) {
                $out[$key] = $obj->labels['singular_name'] ?? $key;
            }
        }
        return $out;
    }

    /**
     * Roles a NEW role-commission contract can be created for, grouped by
     * type (App Role / Member Type / Group Role). A role that already has a
     * contract is left out — the Sales Team plugin keeps one contract per
     * role and would silently replace it.
     */
    public static function commission_role_options()
    {
        $taken = [];
        if (class_exists('ST_Contract_Settings')) {
            foreach ((array) (ST_Contract_Settings::get()['role_commissions'] ?? []) as $rc) {
                $taken[(string) ($rc['role'] ?? '')] = true;
            }
        }
        $groups = [
            'wp_role'    => wp_roles()->get_names(),
            'sp_member'  => self::member_type_labels(),
            'group_role' => ['admin' => __('Group Admin', 'email-manager'), 'mod' => __('Group Moderator', 'email-manager'), 'member' => __('Group Member', 'email-manager')],
        ];
        if (function_exists('gdc_get_group_roles')) {
            foreach ((array) gdc_get_group_roles() as $r) {
                if (!empty($r['slug'])) $groups['group_role'][$r['slug']] = $r['name'] ?? $r['slug'];
            }
        }
        foreach ($groups as $type => $rows) {
            $groups[$type] = array_diff_key($rows, $taken);
        }
        return $groups;
    }

    /** Member types a NEW service contract can be created for (one contract per member type). */
    public static function service_member_type_options()
    {
        $taken = [];
        if (class_exists('ST_Contract_Settings')) {
            foreach ((array) (ST_Contract_Settings::get()['role_contracts'] ?? []) as $rc) {
                $taken[(string) ($rc['member_type'] ?? '')] = true;
            }
        }
        return array_diff_key(self::member_type_labels(), $taken);
    }

    /**
     * Validate the Contract tab's picks: any number of commission contracts
     * (a list of "type:key" refs) and one service contract. A null value
     * means the field wasn't submitted (its plugin is inactive) and keeps
     * what's stored; invalid entries are dropped, duplicates collapsed.
     */
    private static function sanitize_contract_picks($raw_commissions, $raw_service, array $existing)
    {
        if ($raw_commissions === null) {
            $commissions = (array) ($existing['commissions'] ?? []);
        } else {
            $commissions = [];
            foreach ((array) $raw_commissions as $ref) {
                if (!is_scalar($ref)) continue;
                $ref = trim(sanitize_text_field((string) $ref));
                if (preg_match(self::CONTRACT_REF_PATTERN, $ref) && !in_array($ref, $commissions, true)) $commissions[] = $ref;
                if (count($commissions) >= self::MAX_COMMISSIONS) break;
            }
        }
        $service = $raw_service === null ? (string) $existing['service'] : trim(sanitize_text_field((string) $raw_service));
        return [
            'commissions' => array_values($commissions),
            'service'     => preg_match('/^[A-Za-z0-9_\-]+$/', $service) ? $service : '',
        ];
    }

    /* ---------------- Postings tab: job boards + affiliate sites, with source tracking ----------------
     * Two kinds of entry share one list: job board postings, and listings of the
     * application's affiliate program (its commission contracts) on affiliate
     * networks / directories -- each with the cost of being there.
     * Each job board posting attached to an application gets its own tracked
     * apply link (?em_src=<entry id>). Visits through that link, and the
     * applications that follow, are counted per entry — job boards don't share
     * their own numbers with us, so what a board reports (views / clicks /
     * applies) can also be logged by hand for comparison. When a visitor
     * arrives without the tracked link, the referring site is used instead if
     * it points at exactly one live entry for that board. */

    /** Popular job boards: key => label + the hosts used to recognise a link or referrer. */
    public static function board_catalog()
    {
        return [
            'linkedin'       => ['label' => 'LinkedIn',         'hosts' => ['linkedin.com', 'lnkd.in']],
            'indeed'         => ['label' => 'Indeed',           'hosts' => ['indeed.com']],
            'ziprecruiter'   => ['label' => 'ZipRecruiter',     'hosts' => ['ziprecruiter.com']],
            'glassdoor'      => ['label' => 'Glassdoor',        'hosts' => ['glassdoor.com']],
            'monster'        => ['label' => 'Monster',          'hosts' => ['monster.com']],
            'careerbuilder'  => ['label' => 'CareerBuilder',    'hosts' => ['careerbuilder.com']],
            'simplyhired'    => ['label' => 'SimplyHired',      'hosts' => ['simplyhired.com']],
            'dice'           => ['label' => 'Dice',             'hosts' => ['dice.com']],
            'upwork'         => ['label' => 'Upwork',           'hosts' => ['upwork.com']],
            'wellfound'      => ['label' => 'Wellfound',        'hosts' => ['wellfound.com', 'angel.co']],
            'handshake'      => ['label' => 'Handshake',        'hosts' => ['joinhandshake.com']],
            'facebook'       => ['label' => 'Facebook Jobs',    'hosts' => ['facebook.com', 'fb.com']],
            'craigslist'     => ['label' => 'Craigslist',       'hosts' => ['craigslist.org']],
            'remoteok'       => ['label' => 'Remote OK',        'hosts' => ['remoteok.com']],
            'weworkremotely' => ['label' => 'We Work Remotely', 'hosts' => ['weworkremotely.com']],
            'other'          => ['label' => __('Other', 'email-manager'), 'hosts' => []],
        ];
    }

    /** Popular sites to list an affiliate program on: key => label + hosts (networks first, then directories / communities). */
    public static function affiliate_catalog()
    {
        return [
            'shareasale'   => ['label' => 'ShareASale',          'hosts' => ['shareasale.com']],
            'cj'           => ['label' => 'CJ Affiliate',        'hosts' => ['cj.com', 'commissionjunction.com']],
            'impact'       => ['label' => 'Impact',              'hosts' => ['impact.com', 'impactradius.com']],
            'awin'         => ['label' => 'Awin',                'hosts' => ['awin.com', 'awin1.com']],
            'rakuten'      => ['label' => 'Rakuten Advertising', 'hosts' => ['rakutenadvertising.com', 'linksynergy.com']],
            'partnerstack' => ['label' => 'PartnerStack',        'hosts' => ['partnerstack.com']],
            'clickbank'    => ['label' => 'ClickBank',           'hosts' => ['clickbank.com', 'clickbank.net']],
            'flexoffers'   => ['label' => 'FlexOffers',          'hosts' => ['flexoffers.com']],
            'refersion'    => ['label' => 'Refersion',           'hosts' => ['refersion.com']],
            'partnerize'   => ['label' => 'Partnerize',          'hosts' => ['partnerize.com']],
            'tapfiliate'   => ['label' => 'Tapfiliate',          'hosts' => ['tapfiliate.com']],
            'leaddyno'     => ['label' => 'LeadDyno',            'hosts' => ['leaddyno.com']],
            'jvzoo'        => ['label' => 'JVZoo',               'hosts' => ['jvzoo.com']],
            'digistore24'  => ['label' => 'Digistore24',         'hosts' => ['digistore24.com']],
            'affiliatefix' => ['label' => 'AffiliateFix',        'hosts' => ['affiliatefix.com']],
            'reddit'       => ['label' => 'Reddit',              'hosts' => ['reddit.com', 'redd.it']],
            'linkedin'     => ['label' => 'LinkedIn',            'hosts' => ['linkedin.com', 'lnkd.in']],
            'facebook'     => ['label' => 'Facebook Groups',     'hosts' => ['facebook.com', 'fb.com']],
            'other'        => ['label' => __('Other', 'email-manager'), 'hosts' => []],
        ];
    }

    /** 'affiliate' or (anything else) 'job'. */
    public static function clean_kind($kind)
    {
        return $kind === self::KIND_AFFILIATE ? self::KIND_AFFILIATE : self::KIND_JOB;
    }

    /** The site catalog for a kind of entry. */
    public static function catalog_for($kind)
    {
        return self::clean_kind($kind) === self::KIND_AFFILIATE ? self::affiliate_catalog() : self::board_catalog();
    }

    /** Which catalog site a hostname belongs to for a kind of entry ('' if none). */
    public static function board_from_host($host, $kind = self::KIND_JOB)
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') return '';
        foreach (self::catalog_for($kind) as $key => $b) {
            foreach ($b['hosts'] as $h) {
                if ($host === $h || substr($host, -(strlen($h) + 1)) === '.' . $h) return $key;
            }
        }
        return '';
    }

    /** Board list for the editor JS. */
    public static function boards_config()
    {
        $out = [];
        foreach (self::board_catalog() as $key => $b) {
            $out[] = ['key' => $key, 'label' => $b['label'], 'hosts' => $b['hosts']];
        }
        return $out;
    }

    /** Affiliate site list for the editor JS (same shape as boards_config()). */
    public static function affiliates_config()
    {
        $out = [];
        foreach (self::affiliate_catalog() as $key => $b) {
            $out[] = ['key' => $key, 'label' => $b['label'], 'hosts' => $b['hosts']];
        }
        return $out;
    }

    public static function board_status_choices()
    {
        return [
            'live'   => __('Live', 'email-manager'),
            'paused' => __('Paused', 'email-manager'),
            'closed' => __('Closed', 'email-manager'),
        ];
    }

    /** "LinkedIn", or "LinkedIn · Senior Developer" when the entry has its own title. */
    public static function board_full_label(array $b)
    {
        $catalog = self::catalog_for($b['kind'] ?? self::KIND_JOB);
        $label   = $catalog[$b['board']]['label'] ?? $b['board'];
        return ($b['title'] ?? '') !== '' ? $label . ' · ' . $b['title'] : $label;
    }

    /** Validate + normalise the attached postings (used by both save paths). */
    /** The commission contract an affiliate listing promotes: a stored reference like "referral:12", or '' for all of them. */
    private static function clean_program($v)
    {
        $v = sanitize_text_field((string) $v);
        return preg_match('/^[A-Za-z0-9_:.\-]{1,80}$/', $v) ? $v : '';
    }

    public static function sanitize_boards($rows)
    {
        $statuses = self::board_status_choices();
        $out      = [];
        $seen     = [];
        $per_kind = [];
        foreach ((array) $rows as $r) {
            if (!is_array($r)) continue;
            $kind = self::clean_kind($r['kind'] ?? '');
            if (($per_kind[$kind] ?? 0) >= self::MAX_BOARDS) continue;
            $per_kind[$kind] = ($per_kind[$kind] ?? 0) + 1;
            $catalog = self::catalog_for($kind);

            $key = sanitize_key((string) ($r['board'] ?? ''));
            if (!isset($catalog[$key])) $key = 'other';

            $id = sanitize_key((string) ($r['id'] ?? ''));
            if (!preg_match('/^[a-z0-9-]{3,40}$/', $id) || isset($seen[$id])) {
                do { $id = $key . '-' . substr(md5(uniqid('', true)), 0, 6); } while (isset($seen[$id]));
            }
            $seen[$id] = true;

            $url = trim((string) ($r['url'] ?? ''));
            $url = preg_match('#^https?://#i', $url) ? esc_url_raw($url) : '';

            $status   = sanitize_key((string) ($r['status'] ?? 'live'));
            $reported = (array) ($r['reported'] ?? []);
            $title    = sanitize_text_field((string) ($r['title'] ?? ''));
            // Numbers may arrive as "$1,200.50" - drop the formatting but keep a minus sign so negatives clamp to 0.
            $num      = function ($v) { return max(0, (float) preg_replace('/[^0-9.\-]/', '', (string) $v)); };
            $count    = function ($v) use ($num) { return (int) $num($v); };
            $out[] = [
                'id'       => $id,
                'board'    => $key,
                'kind'     => $kind,
                'program'  => $kind === self::KIND_AFFILIATE ? self::clean_program($r['program'] ?? '') : '',
                'title'    => function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120),
                'url'      => $url,
                'status'   => isset($statuses[$status]) ? $status : 'live',
                'cost'     => round($num($r['cost'] ?? 0), 2),
                'reported' => [
                    'views'   => $count($reported['views'] ?? 0),
                    'clicks'  => $count($reported['clicks'] ?? 0),
                    'applies' => $count($reported['applies'] ?? 0),
                ],
            ];
        }
        return $out;
    }

    /** Build the rows from the editor's parallel per-row arrays (board_key[], board_url[], …). */
    private static function boards_from_arrays(array $p)
    {
        $col = function ($name, $i) use ($p) {
            return (isset($p[$name]) && is_array($p[$name]) && isset($p[$name][$i]) && is_scalar($p[$name][$i])) ? (string) $p[$name][$i] : '';
        };
        $rows = [];
        $keys = (isset($p['board_key']) && is_array($p['board_key'])) ? array_keys($p['board_key']) : [];
        foreach ($keys as $i) {
            $rows[] = [
                'id'       => $col('board_id', $i),
                'board'    => $col('board_key', $i),
                'kind'     => $col('board_kind', $i),
                'program'  => $col('board_program', $i),
                'status'   => $col('board_status', $i),
                'title'    => $col('board_title', $i),
                'url'      => $col('board_url', $i),
                'cost'     => $col('board_cost', $i),
                'reported' => ['views' => $col('board_rviews', $i), 'clicks' => $col('board_rclicks', $i), 'applies' => $col('board_rapplies', $i)],
            ];
        }
        return self::sanitize_boards($rows);
    }

    public static function get_boards($posting_id)
    {
        $raw = get_post_meta($posting_id, self::META_BOARDS, true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    /** The tracked apply link for one attached posting. */
    public static function board_link($posting, array $board)
    {
        return add_query_arg([
            self::SRC_PARAM => $board['id'],
            'utm_source'    => $board['board'],
            'utm_medium'    => self::clean_kind($board['kind'] ?? '') === self::KIND_AFFILIATE ? 'affiliate_network' : 'job_board',
        ], self::landing_url($posting));
    }

    /** Attached postings as the editor needs them (each with its tracked link). */
    public static function boards_for_editor($posting)
    {
        $out = [];
        foreach (self::get_boards($posting->ID) as $b) {
            $out[] = $b + ['link' => self::board_link($posting, $b)];
        }
        return $out;
    }

    private static function get_board_stats($posting_id)
    {
        $stats = get_post_meta($posting_id, self::META_BOARD_STATS, true);
        return is_array($stats) ? $stats : [];
    }

    private static function bump_board_stat($posting_id, $board_id, $field)
    {
        $stats = self::get_board_stats($posting_id);
        if (!isset($stats[$board_id])) $stats[$board_id] = ['v' => 0, 's' => 0];
        $stats[$board_id][$field] = (int) ($stats[$board_id][$field] ?? 0) + 1;
        $stats[$board_id]['last'] = time();
        update_post_meta($posting_id, self::META_BOARD_STATS, $stats);
    }

    /** Per-posting numbers for the analytics drawer, plus what arrived without a tracked source. */
    public static function get_board_analytics($posting)
    {
        $id     = $posting->ID;
        $stats  = self::get_board_stats($id);
        $rows   = [];
        $av = $as = 0;
        $cost   = 0.0;
        $by_kind  = [self::KIND_JOB => ['count' => 0, 'cost' => 0.0, 'subs' => 0], self::KIND_AFFILIATE => ['count' => 0, 'cost' => 0.0, 'subs' => 0]];
        $programs = null;
        foreach (self::get_boards($id) as $b) {
            $kind    = self::clean_kind($b['kind'] ?? '');
            $catalog = self::catalog_for($kind);
            $st = $stats[$b['id']] ?? [];
            $v  = (int) ($st['v'] ?? 0);
            $s  = (int) ($st['s'] ?? 0);
            $av += $v;
            $as += $s;
            $cost += (float) $b['cost'];
            $by_kind[$kind]['count']++;
            $by_kind[$kind]['cost'] += (float) $b['cost'];
            $by_kind[$kind]['subs'] += $s;
            $program_label = '';
            if ($kind === self::KIND_AFFILIATE && !empty($b['program'])) {
                if ($programs === null) $programs = self::program_names();
                $program_label = $programs[$b['program']] ?? '';
            }
            $rows[] = $b + [
                'kind'       => $kind,
                'program'    => '',
                'program_label' => $program_label,
                'name'       => $b['title'] !== '' ? $b['title'] : ($catalog[$b['board']]['label'] ?? $b['board']),
                'board_label'=> $catalog[$b['board']]['label'] ?? $b['board'],
                'link'       => self::board_link($posting, $b),
                'views'      => $v,
                'subs'       => $s,
                'conversion' => self::conversion($v, $s),
                'cpa'        => ($b['cost'] > 0 && $s > 0) ? round($b['cost'] / $s, 2) : null,
                'last'       => !empty($st['last']) ? human_time_diff((int) $st['last']) : '',
            ];
        }
        $views = self::get_views($id);
        $subs  = self::get_submissions($id);
        $dv    = max(0, $views - $av);
        $ds    = max(0, $subs - $as);
        return [
            'rows'       => $rows,
            'direct'     => ['views' => $dv, 'subs' => $ds, 'conversion' => self::conversion($dv, $ds)],
            'total_cost' => round($cost, 2),
            'total_cpa'  => ($cost > 0 && $as > 0) ? round($cost / $as, 2) : null,
            'by_kind'    => array_map(function ($k) {
                return ['count' => $k['count'], 'cost' => round($k['cost'], 2), 'subs' => $k['subs'], 'cpa' => ($k['cost'] > 0 && $k['subs'] > 0) ? round($k['cost'] / $k['subs'], 2) : null];
            }, $by_kind),
            'currency'   => function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8') : '$',
        ];
    }

    /** Commission contract reference => name, for showing which program an affiliate listing promotes. */
    private static function program_names()
    {
        $out = [];
        try {
            foreach ((array) self::commission_choices() as $c) {
                if (is_array($c) && isset($c['ref'], $c['name'])) $out[(string) $c['ref']] = (string) $c['name'];
            }
        } catch (\Throwable $e) { /* Sales Team plugin not available: no names */ }
        return $out;
    }

    /** Count a landing visit for the board entry it came from, and remember it for the submission. */
    private function track_landing_source($posting)
    {
        $boards = self::get_boards($posting->ID);
        if (!$boards) return;

        $id  = '';
        $src = isset($_GET[self::SRC_PARAM]) ? sanitize_key(wp_unslash($_GET[self::SRC_PARAM])) : '';
        if ($src !== '') {
            foreach ($boards as $b) {
                if ($b['id'] === $src) $id = $src;
            }
        } else {
            // No tracked link: fall back on the referring site, but only when it points at
            // exactly one live entry here (job board posting or affiliate listing) -- else it's a guess.
            $host = strtolower((string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
            if ($host !== '') {
                $live = [];
                foreach ($boards as $b) {
                    if (($b['status'] ?? '') !== 'live' || $b['board'] === 'other') continue;
                    if (self::board_from_host($host, $b['kind'] ?? self::KIND_JOB) === $b['board']) $live[] = $b['id'];
                }
                if (count($live) === 1) $id = $live[0];
            }
        }
        if ($id === '') return;

        self::bump_board_stat($posting->ID, $id, 'v');
        $name = 'em_src_' . (int) $posting->ID;
        $_COOKIE[$name] = $id;
        if (!headers_sent()) {
            setcookie($name, $id, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    /** The board entry this visitor arrived through (from the cookie), if it still exists. */
    private function submission_source($posting_id)
    {
        $name = 'em_src_' . (int) $posting_id;
        $val  = isset($_COOKIE[$name]) ? sanitize_key(wp_unslash($_COOKIE[$name])) : '';
        if ($val === '') return '';
        foreach (self::get_boards($posting_id) as $b) {
            if ($b['id'] === $val) return $val;
        }
        return '';
    }

    /** The role members get when approved; must be a real role. null = not submitted: keep what is stored. */
    private static function sanitize_contract_role($raw, $existing)
    {
        if ($raw === null) return (string) $existing;
        $role = sanitize_key((string) $raw);
        return ($role !== '' && wp_roles()->is_role($role)) ? $role : '';
    }

    /* ---------------- Contract tab: Dashboard Access ----------------
     * The dashboard sections (admin menu pages and their sub-pages) that
     * members gain when they're approved through this posting. It uses the
     * Feature Access catalog from the Gend Society plugin — the same list its
     * "Manage Access" grid shows — and is granted by adding the ticked slugs
     * to the member's `gs_feature_access` (see enroll_user()). */

    const FEATURE_ACCESS_META = 'gs_feature_access';
    const MENU_CACHE_KEY      = 'gs_admin_menu_structure_v1';

    /**
     * [ ['slug' => …, 'name' => …, 'submenu' => [ ['slug' => …, 'name' => …], … ]], … ]
     * Only network admins may (re)build the shared catalog cache: a site
     * admin's own menu may already be trimmed by Feature Access, and caching
     * that would shrink the list for everyone.
     */
    public static function dashboard_catalog()
    {
        if (!function_exists('gs_get_admin_menu_structure_cached')) return [];
        if (is_super_admin() || current_user_can('manage_network')) {
            $items = gs_get_admin_menu_structure_cached();
        } else {
            $items = get_transient(self::MENU_CACHE_KEY);
        }
        return is_array($items) ? $items : [];
    }

    /**
     * Validate the ticked sections against the catalog (exact matches only,
     * stored in catalog order) and make sure every ticked sub-page brings its
     * parent — a sub-page is hidden unless its top-level entry is allowed
     * too. null = not submitted (or no catalog to check against): keep what's stored.
     */
    private static function sanitize_dashboard_access($raw, array $existing)
    {
        if ($raw === null) return $existing;
        $catalog = self::dashboard_catalog();
        if (!$catalog) return $existing;

        $picked = [];
        foreach ((array) $raw as $slug) {
            if (is_string($slug)) $picked[$slug] = true;
        }
        $out = [];
        foreach ($catalog as $item) {
            $keep_children = [];
            foreach ((array) ($item['submenu'] ?? []) as $sub) {
                if (isset($picked[$sub['slug']])) $keep_children[] = $sub['slug'];
            }
            if (isset($picked[$item['slug']]) || $keep_children) {
                $out[] = $item['slug'];
                foreach ($keep_children as $c) $out[] = $c;
            }
        }
        return array_values(array_unique($out));
    }

    /* ---------------- Enrolment: a posting's contract, applied to a member ----------------
     * A member is enrolled in a contract when they are approved through the
     * posting, or when they earn the posting's Role Access role by any other
     * route. Enrolment gives them the contract's role, its dashboard access,
     * and the roles / member types that the attached commission and service
     * contracts are keyed on (referral programs and member contracts have
     * nothing to assign). It is recorded on the member and announced with the
     * em_posting_contract_enrolled action for other plugins. */

    /** Postings a member is enrolled in: posting id => ['at' => timestamp, 'source' => …]. */
    public static function user_enrollments($user_id)
    {
        $m = get_user_meta($user_id, self::USER_ENROLL_META, true);
        return is_array($m) ? $m : [];
    }

    /** The roles / member types a contract hands out, as [kind, key] pairs (kind: role | member_type). */
    private static function contract_grants(array $contract)
    {
        $grants = [];
        if ($contract['role'] !== '') $grants[] = ['role', $contract['role']];
        if (class_exists('ST_Contract_Settings')) {
            $cs = ST_Contract_Settings::get();
            foreach ((array) $contract['commissions'] as $ref) {
                if (strpos($ref, 'role:') !== 0) continue;
                $key = substr($ref, 5);
                foreach ((array) ($cs['role_commissions'] ?? []) as $rc) {
                    if (($rc['role'] ?? '') !== $key) continue;
                    $type = $rc['role_type'] ?? 'wp_role';
                    if ($type === 'wp_role')   $grants[] = ['role', $key];
                    if ($type === 'sp_member') $grants[] = ['member_type', $key];
                }
            }
            if ($contract['service'] !== '') {
                foreach ((array) ($cs['role_contracts'] ?? []) as $rc) {
                    if (($rc['id'] ?? '') === $contract['service'] && !empty($rc['member_type'])) $grants[] = ['member_type', (string) $rc['member_type']];
                }
            }
        }
        $seen = [];
        $out  = [];
        foreach ($grants as $g) {
            $k = $g[0] . ':' . $g[1];
            if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $g; }
        }
        return $out;
    }

    /**
     * Enrol a member in a posting's contract. Without $force a member who is
     * already enrolled is left alone (so removing something from them later
     * isn't undone by the next role change). Administrator-level roles are
     * never handed out this way. Returns whether anything was applied.
     */
    public static function enroll_user($posting_id, $user_id, $source, $force = false)
    {
        static $busy = [];
        $posting_id = (int) $posting_id;
        $user_id    = (int) $user_id;
        $key        = $posting_id . ':' . $user_id;
        if (!$posting_id || !$user_id || isset($busy[$key])) return false;   // (also stops role chains between postings looping)

        $enrolled = self::user_enrollments($user_id);
        if (isset($enrolled[$posting_id]) && !$force) return false;
        $user = get_userdata($user_id);
        if (!$user) return false;

        $busy[$key] = true;
        $contract   = self::get_contract($posting_id);
        $sections   = [];
        foreach (self::contract_grants($contract) as $grant) {
            list($kind, $slug) = $grant;
            if ($kind === 'role') {
                if (self::role_is_locked($slug) || !wp_roles()->is_role($slug)) continue;
                if (!in_array($slug, (array) $user->roles, true)) $user->add_role($slug);
                $sections = array_merge($sections, self::role_dashboard_access($slug));
            } elseif ($kind === 'member_type' && function_exists('bp_set_member_type')) {
                bp_set_member_type($user_id, $slug, true);
            }
        }
        $sections = array_merge($sections, $contract['dashboard_access']);
        self::merge_feature_access($user_id, array_values(array_unique($sections)));

        // Re-read before writing: handing out a role above can enrol the member in
        // OTHER postings, and those records must not be overwritten by this one.
        $enrolled = self::user_enrollments($user_id);
        $enrolled[$posting_id] = ['at' => time(), 'source' => (string) $source];
        update_user_meta($user_id, self::USER_ENROLL_META, $enrolled);
        do_action('em_posting_contract_enrolled', $user_id, $posting_id, (string) $source, $contract);
        unset($busy[$key]);
        return true;
    }

    /** A member earned a role: enrol them in every open posting whose Role Access is that role. */
    public function on_role_earned($user_id, $role)
    {
        $role = sanitize_key((string) $role);
        if ($role === '' || !$user_id) return;
        $postings = get_posts([
            'post_type'   => self::CPT,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'meta_key'    => self::META_ENROLL_ROLE,
            'meta_value'  => $role,
        ]);
        foreach ($postings as $posting) {
            // A closed or draft posting enrols nobody.
            if ((get_post_meta($posting->ID, self::META_STATUS, true) ?: 'open') !== 'open') continue;
            self::enroll_user($posting->ID, (int) $user_id, 'role:' . $role);
        }
    }

    /** Questions of the linked form, used to "sync" mailing-list fields. */
    public static function get_form_fields($form_id)
    {
        $questions = get_post_meta($form_id, '_chat_form_questions', true);
        if (!is_array($questions)) return [];
        $out = [];
        foreach ($questions as $i => $q) {
            $label = is_array($q) && !empty($q['text']) ? $q['text'] : ('Question ' . ($i + 1));
            $type  = is_array($q) && !empty($q['type']) ? $q['type'] : 'text';
            $out[] = ['label' => $label, 'type' => $type];
        }
        return $out;
    }

    /** All email lists for the list selector. */
    public static function get_lists()
    {
        if (!function_exists('em_get_all_lists')) return [];
        $lists = em_get_all_lists();
        $out = [];
        foreach ($lists as $l) {
            $out[] = [
                'id'    => (int) $l['id'],
                'name'  => $l['name'],
                'count' => (int) ($l['subscriber_count'] ?? 0),
            ];
        }
        return $out;
    }

    public static function get_postings()
    {
        return get_posts([
            'post_type'   => self::CPT,
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);
    }

    public static function landing_url($posting)
    {
        $slug = is_object($posting) ? $posting->post_name : $posting;
        if (get_option('permalink_structure')) {
            return home_url('/apply/' . $slug);
        }
        return add_query_arg('em_posting', $slug, home_url('/'));
    }

    public static function form_shortcode($posting_id)
    {
        $form_id = (int) get_post_meta($posting_id, self::META_FORM_ID, true);
        if (!$form_id || !get_post($form_id)) return '';
        $tag = get_post_type($form_id) === 'basic_form' ? 'basic_form' : 'chat_form';
        return "[{$tag} id='{$form_id}']";
    }

    /** Available forms for the linked-form selector. */
    public static function get_form_choices($search = '', $limit = 20)
    {
        $args = [
            'post_type'   => ['chat_form', 'basic_form'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => $limit,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ];
        if ($search !== '') $args['s'] = $search;
        $posts = get_posts($args);
        $out = [];
        foreach ($posts as $p) {
            $out[] = [
                'id'    => (int) $p->ID,
                'title' => $p->post_title ?: __('(untitled form)', 'email-manager'),
                'type'  => $p->post_type === 'chat_form' ? 'chat_form' : 'basic_form',
            ];
        }
        return $out;
    }

    /* ================================================================
       Analytics helpers
       ================================================================ */

    public static function get_views($posting_id)       { return (int) get_post_meta($posting_id, self::META_VIEWS, true); }
    public static function get_submissions($posting_id)
    {
        $stored = get_post_meta($posting_id, self::META_SUBMISSIONS, true);
        if ($stored !== '') return (int) $stored;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
            self::SUBMISSION_TAG, $posting_id
        ));
    }

    public static function conversion($views, $subs)
    {
        if ($views <= 0) return 0.0;
        return min(100, round(($subs / $views) * 100, 1));
    }

    private static function bump_daily($posting_id, $field)
    {
        $daily = get_post_meta($posting_id, self::META_DAILY, true);
        if (!is_array($daily)) $daily = [];
        $today = current_time('Y-m-d');
        if (!isset($daily[$today])) $daily[$today] = ['v' => 0, 's' => 0];
        $daily[$today][$field] = ($daily[$today][$field] ?? 0) + 1;
        if (count($daily) > 60) {
            ksort($daily);
            $daily = array_slice($daily, -60, null, true);
        }
        update_post_meta($posting_id, self::META_DAILY, $daily);
    }

    public static function daily_series($posting_id, $days = 14)
    {
        $daily = get_post_meta($posting_id, self::META_DAILY, true);
        if (!is_array($daily)) $daily = [];
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days", current_time('timestamp')));
            $row  = $daily[$date] ?? ['v' => 0, 's' => 0];
            $series[] = [
                'date'  => $date,
                'label' => date('M j', strtotime($date)),
                'views' => (int) ($row['v'] ?? 0),
                'subs'  => (int) ($row['s'] ?? 0),
            ];
        }
        return $series;
    }

    /** Applicants who applied through this posting, with their current
     *  Applications-stage status and a link to message them (when the
     *  submission is tied to a real WP user — by advancement or a matching
     *  email). Used by the analytics drawer's applicant list. */
    public static function get_applicants($posting_id)
    {
        $process       = self::get_process($posting_id);
        $default_stage = $process[0]['title'] ?? __('Submitted', 'email-manager');
        $boards        = [];
        foreach (self::get_boards($posting_id) as $b) $boards[$b['id']] = $b;
        $form_id       = (int) get_post_meta($posting_id, self::META_FORM_ID, true);
        $form_title    = $form_id ? (get_the_title($form_id) ?: __('(untitled form)', 'email-manager')) : '—';

        $submissions = get_posts([
            'post_type'   => 'chat_submission',
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'meta_key'    => self::SUBMISSION_TAG,
            'meta_value'  => (int) $posting_id,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $out = [];
        foreach ($submissions as $sub) {
            $answers = get_post_meta($sub->ID, '_chat_submission_data', true);
            $email   = class_exists('EM_Applications') ? EM_Applications::extract_email_from_submission($answers) : '';
            $name    = class_exists('EM_Applications')
                ? EM_Applications::extract_name_from_submission($answers, $email ?: __('(unnamed)', 'email-manager'))
                : __('(unnamed)', 'email-manager');
            $stage   = class_exists('EM_Applications')
                ? (get_post_meta($sub->ID, EM_Applications::APPLICANT_STAGE_META, true) ?: $default_stage)
                : $default_stage;

            $user_id = class_exists('EM_Applications') ? (int) get_post_meta($sub->ID, EM_Applications::APPLICANT_USER_META, true) : 0;
            if (!$user_id && $email) {
                $matched_user = get_user_by('email', $email);
                if ($matched_user) $user_id = $matched_user->ID;
            }

            $message_url = '';
            if ($user_id && function_exists('bp_core_get_user_domain')) {
                $user = get_user_by('id', $user_id);
                if ($user) {
                    $message_url = trailingslashit(bp_core_get_user_domain($user_id)) . 'messages/compose/?r=' . rawurlencode($user->user_login);
                }
            }

            // Where they are in the process and what (if anything) they're
            // waiting on: a designated approver, or the applicant themselves.
            $step_index = self::current_step_index($sub->ID, $process);
            $state      = get_post_meta($sub->ID, self::META_STEP_STATE, true) === 'completed' ? 'completed' : 'pending';
            $step       = $step_index >= 0 ? $process[$step_index] : null;
            $waiting    = ($step && $state === 'pending' && $step_index >= 1) ? $step['completion'] : '';
            $next_title = ($step_index >= 0 && isset($process[$step_index + 1])) ? $process[$step_index + 1]['title'] : '';

            // For a tracked "Action update" step: what they've done so far.
            $items = ($waiting === 'action' && self::step_tracks_actions($step))
                ? self::action_progress($step, $user_id, (int) get_post_meta($sub->ID, self::META_STEP_SINCE, true))
                : [];

            $src_id = (string) get_post_meta($sub->ID, self::SUBMISSION_SRC, true);
            $source = $src_id === '' ? '' : (isset($boards[$src_id]) ? self::board_full_label($boards[$src_id]) : __('Removed posting', 'email-manager'));

            $out[] = [
                'id'             => $sub->ID,
                'name'           => $name,
                'email'          => $email,
                'form'           => $form_title,
                'source'         => $source,
                'stage'          => $stage,
                'step_index'     => $step_index,
                'state'          => $state,
                'waiting_on'     => $waiting,
                'approver_label' => $waiting === 'approval' ? self::approver_label($step) : '',
                'action_label'   => $waiting === 'action' ? $step['action_label'] : '',
                'action_url'     => $waiting === 'action' ? $step['action_url'] : '',
                'action_auto'    => (bool) $items,
                'action_items'   => $items,
                'action_done'    => self::action_all_done($items),
                'next_title'     => $next_title,
                'user_id'        => $user_id,
                'message_url'    => $message_url,
                'submitted'      => get_the_date('', $sub->ID),
            ];
        }
        return $out;
    }

    /**
     * The stages a submission moves through, for the shared applicant-detail
     * drawer: this posting's own Apply Process steps (with the role each one
     * hands out; the approved step enrols in the contract, which grants the
     * contract's role). Empty for a submission that didn't come through a posting.
     */
    public static function stage_chips_for_submission($submission_id)
    {
        $posting_id = (int) get_post_meta($submission_id, self::SUBMISSION_TAG, true);
        if (!$posting_id) return [];
        $contract = self::get_contract($posting_id);
        $out = [];
        foreach (self::get_process($posting_id) as $i => $step) {
            $out[] = [
                'label' => $step['title'] !== '' ? $step['title'] : sprintf(__('Step %d', 'email-manager'), $i + 1),
                'role'  => !empty($step['final']) ? $contract['role'] : (string) $step['role'],
            ];
        }
        return $out;
    }

    /* ================================================================
       Public landing page
       ================================================================ */

    public function maybe_render_landing()
    {
        $slug = get_query_var('em_posting');
        if (!$slug && isset($_GET['em_posting'])) {
            $slug = sanitize_title(wp_unslash($_GET['em_posting']));
        }
        if (!$slug) return;

        $posting = get_page_by_path($slug, OBJECT, self::CPT);
        if (!$posting || $posting->post_status === 'trash') {
            status_header(404);
            nocache_headers();
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Not found</title></head><body style="font-family:sans-serif;background:#0b0e14;color:#cbd5f5;text-align:center;padding:80px;">Posting not found.</body></html>';
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$this->is_bot()) {
            update_post_meta($posting->ID, self::META_VIEWS, self::get_views($posting->ID) + 1);
            self::bump_daily($posting->ID, 'v');
            $this->track_landing_source($posting);
        }

        $this->render_landing($posting);
        exit;
    }

    private function is_bot()
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') return true;
        foreach (['bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit'] as $needle) {
            if (strpos($ua, $needle) !== false) return true;
        }
        return false;
    }

    private function render_landing($posting)
    {
        $landing  = self::get_landing($posting->ID);
        $status   = get_post_meta($posting->ID, self::META_STATUS, true) ?: 'open';
        $form_id  = (int) get_post_meta($posting->ID, self::META_FORM_ID, true);
        $accent   = $landing['accent'] ?: '#6366f1';
        $accent2  = $landing['accent2'] ?: '#8b5cf6';
        $closed   = ($status === 'closed');

        $page_id  = (int) get_post_meta($posting->ID, self::META_LANDING_PAGE, true);
        $page     = $page_id ? get_post($page_id) : null;
        $content  = $page ? trim($page->post_content) : '';

        status_header(200);
        nocache_headers();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="<?php echo $closed ? 'noindex' : 'index, follow'; ?>">
    <title><?php echo esc_html($posting->post_title . ' — ' . get_bloginfo('name')); ?></title>
    <?php $this->landing_styles($accent, $accent2); ?>
    <?php wp_head(); ?>
</head>
<body class="em-lp" style="--lp-accent:<?php echo esc_attr($accent); ?>;--lp-accent2:<?php echo esc_attr($accent2); ?>;">
    <div class="em-lp-bg" aria-hidden="true">
        <span class="em-lp-orb em-lp-orb--1"></span>
        <span class="em-lp-orb em-lp-orb--2"></span>
        <span class="em-lp-orb em-lp-orb--3"></span>
        <span class="em-lp-grid"></span>
    </div>

    <main class="em-lp-shell">
        <section class="em-lp-hero">
            <span class="em-lp-tag em-lp-build" style="--d:0;">
                <span class="em-lp-dot"></span><?php echo esc_html(get_bloginfo('name')); ?> · <?php esc_html_e('Now accepting applications', 'email-manager'); ?>
            </span>
            <h1 class="em-lp-title em-lp-build" style="--d:1;"><?php echo esc_html($posting->post_title); ?></h1>
        </section>

        <section class="em-lp-formcard em-lp-build" style="--d:3;" id="apply">
            <?php if ($closed): ?>
                <div class="em-lp-closed">
                    <div class="em-lp-closed__icon">✦</div>
                    <h2><?php esc_html_e('Applications are closed', 'email-manager'); ?></h2>
                    <p><?php esc_html_e('This posting is no longer accepting submissions. Thank you for your interest.', 'email-manager'); ?></p>
                </div>
            <?php elseif ($content !== ''): ?>
                <div class="em-lp-content">
                    <?php
                    global $post;
                    $post = $page;
                    setup_postdata($post);
                    echo apply_filters('the_content', $content);
                    wp_reset_postdata();
                    ?>
                </div>
            <?php elseif ($form_id && get_post($form_id)): ?>
                <div class="em-lp-content">
                    <?php echo do_shortcode(self::form_shortcode($posting->ID)); ?>
                </div>
            <?php else: ?>
                <div class="em-lp-closed">
                    <div class="em-lp-closed__icon">⚙</div>
                    <h2><?php esc_html_e('This posting is being set up', 'email-manager'); ?></h2>
                    <p><?php esc_html_e('No application form has been linked yet. Please check back soon.', 'email-manager'); ?></p>
                </div>
            <?php endif; ?>
        </section>

        <?php $process = self::get_process($posting->ID); if (!empty($process)): ?>
            <section class="em-lp-process em-lp-build" style="--d:4;">
                <h2 class="em-lp-section-title"><?php esc_html_e('How it works', 'email-manager'); ?></h2>
                <div class="em-lp-steps">
                    <?php foreach ($process as $si => $step): ?>
                        <div class="em-lp-step">
                            <div class="em-lp-step__num"><?php echo (int) $si + 1; ?></div>
                            <div class="em-lp-step__body">
                                <div class="em-lp-step__title"><?php echo esc_html($step['title']); ?></div>
                                <?php if (!empty($step['desc'])): ?><div class="em-lp-step__desc"><?php echo esc_html($step['desc']); ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php $contract = self::get_contract($posting->ID); if (!empty($contract['enabled']) && trim($contract['body']) !== ''): ?>
            <section class="em-lp-contract em-lp-build" style="--d:5;">
                <h2 class="em-lp-section-title"><?php echo esc_html($contract['title'] ?: __('Agreement', 'email-manager')); ?></h2>
                <div class="em-lp-contract__body"><?php echo wp_kses_post(wpautop($contract['body'])); ?></div>
                <?php if (!empty($contract['require_accept'])): ?>
                    <p class="em-lp-contract__note"><?php esc_html_e('By submitting your application you agree to the terms above.', 'email-manager'); ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <footer class="em-lp-foot em-lp-build" style="--d:6;">
            <?php echo esc_html(sprintf(__('© %1$s %2$s', 'email-manager'), date('Y'), get_bloginfo('name'))); ?>
        </footer>
    </main>
    <?php wp_footer(); ?>
</body>
</html>
        <?php
    }

    private function landing_styles($accent, $accent2)
    {
        ?>
<style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body.em-lp {
        font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: #070a12; color: #e7ecfb; min-height: 100vh; position: relative; overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }
    .em-lp-bg { position: fixed; inset: 0; z-index: 0; overflow: hidden; }
    .em-lp-orb { position: absolute; border-radius: 50%; filter: blur(70px); opacity: 0.55; animation: lp-float 18s ease-in-out infinite; }
    .em-lp-orb--1 { width: 520px; height: 520px; top: -160px; left: -120px; background: var(--lp-accent); }
    .em-lp-orb--2 { width: 460px; height: 460px; bottom: -180px; right: -120px; background: var(--lp-accent2); animation-delay: -6s; }
    .em-lp-orb--3 { width: 380px; height: 380px; top: 40%; left: 55%; background: #38bdf8; opacity: 0.28; animation-delay: -11s; }
    .em-lp-grid {
        position: absolute; inset: 0;
        background-image: linear-gradient(rgba(148,163,184,0.06) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,0.06) 1px, transparent 1px);
        background-size: 46px 46px;
        mask-image: radial-gradient(ellipse at 50% 0%, #000 10%, transparent 70%);
        -webkit-mask-image: radial-gradient(ellipse at 50% 0%, #000 10%, transparent 70%);
    }
    @keyframes lp-float { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(40px,-30px) scale(1.08); } }

    .em-lp-shell { position: relative; z-index: 1; max-width: 760px; margin: 0 auto; padding: 72px 22px 56px; }
    .em-lp-hero { text-align: center; margin-bottom: 34px; }
    .em-lp-tag {
        display: inline-flex; align-items: center; gap: 8px; padding: 7px 16px; border-radius: 999px;
        background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12);
        font-size: 0.78rem; font-weight: 600; letter-spacing: 0.02em; color: #cbd5f5; margin-bottom: 22px;
    }
    .em-lp-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--lp-accent); box-shadow: 0 0 0 0 var(--lp-accent); animation: lp-pulse 2.4s ease-out infinite; }
    @keyframes lp-pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--lp-accent) 70%, transparent); } 100% { box-shadow: 0 0 0 12px transparent; } }
    .em-lp-title {
        font-size: clamp(2.1rem, 6vw, 3.5rem); line-height: 1.05; font-weight: 800; letter-spacing: -0.03em; margin: 0 0 16px;
        background: linear-gradient(120deg, #fff 20%, color-mix(in srgb, var(--lp-accent) 60%, #fff) 60%, color-mix(in srgb, var(--lp-accent2) 70%, #fff));
        -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
    }

    .em-lp-formcard {
        background: rgba(15,23,42,0.72); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(148,163,184,0.16); border-radius: 24px; padding: 30px;
        box-shadow: 0 40px 90px rgba(2,6,23,0.55); position: relative; overflow: hidden;
    }
    .em-lp-formcard::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent); }
    .em-lp-content { color: #d7def2; line-height: 1.7; }
    .em-lp-content h1, .em-lp-content h2, .em-lp-content h3 { color: #f8fafc; }
    .em-lp-content a { color: var(--lp-accent); }
    .em-lp-content img { max-width: 100%; height: auto; border-radius: 12px; }
    .em-lp-closed { text-align: center; padding: 26px 12px; }
    .em-lp-closed__icon { font-size: 2.4rem; color: var(--lp-accent); margin-bottom: 8px; }
    .em-lp-closed h2 { margin: 0 0 8px; color: #f8fafc; font-size: 1.3rem; }
    .em-lp-closed p { margin: 0; color: #93a0c2; }
    .em-lp-foot { text-align: center; color: #647088; font-size: 0.82rem; margin-top: 30px; }

    .em-lp-build { opacity: 0; transform: translateY(22px); animation: lp-build 720ms cubic-bezier(0.22,0.61,0.36,1) forwards; animation-delay: calc(var(--d, 0) * 120ms + 120ms); }
    @keyframes lp-build { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) { .em-lp-build { animation: none; opacity: 1; transform: none; } }

    .em-lp-content .chat-form-container, .em-lp-content .basic-form-container { max-width: 100% !important; margin: 0 auto !important; }

    .em-lp-section-title { text-align: center; font-size: 1.5rem; font-weight: 700; letter-spacing: -0.01em; color: #f8fafc; margin: 0 0 22px; }
    .em-lp-process { margin-top: 40px; }
    .em-lp-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
    .em-lp-step {
        display: flex; gap: 13px; align-items: flex-start; padding: 18px;
        background: rgba(255,255,255,0.04); border: 1px solid rgba(148,163,184,0.14); border-radius: 16px;
    }
    .em-lp-step__num {
        flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff;
        background: linear-gradient(135deg, var(--lp-accent), var(--lp-accent2));
    }
    .em-lp-step__title { font-weight: 700; color: #f8fafc; margin-bottom: 4px; }
    .em-lp-step__desc { color: #97a3c4; font-size: 0.9rem; line-height: 1.55; }
    .em-lp-contract { margin-top: 40px; }
    .em-lp-contract__body {
        background: rgba(15,23,42,0.6); border: 1px solid rgba(148,163,184,0.14); border-radius: 16px;
        padding: 22px 24px; color: #c7d0e6; line-height: 1.7; max-height: 360px; overflow-y: auto;
    }
    .em-lp-contract__note { text-align: center; color: #93a0c2; font-size: 0.86rem; margin-top: 12px; }
</style>
        <?php
    }

    /* ================================================================
       Submission attribution + emails
       ================================================================ */

    public function on_form_submission($form_id, $answers, $submission_id)
    {
        $postings = get_posts([
            'post_type'   => self::CPT,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'meta_key'    => self::META_FORM_ID,
            'meta_value'  => (int) $form_id,
        ]);
        if (empty($postings)) return;

        foreach ($postings as $posting) {
            update_post_meta($submission_id, self::SUBMISSION_TAG, $posting->ID);
            // Increment from the stored counter only — using get_submissions() here
            // would fall back to a DB count that already includes the row we just
            // tagged above, double-counting the first submission.
            $current = (int) get_post_meta($posting->ID, self::META_SUBMISSIONS, true);
            update_post_meta($posting->ID, self::META_SUBMISSIONS, $current + 1);
            self::bump_daily($posting->ID, 's');
            // Remember which job board posting (if any) sent them here.
            $board_id = $this->submission_source($posting->ID);
            if ($board_id !== '') {
                update_post_meta($submission_id, self::SUBMISSION_SRC, $board_id);
                self::bump_board_stat($posting->ID, $board_id, 's');
            }
            // A fresh submission enters the Apply Process at its first step —
            // this is what used to be the fixed "Applicant Email"/"Team Email"
            // send, now driven by whatever step 0 is configured to do.
            $this->advance_applicant_to_step($posting, $submission_id, 0, $answers);
            // Submitting the form IS what completes step 1, so the applicant
            // moves straight into step 2 and waits there for that step's
            // approval / user action (a single-step process just completes).
            $this->complete_step($posting, $submission_id, 'submission', 0, $answers);
        }
    }

    /** Move an applicant to one of this posting's own Apply Process steps:
     *  grants (or auto-creates-and-grants) the step's role, records it as
     *  the applicant's current stage, emails the applicant the step's
     *  message, notifies the step's team email(s), and subscribes them to
     *  the step's mailing list. Replaces the old fixed per-posting
     *  "Applicant Email" / "Team Email" settings with per-step behavior. */
    private function advance_applicant_to_step($posting, $submission_id, $step_index, $answers = null)
    {
        $process = self::get_process($posting->ID);
        if (!isset($process[$step_index])) return false;
        $step = $process[$step_index];

        if ($answers === null) {
            $answers = get_post_meta($submission_id, '_chat_submission_data', true);
        }
        $email = class_exists('EM_Applications') ? EM_Applications::extract_email_from_submission($answers) : '';
        $name  = class_exists('EM_Applications') ? EM_Applications::extract_name_from_submission($answers, '') : '';

        $stage_label = $step['title'] !== '' ? $step['title'] : sprintf(__('Step %d', 'email-manager'), $step_index + 1);
        update_post_meta($submission_id, self::META_STEP_INDEX, $step_index);
        update_post_meta($submission_id, self::META_STEP_STATE, 'pending');
        if (class_exists('EM_Applications')) {
            update_post_meta($submission_id, EM_Applications::APPLICANT_STAGE_META, $stage_label);
        }
        $this->log_step_event($submission_id, $stage_label, 'entered', '', get_current_user_id());

        // Role grant + optional auto-create (mirrors EM_Applications::transition_applicant,
        // scoped to this posting's own step instead of the global stage list).
        $user = $email ? get_user_by('email', $email) : null;
        if (!$user && $email && !empty($step['auto_create']) && class_exists('EM_Applications')) {
            $username = EM_Applications::unique_username_from_email($email);
            $user_id  = wp_create_user($username, wp_generate_password(20), $email);
            if (!is_wp_error($user_id)) {
                $user = get_user_by('id', $user_id);
                wp_update_user(['ID' => $user_id, 'display_name' => $name ?: $username]);
                wp_new_user_notification($user_id, null, 'user');
            }
        }
        // The approved step grants the role chosen on the Contract tab; every
        // other step grants its own.
        $grant_role = empty($step['final']) ? (string) ($step['role'] ?? '') : '';
        if ($user && $grant_role !== '') {
            $user->add_role($grant_role);
            if (class_exists('EM_Applications')) update_post_meta($submission_id, EM_Applications::APPLICANT_USER_META, $user->ID);
            // A role also brings the dashboard sections set on it.
            self::merge_feature_access((int) $user->ID, self::role_dashboard_access($grant_role));
        }
        // Approved: the member is enrolled in the contract (its role, dashboard
        // access, and the commission and service contracts attached to it).
        if ($user && !empty($step['final'])) {
            if (class_exists('EM_Applications')) update_post_meta($submission_id, EM_Applications::APPLICANT_USER_META, $user->ID);
            self::enroll_user($posting->ID, (int) $user->ID, 'approval', true);
        }

        // "Action update" steps only count what the member does from here on
        // — stamped AFTER the account exists so setting it up isn't mistaken
        // for the member acting, and a second ahead so the very action that
        // completed the previous step can't also satisfy this one.
        $watch_uid = $user ? (int) $user->ID : 0;
        update_post_meta($submission_id, self::META_STEP_SINCE, time() + 1);
        if ($step_index >= 1 && self::step_tracks_actions($step)) {
            update_option(self::TRACK_OPTION, '1');
            if ($watch_uid) self::watch_add($watch_uid, $submission_id);
        } elseif ($watch_uid) {
            self::watch_remove($watch_uid, $submission_id);
        }

        $tokens = [
            '{applicant_name}'  => $name ?: __('there', 'email-manager'),
            '{applicant_email}' => $email ?: '',
            '{posting_title}'   => $posting->post_title,
            '{step}'            => $stage_label,
            '{action_label}'    => (string) ($step['action_label'] ?? ''),
            '{action_url}'      => (string) ($step['action_url'] ?? ''),
            '{action_list}'     => implode(', ', self::action_update_labels($step)),
            '{site_name}'       => get_bloginfo('name'),
            '{site_url}'        => home_url(),
            '{all_answers}'     => $this->answers_to_text($answers),
        ];

        // Send every email configured on this step — each can go to the
        // applicant, to extra team addresses, or both. Bodies come from the
        // rich HTML editor, so these always go out as HTML mail.
        $emailed_applicant = false;
        if (!empty($step['emails']) && is_array($step['emails'])) {
            foreach ($step['emails'] as $entry) {
                $recipients = [];
                if (!empty($entry['to_applicant']) && $email) { $recipients[] = $email; $emailed_applicant = true; }
                if (!empty($entry['to_emails'])) {
                    $extra = array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $entry['to_emails'])), 'is_email');
                    $recipients = array_merge($recipients, $extra);
                }
                $recipients = array_unique($recipients);
                if (!$recipients) continue;

                $subject = strtr((string) ($entry['subject'] ?? ''), $tokens);
                $body    = strtr((string) ($entry['body'] ?? ''), $tokens);
                wp_mail($recipients, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            }
        }

        if (!empty($step['list_id'])) {
            $this->add_applicant_to_list_id($answers, (int) $step['list_id']);
        }

        // The approved step waits on no one: its role / list / emails have run
        // above, so the process is complete.
        if (!empty($step['final'])) {
            $this->complete_step($posting, $submission_id, 'approved', get_current_user_id(), $answers);
            return true;
        }

        // Steps after step 1 wait on someone. Approval steps ask the
        // designated approver(s) to sign off; user-action steps make sure the
        // applicant is told what to do even if the step has no email of its own.
        if ($step_index >= 1) {
            if ($step['completion'] === 'approval') {
                $this->send_approval_requests($posting, $submission_id, $step_index, $step, $name, $email, $answers);
            } elseif ($step['completion'] === 'action' && $email && !$emailed_applicant && ($step['action_label'] !== '' || self::step_tracks_actions($step))) {
                $todo = self::action_update_labels($step);
                $body = '<p>' . esc_html(sprintf(__('Hi %s,', 'email-manager'), $name ?: __('there', 'email-manager'))) . '</p>'
                    . '<p>' . esc_html(sprintf(__('Next step for %1$s: %2$s', 'email-manager'), $posting->post_title, $step['action_label'] !== '' ? $step['action_label'] : $stage_label)) . '</p>'
                    . ($todo ? '<ul><li>' . implode('</li><li>', array_map('esc_html', $todo)) . '</li></ul>' : '')
                    . ($step['action_url'] !== '' ? '<p><a href="' . esc_url($step['action_url']) . '">' . esc_html($step['action_url']) . '</a></p>' : '');
                wp_mail($email, sprintf(__('%1$s — next step', 'email-manager'), $posting->post_title), $body, ['Content-Type: text/html; charset=UTF-8']);
            }
        }

        // A step that's already satisfied on arrival (e.g. their cover page is
        // already built and published) shouldn't wait for the hourly check.
        if ($step_index >= 1 && $watch_uid && self::step_tracks_actions($step)) {
            $this->check_applicant_actions($posting, $process, $submission_id);
        }

        return true;
    }

    /* ================================================================
       Step completion — approval / user action
       ================================================================ */

    /** Append an entry to the applicant's stage history. */
    private function log_step_event($submission_id, $stage_label, $event, $method, $by_user_id)
    {
        if (!class_exists('EM_Applications')) return;
        $history = get_post_meta($submission_id, EM_Applications::APPLICANT_HISTORY_META, true);
        if (!is_array($history)) $history = [];
        $history[] = [
            'stage'  => $stage_label,
            'event'  => $event,
            'method' => $method,
            'time'   => current_time('mysql'),
            'by'     => (int) $by_user_id,
        ];
        update_post_meta($submission_id, EM_Applications::APPLICANT_HISTORY_META, $history);
    }

    /** The step an applicant is currently on (stored index, else matched by
     *  stage title for older applicants, else the first step). */
    public static function current_step_index($submission_id, array $process)
    {
        if (!$process) return -1;
        $stored = get_post_meta($submission_id, self::META_STEP_INDEX, true);
        if ($stored !== '' && isset($process[(int) $stored])) return (int) $stored;
        $stage = class_exists('EM_Applications') ? get_post_meta($submission_id, EM_Applications::APPLICANT_STAGE_META, true) : '';
        foreach ($process as $i => $s) {
            if ($s['title'] === $stage) return $i;
        }
        return 0;
    }

    /** Mark the applicant's current step complete and move them to the next
     *  one (running its effects) — or flag the whole process complete if it
     *  was the last step. $method: submission | approval | manual | auto. */
    private function complete_step($posting, $submission_id, $method, $by_user_id = 0, $answers = null)
    {
        $process = self::get_process($posting->ID);
        $index   = self::current_step_index($submission_id, $process);
        if ($index < 0) return false;
        if (get_post_meta($submission_id, self::META_STEP_STATE, true) === 'completed') return false;

        $title = $process[$index]['title'] !== '' ? $process[$index]['title'] : sprintf(__('Step %d', 'email-manager'), $index + 1);
        $this->log_step_event($submission_id, $title, 'completed', $method, $by_user_id);

        if (isset($process[$index + 1])) {
            return $this->advance_applicant_to_step($posting, $submission_id, $index + 1, $answers);
        }
        update_post_meta($submission_id, self::META_STEP_STATE, 'completed');
        if ($answers === null) $answers = get_post_meta($submission_id, '_chat_submission_data', true);
        $uid = self::applicant_user_id($submission_id, $answers);
        if ($uid) self::watch_remove($uid, $submission_id);
        return true;
    }

    /** Members who may approve a step: the chosen member, or everyone with
     *  the chosen role (capped so a huge role can't flood an inbox). */
    public static function eligible_approvers(array $step)
    {
        if (($step['completion'] ?? '') !== 'approval') return [];
        if ($step['approver_type'] === 'member') {
            $u = $step['approver_user'] ? get_userdata($step['approver_user']) : false;
            return $u ? [$u] : [];
        }
        if ($step['approver_role'] === '') return [];
        return get_users(['role' => $step['approver_role'], 'number' => self::MAX_APPROVER_EMAILS, 'orderby' => 'ID']);
    }

    /** Admins can always approve; otherwise only the designated member / role. */
    public static function can_user_approve(array $step, $user_id)
    {
        $user_id = (int) $user_id;
        if (!$user_id) return false;
        if (user_can($user_id, 'manage_options')) return true;
        if (($step['completion'] ?? '') !== 'approval') return false;
        if ($step['approver_type'] === 'member') {
            return $step['approver_user'] && (int) $step['approver_user'] === $user_id;
        }
        $u = get_userdata($user_id);
        return $u && $step['approver_role'] !== '' && in_array($step['approver_role'], (array) $u->roles, true);
    }

    /** "Role: Editor" / "Jane Doe" — who signs off, for display. */
    public static function approver_label(array $step)
    {
        if (($step['completion'] ?? '') !== 'approval') return '';
        if ($step['approver_type'] === 'member') {
            $n = (string) ($step['approver_user_name'] ?? '');
            return $n !== '' ? $n : __('Admins only (no member chosen)', 'email-manager');
        }
        $roles = wp_roles()->roles;
        $name  = isset($roles[$step['approver_role']]['name']) ? translate_user_role($roles[$step['approver_role']]['name']) : $step['approver_role'];
        return sprintf(__('Role: %s', 'email-manager'), $name);
    }

    private static function approval_sig($submission_id, $step_index)
    {
        return hash_hmac('sha256', 'em_posting_approve|' . (int) $submission_id . '|' . (int) $step_index, wp_salt('auth'));
    }

    /** Signed one-click approval link (login + eligibility still required). */
    public static function approval_url($submission_id, $step_index)
    {
        return add_query_arg([
            'action' => 'em_posting_approve',
            'sub'    => (int) $submission_id,
            'step'   => (int) $step_index,
            'sig'    => self::approval_sig($submission_id, $step_index),
        ], admin_url('admin-post.php'));
    }

    private function send_approval_requests($posting, $submission_id, $step_index, array $step, $name, $email, $answers)
    {
        $approvers = self::eligible_approvers($step);
        if (!$approvers) return;

        $who   = $name ?: ($email ?: __('An applicant', 'email-manager'));
        $title = $step['title'] !== '' ? $step['title'] : sprintf(__('Step %d', 'email-manager'), $step_index + 1);
        $url   = self::approval_url($submission_id, $step_index);

        $subject = sprintf(__('Approval needed: %1$s — %2$s (%3$s)', 'email-manager'), $who, $posting->post_title, $title);
        $body    = '<p>' . esc_html(sprintf(__('%1$s applied to %2$s and is waiting on the "%3$s" step, which needs your approval.', 'email-manager'), $who, $posting->post_title, $title)) . '</p>'
            . ($email ? '<p>' . esc_html($email) . '</p>' : '')
            . '<p style="color:#555;">' . nl2br(esc_html($this->answers_to_text($answers))) . '</p>'
            . '<p><a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#fff;border-radius:6px;text-decoration:none;">' . esc_html__('Approve and continue', 'email-manager') . '</a></p>'
            . '<p style="color:#777;font-size:12px;">' . esc_html__('You must be signed in as an approver for this step.', 'email-manager') . ' ' . esc_url($url) . '</p>';

        foreach ($approvers as $u) {
            if (is_email($u->user_email)) {
                wp_mail($u->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            }
        }
    }

    /** Front door for the emailed approval link. */
    public function handle_approval_link()
    {
        $sub  = absint($_GET['sub'] ?? 0);
        $step = isset($_GET['step']) ? (int) $_GET['step'] : -1;
        $sig  = (string) ($_GET['sig'] ?? '');
        if (!$sub || $step < 0 || !hash_equals(self::approval_sig($sub, $step), $sig)) {
            wp_die(esc_html__('This approval link is invalid.', 'email-manager'), '', ['response' => 403]);
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::approval_url($sub, $step)));
            exit;
        }

        $posting_id = (int) get_post_meta($sub, self::SUBMISSION_TAG, true);
        $posting    = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT) {
            wp_die(esc_html__('That application could not be found.', 'email-manager'), '', ['response' => 404]);
        }
        $process = self::get_process($posting->ID);
        if (!isset($process[$step]) || $process[$step]['completion'] !== 'approval' || $step < 1) {
            wp_die(esc_html__('That step does not need approval.', 'email-manager'), '', ['response' => 400]);
        }
        if (!self::can_user_approve($process[$step], get_current_user_id())) {
            wp_die(esc_html__('You are not an approver for this step.', 'email-manager'), '', ['response' => 403]);
        }
        if (self::current_step_index($sub, $process) !== $step || get_post_meta($sub, self::META_STEP_STATE, true) === 'completed') {
            wp_die(esc_html__('This step was already handled — nothing more to do.', 'email-manager'), esc_html__('Already handled', 'email-manager'), ['response' => 200]);
        }

        $this->complete_step($posting, $sub, 'approval', get_current_user_id());
        wp_die(esc_html__('Approved — the applicant has moved on to the next step.', 'email-manager'), esc_html__('Approved', 'email-manager'), ['response' => 200]);
    }

    /* ---------------- User-action detection ---------------- */

    /** Best-effort: the WP user behind an application (advancement link or
     *  a matching email). 0 when the applicant has no account yet. */
    public static function applicant_user_id($submission_id, $answers)
    {
        $uid = class_exists('EM_Applications') ? (int) get_post_meta($submission_id, EM_Applications::APPLICANT_USER_META, true) : 0;
        if (!$uid && class_exists('EM_Applications')) {
            $email = EM_Applications::extract_email_from_submission($answers);
            $u = $email ? get_user_by('email', $email) : false;
            if ($u) $uid = (int) $u->ID;
        }
        return $uid;
    }

    /** Plain-English list of the updates a step is waiting on. */
    public static function action_update_labels(array $step)
    {
        if (!self::step_tracks_actions($step)) return [];
        $choices = self::action_update_choices();
        $out = [];
        foreach ($step['action_updates'] as $k) {
            if (isset($choices[$k])) $out[] = $choices[$k];
        }
        return $out;
    }

    /** Which of a step's tracked updates this member has done since $since
     *  (unix ts). Returns [{key, label, done}, ...]. */
    public static function action_progress(array $step, $user_id, $since)
    {
        $choices = self::action_update_choices();
        $log = $user_id ? get_user_meta((int) $user_id, self::META_ACTION_LOG, true) : [];
        if (!is_array($log)) $log = [];
        $items = [];
        foreach ((array) ($step['action_updates'] ?? []) as $k) {
            if (!isset($choices[$k])) continue;
            if ($k === 'cover_page' || $k === 'cover_page_published') {
                // The cover page is a persistent post, so this is judged on the
                // page as it stands (built? published?) rather than as an event.
                $done = self::cover_page_ok((int) $user_id, $k === 'cover_page_published');
            } else {
                $done = isset($log[$k]) && (int) $log[$k] >= (int) $since;
            }
            $items[] = ['key' => $k, 'label' => $choices[$k], 'done' => $done];
        }
        return $items;
    }

    /** Does the member's cover profile page (their `gdc_profile_page` post,
     *  shown under Cover on /members/…) have real content — and, when
     *  $must_publish, is it published? */
    public static function cover_page_ok($user_id, $must_publish)
    {
        $user_id = (int) $user_id;
        if (!$user_id) return false;
        $page_id = (int) get_user_meta($user_id, '_gdc_profile_page_id', true);
        $page    = $page_id ? get_post($page_id) : null;
        if (!$page || $page->post_type !== 'gdc_profile_page' || in_array($page->post_status, ['trash', 'auto-draft'], true)) return false;
        if ($must_publish && $page->post_status !== 'publish') return false;
        return self::profile_page_has_content($page->post_content);
    }

    /** New profile pages start empty ('' or just an empty paragraph block);
     *  anything else — text, image, layout blocks — counts as built. */
    private static function profile_page_has_content($content)
    {
        $c = preg_replace('/<!--.*?-->/s', '', (string) $content);
        $c = preg_replace('#<p[^>]*>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $c);
        return trim($c) !== '';
    }

    /** True once every tracked update is done. */
    public static function action_all_done(array $items)
    {
        if (!$items) return false;
        foreach ($items as $i) {
            if (empty($i['done'])) return false;
        }
        return true;
    }

    /* ---- per-member watch list: which applications wait on this member ---- */

    private static function watch_add($uid, $sid)
    {
        $list = get_user_meta((int) $uid, self::META_WATCH, true);
        if (!is_array($list)) $list = [];
        if (!in_array((int) $sid, $list, true)) {
            $list[] = (int) $sid;
            update_user_meta((int) $uid, self::META_WATCH, $list);
        }
    }

    private static function watch_remove($uid, $sid)
    {
        $list = get_user_meta((int) $uid, self::META_WATCH, true);
        if (!is_array($list) || !in_array((int) $sid, $list, true)) return;
        $list = array_values(array_diff($list, [(int) $sid]));
        if ($list) update_user_meta((int) $uid, self::META_WATCH, $list);
        else delete_user_meta((int) $uid, self::META_WATCH);
    }

    /** Advance one applicant through every tracked user-action step they've
     *  now satisfied (cascading through consecutive satisfied steps). Also
     *  keeps the member's watch entry in step with where the applicant is. */
    private function check_applicant_actions($posting, array $process, $sid)
    {
        $answers = get_post_meta($sid, '_chat_submission_data', true);
        $uid     = self::applicant_user_id($sid, $answers);
        $moved   = false;
        for ($n = 0; $n < count($process); $n++) {
            if (get_post_meta($sid, self::META_STEP_STATE, true) === 'completed') break;
            $idx = self::current_step_index($sid, $process);
            if ($idx < 1 || !self::step_tracks_actions($process[$idx]) || !$uid) break;
            self::watch_add($uid, $sid);   // also covers members who only just got linked
            $since = (int) get_post_meta($sid, self::META_STEP_SINCE, true);
            if (!self::action_all_done(self::action_progress($process[$idx], $uid, $since))) break;
            if (!$this->complete_step($posting, $sid, 'auto', 0, $answers)) break;
            $moved = true;
        }
        if ($uid && !self::is_awaiting_tracked($sid, $process)) self::watch_remove($uid, $sid);
        return $moved;
    }

    /** Is this application currently waiting on tracked member actions? */
    private static function is_awaiting_tracked($sid, array $process)
    {
        if (get_post_meta($sid, self::META_STEP_STATE, true) === 'completed') return false;
        $idx = self::current_step_index($sid, $process);
        return $idx >= 1 && self::step_tracks_actions($process[$idx]);
    }

    /** Advance every applicant of this posting whose tracked actions are all
     *  done. Returns how many moved; $pending counts those still waiting. */
    private function check_actions_for_posting($posting, &$pending = 0)
    {
        $process = self::get_process($posting->ID);
        $tracked = false;
        foreach ($process as $s) {
            if (self::step_tracks_actions($s)) { $tracked = true; break; }
        }
        if (!$tracked) return 0;

        $subs = get_posts([
            'post_type' => 'chat_submission', 'numberposts' => -1, 'post_status' => ['publish', 'draft'],
            'meta_key' => self::SUBMISSION_TAG, 'meta_value' => (int) $posting->ID, 'fields' => 'ids',
        ]);
        $moved = 0;
        foreach ($subs as $sid) {
            if ($this->check_applicant_actions($posting, $process, $sid)) $moved++;
            if (self::is_awaiting_tracked($sid, $process)) $pending++;
        }
        return $moved;
    }

    public function schedule_action_checks()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    /** Hourly safety net (also links members who registered after their
     *  application) — and switches event recording off when nobody's waiting. */
    public function cron_check_actions()
    {
        $pending = 0;
        foreach (self::get_postings() as $posting) {
            $this->check_actions_for_posting($posting, $pending);
        }
        update_option(self::TRACK_OPTION, $pending ? '1' : '0');
    }

    /* ---------------- Member action tracking (event hooks) ---------------- */

    /** Log that the member just did something, then re-check the
     *  applications waiting on them. Only the member's own actions count,
     *  and it's a single option read unless some applicant awaits tracking. */
    private function record_member_action($uid, $key)
    {
        $uid = (int) $uid;
        if (!$uid || get_option(self::TRACK_OPTION) !== '1') return;
        if ((int) get_current_user_id() !== $uid) return;

        $log = get_user_meta($uid, self::META_ACTION_LOG, true);
        if (!is_array($log)) $log = [];
        $log[$key] = time();
        update_user_meta($uid, self::META_ACTION_LOG, $log);

        $this->check_watched($uid);
    }

    /** Re-check every application currently waiting on this member. */
    private function check_watched($uid)
    {
        $subs = get_user_meta((int) $uid, self::META_WATCH, true);
        if (!is_array($subs) || !$subs) return;
        foreach ($subs as $sid) {
            $posting_id = (int) get_post_meta($sid, self::SUBMISSION_TAG, true);
            $posting    = $posting_id ? get_post($posting_id) : null;
            if (!$posting || $posting->post_type !== self::CPT) { self::watch_remove($uid, $sid); continue; }
            $this->check_applicant_actions($posting, self::get_process($posting->ID), $sid);
        }
    }

    /** The member (or an admin for them) saved their cover profile page —
     *  its state is judged live, so just re-check who is waiting on it. */
    public function hook_cover_page($post_id, $post = null)
    {
        if (get_option(self::TRACK_OPTION) !== '1') return;
        if (!is_object($post) || $post->post_type !== 'gdc_profile_page') return;
        $this->check_watched((int) $post->post_author);
    }

    public function hook_avatar($item_id)              { $this->record_member_action($item_id, 'avatar'); }
    public function hook_cover($item_id, $name = '', $url = '', $code = 1)
    {
        if ((int) $code === 1) $this->record_member_action($item_id, 'cover_image');
    }
    public function hook_profile($user_id)             { $this->record_member_action($user_id, 'profile_update'); }
    public function hook_settings()                    { $this->record_member_action(get_current_user_id(), 'settings_update'); }
    public function hook_activity($content, $user_id, $activity_id = 0) { $this->record_member_action($user_id, 'activity_post'); }
    public function hook_group($group_id, $group = null)
    {
        $this->record_member_action(is_object($group) ? (int) ($group->creator_id ?? 0) : 0, 'group_created');
    }
    public function hook_content($new_status, $old_status, $post)
    {
        if ($new_status !== 'publish' || $old_status === 'publish' || !is_object($post)) return;
        $pt = get_post_type_object($post->post_type);
        if (!$pt || empty($pt->public) || $post->post_type === 'attachment') return;
        $this->record_member_action((int) $post->post_author, 'content_created');
    }

    /** Subscribe the applicant to a specific mailing list (per Apply Process step). */
    private function add_applicant_to_list_id($answers, $list_id)
    {
        if (!$list_id || !function_exists('em_add_subscriber')) return;
        $email = class_exists('EM_Applications') ? EM_Applications::extract_email_from_submission($answers) : '';
        if (!$email) return;

        $name  = EM_Applications::extract_name_from_submission($answers, '');
        $first = $name; $last = '';
        if ($name !== '' && strpos($name, ' ') !== false) {
            $parts = explode(' ', $name, 2);
            $first = $parts[0];
            $last  = $parts[1];
        }
        em_add_subscriber($email, $first, $last, [$list_id], 'subscribed');
    }

    private function answers_to_text($answers)
    {
        if (!is_array($answers)) return '';
        $lines = [];
        foreach ($answers as $key => $val) {
            $q = is_array($val) && isset($val['question']) ? $val['question'] : (is_numeric($key) ? 'Question ' . ((int) $key + 1) : ucwords(str_replace('_', ' ', $key)));
            $a = is_array($val) ? ($val['answer'] ?? '') : $val;
            $lines[] = $q . ': ' . $a;
        }
        return implode("\n", $lines);
    }

    /* ================================================================
       AJAX
       ================================================================ */

    private function guard()
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('em_app_support', 'nonce');
    }

    public function ajax_search_forms()
    {
        $this->guard();
        $q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
        wp_send_json_success(['forms' => self::get_form_choices($q, 20)]);
    }

    public function ajax_save_posting()
    {
        $this->guard();

        $posting_id = absint($_POST['posting_id'] ?? 0);
        $title      = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        if ($title === '') $title = __('Untitled Posting', 'email-manager');

        $status  = in_array(($_POST['status'] ?? ''), ['draft', 'open', 'closed'], true) ? $_POST['status'] : 'open';
        $form_id = absint($_POST['form_id'] ?? 0);

        // The editor no longer has colour pickers. A posting saved without them keeps the colours it already has
        // (only a colour that is actually posted is changed).
        $landing = self::landing_colours($posting_id);
        if (isset($_POST['landing_accent']))  $landing['accent']  = sanitize_hex_color(wp_unslash($_POST['landing_accent']))  ?: self::default_landing()['accent'];
        if (isset($_POST['landing_accent2'])) $landing['accent2'] = sanitize_hex_color(wp_unslash($_POST['landing_accent2'])) ?: self::default_landing()['accent2'];

        $ty_mode  = in_array(($_POST['ty_mode'] ?? ''), ['message', 'redirect', 'page'], true) ? $_POST['ty_mode'] : 'message';
        $thankyou = [
            'mode'         => $ty_mode,
            'message'      => wp_kses_post(wp_unslash($_POST['ty_message'] ?? '')),
            'redirect_url' => esc_url_raw(wp_unslash($_POST['ty_redirect_url'] ?? '')),
            'page_id'      => 0,
        ];

        // Apply Process — each step doubles as a status stage: a role to
        // grant (created ahead of time via the role popup), a mailing list,
        // and any number of rich-HTML emails (to the applicant, extra team
        // addresses, or both — each edited via the email popup and passed
        // here as JSON). Replaces the old fixed "Applicant Email" / "Team
        // Email" tabs. process_auto_create is index-keyed like a classic
        // checkbox group so unchecked boxes don't shift later rows out of
        // alignment.
        $p_titles      = isset($_POST['process_title'])        ? (array) $_POST['process_title']        : [];
        $p_descs       = isset($_POST['process_desc'])         ? (array) $_POST['process_desc']         : [];
        $p_roles       = isset($_POST['process_role'])         ? (array) $_POST['process_role']         : [];
        $p_auto        = isset($_POST['process_auto_create'])  ? (array) $_POST['process_auto_create']  : [];
        $p_emails_json = isset($_POST['process_emails_json'])  ? (array) $_POST['process_emails_json']  : [];
        $p_list_ids    = isset($_POST['process_list_id'])      ? (array) $_POST['process_list_id']      : [];
        $p_final       = isset($_POST['process_final'])        ? (array) $_POST['process_final']        : [];
        // How each step after step 1 gets completed (approval / user action).
        $p_completion  = [];
        foreach (['completion', 'approver_type', 'approver_role', 'approver_user', 'action_label', 'action_url', 'action_mode'] as $f) {
            $p_completion[$f] = isset($_POST['process_' . $f]) ? (array) $_POST['process_' . $f] : [];
        }
        // The "Action update" multi-select posts as process_action_updates[<row>][].
        $p_updates = isset($_POST['process_action_updates']) ? (array) $_POST['process_action_updates'] : [];
        $process = [];
        foreach ($p_titles as $i => $pt) {
            $pt = sanitize_text_field(wp_unslash($pt));
            $pd = isset($p_descs[$i]) ? sanitize_textarea_field(wp_unslash($p_descs[$i])) : '';
            if ($pt === '' && $pd === '') continue;
            $raw_completion = [];
            foreach ($p_completion as $f => $vals) {
                $raw_completion[$f] = wp_unslash($vals[$i] ?? '');
            }
            $raw_completion['action_updates'] = isset($p_updates[$i]) && is_array($p_updates[$i]) ? wp_unslash($p_updates[$i]) : [];
            $process[] = array_merge([
                'title'       => $pt,
                'desc'        => $pd,
                'role'        => sanitize_key(wp_unslash($p_roles[$i] ?? '')),
                'auto_create' => !empty($p_auto[$i]) ? 1 : 0,
                'list_id'     => absint($p_list_ids[$i] ?? 0),
                'emails'      => self::sanitize_step_emails($p_emails_json[$i] ?? '[]'),
                'final'       => !empty($p_final[$i]) ? 1 : 0,
            ], self::sanitize_completion($raw_completion));
        }
        // Always ends with the "approved" step (see finalize_process()).
        $process = self::finalize_process($process, true);

        // Contract.
        $contract = [
            'enabled'        => !empty($_POST['contract_enabled']) ? 1 : 0,
            'title'          => sanitize_text_field(wp_unslash($_POST['contract_title'] ?? '')),
            'body'           => wp_kses_post(wp_unslash($_POST['contract_body'] ?? '')),
            'require_accept' => !empty($_POST['contract_require']) ? 1 : 0,
        ] + self::sanitize_contract_picks(
            // The marker says the commission list was sent (an empty list posts nothing else).
            isset($_POST['contract_commissions_present']) ? (array) wp_unslash($_POST['contract_commissions'] ?? []) : null,
            isset($_POST['contract_service']) ? wp_unslash($_POST['contract_service']) : null,
            self::get_contract($posting_id)
        ) + [
            'dashboard_access' => self::sanitize_dashboard_access(
                isset($_POST['contract_dashboard_present']) ? (array) wp_unslash($_POST['contract_dashboard'] ?? []) : null,
                self::get_contract($posting_id)['dashboard_access']
            ),
            'role' => self::sanitize_contract_role(
                isset($_POST['contract_role']) ? wp_unslash($_POST['contract_role']) : null,
                self::get_contract($posting_id)['role']
            ),
        ];

        $postarr = [
            'post_title'  => $title,
            'post_type'   => self::CPT,
            'post_status' => $status === 'draft' ? 'draft' : 'publish',
        ];

        if ($posting_id) {
            $postarr['ID'] = $posting_id;
            wp_update_post($postarr);
        } else {
            $posting_id = wp_insert_post($postarr);
            if (is_wp_error($posting_id) || !$posting_id) {
                wp_send_json_error(['message' => 'Could not create posting'], 500);
            }
        }

        update_post_meta($posting_id, self::META_STATUS, $status);
        update_post_meta($posting_id, self::META_FORM_ID, $form_id);
        update_post_meta($posting_id, self::META_LANDING, $landing);

        // Ensure the landing page exists (always) + thank-you page (only in page mode).
        $this->ensure_owned_page($posting_id, 'landing', $title);
        if ($ty_mode === 'page') {
            $ty_page = $this->ensure_owned_page($posting_id, 'thankyou', $title . ' — ' . __('Thank You', 'email-manager'));
            $thankyou['page_id'] = $ty_page;
        }
        update_post_meta($posting_id, self::META_THANKYOU, $thankyou);
        update_post_meta($posting_id, self::META_PROCESS, $process);
        update_post_meta($posting_id, self::META_CONTRACT, $contract);
        // Role Access (step 1): earning this role enrols the member in the contract.
        update_post_meta($posting_id, self::META_ENROLL_ROLE, self::sanitize_contract_role(
            isset($_POST['contract_enroll_role']) ? wp_unslash($_POST['contract_enroll_role']) : null,
            (string) get_post_meta($posting_id, self::META_ENROLL_ROLE, true)
        ));
        // Job board postings: only touched when the editor sent them (the
        // hidden marker), so clients that don't know the tab keep what's stored.
        if (isset($_POST['boards_present'])) {
            update_post_meta($posting_id, self::META_BOARDS, self::boards_from_arrays(wp_unslash($_POST)));
        }

        // Mirror thank-you behavior onto the linked form so chat-frontend JS
        // handles it, and mark the form as an application form.
        if ($form_id && get_post($form_id)) {
            if ($ty_mode === 'redirect' && $thankyou['redirect_url']) {
                update_post_meta($form_id, '_chat_form_redirect_url', $thankyou['redirect_url']);
                update_post_meta($form_id, '_chat_form_thank_you_message', '');
            } elseif ($ty_mode === 'page' && !empty($thankyou['page_id'])) {
                update_post_meta($form_id, '_chat_form_redirect_url', get_permalink($thankyou['page_id']));
                update_post_meta($form_id, '_chat_form_thank_you_message', '');
            } else {
                update_post_meta($form_id, '_chat_form_thank_you_message', $thankyou['message']);
                update_post_meta($form_id, '_chat_form_redirect_url', '');
            }
            update_post_meta($form_id, EM_Applications::FORM_PURPOSE_META, EM_Applications::PURPOSE_VALUE);
        }

        wp_send_json_success(array_merge(
            $this->posting_payload(get_post($posting_id)),
            ['card' => self::render_card(get_post($posting_id))]
        ));
    }

    /** Shared editor payload for a posting. */
    private function posting_payload($posting)
    {
        $id      = $posting->ID;
        $form_id = (int) get_post_meta($id, self::META_FORM_ID, true);
        $form    = $form_id ? get_post($form_id) : null;
        $lp      = (int) get_post_meta($id, self::META_LANDING_PAGE, true);
        $typ     = (int) get_post_meta($id, self::META_THANKYOU_PAGE, true);

        return [
            'id'          => $id,
            'title'       => $posting->post_title,
            'status'      => get_post_meta($id, self::META_STATUS, true) ?: 'open',
            'form_id'     => $form_id,
            'form_title'  => $form ? ($form->post_title ?: __('(untitled form)', 'email-manager')) : '',
            'form_type'   => $form ? (get_post_type($form_id) === 'chat_form' ? 'Chat' : 'Form') : '',
            'shortcode'   => self::form_shortcode($id),
            'landing'     => self::get_landing($id),
            'thankyou'    => self::get_thankyou($id),
            'process'     => self::get_process($id),
            'contract'    => self::get_contract($id),
            'boards'      => self::boards_for_editor($posting),
            'enroll_role' => (string) get_post_meta($id, self::META_ENROLL_ROLE, true),
            'lists'       => self::get_lists(),
            'roles'       => self::get_role_choices(),
            'form_fields' => $form_id ? self::get_form_fields($form_id) : [],
            'landing_url' => self::landing_url($posting),
            'landing_edit_url'  => $lp  ? admin_url('post.php?post=' . $lp  . '&action=edit') : '',
            'thankyou_edit_url' => $typ ? admin_url('post.php?post=' . $typ . '&action=edit') : '',
            'form_edit_url'     => $form_id ? admin_url('post.php?post=' . $form_id . '&action=edit') : '',
        ];
    }

    public function ajax_get_form_fields()
    {
        $this->guard();
        $form_id = absint($_POST['form_id'] ?? 0);
        wp_send_json_success(['fields' => $form_id ? self::get_form_fields($form_id) : []]);
    }

    public function ajax_create_list()
    {
        $this->guard();
        if (!function_exists('em_create_list')) wp_send_json_error(['message' => 'Lists unavailable'], 500);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') $name = __('Applicants', 'email-manager');
        $list_id = em_create_list($name, __('Created from an application posting.', 'email-manager'), 'applicants');
        if (!$list_id) wp_send_json_error(['message' => 'Could not create list'], 500);
        wp_send_json_success(['id' => (int) $list_id, 'name' => $name, 'count' => 0]);
    }

    /** Creates a new WP role (an Apply Process step's "sub role" for
     *  tracking applicant status) with the requested feature-access
     *  capabilities. If a role with the same slug already exists, it's
     *  returned as-is rather than overwriting its capabilities. */
    public function ajax_create_role()
    {
        $this->guard();
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') wp_send_json_error(['message' => 'Enter a role name'], 400);
        $slug = sanitize_key($name);
        if ($slug === '') wp_send_json_error(['message' => 'Enter a valid role name'], 400);

        if (!wp_roles()->is_role($slug)) {
            $requested = isset($_POST['capabilities']) ? (array) $_POST['capabilities'] : [];
            $allowed   = array_keys(self::capability_choices());
            $caps      = ['read' => true];
            foreach ($requested as $cap) {
                $cap = sanitize_key($cap);
                if (in_array($cap, $allowed, true)) $caps[$cap] = true;
            }
            add_role($slug, $name, $caps);
            // Only a brand-new role gets sections; an existing role of that name is left exactly as it was.
            $sections = self::sanitize_dashboard_access(isset($_POST['dashboard']) ? (array) wp_unslash($_POST['dashboard']) : [], []);
            self::set_role_dashboard_access($slug, $sections);
        }

        wp_send_json_success(self::role_entry($slug));
    }

    /** Edit an existing role's access (capabilities + dashboard sections) from the Contract tab. */
    public function ajax_update_role_access()
    {
        $this->guard();
        $result = self::apply_role_access(
            wp_unslash($_POST['role'] ?? ''),
            isset($_POST['capabilities']) ? (array) wp_unslash($_POST['capabilities']) : [],
            isset($_POST['dashboard_present']) ? (array) wp_unslash($_POST['dashboard'] ?? []) : null
        );
        if (isset($result['error'])) wp_send_json_error(['message' => $result['error']], $result['status']);
        wp_send_json_success($result);
    }

    public function ajax_get_posting()
    {
        $this->guard();
        $id = absint($_POST['posting_id'] ?? 0);
        $posting = $id ? get_post($id) : null;
        if (!$posting || $posting->post_type !== self::CPT) wp_send_json_error(['message' => 'Not found'], 404);
        wp_send_json_success($this->posting_payload($posting));
    }

    public function ajax_delete_posting()
    {
        $this->guard();
        $id = absint($_POST['posting_id'] ?? 0);
        $posting = $id ? get_post($id) : null;
        if (!$posting || $posting->post_type !== self::CPT) wp_send_json_error(['message' => 'Not found'], 404);
        wp_trash_post($id);
        wp_send_json_success(['id' => $id]);
    }

    public function ajax_create_posting_form()
    {
        $this->guard();
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        if ($title === '') $title = __('Application Form', 'email-manager');

        // Questions come as parallel label[]/type[] arrays from the builder popup.
        $labels = isset($_POST['q_label']) ? (array) $_POST['q_label'] : [];
        $types  = isset($_POST['q_type'])  ? (array) $_POST['q_type']  : [];
        $allowed_types = ['text', 'email', 'telephone', 'multiple', 'file'];

        $questions = [];
        foreach ($labels as $i => $label) {
            $label = sanitize_text_field(wp_unslash($label));
            if ($label === '') continue;
            $type = isset($types[$i]) && in_array($types[$i], $allowed_types, true) ? $types[$i] : 'text';
            $questions[] = ['text' => $label, 'type' => $type, 'validation' => ['required' => $type === 'email']];
        }
        if (empty($questions)) {
            $questions = [
                ['text' => __('What is your full name?', 'email-manager'), 'type' => 'text',  'validation' => ['required' => true]],
                ['text' => __('What is your email address?', 'email-manager'), 'type' => 'email', 'validation' => ['required' => true]],
            ];
        }

        $form_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'chat_form',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($form_id) || !$form_id) wp_send_json_error(['message' => 'Could not create form'], 500);

        update_post_meta($form_id, '_chat_form_questions', $questions);
        update_post_meta($form_id, EM_Applications::FORM_PURPOSE_META, EM_Applications::PURPOSE_VALUE);

        wp_send_json_success([
            'id'       => $form_id,
            'title'    => $title,
            'type'     => 'chat_form',
            'edit_url' => admin_url('post.php?post=' . $form_id . '&action=edit'),
        ]);
    }

    public function ajax_get_analytics()
    {
        $this->guard();
        $id = absint($_POST['posting_id'] ?? 0);
        $posting = $id ? get_post($id) : null;
        if (!$posting || $posting->post_type !== self::CPT) wp_send_json_error(['message' => 'Not found'], 404);

        $views = self::get_views($id);
        $subs  = self::get_submissions($id);
        wp_send_json_success([
            'title'       => $posting->post_title,
            'views'       => $views,
            'submissions' => $subs,
            'conversion'  => self::conversion($views, $subs),
            'landing_url' => self::landing_url($posting),
            'series'      => self::daily_series($id, 14),
            'applicants'  => self::get_applicants($id),
            'process'     => self::get_process($id),
            'contract_role' => self::get_contract($id)['role'],
            'boards'      => self::get_board_analytics($posting),
        ]);
    }

    /** Move an applicant to one of this posting's own Apply Process steps
     *  from the analytics drawer's applicant list. */
    public function ajax_advance_applicant()
    {
        $this->guard();
        $posting_id  = absint($_POST['posting_id'] ?? 0);
        $submission_id = absint($_POST['submission_id'] ?? 0);
        $step_index  = isset($_POST['step_index']) ? (int) $_POST['step_index'] : -1;

        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT || !$submission_id || $step_index < 0) {
            wp_send_json_error(['message' => 'Invalid request'], 400);
        }

        if (!self::submission_belongs($submission_id, $posting_id)) {
            wp_send_json_error(['message' => 'Applicant not found'], 404);
        }
        if (!$this->advance_applicant_to_step($posting, $submission_id, $step_index)) {
            wp_send_json_error(['message' => 'Unknown step'], 400);
        }

        wp_send_json_success(['applicants' => self::get_applicants($posting_id)]);
    }

    /** Move several applicants to one of this posting's steps at once (the analytics list's bulk bar). */
    public function ajax_bulk_advance_applicants()
    {
        $this->guard();
        $result = $this->bulk_advance(
            absint($_POST['posting_id'] ?? 0),
            isset($_POST['step_index']) ? (int) $_POST['step_index'] : -1,
            isset($_POST['submission_ids']) ? (array) $_POST['submission_ids'] : []
        );
        if (isset($result['error'])) wp_send_json_error(['message' => $result['error']], $result['status']);
        wp_send_json_success($result);
    }

    /**
     * Runs each applicant through the step's effects (role, list, emails…)
     * exactly like a single Move. Applicants already on that step are skipped
     * so nobody gets the same emails twice; ones that don't belong to this
     * posting are ignored. Returns the refreshed list and the counts.
     */
    private function bulk_advance($posting_id, $step_index, array $ids)
    {
        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT) return ['error' => 'Not found', 'status' => 404];
        $process = self::get_process($posting_id);
        if ($step_index < 0 || !isset($process[$step_index])) return ['error' => 'Unknown step', 'status' => 400];
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, 200);
        if (!$ids) return ['error' => 'Select at least one applicant', 'status' => 400];

        $moved = 0;
        $skipped = 0;
        foreach ($ids as $sid) {
            if (!self::submission_belongs($sid, $posting_id) || self::current_step_index($sid, $process) === $step_index) {
                $skipped++;
                continue;
            }
            if ($this->advance_applicant_to_step($posting, $sid, $step_index)) $moved++; else $skipped++;
        }
        return ['applicants' => self::get_applicants($posting_id), 'moved' => $moved, 'skipped' => $skipped];
    }

    public function rest_bulk_advance_applicants(WP_REST_Request $request)
    {
        $result = $this->bulk_advance(
            absint($request->get_param('id')),
            $request->get_param('step_index') !== null ? (int) $request->get_param('step_index') : -1,
            (array) $request->get_param('submission_ids')
        );
        if (isset($result['error'])) return new WP_Error('em_bulk', $result['error'], ['status' => $result['status']]);
        return new WP_REST_Response($result, 200);
    }

    /** Is this application tagged to this posting? */
    private static function submission_belongs($submission_id, $posting_id)
    {
        return $submission_id && (int) get_post_meta($submission_id, self::SUBMISSION_TAG, true) === (int) $posting_id;
    }

    /** Admin sign-off from the analytics list: approves an approval step, or
     *  confirms a user-action step by hand, then moves the applicant on. */
    public function ajax_complete_step()
    {
        $this->guard();
        $posting_id    = absint($_POST['posting_id'] ?? 0);
        $submission_id = absint($_POST['submission_id'] ?? 0);

        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT || !self::submission_belongs($submission_id, $posting_id)) {
            wp_send_json_error(['message' => 'Applicant not found'], 404);
        }
        if (!$this->complete_step_as_admin($posting, $submission_id)) {
            wp_send_json_error(['message' => 'Nothing to complete'], 400);
        }
        wp_send_json_success(['applicants' => self::get_applicants($posting_id)]);
    }

    private function complete_step_as_admin($posting, $submission_id)
    {
        $process = self::get_process($posting->ID);
        $index   = self::current_step_index($submission_id, $process);
        if ($index < 0) return false;
        $method = ($index >= 1 && $process[$index]['completion'] === 'approval') ? 'approval' : 'manual';
        return $this->complete_step($posting, $submission_id, $method, get_current_user_id());
    }

    /** Re-run the auto-detected user-action checks for this posting now
     *  (they also run hourly on their own). */
    public function ajax_check_actions()
    {
        $this->guard();
        $posting_id = absint($_POST['posting_id'] ?? 0);
        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT) wp_send_json_error(['message' => 'Not found'], 404);
        $moved = $this->check_actions_for_posting($posting);
        wp_send_json_success(['moved' => $moved, 'applicants' => self::get_applicants($posting_id)]);
    }

    /** Members matching a search, for picking a step's specific approver. */
    public static function search_members($q, $limit = 10)
    {
        $args = ['number' => (int) $limit, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($q !== '') {
            $args['search']         = '*' . $q . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'display_name', 'user_email'];
        }
        $out = [];
        foreach (get_users($args) as $u) {
            $out[] = ['id' => (int) $u->ID, 'name' => $u->display_name ?: $u->user_login, 'email' => $u->user_email];
        }
        return $out;
    }

    public function ajax_search_members()
    {
        $this->guard();
        $q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
        wp_send_json_success(['members' => self::search_members($q)]);
    }

    /* ================================================================
       REST routes — JSON-only siblings of the ajax_* handlers above, so
       they never wp_send_json_*()/wp_die() and can be safely dispatched
       in-process (rest_do_request()) from a cross-site proxy.
       ================================================================ */

    public function register_rest_routes()
    {
        $perm = function () {
            return current_user_can('manage_options');
        };

        register_rest_route('em/v1', '/postings/search-forms', [
            'methods' => 'GET', 'callback' => [$this, 'rest_search_forms'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings', [
            'methods' => 'POST', 'callback' => [$this, 'rest_save_posting'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [$this, 'rest_get_posting'],    'permission_callback' => $perm ],
            [ 'methods' => 'DELETE', 'callback' => [$this, 'rest_delete_posting'], 'permission_callback' => $perm ],
        ]);
        register_rest_route('em/v1', '/postings/create-form', [
            'methods' => 'POST', 'callback' => [$this, 'rest_create_posting_form'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/form-fields', [
            'methods' => 'GET', 'callback' => [$this, 'rest_get_form_fields'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/create-list', [
            'methods' => 'POST', 'callback' => [$this, 'rest_create_list'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/create-role', [
            'methods' => 'POST', 'callback' => [$this, 'rest_create_role'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/update-role-access', [
            'methods' => 'POST', 'callback' => [$this, 'rest_update_role_access'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/(?P<id>\d+)/analytics', [
            'methods' => 'GET', 'callback' => [$this, 'rest_get_analytics'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/(?P<id>\d+)/applicants/bulk-advance', [
            'methods' => 'POST', 'callback' => [$this, 'rest_bulk_advance_applicants'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/(?P<id>\d+)/applicants/(?P<submission_id>\d+)/advance', [
            'methods' => 'POST', 'callback' => [$this, 'rest_advance_applicant'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/(?P<id>\d+)/applicants/(?P<submission_id>\d+)/complete', [
            'methods' => 'POST', 'callback' => [$this, 'rest_complete_step'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/(?P<id>\d+)/check-actions', [
            'methods' => 'POST', 'callback' => [$this, 'rest_check_actions'], 'permission_callback' => $perm,
        ]);
        register_rest_route('em/v1', '/postings/search-members', [
            'methods' => 'GET', 'callback' => [$this, 'rest_search_members'], 'permission_callback' => $perm,
        ]);
    }

    public function rest_search_forms(WP_REST_Request $request)
    {
        $q = sanitize_text_field((string) $request->get_param('q'));
        return new WP_REST_Response(['forms' => self::get_form_choices($q, 20)], 200);
    }

    public function rest_save_posting(WP_REST_Request $request)
    {
        $posting_id = absint($request->get_param('posting_id'));
        $title      = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') $title = __('Untitled Posting', 'email-manager');

        $status  = in_array($request->get_param('status'), ['draft', 'open', 'closed'], true) ? $request->get_param('status') : 'open';
        $form_id = absint($request->get_param('form_id'));

        // Colours are no longer edited in the popup: keep what the posting has unless a colour is actually sent.
        $landing = self::landing_colours($posting_id);
        if ($request->get_param('landing_accent') !== null)  $landing['accent']  = sanitize_hex_color((string) $request->get_param('landing_accent'))  ?: self::default_landing()['accent'];
        if ($request->get_param('landing_accent2') !== null) $landing['accent2'] = sanitize_hex_color((string) $request->get_param('landing_accent2')) ?: self::default_landing()['accent2'];

        $ty_mode  = in_array($request->get_param('ty_mode'), ['message', 'redirect', 'page'], true) ? $request->get_param('ty_mode') : 'message';
        $thankyou = [
            'mode'         => $ty_mode,
            'message'      => wp_kses_post((string) $request->get_param('ty_message')),
            'redirect_url' => esc_url_raw((string) $request->get_param('ty_redirect_url')),
            'page_id'      => 0,
        ];

        $p_titles      = (array) $request->get_param('process_title');
        $p_descs       = (array) $request->get_param('process_desc');
        $p_roles       = (array) $request->get_param('process_role');
        $p_auto        = (array) $request->get_param('process_auto_create');
        $p_emails_json = (array) $request->get_param('process_emails_json');
        $p_list_ids    = (array) $request->get_param('process_list_id');
        $p_final       = (array) $request->get_param('process_final');
        $p_completion  = [];
        foreach (['completion', 'approver_type', 'approver_role', 'approver_user', 'action_label', 'action_url', 'action_mode'] as $f) {
            $p_completion[$f] = (array) $request->get_param('process_' . $f);
        }
        $p_updates = (array) $request->get_param('process_action_updates');
        $process = [];
        foreach ($p_titles as $i => $pt) {
            $pt = sanitize_text_field((string) $pt);
            $pd = isset($p_descs[$i]) ? sanitize_textarea_field((string) $p_descs[$i]) : '';
            if ($pt === '' && $pd === '') continue;
            $raw_completion = [];
            foreach ($p_completion as $f => $vals) {
                $raw_completion[$f] = $vals[$i] ?? '';
            }
            $raw_completion['action_updates'] = isset($p_updates[$i]) && is_array($p_updates[$i]) ? $p_updates[$i] : [];
            $process[] = array_merge([
                'title'       => $pt,
                'desc'        => $pd,
                'role'        => sanitize_key((string) ($p_roles[$i] ?? '')),
                'auto_create' => !empty($p_auto[$i]) ? 1 : 0,
                'list_id'     => absint($p_list_ids[$i] ?? 0),
                'emails'      => self::sanitize_step_emails($p_emails_json[$i] ?? '[]'),
                'final'       => !empty($p_final[$i]) ? 1 : 0,
            ], self::sanitize_completion($raw_completion));
        }
        // Always ends with the "approved" step (see finalize_process()).
        $process = self::finalize_process($process, true);

        $contract = [
            'enabled'        => $request->get_param('contract_enabled') ? 1 : 0,
            'title'          => sanitize_text_field((string) $request->get_param('contract_title')),
            'body'           => wp_kses_post((string) $request->get_param('contract_body')),
            'require_accept' => $request->get_param('contract_require') ? 1 : 0,
        ] + self::sanitize_contract_picks(
            $request->get_param('contract_commissions_present') !== null ? (array) $request->get_param('contract_commissions') : null,
            $request->get_param('contract_service'),
            self::get_contract($posting_id)
        ) + [
            'dashboard_access' => self::sanitize_dashboard_access(
                $request->get_param('contract_dashboard_present') !== null ? (array) $request->get_param('contract_dashboard') : null,
                self::get_contract($posting_id)['dashboard_access']
            ),
            'role' => self::sanitize_contract_role($request->get_param('contract_role'), self::get_contract($posting_id)['role']),
        ];

        $postarr = [
            'post_title'  => $title,
            'post_type'   => self::CPT,
            'post_status' => $status === 'draft' ? 'draft' : 'publish',
        ];

        if ($posting_id) {
            $postarr['ID'] = $posting_id;
            wp_update_post($postarr);
        } else {
            $posting_id = wp_insert_post($postarr);
            if (is_wp_error($posting_id) || !$posting_id) {
                return new WP_Error('em_save_failed', 'Could not create posting', ['status' => 500]);
            }
        }

        update_post_meta($posting_id, self::META_STATUS, $status);
        update_post_meta($posting_id, self::META_FORM_ID, $form_id);
        update_post_meta($posting_id, self::META_LANDING, $landing);

        $this->ensure_owned_page($posting_id, 'landing', $title);
        if ($ty_mode === 'page') {
            $ty_page = $this->ensure_owned_page($posting_id, 'thankyou', $title . ' — ' . __('Thank You', 'email-manager'));
            $thankyou['page_id'] = $ty_page;
        }
        update_post_meta($posting_id, self::META_THANKYOU, $thankyou);
        update_post_meta($posting_id, self::META_PROCESS, $process);
        update_post_meta($posting_id, self::META_CONTRACT, $contract);
        update_post_meta($posting_id, self::META_ENROLL_ROLE, self::sanitize_contract_role(
            $request->get_param('contract_enroll_role'),
            (string) get_post_meta($posting_id, self::META_ENROLL_ROLE, true)
        ));
        if ($request->get_param('boards_present') !== null) {
            update_post_meta($posting_id, self::META_BOARDS, self::boards_from_arrays((array) $request->get_params()));
        }

        if ($form_id && get_post($form_id)) {
            if ($ty_mode === 'redirect' && $thankyou['redirect_url']) {
                update_post_meta($form_id, '_chat_form_redirect_url', $thankyou['redirect_url']);
                update_post_meta($form_id, '_chat_form_thank_you_message', '');
            } elseif ($ty_mode === 'page' && !empty($thankyou['page_id'])) {
                update_post_meta($form_id, '_chat_form_redirect_url', get_permalink($thankyou['page_id']));
                update_post_meta($form_id, '_chat_form_thank_you_message', '');
            } else {
                update_post_meta($form_id, '_chat_form_thank_you_message', $thankyou['message']);
                update_post_meta($form_id, '_chat_form_redirect_url', '');
            }
            update_post_meta($form_id, EM_Applications::FORM_PURPOSE_META, EM_Applications::PURPOSE_VALUE);
        }

        return new WP_REST_Response(array_merge(
            $this->posting_payload(get_post($posting_id)),
            ['card' => self::render_card(get_post($posting_id))]
        ), 200);
    }

    public function rest_get_form_fields(WP_REST_Request $request)
    {
        $form_id = absint($request->get_param('form_id'));
        return new WP_REST_Response(['fields' => $form_id ? self::get_form_fields($form_id) : []], 200);
    }

    public function rest_create_list(WP_REST_Request $request)
    {
        if (!function_exists('em_create_list')) return new WP_Error('em_unavailable', 'Lists unavailable', ['status' => 500]);
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') $name = __('Applicants', 'email-manager');
        $list_id = em_create_list($name, __('Created from an application posting.', 'email-manager'), 'applicants');
        if (!$list_id) return new WP_Error('em_save_failed', 'Could not create list', ['status' => 500]);
        return new WP_REST_Response(['id' => (int) $list_id, 'name' => $name, 'count' => 0], 200);
    }

    public function rest_create_role(WP_REST_Request $request)
    {
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') return new WP_Error('em_bad_request', 'Enter a role name', ['status' => 400]);
        $slug = sanitize_key($name);
        if ($slug === '') return new WP_Error('em_bad_request', 'Enter a valid role name', ['status' => 400]);

        if (!wp_roles()->is_role($slug)) {
            $requested = (array) $request->get_param('capabilities');
            $allowed   = array_keys(self::capability_choices());
            $caps      = ['read' => true];
            foreach ($requested as $cap) {
                $cap = sanitize_key($cap);
                if (in_array($cap, $allowed, true)) $caps[$cap] = true;
            }
            add_role($slug, $name, $caps);
            $sections = self::sanitize_dashboard_access((array) $request->get_param('dashboard'), []);
            self::set_role_dashboard_access($slug, $sections);
        }

        return new WP_REST_Response(self::role_entry($slug), 200);
    }

    public function rest_update_role_access(WP_REST_Request $request)
    {
        $result = self::apply_role_access(
            (string) $request->get_param('role'),
            (array) $request->get_param('capabilities'),
            $request->get_param('dashboard_present') !== null ? (array) $request->get_param('dashboard') : null
        );
        if (isset($result['error'])) return new WP_Error('em_role_access', $result['error'], ['status' => $result['status']]);
        return new WP_REST_Response($result, 200);
    }

    public function rest_get_posting(WP_REST_Request $request)
    {
        $id = absint($request->get_param('id'));
        $posting = $id ? get_post($id) : null;
        if (!$posting || $posting->post_type !== self::CPT) return new WP_Error('em_not_found', 'Not found', ['status' => 404]);
        return new WP_REST_Response($this->posting_payload($posting), 200);
    }

    public function rest_delete_posting(WP_REST_Request $request)
    {
        $id = absint($request->get_param('id'));
        $posting = $id ? get_post($id) : null;
        if (!$posting || $posting->post_type !== self::CPT) return new WP_Error('em_not_found', 'Not found', ['status' => 404]);
        wp_trash_post($id);
        return new WP_REST_Response(['id' => $id], 200);
    }

    public function rest_create_posting_form(WP_REST_Request $request)
    {
        $title = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') $title = __('Application Form', 'email-manager');

        $labels = (array) $request->get_param('q_label');
        $types  = (array) $request->get_param('q_type');
        $allowed_types = ['text', 'email', 'telephone', 'multiple', 'file'];

        $questions = [];
        foreach ($labels as $i => $label) {
            $label = sanitize_text_field((string) $label);
            if ($label === '') continue;
            $type = isset($types[$i]) && in_array($types[$i], $allowed_types, true) ? $types[$i] : 'text';
            $questions[] = ['text' => $label, 'type' => $type, 'validation' => ['required' => $type === 'email']];
        }
        if (empty($questions)) {
            $questions = [
                ['text' => __('What is your full name?', 'email-manager'), 'type' => 'text',  'validation' => ['required' => true]],
                ['text' => __('What is your email address?', 'email-manager'), 'type' => 'email', 'validation' => ['required' => true]],
            ];
        }

        $form_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'chat_form',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($form_id) || !$form_id) return new WP_Error('em_save_failed', 'Could not create form', ['status' => 500]);

        update_post_meta($form_id, '_chat_form_questions', $questions);
        update_post_meta($form_id, EM_Applications::FORM_PURPOSE_META, EM_Applications::PURPOSE_VALUE);

        return new WP_REST_Response([
            'id'       => $form_id,
            'title'    => $title,
            'type'     => 'chat_form',
            'edit_url' => admin_url('post.php?post=' . $form_id . '&action=edit'),
        ], 200);
    }

    public function rest_get_analytics(WP_REST_Request $request)
    {
        $id = absint($request->get_param('id'));
        $posting = $id ? get_post($id) : null;
        if (!$posting || $posting->post_type !== self::CPT) return new WP_Error('em_not_found', 'Not found', ['status' => 404]);

        $views = self::get_views($id);
        $subs  = self::get_submissions($id);
        return new WP_REST_Response([
            'title'       => $posting->post_title,
            'views'       => $views,
            'submissions' => $subs,
            'conversion'  => self::conversion($views, $subs),
            'landing_url' => self::landing_url($posting),
            'series'      => self::daily_series($id, 14),
            'applicants'  => self::get_applicants($id),
            'process'     => self::get_process($id),
            'contract_role' => self::get_contract($id)['role'],
            'boards'      => self::get_board_analytics($posting),
        ], 200);
    }

    public function rest_advance_applicant(WP_REST_Request $request)
    {
        $posting_id    = absint($request->get_param('id'));
        $submission_id = absint($request->get_param('submission_id'));
        $step_index    = (int) $request->get_param('step_index');

        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT || !$submission_id || $step_index < 0) {
            return new WP_Error('em_bad_request', 'Invalid request', ['status' => 400]);
        }
        if (!self::submission_belongs($submission_id, $posting_id)) {
            return new WP_Error('em_not_found', 'Applicant not found', ['status' => 404]);
        }
        if (!$this->advance_applicant_to_step($posting, $submission_id, $step_index)) {
            return new WP_Error('em_bad_step', 'Unknown step', ['status' => 400]);
        }
        return new WP_REST_Response(['applicants' => self::get_applicants($posting_id)], 200);
    }

    public function rest_complete_step(WP_REST_Request $request)
    {
        $posting_id    = absint($request->get_param('id'));
        $submission_id = absint($request->get_param('submission_id'));
        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT || !self::submission_belongs($submission_id, $posting_id)) {
            return new WP_Error('em_not_found', 'Applicant not found', ['status' => 404]);
        }
        if (!$this->complete_step_as_admin($posting, $submission_id)) {
            return new WP_Error('em_bad_request', 'Nothing to complete', ['status' => 400]);
        }
        return new WP_REST_Response(['applicants' => self::get_applicants($posting_id)], 200);
    }

    public function rest_check_actions(WP_REST_Request $request)
    {
        $posting_id = absint($request->get_param('id'));
        $posting = $posting_id ? get_post($posting_id) : null;
        if (!$posting || $posting->post_type !== self::CPT) return new WP_Error('em_not_found', 'Not found', ['status' => 404]);
        $moved = $this->check_actions_for_posting($posting);
        return new WP_REST_Response(['moved' => $moved, 'applicants' => self::get_applicants($posting_id)], 200);
    }

    public function rest_search_members(WP_REST_Request $request)
    {
        return new WP_REST_Response(['members' => self::search_members(sanitize_text_field((string) $request->get_param('q')))], 200);
    }

    /* ================================================================
       Admin render
       ================================================================ */

    public static function render_panel()
    {
        $postings  = self::get_postings();
        $total_views = 0; $total_subs = 0; $open = 0;
        foreach ($postings as $p) {
            $total_views += self::get_views($p->ID);
            $total_subs  += self::get_submissions($p->ID);
            if ((get_post_meta($p->ID, self::META_STATUS, true) ?: 'open') === 'open') $open++;
        }
        $conv = self::conversion($total_views, $total_subs);
        ?>
        <?php /* Deliberately NOT a .gdc-subtab-panel: the Applications tab has no sub-tabs any more, and the page-wide
                  sub-tab handlers (e.g. on the Email tab) hide every .gdc-subtab-panel, which would leave this
                  panel hidden with nothing to bring it back. */ ?>
        <div class="em-postings-panel">

            <div class="em-kpi-grid">
                <div class="em-kpi" style="--em-i:0;">
                    <div class="em-kpi__label"><?php esc_html_e('Postings', 'email-manager'); ?></div>
                    <div class="em-kpi__value"><?php echo esc_html(number_format_i18n(count($postings))); ?></div>
                    <div class="em-kpi__hint"><?php echo esc_html(sprintf(_n('%d open', '%d open', $open, 'email-manager'), $open)); ?></div>
                </div>
                <div class="em-kpi" style="--em-i:1;">
                    <div class="em-kpi__label"><?php esc_html_e('Landing Views', 'email-manager'); ?></div>
                    <div class="em-kpi__value"><?php echo esc_html(number_format_i18n($total_views)); ?></div>
                    <div class="em-kpi__hint"><?php esc_html_e('across all postings', 'email-manager'); ?></div>
                </div>
                <div class="em-kpi" style="--em-i:2;">
                    <div class="em-kpi__label"><?php esc_html_e('Applications', 'email-manager'); ?></div>
                    <div class="em-kpi__value"><?php echo esc_html(number_format_i18n($total_subs)); ?></div>
                    <div class="em-kpi__hint"><?php esc_html_e('submitted', 'email-manager'); ?></div>
                </div>
                <div class="em-kpi" style="--em-i:3;">
                    <div class="em-kpi__label"><?php esc_html_e('Conversion', 'email-manager'); ?></div>
                    <div class="em-kpi__value"><?php echo esc_html($conv); ?>%</div>
                    <div class="em-kpi__hint"><?php esc_html_e('views → applications', 'email-manager'); ?></div>
                </div>
            </div>

            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Application Postings', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Each posting is a complete funnel: a landing page, its own form, a thank-you experience, emails, and analytics.', 'email-manager'); ?></p>
                    </div>
                    <button type="button" class="button button-primary em-posting-new">
                        <span class="dashicons dashicons-plus-alt" style="margin-top:3px;"></span>
                        <?php esc_html_e('New Posting', 'email-manager'); ?>
                    </button>
                </div>

                <div class="em-posting-grid" id="em-posting-grid">
                    <?php if (empty($postings)): ?>
                        <div class="em-empty em-posting-empty">
                            <div class="em-empty__icon"><span class="dashicons dashicons-megaphone"></span></div>
                            <div class="em-empty__title"><?php esc_html_e('No postings yet', 'email-manager'); ?></div>
                            <div><?php esc_html_e('Create your first application posting — it builds its own landing page and analytics automatically.', 'email-manager'); ?></div>
                            <button type="button" class="button button-primary em-posting-new" style="margin-top:16px;">
                                <?php esc_html_e('Create a Posting', 'email-manager'); ?>
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($postings as $i => $p): ?>
                            <?php echo self::render_card($p, $i); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php self::render_editor_modal(); ?>
            <?php self::render_newform_modal(); ?>
            <?php self::render_role_modal(); ?>
            <?php self::render_commission_modal(); ?>
            <?php self::render_service_modal(); ?>
            <?php self::render_email_modal(); ?>
            <?php self::render_analytics_drawer(); ?>
        </div>
        <?php
    }

    public static function render_card($posting, $i = 0)
    {
        if (!$posting) return '';
        $id      = $posting->ID;
        $status  = get_post_meta($id, self::META_STATUS, true) ?: 'open';
        $form_id = (int) get_post_meta($id, self::META_FORM_ID, true);
        $landing = self::get_landing($id);
        $views   = self::get_views($id);
        $subs    = self::get_submissions($id);
        $conv    = self::conversion($views, $subs);
        $url     = self::landing_url($posting);
        $form    = $form_id ? get_post($form_id) : null;
        $accent  = $landing['accent'] ?: '#6366f1';
        $accent2 = $landing['accent2'] ?: '#8b5cf6';

        $status_map = [
            'open'   => ['em-pill--success', __('Open', 'email-manager')],
            'draft'  => ['em-pill--warning', __('Draft', 'email-manager')],
            'closed' => ['em-pill--error',   __('Closed', 'email-manager')],
        ];
        list($pill_cls, $pill_label) = $status_map[$status] ?? $status_map['open'];

        ob_start();
        ?>
        <article class="em-posting-card em-reveal" style="--em-i:<?php echo (int) $i; ?>;--card-accent:<?php echo esc_attr($accent); ?>;--card-accent2:<?php echo esc_attr($accent2); ?>;" data-posting-id="<?php echo esc_attr($id); ?>">
            <div class="em-posting-card__glow"></div>
            <div class="em-posting-card__top">
                <span class="em-pill <?php echo esc_attr($pill_cls); ?>"><?php echo esc_html($pill_label); ?></span>
                <div class="em-posting-card__menu">
                    <button type="button" class="em-posting-act em-posting-analytics" title="<?php esc_attr_e('Analytics', 'email-manager'); ?>"><span class="dashicons dashicons-chart-bar"></span></button>
                    <button type="button" class="em-posting-act em-posting-copy" data-url="<?php echo esc_url($url); ?>" title="<?php esc_attr_e('Copy landing URL', 'email-manager'); ?>"><span class="dashicons dashicons-admin-links"></span></button>
                    <a class="em-posting-act" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e('View landing page', 'email-manager'); ?>"><span class="dashicons dashicons-external"></span></a>
                </div>
            </div>

            <h4 class="em-posting-card__title"><?php echo esc_html($posting->post_title); ?></h4>
            <div class="em-posting-card__meta">
                <span class="dashicons dashicons-feedback"></span>
                <?php if ($form): ?>
                    <?php echo esc_html($form->post_title ?: __('(untitled form)', 'email-manager')); ?>
                <?php else: ?>
                    <em><?php esc_html_e('No form linked', 'email-manager'); ?></em>
                <?php endif; ?>
            </div>

            <div class="em-posting-stats">
                <div class="em-posting-stat">
                    <div class="em-posting-stat__v"><?php echo esc_html(number_format_i18n($views)); ?></div>
                    <div class="em-posting-stat__l"><?php esc_html_e('Views', 'email-manager'); ?></div>
                </div>
                <div class="em-posting-stat">
                    <div class="em-posting-stat__v"><?php echo esc_html(number_format_i18n($subs)); ?></div>
                    <div class="em-posting-stat__l"><?php esc_html_e('Applied', 'email-manager'); ?></div>
                </div>
                <div class="em-posting-stat">
                    <div class="em-posting-stat__v"><?php echo esc_html($conv); ?>%</div>
                    <div class="em-posting-stat__l"><?php esc_html_e('Conv.', 'email-manager'); ?></div>
                </div>
            </div>

            <div class="em-posting-card__foot">
                <button type="button" class="button button-primary em-posting-edit"><?php esc_html_e('Edit', 'email-manager'); ?></button>
                <?php if ($form): ?>
                    <a class="button em-posting-editform" href="<?php echo esc_url(admin_url('post.php?post=' . $form_id . '&action=edit')); ?>"><?php esc_html_e('Edit Form', 'email-manager'); ?></a>
                <?php endif; ?>
                <button type="button" class="button em-posting-delete" title="<?php esc_attr_e('Delete', 'email-manager'); ?>"><span class="dashicons dashicons-trash"></span></button>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function render_editor_modal()
    {
        $dty = self::default_thankyou();
        ?>
        <div class="em-drawer em-modal" id="em-posting-drawer" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close="1"></div>
            <div class="em-modal__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title" id="em-posting-drawer-title"><?php esc_html_e('New Posting', 'email-manager'); ?></h3>
                    <span class="em-pa-sync" id="em-pf-sync" aria-live="polite"></span>
                    <button type="button" class="em-drawer__close" data-close="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-pa-loadbar" id="em-pf-loadbar" hidden aria-hidden="true"></div>
                <div class="em-modal__body">
                    <div class="em-pf-loaderror" id="em-pf-loaderror" hidden>
                        <p><?php esc_html_e('This posting couldn\'t be loaded.', 'email-manager'); ?></p>
                        <button type="button" class="button button-primary em-pf-retry"><?php esc_html_e('Try again', 'email-manager'); ?></button>
                    </div>
                    <form id="em-posting-form" class="em-posting-form">
                        <input type="hidden" name="posting_id" value="0" />

                        <!-- Basics — persistent header above the tabs -->
                        <div class="em-pf-head">
                            <div class="em-pf-head__row">
                                <label class="em-pf-field em-pf-head__title">
                                    <span class="em-pf-label"><?php esc_html_e('Posting Title', 'email-manager'); ?></span>
                                    <input type="text" name="title" placeholder="<?php esc_attr_e('e.g. Community Ambassador 2026', 'email-manager'); ?>" />
                                </label>
                                <label class="em-pf-field em-pf-head__status">
                                    <span class="em-pf-label"><?php esc_html_e('Status', 'email-manager'); ?></span>
                                    <select name="status">
                                        <option value="open"><?php esc_html_e('Open — accepting applications', 'email-manager'); ?></option>
                                        <option value="draft"><?php esc_html_e('Draft — hidden', 'email-manager'); ?></option>
                                        <option value="closed"><?php esc_html_e('Closed — landing shown, form hidden', 'email-manager'); ?></option>
                                    </select>
                                </label>
                            </div>
                            <div class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Application Form', 'email-manager'); ?></span>
                                <div class="em-combo" id="em-form-combo">
                                    <input type="hidden" name="form_id" value="0" />
                                    <div class="em-combo__chosen" hidden>
                                        <span class="em-combo__chosen-text"></span>
                                        <button type="button" class="em-combo__clear" aria-label="<?php esc_attr_e('Clear', 'email-manager'); ?>">&times;</button>
                                    </div>
                                    <div class="em-combo__control">
                                        <span class="dashicons dashicons-search"></span>
                                        <input type="text" class="em-combo__search" autocomplete="off" placeholder="<?php esc_attr_e('Search forms by name…', 'email-manager'); ?>" />
                                    </div>
                                    <div class="em-combo__menu" hidden></div>
                                </div>
                                <div class="em-pf-inline" style="margin-top:8px;">
                                    <button type="button" class="button em-pf-newform"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('New Form', 'email-manager'); ?></button>
                                    <span class="em-pf-hint" id="em-pf-form-hint"><?php esc_html_e('Search an existing form or build a new one inline.', 'email-manager'); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="em-pf-steps">
                            <button type="button" class="em-pf-step is-active" data-step="landing" style="--em-i:0;"><?php esc_html_e('Landing', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="thankyou" style="--em-i:1;"><?php esc_html_e('Thank You', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="process" style="--em-i:2;"><?php esc_html_e('Apply Process', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="contract" style="--em-i:3;"><?php esc_html_e('Contract', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="postings" style="--em-i:4;"><?php esc_html_e('Postings', 'email-manager'); ?></button>
                        </div>

                        <!-- Landing -->
                        <div class="em-pf-pane is-active" data-pane="landing">
                            <?php self::render_page_editor_area('landing'); ?>
                        </div>

                        <!-- Thank You -->
                        <div class="em-pf-pane" data-pane="thankyou">
                            <div class="em-pf-toggle-row em-pf-tymodes">
                                <label class="em-pf-radio"><input type="radio" name="ty_mode" value="page" /> <span class="dashicons dashicons-admin-page"></span> <?php esc_html_e('Page', 'email-manager'); ?></label>
                                <label class="em-pf-radio"><input type="radio" name="ty_mode" value="redirect" /> <span class="dashicons dashicons-external"></span> <?php esc_html_e('Redirect', 'email-manager'); ?></label>
                                <label class="em-pf-radio"><input type="radio" name="ty_mode" value="message" checked /> <span class="dashicons dashicons-format-status"></span> <?php esc_html_e('Popup message', 'email-manager'); ?></label>
                            </div>

                            <div data-ty="page" style="display:none;">
                                <?php self::render_page_editor_area('thankyou'); ?>
                            </div>
                            <label class="em-pf-field" data-ty="redirect" style="display:none;">
                                <span class="em-pf-label"><?php esc_html_e('Redirect URL', 'email-manager'); ?></span>
                                <input type="text" name="ty_redirect_url" placeholder="https://…" />
                                <span class="em-pf-hint"><?php esc_html_e('Applicants are sent here right after they submit.', 'email-manager'); ?></span>
                            </label>
                            <label class="em-pf-field" data-ty="message">
                                <span class="em-pf-label"><?php esc_html_e('Thank-You Message', 'email-manager'); ?></span>
                                <textarea name="ty_message" rows="4"><?php echo esc_textarea($dty['message']); ?></textarea>
                                <span class="em-pf-hint"><?php esc_html_e('Shown inline in the form after a successful submission.', 'email-manager'); ?></span>
                            </label>
                        </div>

                        <!-- Apply Process -->
                        <div class="em-pf-pane" data-pane="process">
                            <p class="em-pf-hint" style="margin-bottom:12px;"><?php esc_html_e('Outline the steps applicants go through. Each step appears as a "How it works" entry on the landing page, and can also grant a role, add the applicant to a mailing list, and send emails when they reach it. Step 1 runs when the form is submitted; every later step is completed either by a member\'s approval or by an action the applicant takes, and finishing it moves the applicant to the next step. The last step is fixed: it sets up what happens when the applicant is approved.', 'email-manager'); ?></p>
                            <?php /* The Add Step button lives inside the rows list, between the ordinary steps and the approved step, so
                                     the approved step is always the last thing shown. Steps are inserted before it (see em-postings.js). */ ?>
                            <div id="em-process-rows" class="em-process-rows">
                                <div class="em-process-addwrap">
                                    <button type="button" class="button em-process-add"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add Step', 'email-manager'); ?></button>
                                </div>
                            </div>
                            <div id="em-enroll-park">
                                <div class="em-enroll-pane" id="em-enroll-pane" hidden>
                                    <div class="em-pf-field">
                                        <span class="em-pf-label"><?php esc_html_e('Role Access', 'email-manager'); ?></span>
                                        <div class="em-pf-inline">
                                            <select name="contract_enroll_role" id="em-enroll-role" class="em-enroll-role"><option value=""><?php esc_html_e('— No role —', 'email-manager'); ?></option></select>
                                            <button type="button" class="button em-enroll-editrole" hidden><span class="dashicons dashicons-lock" style="margin-top:3px;"></span> <?php esc_html_e('Edit Access', 'email-manager'); ?></button>
                                            <button type="button" class="button em-enroll-addrole"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add New Role', 'email-manager'); ?></button>
                                        </div>
                                        <span class="em-pf-hint"><?php esc_html_e('Anyone who earns this role, however they get it, is automatically enrolled in this application\'s contract without applying: its role, its dashboard access, and the commission and service contracts attached on the Contract tab. Only while this posting is open.', 'email-manager'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contract -->
                        <div class="em-pf-pane" data-pane="contract">
                            <div class="em-pf-field em-contract-rolefield">
                                <span class="em-pf-label"><?php esc_html_e('Role granted on approval', 'email-manager'); ?></span>
                                <div class="em-pf-inline">
                                    <select name="contract_role" id="em-contract-role" class="em-contract-role"><option value=""><?php esc_html_e('— No role —', 'email-manager'); ?></option></select>
                                    <button type="button" class="button em-contract-editrole" hidden><span class="dashicons dashicons-lock" style="margin-top:3px;"></span> <?php esc_html_e('Edit Access', 'email-manager'); ?></button>
                                    <button type="button" class="button em-contract-addrole"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add New Role', 'email-manager'); ?></button>
                                </div>
                                <span class="em-pf-hint"><?php esc_html_e('The role members get when they are approved through this posting (the last step in Apply Process). Pick a role to edit what it can access, or create a new one with its feature access.', 'email-manager'); ?></span>
                            </div>
                            <div class="em-pf-subtabs" role="tablist">
                                <button type="button" class="em-pf-subtab is-active" role="tab" data-substep="commissions"><?php esc_html_e('Commissions', 'email-manager'); ?></button>
                                <button type="button" class="em-pf-subtab" role="tab" data-substep="services"><?php esc_html_e('Services', 'email-manager'); ?></button>
                                <button type="button" class="em-pf-subtab" role="tab" data-substep="dashboard"><?php esc_html_e('Dashboard Access', 'email-manager'); ?></button>
                            </div>
                            <div class="em-pf-subpane is-active" data-subpane="commissions">
                                <?php self::render_commission_pick(); ?>
                            </div>
                            <div class="em-pf-subpane" data-subpane="services">
                                <?php self::render_service_pick(); ?>
                            </div>
                            <div class="em-pf-subpane" data-subpane="dashboard">
                                <?php self::render_dashboard_pick(); ?>
                            </div>

                            <div class="em-pf-sectionhead"><?php esc_html_e('Agreement terms', 'email-manager'); ?></div>
                            <label class="em-pf-switch">
                                <input type="checkbox" name="contract_enabled" value="1" />
                                <span><?php esc_html_e('Enabled', 'email-manager'); ?></span>
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Contract / Agreement Title', 'email-manager'); ?></span>
                                <input type="text" name="contract_title" value="<?php echo esc_attr(self::default_contract()['title']); ?>" />
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Terms', 'email-manager'); ?></span>
                                <textarea name="contract_body" rows="8" placeholder="<?php esc_attr_e('Paste the agreement / contract terms applicants accept when applying. Basic HTML allowed.', 'email-manager'); ?>"></textarea>
                            </label>
                            <label class="em-pf-switch">
                                <input type="checkbox" name="contract_require" value="1" checked />
                                <span><?php esc_html_e('Require applicants to accept before submitting', 'email-manager'); ?></span>
                            </label>
                            <p class="em-pf-hint"><?php esc_html_e('When enabled, the terms display on the landing page above the form.', 'email-manager'); ?></p>
                        </div>

                        <!-- Postings: affiliate sites + job boards -->
                        <div class="em-pf-pane" data-pane="postings">
                            <input type="hidden" name="boards_present" value="1" />
                            <div class="em-pf-subtabs" role="tablist">
                                <button type="button" class="em-pf-subtab is-active" role="tab" data-substep="affiliates"><?php esc_html_e('Affiliates', 'email-manager'); ?></button>
                                <button type="button" class="em-pf-subtab" role="tab" data-substep="jobboards"><?php esc_html_e('Job Boards', 'email-manager'); ?></button>
                            </div>

                            <div class="em-pf-subpane is-active" data-subpane="affiliates">
                                <p class="em-pf-hint"><?php esc_html_e('List this application\'s affiliate program (its Commission Contracts) on the sites where affiliates look for programs. Each listing gets its own tracked link — use it on the site — so visits and applications are counted per listing in Analytics, and the cost of being listed there counts toward this application\'s spend.', 'email-manager'); ?></p>
                                <div class="em-pf-label"><?php esc_html_e('Add a listing on', 'email-manager'); ?></div>
                                <div class="em-board-chips">
                                    <?php foreach (self::affiliate_catalog() as $key => $b): ?>
                                        <button type="button" class="em-board-chip" data-kind="affiliate" data-board="<?php echo esc_attr($key); ?>"><span class="dashicons dashicons-plus-alt2"></span><?php echo esc_html($b['label']); ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <div id="em-affiliate-rows" class="em-board-rows"></div>
                                <p class="em-pf-hint em-board-empty" data-kind="affiliate"><?php esc_html_e('No affiliate listings yet.', 'email-manager'); ?></p>
                            </div>

                            <div class="em-pf-subpane" data-subpane="jobboards">
                            <p class="em-pf-hint"><?php esc_html_e('Attach this application\'s postings on job boards. Each one gets its own tracked apply link — use it as the apply URL on the board — so visits and applications are counted per posting in Analytics. Job boards don\'t share their own numbers with us, so you can also log what a board reports to compare.', 'email-manager'); ?></p>
                            <div class="em-pf-label"><?php esc_html_e('Add a posting from', 'email-manager'); ?></div>
                            <div class="em-board-chips">
                                <?php foreach (self::board_catalog() as $key => $b): ?>
                                    <button type="button" class="em-board-chip" data-kind="job" data-board="<?php echo esc_attr($key); ?>"><span class="dashicons dashicons-plus-alt2"></span><?php echo esc_html($b['label']); ?></button>
                                <?php endforeach; ?>
                            </div>
                            <div id="em-board-rows" class="em-board-rows"></div>
                            <p class="em-pf-hint em-board-empty" data-kind="job"><?php esc_html_e('No job board postings attached yet.', 'email-manager'); ?></p>
                            </div>
                        </div>

                        <div class="em-pf-actions">
                            <span class="em-pf-saving" hidden><?php esc_html_e('Saving…', 'email-manager'); ?></span>
                            <span class="em-pf-saved" hidden><?php esc_html_e('Saved ✓', 'email-manager'); ?></span>
                            <button type="button" class="button" data-close="1"><?php esc_html_e('Close', 'email-manager'); ?></button>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Save Posting', 'email-manager'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /** Landing/thank-you embedded WP-editor area + shortcode sidebar. */
    private static function render_page_editor_area($role)
    {
        ?>
        <div class="em-pf-pagewrap" data-role="<?php echo esc_attr($role); ?>">
            <div class="em-pf-editor">
                <div class="em-pf-needsave">
                    <span class="dashicons dashicons-edit-page"></span>
                    <p><?php esc_html_e('Save the posting to create its page, then build it right here in the WordPress editor.', 'email-manager'); ?></p>
                    <button type="button" class="button button-primary em-pf-savenow"><?php esc_html_e('Save & create page', 'email-manager'); ?></button>
                </div>
                <iframe class="em-pf-iframe" data-role="<?php echo esc_attr($role); ?>" title="<?php esc_attr_e('Page editor', 'email-manager'); ?>" hidden></iframe>
            </div>
            <aside class="em-pf-side">
                <?php if ($role === 'landing'): ?>
                    <a class="button em-pf-openpage" data-role="landing" href="#" target="_blank" rel="noopener" hidden><?php esc_html_e('Open full editor ↗', 'email-manager'); ?></a>
                <?php endif; ?>
                <div class="em-pf-side__title"><?php esc_html_e('Form shortcode', 'email-manager'); ?></div>
                <p class="em-pf-side__hint"><?php esc_html_e('Paste this into the page where the form should appear.', 'email-manager'); ?></p>
                <div class="em-pf-shortcode">
                    <input type="text" class="em-pf-shortcode__input" readonly value="" placeholder="<?php esc_attr_e('Link a form first', 'email-manager'); ?>" onclick="this.select();" />
                    <button type="button" class="button em-pf-shortcode__copy"><span class="dashicons dashicons-admin-page"></span></button>
                </div>
            </aside>
        </div>
        <?php
    }

    private static function render_newform_modal()
    {
        ?>
        <div class="em-drawer em-modal em-modal--sm" id="em-newform-modal" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close-nf="1"></div>
            <div class="em-modal__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title"><?php esc_html_e('Create Application Form', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" data-close-nf="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-modal__body">
                    <label class="em-pf-field">
                        <span class="em-pf-label"><?php esc_html_e('Form Name', 'email-manager'); ?></span>
                        <input type="text" id="em-nf-title" placeholder="<?php esc_attr_e('e.g. Ambassador Application', 'email-manager'); ?>" />
                    </label>

                    <div class="em-pf-label" style="margin-bottom:8px;"><?php esc_html_e('Questions', 'email-manager'); ?></div>
                    <div id="em-nf-questions" class="em-nf-questions"></div>
                    <button type="button" class="button em-nf-add" style="margin-top:10px;"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add Question', 'email-manager'); ?></button>

                    <div class="em-pf-actions">
                        <span class="em-nf-saving" hidden><?php esc_html_e('Creating…', 'email-manager'); ?></span>
                        <button type="button" class="button" data-close-nf="1"><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                        <button type="button" class="button button-primary em-nf-create"><?php esc_html_e('Create & Insert', 'email-manager'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Question row template -->
            <template id="em-nf-row-tpl">
                <div class="em-nf-row">
                    <input type="text" class="em-nf-q" placeholder="<?php esc_attr_e('Question label', 'email-manager'); ?>" />
                    <select class="em-nf-type">
                        <option value="text"><?php esc_html_e('Short text', 'email-manager'); ?></option>
                        <option value="email"><?php esc_html_e('Email', 'email-manager'); ?></option>
                        <option value="telephone"><?php esc_html_e('Phone', 'email-manager'); ?></option>
                        <option value="multiple"><?php esc_html_e('Dropdown', 'email-manager'); ?></option>
                        <option value="file"><?php esc_html_e('File upload', 'email-manager'); ?></option>
                    </select>
                    <button type="button" class="em-nf-remove" aria-label="<?php esc_attr_e('Remove', 'email-manager'); ?>">&times;</button>
                </div>
            </template>
        </div>
        <?php
    }

    private static function render_role_modal()
    {
        $catalog = self::dashboard_catalog();
        ?>
        <div class="em-drawer em-modal em-modal--wide" id="em-role-modal" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close-role="1"></div>
            <div class="em-modal__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title" id="em-role-title"><?php esc_html_e('Create Role', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" data-close-role="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-modal__body">
                    <label class="em-pf-field">
                        <span class="em-pf-label"><?php esc_html_e('Role Name', 'email-manager'); ?></span>
                        <input type="text" id="em-role-name" placeholder="<?php esc_attr_e('e.g. Finalist', 'email-manager'); ?>" />
                    </label>
                    <p class="em-pf-hint em-role-locked" hidden><?php esc_html_e('This is an administrator-level role, so its access can\'t be changed from here.', 'email-manager'); ?></p>

                    <div class="em-role-access">
                        <div class="em-pf-label" style="margin:14px 0 8px;"><?php esc_html_e('What members with this role can do', 'email-manager'); ?></div>
                        <div class="em-role-caps">
                            <?php foreach (self::capability_groups() as $group => $caps): ?>
                                <div class="em-role-capgroup">
                                    <div class="em-role-capgroup__title"><?php echo esc_html($group); ?></div>
                                    <?php foreach ($caps as $cap => $label): ?>
                                        <label class="em-pf-switch em-role-cap-row">
                                            <input type="checkbox" class="em-role-cap" value="<?php echo esc_attr($cap); ?>" <?php checked($cap === 'read'); ?> <?php disabled($cap === 'read'); ?> />
                                            <span><?php echo esc_html($label); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="em-pf-hint"><?php esc_html_e('Every role can log in; the other toggles control what else members with this role can do.', 'email-manager'); ?></p>

                        <div class="em-pf-label" style="margin:14px 0 8px;"><?php esc_html_e('Dashboard sections they can see', 'email-manager'); ?></div>
                        <?php if ($catalog): ?>
                            <?php self::render_dashboard_grid('role_dashboard[]', $catalog); ?>
                            <p class="em-pf-hint em-role-dash-hint"><?php esc_html_e('Added to the member\'s dashboard access when they get this role. Removing a section here doesn\'t take it away from members who already have it.', 'email-manager'); ?></p>
                        <?php else: ?>
                            <p class="em-pf-hint"><?php esc_html_e('The dashboard sections come from the Feature Access list, which isn\'t available here (it needs the Gend Society plugin, and a network administrator to load it the first time).', 'email-manager'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="em-pf-actions">
                        <span class="em-role-saving" hidden><?php esc_html_e('Creating…', 'email-manager'); ?></span>
                        <button type="button" class="button" data-close-role="1"><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                        <button type="button" class="button button-primary em-role-create"><?php esc_html_e('Create Role', 'email-manager'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /** Contract tab → Commissions: pick an existing commission contract or add one. */
    private static function render_commission_pick()
    {
        if (!self::contract_sources()['commission']) {
            echo '<p class="em-pf-hint">' . esc_html__('The Sales Team plugin must be active to attach a commission contract.', 'email-manager') . '</p>';
            return;
        }
        $groups  = [
            'referral' => __('Referral programs', 'email-manager'),
            'role'     => __('Role contracts', 'email-manager'),
            'member'   => __('Member contracts', 'email-manager'),
        ];
        $choices = self::commission_choices();
        ?>
        <div class="em-pf-field">
            <span class="em-pf-label"><?php esc_html_e('Commission Contracts', 'email-manager'); ?></span>
            <input type="hidden" name="contract_commissions_present" value="1" />
            <div id="em-cc-chosen" class="em-cc-chosen"></div>
            <p class="em-pf-hint em-cc-empty"><?php esc_html_e('No commission contracts attached yet.', 'email-manager'); ?></p>
            <div class="em-pf-inline">
                <select id="em-contract-commission">
                    <option value=""><?php esc_html_e('— Add an existing commission contract —', 'email-manager'); ?></option>
                    <?php foreach ($groups as $group => $group_label): ?>
                        <optgroup label="<?php echo esc_attr($group_label); ?>" data-group="<?php echo esc_attr($group); ?>">
                            <?php foreach ($choices as $c): if ($c['group'] !== $group) continue; ?>
                                <option value="<?php echo esc_attr($c['ref']); ?>"><?php echo esc_html($c['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if (current_user_can('manage_options')): ?>
                    <button type="button" class="button em-cc-add"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add New Commission Contract', 'email-manager'); ?></button>
                <?php endif; ?>
            </div>
        </div>
        <p class="em-pf-hint">
            <?php esc_html_e('The commission contracts that apply to members brought in through this posting. Attach as many as you need.', 'email-manager'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=st_sales_team&tab=commission_contracts')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Manage commission contracts ↗', 'email-manager'); ?></a>
        </p>
        <?php
    }

    /** Contract tab → Dashboard Access: the dashboard sections members gain when approved. */
    private static function render_dashboard_pick()
    {
        $catalog = self::dashboard_catalog();
        if (!$catalog) {
            echo '<p class="em-pf-hint">' . esc_html__('The dashboard sections come from the Feature Access list, which isn\'t available here (it needs the Gend Society plugin, and a network administrator to load it the first time).', 'email-manager') . '</p>';
            return;
        }
        ?>
        <input type="hidden" name="contract_dashboard_present" value="1" />
        <p class="em-pf-hint"><?php esc_html_e('The dashboard sections members gain when they are approved through this posting. They are added to what the member can already see — nothing is ever taken away. Tick a section to give its whole menu — its pages then appear ticked, so you can untick any you don\'t want.', 'email-manager'); ?></p>
        <?php self::render_dashboard_grid('contract_dashboard[]', $catalog); ?>
        <p class="em-pf-hint"><?php esc_html_e('Granted to the member\'s account at the Approved step in Apply Process. Turn on "Create a member if none matches by email" on that step so every applicant ends up with an account to receive it.', 'email-manager'); ?></p>
        <?php
    }

    /** The Feature Access sections as a filterable checkbox grid; $name is the checkbox name. */
    private static function render_dashboard_grid($name, array $catalog)
    {
        ?>
        <div class="em-da-scope">
        <div class="em-da-bar">
            <input type="text" class="em-da-filter" placeholder="<?php esc_attr_e('Filter sections…', 'email-manager'); ?>" autocomplete="off" />
            <span class="em-da-count" aria-live="polite"></span>
            <button type="button" class="button em-da-all"><?php esc_html_e('Select all', 'email-manager'); ?></button>
            <button type="button" class="button em-da-none"><?php esc_html_e('Clear', 'email-manager'); ?></button>
        </div>
        <div class="em-da-grid">
            <?php foreach ($catalog as $item): ?>
                <div class="em-da-card" data-search="<?php echo esc_attr(strtolower($item['name'] . ' ' . implode(' ', array_column((array) $item['submenu'], 'name')))); ?>">
                    <label class="em-da-parent">
                        <input type="checkbox" class="em-da-check em-da-check--parent" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($item['slug']); ?>" />
                        <span><?php echo esc_html($item['name'] !== '' ? $item['name'] : $item['slug']); ?></span>
                        <?php if (!empty($item['submenu'])): ?>
                            <small class="em-da-pagecount"><?php echo esc_html(sprintf(_n('%d page', '%d pages', count($item['submenu']), 'email-manager'), count($item['submenu']))); ?></small>
                        <?php endif; ?>
                    </label>
                    <?php if (!empty($item['submenu'])): ?>
                        <?php /* Collapsed until the menu is ticked; then every page is ticked and can be unticked. */ ?>
                        <ul class="em-da-children" hidden>
                            <?php foreach ($item['submenu'] as $sub): $same = ($sub['slug'] === $item['slug']); ?>
                                <li>
                                    <label<?php if ($same): ?> title="<?php esc_attr_e('This is the menu\'s own page, so it comes with the menu. Untick the menu to remove it.', 'email-manager'); ?>"<?php endif; ?>>
                                        <input type="checkbox" class="em-da-check em-da-check--child<?php echo $same ? ' em-da-check--same' : ''; ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($sub['slug']); ?>" <?php disabled($same); ?> />
                                        <span><?php echo esc_html($sub['name'] !== '' ? $sub['name'] : $sub['slug']); ?></span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="em-pf-hint em-da-nomatch" hidden><?php esc_html_e('No sections match that filter.', 'email-manager'); ?></p>
        </div>
        <?php
    }

    /** Contract tab → Services: pick an existing service (role) contract or add one. */
    private static function render_service_pick()
    {
        if (!self::contract_sources()['service']) {
            echo '<p class="em-pf-hint">' . esc_html__('The Sales Team plugin must be active to attach a service contract.', 'email-manager') . '</p>';
            return;
        }
        ?>
        <div class="em-pf-field">
            <span class="em-pf-label"><?php esc_html_e('Service Contract', 'email-manager'); ?></span>
            <div class="em-pf-inline">
                <select name="contract_service" id="em-contract-service">
                    <option value=""><?php esc_html_e('— None —', 'email-manager'); ?></option>
                    <?php foreach (self::service_choices() as $c): ?>
                        <option value="<?php echo esc_attr($c['id']); ?>"><?php echo esc_html($c['name'] === $c['member_type'] ? $c['name'] : $c['name'] . ' — ' . $c['member_type']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (current_user_can('manage_options')): ?>
                    <button type="button" class="button em-sc-add"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add New Service Contract', 'email-manager'); ?></button>
                <?php endif; ?>
            </div>
        </div>
        <p class="em-pf-hint">
            <?php esc_html_e('The service execution (role) contract that applies to members brought in through this posting.', 'email-manager'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=psoo-projects')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Manage service contracts ↗', 'email-manager'); ?></a>
        </p>
        <?php
    }

    /** Popup: create a commission contract (referral program or role) from the posting editor. */
    private static function render_commission_modal()
    {
        if (!current_user_can('manage_options') || !self::contract_sources()['commission']) return;
        $role_types = [
            'wp_role'    => __('App Role', 'email-manager'),
            'sp_member'  => __('Member Type', 'email-manager'),
            'group_role' => __('Group Role', 'email-manager'),
        ];
        $role_options = self::commission_role_options();
        ?>
        <div class="em-drawer em-modal em-modal--sm em-cx-modal" id="em-cc-modal" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close-cc="1"></div>
            <div class="em-modal__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title"><?php esc_html_e('New Commission Contract', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" data-close-cc="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-modal__body">
                    <label class="em-pf-field">
                        <span class="em-pf-label"><?php esc_html_e('Contract Name', 'email-manager'); ?></span>
                        <input type="text" id="em-cc-name" placeholder="<?php esc_attr_e('e.g. Sales Partner Commission', 'email-manager'); ?>" />
                    </label>
                    <label class="em-pf-field">
                        <span class="em-pf-label"><?php esc_html_e('Contract Type', 'email-manager'); ?></span>
                        <select id="em-cc-type">
                            <option value="referral"><?php esc_html_e('Referral — earns on members they refer', 'email-manager'); ?></option>
                            <option value="role"><?php esc_html_e('Role — earns on qualifying orders while they hold a role', 'email-manager'); ?></option>
                        </select>
                    </label>

                    <div data-cc-panel="referral">
                        <label class="em-pf-field">
                            <span class="em-pf-label"><?php esc_html_e('Tracking URL Parameter', 'email-manager'); ?></span>
                            <input type="text" id="em-cc-param" placeholder="partner" />
                            <span class="em-pf-hint"><?php esc_html_e('Referral links look like ?partner=123. It must be unique across referral programs.', 'email-manager'); ?></span>
                        </label>
                    </div>

                    <div data-cc-panel="role" style="display:none;">
                        <label class="em-pf-field">
                            <span class="em-pf-label"><?php esc_html_e('Role Type', 'email-manager'); ?></span>
                            <select id="em-cc-roletype">
                                <?php foreach ($role_types as $type => $label): ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="em-pf-field" style="margin-top:12px;">
                            <span class="em-pf-label"><?php esc_html_e('Role', 'email-manager'); ?></span>
                            <?php foreach ($role_options as $type => $rows): ?>
                                <select class="em-cc-role" data-roletype="<?php echo esc_attr($type); ?>" style="<?php echo $type === 'wp_role' ? '' : 'display:none;'; ?>">
                                    <?php if (empty($rows)): ?>
                                        <option value=""><?php esc_html_e('No unassigned roles available', 'email-manager'); ?></option>
                                    <?php else: foreach ($rows as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            <?php endforeach; ?>
                            <span class="em-pf-hint"><?php esc_html_e('Each role can have one commission contract, so roles that already have one are not listed.', 'email-manager'); ?></span>
                        </label>
                    </div>

                    <label class="em-pf-field" style="margin-top:12px;">
                        <span class="em-pf-label"><?php esc_html_e('Commission Rate (%)', 'email-manager'); ?></span>
                        <input type="number" id="em-cc-rate" min="0" max="100" step="0.1" value="10" />
                        <span class="em-pf-hint"><?php esc_html_e('Creates a single flat tier. Add more tiers, team overrides and product categories later under Sales Team → Commission Contracts.', 'email-manager'); ?></span>
                    </label>

                    <div class="em-pf-actions">
                        <span class="em-cc-saving" hidden><?php esc_html_e('Creating…', 'email-manager'); ?></span>
                        <button type="button" class="button" data-close-cc="1"><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                        <button type="button" class="button button-primary em-cc-create"><?php esc_html_e('Create & Select', 'email-manager'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /** Popup: create a service (role) contract from the posting editor. */
    private static function render_service_modal()
    {
        if (!current_user_can('manage_options') || !self::contract_sources()['service']) return;
        $assignment = [
            'admin_assigns'    => __('Member selected by contract member type', 'email-manager'),
            'apply_and_select' => __('Member to apply and selected by contract member type', 'email-manager'),
            'first_applicant'  => __('First member to apply is selected', 'email-manager'),
        ];
        $triggers = [
            'milestone_completion' => __('Milestone Completion', 'email-manager'),
            'task_completion'      => __('Task Completion', 'email-manager'),
            'customer_approval'    => __('Customer Approval', 'email-manager'),
        ];
        $types = self::service_member_type_options();
        ?>
        <div class="em-drawer em-modal em-modal--sm em-cx-modal" id="em-sc-modal" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close-sc="1"></div>
            <div class="em-modal__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title"><?php esc_html_e('New Service Contract', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" data-close-sc="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-modal__body">
                    <label class="em-pf-field">
                        <span class="em-pf-label"><?php esc_html_e('Contract Name (optional)', 'email-manager'); ?></span>
                        <input type="text" id="em-sc-name" placeholder="<?php esc_attr_e('e.g. Developer Tasks', 'email-manager'); ?>" />
                    </label>
                    <label class="em-pf-field" style="margin-top:12px;">
                        <span class="em-pf-label"><?php esc_html_e('Member Type', 'email-manager'); ?></span>
                        <select id="em-sc-membertype">
                            <?php if (empty($types)): ?>
                                <option value=""><?php esc_html_e('No member types available', 'email-manager'); ?></option>
                            <?php else: foreach ($types as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                        <span class="em-pf-hint"><?php esc_html_e('Each member type can have one service contract, so types that already have one are not listed.', 'email-manager'); ?></span>
                    </label>
                    <label class="em-pf-field" style="margin-top:12px;">
                        <span class="em-pf-label"><?php esc_html_e('How the Contract Is Assigned', 'email-manager'); ?></span>
                        <select id="em-sc-assignment">
                            <?php foreach ($assignment as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="em-pf-field" style="margin-top:12px;">
                        <span class="em-pf-label"><?php esc_html_e('1 Assigned Task Credit Is Worth (DGEN)', 'email-manager'); ?></span>
                        <input type="number" id="em-sc-credit" min="1" step="1" value="15" />
                    </label>
                    <div class="em-pf-row2 em-sc-paygrid" style="margin-top:12px;">
                        <label class="em-pf-field">
                            <span class="em-pf-label"><?php esc_html_e('Payment Trigger', 'email-manager'); ?></span>
                            <select id="em-sc-trigger">
                                <?php foreach ($triggers as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($key, 'task_completion'); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="em-pf-field">
                            <span class="em-pf-label"><?php esc_html_e('Days to Wait', 'email-manager'); ?></span>
                            <input type="number" id="em-sc-days" min="0" value="0" />
                        </label>
                    </div>
                    <label class="em-pf-switch" style="margin-top:12px;">
                        <input type="checkbox" id="em-sc-instant" />
                        <span><?php esc_html_e('Limit members to one active approved contract at a time (Instant Execution)', 'email-manager'); ?></span>
                    </label>
                    <p class="em-pf-hint" style="margin-top:8px;"><?php esc_html_e('Who may approve or apply, and Leo token rewards, can be set later under Projects → Service Contract.', 'email-manager'); ?></p>

                    <div class="em-pf-actions">
                        <span class="em-sc-saving" hidden><?php esc_html_e('Creating…', 'email-manager'); ?></span>
                        <button type="button" class="button" data-close-sc="1"><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                        <button type="button" class="button button-primary em-sc-create"><?php esc_html_e('Create & Select', 'email-manager'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_email_modal()
    {
        // The placeholders the sender fills in for every email (subject and body) -- keep in step with $tokens where the
        // step's emails are sent.
        $placeholders = [
            '{applicant_name}'  => __("The applicant's name", 'email-manager'),
            '{applicant_email}' => __("The applicant's email address", 'email-manager'),
            '{posting_title}'   => __('The posting they applied to', 'email-manager'),
            '{step}'            => __('The name of this step', 'email-manager'),
            '{action_label}'    => __("This step's action label", 'email-manager'),
            '{action_list}'     => __('The list of actions to complete', 'email-manager'),
            '{action_url}'      => __("This step's action link", 'email-manager'),
            '{all_answers}'     => __('Everything they answered on the form', 'email-manager'),
            '{site_name}'       => __('Your site name', 'email-manager'),
            '{site_url}'        => __('Your site address', 'email-manager'),
        ];
        ?>
        <div class="em-drawer em-modal em-modal--email" id="em-email-modal" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close-email="1"></div>
            <div class="em-modal__panel em-email" role="dialog" aria-modal="true" aria-labelledby="em-email-title">
                <div class="em-drawer__header em-email__head">
                    <span class="em-email__badge" aria-hidden="true"><span class="dashicons dashicons-email-alt"></span></span>
                    <div class="em-email__heading">
                        <h3 class="em-drawer__title" id="em-email-title"><?php esc_html_e('Edit Email', 'email-manager'); ?></h3>
                        <p class="em-email__sub"><?php
                            printf(
                                /* translators: %s: the name of the step */
                                esc_html__('Sent automatically when an applicant reaches %s.', 'email-manager'),
                                '<strong class="em-email__step">' . esc_html__('this step', 'email-manager') . '</strong>'
                            );
                        ?></p>
                    </div>
                    <button type="button" class="em-drawer__close" data-close-email="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-modal__body em-email__body">
                    <section class="em-email__section" aria-labelledby="em-email-h-to">
                        <h4 class="em-email__h" id="em-email-h-to"><span class="dashicons dashicons-groups" aria-hidden="true"></span><?php esc_html_e('Recipients', 'email-manager'); ?></h4>
                        <label class="em-email__switch">
                            <input type="checkbox" id="em-email-to-applicant" />
                            <span class="em-email__track" aria-hidden="true"></span>
                            <span class="em-email__switch-text">
                                <strong><?php esc_html_e('Send to the applicant', 'email-manager'); ?></strong>
                                <small><?php esc_html_e('Goes to the email address on their application.', 'email-manager'); ?></small>
                            </span>
                        </label>
                        <div class="em-email__field">
                            <label class="em-email__label" for="em-email-to-extra"><?php esc_html_e('Also send to', 'email-manager'); ?></label>
                            <input type="text" id="em-email-to-extra" placeholder="lead@example.com, ops@example.com" autocomplete="off" />
                            <small class="em-email__help"><?php esc_html_e('Team members or anyone else who should get a copy. Separate addresses with commas.', 'email-manager'); ?></small>
                        </div>
                    </section>

                    <section class="em-email__section" aria-labelledby="em-email-h-msg">
                        <h4 class="em-email__h" id="em-email-h-msg"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e('Message', 'email-manager'); ?></h4>
                        <div class="em-email__field">
                            <label class="em-email__label" for="em-email-subject"><?php esc_html_e('Subject', 'email-manager'); ?></label>
                            <input type="text" id="em-email-subject" placeholder="<?php esc_attr_e('e.g. Welcome aboard, {applicant_name}!', 'email-manager'); ?>" autocomplete="off" />
                        </div>
                        <div class="em-email__field em-email__field--editor">
                            <label class="em-email__label" for="emmailbody"><?php esc_html_e('Body', 'email-manager'); ?></label>
                            <?php
                            // Deliberately NOT wp_editor() here: this modal starts
                            // display:none, and wp_editor()'s auto-init would size
                            // TinyMCE against a hidden, zero-width container. The
                            // JS instead calls wp.editor.initialize()/.remove() on
                            // every open/close, which is the documented approach
                            // for editors inside on-demand-shown containers.
                            ?>
                            <textarea id="emmailbody" class="wp-editor-area" rows="12" style="width:100%;"></textarea>
                        </div>
                        <div class="em-email__tokens">
                            <span class="em-email__label"><?php esc_html_e('Placeholders', 'email-manager'); ?></span>
                            <div class="em-email__chips">
                                <?php foreach ($placeholders as $token => $what) : ?>
                                    <button type="button" class="em-email__chip" data-token="<?php echo esc_attr($token); ?>" title="<?php echo esc_attr($what); ?>"><?php echo esc_html($token); ?></button>
                                <?php endforeach; ?>
                            </div>
                            <small class="em-email__help"><?php esc_html_e('Click one to drop it in at the cursor, in the subject or the message. Each is replaced with the real details when the email is sent. Switch to the HTML tab to edit the raw HTML directly.', 'email-manager'); ?></small>
                        </div>
                    </section>
                </div>
                <div class="em-email__foot">
                    <button type="button" class="button em-email__btn em-email__btn--ghost" data-close-email="1"><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                    <button type="button" class="button button-primary em-email__btn em-email__btn--primary em-email-save"><?php esc_html_e('Save Email', 'email-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_analytics_drawer()
    {
        ?>
        <div class="em-drawer" id="em-posting-analytics-drawer" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close="1"></div>
            <div class="em-drawer__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title" id="em-pa-title"><?php esc_html_e('Analytics', 'email-manager'); ?></h3>
                    <span class="em-pa-sync" id="em-pa-sync" aria-live="polite"></span>
                    <button type="button" class="em-drawer__close" data-close="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-pa-loadbar" id="em-pa-loadbar" hidden aria-hidden="true"></div>
                <div class="em-drawer__body" id="em-pa-body"></div>
            </div>
        </div>
        <?php
    }
}

new EM_Postings();
