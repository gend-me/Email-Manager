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

    public function __construct()
    {
        add_action('chat_form_submission', array($this, 'on_chat_form_submission'), 10, 3);
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
    }

    public function require_edit_posts()
    {
        return current_user_can('edit_posts')
            ? true
            : new WP_Error('em_launch_forbidden', 'You do not have permission to manage chatflows.', array('status' => 403));
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
        $posts = get_posts(array(
            'post_type'      => 'chat_form',
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
        $out = array();
        foreach ($posts as $p) {
            $out[] = array(
                'id'          => $p->ID,
                'title'       => get_the_title($p),
                'status'      => $p->post_status,
                'launch_code' => (string) get_post_meta($p->ID, '_chat_form_launch_code', true),
                'edit_url'    => admin_url('post.php?post=' . $p->ID . '&action=edit'),
            );
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
