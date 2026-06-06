<?php
/**
 * Member Inbox: BuddyPress "Email" subnav + Email/Chat tab strip
 * inside /members/<user>/messages/ (slice 2ww).
 *
 * Adds an "Email" subnav under the BP Messages component for users
 * who can access an inbox (owner, grantee, admin, super-admin). When
 * the user is on /messages/email/ we render the React inbox SPA;
 * when they're on the standard /messages/inbox/ (Chat) we keep BP's
 * normal templates. A tab strip injected at the top of EITHER screen
 * lets them switch back and forth.
 *
 * Permission function:
 *   em_inbox_user_has_inbox_access(int $user_id): bool
 *
 * @package EmailManager
 * @since   1.5.0
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Permission helper
 * ------------------------------------------------------------------------- */

/**
 * Does the user have any kind of access to an inbox?
 *   - admin (manage_options)            → YES
 *   - super-admin (multisite)            → YES
 *   - has em_inbox_address user_meta     → YES (they own one)
 *   - has any wp_gdc_inbox_grants row    → YES (delegated to them)
 *   - filter em_inbox_has_access lets    → YES (extension hook)
 *     a 3rd-party plugin force-enable
 */
function em_inbox_user_has_inbox_access($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;

    if (user_can($user_id, 'manage_options')) return true;
    if (is_multisite() && function_exists('is_super_admin') && is_super_admin($user_id)) return true;

    if (get_user_meta($user_id, 'em_inbox_address', true)) return true;

    global $wpdb;
    $tbl = $wpdb->prefix . 'gdc_inbox_grants';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl)) === $tbl) {
        $has = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tbl WHERE grantee_user_id = %d
              AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
            $user_id
        ));
        if ($has > 0) return true;
    }

    return (bool) apply_filters('em_inbox_has_access', false, $user_id);
}

/* -------------------------------------------------------------------------
 * Register the Email subnav under BP Messages
 * ------------------------------------------------------------------------- */

add_action('bp_setup_nav', 'em_inbox_add_bp_messages_email_subnav', 110);

function em_inbox_add_bp_messages_email_subnav() {
    if (! function_exists('bp_core_new_subnav_item') || ! function_exists('bp_get_messages_slug')) return;
    if (! function_exists('bp_loggedin_user_id') || ! function_exists('bp_displayed_user_domain')) return;

    $displayed_uid = bp_displayed_user_id();
    if ($displayed_uid <= 0) return;

    // Only render on the displayed user's OWN profile, and only when
    // THAT user can see inboxes.
    $own_profile = function_exists('bp_is_my_profile') ? bp_is_my_profile() : ($displayed_uid === get_current_user_id());
    if (! $own_profile) return;
    if (! em_inbox_user_has_inbox_access($displayed_uid)) return;

    $messages_slug = bp_get_messages_slug();
    $parent_url    = trailingslashit(bp_displayed_user_domain() . $messages_slug);

    bp_core_new_subnav_item(array(
        'name'             => __('Email', 'email-manager'),
        'slug'             => 'email',
        'parent_url'       => $parent_url,
        'parent_slug'      => $messages_slug,
        'screen_function'  => 'em_inbox_bp_messages_email_screen',
        'position'         => 5,  // before Inbox (10)
        'user_has_access'  => true,
        'show_in_admin_bar'=> false,
    ));
}

function em_inbox_bp_messages_email_screen() {
    add_action('bp_template_title',   'em_inbox_bp_messages_email_title');
    add_action('bp_template_content', 'em_inbox_bp_messages_email_content');
    bp_core_load_template(array('members/single/plugins'));
}

function em_inbox_bp_messages_email_title() {
    echo esc_html__('Email', 'email-manager');
}

function em_inbox_bp_messages_email_content() {
    // Render Email/Chat tab strip + React SPA mount. Frontend wrapper
    // gets the same .em-inbox-wrap container so the slice-2ss glass
    // styles apply.
    em_inbox_bp_messages_tab_strip('email');
    echo '<div class="em-inbox-wrap em-inbox-wrap--frontend"><div id="em-inbox-root" data-loading="1">'
       . esc_html__('Loading inbox…', 'email-manager')
       . '</div></div>';
}

