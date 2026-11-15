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

                wp_enqueue_script('chat-forms-admin-js', EMAIL_MANAGER_URL . 'assets/forms/admin.js', array('jquery', 'jquery-ui-sortable'), '2.1', true);
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
                wp_enqueue_style('chat_forms_frontend_css', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.css', array(), '1.3.2', 'all');
                wp_enqueue_style('chat_forms_widget_launcher_css', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.css', array('chat_forms_frontend_css'), '1.3.0', 'all');
                wp_enqueue_script('chat_forms_frontend_js', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.js', array('jquery'), '1.10', true);
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
                .chat-form-question .account-registration-editor {
                    background: linear-gradient(160deg, rgba(99,102,241,.14), rgba(14,165,233,.08)) !important;
                    border: 1px solid rgba(129,140,248,.35) !important; border-left: 4px solid #818cf8 !important; color: #e0e7ff;
                }
                .chat-form-question .account-registration-editor strong { color: #c7d2fe !important; }
                .chat-form-question .account-registration-editor small { color: #a5b4fc !important; }
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

        // WordPress's generic "Custom Fields" box isn't used by chatflows —
        // hide it (post-type support stays, so meta keeps working).
        remove_meta_box('postcustom', 'chat_form', 'normal');

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

        <div class="em-cf-seqpick" id="em-cf-seqpick"
             data-nonce="<?php echo esc_attr(wp_create_nonce('em_cf_seq_search')); ?>">
            <label for="em-cf-seqpick-search"><strong><?php _e('Launch AI Sequence on Completion (Optional):', 'chat-forms'); ?></strong></label>
            <input type="hidden" name="chat_form_launch_code" id="chat-form-launch-code" value="<?php echo esc_attr($launch_code); ?>" />
            <div class="em-cf-seqpick-selected" data-sp-selected <?php echo $launch_code ? '' : 'hidden'; ?>>
                <span class="em-cf-seqpick-selected__icon" aria-hidden="true">&#9889;</span>
                <span class="em-cf-seqpick-selected__text">
                    <strong data-sp-sel-name><?php esc_html_e('Loading sequence…', 'chat-forms'); ?></strong>
                    <span data-sp-sel-meta></span>
                </span>
                <button type="button" class="em-cf-seqpick-x" data-sp-clear aria-label="<?php esc_attr_e('Remove sequence', 'chat-forms'); ?>">&times;</button>
            </div>
            <div class="em-cf-seqpick-bar">
                <div class="em-cf-seqpick-searchwrap">
                    <input type="search" class="em-cf-seqpick-search" id="em-cf-seqpick-search" data-sp-search autocomplete="off"
                           placeholder="<?php esc_attr_e('Search your gend.me group\'s sequences…', 'chat-forms'); ?>" />
                    <div class="em-cf-seqpick-results" data-sp-results hidden></div>
                </div>
                <a class="em-cf-seqpick-new" data-sp-new href="#" target="_blank" rel="noopener" hidden><?php _e('+ Add New Sequence', 'chat-forms'); ?></a>
            </div>
            <button type="button" class="em-cf-seqpick-manual-toggle" data-sp-manual-toggle><?php _e('Paste a launch code instead', 'chat-forms'); ?></button>
            <div class="em-cf-seqpick-code" data-sp-manual hidden>
                <input type="text" data-sp-manual-input autocomplete="off"
                       placeholder="<?php esc_attr_e('LT-xxxxxxxxxx or a [gend_launch] shortcode', 'chat-forms'); ?>" />
            </div>
            <small>
                <?php _e('Pick a sequence from the gend.me group this site belongs to. When this chatflow is completed, the selected AI Sequence fires automatically.', 'chat-forms'); ?>
            </small>
        </div>
        <style>
            #em-cf-seqpick { position: relative; }
            #em-cf-seqpick > label { display: block; margin-bottom: 8px; }
            #em-cf-seqpick .em-cf-seqpick-bar { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; }
            #em-cf-seqpick .em-cf-seqpick-searchwrap { position: relative; flex: 1; min-width: 0; }
            #em-cf-seqpick .em-cf-seqpick-new {
                flex-shrink: 0; display: inline-flex; align-items: center; height: 42px; padding: 0 18px; border-radius: 10px;
                background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff !important; font-weight: 600; font-size: 13px;
                text-decoration: none; white-space: nowrap; box-shadow: 0 8px 20px rgba(99,102,241,.3);
            }
            #em-cf-seqpick .em-cf-seqpick-new:hover { filter: brightness(1.1); }
            #em-cf-seqpick .em-cf-seqpick-new[hidden] { display: none; }
            @media (max-width: 600px) { #em-cf-seqpick .em-cf-seqpick-bar { flex-direction: column; align-items: stretch; } }
            #em-cf-seqpick .em-cf-seqpick-search,
            #em-cf-seqpick .em-cf-seqpick-code input {
                width: 100%; padding: 9px 12px; border-radius: 10px;
                background: rgba(2,6,23,.65); color: #e2e8f0; border: 1px solid rgba(125,211,252,.22);
            }
            #em-cf-seqpick .em-cf-seqpick-code { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
            #em-cf-seqpick [hidden] { display: none !important; }
            #em-cf-seqpick > small { display: block; }
            #em-cf-seqpick .em-cf-seqpick-code input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
            #em-cf-seqpick input::placeholder { color: #94a3b8; }
            #em-cf-seqpick input:focus { outline: none; border-color: rgba(129,140,248,.6); box-shadow: 0 0 0 3px rgba(129,140,248,.2); }
            #em-cf-seqpick .em-cf-seqpick-selected {
                display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding: 12px 14px; border-radius: 12px;
                background: linear-gradient(160deg, rgba(99,102,241,.2), rgba(139,92,246,.12)); border: 1px solid rgba(129,140,248,.45);
            }
            #em-cf-seqpick .em-cf-seqpick-selected[hidden] { display: none; }
            #em-cf-seqpick .em-cf-seqpick-selected__icon {
                flex-shrink: 0; width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center;
                background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-size: 16px;
            }
            #em-cf-seqpick .em-cf-seqpick-selected__text { flex: 1; min-width: 0; }
            #em-cf-seqpick .em-cf-seqpick-selected__text strong { display: block; color: #fff; font-size: 15px; line-height: 1.3; }
            #em-cf-seqpick .em-cf-seqpick-selected__text span { display: block; color: #c7d2fe; font-size: 12.5px; margin-top: 2px; }
            #em-cf-seqpick .em-cf-seqpick-selected__text span:empty { display: none; }
            #em-cf-seqpick .em-cf-seqpick-x {
                flex-shrink: 0; width: 30px; height: 30px; border-radius: 999px; cursor: pointer; font-size: 17px; line-height: 1;
                background: rgba(15,23,42,.85); color: #f1f5f9; border: 1px solid rgba(148,163,184,.3);
            }
            #em-cf-seqpick .em-cf-seqpick-x:hover { background: rgba(239,68,68,.6); border-color: transparent; }
            #em-cf-seqpick .em-cf-seqpick-manual-toggle {
                display: block; margin: 0 0 6px; padding: 0; border: 0; background: none; cursor: pointer;
                color: #a5b4fc; font-size: 12.5px; text-decoration: underline;
            }
            #em-cf-seqpick .em-cf-seqpick-results {
                position: absolute; left: 0; right: 0; z-index: 40; margin-top: 6px; max-height: 320px; overflow-y: auto; padding: 6px;
                background: #050915 !important; border: 1px solid rgba(125,211,252,.25); border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,.55);
            }
            #em-cf-seqpick .em-cf-seqpick-row {
                display: block; width: 100%; text-align: left; padding: 9px 12px; margin: 2px 0; border-radius: 9px;
                border: 0; background: transparent; color: #f1f5f9; cursor: pointer;
            }
            #em-cf-seqpick .em-cf-seqpick-row:hover, #em-cf-seqpick .em-cf-seqpick-row:focus { background: rgba(99,102,241,.2); outline: none; }
            #em-cf-seqpick .em-cf-seqpick-row strong { display: block; font-size: 13px; color: #f8fafc; }
            #em-cf-seqpick .em-cf-seqpick-row span { display: block; font-size: 12px; color: #94a3b8; margin-top: 2px; }
            #em-cf-seqpick .em-cf-seqpick-row code { background: none; padding: 0; color: #a5b4fc; font-size: 11.5px; }
            #em-cf-seqpick .em-cf-seqpick-row.is-off strong::after { content: ' · disabled'; color: #fca5a5; font-weight: 500; }
            #em-cf-seqpick .em-cf-seqpick-muted { color: #94a3b8; font-size: 12.5px; padding: 8px 10px; line-height: 1.5; }
        </style>
        <script>
        jQuery(function ($) {
            var $wrap = $('#em-cf-seqpick');
            if (!$wrap.length) { return; }
            var $code = $('#chat-form-launch-code'), $search = $wrap.find('[data-sp-search]'),
                $results = $wrap.find('[data-sp-results]'), $sel = $wrap.find('[data-sp-selected]'),
                $selName = $wrap.find('[data-sp-sel-name]'), $selMeta = $wrap.find('[data-sp-sel-meta]'),
                $clear = $wrap.find('[data-sp-clear]'), $new = $wrap.find('[data-sp-new]'),
                $manual = $wrap.find('[data-sp-manual]'), $manualInput = $wrap.find('[data-sp-manual-input]'),
                cache = null, loading = null, timer = null, wentToNew = false;

            function load() {
                if (cache) { return $.Deferred().resolve(cache).promise(); }
                if (loading) { return loading; }
                loading = $.post(ajaxurl, { action: 'em_cf_seq_search', nonce: $wrap.data('nonce') })
                    .then(function (res) {
                        loading = null;
                        if (res && res.success) {
                            cache = res.data;
                            if (cache.group && cache.group.new_url) { $new.attr('href', cache.group.new_url).prop('hidden', false); }
                            return cache;
                        }
                        return $.Deferred().reject((res && res.data && res.data.message) || 'Could not load sequences.');
                    }, function () { loading = null; return 'Could not load sequences.'; });
                return loading;
            }
            function seqName(t) { return t.sequence || t.workspace || 'Untitled sequence'; }
            function seqMeta(t) {
                return [t.name, t.workspace && t.workspace !== t.sequence ? t.workspace : '', t.enabled ? '' : 'disabled']
                    .filter(Boolean).join(' · ');
            }
            // Show the selected sequence by name; the LT- code only lives in the hidden field.
            function showLinked() {
                var c = $.trim($code.val());
                $sel.prop('hidden', !c);
                if (!c) { return; }
                var hit = cache ? cache.triggers.filter(function (t) { return t.code === c; })[0] : null;
                if (hit) {
                    $selName.text(seqName(hit));
                    $selMeta.text(seqMeta(hit));
                } else if (cache || !loading) {
                    // A code pasted by hand that isn't one of this group's sequences.
                    $selName.text('Custom launch code');
                    $selMeta.text(c);
                }
            }
            function render(data) {
                var q = $.trim($search.val()).toLowerCase();
                var list = data.triggers.filter(function (t) {
                    return !q || [t.sequence, t.workspace, t.name, t.code].join(' ').toLowerCase().indexOf(q) !== -1;
                });
                $results.empty().prop('hidden', false);
                if (data.group && data.group.name) {
                    $('<div class="em-cf-seqpick-muted">').text('Sequences in ' + data.group.name).appendTo($results);
                }
                if (!list.length) {
                    $('<div class="em-cf-seqpick-muted">').text(data.triggers.length ? 'No matching sequences.' : 'This group has no sequences with a launch code yet. Add a Launch Trigger to a sequence in the desktop app.').appendTo($results);
                    return;
                }
                list.slice(0, 50).forEach(function (t) {
                    var meta = [t.name, t.workspace !== t.sequence ? t.workspace : ''].filter(Boolean).join(' · ');
                    $('<button type="button" class="em-cf-seqpick-row">').toggleClass('is-off', !t.enabled).data('t', t)
                        .append($('<strong>').text(seqName(t)))
                        .append(meta ? $('<span>').text(meta) : '')
                        .appendTo($results);
                });
            }
            function open() {
                $results.empty().prop('hidden', false).append($('<div class="em-cf-seqpick-muted">').text('Loading sequences…'));
                load().then(render, function (msg) {
                    $results.empty().append($('<div class="em-cf-seqpick-muted">').text(msg));
                });
            }

            $search.on('focus', open).on('input', function () { clearTimeout(timer); timer = setTimeout(function () { cache ? render(cache) : open(); }, 120); });
            $search.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
            $results.on('click', '.em-cf-seqpick-row', function () {
                $code.val($(this).data('t').code);
                $search.val('');
                $manualInput.val('');
                $manual.prop('hidden', true);
                $results.prop('hidden', true).empty();
                showLinked();
            });
            $clear.on('click', function () { $code.val(''); $manualInput.val(''); showLinked(); });
            $wrap.find('[data-sp-manual-toggle]').on('click', function () {
                $manual.prop('hidden', !$manual.prop('hidden'));
                if (!$manual.prop('hidden')) { $manualInput.trigger('focus'); }
            });
            // The save handler accepts a bare code or a [gend_launch code="…"] shortcode.
            $manualInput.on('input', function () { $code.val($.trim($manualInput.val())); showLinked(); });
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#em-cf-seqpick .em-cf-seqpick-searchwrap').length) { $results.prop('hidden', true); }
            });
            // Load up front so the "+ Add New Sequence" link knows the group's
            // gend.me URL; reload after the user comes back from creating one.
            load().then(showLinked, showLinked);
            $new.on('click', function () { wentToNew = true; });
            $(window).on('focus', function () {
                if (!wentToNew) { return; }
                wentToNew = false; cache = null;
                load().then(showLinked);
            });
        });
        </script>
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
        <?php
        $cf_list_ids = array_map('intval', (array) get_post_meta($post->ID, '_chat_form_email_lists', true));
        $cf_lists    = array();
        foreach (array_filter($cf_list_ids) as $cf_list_id) {
            $cf_list = function_exists('em_get_list') ? em_get_list($cf_list_id) : null;
            if ($cf_list) {
                $cf_lists[] = array('id' => (int) $cf_list->id, 'name' => (string) $cf_list->name);
            }
        }
        ?>
        <div class="em-cf-listpick" id="em-cf-listpick"
             data-nonce="<?php echo esc_attr(wp_create_nonce('em_cf_list_search')); ?>">
            <label class="em-cf-listpick-label" for="em-cf-listpick-search"><?php _e('Add submitters to email lists', 'chat-forms'); ?></label>
            <input type="hidden" name="chat_form_email_lists" id="chat-form-email-lists"
                   value="<?php echo esc_attr(implode(',', wp_list_pluck($cf_lists, 'id'))); ?>" />
            <div class="em-cf-listpick-chips" data-lp-chips>
                <?php foreach ($cf_lists as $cf_list) : ?>
                    <span class="em-cf-listpick-chip" data-id="<?php echo (int) $cf_list['id']; ?>">
                        <?php echo esc_html($cf_list['name']); ?>
                        <button type="button" data-lp-remove aria-label="<?php esc_attr_e('Remove list', 'chat-forms'); ?>">&times;</button>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="em-cf-listpick-searchwrap">
                <input type="search" class="em-cf-listpick-search" id="em-cf-listpick-search" data-lp-search autocomplete="off"
                       placeholder="<?php esc_attr_e('Search email lists…', 'chat-forms'); ?>" />
                <div class="em-cf-listpick-results" data-lp-results hidden></div>
            </div>
            <p class="description"><?php _e('Everyone who completes this form is added to the selected lists, using the email address they enter (or their account email if they are logged in).', 'chat-forms'); ?></p>
        </div>
        <style>
            #em-cf-listpick { position: relative; margin: 4px 0 22px; padding-bottom: 18px; border-bottom: 1px solid rgba(148,163,184,.16); }
            #em-cf-listpick .em-cf-listpick-label { display: block; margin-bottom: 8px; font-weight: 600; color: #e2e8f0; }
            #em-cf-listpick .em-cf-listpick-chips { display: flex; flex-wrap: wrap; gap: 8px; }
            #em-cf-listpick .em-cf-listpick-chips:not(:empty) { margin-bottom: 10px; }
            #em-cf-listpick .em-cf-listpick-chip {
                display: inline-flex; align-items: center; gap: 6px; padding: 5px 6px 5px 12px; border-radius: 999px;
                background: rgba(99,102,241,.18); border: 1px solid rgba(129,140,248,.4); color: #e0e7ff; font-size: 13px; font-weight: 600;
            }
            #em-cf-listpick .em-cf-listpick-chip button {
                width: 20px; height: 20px; border-radius: 999px; border: 0; padding: 0; cursor: pointer;
                background: rgba(15,23,42,.7); color: #c7d2fe; font-size: 14px; line-height: 20px;
            }
            #em-cf-listpick .em-cf-listpick-chip button:hover { background: rgba(239,68,68,.6); color: #fff; }
            #em-cf-listpick .em-cf-listpick-searchwrap { position: relative; max-width: 460px; }
            #em-cf-listpick .em-cf-listpick-search {
                width: 100%; padding: 9px 12px; border-radius: 10px;
                background: rgba(2,6,23,.65); color: #e2e8f0; border: 1px solid rgba(125,211,252,.22);
            }
            #em-cf-listpick .em-cf-listpick-search::placeholder { color: #94a3b8; }
            #em-cf-listpick .em-cf-listpick-search:focus { outline: none; border-color: rgba(129,140,248,.6); box-shadow: 0 0 0 3px rgba(129,140,248,.2); }
            #em-cf-listpick .em-cf-listpick-results {
                position: absolute; left: 0; right: 0; z-index: 40; margin-top: 6px; max-height: 260px; overflow-y: auto; padding: 6px;
                background: #050915 !important; border: 1px solid rgba(125,211,252,.25); border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,.55);
            }
            #em-cf-listpick .em-cf-listpick-row {
                display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; text-align: left;
                padding: 9px 12px; margin: 2px 0; border-radius: 9px; border: 0; background: transparent; color: #f1f5f9; font-size: 13px; cursor: pointer;
            }
            #em-cf-listpick .em-cf-listpick-row:hover, #em-cf-listpick .em-cf-listpick-row:focus { background: rgba(99,102,241,.2); outline: none; }
            #em-cf-listpick .em-cf-listpick-row small { color: #94a3b8; }
            #em-cf-listpick .em-cf-listpick-muted { color: #94a3b8; font-size: 12px; padding: 8px 10px; }
        </style>
        <script>
        jQuery(function ($) {
            var $wrap = $('#em-cf-listpick');
            if (!$wrap.length) { return; }
            var $input = $('#chat-form-email-lists'), $chips = $wrap.find('[data-lp-chips]'),
                $search = $wrap.find('[data-lp-search]'), $results = $wrap.find('[data-lp-results]'),
                timer = null, req = null;

            function ids() { return $input.val() ? $input.val().split(',') : []; }
            function sync() {
                $input.val($chips.children().map(function () { return $(this).attr('data-id'); }).get().join(','));
            }
            function addChip(id, name) {
                if (ids().indexOf(String(id)) !== -1) { return; }
                $('<span class="em-cf-listpick-chip">').attr('data-id', id).text(name)
                    .append($('<button type="button" data-lp-remove>&times;</button>').attr('aria-label', 'Remove list'))
                    .appendTo($chips);
                sync();
            }
            function search() {
                if (req) { req.abort(); }
                req = $.post(ajaxurl, { action: 'em_cf_list_search', nonce: $wrap.data('nonce'), q: $search.val() })
                    .done(function (res) {
                        var lists = (res && res.success && res.data.lists) || [], chosen = ids();
                        lists = lists.filter(function (l) { return chosen.indexOf(String(l.id)) === -1; });
                        $results.empty().prop('hidden', false);
                        if (!lists.length) {
                            $results.append($('<div class="em-cf-listpick-muted">').text('No matching lists.'));
                            return;
                        }
                        lists.forEach(function (l) {
                            $('<button type="button" class="em-cf-listpick-row">')
                                .data('list', l)
                                .append($('<span>').text(l.name))
                                .append($('<small>').text(l.count + (l.count === 1 ? ' subscriber' : ' subscribers')))
                                .appendTo($results);
                        });
                    });
            }

            $search.on('focus input', function () { clearTimeout(timer); timer = setTimeout(search, 200); });
            $search.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
            $results.on('click', '.em-cf-listpick-row', function () {
                var l = $(this).data('list');
                addChip(l.id, l.name);
                $search.val('');
                $results.prop('hidden', true).empty();
            });
            $chips.on('click', '[data-lp-remove]', function () { $(this).closest('.em-cf-listpick-chip').remove(); sync(); });
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#em-cf-listpick .em-cf-listpick-searchwrap').length) { $results.prop('hidden', true); }
            });
        });
        </script>

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
                <?php $em_is_q = !in_array($type, array('info_block', 'prompt_response', 'support_ticket', 'account_registration'), true); ?>
                <select class="em-cf-sec-kind">
                    <option value="question" <?php selected($em_is_q); ?>>💬 <?php _e('Question', 'chat-forms'); ?></option>
                    <option value="info_block" <?php selected($type, 'info_block'); ?>>📝 <?php _e('Content Block', 'chat-forms'); ?></option>
                    <option value="prompt_response" <?php selected($type, 'prompt_response'); ?>>🤖 <?php _e('AI Prompt', 'chat-forms'); ?></option>
                    <option value="support_ticket" <?php selected($type, 'support_ticket'); ?>>🎫 <?php _e('Support Ticket', 'chat-forms'); ?></option>
                    <option value="account_registration" <?php selected($type, 'account_registration'); ?>>👤 <?php _e('Account Registration', 'chat-forms'); ?></option>
                </select>
                <select class="em-cf-sec-qtype" style="<?php echo $em_is_q ? '' : 'display:none;'; ?>">
                    <option value="text" <?php selected($type, 'text'); ?>>Text</option>
                    <option value="multiple" <?php selected($type, 'multiple'); ?>>Multiple Choice</option>
                    <option value="email" <?php selected($type, 'email'); ?>>Email</option>
                    <option value="telephone" <?php selected($type, 'telephone'); ?>>Telephone</option>
                    <option value="file" <?php selected($type, 'file'); ?>>File Upload</option>
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
                    <small style="display:block;margin-bottom:4px;color:#666;"><?php _e('Member email — resolved to that WP user on save. Their AI Builder Tokens pay for every run of this prompt.', 'chat-forms'); ?></small>
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

            <!-- Account Registration: terms & conditions -->
            <?php
            $tc = isset($data['terms']) && is_array($data['terms']) ? $data['terms'] : array();
            $tc = wp_parse_args($tc, array('enabled' => false, 'label' => '', 'mode' => 'popup', 'title' => '', 'content' => '', 'url' => ''));
            $tc_name = 'chat_form_questions[' . $index . '][terms]';
            ?>
            <div class="account-registration-editor" style="<?php echo $type === 'account_registration' ? '' : 'display:none;'; ?>margin:10px 0;padding:12px;border-radius:12px;">
                <p style="margin:0 0 6px;"><strong>👤 <?php _e('Account Registration', 'chat-forms'); ?></strong></p>
                <small style="display:block;margin-bottom:10px;"><?php _e('Visitors log in or create an account at this step; members who are already logged in just continue.', 'chat-forms'); ?></small>
                <label style="display:flex;gap:8px;align-items:flex-start;">
                    <input type="checkbox" name="<?php echo esc_attr($tc_name); ?>[enabled]" value="1" class="em-cf-terms-toggle" style="margin-top:3px;" <?php checked(!empty($tc['enabled'])); ?> />
                    <span><?php _e('Require accepting terms &amp; conditions before the account is created', 'chat-forms'); ?></span>
                </label>
                <div class="em-cf-terms-config" style="<?php echo !empty($tc['enabled']) ? '' : 'display:none;'; ?>margin-top:10px;">
                    <label><?php _e('Checkbox text:', 'chat-forms'); ?></label>
                    <input type="text" name="<?php echo esc_attr($tc_name); ?>[label]" class="widefat" value="<?php echo esc_attr($tc['label']); ?>" placeholder="<?php esc_attr_e('I agree to the Terms & Conditions', 'chat-forms'); ?>" />
                    <label style="display:block;margin-top:10px;"><?php _e('Show the terms:', 'chat-forms'); ?></label>
                    <select name="<?php echo esc_attr($tc_name); ?>[mode]" class="widefat em-cf-terms-mode">
                        <option value="popup" <?php selected($tc['mode'], 'popup'); ?>><?php _e('In a popup (write the terms here)', 'chat-forms'); ?></option>
                        <option value="page" <?php selected($tc['mode'], 'page'); ?>><?php _e('On another page (link)', 'chat-forms'); ?></option>
                    </select>
                    <div class="em-cf-terms-popup" style="<?php echo $tc['mode'] === 'page' ? 'display:none;' : ''; ?>margin-top:10px;">
                        <label><?php _e('Popup title:', 'chat-forms'); ?></label>
                        <input type="text" name="<?php echo esc_attr($tc_name); ?>[title]" class="widefat" value="<?php echo esc_attr($tc['title']); ?>" placeholder="<?php esc_attr_e('Terms & Conditions', 'chat-forms'); ?>" />
                        <label style="display:block;margin-top:10px;"><?php _e('Terms text:', 'chat-forms'); ?></label>
                        <textarea name="<?php echo esc_attr($tc_name); ?>[content]" rows="8" class="widefat" placeholder="<?php esc_attr_e('Paste or write your terms. Basic HTML is allowed.', 'chat-forms'); ?>"><?php echo esc_textarea($tc['content']); ?></textarea>
                    </div>
                    <div class="em-cf-terms-page" style="<?php echo $tc['mode'] === 'page' ? '' : 'display:none;'; ?>margin-top:10px;">
                        <label><?php _e('Terms page:', 'chat-forms'); ?></label>
                        <input type="url" name="<?php echo esc_attr($tc_name); ?>[url]" class="widefat" list="em-cf-terms-pages" value="<?php echo esc_attr($tc['url']); ?>" placeholder="https://example.com/terms" />
                        <small style="display:block;margin-top:4px;"><?php _e('Start typing to pick one of your pages, or paste any URL. It opens in a new tab and the visitor ticks the box to accept.', 'chat-forms'); ?></small>
                    </div>
                </div>
                <?php
                // One datalist of the site's pages for every terms-page field (printed once).
                static $em_cf_terms_pages_done = false;
                if (!$em_cf_terms_pages_done) {
                    $em_cf_terms_pages_done = true;
                    echo '<datalist id="em-cf-terms-pages">';
                    foreach (get_pages(array('number' => 200, 'sort_column' => 'post_title')) as $tp) {
                        echo '<option value="' . esc_attr(get_permalink($tp)) . '">' . esc_html($tp->post_title) . '</option>';
                    }
                    echo '</datalist>';
                }
                ?>
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

                // Account Registration — terms & conditions the visitor must accept.
                if (isset($question_data['terms']) && is_array($question_data['terms'])) {
                    $tq = wp_unslash($question_data['terms']);
                    $sanitized_question['terms'] = array(
                        'enabled' => !empty($tq['enabled']),
                        'label'   => sanitize_text_field($tq['label'] ?? ''),
                        'mode'    => (($tq['mode'] ?? '') === 'page') ? 'page' : 'popup',
                        'title'   => sanitize_text_field($tq['title'] ?? ''),
                        'content' => wp_kses_post($tq['content'] ?? ''),
                        'url'     => esc_url_raw($tq['url'] ?? ''),
                    );
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

        // Email lists every submitter gets added to (see em_cf_join_email_lists).
        if (isset($_POST['chat_form_email_lists'])) {
            $list_ids = array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['chat_form_email_lists']))));
            $list_ids = array_values(array_unique(array_filter($list_ids, function ($id) {
                return function_exists('em_get_list') && em_get_list($id);
            })));
            update_post_meta($post_id, '_chat_form_email_lists', $list_ids);
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
            $cf_agent_id   = (int) $_POST['chat_form_bot_agent'];
            $cf_agent_name = isset($_POST['chat_form_bot_agent_name']) ? sanitize_text_field(wp_unslash($_POST['chat_form_bot_agent_name'])) : '';
            $cf_agent_av   = isset($_POST['chat_form_bot_agent_avatar']) ? em_cf_clean_avatar(wp_unslash($_POST['chat_form_bot_agent_avatar'])) : '';
            if ($cf_agent_id > 0 && get_userdata($cf_agent_id)) {
                update_post_meta($post_id, '_chat_form_bot_agent_id', $cf_agent_id);
                update_post_meta($post_id, '_chat_form_bot_agent_name', get_userdata($cf_agent_id)->display_name);
                delete_post_meta($post_id, '_chat_form_bot_agent_hub_id');
                // The agent's avatar IS the bot avatar — same meta the whole
                // front-end chat pipeline already reads.
                update_post_meta($post_id, '_chat_form_bot_avatar', esc_url_raw(get_avatar_url($cf_agent_id, array('size' => 96))));
            } elseif ($cf_agent_id > 0 && $cf_agent_name !== '') {
                // Separately hosted site: a gend.me group agent with no local
                // user. Speaks with its gend.me name + avatar (Agent Messages
                // still needs a local user, so _chat_form_bot_agent_id stays empty).
                delete_post_meta($post_id, '_chat_form_bot_agent_id');
                update_post_meta($post_id, '_chat_form_bot_agent_hub_id', $cf_agent_id);
                update_post_meta($post_id, '_chat_form_bot_agent_name', $cf_agent_name);
                if ($cf_agent_av !== '') update_post_meta($post_id, '_chat_form_bot_avatar', $cf_agent_av);
            } else {
                // "— pick…" chosen: unlink the agent but keep any existing
                // avatar so older flows keep rendering unchanged.
                delete_post_meta($post_id, '_chat_form_bot_agent_id');
                delete_post_meta($post_id, '_chat_form_bot_agent_hub_id');
                delete_post_meta($post_id, '_chat_form_bot_agent_name');
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
            <?php _e('Protects your form from spam submissions. Requires the Site and Secret keys from the Talk Flows reCAPTCHA settings.', 'chat-forms'); ?>
        </p>
        <?php if (current_user_can('manage_options') && class_exists('EM_Email_SMTP')) : ?>
            <p><button type="button" class="button" data-em-recaptcha-open><?php _e('reCAPTCHA Settings', 'chat-forms'); ?></button></p>
            <?php EM_Email_SMTP::render_recaptcha_modal(); ?>
        <?php endif; ?>
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

                <?php
                $frontend_options = array(
                    'all'      => __('Every page', 'chat-forms'),
                    'home'     => __('Home page', 'chat-forms'),
                    'archive'  => __('Archives & blog', 'chat-forms'),
                    'singular' => __('Every single post, page & product', 'chat-forms'),
                );
                $admin_options = array(
                    'all'       => __('Every admin page', 'chat-forms'),
                    'dashboard' => __('Dashboard only', 'chat-forms'),
                    'plugins'   => __('Plugins screen', 'chat-forms'),
                    'users'     => __('Users screen', 'chat-forms'),
                    'tools'     => __('Tools screen', 'chat-forms'),
                );
                // Saved values → chips: rule keys, or post IDs (pages, posts, products…).
                $em_cf_chip = function ($val, $rules) {
                    if (isset($rules[$val])) return array('value' => $val, 'label' => $rules[$val], 'type' => __('Rule', 'chat-forms'));
                    $p = ctype_digit((string) $val) ? get_post((int) $val) : null;
                    if (!$p) return null;
                    $pto = get_post_type_object($p->post_type);
                    return array('value' => (string) $p->ID, 'label' => get_the_title($p) ?: __('(no title)', 'chat-forms'),
                                 'type' => $pto ? $pto->labels->singular_name : $p->post_type);
                };
                $em_cf_pickers = array(
                    array('key' => 'frontend', 'label' => __('Show on Frontend Pages:', 'chat-forms'), 'rules' => $frontend_options, 'values' => $pages_frontend,
                          'placeholder' => __('Search pages, posts & products…', 'chat-forms'), 'remote' => true,
                          'help' => __('"Every page" overrides everything else.', 'chat-forms')),
                    array('key' => 'admin', 'label' => __('Show on Admin Pages:', 'chat-forms'), 'rules' => $admin_options, 'values' => $pages_admin,
                          'placeholder' => __('Search admin screens…', 'chat-forms'), 'remote' => false, 'help' => ''),
                );
                foreach ($em_cf_pickers as $pk):
                    $rule_rows = array();
                    foreach ($pk['rules'] as $rv => $rl) $rule_rows[] = array('value' => $rv, 'label' => $rl, 'type' => __('Rule', 'chat-forms'));
                ?>
                <div class="em-cf-pagepick" data-pp data-name="chat_form_widget_pages_<?php echo esc_attr($pk['key']); ?>[]"
                     data-remote="<?php echo $pk['remote'] ? '1' : ''; ?>" data-rules="<?php echo esc_attr(wp_json_encode($rule_rows)); ?>">
                    <p style="margin:14px 0 6px;"><strong><?php echo esc_html($pk['label']); ?></strong></p>
                    <div class="em-cf-pagepick-chips" data-pp-chips><?php
                        foreach ($pk['values'] as $v) {
                            $c = $em_cf_chip((string) $v, $pk['rules']);
                            if (!$c) continue; ?>
                        <span class="em-cf-pagepick-chip" data-value="<?php echo esc_attr($c['value']); ?>">
                            <input type="hidden" name="chat_form_widget_pages_<?php echo esc_attr($pk['key']); ?>[]" value="<?php echo esc_attr($c['value']); ?>" />
                            <span><?php echo esc_html($c['label']); ?></span><em><?php echo esc_html($c['type']); ?></em>
                            <button type="button" data-pp-remove aria-label="<?php esc_attr_e('Remove', 'chat-forms'); ?>">&times;</button>
                        </span><?php } ?></div>
                    <div class="em-cf-pagepick-searchwrap">
                        <input type="search" class="em-cf-pagepick-search" data-pp-search autocomplete="off" placeholder="<?php echo esc_attr($pk['placeholder']); ?>" />
                        <div class="em-cf-pagepick-results" data-pp-results hidden></div>
                    </div>
                    <?php if ($pk['help']) : ?><p class="description"><?php echo esc_html($pk['help']); ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <style>
                    .em-cf-pagepick [hidden] { display: none !important; }
                    .em-cf-pagepick-chips { display: flex; flex-wrap: wrap; gap: 6px; }
                    .em-cf-pagepick-chips:not(:empty) { margin-bottom: 8px; }
                    .em-cf-pagepick-chip {
                        display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 4px 5px 4px 10px; border-radius: 999px;
                        background: rgba(99,102,241,.18); border: 1px solid rgba(129,140,248,.4); color: #e0e7ff; font-size: 12.5px; font-weight: 600;
                    }
                    .em-cf-pagepick-chip > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
                    .em-cf-pagepick-chip em { font-style: normal; font-weight: 500; font-size: 10.5px; color: #a5b4fc; text-transform: uppercase; letter-spacing: .04em; }
                    .em-cf-pagepick-chip button {
                        flex-shrink: 0; width: 18px; height: 18px; border-radius: 999px; border: 0; padding: 0; cursor: pointer;
                        background: rgba(15,23,42,.7); color: #c7d2fe; font-size: 13px; line-height: 18px;
                    }
                    .em-cf-pagepick-chip button:hover { background: rgba(239,68,68,.6); color: #fff; }
                    .em-cf-pagepick-searchwrap { position: relative; }
                    .em-cf-pagepick-search {
                        width: 100%; padding: 9px 12px; border-radius: 10px;
                        background: rgba(2,6,23,.65); color: #e2e8f0; border: 1px solid rgba(125,211,252,.22);
                    }
                    .em-cf-pagepick-search::placeholder { color: #94a3b8; }
                    .em-cf-pagepick-search:focus { outline: none; border-color: rgba(129,140,248,.6); box-shadow: 0 0 0 3px rgba(129,140,248,.2); }
                    .em-cf-pagepick-results {
                        position: absolute; left: 0; right: 0; z-index: 40; margin-top: 6px; max-height: 280px; overflow-y: auto; padding: 6px;
                        background: #050915 !important; border: 1px solid rgba(125,211,252,.25); border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,.55);
                    }
                    .em-cf-pagepick-row {
                        display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; text-align: left;
                        padding: 8px 10px; margin: 1px 0; border-radius: 8px; border: 0; background: transparent; color: #f1f5f9; font-size: 13px; cursor: pointer;
                    }
                    .em-cf-pagepick-row:hover, .em-cf-pagepick-row:focus { background: rgba(99,102,241,.2); outline: none; }
                    .em-cf-pagepick-row small { flex-shrink: 0; color: #94a3b8; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
                    .em-cf-pagepick-head { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; padding: 6px 10px 2px; }
                    .em-cf-pagepick-muted { color: #94a3b8; font-size: 12.5px; padding: 6px 10px; }
                </style>
                <script>
                jQuery(function ($) {
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('em_cf_content_search')); ?>;
                    $('[data-pp]').each(function () {
                        var $pp = $(this), $chips = $pp.find('[data-pp-chips]'), $search = $pp.find('[data-pp-search]'),
                            $results = $pp.find('[data-pp-results]'), rules = $pp.data('rules') || [], name = $pp.attr('data-name'),
                            remote = !!$pp.attr('data-remote'), timer = null, req = null;

                        function chosen() { return $chips.children().map(function () { return $(this).attr('data-value'); }).get(); }
                        function addChip(item) {
                            if (chosen().indexOf(String(item.value)) !== -1) { return; }
                            $('<span class="em-cf-pagepick-chip">').attr('data-value', item.value)
                                .append($('<input type="hidden">').attr({ name: name, value: item.value }))
                                .append($('<span>').text(item.label)).append($('<em>').text(item.type))
                                .append($('<button type="button" data-pp-remove aria-label="Remove">&times;</button>'))
                                .appendTo($chips);
                        }
                        function rows(title, items) {
                            var have = chosen();
                            items = items.filter(function (i) { return have.indexOf(String(i.value)) === -1; });
                            if (!items.length) { return; }
                            if (title) { $('<div class="em-cf-pagepick-head">').text(title).appendTo($results); }
                            items.forEach(function (i) {
                                $('<button type="button" class="em-cf-pagepick-row">').data('item', i)
                                    .append($('<span>').text(i.label)).append($('<small>').text(i.type)).appendTo($results);
                            });
                        }
                        function render(content) {
                            var q = $.trim($search.val()).toLowerCase();
                            $results.empty().prop('hidden', false);
                            rows(remote ? 'Rules' : '', rules.filter(function (r) { return !q || r.label.toLowerCase().indexOf(q) !== -1; }));
                            if (remote) {
                                if (content === null) { $('<div class="em-cf-pagepick-muted">').text('Searching…').appendTo($results); }
                                else { rows(q ? 'Matching content' : 'Recent content', content); }
                            }
                            if (!$results.children().length) { $('<div class="em-cf-pagepick-muted">').text('No matches.').appendTo($results); }
                        }
                        function search() {
                            render(remote ? null : []);
                            if (!remote) { return; }
                            if (req) { req.abort(); }
                            req = $.post(ajaxurl, { action: 'em_cf_content_search', nonce: nonce, q: $search.val() })
                                .done(function (res) { render((res && res.success && res.data.items) || []); })
                                .fail(function (x) { if (x.statusText !== 'abort') { render([]); } });
                        }

                        $search.on('focus input', function () { clearTimeout(timer); timer = setTimeout(search, 200); });
                        $search.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
                        $results.on('click', '.em-cf-pagepick-row', function () {
                            addChip($(this).data('item'));
                            $(this).remove();
                            if (!$results.find('.em-cf-pagepick-row').length) { $results.prop('hidden', true); }
                        });
                        $chips.on('click', '[data-pp-remove]', function () { $(this).closest('.em-cf-pagepick-chip').remove(); });
                        $(document).on('click', function (e) {
                            if (!$(e.target).closest($pp.find('.em-cf-pagepick-searchwrap')).length) { $results.prop('hidden', true); }
                        });
                    });
                });
                </script>
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
            // Separately hosted sites pick a gend.me agent that has no local
            // user — its name + avatar are stored on the flow instead.
            $cf_hub_agent_id = (int) get_post_meta($post->ID, '_chat_form_bot_agent_hub_id', true);
            $cf_sel_name     = $cf_bot_agent ? $cf_bot_agent->display_name : ($cf_hub_agent_id ? (string) get_post_meta($post->ID, '_chat_form_bot_agent_name', true) : '');
            $cf_sel_avatar   = $cf_bot_agent ? get_avatar_url($cf_bot_agent->ID, array('size' => 96)) : ($cf_hub_agent_id ? (string) get_post_meta($post->ID, '_chat_form_bot_avatar', true) : '');
            $cf_has_agent    = $cf_sel_name !== '';
            ?>
            <div class="em-cf-agentpick" id="em-cf-agentpick"
                 data-nonce="<?php echo esc_attr(wp_create_nonce('em_cf_agent_search')); ?>">
                <input type="hidden" name="chat_form_bot_agent" id="chat-form-bot-agent"
                       value="<?php echo esc_attr($cf_has_agent ? ($cf_bot_agent ? $cf_bot_agent_id : $cf_hub_agent_id) : ''); ?>" />
                <input type="hidden" name="chat_form_bot_agent_name" data-ap-name-field value="<?php echo esc_attr($cf_sel_name); ?>" />
                <input type="hidden" name="chat_form_bot_agent_avatar" data-ap-avatar-field value="<?php echo esc_attr($cf_sel_avatar); ?>" />
                <div class="em-cf-agentpick-selected" data-ap-selected <?php echo $cf_has_agent ? '' : 'hidden'; ?>>
                    <span class="em-cf-agentpick-av" data-ap-sel-av>
                        <?php if ($cf_sel_avatar !== '') : ?><img data-ap-sel-avatar src="<?php echo esc_attr($cf_sel_avatar); ?>" alt="" /><?php else : ?><?php echo esc_html(strtoupper(mb_substr($cf_sel_name, 0, 1))); ?><?php endif; ?>
                    </span>
                    <strong data-ap-sel-name><?php echo esc_html($cf_sel_name); ?></strong>
                    <button type="button" class="em-cf-agentpick-x" data-ap-clear aria-label="<?php esc_attr_e('Unlink agent', 'chat-forms'); ?>">&times;</button>
                </div>
                <div class="em-cf-agentpick-searchwrap" data-ap-searchwrap <?php echo $cf_has_agent ? 'hidden' : ''; ?>>
                    <input type="search" class="em-cf-agentpick-search" data-ap-search autocomplete="off"
                           placeholder="<?php esc_attr_e('Search your gend.me group\'s agents…', 'chat-forms'); ?>" />
                    <div class="em-cf-agentpick-results" data-ap-results hidden></div>
                </div>
                <a class="em-cf-agentpick-new" data-ap-new href="#" target="_blank" rel="noopener" hidden><?php _e('+ Add New Agent', 'chat-forms'); ?></a>
            </div>
            <p class="description"><?php _e('The gend.me group agent this chatflow speaks as — their avatar shows next to every bot message. Unless the display mode is "Shortcode only", a launched chatflow also arrives as private messages from this agent. User responses use their WP profile photo automatically.', 'chat-forms'); ?></p>

            <style>
                @property --emcfang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
                #em-cf-agentpick { position: relative; }
                #em-cf-agentpick [hidden] { display: none !important; }
                #em-cf-agentpick .em-cf-agentpick-av {
                    flex-shrink: 0; width: 32px; height: 32px; border-radius: 999px; overflow: hidden;
                    display: inline-flex; align-items: center; justify-content: center;
                    background: linear-gradient(135deg, #7c5cff, #38bdf8); color: #fff; font-weight: 700; font-size: 14px;
                }
                #em-cf-agentpick .em-cf-agentpick-av img { width: 100%; height: 100%; }
                #em-cf-agentpick .em-cf-agentpick-row small { display: block; color: #94a3b8; font-size: 11.5px; font-weight: 400; }
                #em-cf-agentpick .em-cf-agentpick-new {
                    display: flex; align-items: center; justify-content: center; margin-top: 8px; height: 38px; border-radius: 10px;
                    background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff !important; font-weight: 600; font-size: 13px;
                    text-decoration: none; box-shadow: 0 8px 20px rgba(99,102,241,.3);
                }
                #em-cf-agentpick .em-cf-agentpick-new:hover { filter: brightness(1.1); }
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
                var $new = $wrap.find('[data-ap-new]');
                var $nameField = $wrap.find('[data-ap-name-field]'), $avField = $wrap.find('[data-ap-avatar-field]');
                var timer = null, req = null;
                function escT(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
                // Avatar bubble: the image, or the agent's initial when there is none.
                function avatar(url, name) {
                    var $av = $('<span class="em-cf-agentpick-av">');
                    if (url) { $av.append($('<img alt="">').attr('src', url).on('error', function () { $(this).replaceWith(document.createTextNode((name || '?').charAt(0).toUpperCase())); })); }
                    else { $av.text((name || '?').charAt(0).toUpperCase()); }
                    return $av;
                }
                function run(q) {
                    if (req) { req.abort(); }
                    $results.removeAttr('hidden').html('<div class="em-cf-agentpick-muted"><?php echo esc_js(__('Searching…', 'chat-forms')); ?></div>');
                    req = $.post(ajaxurl, { action: 'em_cf_agent_search', nonce: nonce, q: q }, function (resp) {
                        if (!resp || !resp.success) {
                            $results.empty().append($('<div class="em-cf-agentpick-muted">').text((resp && resp.data && resp.data.message) || '<?php echo esc_js(__('Could not load agents.', 'chat-forms')); ?>'));
                            return;
                        }
                        var data = resp.data, rows = data.agents || [];
                        if (data.group && data.group.new_agent_url) { $new.attr('href', data.group.new_agent_url).removeAttr('hidden'); }
                        $results.empty();
                        if (data.group && data.group.name) {
                            $('<div class="em-cf-agentpick-muted">').text('<?php echo esc_js(__('Agents in', 'chat-forms')); ?> ' + data.group.name).appendTo($results);
                        }
                        if (!rows.length) {
                            $('<div class="em-cf-agentpick-muted">').text(q ? '<?php echo esc_js(__('No agents match.', 'chat-forms')); ?>' : '<?php echo esc_js(__('This group has no agents yet — add one with the button below.', 'chat-forms')); ?>').appendTo($results);
                            return;
                        }
                        rows.forEach(function (a) {
                            $('<button type="button" class="em-cf-agentpick-row">')
                                .attr({ 'data-id': parseInt(a.id, 10), 'data-avatar': a.avatar || '', 'data-name': a.name || '' })
                                .append(avatar(a.avatar, a.name))
                                .append($('<strong>').text(a.name).append(a.title ? $('<small>').text(a.title) : ''))
                                .appendTo($results);
                        });
                    });
                }
                // Learn the group's "+ Add New Agent" link up front.
                $.post(ajaxurl, { action: 'em_cf_agent_search', nonce: nonce, q: '' }, function (resp) {
                    var g = resp && resp.success && resp.data && resp.data.group;
                    if (g && g.new_agent_url) { $new.attr('href', g.new_agent_url).removeAttr('hidden'); }
                });
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
                    $nameField.val($b.attr('data-name'));
                    $avField.val($b.attr('data-avatar'));
                    $sel.find('[data-ap-sel-av]').replaceWith(avatar($b.attr('data-avatar'), $b.attr('data-name')).attr('data-ap-sel-av', ''));
                    $sel.find('[data-ap-sel-name]').text($b.attr('data-name'));
                    $sel.removeAttr('hidden');
                    $sw.attr('hidden', 'hidden');
                    $results.attr('hidden', 'hidden');
                });
                $wrap.on('click', '[data-ap-clear]', function () {
                    $hidden.val('');
                    $nameField.val('');
                    $avField.val('');
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
            </script>

            <hr style="margin:14px 0;border:0;border-top:1px solid #eee;" />

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


// ─── Chatflow "Bot Agent" AJAX search (the gend.me group's agents) ──────────
// Sub-sites read the group's roster directly; separately hosted sites ask
// gend.me's em/v1/group-agents. Sites with no group keep the old site-wide
// ai_agent search.
add_action('wp_ajax_em_cf_agent_search', 'em_cf_agent_search_ajax');
function em_cf_agent_search_ajax()
{
    check_ajax_referer('em_cf_agent_search', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);
    $q = isset($_POST['q']) ? strtolower(trim(sanitize_text_field((string) wp_unslash($_POST['q'])))) : '';
    $match = function ($a) use ($q) {
        return $q === '' || strpos(strtolower($a['name'] . ' ' . ($a['title'] ?? '')), $q) !== false;
    };

    $gid = em_cf_site_group_id();
    if ($gid) {
        if (is_multisite() && function_exists('groups_get_group')) {
            $g = groups_get_group($gid);
            if ($g && !empty($g->id)) {
                $summary = em_cf_group_summary($gid);
                wp_send_json_success(array(
                    'group'  => array('id' => $gid, 'name' => $summary['name'], 'new_agent_url' => $summary['new_agent_url']),
                    'agents' => array_values(array_filter(em_cf_group_agents($gid), $match)),
                    'source' => 'network',
                ));
            }
        }
        $body = em_cf_hub_fetch('group-agents', $gid);
        if (is_wp_error($body)) wp_send_json_error(array('message' => $body->get_error_message()));
        $agents = array();
        foreach ((array) ($body['agents'] ?? array()) as $a) {
            if (!is_array($a)) continue;
            $agents[] = array(
                'id'     => (int) ($a['id'] ?? 0),
                'name'   => sanitize_text_field((string) ($a['name'] ?? '')),
                'title'  => sanitize_text_field((string) ($a['title'] ?? '')),
                'avatar' => em_cf_clean_avatar((string) ($a['avatar'] ?? '')),
            );
        }
        wp_send_json_success(array(
            'group'  => $body['group'],
            'agents' => array_values(array_filter($agents, $match)),
            'source' => 'remote',
        ));
    }

    $args = array('role' => 'ai_agent', 'number' => 20, 'orderby' => 'display_name', 'order' => 'ASC');
    if ($q !== '') {
        $args['search'] = '*' . $q . '*';
        $args['search_columns'] = array('display_name', 'user_login', 'user_nicename', 'user_email');
    }
    $out = array();
    foreach (get_users($args) as $u) {
        $out[] = array('id' => (int) $u->ID, 'name' => (string) $u->display_name, 'title' => '',
                       'avatar' => get_avatar_url($u->ID, array('size' => 64)));
    }
    wp_send_json_success(array('group' => null, 'agents' => $out, 'source' => 'site'));
}


// ─── Talk Flows → Chatflows list: Delete column (moves the flow to the Trash) ─
add_action('wp_ajax_em_cf_delete_chatflow', 'em_cf_delete_chatflow_ajax');
function em_cf_delete_chatflow_ajax()
{
    check_ajax_referer('em_cf_delete_chatflow', 'nonce');
    $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $post = $id ? get_post($id) : null;
    if (!$post || $post->post_type !== 'chat_form') {
        wp_send_json_error(array('message' => __('Chatflow not found.', 'chat-forms')), 404);
    }
    if (!current_user_can('delete_post', $id)) {
        wp_send_json_error(array('message' => __('You are not allowed to delete this chatflow.', 'chat-forms')), 403);
    }
    if (!wp_trash_post($id)) {
        wp_send_json_error(array('message' => __('Could not delete this chatflow.', 'chat-forms')));
    }
    wp_send_json_success(array('id' => $id));
}

// ─── Display & Widget metabox: content search for "Show on Frontend Pages" ───
add_action('wp_ajax_em_cf_content_search', 'em_cf_content_search_ajax');
function em_cf_content_search_ajax()
{
    check_ajax_referer('em_cf_content_search', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);
    $q     = isset($_POST['q']) ? sanitize_text_field((string) wp_unslash($_POST['q'])) : '';
    $types = array_values(array_filter(array('page', 'post', 'product'), 'post_type_exists'));
    $args  = array(
        'post_type'              => $types,
        'post_status'            => 'publish',
        'posts_per_page'         => 20,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );
    if ($q !== '') {
        $args['s'] = $q;
    } else {
        $args['orderby'] = 'modified';
    }
    $items = array();
    foreach ((new WP_Query($args))->posts as $p) {
        $pto = get_post_type_object($p->post_type);
        $items[] = array(
            'value' => (string) $p->ID,
            'label' => get_the_title($p) ?: __('(no title)', 'chat-forms'),
            'type'  => $pto ? $pto->labels->singular_name : $p->post_type,
        );
    }
    wp_send_json_success(array('items' => $items));
}

// ─── Email Logic metabox: email list AJAX search ─────────────────────────────
add_action('wp_ajax_em_cf_list_search', 'em_cf_list_search_ajax');
function em_cf_list_search_ajax()
{
    check_ajax_referer('em_cf_list_search', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);
    $q   = isset($_POST['q']) ? strtolower(trim(sanitize_text_field((string) wp_unslash($_POST['q'])))) : '';
    $out = array();
    foreach (function_exists('em_get_all_lists') ? em_get_all_lists() : array() as $list) {
        if ($q !== '' && strpos(strtolower((string) $list['name']), $q) === false) continue;
        $out[] = array(
            'id'    => (int) $list['id'],
            'name'  => (string) $list['name'],
            'count' => (int) $list['subscriber_count'],
        );
        if (count($out) >= 25) break;
    }
    wp_send_json_success(array('lists' => $out));
}

// ─── Add every submitter to the form's selected email lists ──────────────────
// Fires for both the chat UI and Agent Messages completions.
add_action('chat_form_submission', 'em_cf_join_email_lists', 10, 3);
function em_cf_join_email_lists($form_id, $answers, $submission_id)
{
    $list_ids = array_filter(array_map('intval', (array) get_post_meta($form_id, '_chat_form_email_lists', true)));
    if (!$list_ids || !function_exists('em_add_subscriber')) return;

    $email = class_exists('EM_Applications') ? EM_Applications::extract_email_from_submission($answers) : '';
    $name  = class_exists('EM_Applications') ? EM_Applications::extract_name_from_submission($answers) : '';

    // Registration steps store {"email": …} as a JSON answer.
    if (!$email && is_array($answers)) {
        foreach ($answers as $val) {
            $raw  = is_array($val) ? ($val['answer'] ?? '') : $val;
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json) && !empty($json['email']) && is_email($json['email'])) {
                $email = sanitize_email($json['email']);
                break;
            }
        }
    }

    // Fall back to the submitter's account.
    $user = null;
    $author_id = (int) get_post_field('post_author', $submission_id);
    if ($author_id) $user = get_userdata($author_id);
    if (!$user && is_user_logged_in()) $user = wp_get_current_user();
    if (!$email && $user) $email = $user->user_email;
    if (!$email || !is_email($email)) return;

    $first = $user ? (string) $user->first_name : '';
    $last  = $user ? (string) $user->last_name : '';
    if ($name !== '') {
        $parts = preg_split('/\s+/', trim($name), 2);
        $first = $parts[0];
        $last  = $parts[1] ?? $last;
    } elseif ($first === '' && $user) {
        $first = (string) $user->display_name;
    }

    // em_add_subscriber() overwrites name + status on existing subscribers —
    // keep theirs so an unsubscribed address isn't silently resubscribed.
    $status = 'subscribed';
    if (function_exists('em_get_subscriber') && ($existing = em_get_subscriber($email))) {
        if (!empty($existing->first_name) || !empty($existing->last_name)) {
            $first = (string) $existing->first_name;
            $last  = (string) $existing->last_name;
        }
        if (!empty($existing->status)) $status = (string) $existing->status;
    }

    em_add_subscriber($email, $first, $last, $list_ids, $status);
}


// ─── Success Message metabox: sequence (Launch Trigger) search ───────────────
// Sequences live on gend.me as BuddyPress groupmeta `_psoo_sequence_workspaces`
// of the group this site belongs to; each workspace's triggers carry the
// LT- codes Chat_Forms_Launch fires. Sub-sites on the gend.me network read
// the groupmeta directly; separately hosted sites ask gend.me's
// em/v1/group-sequences route with the admin's gend.me OAuth token.

/** The gend.me group this site belongs to (0 when it isn't linked). */
function em_cf_site_group_id()
{
    $blog_id = get_current_blog_id();
    $gid = 0;
    if (function_exists('wu_get_site')) {
        try {
            $wu_site = wu_get_site($blog_id);
            if ($wu_site) $gid = (int) $wu_site->get_meta('gdc_bp_group_id', 0);
        } catch (\Throwable $e) {}
    }
    if (!$gid) $gid = (int) get_option('gdc_bp_group_id', 0);
    if (!$gid && is_multisite()) $gid = (int) get_metadata('blog', $blog_id, 'gdc_bp_group_id', true);
    // Separately hosted apps linked through contracts-and-payments.
    if (!$gid && class_exists('Gend_CP_App_Identity')) {
        $linked = Gend_CP_App_Identity::linked_group();
        if (!$linked && method_exists('Gend_CP_App_Identity', 'fetch_binding_from_gendme')) {
            $linked = Gend_CP_App_Identity::fetch_binding_from_gendme();
        }
        if (is_array($linked) && !empty($linked['id'])) $gid = (int) $linked['id'];
    }
    return $gid;
}

/** Every trigger with a launch code in a group's sequence workspaces (read where the groupmeta lives). */
function em_cf_group_sequence_triggers($group_id)
{
    $out = array();
    if (!function_exists('groups_get_groupmeta')) return $out;
    foreach ((array) groups_get_groupmeta((int) $group_id, '_psoo_sequence_workspaces', true) as $ws) {
        $ws = (array) $ws;
        if (!empty($ws['deleted'])) continue;
        $ws_name  = sanitize_text_field((string) ($ws['name'] ?? ''));
        $seq_name = sanitize_text_field((string) ($ws['sequenceName'] ?? ''));
        foreach ((array) ($ws['triggers'] ?? array()) as $tr) {
            $tr = (array) $tr;
            $code = sanitize_text_field((string) ($tr['code'] ?? ''));
            if ($code === '') continue;
            $out[] = array(
                'code'      => $code,
                'name'      => sanitize_text_field((string) ($tr['name'] ?? '')),
                'type'      => sanitize_key((string) ($tr['type'] ?? 'manual')),
                'enabled'   => !empty($tr['enabled']),
                'workspace' => $ws_name,
                'sequence'  => $seq_name !== '' ? $seq_name : $ws_name,
            );
        }
    }
    usort($out, function ($a, $b) {
        return strcasecmp($a['sequence'] . ' ' . $a['name'], $b['sequence'] . ' ' . $b['name']);
    });
    return $out;
}

/** Group id + display name, read locally. */
function em_cf_group_summary($group_id)
{
    $name = '';
    $new_url = '';
    $new_agent_url = '';
    if (function_exists('groups_get_group')) {
        $g = groups_get_group((int) $group_id);
        if ($g && !empty($g->id)) {
            $name = (string) $g->name;
            // Members Hub → Sequences → "+ New Workspace" (deep link handled
            // by projects/assets/psoo-group-members-tabs.js).
            if (function_exists('bp_get_group_permalink')) {
                $hub_url = trailingslashit(bp_get_group_permalink($g)) . 'members-hub/';
                $new_url = add_query_arg(array('gm_tab' => 'sequences', 'gm_new' => 'workspace'), $hub_url);
                $new_agent_url = add_query_arg(array('gm_tab' => 'groups', 'gm_new' => 'agent'), $hub_url);
            }
        }
    }
    return array('id' => (int) $group_id, 'name' => $name, 'new_url' => $new_url, 'new_agent_url' => $new_agent_url);
}

add_action('wp_ajax_em_cf_seq_search', 'em_cf_seq_search_ajax');
function em_cf_seq_search_ajax()
{
    check_ajax_referer('em_cf_seq_search', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(array('message' => 'forbidden'), 403);

    $gid = em_cf_site_group_id();
    if (!$gid) {
        wp_send_json_error(array('message' => __('This site isn\'t linked to a gend.me group yet, so there are no sequences to pick. You can still paste a launch code below.', 'chat-forms')));
    }

    // Sub-site on the gend.me network: the group's meta is right here.
    if (is_multisite() && function_exists('groups_get_group')) {
        $g = groups_get_group($gid);
        if ($g && !empty($g->id)) {
            wp_send_json_success(array(
                'group'    => em_cf_group_summary($gid),
                'triggers' => em_cf_group_sequence_triggers($gid),
                'source'   => 'network',
            ));
        }
    }

    // Separately hosted: ask gend.me with this admin's gend.me token.
    $uid   = get_current_user_id();
    $token = class_exists('EM_Leo_OAuth') ? (string) EM_Leo_OAuth::resolve_user_token($uid) : '';
    if ($token === '' && class_exists('Gend_CP_OAuth_Client') && method_exists('Gend_CP_OAuth_Client', 'bearer_for')) {
        $token = (string) Gend_CP_OAuth_Client::bearer_for($uid);
    }
    if ($token === '') {
        wp_send_json_error(array('message' => __('Connect this site to your gend.me account (Talk Flows → Leo settings) to load your group\'s sequences. You can still paste a launch code below.', 'chat-forms')));
    }

    $hub = class_exists('EM_Leo_OAuth') ? EM_Leo_OAuth::hub_url() : 'https://gend.me';
    $r = wp_remote_get(add_query_arg('group_id', $gid, rtrim($hub, '/') . '/wp-json/em/v1/group-sequences'), array(
        'timeout' => 12,
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
    ));
    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => sprintf(__('Couldn\'t reach gend.me: %s', 'chat-forms'), $r->get_error_message())));
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    $body = json_decode(wp_remote_retrieve_body($r), true);
    if ($code === 401) {
        wp_send_json_error(array('message' => __('Your gend.me connection has expired. Reconnect in Talk Flows → Leo settings, then try again.', 'chat-forms')));
    }
    if ($code !== 200 || !is_array($body) || !isset($body['triggers'])) {
        $msg = is_array($body) && !empty($body['message']) ? (string) $body['message'] : sprintf(__('gend.me returned an error (%d).', 'chat-forms'), $code);
        wp_send_json_error(array('message' => $msg));
    }
    wp_send_json_success(array(
        'group'    => array(
            'id'      => $gid,
            'name'    => sanitize_text_field((string) ($body['group']['name'] ?? '')),
            'new_url' => (function ($url) use ($hub) {
                $url = esc_url_raw((string) $url);
                return ($url !== '' && wp_parse_url($url, PHP_URL_HOST) === wp_parse_url($hub, PHP_URL_HOST)) ? $url : '';
            })($body['group']['new_url'] ?? ''),
        ),
        'triggers' => array_values(array_map(function ($t) {
            return array(
                'code'      => sanitize_text_field((string) ($t['code'] ?? '')),
                'name'      => sanitize_text_field((string) ($t['name'] ?? '')),
                'type'      => sanitize_key((string) ($t['type'] ?? 'manual')),
                'enabled'   => !empty($t['enabled']),
                'workspace' => sanitize_text_field((string) ($t['workspace'] ?? '')),
                'sequence'  => sanitize_text_field((string) ($t['sequence'] ?? '')),
            );
        }, array_filter((array) $body['triggers'], 'is_array'))),
        'source'   => 'remote',
    ));
}

// gend.me side: GET em/v1/group-sequences?group_id= for separately hosted
// sites. Auth is the logged-in session or a gend.me OAuth bearer token; the
// caller must belong to the group (same gate as psoo-pm sequence-workspaces).
add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/group-sequences', array(
        'methods'             => 'GET',
        'callback'            => 'em_cf_rest_group_sequences',
        'permission_callback' => '__return_true',
        'args'                => array('group_id' => array('required' => true, 'type' => 'integer')),
    ));
    register_rest_route('em/v1', '/group-agents', array(
        'methods'             => 'GET',
        'callback'            => 'em_cf_rest_group_agents',
        'permission_callback' => '__return_true',
        'args'                => array('group_id' => array('required' => true, 'type' => 'integer')),
    ));
});
function em_cf_rest_group_sequences(WP_REST_Request $request)
{
    $gid = em_cf_rest_group_gate($request);
    if (is_wp_error($gid)) return $gid;
    return rest_ensure_response(array(
        'group'    => em_cf_group_summary($gid),
        'triggers' => em_cf_group_sequence_triggers($gid),
    ));
}
function em_cf_rest_group_agents(WP_REST_Request $request)
{
    $gid = em_cf_rest_group_gate($request);
    if (is_wp_error($gid)) return $gid;
    return rest_ensure_response(array(
        'group'  => em_cf_group_summary($gid),
        'agents' => em_cf_group_agents($gid),
    ));
}
/** Signed-in (session or gend.me bearer) member of the requested group → its id. */
function em_cf_rest_group_gate(WP_REST_Request $request)
{
    if (!function_exists('groups_get_group') || !function_exists('groups_get_groupmeta')) {
        return new WP_Error('unavailable', 'Groups are not available on this site.', array('status' => 404));
    }
    $uid = get_current_user_id();
    if (!$uid) {
        $auth = (string) $request->get_header('authorization');
        if (stripos($auth, 'Bearer ') === 0 && function_exists('gdc_self_hosted_resolve_oauth_user_id')) {
            $token = trim(substr($auth, 7));
            $ckey  = 'em_cf_bearer_' . md5($token);
            $uid   = (int) get_transient($ckey);
            if (!$uid) {
                $uid = (int) gdc_self_hosted_resolve_oauth_user_id($token);
                if ($uid) set_transient($ckey, $uid, 5 * MINUTE_IN_SECONDS);
            }
        }
    }
    if (!$uid) return new WP_Error('no_token', 'gend.me sign-in required.', array('status' => 401));

    $gid   = (int) $request->get_param('group_id');
    $group = $gid ? groups_get_group($gid) : null;
    if (!$group || empty($group->id)) return new WP_Error('bad_group', 'Group not found.', array('status' => 404));
    if (!user_can($uid, 'manage_options') && !(function_exists('groups_is_user_member') && groups_is_user_member($uid, $gid))) {
        return new WP_Error('forbidden', 'Your gend.me account is not a member of this site\'s group.', array('status' => 403));
    }
    return $gid;
}

