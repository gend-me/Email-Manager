<?php
/**
 * REST API Handlers for Email Lists and Subscribers
 *
 * @package EmailManager
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

function em_register_email_manager_routes()
{
    $namespace = 'em/v1';

    // Lists
    register_rest_route($namespace, '/email-lists', array(
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'em_get_lists_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'em_create_list_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));

    register_rest_route($namespace, '/email-lists/(?P<id>\d+)', array(
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'em_get_list_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
        array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => 'em_update_list_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
        array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => 'em_delete_list_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));

    register_rest_route($namespace, '/email-lists/(?P<id>\d+)/subscribers', array(
        array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'em_get_list_subscribers_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
        array(
            'methods' => WP_REST_Server::CREATABLE, // Add existing subscriber to list
            'callback' => 'em_add_subscriber_to_list_handler', // NOT IMPLEMENTED in original file, but logic exists in JS to call POST to this endpoint? Checking JS...
            // JS calls: POST .../email-lists/ID/subscribers with subscriber_id
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));

    // Subscribers
    register_rest_route($namespace, '/email-subscribers', array(
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'em_add_subscriber_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));

    register_rest_route($namespace, '/email-lists/(?P<id>\d+)/subscribers/(?P<sub_id>\d+)', array(
        array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => 'em_remove_subscriber_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));

    // Import
    register_rest_route($namespace, '/email-lists/(?P<id>\d+)/subscribers/import', array(
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'em_import_csv_handler',
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));
}

function em_rest_permission_check()
{
    return current_user_can('manage_options');
}

// Handler Functions

function em_get_lists_handler(WP_REST_Request $request)
{
    $lists = em_get_all_lists();

    return array(
        'success' => true,
        'lists' => $lists
    );
}

function em_create_list_handler(WP_REST_Request $request)
{
    $params = $request->get_json_params();

    $name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
    $description = isset($params['description']) ? sanitize_textarea_field($params['description']) : '';
    $type = isset($params['type']) ? sanitize_text_field($params['type']) : 'general';
    $auto_enroll_rules = isset($params['auto_enroll_rules']) ? $params['auto_enroll_rules'] : array();

    if (empty($name)) {
        return new WP_Error('missing_name', __('List name is required', 'email-manager'), array('status' => 400));
    }

    $list_id = em_create_list($name, $description, $type, $auto_enroll_rules);

    if ($list_id) {
        $list = em_get_list($list_id);
        return array(
            'success' => true,
            'message' => __('List created successfully', 'email-manager'),
            'list' => $list
        );
    }

    return new WP_Error('create_failed', __('Failed to create list', 'email-manager'), array('status' => 500));
}

function em_get_list_handler(WP_REST_Request $request)
{
    $list_id = (int) $request['id'];
    $list = em_get_list($list_id);

    if (!$list) {
        return new WP_Error('not_found', __('List not found', 'email-manager'), array('status' => 404));
    }

    $auto_enroll_rules = em_get_list_auto_enroll_rules($list_id);
    $list->auto_enroll_rules = $auto_enroll_rules;

    return array(
        'success' => true,
        'list' => $list
    );
}

function em_update_list_handler(WP_REST_Request $request)
{
    $list_id = (int) $request['id'];
    $params = $request->get_json_params();

    $result = em_update_list($list_id, $params);

    if ($result) {
        $list = em_get_list($list_id);
        return array(
            'success' => true,
            'message' => __('List updated successfully', 'email-manager'),
            'list' => $list
        );
    }

    return new WP_Error('update_failed', __('Failed to update list', 'email-manager'), array('status' => 500));
}

function em_delete_list_handler(WP_REST_Request $request)
{
    $list_id = (int) $request['id'];
    // In original code: $delete_subscribers = isset($_GET['delete_subscribers']) && $_GET['delete_subscribers'] === 'true';
    $delete_subscribers = $request->get_param('delete_subscribers') === 'true';

    $result = em_delete_list($list_id, $delete_subscribers);

    if ($result) {
        return array(
            'success' => true,
            'message' => __('List deleted successfully', 'email-manager')
        );
    }

    return new WP_Error('delete_failed', __('Failed to delete list', 'email-manager'), array('status' => 500));
}

function em_get_list_subscribers_handler(WP_REST_Request $request)
{
    $list_id = (int) $request['id'];
    $subscribers = em_get_list_subscribers($list_id);

    return array(
        'success' => true,
        'subscribers' => $subscribers
    );
}

// Added this to handle the JS call: endpoint + '/' + currentListId + '/subscribers' (POST) with subscriber_id
function em_add_subscriber_to_list_handler(WP_REST_Request $request)
{
    global $wpdb;
    $list_id = (int) $request['id'];
    $params = $request->get_json_params();
    $subscriber_id = isset($params['subscriber_id']) ? (int) $params['subscriber_id'] : 0;

    if (!$subscriber_id) {
        return new WP_Error('missing_subscriber', __('Subscriber ID is required', 'email-manager'), array('status' => 400));
    }

    // Manual insertion into join table as no specific function exists in email-subscribers.php for just linking
    // Actually em_add_subscriber handles linking if list_ids provides.
    // But here we are just linking an existing subscriber.
    // Let's check if there is a function. No.
    // Creating manual linking here to match expected JS behavior.

    $table_list_subs = $wpdb->prefix . 'gdc_email_list_subscribers';
    $current_time = current_time('mysql');

    // Check if exists
    $in_list = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_list_subs WHERE list_id = %d AND subscriber_id = %d",
        $list_id,
        $subscriber_id
    ));

    if (!$in_list) {
        $result = $wpdb->insert(
            $table_list_subs,
            array(
                'list_id' => $list_id,
                'subscriber_id' => $subscriber_id,
                'subscribed_at' => $current_time
            ),
            array('%d', '%d', '%s')
        );
        if ($result) {
            return array('success' => true);
        }
        return new WP_Error('db_error', __('Failed to link subscriber', 'email-manager'), array('status' => 500));
    }

    return array('success' => true);
}


function em_add_subscriber_handler(WP_REST_Request $request)
{
    $params = $request->get_json_params();

    $email = isset($params['email']) ? sanitize_email($params['email']) : '';
    $first_name = isset($params['first_name']) ? sanitize_text_field($params['first_name']) : '';
    $last_name = isset($params['last_name']) ? sanitize_text_field($params['last_name']) : '';
    $list_ids = isset($params['list_ids']) ? $params['list_ids'] : array();

    if (!is_email($email)) {
        return new WP_Error('invalid_email', __('Invalid email address', 'email-manager'), array('status' => 400));
    }

    // In original code, it required list_ids.
    // if (empty($list_ids)) {
    //     return new WP_Error('missing_list', __('Please select at least one list', 'gdc'), array('status' => 400));
    // }
    // However, JS logic seems to create subscriber first then link.
    // We will allow empty list_ids for creation, then linking separately if needed.
    // But original code had check. We'll keep it lax if JS does two steps.

    $subscriber_id = em_add_subscriber($email, $first_name, $last_name, $list_ids);

    if ($subscriber_id) {
        return array(
            'success' => true,
            'message' => __('Subscriber added successfully', 'email-manager'),
            'subscriber_id' => $subscriber_id,
            'id' => $subscriber_id // Return id as well for JS convenience
        );
    }

    return new WP_Error('add_failed', __('Failed to add subscriber', 'email-manager'), array('status' => 500));
}

function em_remove_subscriber_handler(WP_REST_Request $request)
{
    // URL: /email-lists/(?P<id>\d+)/subscribers/(?P<sub_id>\d+)
    $list_id = (int) $request['id'];
    $subscriber_id = (int) $request['sub_id'];

    if (!$list_id) {
        return new WP_Error('missing_list_id', __('List ID is required', 'email-manager'), array('status' => 400));
    }

    $result = em_remove_subscriber($subscriber_id, $list_id);

    if ($result) {
        return array(
            'success' => true,
            'message' => __('Subscriber removed successfully', 'email-manager')
        );
    }

    return new WP_Error('remove_failed', __('Failed to remove subscriber', 'email-manager'), array('status' => 500));
}

function em_import_csv_handler(WP_REST_Request $request)
{
    $files = $request->get_file_params();
    $list_id = (int) $request['id'];

    if (!$list_id) {
        return new WP_Error('missing_list_id', __('List ID is required', 'email-manager'), array('status' => 400));
    }

    // In original code, it checked $_FILES['file'] or params.
    // If sent as JSON body with `subscribers` array (from visual inspection of `email-lists-table.js` line 392):
    // data: JSON.stringify({ subscribers })
    // It seems the JS parses CSV client side and sends JSON!
    // Let's check `email-lists-table.js` line 392 again.
    // $.ajax({ url: ... + '/subscribers/import', method: 'POST', contentType: 'application/json', data: JSON.stringify({ subscribers }) })
    // So `email-rest-handlers.php` logic for CSV file upload (lines 168+) seems mismatch with JS!
    // Wait, let me re-read `email-rest-handlers.php`.
    // The original `email-rest-handlers.php` had `gdc_import_csv_handler` using `$request->get_file_params()`.
    // But `email-lists-table.js` uses `data: JSON.stringify({ subscribers })`.
    // It's possible I am looking at a mismatched version or the JS logic I saw was updated.
    // I will support BOTH or prioritize the JS behavior I saw.
    // The JS I saw in `email-lists-table.js`:
    // reader.onload... var subscribers = []; ... $.ajax( ... data: JSON.stringify({ subscribers }) )
    // So the handler MUST accept JSON `subscribers`.

    $params = $request->get_json_params();
    if (isset($params['subscribers']) && is_array($params['subscribers'])) {
        // Handle JSON import
        $results = array('success' => 0, 'errors' => array());
        foreach ($params['subscribers'] as $sub) {
            $email = isset($sub['email']) ? $sub['email'] : '';
            $fname = isset($sub['first_name']) ? $sub['first_name'] : '';
            $lname = isset($sub['last_name']) ? $sub['last_name'] : '';
            if (em_add_subscriber($email, $fname, $lname, array($list_id))) {
                $results['success']++;
            } else {
                $results['errors'][] = "Failed to add $email";
            }
        }
        return array(
            'success' => true,
            'message' => sprintf(__('Imported %d subscribers', 'email-manager'), $results['success']),
            'imported' => $results['success'], // JS expects 'added' or 'imported'? JS: result.added || subscribers.length
            'added' => $results['success'],
            'errors' => $results['errors']
        );
    }

    // Fallback to file check just in case
    if (!isset($files['file']) || $files['file']['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', __('File upload failed or invalid JSON data', 'email-manager'), array('status' => 400));
    }

    $file_path = $files['file']['tmp_name'];
    $result = em_import_subscribers_csv($file_path, $list_id);

    return array(
        'success' => true,
        'message' => sprintf(__('Imported %d subscribers', 'email-manager'), $result['success']),
        'imported' => $result['success'],
        'errors' => $result['errors']
    );
}
