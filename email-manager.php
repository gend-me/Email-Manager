<?php
/**
 * Plugin Name: Community Emails
 * Description: Automate high-touch communication and keep your users engaged with targeted community updates.
 * Version:     1.0.1
 * Author:      By GenD
 * Author URI:  https://gend.me/
 * Text Domain: email-manager
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('EMAIL_MANAGER_VERSION', time() + 25);
define('EMAIL_MANAGER_PATH', plugin_dir_path(__FILE__));
define('EMAIL_MANAGER_URL', plugin_dir_url(__FILE__)); // Renamed to EM_URL in the instruction, but keeping original for consistency with other defines.
define('EM_PATH', EMAIL_MANAGER_PATH); // Added for GitHub Updater
define('EM_URL', EMAIL_MANAGER_URL); // Added for GitHub Updater

// GitHub Updater
if (file_exists(EM_PATH . 'inc/class-gend-github-updater.php')) {
    require_once EM_PATH . 'inc/class-gend-github-updater.php';
    new GenD_GitHub_Updater(__FILE__, 'gend-me/Email-Manager');
}

// Integration with GenD Core (if needed in future)
// For now, it stands alone.

// Include required files
require_once EMAIL_MANAGER_PATH . 'inc/email-database.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-lists.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-subscribers.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-rest-handlers.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-logs.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-smtp.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-webhook.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-threading.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-user-provisioning.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-attachments.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-rest-list.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-admin.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-hub-registry.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-send.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-participants.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-search.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-kind-migration.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-outbound-queue.php';
require_once EMAIL_MANAGER_PATH . 'inc/inbox-diagnostics.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-templates.php';
require_once EMAIL_MANAGER_PATH . 'inc/wc-email-override.php';
require_once EMAIL_MANAGER_PATH . 'inc/applications.php';
require_once EMAIL_MANAGER_PATH . 'inc/support.php';
require_once EMAIL_MANAGER_PATH . 'inc/personas.php';
require_once EMAIL_MANAGER_PATH . 'inc/chats.php';
require_once EMAIL_MANAGER_PATH . 'inc/leo-integration.php';
require_once EMAIL_MANAGER_PATH . 'inc/leo-oauth.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-manager-admin.php';
require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-core.php';

// Initialize Forms System
add_action('plugins_loaded', 'em_initialize_forms_system');
function em_initialize_forms_system()
{
    $chat_forms = new Chat_Forms_Core();
    $chat_forms->run();
}

// Activation Hook
register_activation_hook(__FILE__, 'em_activate_plugin');
function em_activate_plugin()
{
    // Create database tables
    if (function_exists('em_maybe_create_email_tables')) {
        em_maybe_create_email_tables();
    }
    if (function_exists('em_inbox_maybe_create_tables')) {
        em_inbox_maybe_create_tables();
    }
    if (function_exists('em_inbox_thread_maybe_create_tables')) {
        em_inbox_thread_maybe_create_tables();
    }
    if (function_exists('em_inbox_get_or_create_hmac_secret')) {
        em_inbox_get_or_create_hmac_secret();
    }
}

// Admin Init Hook
add_action('admin_init', 'em_admin_init');
function em_admin_init()
{
    // Ensure tables exist
    if (function_exists('em_maybe_create_email_tables')) {
        em_maybe_create_email_tables();
    }
    if (function_exists('em_inbox_maybe_create_tables')) {
        em_inbox_maybe_create_tables();
    }
    if (function_exists('em_inbox_thread_maybe_create_tables')) {
        em_inbox_thread_maybe_create_tables();
    }
}

/**
 * Resolve full email data array for a given email ID.
 * Searches WP core emails, WooCommerce emails, and BuddyPress emails.
 * Returns null if not found.
 */
