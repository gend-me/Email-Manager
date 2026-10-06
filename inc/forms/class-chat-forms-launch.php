<?php
/**
 * Chat_Forms_Launch — bridges a chatflow's completion (or a standalone
 * `[gend_launch]` link click, usable anywhere on the site) to a Launch
 * Trigger minted in the desktop app's Sequences popup.
 *
 * Wire format: identical to the "GenD Webhook Bridge" companion plugin
 * (see companion-plugin-generator.js on the desktop side) — the SAME
 * `gend_webhook_endpoint` / `gend_webhook_api_key` options, a trigger name
 * in the URL's `<trigger>` placeholder, and an `X-Gend-Key` header. This
 * class does not require that plugin's class to be loaded — it reads the
 * same option keys directly, so it works whether the bridge is a separate
 * plugin or the options were set some other way.
 *
 * Two doors in:
 *   1. Chatflow completion — hooks Chat_Forms_Ajax's `chat_form_submission`
 *      action; if the completed form has a `_chat_form_launch_code`, fires
 *      the trigger with the submission as context.
 *   2. `[gend_launch code="…"]` shortcode — renders a link to a public REST
 *      route on THIS site. Clicking it fires the trigger, then redirects
 *      back with `?gend_launched=1` so gend-launch-open-widget.js can
 *      reveal the site's AI chat widget (<aipa-widget> — owned by LEO on
 *      the hub, mirrored by gend-society's GS_AI_Widget on subsites where
 *      LEO itself is never installed; see gend-society/inc/ai-widget.php).
 *      This class never talks to the LEO plugin directly for exactly that
 *      reason — <aipa-widget> is the only thing guaranteed present.
 */

if (!defined('ABSPATH')) exit;

class Chat_Forms_Launch
{
    const OPT_ENDPOINT = 'gend_webhook_endpoint';
    const OPT_API_KEY  = 'gend_webhook_api_key';
    // Project Management "chatflow" task-type — a per-user registry of
    // TRACKED launches (as opposed to fire_launch()'s fire-and-forget
    // trigger), so every gend.me chat widget can list them and show
    // pending/complete status. See launch_tracked_instance() below.
    const OPT_LAUNCHED_META = '_gend_launched_chatflows';

    public function __construct()
    {
        add_action('chat_form_submission', array($this, 'on_chat_form_submission'), 10, 3);
        // Sequence start-trigger: "joined an email list". The list's launch
        // code lives in gdc_email_list_settings (setting_key 'launch_code').
        add_action('em_list_subscriber_added', array($this, 'on_list_subscriber_added'), 10, 2);
        add_shortcode('gend_launch', array($this, 'render_shortcode'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_widget_opener'));
    }

    /** Read a gend-webhook option, respecting multisite (matches the bridge plugin's get_opt). */
    private function get_opt($key)
    {
        return is_multisite() ? get_site_option($key, '') : get_option($key, '');
    }

    /**
     * Fire-and-forget POST of a launch to the desktop node. Never blocks the
     * caller (a chatflow submission or a link click) — mirrors the bridge
     * plugin's own `post()` posture exactly.
     */
    public function fire_launch($code, $context = array())
    {
        $code = trim((string) $code);
        if ($code === '') {
            return array('ok' => false, 'error' => 'missing-code');
        }

        // Cheap abuse/bot-preview guard: the [gend_launch] link is a public
        // GET route by design (it has to be pasteable anywhere), but that
        // means link-unfurl bots (Slack/Discord/iMessage previews) will
        // GET-fetch it the instant it's shared, and a double-click or page
        // reload would refire it too. Collapse repeat fires of the SAME
        // code to at most one per 30s rather than gating on auth, which
        // would defeat the point of a public launch link.
        $cooldown_key = 'gend_launch_cd_' . md5($code);
        if (get_transient($cooldown_key)) {
            return array('ok' => false, 'error' => 'cooldown');
        }
        set_transient($cooldown_key, 1, 30);

        $endpoint_tpl = $this->get_opt(self::OPT_ENDPOINT);
        $api_key      = $this->get_opt(self::OPT_API_KEY);
        if (!$endpoint_tpl || !$api_key) {
            return array('ok' => false, 'error' => 'gend-webhook-not-configured');
        }

        $url = str_replace('<trigger>', 'custom/chatflow.completed', $endpoint_tpl);

        $payload = array_merge(array(
            'launch_code' => $code,
            'site_name'   => get_bloginfo('name'),
            'site_url'    => home_url('/'),
            'fired_at'    => gmdate('c'),
        ), $context);

        wp_remote_post($url, array(
            'timeout'  => 5,
            'blocking' => false, // fire-and-forget — never slow the visitor down
            'headers'  => array(
                'Content-Type' => 'application/json',
                'X-Gend-Key'   => $api_key,
            ),
            'body' => wp_json_encode($payload),
        ));

        return array('ok' => true, 'requested' => true);
    }

    /**
     * Chatflow completion → fire the launch code configured on that form
     * (Success Message metabox → "Launch AI Sequence on Completion").
     * No-op when the form has no code set — every existing chatflow keeps
     * working unchanged.
     */
    public function on_chat_form_submission($form_id, $answers, $submission_id)
    {
        $launch_code = get_post_meta($form_id, '_chat_form_launch_code', true);
        if (!$launch_code) {
            return;
        }
        $this->fire_launch($launch_code, array(
            'source'        => 'chatflow_completion',
            'form_id'       => (int) $form_id,
            'form_title'    => get_the_title($form_id),
            'submission_id' => (int) $submission_id,
        ));
        $this->complete_tracked_instance($launch_code, $answers, $submission_id);
    }

    /**
     * [gend_launch code="LT-xxxx" label="Launch"]
     * Renders a plain link to the click-through REST route. Usable in any
     * post/page/widget on the site — not tied to a specific chatflow.
     */
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'code'  => '',
            'label' => __('Launch', 'chat-forms'),
            'class' => 'gend-launch-link',
        ), $atts, 'gend_launch');

