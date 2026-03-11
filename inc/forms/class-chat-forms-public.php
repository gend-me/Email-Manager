<?php

class Chat_Forms_Public
{

    public function enqueue_styles()
    {
        wp_enqueue_style('chat_forms_frontend_css', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.css', array(), '1.0.3', 'all');
    }

    public function enqueue_scripts()
    {
        $smtp_settings = get_option('em_smtp_settings', []);
        $recaptcha_site_key = $smtp_settings['recaptcha_site_key'] ?? '';

        wp_enqueue_script('chat_forms_frontend_js', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.js', array('jquery'), '1.3', false);
        wp_localize_script('chat_forms_frontend_js', 'chatFormsPublic', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chat_forms_submit_nonce'),
            'recaptchaSiteKey' => $recaptcha_site_key
        ));

        if ($recaptcha_site_key) {
            wp_enqueue_script('google-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . $recaptcha_site_key, array(), null, false);
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