/** The AI agents that belong to a gend.me group (read where the group lives). */
function em_cf_group_agents($group_id)
{
    $out = array();
    $group_id = (int) $group_id;
    if (function_exists('psoo_get_group_members_payload')) {
        // Same roster the Members Hub shows: group members flagged as AI agents.
        $payload = psoo_get_group_members_payload($group_id);
        $rows = isset($payload['grouped']['agents']) ? (array) $payload['grouped']['agents'] : array();
    } else {
        $rows = array();
        if (function_exists('groups_get_group_members')) {
            $res = groups_get_group_members(array('group_id' => $group_id, 'per_page' => 500, 'exclude_admins_mods' => false));
            foreach ((array) ($res['members'] ?? array()) as $m) {
                $uid = (int) $m->ID;
                $u = get_userdata($uid);
                if (!$u || !(get_user_meta($uid, '_aipa_is_agent', true) || in_array('ai_agent', (array) $u->roles, true))) continue;
                $rows[] = array('id' => $uid, 'name' => $u->display_name, 'avatar' => get_avatar_url($uid, array('size' => 96)),
                                'title' => '', 'status' => (string) get_user_meta($uid, '_aipa_agent_status', true));
            }
        }
    }
    foreach ($rows as $a) {
        $a = (array) $a;
        $status = strtolower((string) ($a['status'] ?? ''));
        if (in_array($status, array('deactivated', 'inactive', 'disabled'), true)) continue;
        $out[] = array(
            'id'     => (int) ($a['id'] ?? 0),
            'name'   => sanitize_text_field((string) ($a['name'] ?? '')),
            'title'  => sanitize_text_field((string) ($a['title'] ?? '')),
            'avatar' => em_cf_clean_avatar((string) ($a['avatar'] ?? '')),
        );
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $out;
}

/** An avatar URL, or the roster's inline SVG placeholder; anything else → ''. */
function em_cf_clean_avatar($url)
{
    $url = trim((string) $url);
    if (strpos($url, 'data:image/svg+xml') === 0 && strpos($url, '<script') === false && strlen($url) < 4000) return $url;
    return esc_url_raw($url);
}

/** GET a gend.me em/v1 route for this site's group with the admin's gend.me token. */
function em_cf_hub_fetch($route, $group_id)
{
    $uid   = get_current_user_id();
    $token = class_exists('EM_Leo_OAuth') ? (string) EM_Leo_OAuth::resolve_user_token($uid) : '';
    if ($token === '' && class_exists('Gend_CP_OAuth_Client') && method_exists('Gend_CP_OAuth_Client', 'bearer_for')) {
        $token = (string) Gend_CP_OAuth_Client::bearer_for($uid);
    }
    if ($token === '') {
        return new WP_Error('not_connected', __('Connect this site to your gend.me account (Talk Flows → Leo settings) to load your group.', 'chat-forms'));
    }
    $hub = class_exists('EM_Leo_OAuth') ? EM_Leo_OAuth::hub_url() : 'https://gend.me';
    $r = wp_remote_get(add_query_arg('group_id', (int) $group_id, rtrim($hub, '/') . '/wp-json/em/v1/' . $route), array(
        'timeout' => 12,
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
    ));
    if (is_wp_error($r)) {
        return new WP_Error('unreachable', sprintf(__('Couldn\'t reach gend.me: %s', 'chat-forms'), $r->get_error_message()));
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    $body = json_decode(wp_remote_retrieve_body($r), true);
    if ($code === 401) {
        return new WP_Error('expired', __('Your gend.me connection has expired. Reconnect in Talk Flows → Leo settings, then try again.', 'chat-forms'));
    }
    if ($code !== 200 || !is_array($body)) {
        $msg = is_array($body) && !empty($body['message']) ? (string) $body['message'] : sprintf(__('gend.me returned an error (%d).', 'chat-forms'), $code);
        return new WP_Error('hub_error', $msg);
    }
    $new_agent = (string) ($body['group']['new_agent_url'] ?? '');
    $body['group'] = array(
        'id'            => (int) $group_id,
        'name'          => sanitize_text_field((string) ($body['group']['name'] ?? '')),
        'new_agent_url' => ($new_agent !== '' && wp_parse_url($new_agent, PHP_URL_HOST) === wp_parse_url($hub, PHP_URL_HOST)) ? esc_url_raw($new_agent) : '',
    );
    return $body;
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
