<?php
/**
 * Email Subscriber Management Functions
 *
 * @package EmailManager
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Add a subscriber to one or more lists
 *
 * @param string $email
 * @param string $first_name
 * @param string $last_name
 * @param array $list_ids
 * @param string $status
 * @return int|false Subscriber ID or false on failure
 */
function em_add_subscriber($email, $first_name = '', $last_name = '', $list_ids = array(), $status = 'subscribed')
{
    global $wpdb;

    $table_subscribers = $wpdb->prefix . 'gdc_email_subscribers';
    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $email = sanitize_email($email);
    if (!is_email($email)) {
        return false;
    }

    $first_name = sanitize_text_field($first_name);
    $last_name = sanitize_text_field($last_name);
    $status = sanitize_text_field($status);

    // Get IP address
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';

    $current_time = current_time('mysql');

    // Check if subscriber already exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_subscribers WHERE email = %s",
        $email
    ));

    if ($existing) {
        $subscriber_id = $existing->id;

        // Update subscriber info
        $wpdb->update(
            $table_subscribers,
            array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'status' => $status,
                'updated_at' => $current_time
            ),
            array('id' => $subscriber_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
    } else {
        // Insert new subscriber
        $result = $wpdb->insert(
            $table_subscribers,
            array(
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'status' => $status,
                'ip_address' => $ip_address,
                'created_at' => $current_time,
                'updated_at' => $current_time
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            return false;
        }

        $subscriber_id = $wpdb->insert_id;
    }

    // Add to lists
    if (!empty($list_ids) && is_array($list_ids)) {
        foreach ($list_ids as $list_id) {
            $list_id = (int) $list_id;

            // Check if already in list
            $in_list = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_list_subs WHERE list_id = %d AND subscriber_id = %d",
                $list_id,
                $subscriber_id
            ));

            if (!$in_list) {
                $wpdb->insert(
                    $table_list_subs,
                    array(
                        'list_id' => $list_id,
                        'subscriber_id' => $subscriber_id,
                        'subscribed_at' => $current_time
                    ),
                    array('%d', '%d', '%s')
                );
            }
        }
    }

    return $subscriber_id;
}

/**
 * Remove a subscriber from a specific list
 *
 * @param int $subscriber_id
 * @param int $list_id
 * @return bool
 */
function em_remove_subscriber($subscriber_id, $list_id)
{
    global $wpdb;

    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $result = $wpdb->delete(
        $table_list_subs,
        array(
            'subscriber_id' => $subscriber_id,
            'list_id' => $list_id
        ),
        array('%d', '%d')
    );

    return $result !== false;
}

/**
 * Get subscriber by ID or email
 *
 * @param int|string $identifier Subscriber ID or email address
 * @return object|null
 */
function em_get_subscriber($identifier)
{
    global $wpdb;

    $table_subscribers = $wpdb->prefix . 'gdc_email_subscribers';

    if (is_numeric($identifier)) {
        $subscriber = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscribers WHERE id = %d",
            $identifier
        ));
    } else {
        $subscriber = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscribers WHERE email = %s",
            $identifier
        ));
    }

    return $subscriber;
}

/**
 * Import subscribers from CSV file
 *
 * @param string $file_path Path to CSV file
 * @param int $list_id List ID to add subscribers to
 * @return array Results with success count and errors
 */
function em_import_subscribers_csv($file_path, $list_id)
{
    $results = array(
        'success' => 0,
        'errors' => array()
    );

    if (!file_exists($file_path)) {
        $results['errors'][] = __('File not found', 'email-manager');
        return $results;
    }

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        $results['errors'][] = __('Could not open file', 'email-manager');
        return $results;
    }

    $line = 0;
    while (($data = fgetcsv($handle)) !== false) {
        $line++;

        // Skip header row
        if ($line === 1 && (strtolower($data[0]) === 'email' || strtolower($data[0]) === 'e-mail')) {
            continue;
        }

        // Expecting: email, first_name, last_name
        if (count($data) >= 1) {
            $email = isset($data[0]) ? trim($data[0]) : '';
            $first_name = isset($data[1]) ? trim($data[1]) : '';
            $last_name = isset($data[2]) ? trim($data[2]) : '';

            if (empty($email) || !is_email($email)) {
                $results['errors'][] = sprintf(__('Line %d: Invalid email address', 'email-manager'), $line);
                continue;
            }

            $subscriber_id = em_add_subscriber($email, $first_name, $last_name, array($list_id));

            if ($subscriber_id) {
                $results['success']++;
            } else {
                $results['errors'][] = sprintf(__('Line %d: Failed to add subscriber', 'email-manager'), $line);
            }
        }
    }

    fclose($handle);

    return $results;
}

/**
 * Get subscriber statistics
 *
 * @param int $subscriber_id
 * @return array
 */
function em_get_subscriber_stats($subscriber_id)
{
    global $wpdb;

    $table_tracking = $wpdb->prefix . 'gdc_email_tracking';
    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $stats = array(
        'total_sent' => 0,
        'total_opened' => 0,
        'total_clicked' => 0,
        'last_activity' => null,
        'lists_count' => 0
    );

    // Count events
    $events = $wpdb->get_results($wpdb->prepare(
        "SELECT event_type, COUNT(*) as count, MAX(created_at) as last_event
         FROM $table_tracking
         WHERE subscriber_id = %d
         GROUP BY event_type",
        $subscriber_id
    ));

    if ($events) {
        foreach ($events as $event) {
            switch ($event->event_type) {
                case 'sent':
                    $stats['total_sent'] = (int) $event->count;
                    break;
                case 'opened':
                    $stats['total_opened'] = (int) $event->count;
                    break;
                case 'clicked':
                    $stats['total_clicked'] = (int) $event->count;
                    break;
            }

            if (!$stats['last_activity'] || $event->last_event > $stats['last_activity']) {
                $stats['last_activity'] = $event->last_event;
            }
        }
    }

    // Count lists
    $stats['lists_count'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_list_subs WHERE subscriber_id = %d",
        $subscriber_id
    ));

    return $stats;
}

/**
 * Update subscriber status
 *
 * @param int $subscriber_id
 * @param string $status 'subscribed', 'unsubscribed', 'bounced', 'complained'
 * @return bool
 */
function em_update_subscriber_status($subscriber_id, $status)
{
    global $wpdb;

    $table_subscribers = $wpdb->prefix . 'gdc_email_subscribers';

    $allowed_statuses = array('subscribed', 'unsubscribed', 'bounced', 'complained');
    if (!in_array($status, $allowed_statuses)) {
        return false;
    }

    $result = $wpdb->update(
        $table_subscribers,
        array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        ),
        array('id' => $subscriber_id),
        array('%s', '%s'),
        array('%d')
    );

    return $result !== false;
}

/**
 * Get subscriber lists
 *
 * @param int $subscriber_id
 * @return array
 */
function em_get_subscriber_lists($subscriber_id)
{
    global $wpdb;

    $table_lists = $wpdb->prefix . 'gdc_email_lists';
    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';

    $sql = "SELECT l.*, ls.subscribed_at
            FROM $table_lists l
            INNER JOIN $table_list_subs ls ON l.id = ls.list_id
            WHERE ls.subscriber_id = %d
            ORDER BY ls.subscribed_at DESC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $subscriber_id), ARRAY_A);

    return $results ? $results : array();
}
