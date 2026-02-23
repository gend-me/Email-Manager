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
define('EMAIL_MANAGER_VERSION', '1.0.1');
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
require_once EMAIL_MANAGER_PATH . 'inc/email-templates.php';
require_once EMAIL_MANAGER_PATH . 'inc/email-manager-admin.php';

// Activation Hook
register_activation_hook(__FILE__, 'em_activate_plugin');
function em_activate_plugin()
{
    // Create database tables
    if (function_exists('em_maybe_create_email_tables')) {
        em_maybe_create_email_tables();
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
}

// Enqueue Assets
add_action('admin_enqueue_scripts', 'em_enqueue_assets');
function em_enqueue_assets($hook)
{
    $is_em_page = (strpos($hook, 'email-manager') !== false) || (isset($_GET['page']) && $_GET['page'] === 'email-manager');
    if ($is_em_page) {

        // Enqueue Email AI Popup
        wp_enqueue_style('em-email-ai-popup', EMAIL_MANAGER_URL . 'assets/email-ai-popup.css', array(), EMAIL_MANAGER_VERSION);
        wp_enqueue_style('em-admin-dashboard', EMAIL_MANAGER_URL . 'assets/admin-dashboard-base.css', array(), EMAIL_MANAGER_VERSION);
        wp_enqueue_style('em-email-manager-admin', EMAIL_MANAGER_URL . 'assets/email-manager-admin.css', array(), EMAIL_MANAGER_VERSION);
        wp_enqueue_script('em-email-ai-popup', EMAIL_MANAGER_URL . 'assets/email-ai-popup.js', array('jquery'), EMAIL_MANAGER_VERSION, true);

        // Enqueue Lists Table Script
        wp_enqueue_script('em-email-lists-table', EMAIL_MANAGER_URL . 'assets/email-lists-table.js', array('jquery', 'em-email-ai-popup'), EMAIL_MANAGER_VERSION, true);

        // Localize Script
        wp_localize_script('em-email-ai-popup', 'GDC_EMAIL_AI_CONFIG', array( // Keeping GDC_ prefix for compatibility if JS uses it
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'listsEndpoint' => rest_url('em/v1/email-lists'),       // New endpoint
            'subscribersEndpoint' => rest_url('em/v1/email-subscribers'), // New endpoint
            // 'leoIcon' => ... (Add if needed)
            // 'siteLogo' => ... (Add if needed)
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
            __('Email Manager', 'email-manager'),
            __('Email Manager', 'email-manager'),
            'manage_options',
            'email-manager',
            'em_render_email_manager_page'
        );
    } else {
        add_menu_page(
            __('Email Manager', 'email-manager'),
            __('Email Manager', 'email-manager'),
            'manage_options',
            'email-manager',
            'em_render_email_manager_page',
            'dashicons-email',
            30
        );
    }
}
