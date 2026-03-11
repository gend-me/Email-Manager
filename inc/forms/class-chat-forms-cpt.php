<?php

class Chat_Forms_CPT
{

    public function parse_request($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ('chat_form' === $query->get('post_type')) {
            // Ensure we are getting all posts for the custom admin page if needed
        }
    }

    public function __construct()
    {
        $post_types = array('chat_form', 'basic_form');
        foreach ($post_types as $pt) {
            add_filter("manage_{$pt}_posts_columns", array($this, 'set_custom_columns'));
            add_action("manage_{$pt}_posts_custom_column", array($this, 'custom_column_content'), 10, 2);
        }
    }

    public function register_cpt()
    {
        // 1. Register Basic Forms CPT (Parent Menu)
        $basic_labels = array(
            'name' => _x('Forms', 'Post Type General Name', 'chat-forms'),
            'singular_name' => _x('Form', 'Post Type Singular Name', 'chat-forms'),
            'menu_name' => __('Forms', 'chat-forms'),
            'name_admin_bar' => __('Form', 'chat-forms'),
            'all_items' => __('All Forms', 'chat-forms'),
            'add_new_item' => __('Add New Form', 'chat-forms'),
            'add_new' => __('Add New', 'chat-forms'),
            'new_item' => __('New Form', 'chat-forms'),
            'edit_item' => __('Edit Form', 'chat-forms'),
            'update_item' => __('Update Form', 'chat-forms'),
            'view_item' => __('View Form', 'chat-forms'),
            'search_items' => __('Search Forms', 'chat-forms'),
            'not_found' => __('Not found', 'chat-forms'),
            'not_found_in_trash' => __('Not found in Trash', 'chat-forms'),
        );
        $basic_args = array(
            'label' => __('Form', 'chat-forms'),
            'description' => __('Basic Forms', 'chat-forms'),
            'labels' => $basic_labels,
            'supports' => array('title', 'custom-fields'),
            'hierarchical' => false,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Hidden from main sidebar, will be in Email Manager
            'menu_position' => 5,
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => true,
            'can_export' => true,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'capability_type' => 'page',
            'menu_icon' => 'dashicons-feedback', // A different icon to distinguish
        );
        register_post_type('basic_form', $basic_args);

        // 2. Register Chat Forms CPT (Submenu)
        $labels = array(
            'name' => _x('Chat Forms', 'Post Type General Name', 'chat-forms'),
            'singular_name' => _x('Chat Form', 'Post Type Singular Name', 'chat-forms'),
            'menu_name' => __('Chat Forms', 'chat-forms'),
            'name_admin_bar' => __('Chat Form', 'chat-forms'),
            'archives' => __('Item Archives', 'chat-forms'),
            'attributes' => __('Item Attributes', 'chat-forms'),
            'parent_item_colon' => __('Parent Item:', 'chat-forms'),
            'all_items' => __('All Chat Forms', 'chat-forms'),
            'add_new_item' => __('Add New Chat Form', 'chat-forms'),
            'add_new' => __('Add New', 'chat-forms'),
            'new_item' => __('New Item', 'chat-forms'),
            'edit_item' => __('Edit Item', 'chat-forms'),
            'update_item' => __('Update Item', 'chat-forms'),
            'view_item' => __('View Item', 'chat-forms'),
            'view_items' => __('View Items', 'chat-forms'),
            'search_items' => __('Search Item', 'chat-forms'),
            'not_found' => __('Not found', 'chat-forms'),
            'not_found_in_trash' => __('Not found in Trash', 'chat-forms'),
            'featured_image' => __('Featured Image', 'chat-forms'),
            'set_featured_image' => __('Set featured image', 'chat-forms'),
            'remove_featured_image' => __('Remove featured image', 'chat-forms'),
            'use_featured_image' => __('Use as featured image', 'chat-forms'),
            'insert_into_item' => __('Insert into item', 'chat-forms'),
            'uploaded_to_this_item' => __('Uploaded to this item', 'chat-forms'),
            'items_list' => __('Items list', 'chat-forms'),
            'items_list_navigation' => __('Items list navigation', 'chat-forms'),
            'filter_items_list' => __('Filter items list', 'chat-forms'),
        );
        $args = array(
            'label' => __('Chat Form', 'chat-forms'),
            'description' => __('Chat Forms', 'chat-forms'),
            'labels' => $labels,
            'supports' => array('title', 'custom-fields'), // Title for name, custom-fields for questions (though we'll likely use a custom meta box or serialized data)
            'hierarchical' => false,
            'public' => false, // Not publicly queryable like a blog post
            'show_ui' => true,  // Show in admin
            'show_in_menu' => false, // Hidden from main sidebar
            'menu_position' => 5,
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => true,
            'can_export' => true,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'capability_type' => 'page',
            'menu_icon' => 'dashicons-format-chat',
        );
        register_post_type('chat_form', $args);

        // Register Submissions CPT
        $submission_labels = array(
            'name' => _x('Chat Submissions', 'Post Type General Name', 'chat-forms'),
            'singular_name' => _x('Chat Submission', 'Post Type Singular Name', 'chat-forms'),
            'menu_name' => __('Submissions', 'chat-forms'),
        );
        $submission_args = array(
            'label' => __('Chat Submission', 'chat-forms'),
            'labels' => $submission_labels,
            'supports' => array('title', 'editor', 'custom-fields'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Hidden from main sidebar
            'capabilities' => array(
                'create_posts' => 'do_not_allow', // Users can't create submissions manually in admin
            ),
            'map_meta_cap' => true,
        );
        register_post_type('chat_submission', $submission_args);
    }

    public function set_custom_columns($columns)
    {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['shortcode'] = __('Shortcode', 'chat-forms');
            }
        }
        return $new_columns;
    }

    public function custom_column_content($column, $post_id)
    {
        if ($column === 'shortcode') {
            $post_type = get_post_type($post_id);
            $shortcode_tag = ($post_type === 'basic_form') ? 'basic_form' : 'chat_form';
            echo '<input type="text" readonly value="[' . $shortcode_tag . ' id=\'' . $post_id . '\']" style="width:100%;" onclick="this.select();" />';
        }
    }
}

// Initialize CPT
$cpt = new Chat_Forms_CPT();
add_action('init', array($cpt, 'register_cpt'));

