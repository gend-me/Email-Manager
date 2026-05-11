<?php

class Chat_Forms_Public
{

    public function enqueue_styles()
    {
        wp_enqueue_style('chat_forms_frontend_css', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.css', array(), '1.3.0', 'all');
        wp_enqueue_style('chat_forms_widget_launcher_css', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.css', array('chat_forms_frontend_css'), '1.3.0', 'all');
    }

    public function enqueue_scripts()
    {
        $smtp_settings = get_option('em_smtp_settings', []);
        $recaptcha_site_key = $smtp_settings['recaptcha_site_key'] ?? '';

        wp_enqueue_script('chat_forms_frontend_js', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.js', array('jquery'), '1.6', false);
        wp_enqueue_script('chat_forms_widget_launcher_js', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.js', array('jquery', 'chat_forms_frontend_js'), '1.0.0', true);
        $current_user_data = array();
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $current_user_data = array(
                'user_id'      => $current_user->ID,
                'username'     => $current_user->user_login,
                'display_name' => $current_user->display_name,
                'avatar_url'   => get_avatar_url($current_user->ID, array('size' => 96)),
                'email'        => $current_user->user_email,
            );
        }

        // Brand palette: per-form colors override; otherwise fall back to global brand option
        $brand_colors = get_option('em_chat_brand_colors', array());
        if (!is_array($brand_colors)) $brand_colors = array();

        wp_localize_script('chat_forms_frontend_js', 'chatFormsPublic', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('chat_forms_submit_nonce'),
            'recaptchaSiteKey' => $recaptcha_site_key,
            'isLoggedIn'       => is_user_logged_in(),
            'currentUser'      => $current_user_data,
            'brand'            => wp_parse_args($brand_colors, array(
                'primary'   => '#6366f1',
                'secondary' => '#8b5cf6',
                'surface'   => 'rgba(15, 23, 42, 0.9)',
                'text'      => '#f8fafc',
            )),
        ));

        if ($recaptcha_site_key) {
            wp_enqueue_script('google-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . $recaptcha_site_key, array(), null, false);
        }
    }

    /* ================================================================
       Widget injection — runs on every public + admin page request and
       picks the chat_form posts targeted to render here.
       ================================================================ */

    public function inject_widgets_frontend()
    {
        if (is_admin()) return;
        $forms = $this->get_widget_forms_for_context('frontend');
        if (empty($forms)) return;
        $this->print_widget_launchers($forms);
    }

    public function inject_widgets_admin()
    {
        $forms = $this->get_widget_forms_for_context('admin');
        if (empty($forms)) return;

        // Need our chat assets on admin pages too
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

        $this->print_widget_launchers($forms);
    }

    private function get_widget_forms_for_context($context)
    {
        $modes_in_widget = array('widget', 'both');
        $forms = get_posts(array(
            'post_type'   => 'chat_form',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => array(
                array(
                    'key'     => '_chat_form_display_mode',
                    'value'   => $modes_in_widget,
                    'compare' => 'IN',
                ),
            ),
        ));
        if (empty($forms)) return array();

        $matches = array();
        foreach ($forms as $form) {
            $pages_meta_key = $context === 'admin' ? '_chat_form_widget_pages_admin' : '_chat_form_widget_pages_frontend';
            $pages = get_post_meta($form->ID, $pages_meta_key, true);
            if (!is_array($pages) || empty($pages)) continue;

            if ($context === 'admin') {
                if (in_array('all', $pages, true)) { $matches[] = $form; continue; }
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                if (!$screen) continue;
                if (in_array('dashboard', $pages, true) && $screen->id === 'dashboard') $matches[] = $form;
                elseif (in_array('plugins', $pages, true) && $screen->id === 'plugins') $matches[] = $form;
                elseif (in_array('users', $pages, true) && in_array($screen->id, array('users', 'user-edit', 'profile'), true)) $matches[] = $form;
                elseif (in_array('tools', $pages, true) && $screen->id === 'tools') $matches[] = $form;
            } else {
                if (in_array('all', $pages, true)) { $matches[] = $form; continue; }
                $matched = false;
                if (in_array('home', $pages, true) && (is_front_page() || is_home())) $matched = true;
                if (in_array('archive', $pages, true) && (is_archive() || is_home())) $matched = true;
                if (in_array('singular', $pages, true) && is_singular()) $matched = true;
                if (!$matched && is_singular()) {
                    $current_id = (string) get_queried_object_id();
                    if (in_array($current_id, array_map('strval', $pages), true)) $matched = true;
                }
                if ($matched) $matches[] = $form;
            }
        }
        return $matches;
    }

    private function print_widget_launchers($forms)
    {
        foreach ($forms as $form) {
            $cfg = get_post_meta($form->ID, '_chat_form_widget_config', true);
            if (!is_array($cfg)) $cfg = array();
            $cfg = wp_parse_args($cfg, array(
                'image_url'     => '',
                'border_color'  => '#6366f1',
                'border_radius' => 16,
                'position'      => 'bottom-right',
            ));
            $bot_avatar = get_post_meta($form->ID, '_chat_form_bot_avatar', true);

            // Get questions for the localized data
            $questions = get_post_meta($form->ID, '_chat_form_questions', true);
            if (!is_array($questions)) $questions = array();

            $form_data = array(
                'id'           => (int) $form->ID,
                'title'        => get_the_title($form->ID),
                'image'        => $cfg['image_url'],
                'borderColor'  => $cfg['border_color'],
                'borderRadius' => (int) $cfg['border_radius'],
                'position'     => $cfg['position'],
                'botAvatar'    => $bot_avatar,
                'questions'    => $questions,
                'thankYou'     => get_post_meta($form->ID, '_chat_form_thank_you_message', true),
            );
            ?>
            <div class="chat-widget-launcher"
                 data-form-id="<?php echo esc_attr($form->ID); ?>"
                 data-position="<?php echo esc_attr($cfg['position']); ?>"
                 data-form='<?php echo esc_attr(wp_json_encode($form_data)); ?>'
                 style="--cw-border-color:<?php echo esc_attr($cfg['border_color']); ?>;--cw-border-radius:<?php echo esc_attr($cfg['border_radius']); ?>px;">
                <button type="button" class="chat-widget-launcher__btn" aria-label="<?php echo esc_attr(get_the_title($form->ID)); ?>">
                    <?php if (!empty($cfg['image_url'])): ?>
                        <img src="<?php echo esc_url($cfg['image_url']); ?>" alt="" />
                    <?php else: ?>
                        <span class="dashicons dashicons-format-chat"></span>
                    <?php endif; ?>
                </button>
            </div>
            <?php
        }
    }

    /**
     * Add dynamic styling based on form settings
     */
    private function get_form_styles($form_id)
    {
        $gradient_start = get_post_meta($form_id, '_chat_form_gradient_start', true) ?: '#667eea';
        $gradient_end = get_post_meta($form_id, '_chat_form_gradient_end', true) ?: '#764ba2';
        $button_style = get_post_meta($form_id, '_chat_form_button_style', true) ?: 'rounded';
        $font_family = get_post_meta($form_id, '_chat_form_font_family', true) ?: 'system';

        // Font family mapping
        $font_map = array(
            'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            'inter' => '"Inter", sans-serif',
            'roboto' => '"Roboto", sans-serif',
            'poppins' => '"Poppins", sans-serif',
            'montserrat' => '"Montserrat", sans-serif'
        );

        // Button style mapping
        $button_radius = array(
            'rounded' => '8px',
            'pill' => '50px',
            'square' => '0px'
        );

        $css = "<style>
            #chat-form-{$form_id} {
                font-family: {$font_map[$font_family]};
            }
            #chat-form-{$form_id} .chat-header {
                background: linear-gradient(135deg, {$gradient_start} 0%, {$gradient_end} 100%);
            }
            #chat-form-{$form_id} .chat-submit-btn,
            #chat-form-{$form_id} .chat-options button.selected {
                background: linear-gradient(135deg, {$gradient_start} 0%, {$gradient_end} 100%);
                border-radius: {$button_radius[$button_style]};
            }
            #chat-form-{$form_id} .chat-input {
                border-radius: {$button_radius[$button_style]};
            }
        </style>";