function em_resolve_email_data_by_id( $id ) {
    if ( empty( $id ) ) return null;

    // 1. WordPress core / account emails
    $core_ids = array(
        'new_user_registration' => array( 'section' => 'account', 'label' => 'New User Registration', 'subject' => '[' . get_bloginfo('name') . '] New User Registration' ),
        'new_user_welcome'      => array( 'section' => 'account', 'label' => 'New User Welcome',      'subject' => '[' . get_bloginfo('name') . '] Your username and password' ),
        'password_reset'        => array( 'section' => 'account', 'label' => 'Password Reset',        'subject' => '[' . get_bloginfo('name') . '] Password Reset' ),
        'email_change_confirmation' => array( 'section' => 'account', 'label' => 'Email Change Confirmation', 'subject' => '[' . get_bloginfo('name') . '] Email Change Confirmation' ),
    );
    if ( isset( $core_ids[ $id ] ) ) {
        return array_merge( array( 'id' => $id, 'html' => '', 'description' => '' ), $core_ids[ $id ] );
    }

    // 2. WooCommerce emails. WC() fatals when WooCommerce's helper function
    // exists but the WooCommerce class never finished loading (the
    // "installation incomplete" mode). Gate on class_exists('WooCommerce')
    // before calling WC() — see same fix in inc/email-manager-admin.php.
    if ( class_exists( 'WC_Emails' ) || ( class_exists('WooCommerce') && function_exists('WC') && WC()->mailer() ) ) {
        try {
            $emails = class_exists('WC_Emails')
                ? WC_Emails::instance()->get_emails()
                : WC()->mailer()->get_emails();
            foreach ( $emails as $email ) {
                if ( $email->id === $id ) {
                    $subject = $email->get_option('subject', '');
                    if ( empty($subject) && method_exists($email, 'get_default_subject') ) {
                        try { $subject = $email->get_default_subject(); } catch ( Exception $e ) { $subject = ''; }
                    }
                    $heading = $email->get_option('heading', '');
                    if ( empty($heading) && method_exists($email, 'get_default_heading') ) {
                        try { $heading = $email->get_default_heading(); } catch ( Exception $e ) { $heading = ''; }
                    }
                    $is_customer = ( strpos($email->id, 'customer_') === 0 );
                    return array(
                        'id'               => $email->id,
                        'section'          => 'store',
                        'label'            => $email->get_title(),
                        'subject'          => $subject,
                        'preheader'        => $heading,
                        'html'             => function_exists('em_get_wc_email_body_template') ? em_get_wc_email_body_template($email) : '',
                        'description'      => $email->get_description(),
                        'send_to_customer' => $is_customer,
                        'wc_recipient'     => $is_customer ? 'Customer' : ( $email->get_recipient() ?: get_option('admin_email') ),
                        'is_enabled'       => $email->is_enabled(),
                        'tokens'           => function_exists('em_get_wc_email_tokens') ? em_get_wc_email_tokens($email) : array(),
                    );
                }
            }
        } catch ( Exception $e ) { /* silently skip */ }
    }

    // 3. BuddyPress / social emails — look up by post slug
    if ( function_exists('bp_get_email') ) {
        $bp_email = bp_get_email( $id );
        if ( ! is_wp_error($bp_email) && $bp_email ) {
            return array(
                'id'          => $id,
                'section'     => 'social',
                'label'       => $bp_email->get_subject(),
                'subject'     => $bp_email->get_subject(),
                'html'        => $bp_email->get_content_html(),
                'description' => '',
            );
        }
    }

    // Not found — return minimal data so JS can still open a blank editor
    return array(
        'id'      => $id,
        'section' => 'account',
        'label'   => ucwords( str_replace( array('_', '-'), ' ', $id ) ),
        'subject' => '',
        'html'    => '',
    );
}