        $code = sanitize_text_field($atts['code']);
        if ($code === '') {
            return '';
        }

        $url = rest_url('em/v1/launch/' . rawurlencode($code));
        return sprintf(
            '<a href="%s" class="%s" data-gend-launch-code="%s">%s</a>',
            esc_url($url),
            esc_attr($atts['class']),
            esc_attr($code),
            esc_html($atts['label'])
        );
    }

    public function register_routes()
    {
        register_rest_route('em/v1', '/launch/(?P<code>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_launch_click'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'code' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        // Desktop-app bridge for the Sequences popup's Launch Trigger
        // "Chatflow" mode (see chatflows:* IPC in the Electron app). These
        // are the ONLY authenticated routes in this class — the caller is
        // the account owner's own OAuth bearer (same one 'auth:memberships'
        // uses), never a logged-out visitor, so no rest_authentication_errors
        // allow-listing is needed here.
        register_rest_route('em/v1', '/chatflows', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'list_chatflows'),
                'permission_callback' => array($this, 'require_edit_posts'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'create_chatflow'),
                'permission_callback' => array($this, 'require_edit_posts'),
            ),
        ));
        // No extra 'id' arg validation here — the route's own \d+ regex
        // already guarantees a numeric id. (A bare 'is_numeric' validate_callback
        // fatals on PHP 8: WP calls it as is_numeric($value, $request, $param),
        // and is_numeric is a PHP built-in that throws ArgumentCountError on
        // extra args, unlike a plain PHP-defined function.)
        register_rest_route('em/v1', '/chatflows/(?P<id>\d+)/link', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'link_chatflow'),
            'permission_callback' => array($this, 'require_edit_posts'),
        ));
        // Full detail (GET) + full-question save (POST) for the desktop
        // app's native Chatflow Editor — see get_chatflow()/save_chatflow().
        register_rest_route('em/v1', '/chatflows/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_chatflow'),
                'permission_callback' => array($this, 'require_edit_posts'),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'save_chatflow'),
                'permission_callback' => array($this, 'require_edit_posts'),
            ),
        ));

        // Project Management "chatflow" task-type — TRACKED launches (see
        // OPT_LAUNCHED_META above). Same auth posture as the routes above:
        // the desktop's own OAuth bearer, never a logged-out visitor.
        register_rest_route('em/v1', '/chatflows/launch', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'launch_tracked_instance'),
            'permission_callback' => array($this, 'require_edit_posts'),
        ));
        register_rest_route('em/v1', '/launch-bind', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'bind_launch_code'),
            'permission_callback' => array($this, 'require_edit_posts'),
        ));
        register_rest_route('em/v1', '/launch-targets', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'list_launch_targets'),
            'permission_callback' => array($this, 'require_edit_posts'),
        ));
        register_rest_route('em/v1', '/chatflows/launched', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'list_launched'),
            'permission_callback' => array($this, 'require_edit_posts'),
        ));
        register_rest_route('em/v1', '/chatflows/launched/(?P<id>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_launched'),
            'permission_callback' => array($this, 'require_edit_posts'),
        ));
    }

    /** Read one gdc_email_list_settings value for a list. */
    private function get_list_setting($list_id, $key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'gdc_email_list_settings';
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table} WHERE list_id = %d AND setting_key = %s",
            (int) $list_id,
            $key
        ));
    }

    /** Upsert one gdc_email_list_settings value for a list. */
    private function set_list_setting($list_id, $key, $value)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'gdc_email_list_settings';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE list_id = %d AND setting_key = %s",
            (int) $list_id,
            $key
        ));
        if ($id) {
            $wpdb->update($table, array('setting_value' => (string) $value), array('id' => (int) $id));
        } else {
            $wpdb->insert($table, array(
                'list_id'       => (int) $list_id,
                'setting_key'   => $key,
                'setting_value' => (string) $value,
            ));
        }
    }

    /** "Joined an email list" sequence trigger — fires the bound launch code. */
    public function on_list_subscriber_added($list_id, $subscriber_id)
    {
        $code = $this->get_list_setting((int) $list_id, 'launch_code');
        if ($code === '') {
            return;
        }
        $this->fire_launch($code, array(
            'source'        => 'list_join',
            'list_id'       => (int) $list_id,
            'subscriber_id' => (int) $subscriber_id,
        ));
    }

    /**
     * POST em/v1/launch-bind { kind, target_id, code, group_id? } — binds a
     * sequence launch code to a start-trigger target on the linked app:
     * kind 'chatflow'|'form' → _chat_form_launch_code post meta (both CPTs
     * share the completion hook); kind 'list' → the list's launch_code
     * setting (fired by on_list_subscriber_added).
     */
    public function bind_launch_code(WP_REST_Request $request)
    {
        $switched = $this->maybe_switch_to_group_site($request);
        $kind = sanitize_key((string) $request->get_param('kind'));
        $tid  = (int) $request->get_param('target_id');
        $code = sanitize_text_field((string) $request->get_param('code'));
        $res  = null;
        if ($tid <= 0 || $code === '' || !in_array($kind, array('chatflow', 'form', 'list'), true)) {
            $res = new WP_Error('em_bind_bad', __('kind, target_id and code are required.', 'chat-forms'), array('status' => 400));
        } elseif ('list' === $kind) {
            $this->set_list_setting($tid, 'launch_code', $code);
            $res = rest_ensure_response(array('ok' => true, 'kind' => 'list', 'target_id' => $tid));
        } else {
            $post = get_post($tid);
            if (!$post || !in_array($post->post_type, array('chat_form', 'basic_form'), true)) {
                $res = new WP_Error('em_bind_not_found', __('Target not found.', 'chat-forms'), array('status' => 404));
            } else {
                update_post_meta($tid, '_chat_form_launch_code', $code);
                $res = rest_ensure_response(array('ok' => true, 'kind' => $kind, 'target_id' => $tid));
            }
        }
        if ($switched) {
            restore_current_blog();
        }
        return $res;
    }

    /**
     * GET em/v1/launch-targets?group_id= — everything a sequence start
     * trigger can bind to on the linked app: chatflows, standalone forms,
     * and email lists.
     */
    public function list_launch_targets(WP_REST_Request $request)
    {
        $switched = $this->maybe_switch_to_group_site($request);

        $chatflows = array();
        foreach (get_posts(array('post_type' => 'chat_form', 'post_status' => array('publish', 'draft'), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC')) as $cf) {
            $chatflows[] = array('id' => $cf->ID, 'title' => get_the_title($cf));
        }
        $forms = array();
        foreach (get_posts(array('post_type' => 'basic_form', 'post_status' => array('publish', 'draft'), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC')) as $bf) {
            $forms[] = array('id' => $bf->ID, 'title' => get_the_title($bf));
        }
        $lists = array();
        if (function_exists('em_get_all_lists')) {
            foreach ((array) em_get_all_lists() as $l) {
                if (is_array($l) && isset($l['id'])) {
                    $lists[] = array('id' => (int) $l['id'], 'title' => (string) ($l['name'] ?? ('List #' . $l['id'])));
                }
            }
        }

        if ($switched) {
            restore_current_blog();
        }
        return rest_ensure_response(array('chatflows' => $chatflows, 'forms' => $forms, 'lists' => $lists));
    }

    /**
     * ?group_id= support: the widget asks for a GROUP's chatflows — the Talk
     * Flows catalog of the group's LINKED web app (gdc_site_id), not the
     * hub's. Switches blog context when the linked site differs; caller must
     * restore_current_blog() when this returns true.
     */
    private function maybe_switch_to_group_site(WP_REST_Request $request)
    {
        $gid = (int) $request->get_param('group_id');
        if ($gid <= 0 || !is_multisite() || !function_exists('groups_get_groupmeta')) {
            return false;
        }
        $site_id = (int) groups_get_groupmeta($gid, 'gdc_site_id', true);
        if ($site_id <= 0 || $site_id === get_current_blog_id()) {
            return false;
        }
        switch_to_blog($site_id);
        return true;
    }

    public function require_edit_posts()
    {
        return current_user_can('edit_posts')
            ? true
            : new WP_Error('em_launch_forbidden', 'You do not have permission to manage chatflows.', array('status' => 403));
    }

    /* -----------------------------------------------------------------
     * Tracked launches (`_gend_launched_chatflows` on the launching
     * user) — mirrors the Phase 39 `_gend_devices` registry pattern:
     * member-scoped meta, upsert-by-id, every field defensively read.
     * -------------------------------------------------------------- */

    private function get_launched_records($user_id)
    {
        $raw = get_user_meta((int) $user_id, self::OPT_LAUNCHED_META, true);
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $rec) {
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        return $out;
    }

    private function save_launched_records($user_id, $records)
    {
        update_user_meta((int) $user_id, self::OPT_LAUNCHED_META, array_values($records));
    }

    /**
     * POST /em/v1/chatflows/launch { chatflow_id, task_id } — creates a
     * tracked launch instance (every gend.me chat widget lists these +
     * shows pending/complete) and fires the chatflow's own Launch Trigger
     * mechanism exactly like a real visitor completing it would.
     */
    public function launch_tracked_instance(WP_REST_Request $request)
    {
        $switched = $this->maybe_switch_to_group_site($request);
        $res = $this->launch_tracked_instance_body($request);
        if ($switched) {
            restore_current_blog();
        }
        return $res;
    }

    private function launch_tracked_instance_body(WP_REST_Request $request)
    {
        $uid = get_current_user_id();
        if (!$uid) {
            return new WP_Error('em_launch_auth', __('Authentication required.', 'chat-forms'), array('status' => 401));
        }

        $chatflow_id = (int) $request->get_param('chatflow_id');
        $post = get_post($chatflow_id);
        if (!$post || $post->post_type !== 'chat_form') {
            return new WP_Error('em_launch_not_found', __('Chatflow not found.', 'chat-forms'), array('status' => 404));
        }
        $code = (string) get_post_meta($chatflow_id, '_chat_form_launch_code', true);
        if ($code === '') {
            return new WP_Error('em_launch_no_code', __('This chatflow has no Launch Trigger linked yet.', 'chat-forms'), array('status' => 400));
        }

        $record = array(
            'id'             => wp_generate_uuid4(),
            'chatflow_id'    => $chatflow_id,
            'chatflow_title' => get_the_title($chatflow_id),
            'launch_code'    => $code,
            'task_id'        => sanitize_text_field((string) $request->get_param('task_id')),
            'status'         => 'pending',
            'launched_at'    => time(),
            'completed_at'   => 0,
            'submission_id'  => 0,
            'answers_digest' => '',
        );

        $records = $this->get_launched_records($uid);
        $records[] = $record;
        // Cap history so this meta can't grow unbounded — keep the 100 most recent.
        if (count($records) > 100) {
            $records = array_slice($records, -100);
        }
        $this->save_launched_records($uid, $records);

        // O(1) launch_code -> {user_id, record_id} index for the completion
        // hook below, which has no idea WHICH desktop user launched this —
        // the person completing the chatflow is very often someone else
        // entirely (a customer, a teammate). 24h TTL comfortably covers a
        // real visitor's completion time.
        set_transient('gend_lc_idx_' . md5($code), array('user_id' => $uid, 'record_id' => $record['id']), DAY_IN_SECONDS);

        $this->fire_launch($code, array(
            'source'  => 'pm_task_launch',
            'task_id' => $record['task_id'],
        ));

        // Agent-Messages mode: the flow ALSO runs as profile DMs from the
        // Bot Agent — first question sends immediately; replies advance it
        // (see em_cf_msgflow_on_message below).
        if (function_exists('em_cf_msgflow_start') && '1' === get_post_meta($chatflow_id, '_chat_form_agent_messages', true)) {
            em_cf_msgflow_start($chatflow_id, $uid);
        }

        return rest_ensure_response($record);
    }

    /**
     * GET /em/v1/chatflows/launched — the current user's launched-chatflow
     * registry. Every gend.me chat widget (Members/Agents/Projects DMs +
     * the Agent Profile popup) polls this to list launches + their status.
     */
    public function list_launched(WP_REST_Request $request)
    {
        $uid = get_current_user_id();
        if (!$uid) {
            return new WP_Error('em_launch_auth', __('Authentication required.', 'chat-forms'), array('status' => 401));
        }
        return rest_ensure_response(array('launched' => $this->get_launched_records($uid)));
    }

    /** GET /em/v1/chatflows/launched/<id> — single record (desktop poll target). */
    public function get_launched(WP_REST_Request $request)
    {
        $uid = get_current_user_id();
        if (!$uid) {
            return new WP_Error('em_launch_auth', __('Authentication required.', 'chat-forms'), array('status' => 401));
        }
        $id = (string) $request->get_param('id');
        foreach ($this->get_launched_records($uid) as $rec) {
            if (isset($rec['id']) && $rec['id'] === $id) {
                return rest_ensure_response($rec);
            }
        }
        return new WP_Error('em_launch_not_found', __('Launch instance not found.', 'chat-forms'), array('status' => 404));
    }

    /**
     * Marks a tracked launch instance complete, if one exists for this
     * launch code — found via the O(1) transient index set at launch time.
     * No-op (not an error) when no tracked instance was launched for this
     * code, which is the common case (most completions are real visitors
     * hitting a chatflow that was never launched from a PM task).
     */
    private function complete_tracked_instance($launch_code, $answers, $submission_id)
    {
        $idx = get_transient('gend_lc_idx_' . md5($launch_code));
        if (!is_array($idx) || empty($idx['user_id']) || empty($idx['record_id'])) {
            return;
        }

        $uid = (int) $idx['user_id'];
        $records = $this->get_launched_records($uid);
        $found = false;
        foreach ($records as &$rec) {
            if (isset($rec['id']) && $rec['id'] === $idx['record_id'] && $rec['status'] === 'pending') {
                $rec['status']         = 'complete';
                $rec['completed_at']   = time();
                $rec['submission_id']  = (int) $submission_id;
                $rec['answers_digest'] = $this->build_answers_digest($answers);
                $found = true;
                break;
            }
        }
        unset($rec);
        if ($found) {
            $this->save_launched_records($uid, $records);
            delete_transient('gend_lc_idx_' . md5($launch_code));
        }
    }

    /**
     * Compact plain-text "Question: Answer" digest for the "Build Prompt"
     * task-type to consume. Mirrors build_html_email()'s own answer-shape
     * detection (Chat_Forms_Ajax) so both stay in sync with the real
     * submission format: each entry is either {question,answer} or a
     * scalar keyed by field name.
     */
    private function build_answers_digest($answers)
    {
        if (!is_array($answers)) {
            return '';
        }
        $lines = array();
        foreach ($answers as $key => $answer) {
            if (is_array($answer)) {
                $question = isset($answer['question']) ? (string) $answer['question'] : (string) $key;
                $ans = isset($answer['answer']) ? (string) $answer['answer'] : '';
            } else {
                $question = is_numeric($key) ? ('Question ' . (intval($key) + 1)) : ucwords(str_replace('_', ' ', (string) $key));
                $ans = (string) $answer;
            }
            $question = trim(wp_strip_all_tags($question));
            $ans = trim(wp_strip_all_tags($ans));
            if ($ans === '') {
                continue;
            }
            $lines[] = "{$question}: {$ans}";
        }
        $digest = implode("\n", $lines);
        return strlen($digest) > 4000 ? (substr($digest, 0, 4000) . '…') : $digest;
    }

    /**
     * GET /em/v1/chatflows — every chat_form post, for the desktop app's
     * Launch Trigger "Chatflow" picker. Includes whatever launch code (if
     * any) is already wired to each one, so re-picking one that's already
     * linked elsewhere is a visible, deliberate overwrite rather than a
     * silent surprise.
     */
    public function list_chatflows(WP_REST_Request $request)
    {
        $switched = $this->maybe_switch_to_group_site($request);
        $posts = get_posts(array(
            'post_type'      => 'chat_form',
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
        $out = array();
        foreach ($posts as $p) {
            $questions = get_post_meta($p->ID, '_chat_form_questions', true);
            if (!is_array($questions)) {
                $questions = array();
            }
            $has_ai_prompt = false;
            foreach ($questions as $q) {
                if (is_array($q) && isset($q['type']) && $q['type'] === 'prompt_response') {
                    $has_ai_prompt = true;
                    break;
                }
            }
            $out[] = array(
                'id'             => $p->ID,
                'title'          => get_the_title($p),
                'status'         => $p->post_status,
                'launch_code'    => (string) get_post_meta($p->ID, '_chat_form_launch_code', true),
                'edit_url'       => admin_url('post.php?post=' . $p->ID . '&action=edit'),
                'question_count' => count($questions),
                'has_ai_prompt'  => $has_ai_prompt,
                'modified'       => $p->post_modified_gmt,
            );
        }
        if ($switched) {
            restore_current_blog();
        }
        return rest_ensure_response($out);
    }

    /**
     * POST /em/v1/chatflows { title } — a bare draft stub. The desktop app
     * opens `edit_url` in the browser right after so the actual Q&A
     * question-building happens in the REAL chatflow editor, not a
     * duplicate of it inside the desktop app.
     */
    public function create_chatflow(WP_REST_Request $request)
    {
        $title = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') {
            $title = 'New chatflow ' . gmdate('Y-m-d H:i');
        }
        $id = wp_insert_post(array(
            'post_type'   => 'chat_form',
            'post_title'  => $title,
            'post_status' => 'draft',
        ), true);
        if (is_wp_error($id)) {
            return $id;
        }
        return rest_ensure_response(array(
            'id'          => $id,
            'title'       => $title,
            'status'      => 'draft',
            'launch_code' => '',
            'edit_url'    => admin_url('post.php?post=' . $id . '&action=edit'),
        ));
    }

    /**
     * POST /em/v1/chatflows/<id>/link { launch_code } — wires a Launch
     * Trigger's code onto a chatflow's "Launch AI Sequence on Completion"
     * meta remotely, so picking a chatflow in the desktop app is enough —
     * no manual copy-paste into the WP admin field required.
     */
    public function link_chatflow(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'chat_form') {
            return new WP_Error('em_launch_not_found', 'Chatflow not found.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('em_launch_forbidden', 'You cannot edit this chatflow.', array('status' => 403));
        }
        $code = sanitize_text_field((string) $request->get_param('launch_code'));
        update_post_meta($id, '_chat_form_launch_code', $code);
        return rest_ensure_response(array('ok' => true, 'id' => $id, 'launch_code' => $code));
    }

    /**
     * GET /em/v1/chatflows/<id> — full detail for the desktop app's native
     * Chatflow Editor: every question (verbatim from `_chat_form_questions`,
     * same shape Chat_Forms_Ajax::get_questions() sends the public widget —
     * see class-chat-forms-ajax.php) plus the launch code + a few Success
     * Message fields the editor round-trips even though it doesn't expose
     * UI for them yet.
     */
    public function get_chatflow(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'chat_form') {
            return new WP_Error('em_launch_not_found', 'Chatflow not found.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('em_launch_forbidden', 'You cannot view this chatflow.', array('status' => 403));
        }
        $questions = get_post_meta($id, '_chat_form_questions', true);
        if (!is_array($questions)) {
            $questions = array();
        }
        return rest_ensure_response(array(
            'id'                => $id,
            'title'             => get_the_title($id),
            'status'            => $post->post_status,
            'questions'         => array_values($questions),
            'launch_code'       => (string) get_post_meta($id, '_chat_form_launch_code', true),
            'thank_you_message' => (string) get_post_meta($id, '_chat_form_thank_you_message', true),
            'redirect_url'      => (string) get_post_meta($id, '_chat_form_redirect_url', true),
            'edit_url'          => admin_url('post.php?post=' . $id . '&action=edit'),
        ));
    }

    /**
     * POST /em/v1/chatflows/<id> { title?, questions?, launch_code? } — the
     * Chatflow Editor's Save. `_chat_form_questions` is ALWAYS a full
     * overwrite on the wp-admin side (Chat_Forms_Admin::save_questions(),
     * class-chat-forms-admin.php) — there is no per-question merge with
     * whatever was there before. This mirrors that same full-overwrite
     * contract AND the exact same field-by-field sanitization, so a
     * chatflow built partly in wp-admin and partly here round-trips
     * losslessly: the desktop app must always submit the COMPLETE question
     * list (every question it loaded via GET, edited or not) or fields it
     * didn't touch will be silently dropped, same as the wp-admin editor.
     */
    public function save_chatflow(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $post = get_post($id);
        if (!$post || $post->post_type !== 'chat_form') {
            return new WP_Error('em_launch_not_found', 'Chatflow not found.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('em_launch_forbidden', 'You cannot edit this chatflow.', array('status' => 403));
        }

        $title = $request->get_param('title');
        if (is_string($title) && trim($title) !== '') {
            wp_update_post(array('ID' => $id, 'post_title' => sanitize_text_field($title)));
        }

        $raw_questions = $request->get_param('questions');
        if (is_array($raw_questions)) {
            $questions = array();
            foreach ($raw_questions as $question_data) {
                if (!is_array($question_data)) {
                    continue;
                }
                $sanitized_question = array(
                    'text' => isset($question_data['text']) ? sanitize_text_field($question_data['text']) : '',
                    'type' => isset($question_data['type']) ? sanitize_text_field($question_data['type']) : 'text',
                );

                if (isset($question_data['options']) && is_array($question_data['options'])) {
                    $sanitized_question['options'] = array();
                    foreach ($question_data['options'] as $option_data) {
                        if (!is_array($option_data)) {
                            continue;
                        }
                        $sanitized_question['options'][] = array(
                            'label'         => isset($option_data['label']) ? sanitize_text_field($option_data['label']) : '',
                            'value'         => isset($option_data['value']) ? sanitize_text_field($option_data['value']) : '',
                            'image'         => isset($option_data['image']) ? esc_url_raw($option_data['image']) : '',
                            'response_html' => isset($option_data['response_html']) ? wp_kses_post($option_data['response_html']) : '',
                        );
                    }
                }

                if (isset($question_data['content'])) {
                    $sanitized_question['content'] = wp_kses_post($question_data['content']);
                }

                if (isset($question_data['prompt'])) {
                    $sanitized_question['prompt'] = sanitize_textarea_field($question_data['prompt']);
                }

                if (isset($question_data['pays'])) {
                    $pays_raw = sanitize_text_field($question_data['pays']);
                    $sanitized_question['pays'] = in_array($pays_raw, array('site', 'admin', 'chat_user', 'member'), true)
                        ? $pays_raw : 'site';
                    if ($sanitized_question['pays'] === 'member') {
                        $email = isset($question_data['pays_user_email']) ? sanitize_email($question_data['pays_user_email']) : '';
                        $sanitized_question['pays_user_email'] = $email;
                        $sanitized_question['pays_user_id'] = 0;
                        if ($email) {
                            $u = get_user_by('email', $email);
                            if ($u) {
                                $sanitized_question['pays_user_id'] = $u->ID;
                            }
                        }
                    } else {
                        $sanitized_question['pays_user_email'] = '';
                        $sanitized_question['pays_user_id'] = 0;
                    }
                }

                if (isset($question_data['validation']) && is_array($question_data['validation'])) {
                    $v = $question_data['validation'];
                    $sanitized_question['validation'] = array(
                        'required'      => !empty($v['required']),
                        'min_length'    => isset($v['min_length']) ? absint($v['min_length']) : 0,
                        'max_length'    => isset($v['max_length']) ? absint($v['max_length']) : 0,
                        'error_message' => isset($v['error_message']) ? sanitize_text_field($v['error_message']) : '',
                    );
                }

                if (isset($question_data['conditional']) && is_array($question_data['conditional'])) {
                    $c = $question_data['conditional'];
                    $rules = array();
                    if (isset($c['rules']) && is_array($c['rules'])) {
                        foreach ($c['rules'] as $rule) {
                            if (!is_array($rule)) {
                                continue;
                            }
                            $rules[] = array(
                                'question' => isset($rule['question']) ? sanitize_text_field($rule['question']) : '',
                                'operator' => isset($rule['operator']) ? sanitize_text_field($rule['operator']) : 'equals',
                                'value'    => isset($rule['value']) ? sanitize_text_field($rule['value']) : '',
                            );
                        }
                    }
                    $sanitized_question['conditional'] = array(
                        'enabled' => !empty($c['enabled']) ? 1 : 0,
                        'logic'   => isset($c['logic']) ? sanitize_text_field($c['logic']) : 'all',
                        'rules'   => $rules,
                    );
                }

                $questions[] = $sanitized_question;
            }
            update_post_meta($id, '_chat_form_questions', $questions);
        }

        if ($request->has_param('launch_code')) {
            update_post_meta($id, '_chat_form_launch_code', sanitize_text_field((string) $request->get_param('launch_code')));
        }

        return rest_ensure_response(array('ok' => true, 'id' => $id));
    }

    /**
     * GET /em/v1/launch/<code> — the [gend_launch] link's destination.
     * Fires the launch, then redirects back to the referrer (or home)
     * with `?gend_launched=1` so gend-launch-open-widget.js can reveal
     * the AI chat widget as the visible confirmation. Never renders its
     * own page — the widget IS the landing experience.
     */
    public function handle_launch_click(WP_REST_Request $request)
    {
        $code = (string) $request->get_param('code');
        $this->fire_launch($code, array('source' => 'link_click'));

        $redirect = $request->get_param('redirect');
        $back = $redirect ? esc_url_raw($redirect) : (wp_get_referer() ?: home_url('/'));
        wp_safe_redirect(add_query_arg('gend_launched', '1', $back));
        exit;
    }

    /**
     * Only enqueue the (tiny) widget-opener script on the request that's
     * landing back from a launch click — no reason to ship it site-wide.
     */
    public function maybe_enqueue_widget_opener()
    {
        if (!isset($_GET['gend_launched'])) {
            return;
        }
        wp_enqueue_script(
            'gend-launch-open-widget',
            EMAIL_MANAGER_URL . 'assets/forms/gend-launch-open-widget.js',
            array(),
            '1.0.0',
            true
        );
    }
}


/* ─────────────────────────────────────────────────────────────────────────────
   Agent-Messages chatflow runner (Talk Flows × Social Profiles)
   ─────────────────────────────────────────────────────────────────────────────
   When a chatflow has "Run through Agent Messages" enabled + a Bot Agent,
   launching it starts a BP private-message conversation: the agent sends
   question 1; every member reply in that thread records the answer and sends
   the next question; the last reply stores a chat_submission and fires
   do_action('chat_form_submission') — the exact pipeline the chat UI uses
   (notifications, launch triggers, list joins, tracked-instance completion).

   State lives in a NETWORK option keyed by thread id (site options, not
   per-blog) because a launch can happen under switch_to_blog(container)
   while the member's replies arrive on hub requests. The question list,
   title, thank-you text and the form's blog id are SNAPSHOTTED into the
   state so no cross-site post reads happen at reply time.
   ──────────────────────────────────────────────────────────────────────── */

function em_cf_msgflow_state_key($thread_id)
{
    return 'em_cf_msgflow_' . (int) $thread_id;
}

/** Normalized question list for message delivery. */
function em_cf_msgflow_questions($form_id)
{
    $raw = get_post_meta((int) $form_id, '_chat_form_questions', true);
    if (!is_array($raw)) return array();
    $out = array();
    foreach ($raw as $q) {
        if (!is_array($q)) continue;
        $text = trim(wp_strip_all_tags((string) ($q['text'] ?? '')));
        if ($text === '') continue;
        $opts = array();
        $rawOpts = $q['options'] ?? array();
        if (is_string($rawOpts)) $rawOpts = preg_split('/\r?\n/', $rawOpts);
        foreach ((array) $rawOpts as $o) {
            if (is_scalar($o) && trim((string) $o) !== '') $opts[] = trim((string) $o);
            elseif (is_array($o) && isset($o['label'])) $opts[] = trim((string) $o['label']);
        }
        $out[] = array('text' => $text, 'options' => $opts);
    }
    return $out;
}

function em_cf_msgflow_format_q($q, $title = '')
{
    $msg = ($title !== '' ? ('💬 ' . $title . "\n\n") : '') . $q['text'];
    if (!empty($q['options'])) {
        $msg .= "\n\n" . __('Reply with one of:', 'chat-forms') . ' ' . implode(' · ', $q['options']);
    }
    return $msg;
}

/** Reuse the member's existing 1:1 thread with the agent (same lookup the
 *  agent-send route uses) so flows never fragment into new threads. */
function em_cf_msgflow_thread($agent_id, $user_id)
{
    if (!class_exists('BP_Messages_Thread') || !method_exists('BP_Messages_Thread', 'get_current_threads_for_user')) return 0;
    if (!function_exists('em_chat_thread_summary')) return 0;
    $res = BP_Messages_Thread::get_current_threads_for_user(array(
        'user_id' => (int) $user_id,
        'box'     => 'inbox',
        'limit'   => 100,
        'page'    => 1,
    ));
    if (empty($res['threads'])) return 0;
    foreach ($res['threads'] as $th) {
        $sum = em_chat_thread_summary($th->thread_id, (int) $user_id);
        if (!$sum || count($sum['others']) !== 1) continue;
        if ((int) $sum['others'][0]['user_id'] === (int) $agent_id) return (int) $th->thread_id;
    }
    return 0;
}

/** Kick off the message flow: send question 1 as the agent + persist state. */
function em_cf_msgflow_start($form_id, $user_id)
{
    if (!function_exists('messages_new_message')) return false;
    $form_id  = (int) $form_id;
    $user_id  = (int) $user_id;
    $agent_id = (int) get_post_meta($form_id, '_chat_form_bot_agent_id', true);
    if (!$agent_id || !$user_id || !get_userdata($agent_id)) return false;
    $qs = em_cf_msgflow_questions($form_id);
    if (empty($qs)) return false;

    $title  = get_the_title($form_id);
    $thanks = trim(wp_strip_all_tags((string) get_post_meta($form_id, '_chat_form_thank_you_message', true)));

    $thread_id = em_cf_msgflow_thread($agent_id, $user_id);
    $first = em_cf_msgflow_format_q($qs[0], $title);
    if ($thread_id > 0) {
        $sent = messages_new_message(array('thread_id' => $thread_id, 'sender_id' => $agent_id, 'content' => $first));
    } else {
        $sent = messages_new_message(array('sender_id' => $agent_id, 'recipients' => array($user_id), 'subject' => $title ?: __('Chatflow', 'chat-forms'), 'content' => $first));
        $thread_id = is_wp_error($sent) ? 0 : (int) $sent;
    }
    if (!$sent || is_wp_error($sent) || !$thread_id) return false;

    update_site_option(em_cf_msgflow_state_key($thread_id), array(
        'form_id'  => $form_id,
        'site_id'  => get_current_blog_id(),
        'user_id'  => $user_id,
        'agent_id' => $agent_id,
        'title'    => (string) $title,
        'thanks'   => $thanks,
        'qs'       => $qs,
        'q_index'  => 0,
        'answers'  => array(),
        'started'  => time(),
    ));
    return $thread_id;
}

/** Member replies advance the flow; the last reply completes it. */
add_action('messages_message_sent', 'em_cf_msgflow_on_message');
function em_cf_msgflow_on_message($message)
{
    static $busy = false;
    if ($busy) return; // our own agent sends re-enter this hook
    if (!is_object($message) || empty($message->thread_id)) return;

    $key = em_cf_msgflow_state_key((int) $message->thread_id);
    $st  = get_site_option($key);
    if (!is_array($st) || empty($st['qs'])) return;
    if ((int) $message->sender_id !== (int) $st['user_id']) return; // only member replies advance

    $answer = trim(wp_strip_all_tags((string) $message->message));
    $idx = (int) $st['q_index'];
    if (!isset($st['qs'][$idx])) { delete_site_option($key); return; }

    $st['answers'][] = array('question' => (string) $st['qs'][$idx]['text'], 'answer' => $answer);
    $idx++;

    $busy = true;
    if (isset($st['qs'][$idx])) {
        $st['q_index'] = $idx;
        update_site_option($key, $st);
        messages_new_message(array(
            'thread_id' => (int) $message->thread_id,
            'sender_id' => (int) $st['agent_id'],
            'content'   => em_cf_msgflow_format_q($st['qs'][$idx]),
        ));
        $busy = false;
        return;
    }

    // ── Complete: store the submission ON THE FORM'S SITE + fire the same
    // pipeline the chat UI does, then thank the member in-thread.
    delete_site_option($key);
    $form_site = (int) ($st['site_id'] ?? get_current_blog_id());
    $switched  = is_multisite() && $form_site && $form_site !== get_current_blog_id();
    if ($switched) switch_to_blog($form_site);
    $submission_id = wp_insert_post(array(
        'post_title'  => 'Submission for ' . (string) $st['title'] . ' - ' . gmdate('Y-m-d H:i:s'),
        'post_type'   => 'chat_submission',
        'post_status' => 'publish',
        'post_author' => (int) $st['user_id'],
    ));
    if (!is_wp_error($submission_id)) {
        update_post_meta($submission_id, '_chat_submission_form_id', (int) $st['form_id']);
        update_post_meta($submission_id, '_chat_submission_data', $st['answers']);
        do_action('chat_form_submission', (int) $st['form_id'], $st['answers'], $submission_id);
    }
    if ($switched) restore_current_blog();

    $thanks = (string) ($st['thanks'] ?? '');
    messages_new_message(array(
        'thread_id' => (int) $message->thread_id,
        'sender_id' => (int) $st['agent_id'],
        'content'   => $thanks !== '' ? $thanks : __('Thanks — all done! Your responses have been recorded. ✅', 'chat-forms'),
    ));
    $busy = false;
}
