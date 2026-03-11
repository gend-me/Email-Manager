<?php

class Chat_Forms_Core
{

    public function run()
    {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function load_dependencies()
    {
        require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-admin.php';
        require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-public.php';
        require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-cpt.php';
        require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-ajax.php';
        require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-submissions.php';
        require_once EMAIL_MANAGER_PATH . 'inc/forms/class-chat-forms-export.php';
        new Chat_Forms_Ajax();
        new Chat_Forms_Submissions();
    }

    private function define_admin_hooks()
    {
        $plugin_admin = new Chat_Forms_Admin();
        add_action('admin_menu', array($plugin_admin, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($plugin_admin, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($plugin_admin, 'add_meta_boxes'));
        add_action('save_post', array($plugin_admin, 'save_questions'));
        add_action('wp_ajax_chat_forms_send_test_email', array($plugin_admin, 'send_test_email'));
    }

    private function define_public_hooks()
    {
        $plugin_public = new Chat_Forms_Public();
        add_action('wp_enqueue_scripts', array($plugin_public, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($plugin_public, 'enqueue_scripts'));
        add_shortcode('chat_form', array($plugin_public, 'render_shortcode'));
        add_shortcode('basic_form', array($plugin_public, 'render_basic_form_shortcode'));
    }
}