        // Load fonts from Google Fonts if not system
        if ($font_family !== 'system') {
            $css .= "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">
            <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>
            <link href=\"https://fonts.googleapis.com/css2?family=" . ucfirst($font_family) . ":wght@400;500;600;700&display=swap\" rel=\"stylesheet\">";
        }

        return $css;
    }

    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'chat_form');

        $form_id = intval($atts['id']);
        if (!$form_id)
            return '';

        // Fetch form data (questions)
        $questions = get_post_meta($form_id, '_chat_form_questions', true);
        // We will likely store questions as a JSON array in meta.

        ob_start();

        // Output custom styles
        echo $this->get_form_styles($form_id);
        ?>
        <div id="chat-form-<?php echo $form_id; ?>" class="chat-form-container glassmorphism"
            data-form-id="<?php echo $form_id; ?>">
            <div class="chat-header">
                <h3>
                    <?php echo get_the_title($form_id); ?>
                </h3>
            </div>
            <div class="chat-messages">
                <!-- Messages will be injected here by JS -->
            </div>
            <div class="chat-input-area">
                <input type="text" class="chat-input" placeholder="Type your answer..." />
                <button class="chat-submit-btn">Send</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    public function render_basic_form_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'basic_form');

        $form_id = intval($atts['id']);
        if (!$form_id)
            return '';

        // Fetch custom HTML
        $custom_html = get_post_meta($form_id, '_chat_form_custom_html', true);

        // Fetch Questions
        $questions = get_post_meta($form_id, '_chat_form_questions', true);
        if (!is_array($questions)) {
            $questions = array();
        }

        ob_start();

        // Output custom styles
        echo $this->get_form_styles($form_id);

        // Generate Field HTML
        $fields_html = '';
        foreach ($questions as $index => $q) {
            $type = isset($q['type']) ? $q['type'] : 'text';
            $text = isset($q['text']) ? esc_html($q['text']) : '';
            $required = isset($q['validation']['required']) && $q['validation']['required'] ? 'required' : '';
            $name = 'chat_form_field_' . $index;

            $fields_html .= '<div class="sn-input-group chat-form-field-group">';
            $fields_html .= '<label>' . $text . '</label>';

            if ($type === 'text' || $type === 'email' || $type === 'telephone') {
                $input_type = $type === 'telephone' ? 'tel' : $type;
                $fields_html .= '<input type="' . esc_attr($input_type) . '" name="' . esc_attr($name) . '" ' . $required . ' />';
            } elseif ($type === 'multiple') {
                $fields_html .= '<select name="' . esc_attr($name) . '" ' . $required . ' style="width:100%;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,0.2);padding:12px 0;color:#ffffff;font-family:inherit;">';
                $fields_html .= '<option value="" style="color:#000;">' . __('Select an option...', 'chat-forms') . '</option>';
                if (!empty($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        $opt_label = esc_html($opt['label']);
                        $opt_val = esc_attr($opt['value']);
                        $fields_html .= '<option value="' . $opt_val . '" style="color:#000;">' . $opt_label . '</option>';
                    }
                }
                $fields_html .= '</select>';
            } elseif ($type === 'file') {
                $fields_html .= '<input type="file" name="' . esc_attr($name) . '" ' . $required . ' />';
            }

            $fields_html .= '</div>';
        }

        // Add Submit Button and Hidden Fields
        $nonce_field = wp_create_nonce('chat_forms_submit_nonce');
        $submit_html = '<div class="chat-form-submit-group" style="margin-top: 20px;">
                            <input type="hidden" name="action" value="chat_forms_submit_form" />
                            <input type="hidden" name="form_id" value="' . esc_attr($form_id) . '" />
                            <input type="hidden" name="nonce" value="' . esc_attr($nonce_field) . '" />
                            <button type="submit" class="sn-submit-btn chat-forms-basic-submit">' . __('Submit', 'chat-forms') . '</button>
                        </div>
                        <div class="chat-form-response-message" style="display:none; margin-top:15px; padding:15px; border-radius:4px;"></div>';

        $fields_html .= $submit_html;

        // Process Custom HTML
        $final_html = '';
        if ($custom_html) {
            // Because wp_kses_post strips <form> and <input> tags, we need to allow them if the user builds their own form.
            // We use a custom allowed HTML array or simply output it directly since only admins can save this meta box.
            // Given that this is an admin-controlled setting, we can safely output the raw HTML, or use a very permissive kses.
            // Let's use raw HTML but unslashed, which was done during save. 
            // We'll wrap their form with our class dynamically via JS or they can add it via str_replace if we find a form tag.

            $parsed_custom_html = $custom_html; // Output Raw HTML

            // Check if placeholder exists
            if (strpos($parsed_custom_html, '[form_fields]') !== false) {
                // If they use [form_fields], we swap it. But we also need to catch if they used their own <form> tag
                // If they have their own form tag, we just need to ensure it has our class so JS intercepts.
                $parsed_custom_html = str_replace('<form ', '<form class="chat-forms-basic-form" data-form-id="' . $form_id . '" ', $parsed_custom_html);

                $final_html = str_replace('[form_fields]', $fields_html, $parsed_custom_html);
            } else {
                // If no placeholder, see if they used a form tag anyway
                if (strpos($parsed_custom_html, '<form') !== false) {
                    $parsed_custom_html = str_replace('<form ', '<form class="chat-forms-basic-form" data-form-id="' . $form_id . '" ', $parsed_custom_html);
                    $final_html = $parsed_custom_html;

                    // Inject nonce somewhere inside the form
                    $final_html = str_replace('</form>', '<input type="hidden" name="action" value="chat_forms_submit_form" /><input type="hidden" name="form_id" value="' . esc_attr($form_id) . '" /><input type="hidden" name="nonce" value="' . esc_attr($nonce_field) . '" /></form>', $final_html);
                } else {
                    $final_html = $parsed_custom_html . '<form class="chat-forms-basic-form" data-form-id="' . $form_id . '" style="margin-top:30px;">' . $fields_html . '</form>';
                }
            }
        } else {
            // No custom HTML, output standard wrapper
            $final_html = '
            <div id="basic-form-' . $form_id . '" class="basic-form-container glassmorphism" data-form-id="' . $form_id . '">
                <div class="basic-form-header">
                    <h3>' . get_the_title($form_id) . '</h3>
                </div>
                <form class="chat-forms-basic-form" data-form-id="' . $form_id . '">
                    ' . $fields_html . '
                </form>
            </div>';
        }

        echo $final_html;

        return ob_get_clean();
    }
}