// Enqueue Assets
add_action('admin_enqueue_scripts', 'em_enqueue_assets');
function em_enqueue_assets($hook)
{
    $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $is_em_page = (strpos($hook, 'email-manager') !== false)
        || $current_page === 'email-manager'
        || $current_page === 'gdc-store-settings'
        || $current_page === 'gdc-social-network-settings';
    if ($is_em_page) {

        // Enqueue WP Media
        wp_enqueue_media();

        // Enqueue Email AI Popup
        wp_enqueue_style('em-email-ai-popup', EMAIL_MANAGER_URL . 'assets/email-ai-popup.css', array(), EMAIL_MANAGER_VERSION);
        wp_enqueue_style('em-admin-dashboard', EMAIL_MANAGER_URL . 'assets/admin-dashboard-base.css', array(), EMAIL_MANAGER_VERSION);
        wp_enqueue_style('em-email-manager-admin', EMAIL_MANAGER_URL . 'assets/email-manager-admin.css', array(), EMAIL_MANAGER_VERSION);
        wp_enqueue_script('em-email-ai-popup', EMAIL_MANAGER_URL . 'assets/email-ai-popup.js', array('jquery'), EMAIL_MANAGER_VERSION, true);

        // Enqueue Lists Table Script
        wp_enqueue_script('em-email-lists-table', EMAIL_MANAGER_URL . 'assets/email-lists-table.js', array('jquery', 'em-email-ai-popup'), EMAIL_MANAGER_VERSION, true);

        // Applications + Support shared assets
        wp_enqueue_style('em-app-support', EMAIL_MANAGER_URL . 'assets/em-app-support.css', array('em-email-manager-admin'), EMAIL_MANAGER_VERSION);
        wp_enqueue_script('em-app-support', EMAIL_MANAGER_URL . 'assets/em-app-support.js', array('jquery'), EMAIL_MANAGER_VERSION, true);
        wp_localize_script('em-app-support', 'EM_AS_CONFIG', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('em_app_support'),
        ));

        // Resolve configure email data server-side so the JS can open the popup directly.
        $configure_email_data = null;
        if ( ! empty( $_GET['configure'] ) ) {
            $configure_email_data = em_resolve_email_data_by_id( sanitize_text_field( wp_unslash( $_GET['configure'] ) ) );
        }

        // Localize Script
        wp_localize_script('em-email-ai-popup', 'GDC_EMAIL_AI_CONFIG', array( // Keeping GDC_ prefix for compatibility if JS uses it
            'root'                  => esc_url_raw(rest_url()),
            'nonce'                 => wp_create_nonce('wp_rest'),
            'listsEndpoint'         => rest_url('em/v1/email-lists'),
            'subscribersEndpoint'   => rest_url('em/v1/email-subscribers'),
            'proposalEmailsEndpoint'=> rest_url('em/v1/proposal-emails'),
            'testEmailEndpoint'     => rest_url('em/v1/send-test-email'),
            'sendEmailEndpoint'     => rest_url('em/v1/send-email'),
            'wcEmailSaveEndpoint'   => rest_url('em/v1/wc-email-save'),
            'wcEmailToggleEndpoint' => rest_url('em/v1/wc-email-toggle'),
            'wcEmailRenderEndpoint' => rest_url('em/v1/wc-email-render'),
            'bpEmailSaveEndpoint'   => rest_url('em/v1/bp-email-save'),
            'configureEmail'        => $configure_email_data,
        ));
        // Also localize for email lists table if it uses a separate config object, 
        // but analysis showed it uses GDC_EMAIL_AI_CONFIG or gdcEmailConfig. 
        // Let's ensure compatibility.
        wp_localize_script('em-email-lists-table', 'gdcEmailConfig', array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'listsEndpoint' => rest_url('em/v1/email-lists'),
            'subscribersEndpoint' => rest_url('em/v1/email-subscribers')
        ));
    }
}

// REST API Init
add_action('rest_api_init', 'em_register_rest_routes');
function em_register_rest_routes()
{
    // Registered in inc/email-rest-handlers.php
    if (function_exists('em_register_email_manager_routes')) {
        em_register_email_manager_routes();
    }
}

// Admin Menu
add_action('admin_menu', 'em_register_admin_menu', 1100);
function em_register_admin_menu()
{
    if (defined('GDC_VERSION') || defined('GS_VERSION')) {
        $parent_slug = defined('GS_VERSION') ? 'gs-app' : 'gdc-app';
        add_submenu_page(
            $parent_slug,
            __('Messages', 'email-manager'),
            __('Messages', 'email-manager'),
            'manage_options',
            'email-manager',
            'em_render_email_manager_page'
        );
    } else {
        add_menu_page(
            __('Messages', 'email-manager'),
            __('Messages', 'email-manager'),
            'manage_options',
            'email-manager',
            'em_render_email_manager_page',
            'dashicons-email',
            30
        );
    }
}
