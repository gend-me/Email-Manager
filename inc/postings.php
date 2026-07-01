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
    const META_EMAILS      = '_em_posting_emails';
    const META_PROCESS     = '_em_posting_process';
    const META_CONTRACT    = '_em_posting_contract';
    const META_LIST        = '_em_posting_list';
    const META_VIEWS       = '_em_posting_views';
    const META_SUBMISSIONS = '_em_posting_submissions';
    const META_DAILY       = '_em_posting_daily';
    const META_LANDING_PAGE  = '_em_posting_landing_page_id';
    const META_THANKYOU_PAGE = '_em_posting_thankyou_page_id';
    const PAGE_OWNER_META    = '_em_posting_owner';
    const PAGE_ROLE_META     = '_em_posting_page_role';
    const SUBMISSION_TAG    = '_em_posting_id';
    const REWRITE_VERSION   = '_em_postings_rw_v';
    const REWRITE_VERSION_N = '2';

    public function __construct()
    {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'maybe_flush_rewrite'], 99);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'maybe_render_landing']);

        // Owned landing/thank-you pages use the reliable classic editor (so they
        // iframe cleanly) and surface the form shortcode in their sidebar.
        add_filter('use_block_editor_for_post', [$this, 'force_classic_editor'], 10, 2);
        add_action('add_meta_boxes', [$this, 'register_shortcode_metabox'], 10, 2);

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
        add_action('wp_ajax_em_get_posting_analytics', [$this, 'ajax_get_analytics']);
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

    public function force_classic_editor($use, $post)
    {
        if ($post && get_post_meta($post->ID, self::PAGE_OWNER_META, true)) {
            return false;
        }
        return $use;
    }

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

        $pid = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_content' => $content,
        ]);
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

    public static function default_emails()
    {
        return [
            'applicant_enabled' => 1,
            'applicant_subject' => __('We received your application', 'email-manager'),
            'applicant_body'    => __("Hi {applicant_name},\n\nThanks for applying to {posting_title}. We've received your application and our team will review it shortly.\n\n— {site_name}", 'email-manager'),
            'admin_enabled'     => 1,
            'admin_email'       => get_option('admin_email'),
            'admin_subject'     => __('New application: {posting_title}', 'email-manager'),
            'admin_body'        => __("A new application was submitted for {posting_title}.\n\nApplicant: {applicant_name}\nEmail: {applicant_email}\n\n{all_answers}", 'email-manager'),
        ];
    }

    public static function get_landing($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_LANDING, true), self::default_landing());
    }

    public static function get_thankyou($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_THANKYOU, true), self::default_thankyou());
    }

    public static function get_emails($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_EMAILS, true), self::default_emails());
    }

    public static function default_process()
    {
        return [
            ['title' => __('Apply', 'email-manager'),    'desc' => __('Submit your application through the form below.', 'email-manager')],
            ['title' => __('Review', 'email-manager'),   'desc' => __('Our team reviews every application carefully.', 'email-manager')],
            ['title' => __('Decision', 'email-manager'), 'desc' => __("We'll reach out with next steps within a few days.", 'email-manager')],
        ];
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
            $clean[] = ['title' => $title, 'desc' => $desc];
        }
        return $clean;
    }

    public static function default_contract()
    {
        return [
            'enabled'        => 0,
            'title'          => __('Applicant Agreement', 'email-manager'),
            'body'           => '',
            'require_accept' => 1,
        ];
    }

    public static function get_contract($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_CONTRACT, true), self::default_contract());
    }

    public static function default_list()
    {
        return ['enabled' => 0, 'list_id' => 0];
    }

    public static function get_list($posting_id)
    {
        return wp_parse_args((array) get_post_meta($posting_id, self::META_LIST, true), self::default_list());
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
            $this->send_posting_emails($posting, $form_id, $answers, $submission_id);
            $this->add_applicant_to_list($posting, $answers);
        }
    }

    /** Sync the applicant into the configured mailing list (fields come from the form). */
    private function add_applicant_to_list($posting, $answers)
    {
        $list = self::get_list($posting->ID);
        if (empty($list['enabled']) || empty($list['list_id'])) return;
        if (!function_exists('em_add_subscriber')) return;

        $email = EM_Applications::extract_email_from_submission($answers);
        if (!$email) return;

        $name  = EM_Applications::extract_name_from_submission($answers, '');
        $first = $name; $last = '';
        if ($name !== '' && strpos($name, ' ') !== false) {
            $parts = explode(' ', $name, 2);
            $first = $parts[0];
            $last  = $parts[1];
        }
        em_add_subscriber($email, $first, $last, [(int) $list['list_id']], 'subscribed');
    }

    private function send_posting_emails($posting, $form_id, $answers, $submission_id)
    {
        $emails = self::get_emails($posting->ID);
        $name   = EM_Applications::extract_name_from_submission($answers, '');
        $email  = EM_Applications::extract_email_from_submission($answers);

        $tokens = [
            '{applicant_name}'  => $name ?: __('there', 'email-manager'),
            '{applicant_email}' => $email ?: '',
            '{posting_title}'   => $posting->post_title,
            '{site_name}'       => get_bloginfo('name'),
            '{site_url}'        => home_url(),
            '{all_answers}'     => $this->answers_to_text($answers),
        ];

        if (!empty($emails['applicant_enabled']) && $email) {
            wp_mail($email, strtr($emails['applicant_subject'], $tokens), strtr($emails['applicant_body'], $tokens));
        }

        if (!empty($emails['admin_enabled']) && !empty($emails['admin_email'])) {
            $recipients = array_filter(array_map('trim', explode(',', $emails['admin_email'])), 'is_email');
            if ($recipients) wp_mail($recipients, strtr($emails['admin_subject'], $tokens), strtr($emails['admin_body'], $tokens));
        }
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

        $landing = [
            'accent'  => sanitize_hex_color(wp_unslash($_POST['landing_accent'] ?? '')) ?: '#6366f1',
            'accent2' => sanitize_hex_color(wp_unslash($_POST['landing_accent2'] ?? '')) ?: '#8b5cf6',
        ];

        $ty_mode  = in_array(($_POST['ty_mode'] ?? ''), ['message', 'redirect', 'page'], true) ? $_POST['ty_mode'] : 'message';
        $thankyou = [
            'mode'         => $ty_mode,
            'message'      => wp_kses_post(wp_unslash($_POST['ty_message'] ?? '')),
            'redirect_url' => esc_url_raw(wp_unslash($_POST['ty_redirect_url'] ?? '')),
            'page_id'      => 0,
        ];

        $emails = [
            'applicant_enabled' => !empty($_POST['email_applicant_enabled']) ? 1 : 0,
            'applicant_subject' => sanitize_text_field(wp_unslash($_POST['email_applicant_subject'] ?? '')),
            'applicant_body'    => sanitize_textarea_field(wp_unslash($_POST['email_applicant_body'] ?? '')),
            'admin_enabled'     => !empty($_POST['email_admin_enabled']) ? 1 : 0,
            'admin_email'       => sanitize_text_field(wp_unslash($_POST['email_admin_email'] ?? '')),
            'admin_subject'     => sanitize_text_field(wp_unslash($_POST['email_admin_subject'] ?? '')),
            'admin_body'        => sanitize_textarea_field(wp_unslash($_POST['email_admin_body'] ?? '')),
        ];

        // Mailing-list sync (configured on the Applicant Email tab).
        $list = [
            'enabled' => !empty($_POST['list_enabled']) ? 1 : 0,
            'list_id' => absint($_POST['list_id'] ?? 0),
        ];

        // Apply Process — parallel title[]/desc[] rows.
        $p_titles = isset($_POST['process_title']) ? (array) $_POST['process_title'] : [];
        $p_descs  = isset($_POST['process_desc'])  ? (array) $_POST['process_desc']  : [];
        $process  = [];
        foreach ($p_titles as $i => $pt) {
            $pt = sanitize_text_field(wp_unslash($pt));
            $pd = isset($p_descs[$i]) ? sanitize_textarea_field(wp_unslash($p_descs[$i])) : '';
            if ($pt === '' && $pd === '') continue;
            $process[] = ['title' => $pt, 'desc' => $pd];
        }

        // Contract.
        $contract = [
            'enabled'        => !empty($_POST['contract_enabled']) ? 1 : 0,
            'title'          => sanitize_text_field(wp_unslash($_POST['contract_title'] ?? '')),
            'body'           => wp_kses_post(wp_unslash($_POST['contract_body'] ?? '')),
            'require_accept' => !empty($_POST['contract_require']) ? 1 : 0,
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
        update_post_meta($posting_id, self::META_EMAILS, $emails);
        update_post_meta($posting_id, self::META_PROCESS, $process);
        update_post_meta($posting_id, self::META_CONTRACT, $contract);
        update_post_meta($posting_id, self::META_LIST, $list);

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
            'emails'      => self::get_emails($id),
            'process'     => self::get_process($id),
            'contract'    => self::get_contract($id),
            'list'        => self::get_list($id),
            'lists'       => self::get_lists(),
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
        ]);
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
        <div class="gdc-subtab-panel" data-subpanel="postings">

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
        $dl  = self::default_landing();
        $de  = self::default_emails();
        $dty = self::default_thankyou();
        ?>
        <div class="em-drawer em-modal" id="em-posting-drawer" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close="1"></div>
            <div class="em-modal__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title" id="em-posting-drawer-title"><?php esc_html_e('New Posting', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" data-close="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-modal__body">
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
                            <button type="button" class="em-pf-step" data-step="process" style="--em-i:1;"><?php esc_html_e('Apply Process', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="contract" style="--em-i:2;"><?php esc_html_e('Contract', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="thankyou" style="--em-i:3;"><?php esc_html_e('Thank You', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="email-applicant" style="--em-i:4;"><?php esc_html_e('Applicant Email', 'email-manager'); ?></button>
                            <button type="button" class="em-pf-step" data-step="email-team" style="--em-i:5;"><?php esc_html_e('Team Email', 'email-manager'); ?></button>
                        </div>

                        <!-- Landing -->
                        <div class="em-pf-pane is-active" data-pane="landing">
                            <div class="em-pf-themebar">
                                <span class="em-pf-themebar__label"><?php esc_html_e('Page theme', 'email-manager'); ?></span>
                                <label class="em-pf-swatch"><?php esc_html_e('Accent', 'email-manager'); ?> <input type="color" name="landing_accent" value="<?php echo esc_attr($dl['accent']); ?>" /></label>
                                <label class="em-pf-swatch"><?php esc_html_e('Accent 2', 'email-manager'); ?> <input type="color" name="landing_accent2" value="<?php echo esc_attr($dl['accent2']); ?>" /></label>
                                <a class="button em-pf-openpage" data-role="landing" href="#" target="_blank" rel="noopener" hidden><?php esc_html_e('Open full editor ↗', 'email-manager'); ?></a>
                            </div>
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
                            <p class="em-pf-hint" style="margin-bottom:12px;"><?php esc_html_e('Outline the steps applicants go through. These appear as a “How it works” section on the landing page.', 'email-manager'); ?></p>
                            <div id="em-process-rows" class="em-process-rows"></div>
                            <button type="button" class="button em-process-add" style="margin-top:10px;"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('Add Step', 'email-manager'); ?></button>
                        </div>

                        <!-- Contract -->
                        <div class="em-pf-pane" data-pane="contract">
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

                        <!-- Applicant Email -->
                        <div class="em-pf-pane" data-pane="email-applicant">
                            <label class="em-pf-switch">
                                <input type="checkbox" name="email_applicant_enabled" value="1" checked />
                                <span><?php esc_html_e('Enabled', 'email-manager'); ?></span>
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Subject', 'email-manager'); ?></span>
                                <input type="text" name="email_applicant_subject" value="<?php echo esc_attr($de['applicant_subject']); ?>" />
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Body', 'email-manager'); ?></span>
                                <textarea name="email_applicant_body" rows="6"><?php echo esc_textarea($de['applicant_body']); ?></textarea>
                            </label>
                            <p class="em-pf-hint"><?php esc_html_e('Tokens: {applicant_name}, {posting_title}, {site_name}, {site_url}', 'email-manager'); ?></p>

                            <!-- Add applicant to a mailing list (synced with the linked form's fields) -->
                            <div class="em-pf-listblock">
                                <label class="em-pf-switch">
                                    <input type="checkbox" name="list_enabled" value="1" />
                                    <span><?php esc_html_e('Add applicants to a mailing list', 'email-manager'); ?></span>
                                </label>
                                <div class="em-pf-listbody">
                                    <div class="em-pf-field">
                                        <span class="em-pf-label"><?php esc_html_e('List', 'email-manager'); ?></span>
                                        <div class="em-pf-inline">
                                            <select name="list_id" id="em-pf-list-select">
                                                <option value="0"><?php esc_html_e('— Select a list —', 'email-manager'); ?></option>
                                            </select>
                                            <button type="button" class="button em-pf-newlist"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> <?php esc_html_e('New List', 'email-manager'); ?></button>
                                        </div>
                                    </div>
                                    <div class="em-pf-field">
                                        <span class="em-pf-label"><?php esc_html_e('Synced from the form', 'email-manager'); ?></span>
                                        <div class="em-pf-syncfields" id="em-pf-syncfields"></div>
                                        <span class="em-pf-hint"><?php esc_html_e('These fields from the linked form are saved with each subscriber.', 'email-manager'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Team Email -->
                        <div class="em-pf-pane" data-pane="email-team">
                            <label class="em-pf-switch">
                                <input type="checkbox" name="email_admin_enabled" value="1" checked />
                                <span><?php esc_html_e('Enabled', 'email-manager'); ?></span>
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Send to', 'email-manager'); ?></span>
                                <input type="text" name="email_admin_email" value="<?php echo esc_attr($de['admin_email']); ?>" placeholder="team@example.com, lead@example.com" />
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Subject', 'email-manager'); ?></span>
                                <input type="text" name="email_admin_subject" value="<?php echo esc_attr($de['admin_subject']); ?>" />
                            </label>
                            <label class="em-pf-field">
                                <span class="em-pf-label"><?php esc_html_e('Body', 'email-manager'); ?></span>
                                <textarea name="email_admin_body" rows="6"><?php echo esc_textarea($de['admin_body']); ?></textarea>
                            </label>
                            <p class="em-pf-hint"><?php esc_html_e('Tokens: {applicant_name}, {applicant_email}, {posting_title}, {all_answers}', 'email-manager'); ?></p>
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

    private static function render_analytics_drawer()
    {
        ?>
        <div class="em-drawer" id="em-posting-analytics-drawer" aria-hidden="true">
            <div class="em-drawer__backdrop" data-close="1"></div>
            <div class="em-drawer__panel" role="dialog" aria-modal="true">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title" id="em-pa-title"><?php esc_html_e('Analytics', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" data-close="1" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-drawer__body" id="em-pa-body"></div>
            </div>
        </div>
        <?php
    }
}

new EM_Postings();
