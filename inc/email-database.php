<?php
/**
 * Email Lists Database Schema and Installation
 *
 * @package EmailManager
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

// Database version for migrations
define('EM_EMAIL_DB_VERSION', '1.0.0');

/**
 * Create email lists database tables
 */
function em_create_email_tables()
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Lists table (Using same table names as GenD Core for compatibility/sharing)
    $table_lists = $wpdb->prefix . 'gdc_email_lists';
    $sql_lists = "CREATE TABLE $table_lists (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        description text,
        type varchar(50) DEFAULT 'general',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_type (type),
        KEY idx_created (created_at)
    ) $charset_collate;";

    // Subscribers table
    $table_subscribers = $wpdb->prefix . 'gdc_email_subscribers';
    $sql_subscribers = "CREATE TABLE $table_subscribers (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        first_name varchar(100),
        last_name varchar(100),
        status varchar(20) DEFAULT 'subscribed',
        ip_address varchar(45),
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_email (email),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) $charset_collate;";

    // List subscribers relationship table
    $table_list_subscribers = $wpdb->prefix . 'gdc_email_list_subscribers';
    $sql_list_subscribers = "CREATE TABLE $table_list_subscribers (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        list_id bigint(20) UNSIGNED NOT NULL,
        subscriber_id bigint(20) UNSIGNED NOT NULL,
        subscribed_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_list_subscriber (list_id, subscriber_id),
        KEY idx_list (list_id),
        KEY idx_subscriber (subscriber_id)
    ) $charset_collate;";

    // Email tracking table
    $table_tracking = $wpdb->prefix . 'gdc_email_tracking';
    $sql_tracking = "CREATE TABLE $table_tracking (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subscriber_id bigint(20) UNSIGNED NOT NULL,
        email_id varchar(100),
        event_type varchar(20) NOT NULL,
        event_data text,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_subscriber (subscriber_id),
        KEY idx_email (email_id),
        KEY idx_event (event_type),
        KEY idx_created (created_at)
    ) $charset_collate;";

    // List settings table
    $table_settings = $wpdb->prefix . 'gdc_email_list_settings';
    $sql_settings = "CREATE TABLE $table_settings (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        list_id bigint(20) UNSIGNED NOT NULL,
        setting_key varchar(100) NOT NULL,
        setting_value longtext,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_list_setting (list_id, setting_key),
        KEY idx_list (list_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    dbDelta($sql_lists);
    dbDelta($sql_subscribers);
    dbDelta($sql_list_subscribers);
    dbDelta($sql_tracking);
    dbDelta($sql_settings);

    // Store database version
    update_option('em_email_db_version', EM_EMAIL_DB_VERSION);
}

/**
 * Check if email tables exist
 *
 * @return bool
 */
function em_email_tables_exist()
{
    global $wpdb;

    $tables = array(
        $wpdb->prefix . 'gdc_email_lists',
        $wpdb->prefix . 'gdc_email_subscribers',
        $wpdb->prefix . 'gdc_email_list_subscribers',
        $wpdb->prefix . 'gdc_email_tracking',
        $wpdb->prefix . 'gdc_email_list_settings'
    );

    foreach ($tables as $table) {
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return false;
        }
    }

    return true;
}

/**
 * Get current database version
 *
 * @return string
 */
function em_get_email_db_version()
{
    return get_option('em_email_db_version', '0.0.0');
}

/**
 * Drop all email tables (for uninstall)
 */
function em_drop_email_tables()
{
    global $wpdb;

    $tables = array(
        $wpdb->prefix . 'gdc_email_list_settings',
        $wpdb->prefix . 'gdc_email_tracking',
        $wpdb->prefix . 'gdc_email_list_subscribers',
        $wpdb->prefix . 'gdc_email_subscribers',
        $wpdb->prefix . 'gdc_email_lists'
    );

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }

    delete_option('em_email_db_version');
}

/**
 * Check and create tables if needed
 */
function em_maybe_create_email_tables()
{
    if (!em_email_tables_exist()) {
        em_create_email_tables();
    }
}
