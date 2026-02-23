<?php
/**
 * Email Lists Management Functions
 *
 * @package EmailManager
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Get all email lists with subscriber counts
 *
 * @return array
 */
function em_get_all_lists()
{
    global $wpdb;

    $table_lists = $wpdb->prefix . 'gdc_email_lists';
    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $sql = "SELECT l.*, COUNT(DISTINCT ls.subscriber_id) as subscriber_count
            FROM $table_lists l
            LEFT JOIN $table_list_subs ls ON l.id = ls.list_id
            GROUP BY l.id
            ORDER BY l.created_at DESC";

    $results = $wpdb->get_results($sql, ARRAY_A);

    return $results ? $results : array();
}

/**
 * Get a single list by ID
 *
 * @param int $list_id
 * @return object|null
 */
function em_get_list($list_id)
{
    global $wpdb;

    $table_lists = $wpdb->prefix . 'gdc_email_lists';

    $list = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_lists WHERE id = %d",
        $list_id
    ));

    return $list;
}

/**
 * Create a new email list
 *
 * @param string $name
 * @param string $description
 * @param string $type
 * @param array $auto_enroll_rules
 * @return int|false List ID or false on failure
 */
function em_create_list($name, $description = '', $type = 'general', $auto_enroll_rules = array())
{
    global $wpdb;

    $table_lists = $wpdb->prefix . 'gdc_email_lists';

    $name = sanitize_text_field($name);
    $description = sanitize_textarea_field($description);
    $type = sanitize_text_field($type);

    $current_time = current_time('mysql');

    $result = $wpdb->insert(
        $table_lists,
        array(
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'created_at' => $current_time,
            'updated_at' => $current_time
        ),
        array('%s', '%s', '%s', '%s', '%s')
    );

    if (!$result) {
        return false;
    }

    $list_id = $wpdb->insert_id;

    // Save auto-enrollment rules
    if (!empty($auto_enroll_rules) && $list_id) {
        em_update_list_auto_enroll_rules($list_id, $auto_enroll_rules);
    }

    return $list_id;
}

/**
 * Update an existing list
 *
 * @param int $list_id
 * @param array $data
 * @return bool
 */
function em_update_list($list_id, $data)
{
    global $wpdb;

    $table_lists = $wpdb->prefix . 'gdc_email_lists';

    $update_data = array(
        'updated_at' => current_time('mysql')
    );
    $update_format = array('%s');

    if (isset($data['name'])) {
        $update_data['name'] = sanitize_text_field($data['name']);
        $update_format[] = '%s';
    }

    if (isset($data['description'])) {
        $update_data['description'] = sanitize_textarea_field($data['description']);
        $update_format[] = '%s';
    }

    if (isset($data['type'])) {
        $update_data['type'] = sanitize_text_field($data['type']);
        $update_format[] = '%s';
    }

    $result = $wpdb->update(
        $table_lists,
        $update_data,
        array('id' => $list_id),
        $update_format,
        array('%d')
    );

    // Update auto-enrollment rules if provided
    if (isset($data['auto_enroll_rules'])) {
        em_update_list_auto_enroll_rules($list_id, $data['auto_enroll_rules']);
    }

    return $result !== false;
}

/**
 * Delete a list
 *
 * @param int $list_id
 * @param bool $delete_subscribers Whether to delete subscriber relationships
 * @return bool
 */
function em_delete_list($list_id, $delete_subscribers = false)
{
    global $wpdb;

    $table_lists = $wpdb->prefix . 'gdc_email_lists';
    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';
    $table_settings = $wpdb->prefix . 'gdc_email_list_settings';

    // Delete list-subscriber relationships
    $wpdb->delete($table_list_subs, array('list_id' => $list_id), array('%d'));

    // Delete list settings
    $wpdb->delete($table_settings, array('list_id' => $list_id), array('%d'));

    // Delete the list itself
    $result = $wpdb->delete($table_lists, array('id' => $list_id), array('%d'));

    return $result !== false;
}

/**
 * Get subscriber count for a list
 *
 * @param int $list_id
 * @return int
 */
function em_get_list_subscriber_count($list_id)
{
    global $wpdb;

    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_list_subs WHERE list_id = %d",
        $list_id
    ));

    return (int) $count;
}

/**
 * Get auto-enrollment rules for a list
 *
 * @param int $list_id
 * @return array
 */
function em_get_list_auto_enroll_rules($list_id)
{
    global $wpdb;

    $table_settings = $wpdb->prefix . 'gdc_email_list_settings';

    $rules = $wpdb->get_row($wpdb->prepare(
        "SELECT setting_value FROM $table_settings WHERE list_id = %d AND setting_key = 'auto_enroll_rules'",
        $list_id
    ));

    if ($rules && $rules->setting_value) {
        $decoded = json_decode($rules->setting_value, true);
        return is_array($decoded) ? $decoded : array();
    }

    return array();
}

/**
 * Update auto-enrollment rules for a list
 *
 * @param int $list_id
 * @param array $rules
 * @return bool
 */
function em_update_list_auto_enroll_rules($list_id, $rules)
{
    global $wpdb;

    $table_settings = $wpdb->prefix . 'gdc_email_list_settings';

    $rules_json = json_encode($rules);

    // Try to update first
    $updated = $wpdb->update(
        $table_settings,
        array('setting_value' => $rules_json),
        array(
            'list_id' => $list_id,
            'setting_key' => 'auto_enroll_rules'
        ),
        array('%s'),
        array('%d', '%s')
    );

    // If no rows updated, insert new record
    if ($updated === 0) {
        $wpdb->insert(
            $table_settings,
            array(
                'list_id' => $list_id,
                'setting_key' => 'auto_enroll_rules',
                'setting_value' => $rules_json
            ),
            array('%d', '%s', '%s')
        );
    }

    return true;
}

/**
 * Get all subscribers for a list
 *
 * @param int $list_id
 * @return array
 */
function em_get_list_subscribers($list_id)
{
    global $wpdb;

    $table_subscribers = $wpdb->prefix . 'gdc_email_subscribers';
    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $sql = "SELECT s.*, ls.subscribed_at
            FROM $table_subscribers s
            INNER JOIN $table_list_subs ls ON s.id = ls.subscriber_id
            WHERE ls.list_id = %d
            ORDER BY ls.subscribed_at DESC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $list_id), ARRAY_A);

    return $results ? $results : array();
}
