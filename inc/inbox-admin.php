<?php
/**
 * Member Inbox: wp-admin React mount + asset enqueue.
 *
 * Adds an "Inbox" submenu under whatever the email-manager parent slug
 * resolves to (gs-app / gdc-app / standalone email-manager), renders an
 * empty <div id="em-inbox-root"> mount point, and enqueues the React
 * SPA bundle (assets/inbox-app.js — uses wp.element + htm + wp.components,
 * no build step).
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    $parent_slug = defined('GS_VERSION') ? 'gs-app' : (defined('GDC_VERSION') ? 'gdc-app' : 'email-manager');
    add_submenu_page(
        $parent_slug,
        __('Inbox', 'email-manager'),
        __('Inbox', 'email-manager'),
        'read',                              // every logged-in user; granular gating happens in REST
        'email-manager-inbox',
        'em_inbox_render_admin_page'
    );
}, 1200);

function em_inbox_render_admin_page() {
    echo '<div class="wrap em-inbox-wrap"><div id="em-inbox-root" data-loading="1">Loading inbox…</div></div>';
}

add_action('admin_enqueue_scripts', function ($hook) {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'email-manager-inbox') return;

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
    wp_localize_script('em-inbox-app', 'EM_INBOX_CONFIG', array(
        'restRoot'        => esc_url_raw(rest_url('em/v1/inbox/')),
        'nonce'           => wp_create_nonce('wp_rest'),
        'isAdmin'         => current_user_can('manage_options'),
        'currentUserEmail'=> $u ? $u->user_email : '',
    ));
});