/* -------------------------------------------------------------------------
 * Inject the Email/Chat tab strip above the standard messages screens
 * (Inbox, Sentbox, Notices, Compose) so the user can flip back.
 * ------------------------------------------------------------------------- */

foreach (array(
    'bp_before_member_messages_threads',
    'bp_before_member_messages_loops',
    'bp_before_member_messages_options',
    'messages_box_loop_start',
) as $hook) {
    add_action($hook, 'em_inbox_bp_messages_tab_strip_inject', 1);
}

function em_inbox_bp_messages_tab_strip_inject() {
    static $rendered = false;
    if ($rendered) return;
    if (! function_exists('bp_displayed_user_id')) return;
    if (! em_inbox_user_has_inbox_access(bp_displayed_user_id())) return;
    em_inbox_bp_messages_tab_strip('chat');
    $rendered = true;
}

function em_inbox_bp_messages_tab_strip($active) {
    if (! function_exists('bp_displayed_user_domain') || ! function_exists('bp_get_messages_slug')) return;
    $messages_url = trailingslashit(bp_displayed_user_domain() . bp_get_messages_slug());
    $email_url    = $messages_url . 'email/';
    $chat_url     = $messages_url;  // default messages root = inbox = chat
    $email_active = $active === 'email' ? ' is-active' : '';
    $chat_active  = $active === 'chat'  ? ' is-active' : '';
    ?>
    <nav class="em-inbox-messages-tabs" aria-label="<?php esc_attr_e('Messages section', 'email-manager'); ?>">
      <a class="em-inbox-messages-tab<?php echo $email_active; ?>" href="<?php echo esc_url($email_url); ?>" aria-current="<?php echo $email_active ? 'page' : 'false'; ?>">
        <span class="em-inbox-messages-tab-icon" aria-hidden="true">📧</span> <?php esc_html_e('Email', 'email-manager'); ?>
      </a>
      <a class="em-inbox-messages-tab<?php echo $chat_active; ?>" href="<?php echo esc_url($chat_url); ?>" aria-current="<?php echo $chat_active ? 'page' : 'false'; ?>">
        <span class="em-inbox-messages-tab-icon" aria-hidden="true">💬</span> <?php esc_html_e('Chat', 'email-manager'); ?>
      </a>
    </nav>
    <?php
}

/* -------------------------------------------------------------------------
 * Frontend asset enqueue — load the inbox SPA when on /messages/email/
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'em_inbox_bp_messages_email_enqueue', 20);
function em_inbox_bp_messages_email_enqueue() {
    if (! function_exists('bp_is_messages_component') || ! function_exists('bp_current_action')) return;
    // Always enqueue the tab-strip CSS on any messages screen so the
    // tab strip looks right on the Chat side too.
    if (function_exists('bp_is_messages_component') && bp_is_messages_component()) {
        wp_enqueue_style(
            'em-inbox-bp-tabs',
            EMAIL_MANAGER_URL . 'assets/inbox-bp-tabs.css',
            array(),
            EMAIL_MANAGER_VERSION
        );
    }
    // SPA only on the Email screen.
    if (! bp_is_messages_component() || bp_current_action() !== 'email') return;

    wp_enqueue_style(
        'em-inbox-app',
        EMAIL_MANAGER_URL . 'assets/inbox-app.css',
        array('wp-components'),
        EMAIL_MANAGER_VERSION
    );
    wp_enqueue_script(
        'em-inbox-app',
        EMAIL_MANAGER_URL . 'assets/inbox-app.js',
        array('wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch'),
        EMAIL_MANAGER_VERSION,
        true
    );
    $u = wp_get_current_user();
    $tz_str = function_exists('wp_timezone_string') ? wp_timezone_string() : (get_option('timezone_string') ?: 'UTC');
    wp_localize_script('em-inbox-app', 'EM_INBOX_CONFIG', array(
        'restRoot'         => esc_url_raw(rest_url('em/v1/inbox/')),
        'nonce'            => wp_create_nonce('wp_rest'),
        'isAdmin'          => current_user_can('manage_options'),
        'currentUserEmail' => $u ? $u->user_email : '',
        'userTimezone'     => $tz_str ?: 'UTC',
        'frontend'         => true,
    ));
}
