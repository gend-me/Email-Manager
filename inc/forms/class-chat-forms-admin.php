<?php

class Chat_Forms_Admin
{

    public function add_plugin_admin_menu()
    {
        // Removed custom menu page, using 'basic_form' CPT menu natively
    }

    public function enqueue_admin_scripts($hook)
    {
        // Hook styles for edit page
        add_action('admin_head', array($this, 'add_form_edit_styles'));

        $screen = get_current_screen();
        if ($screen && ($screen->post_type === 'chat_form' || $screen->post_type === 'chat_submission' || $screen->post_type === 'basic_form')) {
            // Enqueue styles
            wp_enqueue_style('chat_forms_admin_css', EMAIL_MANAGER_URL . 'assets/forms/admin.css', array(), '1.1.0', 'all');

            if ($screen->post_type === 'chat_form' || $screen->post_type === 'basic_form') {
                // Enqueue media uploader
                wp_enqueue_media();

                // Enqueue jQuery UI for sortable
                wp_enqueue_script('jquery-ui-sortable');

                // Ensure TinyMCE + quicktags + wp.editor JS API are loaded so we can
                // dynamically initialize editors on newly-added Info Block questions.
                if (function_exists('wp_enqueue_editor')) {
                    wp_enqueue_editor();
                }

                wp_enqueue_script('chat-forms-admin-js', EMAIL_MANAGER_URL . 'assets/forms/admin.js', array('jquery', 'jquery-ui-sortable'), '2.0', true);
                wp_localize_script('chat-forms-admin-js', 'chatFormsAjax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('chat_forms_nonce')
                ));
                wp_localize_script('chat-forms-admin-js', 'emLeoConfig', array(
                    'hubUrl'      => class_exists('EM_Leo_OAuth') ? EM_Leo_OAuth::hub_url() : 'https://gend.me',
                    'clientId'    => class_exists('EM_Leo_OAuth') ? EM_Leo_OAuth::client_id() : '',
                    'statusUrl'   => rest_url('em/v1/oauth/status'),
                    'exchangeUrl' => rest_url('em/v1/oauth/exchange'),
                    'revokeUrl'   => rest_url('em/v1/oauth/revoke'),
                    'balanceUrl'  => rest_url('em/v1/oauth/balance'),
                ));

                // Live-preview support — load the public chat assets on the edit page
                // so the "Test This Chatflow" button can mount the actual chat widget.
                wp_enqueue_style('chat_forms_frontend_css', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.css', array(), '1.3.0', 'all');
                wp_enqueue_style('chat_forms_widget_launcher_css', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.css', array('chat_forms_frontend_css'), '1.3.0', 'all');
                wp_enqueue_script('chat_forms_frontend_js', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.js', array('jquery'), '1.6', true);
                wp_enqueue_script('chat_forms_widget_launcher_js', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.js', array('jquery', 'chat_forms_frontend_js'), '1.0.0', true);

                $cu = wp_get_current_user();
                wp_localize_script('chat_forms_frontend_js', 'chatFormsPublic', array(
                    'ajaxUrl'     => admin_url('admin-ajax.php'),
                    'nonce'       => wp_create_nonce('chat_forms_submit_nonce'),
                    'isLoggedIn'  => is_user_logged_in(),
                    'currentUser' => is_user_logged_in() ? array(
                        'user_id'      => $cu->ID,
                        'username'     => $cu->user_login,
                        'display_name' => $cu->display_name,
                        'avatar_url'   => get_avatar_url($cu->ID, array('size' => 96)),
                        'email'        => $cu->user_email,
                    ) : array(),
                    'brand'       => wp_parse_args(get_option('em_chat_brand_colors', array()), array(
                        'primary'   => '#6366f1',
                        'secondary' => '#8b5cf6',
                        'surface'   => 'rgba(15, 23, 42, 0.9)',
                        'text'      => '#f8fafc',
                    )),
                ));

                // Render Modal in Footer to avoid overflow/z-index issues
                add_action('admin_footer', array($this, 'render_email_modal'));
            }
        }
    }

    public function add_form_edit_styles()
    {
        $screen = get_current_screen();
        if (!$screen) return;

        // Apply styles to 'edit-XX' list pages OR the single 'post' edit pages for forms
        if (in_array($screen->id, array('edit-basic_form', 'basic_form', 'edit-chat_form', 'chat_form', 'edit-chat_submission', 'chat_submission'))) {
            echo '<style>
                /* Overall Background & Glassmorphic Container */
                #wpbody-content {
                    background: #0b0e14;
                    padding-top: 20px;
                }
                .wrap {
                    background: var(--em-glass-bg);
                    backdrop-filter: var(--em-glass-blur);
                    border: 1px solid var(--em-glass-border);
                    border-radius: var(--em-border-radius);
                    padding: 32px;
                    box-shadow: var(--em-panel-shadow);
                    max-width: 1200px;
                    margin: 20px auto;
                    color: var(--em-text-primary);
                }
                
                /* Title Area */
                .wrap > h1.wp-heading-inline {
                    font-size: 2rem;
                    font-weight: 700;
                    color: var(--em-text-primary);
                    margin-bottom: 24px;
                    letter-spacing: -0.02em;
                }
                .page-title-action {
                    background: var(--em-gradient-indigo) !important;
                    color: white !important;
                    border: 1px solid rgba(255,255,255,0.1) !important;
                    border-radius: 10px !important;
                    padding: 10px 24px !important;
                    font-weight: 600 !important;
                    text-transform: uppercase !important;
                    letter-spacing: 0.05em !important;
                    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
                    transition: all 0.3s ease !important;
                    margin-left: 15px !important;
                }
                .page-title-action:hover {
                    transform: translateY(-2px) !important;
                    box-shadow: 0 8px 25px var(--em-glow-primary) !important;
                    filter: brightness(1.1);
                }

                /* Metabox Styling */
                .postbox {
                    background: rgba(15, 23, 42, 0.6) !important;
                    border: 1px solid var(--em-glass-border) !important;
                    border-radius: 12px !important;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.1) !important;
                    margin-bottom: 24px !important;
                    color: var(--em-text-primary) !important;
                }
                .postbox-header {
                    border-bottom: 1px solid var(--em-glass-border) !important;
                    background: rgba(99, 102, 241, 0.05) !important;
                    border-top-left-radius: 12px !important;
                    border-top-right-radius: 12px !important;
                    padding: 12px 18px !important;
                }
                .postbox-header h2 {
                    font-weight: 700 !important;
                    color: var(--em-text-primary) !important;
                    text-transform: uppercase;
                    letter-spacing: 0.08em;
                    font-size: 13px !important;
                }
                .postbox .inside {
                    padding: 20px !important;
                    color: var(--em-text-secondary) !important;
                }

                /* ============================================================
                   WordPress list tables (.wp-list-table.widefat) — match LEO
                   obsidian theme so white text never lands on white rows.
                   ============================================================ */
                .wp-list-table,
                table.widefat {
                    background: rgba(15, 23, 42, 0.6) !important;
                    border: 1px solid rgba(255, 255, 255, 0.08) !important;
                    border-radius: 12px !important;
                    overflow: hidden !important;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25) !important;
                    color: #f3f4f6 !important;
                    border-spacing: 0 !important;
                }

                .wp-list-table thead th,
                .wp-list-table tfoot th,
                table.widefat thead th,
                table.widefat tfoot th {
                    background: rgba(255, 255, 255, 0.04) !important;
                    color: #cbd5f5 !important;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
                    font-weight: 700 !important;
                    text-transform: uppercase;
                    letter-spacing: 0.06em;
                    font-size: 0.72rem !important;
                    padding: 14px 16px !important;
                }

                .wp-list-table thead th a,
                .wp-list-table thead th a span,
                table.widefat thead th a,
                table.widefat thead th a span {
                    color: #cbd5f5 !important;
                }

                .wp-list-table tbody td,
                table.widefat tbody td,
                .wp-list-table tbody th,
                table.widefat tbody th {
                    background: transparent !important;
                    color: #f3f4f6 !important;
                    border-top: 1px solid rgba(255, 255, 255, 0.04) !important;
                    padding: 14px 16px !important;
                    vertical-align: middle !important;
                }

                .wp-list-table tbody tr,
                table.widefat tbody tr {
                    background: transparent !important;
                    transition: background 200ms ease;
                }

                .wp-list-table.striped > tbody > tr:nth-child(odd),
                table.widefat.striped > tbody > tr:nth-child(odd),
                .wp-list-table > tbody > tr.alternate,
                table.widefat > tbody > tr.alternate {
                    background: rgba(255, 255, 255, 0.025) !important;
                }

                .wp-list-table tbody tr:hover,
                table.widefat tbody tr:hover {
                    background: rgba(99, 102, 241, 0.08) !important;
                }

                /* Title cells + row-action links */
                .wp-list-table .row-title,
                .wp-list-table tbody td strong a,
                .wp-list-table tbody td strong {
                    color: #f3f4f6 !important;
                    font-weight: 700;
                }

                .wp-list-table .row-actions,
                .wp-list-table .row-actions a {
                    color: #94a3b8 !important;
                }

                .wp-list-table .row-actions a:hover {
                    color: #818cf8 !important;
                }

                /* Date / author / column-secondary text */
                .wp-list-table tbody td .post-state,
                .wp-list-table tbody td a {
                    color: #cbd5f5 !important;
                }

                /* Read-only shortcode <input> inside table cells */
                .wp-list-table tbody td input[type="text"][readonly],
                table.widefat tbody td input[type="text"][readonly] {
                    background: rgba(255, 255, 255, 0.06) !important;
                    color: #f3f4f6 !important;
                    border: 1px solid rgba(255, 255, 255, 0.12) !important;
                    border-radius: 6px !important;
                    padding: 6px 10px !important;
                }

                /* Table footer + pagination */
                .tablenav,
                .tablenav.bottom,
                .tablenav.top {
                    background: transparent !important;
                    color: #cbd5f5 !important;
                    margin: 14px 0 !important;
                }

                .tablenav .tablenav-pages a,
                .tablenav .tablenav-pages span.current,
                .tablenav .displaying-num {
                    color: #cbd5f5 !important;
                }

                .tablenav .tablenav-pages span.current {
                    background: rgba(79, 70, 229, 0.18) !important;
                    border-color: rgba(79, 70, 229, 0.4) !important;
                }

                /* "All | Published | Drafts | Trash" filters above the list */
                .subsubsub a,
                .subsubsub li,
                .subsubsub .count {
                    color: #cbd5f5 !important;
                }

                .subsubsub a.current {
                    color: #f3f4f6 !important;
                    font-weight: 700;
                }

                /* Bulk actions / search input */
                .tablenav select,
                .search-box input[type="search"] {
                    background: rgba(0, 0, 0, 0.3) !important;
                    border: 1px solid rgba(255, 255, 255, 0.15) !important;
                    color: #f3f4f6 !important;
                    border-radius: 8px !important;
                    padding: 6px 10px !important;
                }

                .search-box input[type="search"]::placeholder {
                    color: rgba(203, 213, 245, 0.5) !important;
                }

                /* Checkbox column on list tables */
                .wp-list-table .check-column input[type="checkbox"] {
                    accent-color: #4f46e5;
                }

                /* "No items" empty state */
                .wp-list-table .no-items td {
                    color: #94a3b8 !important;
                    text-align: center !important;
                    padding: 32px 16px !important;
                }

                /* ═══ Section builder — full dark glass restyle ═══
                   The section cards + sub-editors carried light inline
                   backgrounds (white / #eef2ff / #fff8e6) from the pre-dark
                   era, leaving headings unreadable on the dark shell.
                   !important beats those inline styles. */
                .chat-form-question {
                    background: linear-gradient(160deg, rgba(15,23,42,.82), rgba(12,16,44,.6)) !important;
                    border: 1px solid rgba(125,211,252,.18) !important;
                    border-radius: 16px !important;
                    padding: 18px 20px !important;
                    margin: 0 0 16px !important;
                    box-shadow: 0 14px 34px rgba(0,0,0,.35) !important;
                    color: #cbd5e1 !important;
                }
                .chat-form-question h4 {
                    color: #f8fafc !important;
                    display: flex; align-items: center; gap: 10px;
                    border-bottom: 1px solid rgba(125,211,252,.14);
                    padding-bottom: 10px; margin: 0 0 14px;
                }
                .chat-form-question .drag-handle { color: #64748b !important; }
                .chat-form-question .question-number { color: #7dd3fc !important; }
                .chat-form-question .question-actions { margin-left: auto; display: flex; gap: 8px; }
                .chat-form-question p,
                .chat-form-question label,
                .chat-form-question strong,
                .chat-form-question legend {
                    color: #e2e8f0 !important;
                }
                .chat-form-question small,
                .chat-form-question .description {
                    color: #94a3b8 !important;
                }

                /* Every input surface inside a section — dark glass. */
                .chat-form-question input[type="text"],
                .chat-form-question input[type="email"],
                .chat-form-question input[type="number"],
                .chat-form-question input[type="url"],
                .chat-form-question input[type="search"],
                .chat-form-question select,
                .chat-form-question textarea {
                    background: rgba(2,6,23,.62) !important;
                    color: #e2e8f0 !important;
                    border: 1px solid rgba(125,211,252,.22) !important;
                    border-radius: 10px !important;
                    box-shadow: none !important;
                }
                .chat-form-question input:focus,
                .chat-form-question select:focus,
                .chat-form-question textarea:focus {
                    border-color: rgba(34,211,238,.55) !important;
                    box-shadow: 0 0 14px rgba(34,211,238,.2) !important;
                    outline: none !important;
                }
                .chat-form-question ::placeholder { color: #64748b !important; }
                .chat-form-question select option { background: #0b1120 !important; color: #e2e8f0 !important; }

                /* Buttons inside sections (Duplicate / Remove / tokens / options). */
                .chat-form-question .button {
                    background: rgba(15,23,42,.8) !important;
                    color: #cbd5e1 !important;
                    border: 1px solid rgba(125,211,252,.22) !important;
                    border-radius: 999px !important;
                    box-shadow: none !important;
                    transition: color .2s ease, border-color .2s ease, box-shadow .25s ease;
                }
                .chat-form-question .button:hover {
                    color: #eafcff !important;
                    border-color: rgba(34,211,238,.5) !important;
                    box-shadow: 0 0 14px rgba(34,211,238,.18) !important;
                }
                .chat-form-question .remove-question {
                    color: #fda4af !important;
                    border-color: rgba(244,63,94,.35) !important;
                }
                .chat-form-question .remove-question:hover {
                    color: #fecdd3 !important;
                    border-color: rgba(244,63,94,.6) !important;
                    box-shadow: 0 0 14px rgba(244,63,94,.2) !important;
                }
                .chat-form-question .em-prompt-token {
                    font-family: ui-monospace, SFMono-Regular, Menlo, monospace !important;
                    font-size: 11px !important;
                    color: #a5b4fc !important;
                    border-color: rgba(99,102,241,.4) !important;
                }

                /* AI Prompt sub-editor — indigo glass. */
                .chat-form-question .prompt-response-editor {
                    background: rgba(99,102,241,.1) !important;
                    border: 1px solid rgba(99,102,241,.28) !important;
                    border-left: 4px solid #6366f1 !important;
                    border-radius: 12px !important;
                }
                .chat-form-question .prompt-response-editor strong { color: #c7d2fe !important; }
                .chat-form-question .prompt-response-editor small { color: #a5b4fc !important; }
                .chat-form-question .prompt-response-editor hr { border-top-color: rgba(99,102,241,.3) !important; }
                .chat-form-question .prompt-response-pays-admin {
                    background: rgba(2,6,23,.55) !important;
                    border: 1px solid rgba(99,102,241,.3) !important;
                    border-radius: 10px !important;
                }
                .chat-form-question .prompt-response-pays-admin .em-leo-admin-status { color: #a5b4fc !important; }
                .chat-form-question .prompt-response-pays-chat-user { color: #94a3b8 !important; }

                /* Content Block sub-editor — amber glass. */
                .chat-form-question .info-block-editor {
                    background: rgba(240,180,41,.08) !important;
                    border: 1px solid rgba(240,180,41,.28) !important;
                    border-left: 4px solid #f0b429 !important;
                    border-radius: 12px !important;
                }
                .chat-form-question .info-block-editor strong { color: #fde68a !important; }
                .chat-form-question .info-block-editor small { color: #fcd34d !important; }
                /* wp_editor inside the content block keeps a light canvas for
                   WYSIWYG readability — just frame it. */
                .chat-form-question .info-block-editor .wp-editor-container {
                    border: 1px solid rgba(240,180,41,.3) !important;
                    border-radius: 8px; overflow: hidden;
                }
                .chat-form-question .info-block-editor .wp-editor-tabs .wp-switch-editor {
                    background: rgba(2,6,23,.55) !important;
                    color: #cbd5e1 !important;
                    border-color: rgba(240,180,41,.3) !important;
                }

                /* Support-Ticket sub-editor — teal glass. */
                .chat-form-question .support-ticket-editor {
                    background: rgba(20,184,166,.09) !important;
                    border: 1px solid rgba(20,184,166,.3) !important;
                    border-left: 4px solid #14b8a6 !important;
                    border-radius: 12px !important;
                }
                .chat-form-question .support-ticket-editor strong { color: #99f6e4 !important; }
                .chat-form-question .support-ticket-editor small { color: #5eead4 !important; }

                /* Options manager, validation + conditional-logic blocks. */
                .chat-form-question .options-manager,
                .chat-form-question .conditional-rules,
                .chat-form-question .validation-settings,
                .chat-form-question .conditional-logic-wrapper,
                .chat-form-question .validation-rules {
                    background: rgba(2,6,23,.4) !important;
                    border: 1px solid rgba(125,211,252,.14) !important;
                    border-radius: 12px !important;
                    color: #cbd5e1 !important;
                }
                .chat-form-question .option-item,
                .chat-form-question .option-response-wrap,
                .chat-form-question .conditional-rule-item {
                    background: rgba(15,23,42,.6) !important;
                    border: 1px solid rgba(125,211,252,.12) !important;
                    border-radius: 10px !important;
                }
                .chat-form-question .validation-settings p,
                .chat-form-question .validation-settings label,
                .chat-form-question .conditional-logic-wrapper label,
                .chat-form-question .conditional-rules label,
                .chat-form-question .conditional-rules p {
                    color: #e2e8f0 !important;
                }
                .chat-form-question .conditional-logic-wrapper { padding: 10px 12px; }
                .chat-form-question input[type="checkbox"],
                .chat-form-question input[type="radio"] {
                    background: rgba(2,6,23,.62) !important;
                    border: 1px solid rgba(125,211,252,.35) !important;
                }
            </style>';
        }
    }
    public function render_forms_tab()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Create a New Form', 'chat-forms'); ?></h1>
            <p class="description" style="font-size: 1.1rem; margin-bottom: 30px;"><?php _e('Choose a template below to get started, or create a blank form.', 'chat-forms'); ?></p>

            <div class="chat-forms-templates-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; margin-top: 20px;">
                <!-- Blank Form -->
                <div class="chat-form-template-card" style="background: rgba(255,255,255,0.03); border: 1px solid var(--em-glass-border); border-radius: 16px; padding: 32px; text-align: center; transition: all 0.3s ease; backdrop-filter: var(--em-glass-blur);">
                    <div style="font-size: 4em; margin-bottom: 20px; filter: drop-shadow(0 0 15px rgba(255,255,255,0.2));">📄</div>
                    <h3 style="margin-top: 0; color: var(--em-text-primary); font-size: 1.4rem;"><?php _e('Blank Form', 'chat-forms'); ?></h3>
                    <p style="color: var(--em-text-secondary); font-size: 14px; line-height: 1.6; margin-bottom: 24px;"><?php _e('Start from scratch with an empty canvas.', 'chat-forms'); ?></p>
                    <a href="<?php echo admin_url('post-new.php?post_type=chat_form'); ?>" class="button button-primary" style="width: 100%; box-sizing: border-box;"><?php _e('Create Blank', 'chat-forms'); ?></a>
                </div>

                <!-- Contact Form -->
                <div class="chat-form-template-card" style="background: rgba(255,255,255,0.03); border: 1px solid var(--em-glass-border); border-radius: 16px; padding: 32px; text-align: center; transition: all 0.3s ease; backdrop-filter: var(--em-glass-blur);">
                    <div style="font-size: 4em; margin-bottom: 20px; filter: drop-shadow(0 0 15px rgba(99,102,241,0.4));">✉️</div>
                    <h3 style="margin-top: 0; color: var(--em-text-primary); font-size: 1.4rem;"><?php _e('Contact Form', 'chat-forms'); ?></h3>
                    <p style="color: var(--em-text-secondary); font-size: 14px; line-height: 1.6; margin-bottom: 24px;"><?php _e('A standard setup to let users reach out to you.', 'chat-forms'); ?></p>
                    <a href="<?php echo admin_url('post-new.php?post_type=chat_form&template=contact'); ?>" class="button button-secondary" style="width: 100%; box-sizing: border-box; border-color: rgba(255,255,255,0.2);"><?php _e('Use Template', 'chat-forms'); ?></a>
                </div>

                <!-- Feedback Form -->
                <div class="chat-form-template-card" style="background: rgba(255,255,255,0.03); border: 1px solid var(--em-glass-border); border-radius: 16px; padding: 32px; text-align: center; transition: all 0.3s ease; backdrop-filter: var(--em-glass-blur);">
                    <div style="font-size: 4em; margin-bottom: 20px; filter: drop-shadow(0 0 15px rgba(236,72,153,0.4));">⭐</div>
                    <h3 style="margin-top: 0; color: var(--em-text-primary); font-size: 1.4rem;"><?php _e('Feedback Survey', 'chat-forms'); ?></h3>
                    <p style="color: var(--em-text-secondary); font-size: 14px; line-height: 1.6; margin-bottom: 24px;"><?php _e('Gather quick thoughts and star ratings.', 'chat-forms'); ?></p>
                    <a href="<?php echo admin_url('post-new.php?post_type=chat_form&template=feedback'); ?>" class="button button-secondary" style="width: 100%; box-sizing: border-box; border-color: rgba(255,255,255,0.2);"><?php _e('Use Template', 'chat-forms'); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_meta_boxes()
    {
        $post_types = array('chat_form', 'basic_form');

        foreach ($post_types as $pt) {
            add_meta_box(
                'chat_forms_builder',
                __('Form Questions', 'chat-forms'),
                array($this, 'render_form_builder_metabox'),
                $pt,
                'normal',
                'high'
            );
            add_meta_box(
                'chat_forms_success_message',
                __('🎉 Success Message & Redirect', 'chat-forms'),
                array($this, 'render_success_message_metabox'),
                $pt,
                'normal',
                'default'
            );
            add_meta_box(
                'chat_forms_email_logic',
                __('📧 Email Logic & Notifications', 'chat-forms'),
                array($this, 'render_email_logic_metabox'),
                $pt,
                'normal',
                'default'
            );
            add_meta_box(
                'chat_forms_styling',
                __('🎨 Custom Styling', 'chat-forms'),
                array($this, 'render_styling_metabox'),
                $pt,
                'side',
                'default'
            );
            add_meta_box(
                'chat_forms_analytics',
                __('📊 Form Analytics', 'chat-forms'),
                array($this, 'render_analytics_metabox'),
                $pt,
                'side',
                'default'
            );
            add_meta_box(
                'chat_forms_usage',
                __('📺 Display & Widget', 'chat-forms'),
                array($this, 'render_usage_metabox'),
                $pt,
                'side',
                'high'
            );
            add_meta_box(
                'chat_forms_security',
                __('🛡️ Security Settings', 'chat-forms'),
                array($this, 'render_security_metabox'),
                $pt,
                'side',
                'default'
            );
        }

        // Add Custom HTML metabox only for basic forms
        add_meta_box(
            'chat_forms_custom_html',
            __('🖌️ Custom HTML Styling', 'chat-forms'),
            array($this, 'render_custom_html_metabox'),
            'basic_form',
            'normal',
            'default'
        );
    }

    public function render_custom_html_metabox($post)
    {
        $custom_html = get_post_meta($post->ID, '_chat_form_custom_html', true);
        ?>
        <p class="description">
            <?php _e('Inject custom HTML, CSS, or wrapper elements above or below your form. This is for advanced styling of basic forms.', 'chat-forms'); ?>
        </p>
        <p>
            <label><strong><?php _e('Custom HTML / Scripts:', 'chat-forms'); ?></strong></label><br />
            <textarea name="chat_form_custom_html" class="large-text code" rows="6" style="font-family: monospace;"><?php echo esc_textarea($custom_html); ?></textarea>
        </p>
        <?php
    }

    public function render_form_builder_metabox($post)
    {
        wp_nonce_field('chat_forms_save_questions', 'chat_forms_questions_nonce'); // Kept original nonce name for questions
        $questions = get_post_meta($post->ID, '_chat_form_questions', true);

        // Live Preview Button (test the chat without recording)
        ?>
        <div style="margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #eef2ff 0%, #ede9fe 100%); border-left: 4px solid #6366f1; border-radius: 6px;">
            <p style="margin: 0 0 10px 0; font-weight: 600;">👁️ <?php _e('Live Preview', 'chat-forms'); ?></p>
            <button type="button" id="preview-form-btn" class="button button-primary" data-form-id="<?php echo esc_attr($post->ID); ?>" data-preview-mode="1">
                <?php _e('🔍 Test This Chatflow', 'chat-forms'); ?>
            </button>
            <small style="display:block;margin-top:8px;color:#475569;">
                <?php _e('Opens the actual chat — fully interactive. Submissions in preview mode are NOT recorded.', 'chat-forms'); ?>
            </small>
        </div>
        <?php

        if (!is_array($questions) || empty($questions)) {
            $questions = array();

            // Handle pre-filling templates
            if (isset($_GET['template'])) {
                $template = sanitize_text_field($_GET['template']);
                if ($template === 'contact') {
                    $questions = array(
                        array('text' => __('What is your name?', 'chat-forms'), 'type' => 'text', 'validation' => array('required' => 1)),
                        array('text' => __('What is your email address?', 'chat-forms'), 'type' => 'email', 'validation' => array('required' => 1)),
                        array('text' => __('How can we help you today?', 'chat-forms'), 'type' => 'text', 'validation' => array('required' => 1)),
                    );
                } elseif ($template === 'feedback') {
                    $questions = array(
                        array(
                            'text' => __('How would you rate your experience?', 'chat-forms'),
                            'type' => 'multiple',
                            'options' => array(
                                array('label' => 'Amazing 🤩', 'value' => 'amazing'),
                                array('label' => 'Good 🙂', 'value' => 'good'),
                                array('label' => 'Okay 😐', 'value' => 'okay'),
                                array('label' => 'Poor 😞', 'value' => 'poor'),
                            ),
                            'validation' => array('required' => 1)
                        ),
                        array('text' => __('Any additional comments?', 'chat-forms'), 'type' => 'text'),
                    );
                }
            }
        }
        ?>
        <div id="chat-forms-questions-wrapper">
            <div id="chat-forms-questions-container">
                <?php
                if (!empty($questions)) {
                    foreach ($questions as $index => $q) {
                        $this->render_single_question($index, $q);
                    }
                }
                ?>
            </div>
            <div class="em-cf-addsec" id="em-cf-addsec" style="margin-top:12px;">
                <button type="button" id="em-cf-addsec-btn" class="em-cf-addsec-btn">＋ <?php _e('Add New Section', 'chat-forms'); ?></button>
                <div class="em-cf-addsec-choices" id="em-cf-addsec-choices" hidden>
                    <button type="button" id="add-question" class="em-cf-addsec-choice"
                        title="<?php esc_attr_e('A question the member answers (text, choice, email…).', 'chat-forms'); ?>">
                        <span class="em-cf-addsec-ico">💬</span><span><?php _e('Question', 'chat-forms'); ?></span>
                    </button>
                    <button type="button" id="add-content-block" class="em-cf-addsec-choice"
                        title="<?php esc_attr_e('A rich-text or HTML content block (no user input).', 'chat-forms'); ?>">
                        <span class="em-cf-addsec-ico">📝</span><span><?php _e('Content Block', 'chat-forms'); ?></span>
                    </button>
                    <button type="button" id="add-ai-prompt" class="em-cf-addsec-choice"
                        title="<?php esc_attr_e('An AI-generated response step powered by LEO.', 'chat-forms'); ?>">
                        <span class="em-cf-addsec-ico">🤖</span><span><?php _e('AI Prompt', 'chat-forms'); ?></span>
                    </button>
                </div>
            </div>
            <style>
                @property --emcfsecang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
                #em-cf-addsec { perspective: 800px; }
                #em-cf-addsec .em-cf-addsec-btn,
                #em-cf-addsec .em-cf-addsec-choice {
                    position: relative; cursor: pointer;
                    background: linear-gradient(160deg, rgba(15,23,42,.85), rgba(12,16,44,.65));
                    color: #e2e8f0; border: 1px solid rgba(125,211,252,.25);
                    border-radius: 14px; font-weight: 700;
                    transform-style: preserve-3d; will-change: transform;
                    transition: transform .16s ease, box-shadow .28s ease, color .2s ease;
                }
                #em-cf-addsec .em-cf-addsec-btn { padding: 12px 26px; font-size: 14px; }
                #em-cf-addsec .em-cf-addsec-btn::before,
                #em-cf-addsec .em-cf-addsec-choice::before {
                    content: ''; position: absolute; inset: -1px; border-radius: 15px;
                    padding: 1.5px; pointer-events: none; opacity: 0;
                    background: conic-gradient(from var(--emcfsecang, 0deg), #22d3ee, #b608c9, #7dd3fc, #22d3ee);
                    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                    -webkit-mask-composite: xor;
                            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                            mask-composite: exclude;
                    transition: opacity .3s ease;
                }
                #em-cf-addsec .em-cf-addsec-btn:hover,
                #em-cf-addsec .em-cf-addsec-choice:hover {
                    color: #eafcff;
                    box-shadow: 0 16px 36px rgba(0,0,0,.45), 0 0 22px rgba(34,211,238,.22);
                }
                #em-cf-addsec .em-cf-addsec-btn:hover::before,
                #em-cf-addsec .em-cf-addsec-choice:hover::before {
                    opacity: 1;
                    animation: emCfSecSpin 2.6s linear infinite;
                }
                @keyframes emCfSecSpin { to { --emcfsecang: 360deg; } }
                #em-cf-addsec .em-cf-addsec-choices {
                    display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;
                }
                #em-cf-addsec .em-cf-addsec-choices[hidden] { display: none; }
                #em-cf-addsec .em-cf-addsec-choice {
                    display: flex; flex-direction: column; align-items: center; gap: 6px;
                    padding: 14px 22px; min-width: 130px; font-size: 12.5px;
                }
                #em-cf-addsec .em-cf-addsec-ico { font-size: 24px; }
                @media (prefers-reduced-motion: reduce) {
                    #em-cf-addsec .em-cf-addsec-btn::before,
                    #em-cf-addsec .em-cf-addsec-choice::before { animation: none !important; }
                }
            </style>
            <script>
            // Connected-device catalog for the AI Prompt "Runs At" selects
            // (group admins' devices incl. the desktop Claude terminal).
            window.emCfRunDevices = <?php echo wp_json_encode(em_cf_run_devices()); ?>;
            // Real AI Models leaderboard picks, pre-rendered as <option> HTML once (a brand new AI Prompt
            // question's JS template starts with an empty select and gets this same real markup spliced in,
            // exactly matching what an existing question already has PHP-rendered inline).
            window.emCfAiModelOptionsHtml = <?php echo wp_json_encode(em_cf_ai_model_options('')); ?>;
            jQuery(function ($) {
                var $choices = $('#em-cf-addsec-choices');
                $('#em-cf-addsec-btn').on('click', function () {
                    $choices.attr('hidden') !== undefined && $choices.is('[hidden]')
                        ? $choices.removeAttr('hidden') : $choices.attr('hidden', 'hidden');
                });
                // Picking a type collapses the chooser again (the existing
                // admin.js handlers on these ids do the actual append).
                $choices.on('click', '.em-cf-addsec-choice', function () {
                    $choices.attr('hidden', 'hidden');
                });
                // 3D tilt toward the cursor.
                $('#em-cf-addsec').on('mousemove', '.em-cf-addsec-btn, .em-cf-addsec-choice', function (e) {
                    var r = this.getBoundingClientRect();
                    var x = e.clientX - r.left, y = e.clientY - r.top;
                    var rx = ((y - r.height / 2) / r.height) * -8;
                    var ry = ((x - r.width / 2) / r.width) * 8;
                    this.style.transform = 'perspective(700px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) translateY(-1px)';
                }).on('mouseleave', '.em-cf-addsec-btn, .em-cf-addsec-choice', function () {
                    this.style.transform = '';
                });
            });
            </script>
        </div>
        <?php
    }

    public function render_success_message_metabox($post)
    {
        $thank_you_message = get_post_meta($post->ID, '_chat_form_thank_you_message', true);
        $redirect_url = get_post_meta($post->ID, '_chat_form_redirect_url', true);
        $launch_code = get_post_meta($post->ID, '_chat_form_launch_code', true);
        ?>
        <p>
            <label><strong><?php _e('Custom Thank You Message:', 'chat-forms'); ?></strong></label><br />
            <?php
            $settings = array(
                'media_buttons' => true,
                'textarea_name' => 'chat_form_thank_you_message',
                'textarea_rows' => 10,
                'teeny' => false
            );
            wp_editor($thank_you_message, 'chat_form_thank_you_message', $settings);
            ?>
            <small><?php _e('Displayed to the user after successful submission.', 'chat-forms'); ?></small>
        </p>

        <hr style="margin: 20px 0;" />

        <p>
            <label><strong><?php _e('Redirect URL (Optional):', 'chat-forms'); ?></strong></label><br />
            <input type="url" name="chat_form_redirect_url" value="<?php echo esc_attr($redirect_url); ?>" class="widefat"
                placeholder="https://example.com/thank-you" />
            <small><?php _e('If set, this will override the thank you message and redirect the user immediately.', 'chat-forms'); ?></small>
        </p>

        <hr style="margin: 20px 0;" />

        <p>
            <label><strong><?php _e('Launch AI Sequence on Completion (Optional):', 'chat-forms'); ?></strong></label><br />
            <input type="text" name="chat_form_launch_code" value="<?php echo esc_attr($launch_code); ?>" class="widefat"
                placeholder="LT-xxxxxxxxxx" />
            <small>
                <?php _e('Paste a Launch Trigger code (or its [gend_launch] shortcode) copied from the desktop app\'s Sequences popup. When this chatflow is completed, the linked AI Sequence fires automatically.', 'chat-forms'); ?>
            </small>
        </p>
        <?php
    }

    public function render_email_logic_metabox($post)
    {
        // Fetch existing rules (or migrate legacy settings if needed, though for now we start fresh/empty or pull legacy as a 'Default' rule if we wanted to be fancy. Let's start with just the container.)
        $email_rules = get_post_meta($post->ID, '_chat_form_email_rules', true);
        if (!is_array($email_rules)) {
            $email_rules = array();
            
            // Migration check: If no rules but legacy email exists, maybe create a default one? 
            // For now, let's just leave it empty to avoid confusion. User can create new ones.
        }
        ?>
        <div id="chat-forms-email-logic-wrapper">
            <p class="description">
                <?php _e('Configure email notifications sent upon form submission. You can set up multiple emails for admins, users, or specific teams.', 'chat-forms'); ?>
            </p>

            <table class="widefat fixed striped" id="chat-forms-email-rules-list">
                <thead>
                    <tr>
                        <th style="width: 200px;"><?php _e('Email Name', 'chat-forms'); ?></th>
                        <th><?php _e('Sent To', 'chat-forms'); ?></th>
                        <th style="width: 100px; text-align: right;"><?php _e('Actions', 'chat-forms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Rules will be injected here via JS -->
                    <tr class="no-rules-message" style="<?php echo empty($email_rules) ? '' : 'display:none;'; ?>">
                        <td colspan="3"><?php _e('No email rules defined. Click "Add New Email" to create one.', 'chat-forms'); ?></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 10px;">
                <button type="button" id="add-email-rule-btn" class="button button-primary">
                    <?php _e('+ Add New Email', 'chat-forms'); ?>
                </button>
            </div>

            <!-- Hidden input to store the JSON data -->
            <input type="hidden" name="chat_form_email_rules" id="chat_form_email_rules" 
                value="<?php echo esc_attr(json_encode($email_rules)); ?>" />
        </div>
        <?php
    }

    /**
     * Render Email Modal in Footer
     */
    public function render_email_modal()
    {
        // Check if we are on the correct screen
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('chat_form', 'basic_form'))) {
            return;
        }
        ?>
        <!-- Email Rule Editor Modal (Hidden) -->
        <div id="chat-forms-email-modal" style="display:none;">
            <div class="chat-forms-modal-backdrop"></div>
            <div class="chat-forms-modal-content">
                <div class="chat-forms-modal-header">
                    <h3><?php _e('Edit Email Rule', 'chat-forms'); ?></h3>
                    <button type="button" class="chat-forms-modal-close">&times;</button>
                </div>
                <div class="chat-forms-modal-body">
                    <input type="hidden" id="email_rule_id" />
                    <p>
                        <label><?php _e('Rule Name:', 'chat-forms'); ?></label>
                        <input type="text" id="email_rule_name" class="widefat" placeholder="e.g., Admin Notification" />
                    </p>
                    <p>
                        <label><?php _e('Send To:', 'chat-forms'); ?></label>
                        <input type="text" id="email_rule_to" class="widefat" placeholder="email@example.com" />
                        <small><?php _e('Enter email addresses (comma separated) or use {user_email} placeholder.', 'chat-forms'); ?></small>
                    </p>
                    <div style="display:flex; gap:10px;">
                        <p style="flex:1;">
                            <label><?php _e('CC:', 'chat-forms'); ?></label>
                            <input type="text" id="email_rule_cc" class="widefat" />
                        </p>
                        <p style="flex:1;">
                            <label><?php _e('BCC:', 'chat-forms'); ?></label>
                            <input type="text" id="email_rule_bcc" class="widefat" />
                        </p>
                    </div>
                    <p>
                        <label><?php _e('Subject:', 'chat-forms'); ?></label>
                        <input type="text" id="email_rule_subject" class="widefat" />
                    </p>
                    <p>
                        <label><?php _e('Email Body:', 'chat-forms'); ?></label>
                        <div id="email-dynamic-vars" style="margin-bottom: 10px; padding: 10px; background: rgba(2,6,23,.55); color: #e2e8f0; border: 1px solid rgba(125,211,252,.18); border-radius: 10px;">
                            <strong><?php _e('Insert Variable:', 'chat-forms'); ?></strong>
                            <div class="vars-list" style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        <?php
                        $editor_settings = array(
                            'media_buttons' => true,
                            'textarea_name' => 'email_rule_body_dummy', // We will sync this manually via JS
                            'textarea_rows' => 12,
                            'editor_class' => 'email-rule-editor',
                            'teeny' => false
                        );
                        wp_editor('', 'chat_form_email_body_editor', $editor_settings);
                        ?>
                    </p>
                </div>
                <div class="chat-forms-modal-footer" style="justify-content: space-between;">
                    <div style="display:flex; gap:5px; align-items:center;">
                        <input type="email" id="test-email-recipient" placeholder="test@example.com" class="regular-text" style="width:200px;" />
                        <button type="button" id="send-test-email-btn" class="button button-secondary"><?php _e('Send Test Email', 'chat-forms'); ?></button>
                    </div>
                    <button type="button" id="save-email-rule-btn" class="button button-primary"><?php _e('Save Rule', 'chat-forms'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render custom styling metabox
     */
    public function render_styling_metabox($post)
    {
        $gradient_start = get_post_meta($post->ID, '_chat_form_gradient_start', true) ?: '#667eea';
        $gradient_end = get_post_meta($post->ID, '_chat_form_gradient_end', true) ?: '#764ba2';
        $button_style = get_post_meta($post->ID, '_chat_form_button_style', true) ?: 'rounded';
        $font_family = get_post_meta($post->ID, '_chat_form_font_family', true) ?: 'system';
        ?>
        <div style="padding: 10px 0;">
            <p>
                <label><strong><?php _e('Gradient Start:', 'chat-forms'); ?></strong></label><br />
                <input type="color" name="chat_form_gradient_start" value="<?php echo esc_attr($gradient_start); ?>"
                    style="width: 100%; height: 40px; cursor: pointer;" />
            </p>
            <p>
                <label><strong><?php _e('Gradient End:', 'chat-forms'); ?></strong></label><br />
                <input type="color" name="chat_form_gradient_end" value="<?php echo esc_attr($gradient_end); ?>"
                    style="width: 100%; height: 40px; cursor: pointer;" />
            </p>
            <p>
                <label><strong><?php _e('Button Style:', 'chat-forms'); ?></strong></label><br />
                <select name="chat_form_button_style" style="width: 100%;">
                    <option value="rounded" <?php selected($button_style, 'rounded'); ?>>Rounded</option>
                    <option value="pill" <?php selected($button_style, 'pill'); ?>>Pill</option>
                    <option value="square" <?php selected($button_style, 'square'); ?>>Square</option>
                </select>
            </p>
            <p>
                <label><strong><?php _e('Font Family:', 'chat-forms'); ?></strong></label><br />
                <select name="chat_form_font_family" style="width: 100%;">
                    <option value="system" <?php selected($font_family, 'system'); ?>>System</option>
                    <option value="inter" <?php selected($font_family, 'inter'); ?>>Inter</option>
                    <option value="roboto" <?php selected($font_family, 'roboto'); ?>>Roboto</option>
                    <option value="poppins" <?php selected($font_family, 'poppins'); ?>>Poppins</option>
                    <option value="montserrat" <?php selected($font_family, 'montserrat'); ?>>Montserrat</option>
                </select>
            </p>
        </div>
        <?php
    }

    /**
     * Render analytics metabox
     */
    public function render_analytics_metabox($post)
    {
        $args = array(
            'post_type' => 'chat_submission',
            'meta_query' => array(
                array(
                    'key' => '_chat_form_id',
                    'value' => $post->ID
                )
            ),
            'posts_per_page' => -1,
            'post_status' => 'any'
        );
        $submissions = get_posts($args);
        $total = count($submissions);

        $thirty_days_ago = strtotime('-30 days');
        $recent = 0;
        foreach ($submissions as $sub) {
            if (strtotime($sub->post_date) >= $thirty_days_ago) {
                $recent++;
            }
        }
        ?>
        <div style="padding: 10px 0;">
            <div
                style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 15px;">
                <div style="font-size: 36px; font-weight: bold;"><?php echo $total; ?></div>
                <div style="font-size: 14px;">Total Submissions</div>
            </div>
            <div style="background: rgba(2,6,23,.55); border: 1px solid rgba(125,211,252,.18); color: #e2e8f0; padding: 15px; border-radius: 10px; margin-bottom: 10px;">
                <div style="display: flex; justify-content: space-between;">
                    <span>📅 Last 30 Days:</span>
                    <strong style="color: #667eea;"><?php echo $recent; ?></strong>
                </div>
            </div>
            <?php if ($total > 0): ?>
                <div style="background: rgba(2,6,23,.55); border: 1px solid rgba(125,211,252,.18); color: #e2e8f0; padding: 15px; border-radius: 10px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>📈 Avg per Day:</span>
                        <strong
                            style="color: #764ba2;"><?php echo number_format($total / max(1, (time() - strtotime($post->post_date)) / 86400), 1); ?></strong>
                    </div>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #999; font-size: 13px;">No submissions yet</p>
            <?php endif; ?>
        </div>
        <?php
    }


    private function render_single_question($index, $data)
    {
        $text = isset($data['text']) ? esc_attr($data['text']) : '';
        $type = isset($data['type']) ? esc_attr($data['type']) : 'text';
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : array();

        // Legacy support: if options is a string (old format), convert to array
        if (isset($data['options']) && is_string($data['options'])) {
            $legacy_opts = explode(',', $data['options']);
            $options = array();
            foreach ($legacy_opts as $opt) {
                $opt = trim($opt);
                if ($opt) {
                    $options[] = array('label' => $opt, 'value' => strtolower(str_replace(' ', '_', $opt)));
                }
            }
        }
        ?>
        <div class="chat-form-question">
            <h4>
                <span class="drag-handle" style="cursor: move;">☰</span>
                <span>Question <span class="question-number"><?php echo $index + 1; ?></span></span>
                <div class="question-actions">
                    <button type="button" class="button duplicate-question" title="Duplicate Question">📋 Duplicate</button>
                    <button type="button" class="button remove-question">Remove</button>
                </div>
            </h4>
            <p>
                <label><?php _e('Question Text:', 'chat-forms'); ?></label><br />
                <input type="text" name="chat_form_questions[<?php echo $index; ?>][text]"
                    value="<?php echo esc_attr($text); ?>" class="widefat question-text" />
            </p>
            <p>
                <label><?php _e('Section:', 'chat-forms'); ?></label>
                <?php $em_is_q = !in_array($type, array('info_block', 'prompt_response', 'support_ticket'), true); ?>
                <select class="em-cf-sec-kind">
                    <option value="question" <?php selected($em_is_q); ?>>💬 <?php _e('Question', 'chat-forms'); ?></option>
                    <option value="info_block" <?php selected($type, 'info_block'); ?>>📝 <?php _e('Content Block', 'chat-forms'); ?></option>
                    <option value="prompt_response" <?php selected($type, 'prompt_response'); ?>>🤖 <?php _e('AI Prompt', 'chat-forms'); ?></option>
                    <option value="support_ticket" <?php selected($type, 'support_ticket'); ?>>🎫 <?php _e('Support Ticket', 'chat-forms'); ?></option>
                </select>
                <select class="em-cf-sec-qtype" style="<?php echo $em_is_q ? '' : 'display:none;'; ?>">
                    <option value="text" <?php selected($type, 'text'); ?>>Text</option>
                    <option value="multiple" <?php selected($type, 'multiple'); ?>>Multiple Choice</option>
                    <option value="email" <?php selected($type, 'email'); ?>>Email</option>
                    <option value="telephone" <?php selected($type, 'telephone'); ?>>Telephone</option>
                    <option value="file" <?php selected($type, 'file'); ?>>File Upload</option>
                    <option value="account_registration" <?php selected($type, 'account_registration'); ?>>Account Registration</option>
                </select>
                <?php /* The REAL posted field — hidden, driven by the two UI
                         selects above so the save format never changes. */ ?>
                <select name="chat_form_questions[<?php echo $index; ?>][type]" class="question-type" style="display:none;">
                    <option value="text" <?php selected($type, 'text'); ?>>Text</option>
                    <option value="multiple" <?php selected($type, 'multiple'); ?>>Multiple Choice</option>
                    <option value="email" <?php selected($type, 'email'); ?>>Email</option>
                    <option value="telephone" <?php selected($type, 'telephone'); ?>>Telephone</option>
                    <option value="file" <?php selected($type, 'file'); ?>>File Upload</option>
                    <option value="account_registration" <?php selected($type, 'account_registration'); ?>>Account Registration</option>
                    <option value="info_block" <?php selected($type, 'info_block'); ?>>📝 Info Block (no input)</option>
                    <option value="prompt_response" <?php selected($type, 'prompt_response'); ?>>🤖 Prompt Response (LEO AI)</option>
                    <option value="support_ticket" <?php selected($type, 'support_ticket'); ?>>🎫 Support Ticket</option>
                </select>
            </p>

            <!-- Info Block editor (rich-text with visual + code tabs) -->
            <div class="info-block-editor" style="<?php echo $type === 'info_block' ? '' : 'display:none;'; ?>margin:10px 0;padding:12px;background:#fff8e6;border-left:4px solid #f0b429;border-radius:4px;">
                <p style="margin:0 0 6px;"><strong>📝 <?php _e('Block content:', 'chat-forms'); ?></strong></p>
                <small style="display:block;margin-bottom:8px;color:#666;"><?php _e('Shown to the user as a bot message. Visual + Text tabs let you write rich content or paste raw HTML.', 'chat-forms'); ?></small>
                <?php
                $editor_id = 'info_block_' . $index;
                $info_content = isset($data['content']) ? $data['content'] : '';
                wp_editor($info_content, $editor_id, array(
                    'textarea_name' => 'chat_form_questions[' . $index . '][content]',
                    'textarea_rows' => 6,
                    'media_buttons' => true,
                    'teeny'         => false,
                    'tinymce'       => true,
                ));
                ?>
            </div>

            <!-- Prompt Response editor -->
            <?php
            $pays            = isset($data['pays']) ? $data['pays'] : 'site';
            $pays_user_email = isset($data['pays_user_email']) ? $data['pays_user_email'] : '';
            ?>
            <div class="prompt-response-editor" style="<?php echo $type === 'prompt_response' ? '' : 'display:none;'; ?>margin:10px 0;padding:12px;background:#eef2ff;border-left:4px solid #6366f1;border-radius:4px;">
                <p style="margin:0 0 6px;"><strong>🤖 <?php _e('Prompt template:', 'chat-forms'); ?></strong></p>
                <small style="display:block;margin-bottom:8px;color:#666;"><?php _e('Sent to LEO AI when this step runs. Click a token below to insert it. Bot reply shown to the user.', 'chat-forms'); ?></small>
                <textarea name="chat_form_questions[<?php echo $index; ?>][prompt]" rows="5" class="widefat prompt-response-template" style="font-family:monospace;font-size:12px;" placeholder="You are a helpful assistant. The user said: {previous}. Reply in a friendly, concise way."><?php echo esc_textarea(isset($data['prompt']) ? $data['prompt'] : ''); ?></textarea>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="button button-small em-prompt-token" data-token="{previous}">{previous}</button>
                    <button type="button" class="button button-small em-prompt-token" data-token="{answer:1}">{answer:1}</button>
                    <button type="button" class="button button-small em-prompt-token" data-token="{question:1}">{question:1}</button>
                    <button type="button" class="button button-small em-prompt-token" data-token="{site_name}">{site_name}</button>
                    <button type="button" class="button button-small em-prompt-token" data-token="{site_url}">{site_url}</button>
                    <button type="button" class="button button-small em-prompt-token" data-token="{user_email}">{user_email}</button>
                    <button type="button" class="button button-small em-prompt-token" data-token="{user_name}">{user_name}</button>
                </div>

                <hr style="margin:14px 0 10px;border:0;border-top:1px solid #c7d2fe;" />
                <p style="margin:0 0 6px;"><strong>🖥️ <?php _e('AI Runs At:', 'chat-forms'); ?></strong></p>
                <?php
                $run_target      = isset($data['run_target']) && $data['run_target'] !== '' ? (string) $data['run_target'] : 'gendme';
                $run_integration = isset($data['run_integration']) ? (string) $data['run_integration'] : '';
                $run_model       = isset($data['run_model']) ? (string) $data['run_model'] : '';
                ?>
                <select name="chat_form_questions[<?php echo $index; ?>][run_target]" class="widefat prompt-run-target"
                        data-saved="<?php echo esc_attr($run_target); ?>">
                    <option value="gendme"><?php _e('⛓️ gend.me Compute Network (blockchain)', 'chat-forms'); ?></option>
                </select>
                <div class="prompt-run-device" style="display:none;margin-top:8px;">
                    <select name="chat_form_questions[<?php echo $index; ?>][run_integration]" class="widefat prompt-run-integration"
                            data-saved="<?php echo esc_attr($run_integration); ?>"></select>
                    <select name="chat_form_questions[<?php echo $index; ?>][run_model]" class="widefat prompt-run-model" style="margin-top:6px;"
                            data-saved="<?php echo esc_attr($run_model); ?>"></select>
                    <small style="display:block;margin-top:6px;color:#666;"><?php _e('Runs on that device with its own AI (member hardware / personal license — no gend.me metering). Falls back to the Compute Network whenever the device is offline.', 'chat-forms'); ?></small>
                </div>
                <?php $ai_model = isset($data['ai_model']) ? (string) $data['ai_model'] : ''; ?>
                <div class="prompt-run-gendme-model" style="margin-top:8px;">
                    <select name="chat_form_questions[<?php echo $index; ?>][ai_model]" class="widefat prompt-run-ai-model" data-saved="<?php echo esc_attr($ai_model); ?>">
                        <?php echo em_cf_ai_model_options($ai_model); ?>
                    </select>
                    <small style="display:block;margin-top:6px;color:#666;"><?php _e('Which real model on the gend.me Compute Network answers this prompt, from the AI Models leaderboard.', 'chat-forms'); ?></small>
                </div>

                <hr style="margin:14px 0 10px;border:0;border-top:1px solid #c7d2fe;" />
                <p style="margin:0 0 6px;"><strong>💳 <?php _e('Who pays for this AI response?', 'chat-forms'); ?></strong></p>
                <select name="chat_form_questions[<?php echo $index; ?>][pays]" class="widefat prompt-response-pays">
                    <option value="site"      <?php selected($pays, 'site'); ?>><?php _e('Site default (configured site token)', 'chat-forms'); ?></option>
                    <option value="admin"     <?php selected($pays, 'admin'); ?>><?php _e('My balance (the admin building this)', 'chat-forms'); ?></option>
                    <option value="chat_user" <?php selected($pays, 'chat_user'); ?>><?php _e('Chat user (their own LEO balance)', 'chat-forms'); ?></option>
                    <option value="member"    <?php selected($pays, 'member'); ?>><?php _e('Specific app member', 'chat-forms'); ?></option>
                </select>
                <div class="prompt-response-pays-member" style="<?php echo $pays === 'member' ? '' : 'display:none;'; ?>margin-top:8px;">
                    <small style="display:block;margin-bottom:4px;color:#666;"><?php _e('Member email — resolved to that WP user on save. Their LEO token is used to pay for every run of this prompt.', 'chat-forms'); ?></small>
                    <input type="email" name="chat_form_questions[<?php echo $index; ?>][pays_user_email]" value="<?php echo esc_attr($pays_user_email); ?>" class="widefat" placeholder="member@example.com" />
                </div>
                <div class="prompt-response-pays-admin" style="<?php echo $pays === 'admin' ? '' : 'display:none;'; ?>margin-top:8px;padding:10px;background:#fff;border:1px solid #c7d2fe;border-radius:6px;">
                    <span class="em-leo-admin-status" style="font-size:12px;color:#666;">Checking your LEO connection…</span>
                </div>
                <div class="prompt-response-pays-chat-user" style="<?php echo $pays === 'chat_user' ? '' : 'display:none;'; ?>margin-top:8px;font-size:11px;color:#475569;">
                    <?php _e('A balance bar will appear at the top of the chat asking the user to log in with their LEO account before this prompt runs.', 'chat-forms'); ?>
                </div>
                <p style="margin-top:8px;font-size:11px;color:#666;">
                    <?php
                    if (class_exists('EM_Leo') && EM_Leo::is_enabled()) {
                        echo '✅ ' . esc_html__('LEO AI is configured. If the chosen payer has no personal token, the site default is used.', 'chat-forms');
                    } else {
                        echo '⚠️ ' . esc_html__('LEO AI is not configured. Configure it in the Email Manager settings.', 'chat-forms');
                    }
                    ?>
                </p>
            </div>

            <!-- Support Ticket editor -->
            <?php $st_priority = isset($data['priority']) ? $data['priority'] : 'normal'; ?>
            <div class="support-ticket-editor" style="<?php echo $type === 'support_ticket' ? '' : 'display:none;'; ?>margin:10px 0;padding:12px;border-radius:12px;">
                <p style="margin:0 0 6px;"><strong>🎫 <?php _e('Automatic Support Ticket', 'chat-forms'); ?></strong></p>
                <small style="display:block;margin-bottom:10px;"><?php _e('Completing this flow files the submission as a SUPPORT TICKET in Email Manager → Support — with the full chat transcript and every form field attached. Saving the form marks it as a support-intake form so its runs appear on that tab.', 'chat-forms'); ?></small>
                <label><?php _e('Default priority:', 'chat-forms'); ?></label>
                <select name="chat_form_questions[<?php echo $index; ?>][priority]" class="widefat">
                    <option value="low" <?php selected($st_priority, 'low'); ?>><?php _e('Low', 'chat-forms'); ?></option>
                    <option value="normal" <?php selected($st_priority, 'normal'); ?>><?php _e('Normal', 'chat-forms'); ?></option>
                    <option value="high" <?php selected($st_priority, 'high'); ?>><?php _e('High', 'chat-forms'); ?></option>
                </select>
            </div>

            <!-- Validation Settings -->
            <div class="validation-settings" style="background: #f0f0f1; padding: 10px; margin: 10px 0; border-radius: 4px;">
                <p style="margin: 0 0 10px 0; font-weight: 600;">⚙️ Validation Settings</p>
                <label style="display: block; margin-bottom: 8px;">
                    <input type="checkbox" name="chat_form_questions[<?php echo $index; ?>][validation][required]" value="1"
                        <?php echo isset($data['validation']['required']) && $data['validation']['required'] ? 'checked' : ''; ?> />
                    <?php _e('Required field', 'chat-forms'); ?>
                </label>
                <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                    <label style="flex: 1;">
                        <?php _e('Min length:', 'chat-forms'); ?>
                        <input type="number" name="chat_form_questions[<?php echo $index; ?>][validation][min_length]"
                            value="<?php echo isset($data['validation']['min_length']) ? esc_attr($data['validation']['min_length']) : ''; ?>"
                            placeholder="0" style="width: 100%;" />
                    </label>
                    <label style="flex: 1;">
                        <?php _e('Max length:', 'chat-forms'); ?>
                        <input type="number" name="chat_form_questions[<?php echo $index; ?>][validation][max_length]"
                            value="<?php echo isset($data['validation']['max_length']) ? esc_attr($data['validation']['max_length']) : ''; ?>"
                            placeholder="unlimited" style="width: 100%;" />
                    </label>
                </div>
                <label style="display: block;">
                    <?php _e('Custom error message:', 'chat-forms'); ?>
                    <input type="text" name="chat_form_questions[<?php echo $index; ?>][validation][error_message]"
                        value="<?php echo isset($data['validation']['error_message']) ? esc_attr($data['validation']['error_message']) : ''; ?>"
                        placeholder="Please provide a valid answer" style="width: 100%;" />
                </label>
            </div>
            <div class="options-manager"
                style="<?php echo (in_array($type, array('multiple', 'select'))) ? '' : 'display:none;'; ?>">
                <label>Options:</label>
                <div class="options-container">
                    <?php
                    if (!empty($options)) {
                        foreach ($options as $opt_index => $option) {
                            $opt_label = isset($option['label']) ? esc_attr($option['label']) : '';
                            $opt_value = isset($option['value']) ? esc_attr($option['value']) : '';
                            $opt_image = isset($option['image']) ? esc_attr($option['image']) : '';
                            ?>
                            <div class="option-item" style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px;margin-bottom:8px;">
                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <input type="text"
                                        name="chat_form_questions[<?php echo $index; ?>][options][<?php echo $opt_index; ?>][label]"
                                        value="<?php echo $opt_label; ?>" placeholder="Label (e.g., Red)" class="option-label" />
                                    <input type="text"
                                        name="chat_form_questions[<?php echo $index; ?>][options][<?php echo $opt_index; ?>][value]"
                                        value="<?php echo $opt_value; ?>" placeholder="Value (e.g., red)" class="option-value" />
                                    <input type="hidden"
                                        name="chat_form_questions[<?php echo $index; ?>][options][<?php echo $opt_index; ?>][image]"
                                        value="<?php echo $opt_image; ?>" class="option-image-url" />
                                    <button type="button"
                                        class="button upload-image-btn"><?php echo $opt_image ? '🖼️ Change' : '📷 Add Image'; ?></button>
                                    <?php if ($opt_image): ?>
                                        <img src="<?php echo $opt_image; ?>" class="option-image-preview"
                                            style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin-left:5px;" />
                                    <?php endif; ?>
                                    <button type="button" class="button option-toggle-response" title="Configure branching response">💬 Response</button>
                                    <button type="button" class="remove-option">Remove</button>
                                </div>
                                <?php $opt_response = isset($option['response_html']) ? $option['response_html'] : ''; ?>
                                <div class="option-response-wrap" style="<?php echo $opt_response ? '' : 'display:none;'; ?>margin-top:8px;padding:10px;background:#f0f5ff;border-radius:4px;">
                                    <small style="display:block;margin-bottom:4px;color:#666;">📨 <?php _e('Reply shown when this option is picked (HTML allowed):', 'chat-forms'); ?></small>
                                    <textarea
                                        name="chat_form_questions[<?php echo $index; ?>][options][<?php echo $opt_index; ?>][response_html]"
                                        rows="3" class="widefat option-response-html"
                                        placeholder="Great choice! Here's what happens next…"><?php echo esc_textarea($opt_response); ?></textarea>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
                <button type="button" class="button add-option-btn">+ Add Option</button>
            </div>
            <div class="conditional-logic-wrapper" style="margin-top: 15px;">
                <label>
                    <input type="checkbox" name="chat_form_questions[<?php echo $index; ?>][conditional][enabled]" value="1"
                        class="conditional-toggle" <?php echo isset($data['conditional']['enabled']) && $data['conditional']['enabled'] ? 'checked' : ''; ?> />
                    <?php _e('Enable Conditional Logic', 'chat-forms'); ?>
                </label>
                <div class="conditional-rules"
                    style="<?php echo (isset($data['conditional']['enabled']) && $data['conditional']['enabled']) ? '' : 'display:none;'; ?> margin-top:10px; padding:10px; background:#fafafa; border-radius:4px;">
                    <p><small><?php _e('Show this question only if:', 'chat-forms'); ?></small></p>
                    <select name="chat_form_questions[<?php echo $index; ?>][conditional][logic]" style="margin-bottom:10px;">
                        <option value="all" <?php echo isset($data['conditional']['logic']) && $data['conditional']['logic'] === 'all' ? 'selected' : ''; ?>>
                            <?php _e('All conditions match', 'chat-forms'); ?>
                        </option>
                        <option value="any" <?php echo isset($data['conditional']['logic']) && $data['conditional']['logic'] === 'any' ? 'selected' : ''; ?>>
                            <?php _e('Any condition matches', 'chat-forms'); ?>
                        </option>
                    </select>
                    <div class="conditional-rules-list">
                        <?php
                        if (isset($data['conditional']['rules']) && is_array($data['conditional']['rules'])) {
                            foreach ($data['conditional']['rules'] as $rule_idx => $rule) {
                                ?>
                                <div class="conditional-rule-item"
                                    style="display:flex; gap:5px; margin-bottom:5px; align-items:center;">
                                    <select
                                        name="chat_form_questions[<?php echo $index; ?>][conditional][rules][<?php echo $rule_idx; ?>][question]"
                                        style="flex:1;">
                                        <option value=""><?php _e('Select question...', 'chat-forms'); ?></option>
                                        <?php for ($i = 0; $i < $index; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo isset($rule['question']) && $rule['question'] == $i ? 'selected' : ''; ?>><?php echo 'Question ' . ($i + 1); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <select
                                        name="chat_form_questions[<?php echo $index; ?>][conditional][rules][<?php echo $rule_idx; ?>][operator]"
                                        style="flex:1;">
                                        <option value="equals" <?php echo isset($rule['operator']) && $rule['operator'] === 'equals' ? 'selected' : ''; ?>><?php _e('equals', 'chat-forms'); ?></option>
                                        <option value="not_equals" <?php echo isset($rule['operator']) && $rule['operator'] === 'not_equals' ? 'selected' : ''; ?>>
                                            <?php _e('not equals', 'chat-forms'); ?>
                                        </option>
                                        <option value="contains" <?php echo isset($rule['operator']) && $rule['operator'] === 'contains' ? 'selected' : ''; ?>><?php _e('contains', 'chat-forms'); ?></option>
                                    </select>
                                    <input type="text"
                                        name="chat_form_questions[<?php echo $index; ?>][conditional][rules][<?php echo $rule_idx; ?>][value]"
                                        value="<?php echo isset($rule['value']) ? esc_attr($rule['value']) : ''; ?>" placeholder="Value"
                                        style="flex:1;" />
                                    <button type="button" class="button remove-rule">×</button>
                                </div>
                                <?php
                            }
                        }
                        ?>
                    </div>
                    <button type="button" class="button add-rule-btn"
                        style="margin-top:5px;"><?php _e('+ Add Rule', 'chat-forms'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_questions($post_id)
    {
        // Add check for post type since this hook fires for all posts
        if (isset($_POST['post_type']) && !in_array($_POST['post_type'], array('chat_form', 'basic_form'))) {
            return;
        }

        if (!isset($_POST['chat_forms_questions_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['chat_forms_questions_nonce'], 'chat_forms_save_questions')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // DEBUG: Log what we're receiving
        $log_file = WP_CONTENT_DIR . '/debug_chat_forms.txt';
        $log_data = date('Y-m-d H:i:s') . " - Save Questions POST:\n" . print_r($_POST, true) . "\n----------------\n";
        file_put_contents($log_file, $log_data, FILE_APPEND);


        if (isset($_POST['chat_form_questions']) && is_array($_POST['chat_form_questions'])) {
            $questions = array();

            foreach ($_POST['chat_form_questions'] as $question_data) {
                $sanitized_question = array(
                    'text' => isset($question_data['text']) ? sanitize_text_field($question_data['text']) : '',
                    'type' => isset($question_data['type']) ? sanitize_text_field($question_data['type']) : 'text',
                );

                // Handle options for multiple choice/select questions
                if (isset($question_data['options']) && is_array($question_data['options'])) {
                    $sanitized_question['options'] = array();
                    foreach ($question_data['options'] as $option_data) {
                        if (is_array($option_data)) {
                            $sanitized_question['options'][] = array(
                                'label'         => isset($option_data['label']) ? sanitize_text_field($option_data['label']) : '',
                                'value'         => isset($option_data['value']) ? sanitize_text_field($option_data['value']) : '',
                                'image'         => isset($option_data['image']) ? esc_url_raw($option_data['image']) : '',
                                'response_html' => isset($option_data['response_html']) ? wp_kses_post(wp_unslash($option_data['response_html'])) : '',
                            );
                        }
                    }
                }

                // Info Block content (rich HTML from wp_editor)
                if (isset($question_data['content'])) {
                    $sanitized_question['content'] = wp_kses_post(wp_unslash($question_data['content']));
                }

                // Prompt Response template
                if (isset($question_data['prompt'])) {
                    $sanitized_question['prompt'] = sanitize_textarea_field(wp_unslash($question_data['prompt']));
                }

                // Support Ticket — default priority.
                if (isset($question_data['priority'])) {
                    $em_pr = sanitize_key(wp_unslash($question_data['priority']));
                    $sanitized_question['priority'] = in_array($em_pr, array('low', 'normal', 'high'), true) ? $em_pr : 'normal';
                }

                // Prompt Response — run target (connected device vs the
                // gend.me Compute Network).
                if (isset($question_data['run_target'])) {
                    $rt = sanitize_text_field(wp_unslash($question_data['run_target']));
                    $sanitized_question['run_target']      = $rt === '' ? 'gendme' : $rt;
                    $sanitized_question['run_integration'] = isset($question_data['run_integration']) ? sanitize_text_field(wp_unslash($question_data['run_integration'])) : '';
                    $sanitized_question['run_model']       = isset($question_data['run_model']) ? sanitize_text_field(wp_unslash($question_data['run_model'])) : '';
                }

                // Prompt Response — which real AI Models leaderboard pick serves this prompt on the gend.me
                // Compute Network (moved here from the Sequences admin page's per-task Model field for
                // Chatflow-kind tasks, since a chat form's own questions are the more natural place to
                // configure this per-question). Never stored on trust -- must still resolve to a real pick.
                if (isset($question_data['ai_model'])) {
                    $am = sanitize_text_field(wp_unslash($question_data['ai_model']));
                    $sanitized_question['ai_model'] = em_cf_is_valid_ai_model_pick($am) ? $am : '';
                }

                // Prompt Response — token payer config
                if (isset($question_data['pays'])) {
                    $pays_raw = sanitize_text_field(wp_unslash($question_data['pays']));
                    $sanitized_question['pays'] = in_array($pays_raw, array('site', 'admin', 'chat_user', 'member'), true) ? $pays_raw : 'site';

                    if ($sanitized_question['pays'] === 'member') {
                        $email = isset($question_data['pays_user_email']) ? sanitize_email(wp_unslash($question_data['pays_user_email'])) : '';
                        $sanitized_question['pays_user_email'] = $email;
                        $sanitized_question['pays_user_id'] = 0;
                        if ($email) {
                            $u = get_user_by('email', $email);
                            if ($u) {
                                $sanitized_question['pays_user_id'] = $u->ID;
                            }
                        }
                    } else {
                        $sanitized_question['pays_user_email'] = '';
                        $sanitized_question['pays_user_id']    = 0;
                    }
                }

                // Handle validation rules
                if (isset($question_data['validation']) && is_array($question_data['validation'])) {
                    $sanitized_question['validation'] = array(
                        'required' => isset($question_data['validation']['required']) ? (bool) $question_data['validation']['required'] : false,
                        'min_length' => isset($question_data['validation']['min_length']) ? absint($question_data['validation']['min_length']) : 0,
                        'max_length' => isset($question_data['validation']['max_length']) ? absint($question_data['validation']['max_length']) : 0,
                        'error_message' => isset($question_data['validation']['error_message']) ? sanitize_text_field($question_data['validation']['error_message']) : '',
                    );
                }

                // Handle conditional logic
                if (isset($question_data['conditional']) && is_array($question_data['conditional'])) {
                    $sanitized_question['conditional'] = array(
                        'enabled' => isset($question_data['conditional']['enabled']) ? 1 : 0,
                        'logic' => isset($question_data['conditional']['logic']) ? sanitize_text_field($question_data['conditional']['logic']) : 'all',
                        'rules' => array()
                    );

                    if (isset($question_data['conditional']['rules']) && is_array($question_data['conditional']['rules'])) {
                        foreach ($question_data['conditional']['rules'] as $rule) {
                            $sanitized_question['conditional']['rules'][] = array(
                                'question' => isset($rule['question']) ? sanitize_text_field($rule['question']) : '',
                                'operator' => isset($rule['operator']) ? sanitize_text_field($rule['operator']) : 'equals',
                                'value' => isset($rule['value']) ? sanitize_text_field($rule['value']) : '',
                            );
                        }
                    }
                }



                $questions[] = $sanitized_question;
            }

            // DEBUG: Log final questions array before saving
            $log_data = date('Y-m-d H:i:s') . " - FINAL Sanitized Questions to Save:\n" . print_r($questions, true) . "\n----------------\n";
            file_put_contents($log_file, $log_data, FILE_APPEND);

            update_post_meta($post_id, '_chat_form_questions', $questions);

            // A Support-Ticket section makes this a support-intake form —
            // its submissions then list on Email Manager → Support. Removing
            // the section clears the purpose again (only when WE set it).
            if (class_exists('EM_Applications') && class_exists('EM_Support')) {
                $em_cf_has_support = false;
                foreach ($questions as $em_cf_q) {
                    if (is_array($em_cf_q) && ($em_cf_q['type'] ?? '') === 'support_ticket') { $em_cf_has_support = true; break; }
                }
                $em_cf_cur = get_post_meta($post_id, EM_Applications::FORM_PURPOSE_META, true);
                if ($em_cf_has_support) {
                    update_post_meta($post_id, EM_Applications::FORM_PURPOSE_META, EM_Support::PURPOSE_VALUE);
                } elseif ($em_cf_cur === EM_Support::PURPOSE_VALUE) {
                    delete_post_meta($post_id, EM_Applications::FORM_PURPOSE_META);
                }
            }
        } else {
            // DEBUG: Log deletion
            $log_data = date('Y-m-d H:i:s') . " - Deleting all questions meta (POST data missing or invalid)\n----------------\n";
            file_put_contents($log_file, $log_data, FILE_APPEND);

            delete_post_meta($post_id, '_chat_form_questions');
        }

        // Save Email Settings & Success Message
        if (isset($_POST['chat_form_thank_you_message'])) {
            // Use wp_kses_post to allow HTML from wp_editor
            update_post_meta($post_id, '_chat_form_thank_you_message', wp_kses_post($_POST['chat_form_thank_you_message']));
        }
        if (isset($_POST['chat_form_redirect_url'])) {
            update_post_meta($post_id, '_chat_form_redirect_url', esc_url_raw($_POST['chat_form_redirect_url']));
        }
        if (isset($_POST['chat_form_launch_code'])) {
            // Tolerate either the bare code or the full [gend_launch code="…"]
            // shortcode being pasted — extract just the code either way.
            $raw = sanitize_text_field(wp_unslash($_POST['chat_form_launch_code']));
            if (preg_match('/code=["\']([^"\']+)["\']/', $raw, $m)) {
                $raw = $m[1];
            }
            update_post_meta($post_id, '_chat_form_launch_code', trim($raw));
        }

        // Save Email Rules (New System)
        if (isset($_POST['chat_form_email_rules'])) {
            $rules_json = stripslashes($_POST['chat_form_email_rules']);
            $rules = json_decode($rules_json, true);
            
            if (is_array($rules)) {
                $sanitized_rules = array();
                foreach ($rules as $rule) {
                    $sanitized_rules[] = array(
                        'id' => isset($rule['id']) ? sanitize_text_field($rule['id']) : uniqid(),
                        'name' => isset($rule['name']) ? sanitize_text_field($rule['name']) : '',
                        'to' => isset($rule['to']) ? sanitize_text_field($rule['to']) : '', // Can be comma separated
                        'cc' => isset($rule['cc']) ? sanitize_text_field($rule['cc']) : '',
                        'bcc' => isset($rule['bcc']) ? sanitize_text_field($rule['bcc']) : '',
                        'subject' => isset($rule['subject']) ? sanitize_text_field($rule['subject']) : '',
                        'body' => isset($rule['body']) ? wp_kses_post($rule['body']) : '', // HTML allowed
                    );
                }
                update_post_meta($post_id, '_chat_form_email_rules', $sanitized_rules);
            }
        }

        /* 
         * Legacy Email Settings - Kept for backward compatibility or fallback if needed.
         * We might eventually migrate these into the first rule of the new system.
         */
        if (isset($_POST['chat_form_notification_email'])) {
            update_post_meta($post_id, '_chat_form_notification_email', sanitize_text_field($_POST['chat_form_notification_email']));
        }

        // Custom styling settings
        if (isset($_POST['chat_form_gradient_start'])) {
            update_post_meta($post_id, '_chat_form_gradient_start', sanitize_hex_color($_POST['chat_form_gradient_start']));
        }
        if (isset($_POST['chat_form_gradient_end'])) {
            update_post_meta($post_id, '_chat_form_gradient_end', sanitize_hex_color($_POST['chat_form_gradient_end']));
        }
        if (isset($_POST['chat_form_button_style'])) {
            update_post_meta($post_id, '_chat_form_button_style', sanitize_text_field($_POST['chat_form_button_style']));
        }
        if (isset($_POST['chat_form_font_family'])) {
            update_post_meta($post_id, '_chat_form_font_family', sanitize_text_field($_POST['chat_form_font_family']));
        }
        // New meta for custom HTML
        if (isset($_POST['chat_form_custom_html'])) {
            // We do NOT use wp_kses_post here because users are expected to put raw <form>, <input>, and <script> tags.
            // Since this metabox is only available to users who can edit forms (usually admins), it is safe.
            update_post_meta($post_id, '_chat_form_custom_html', wp_unslash($_POST['chat_form_custom_html']));
        }

        // Save reCAPTCHA toggle
        $recaptcha_enabled = isset($_POST['chat_form_enable_recaptcha']) ? '1' : '0';
        update_post_meta($post_id, '_chat_form_enable_recaptcha', $recaptcha_enabled);

        // Display mode + widget config
        if (isset($_POST['chat_form_display_mode'])) {
            $mode = in_array($_POST['chat_form_display_mode'], array('shortcode', 'widget', 'both'), true)
                ? $_POST['chat_form_display_mode'] : 'shortcode';
            update_post_meta($post_id, '_chat_form_display_mode', $mode);
        }
        if (isset($_POST['chat_form_widget_config']) && is_array($_POST['chat_form_widget_config'])) {
            $cfg = $_POST['chat_form_widget_config'];
            $valid_positions = array('bottom-right', 'bottom-left', 'top-right', 'top-left');
            $position = isset($cfg['position']) && in_array($cfg['position'], $valid_positions, true) ? $cfg['position'] : 'bottom-right';
            update_post_meta($post_id, '_chat_form_widget_config', array(
                'image_url'     => isset($cfg['image_url']) ? esc_url_raw($cfg['image_url']) : '',
                'border_color'  => isset($cfg['border_color']) ? sanitize_text_field($cfg['border_color']) : '#6366f1',
                'border_radius' => isset($cfg['border_radius']) ? max(0, min(100, absint($cfg['border_radius']))) : 16,
                'position'      => $position,
            ));
        }
        if (isset($_POST['chat_form_widget_pages_frontend'])) {
            $vals = array_map('sanitize_text_field', (array) $_POST['chat_form_widget_pages_frontend']);
            update_post_meta($post_id, '_chat_form_widget_pages_frontend', array_values(array_unique($vals)));
        } else {
            delete_post_meta($post_id, '_chat_form_widget_pages_frontend');
        }
        if (isset($_POST['chat_form_widget_pages_admin'])) {
            $vals = array_map('sanitize_text_field', (array) $_POST['chat_form_widget_pages_admin']);
            update_post_meta($post_id, '_chat_form_widget_pages_admin', array_values(array_unique($vals)));
        } else {
            delete_post_meta($post_id, '_chat_form_widget_pages_admin');
        }
        if (isset($_POST['chat_form_bot_avatar'])) {
            update_post_meta($post_id, '_chat_form_bot_avatar', esc_url_raw(wp_unslash($_POST['chat_form_bot_avatar'])));
        }
        if (isset($_POST['chat_form_bot_agent'])) {
            // Our metabox posted — the checkbox is absent when unchecked.
            update_post_meta($post_id, '_chat_form_agent_messages', isset($_POST['chat_form_agent_messages']) ? '1' : '');
        }
        if (isset($_POST['chat_form_bot_agent'])) {
            $cf_agent_id = (int) $_POST['chat_form_bot_agent'];
            if ($cf_agent_id > 0 && get_userdata($cf_agent_id)) {
                update_post_meta($post_id, '_chat_form_bot_agent_id', $cf_agent_id);
                // The agent's avatar IS the bot avatar — same meta the whole
                // front-end chat pipeline already reads.
                update_post_meta($post_id, '_chat_form_bot_avatar', esc_url_raw(get_avatar_url($cf_agent_id, array('size' => 96))));
            } else {
                // "— pick…" chosen: unlink the agent but keep any existing
                // avatar so older flows keep rendering unchanged.
                delete_post_meta($post_id, '_chat_form_bot_agent_id');
            }
        }
    }

    public function render_security_metabox($post)
    {
        $enable_recaptcha = get_post_meta($post->ID, '_chat_form_enable_recaptcha', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="chat_form_enable_recaptcha" value="1" <?php checked($enable_recaptcha, '1'); ?> />
                <strong><?php _e('Enable Google reCAPTCHA v3', 'chat-forms'); ?></strong>
            </label>
        </p>
        <p class="description">
            <?php _e('Protects your form from spam submissions. Requires Site and Secret keys to be configured in Email Manager settings.', 'chat-forms'); ?>
        </p>
        <?php
    }

    public function render_usage_metabox($post)
    {
        $display_mode    = get_post_meta($post->ID, '_chat_form_display_mode', true) ?: 'shortcode';
        $widget_config   = get_post_meta($post->ID, '_chat_form_widget_config', true);
        if (!is_array($widget_config)) $widget_config = array();
        $widget_config = wp_parse_args($widget_config, array(
            'image_url'     => '',
            'border_color'  => '#6366f1',
            'border_radius' => 16,
            'position'      => 'bottom-right',
        ));
        $pages_frontend = get_post_meta($post->ID, '_chat_form_widget_pages_frontend', true);
        if (!is_array($pages_frontend)) $pages_frontend = array();
        $pages_admin = get_post_meta($post->ID, '_chat_form_widget_pages_admin', true);
        if (!is_array($pages_admin)) $pages_admin = array();
        $bot_avatar = get_post_meta($post->ID, '_chat_form_bot_avatar', true);
        ?>
        <div class="chat-forms-display-config">
            <p style="margin-top:0;"><strong><?php _e('Display Mode:', 'chat-forms'); ?></strong></p>
            <select name="chat_form_display_mode" id="chat-form-display-mode" class="widefat">
                <option value="shortcode" <?php selected($display_mode, 'shortcode'); ?>><?php _e('Shortcode only', 'chat-forms'); ?></option>
                <option value="widget"    <?php selected($display_mode, 'widget'); ?>><?php _e('Chat widget only', 'chat-forms'); ?></option>
                <option value="both"      <?php selected($display_mode, 'both'); ?>><?php _e('Both shortcode + widget', 'chat-forms'); ?></option>
            </select>
            <p class="description"><?php _e('Choose how this chatflow is exposed to users.', 'chat-forms'); ?></p>

            <div class="chat-form-widget-config" style="<?php echo $display_mode === 'shortcode' ? 'display:none;' : ''; ?>margin-top:14px;border-top:1px solid #eee;padding-top:14px;">
                <p style="margin:0 0 6px;"><strong><?php _e('Widget Image:', 'chat-forms'); ?></strong></p>
                <input type="hidden" name="chat_form_widget_config[image_url]" id="chat-form-widget-image-url" value="<?php echo esc_attr($widget_config['image_url']); ?>" />
                <button type="button" class="button chat-form-pick-image" data-target="#chat-form-widget-image-url"><?php echo $widget_config['image_url'] ? '🖼️ Change' : '📷 Upload'; ?></button>
                <?php if ($widget_config['image_url']): ?>
                    <img src="<?php echo esc_url($widget_config['image_url']); ?>" style="display:block;width:60px;height:60px;object-fit:cover;border-radius:50%;margin-top:8px;" />
                <?php endif; ?>
                <p class="description" style="margin-top:4px;"><?php _e('Used as the floating widget icon.', 'chat-forms'); ?></p>

                <p style="margin:14px 0 6px;"><strong><?php _e('Border Color:', 'chat-forms'); ?></strong></p>
                <input type="text" name="chat_form_widget_config[border_color]" value="<?php echo esc_attr($widget_config['border_color']); ?>" class="widefat" placeholder="#6366f1" />

                <p style="margin:14px 0 6px;"><strong><?php _e('Roundness (px):', 'chat-forms'); ?></strong></p>
                <input type="number" min="0" max="100" name="chat_form_widget_config[border_radius]" value="<?php echo esc_attr($widget_config['border_radius']); ?>" class="widefat" />

                <p style="margin:14px 0 6px;"><strong><?php _e('Screen Position:', 'chat-forms'); ?></strong></p>
                <select name="chat_form_widget_config[position]" class="widefat">
                    <option value="bottom-right" <?php selected($widget_config['position'], 'bottom-right'); ?>><?php _e('Bottom right', 'chat-forms'); ?></option>
                    <option value="bottom-left"  <?php selected($widget_config['position'], 'bottom-left'); ?>><?php _e('Bottom left', 'chat-forms'); ?></option>
                    <option value="top-right"    <?php selected($widget_config['position'], 'top-right'); ?>><?php _e('Top right', 'chat-forms'); ?></option>
                    <option value="top-left"     <?php selected($widget_config['position'], 'top-left'); ?>><?php _e('Top left', 'chat-forms'); ?></option>
                </select>

                <hr style="margin:18px 0;border:0;border-top:1px solid #eee;" />

                <p style="margin:0 0 6px;"><strong><?php _e('Show on Frontend Pages:', 'chat-forms'); ?></strong></p>
                <select name="chat_form_widget_pages_frontend[]" multiple class="widefat" style="height:100px;">
                    <?php
                    $frontend_options = array(
                        'all'      => __('Every page', 'chat-forms'),
                        'home'     => __('Home page', 'chat-forms'),
                        'archive'  => __('Archives & blog', 'chat-forms'),
                        'singular' => __('Single posts/pages', 'chat-forms'),
                    );
                    foreach ($frontend_options as $val => $label):
                        $sel = in_array($val, $pages_frontend, true) ? 'selected' : '';
                    ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php echo $sel; ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach;
                    // List pages by ID
                    $pages = get_pages(array('number' => 100));
                    foreach ($pages as $page):
                        $sel = in_array((string) $page->ID, array_map('strval', $pages_frontend), true) ? 'selected' : '';
                    ?>
                        <option value="<?php echo esc_attr($page->ID); ?>" <?php echo $sel; ?>><?php echo esc_html('— ' . $page->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Hold Ctrl/Cmd to select multiple. "Every page" overrides everything else.', 'chat-forms'); ?></p>

                <p style="margin:14px 0 6px;"><strong><?php _e('Show on Admin Pages:', 'chat-forms'); ?></strong></p>
                <select name="chat_form_widget_pages_admin[]" multiple class="widefat" style="height:80px;">
                    <?php
                    $admin_options = array(
                        'all'       => __('Every admin page', 'chat-forms'),
                        'dashboard' => __('Dashboard only', 'chat-forms'),
                        'plugins'   => __('Plugins screen', 'chat-forms'),
                        'users'     => __('Users screen', 'chat-forms'),
                        'tools'     => __('Tools screen', 'chat-forms'),
                    );
                    foreach ($admin_options as $val => $label):
                        $sel = in_array($val, $pages_admin, true) ? 'selected' : '';
                    ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php echo $sel; ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <hr style="margin:14px 0;border:0;border-top:1px solid #eee;" />

            <p style="margin:0 0 6px;"><strong><?php _e('Bot Agent (chat bubbles):', 'chat-forms'); ?></strong></p>
            <?php
            // The chatflow speaks AS one of the connected web app's agents —
            // pick the agent instead of uploading a raw avatar image. The
            // selection also writes _chat_form_bot_avatar (the agent's
            // avatar URL) so the whole front-end pipeline stays untouched.
            $cf_bot_agent_id = (int) get_post_meta($post->ID, '_chat_form_bot_agent_id', true);
            $cf_bot_agent    = $cf_bot_agent_id ? get_userdata($cf_bot_agent_id) : null;
            ?>
            <div class="em-cf-agentpick" id="em-cf-agentpick"
                 data-nonce="<?php echo esc_attr(wp_create_nonce('em_cf_agent_search')); ?>">
                <input type="hidden" name="chat_form_bot_agent" id="chat-form-bot-agent"
                       value="<?php echo esc_attr($cf_bot_agent_id ?: ''); ?>" />
                <div class="em-cf-agentpick-selected" data-ap-selected <?php echo $cf_bot_agent ? '' : 'hidden'; ?>>
                    <img data-ap-sel-avatar src="<?php echo esc_url($cf_bot_agent ? get_avatar_url($cf_bot_agent->ID, array('size' => 96)) : ''); ?>" alt="" />
                    <strong data-ap-sel-name><?php echo esc_html($cf_bot_agent ? $cf_bot_agent->display_name : ''); ?></strong>
                    <button type="button" class="em-cf-agentpick-x" data-ap-clear aria-label="<?php esc_attr_e('Unlink agent', 'chat-forms'); ?>">&times;</button>
                </div>
                <div class="em-cf-agentpick-searchwrap" data-ap-searchwrap <?php echo $cf_bot_agent ? 'hidden' : ''; ?>>
                    <input type="search" class="em-cf-agentpick-search" data-ap-search autocomplete="off"
                           placeholder="<?php esc_attr_e('Search connected agents…', 'chat-forms'); ?>" />
                    <div class="em-cf-agentpick-results" data-ap-results hidden></div>
                </div>
            </div>
            <p class="description"><?php _e('The web app agent this chatflow speaks as — their avatar shows next to every bot message. User responses use their WP profile photo automatically.', 'chat-forms'); ?></p>

            <?php if (function_exists('messages_new_message') || (function_exists('bp_is_active') && bp_is_active('messages'))) : ?>
                <hr style="margin:14px 0;border:0;border-top:1px solid #eee;" />
                <p style="margin:0 0 6px;"><strong><?php _e('Run through Agent Messages:', 'chat-forms'); ?></strong></p>
                <label style="display:flex;gap:8px;align-items:flex-start;">
                    <input type="checkbox" name="chat_form_agent_messages" value="1" style="margin-top:3px;"
                        <?php checked('1', get_post_meta($post->ID, '_chat_form_agent_messages', true)); ?> />
                    <span><?php _e('Deliver this chatflow as profile messages from the Bot Agent', 'chat-forms'); ?></span>
                </label>
                <p class="description"><?php _e('When triggered, each question arrives as a private message from the agent above; the member replies in Messages (or the bottom-right chat widget) and every reply advances the flow. Completion records a submission and fires the same notifications, launch triggers and list joins as the chat UI. Requires a Bot Agent.', 'chat-forms'); ?></p>
            <?php endif; ?>
            <style>
                @property --emcfang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
                #em-cf-agentpick { position: relative; }
                #em-cf-agentpick .em-cf-agentpick-search {
                    width: 100%; padding: 9px 12px; border-radius: 10px;
                    background: rgba(2,6,23,.65); color: #e2e8f0;
                    border: 1px solid rgba(125,211,252,.22);
                    transition: box-shadow .25s ease;
                }
                #em-cf-agentpick .em-cf-agentpick-search:focus {
                    outline: none;
                    box-shadow: 0 0 16px rgba(34,211,238,.25);
                    border-color: rgba(34,211,238,.5);
                }
                #em-cf-agentpick .em-cf-agentpick-results {
                    position: absolute; left: 0; right: 0; z-index: 40;
                    margin-top: 6px; max-height: 260px; overflow-y: auto;
                    background: rgba(4,8,20,.97); border: 1px solid rgba(125,211,252,.25);
                    border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,.55);
                    padding: 6px;
                }
                #em-cf-agentpick .em-cf-agentpick-row,
                #em-cf-agentpick .em-cf-agentpick-selected {
                    position: relative; display: flex; align-items: center; gap: 10px;
                    width: 100%; text-align: left; padding: 8px 10px; margin: 4px 0;
                    background: linear-gradient(160deg, rgba(15,23,42,.75), rgba(12,16,44,.55));
                    border: 1px solid rgba(125,211,252,.16); border-radius: 12px;
                    color: #e2e8f0; cursor: pointer;
                    transform-style: preserve-3d; will-change: transform;
                    transition: transform .16s ease, box-shadow .25s ease;
                }
                #em-cf-agentpick .em-cf-agentpick-row::before,
                #em-cf-agentpick .em-cf-agentpick-selected::before {
                    content: ''; position: absolute; inset: -1px; border-radius: 13px;
                    padding: 1.5px; pointer-events: none; opacity: 0;
                    background: conic-gradient(from var(--emcfang, 0deg), #22d3ee, #b608c9, #7dd3fc, #22d3ee);
                    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                    -webkit-mask-composite: xor;
                            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                            mask-composite: exclude;
                    transition: opacity .3s ease;
                }
                #em-cf-agentpick .em-cf-agentpick-row:hover,
                #em-cf-agentpick .em-cf-agentpick-selected:hover {
                    box-shadow: 0 14px 32px rgba(0,0,0,.45), 0 0 18px rgba(34,211,238,.18);
                }
                #em-cf-agentpick .em-cf-agentpick-row:hover::before,
                #em-cf-agentpick .em-cf-agentpick-selected:hover::before {
                    opacity: 1;
                    animation: emCfBorderSpin 2.6s linear infinite;
                }
                @keyframes emCfBorderSpin { to { --emcfang: 360deg; } }
                #em-cf-agentpick img { width: 32px; height: 32px; border-radius: 999px; object-fit: cover; background: rgba(125,211,252,.1); }
                #em-cf-agentpick strong { color: #f8fafc; font-size: 13px; }
                #em-cf-agentpick .em-cf-agentpick-muted { color: #94a3b8; font-size: 12px; padding: 8px 10px; }
                #em-cf-agentpick .em-cf-agentpick-x {
                    margin-left: auto; width: 26px; height: 26px; border-radius: 999px;
                    background: rgba(15,23,42,.85); color: #f1f5f9;
                    border: 1px solid rgba(148,163,184,.3); cursor: pointer;
                    font-size: 15px; line-height: 1;
                }
                @media (prefers-reduced-motion: reduce) {
                    #em-cf-agentpick .em-cf-agentpick-row::before,
                    #em-cf-agentpick .em-cf-agentpick-selected::before { animation: none !important; }
                }
            </style>
            <script>
            jQuery(function ($) {
                var $wrap = $('#em-cf-agentpick');
                if (!$wrap.length) { return; }
                var nonce = $wrap.attr('data-nonce');
                var $hidden = $('#chat-form-bot-agent');
                var $sel = $wrap.find('[data-ap-selected]');
                var $sw = $wrap.find('[data-ap-searchwrap]');
                var $search = $wrap.find('[data-ap-search]');
                var $results = $wrap.find('[data-ap-results]');
                var timer = null;
                function escT(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
                function run(q) {
                    $results.removeAttr('hidden').html('<div class="em-cf-agentpick-muted"><?php echo esc_js(__('Searching…', 'chat-forms')); ?></div>');
                    $.post(ajaxurl, { action: 'em_cf_agent_search', nonce: nonce, q: q }, function (resp) {
                        var rows = (resp && resp.success && resp.data && resp.data.agents) || [];
                        if (!rows.length) {
                            $results.html('<div class="em-cf-agentpick-muted"><?php echo esc_js(__('No agents match.', 'chat-forms')); ?></div>');
                            return;
                        }
                        $results.html(rows.map(function (a) {
                            return '<button type="button" class="em-cf-agentpick-row" data-id="' + parseInt(a.id, 10) + '"'
                                + ' data-avatar="' + escT(a.avatar) + '" data-name="' + escT(a.name) + '">'
                                + '<img src="' + escT(a.avatar) + '" alt="" /><strong>' + escT(a.name) + '</strong>'
                                + '</button>';
                        }).join(''));
                    });
                }
                $search.on('input focus', function () {
                    var q = $search.val() || '';
                    clearTimeout(timer);
                    timer = setTimeout(function () { run(q); }, 250);
                });
                $(document).on('click', function (e) {
                    if (!$(e.target).closest('#em-cf-agentpick').length) { $results.attr('hidden', 'hidden'); }
                });
                $results.on('click', '.em-cf-agentpick-row', function () {
                    var $b = $(this);
                    $hidden.val($b.attr('data-id'));
                    $sel.find('[data-ap-sel-avatar]').attr('src', $b.attr('data-avatar'));
                    $sel.find('[data-ap-sel-name]').text($b.attr('data-name'));
                    $sel.removeAttr('hidden');
                    $sw.attr('hidden', 'hidden');
                    $results.attr('hidden', 'hidden');
                });
                $wrap.on('click', '[data-ap-clear]', function () {
                    $hidden.val('');
                    $sel.attr('hidden', 'hidden');
                    $sw.removeAttr('hidden');
                    $search.val('').trigger('focus');
                });
                // 3D tilt toward the cursor on every interactive card.
                $wrap.on('mousemove', '.em-cf-agentpick-row, .em-cf-agentpick-selected', function (e) {
                    var r = this.getBoundingClientRect();
                    var x = e.clientX - r.left, y = e.clientY - r.top;
                    var rx = ((y - r.height / 2) / r.height) * -8;
                    var ry = ((x - r.width / 2) / r.width) * 8;
                    this.style.transform = 'perspective(600px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg)';
                });
                $wrap.on('mouseleave', '.em-cf-agentpick-row, .em-cf-agentpick-selected', function () {
                    this.style.transform = '';
                });
            });
            </script>order:0;border-top:1px solid #eee;" />

            <p style="margin:0 0 6px;"><strong><?php _e('Standard Shortcode:', 'chat-forms'); ?></strong></p>
            <input type="text" readonly value="[chat_form id='<?php echo esc_attr($post->ID); ?>']" class="widefat" onclick="this.select()">

            <p style="margin:14px 0 6px;"><strong><?php _e('Popup Trigger Class:', 'chat-forms'); ?></strong></p>
            <div style="background:rgba(2,6,23,.6);padding:8px;border-radius:8px;border:1px solid rgba(125,211,252,.25);font-size:11px;color:#e2e8f0;">
                <code style="background:transparent;color:#7dd3fc;">class="chat-form-popup-trigger" data-form-id="<?php echo esc_attr($post->ID); ?>"</code>
            </div>
        </div>

        <script>
            jQuery(function ($) {
                $('#chat-form-display-mode').on('change', function () {
                    $('.chat-form-widget-config').toggle($(this).val() !== 'shortcode');
                });
                $('.chat-form-pick-image').on('click', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var target = $btn.data('target');
                    var frame = wp.media({ title: 'Pick image', multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        $(target).val(att.url);
                        var $img = $btn.next('img');
                        if ($img.length) $img.attr('src', att.url);
                        else $btn.after('<img src="' + att.url + '" style="display:block;width:60px;height:60px;object-fit:cover;border-radius:50%;margin-top:8px;" />');
                        $btn.text('🖼️ Change');
                    });
                    frame.open();
                });
            });
        </script>
        <?php
    }


    public function send_test_email()
    {
        check_ajax_referer('chat_forms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $to = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
        $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : 'Test Email';
        // Allow HTML in body for test emails
        $body = isset($_POST['body']) ? wp_kses_post($_POST['body']) : '';

        if (!$to) {
            wp_send_json_error('Invalid email address');
        }

        // Dummy Replacements for Test
        $replacements = array(
            '{form_name}' => 'Test Form Name',
            '{user_email}' => 'user@example.com',
            '{submission_id}' => '#TEST-123',
            '{date}' => date('Y-m-d'),
            '{time}' => date('H:i:s'),
            '{all_fields}' => '<div style="background:#f9f9f9;padding:15px;border-left:4px solid #667eea;"><p><strong>Question 1:</strong> Sample Answer 1</p><p><strong>Question 2:</strong> Sample Answer 2</p></div>'
        );
        
        // Dynamic Question Replacements {question_N}
        // Since we don't have real questions here, iterate 1-20 and add dummy answers
        for ($i = 1; $i <= 20; $i++) {
            $replacements['{question_' . $i . '}'] = 'Test Answer for Question ' . $i;
        }

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
        $body = str_replace(array_keys($replacements), array_values($replacements), $body);

        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, '[TEST] ' . $subject, $body, $headers);

        if ($sent) {
            wp_send_json_success('Email sent');
        } else {
            wp_send_json_error('wp_mail failed');
        }
    }

}


// ─── Chatflow "Bot Agent" AJAX search (connected ai_agent users) ─────────────
add_action('wp_ajax_em_cf_agent_search', 'em_cf_agent_search_ajax');
function em_cf_agent_search_ajax()
{
    check_ajax_referer('em_cf_agent_search', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);
    $q = isset($_POST['q']) ? sanitize_text_field((string) wp_unslash($_POST['q'])) : '';
    $args = array(
        'role'    => 'ai_agent',
        'number'  => 20,
        'orderby' => 'display_name',
        'order'   => 'ASC',
    );
    if ($q !== '') {
        $args['search'] = '*' . $q . '*';
        $args['search_columns'] = array('display_name', 'user_login', 'user_nicename', 'user_email');
    }
    $out = array();
    foreach (get_users($args) as $u) {
        $out[] = array(
            'id'     => (int) $u->ID,
            'name'   => (string) $u->display_name,
            'avatar' => get_avatar_url($u->ID, array('size' => 64)),
        );
    }
    wp_send_json_success(array('agents' => $out));
}


// ─── "Back to Chatflows" bar at the top of the flow editor ───────────────────
add_action('edit_form_top', 'em_cf_editor_back_button');
function em_cf_editor_back_button($post)
{
    if (!$post || !in_array($post->post_type, array('chat_form', 'basic_form'), true)) {
        return;
    }
    $is_flow = 'chat_form' === $post->post_type;
    $url = admin_url('admin.php?page=talk-flows#tab=' . ($is_flow ? 'chatflows' : 'forms'));
    $label = $is_flow ? __('Back to Chatflows', 'chat-forms') : __('Back to Forms', 'chat-forms');
    ?>
    <a href="<?php echo esc_url($url); ?>" class="em-cf-back-btn">&larr; <?php echo esc_html($label); ?></a>
    <style>
        .em-cf-back-btn {
            display: inline-flex; align-items: center; gap: 8px;
            margin: 0 0 14px; padding: 9px 20px;
            background: linear-gradient(160deg, rgba(15,23,42,.85), rgba(12,16,44,.65));
            color: #7dd3fc !important;
            border: 1px solid rgba(125,211,252,.3);
            border-radius: 999px;
            font-weight: 700; font-size: 13px;
            text-decoration: none;
            transition: box-shadow .25s ease, border-color .25s ease, transform .15s ease;
        }
        .em-cf-back-btn:hover {
            color: #eafcff !important;
            border-color: rgba(34,211,238,.6);
            box-shadow: 0 10px 26px rgba(0,0,0,.4), 0 0 18px rgba(34,211,238,.22);
            transform: translateY(-1px);
        }
    </style>
    <?php
}


/**
 * Connected-device catalog for the AI Prompt "Runs At" selector — the
 * linked group admins' registered devices (projects-plugin registry),
 * each with its AI integrations. Desktops always offer the Claude
 * terminal (mirrors the sequence editor). Empty when the bridge is absent.
 */
function em_cf_run_devices()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array();
    if (!function_exists('psoo_group_device_owner_uids') || !function_exists('psoo_device_get_records')) {
        return $cache;
    }
    $gid  = (int) get_option('gdc_bp_group_id');
    $seen = array();
    foreach (psoo_group_device_owner_uids($gid) as $uid) {
        $owner = get_userdata((int) $uid);
        foreach (psoo_device_get_records((int) $uid) as $rec) {
            $did = isset($rec['device_id']) ? (string) $rec['device_id'] : '';
            if ($did === '' || isset($seen[$did])) continue;
            $seen[$did] = true;
            $ints = isset($rec['ai_integrations']) && is_array($rec['ai_integrations']) ? array_values($rec['ai_integrations']) : array();
            $type = isset($rec['type']) ? (string) $rec['type'] : 'device';
            if ('desktop' === $type) {
                $has_ct = false;
                foreach ($ints as $it) { if (is_array($it) && ($it['id'] ?? '') === 'claude-terminal') { $has_ct = true; break; } }
                if (!$has_ct) $ints[] = array('id' => 'claude-terminal', 'displayName' => 'Claude Terminal (personal account)', 'available' => true, 'models' => array());
            }
            $cache[] = array(
                'device_id'       => $did,
                'label'           => isset($rec['label']) && $rec['label'] !== '' ? (string) $rec['label'] : $did,
                'type'            => $type,
                'owner'           => $owner ? (string) $owner->display_name : '',
                'online'          => function_exists('psoo_device_online') ? (bool) psoo_device_online($rec) : false,
                'ai_integrations' => $ints,
            );
        }
    }
    return $cache;
}

/**
 * Real AI Models leaderboard picks (leo plugin, aipa_get_ai_models()) as a flat <option> list for the AI
 * Prompt "AI Runs At" section's model picker -- one option per real Best Quality/Value/Cost pick actually
 * configured on the leo AI Models page, using the exact same 'pick:<category>:<tier>:<pickId>' value leo's
 * own model picker uses elsewhere, so this stays interoperable if that value is ever read from that side.
 * Guarded since email-manager has no hard dependency on leo being active.
 */
function em_cf_ai_model_options($selected)
{
    if (!function_exists('aipa_get_ai_models')) {
        return '<option value="">' . esc_html__('— AI Models leaderboard unavailable —', 'chat-forms') . '</option>';
    }
    $pick_labels = array('quality' => __('Best Quality', 'chat-forms'), 'value' => __('Best Value', 'chat-forms'), 'cost' => __('Cheapest Cost', 'chat-forms'));
    $html = '<option value="">' . esc_html__('— use the default model for this tier —', 'chat-forms') . '</option>';
    foreach (aipa_get_ai_models() as $cat_id => $cat) {
        foreach ((array) ($cat['tiers'] ?? array()) as $tid => $tier) {
            $leaderboard = $tier['leaderboard'] ?? array();
            if (!$leaderboard) continue;
            $by_id = array();
            foreach ($leaderboard as $e) { $by_id[$e['id']] = $e['name']; }
            foreach ($pick_labels as $pid => $pick_label) {
                $entry_id = $tier['picks'][$pid] ?? null;
                $model_name = ($entry_id && isset($by_id[$entry_id])) ? $by_id[$entry_id] : null;
                if (!$model_name) continue;
                $value = 'pick:' . $cat_id . ':' . $tid . ':' . $pid;
                $label = ($cat['label'] ?? $cat_id) . ' · ' . $tid . ' · ' . $pick_label . ' (' . $model_name . ')';
                $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
            }
        }
    }
    return $html;
}

/** Real validation for a submitted em_cf_ai_model_options() value -- '' (use default) is always valid;
 *  anything else must resolve to a pick that genuinely still exists on the real leo AI Models leaderboard,
 *  never stored as-is on trust. */
function em_cf_is_valid_ai_model_pick($value)
{
    if ($value === '') return true;
    if (!function_exists('aipa_get_ai_models')) return false;
    $parts = explode(':', $value);
    if (count($parts) !== 4 || $parts[0] !== 'pick') return false;
    list(, $cat_id, $tid, $pid) = $parts;
    $categories = aipa_get_ai_models();
    return !empty($categories[$cat_id]['tiers'][$tid]['picks'][$pid]);
}
