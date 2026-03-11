<?php

class Chat_Forms_Ajax
{

    public function __construct()
    {
        add_action('wp_ajax_chat_forms_get_questions', array($this, 'get_questions'));
        add_action('wp_ajax_nopriv_chat_forms_get_questions', array($this, 'get_questions'));

        add_action('wp_ajax_chat_forms_submit_entry', array($this, 'submit_entry'));
        add_action('wp_ajax_nopriv_chat_forms_submit_entry', array($this, 'submit_entry'));
    }

    public function get_questions()
    {
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
        }

        $questions = get_post_meta($form_id, '_chat_form_questions', true);
        $thank_you_message = get_post_meta($form_id, '_chat_form_thank_you_message', true);
        $redirect_url = get_post_meta($form_id, '_chat_form_redirect_url', true);

        // Sanitize and structure for frontend
        $formatted_questions = array();
        if (!is_array($questions)) {
            $questions = array();
        }
        if (is_array($questions) && !empty($questions)) {
            foreach ($questions as $i => $q) {
                $questionData = array(
                    'id' => $i,
                    'text' => isset($q['text']) ? $q['text'] : '',
                    'type' => isset($q['type']) ? $q['type'] : 'text',
                    'options' => array(),
                    'conditional' => isset($q['conditional']) ? $q['conditional'] : array(),
                    'validation' => isset($q['validation']) ? $q['validation'] : array()
                );

                // Handle options - support both old string format and new array format
                if (isset($q['options'])) {
                    if (is_array($q['options']) && !empty($q['options'])) {
                        // New format: array of objects with label/value/image
                        foreach ($q['options'] as $opt) {
                            if (is_array($opt)) {
                                // New format: array with label, value, and optional image
                                $questionData['options'][] = array(
                                    'label' => isset($opt['label']) ? $opt['label'] : '',
                                    'value' => isset($opt['value']) ? $opt['value'] : '',
                                    'image' => isset($opt['image']) ? $opt['image'] : ''
                                );
                            } else {
                                // Fallback for unexpected array element, treat as simple option
                                $questionData['options'][] = array(
                                    'label' => $opt,
                                    'value' => strtolower(str_replace(' ', '_', $opt)),
                                    'image' => ''
                                );
                            }
                        }
                    } elseif (is_string($q['options'])) {
                        // Legacy format: comma-separated string
                        $opts = explode(',', $q['options']);
                        foreach ($opts as $opt) {
                            $opt = trim($opt);
                            if ($opt) {
                                $questionData['options'][] = array(
                                    'label' => $opt,
                                    'value' => strtolower(str_replace(' ', '_', $opt)),
                                    'image' => ''
                                );
                            }
                        }
                    }
                }

                $response[] = $questionData;
                $formatted_questions[] = $questionData;
            }
        } else {
            // Fallback if no questions
            $formatted_questions[] = array('id' => 0, 'text' => 'Hello! How can I help you?', 'type' => 'text', 'options' => array());
        }

        wp_send_json_success(array(
            'questions' => $formatted_questions,
            'thank_you_message' => $thank_you_message,
            'redirect_url' => $redirect_url
        ));
    }

    public function submit_entry()
    {
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $answers = isset($_POST['answers']) ? $_POST['answers'] : array();

        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
        }

        // reCAPTCHA verification
        $recaptcha_enabled = get_post_meta($form_id, '_chat_form_enable_recaptcha', true) === '1';
        if ($recaptcha_enabled) {
            $smtp_settings = get_option('em_smtp_settings', []);
            $secret_key = $smtp_settings['recaptcha_secret_key'] ?? '';
            $token = isset($_POST['recaptcha_token']) ? sanitize_text_field($_POST['recaptcha_token']) : '';

            if (empty($secret_key)) {
                // If enabled but no secret key, we might want to log this or fail-safe?
                // Let's allow but log for now to avoid breaking things if admin forgot keys.
                // Actually, security-wise it's better to block or warn. 
                // Let's block to enforce security if they took the time to enable it.
                wp_send_json_error('reCAPTCHA secret key is not configured.');
            }

            if (empty($token)) {
                wp_send_json_error('reCAPTCHA verification failed (Token missing).');
            }

            $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
                'body' => array(
                    'secret' => $secret_key,
                    'response' => $token,
                    'remoteip' => $_SERVER['REMOTE_ADDR']
                )
            ));

            if (is_wp_error($response)) {
                wp_send_json_error('reCAPTCHA verification service unreachable.');
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!$body || !isset($body['success']) || !$body['success'] || $body['score'] < 0.5) {
                wp_send_json_error('reCAPTCHA verification failed (Bot detected or invalid token).');
            }
        }

        $form_title = get_the_title($form_id);

        // Create Submission
        $post_data = array(
            'post_title' => 'Submission for ' . $form_title . ' - ' . date('Y-m-d H:i:s'),
            'post_type' => 'chat_submission',
            'post_status' => 'publish'
        );
        $submission_id = wp_insert_post($post_data);

        if (is_wp_error($submission_id)) {
            wp_send_json_error('Could not save submission');
        }

        // Save answers as meta
        update_post_meta($submission_id, '_chat_submission_form_id', $form_id);
        update_post_meta($submission_id, '_chat_submission_data', $answers);

        // Trigger Email Notifications
        $this->send_notifications($form_id, $answers, $submission_id);

        $thank_you_message = get_post_meta($form_id, '_chat_form_thank_you_message', true);
        $redirect_url = get_post_meta($form_id, '_chat_form_redirect_url', true);

        wp_send_json_success(array(
            'submission_id' => $submission_id,
            'message' => $thank_you_message,
            'redirect_url' => $redirect_url
        ));
    }

    private function send_notifications($form_id, $answers, $submission_id)
    {
        $email_rules = get_post_meta($form_id, '_chat_form_email_rules', true);

        // Check for new Email Rules system
        if (is_array($email_rules) && !empty($email_rules)) {
            $this->process_email_rules($form_id, $answers, $submission_id, $email_rules);
            return;
        }

        // Fallback to Legacy Logic
        $notification_email = get_post_meta($form_id, '_chat_form_notification_email', true);
        if ($notification_email) {
            $email_to = explode(',', str_replace(' ', '', $notification_email));
            $answers_data = get_post_meta($submission_id, '_chat_submission_data', true);

            // Get email settings
            $email_subject = get_post_meta($form_id, '_chat_form_email_subject', true);
            $email_cc = get_post_meta($form_id, '_chat_form_email_cc', true);
            $email_bcc = get_post_meta($form_id, '_chat_form_email_bcc', true);
            $send_confirmation = get_post_meta($form_id, '_chat_form_send_confirmation', true);

            if (!$email_subject) {
                $email_subject = 'New Form Submission';
            }

            // Build HTML email
            $message = $this->build_html_email($answers_data, get_the_title($form_id));

            // Set headers for HTML email
            $headers = array('Content-Type: text/html; charset=UTF-8');

            // Add CC
            if ($email_cc) {
                $cc_emails = explode(',', str_replace(' ', '', $email_cc));
                foreach ($cc_emails as $cc) {
                    if (is_email($cc)) {
                        $headers[] = 'Cc: ' . $cc;
                    }
                }
            }

            // Add BCC
            if ($email_bcc) {
                $bcc_emails = explode(',', str_replace(' ', '', $email_bcc));
                foreach ($bcc_emails as $bcc) {
                    if (is_email($bcc)) {
                        $headers[] = 'Bcc: ' . $bcc;
                    }
                }
            }

            // Send admin notification
            wp_mail($email_to, $email_subject, $message, $headers);

            // Send user confirmation if enabled
            if ($send_confirmation && is_array($answers_data)) {
                $user_email = $this->extract_user_email($answers_data);
                if ($user_email) {
                    $confirmation_message = $this->build_confirmation_email($answers_data, get_the_title($form_id));
                    wp_mail($user_email, 'Confirmation: ' . $email_subject, $confirmation_message, $headers);
                }
            }
        }
    }

    /**
     * Process new rule-based email system
     */
    private function process_email_rules($form_id, $answers, $submission_id, $rules)
    {
        $answers_data = get_post_meta($submission_id, '_chat_submission_data', true);
        $user_email = $this->extract_user_email($answers_data);
        $form_title = get_the_title($form_id);

        // Prepare replacement data
        $replacements = array(
            '{form_name}' => $form_title,
            '{user_email}' => $user_email,
            '{submission_id}' => $submission_id,
            '{date}' => date(get_option('date_format')),
            '{time}' => date(get_option('time_format')),
            '{all_fields}' => $this->build_html_email($answers_data, $form_title)
        );

        // Add question placeholders (e.g. {question_1}, {question_2})
        if (is_array($answers_data)) {
            foreach ($answers_data as $index => $answer) {
                if (is_array($answer)) {
                    $val = isset($answer['answer']) ? $answer['answer'] : '';
                } else {
                    $val = (string) $answer;
                }

                if (is_numeric($index)) {
                    $qNum = $index + 1;
                    $replacements['{question_' . $qNum . '}'] = $val;
                } else {
                    $replacements['{' . $index . '}'] = $val; // Supports {project_type}, etc.
                }
            }
        }

        foreach ($rules as $rule) {
            $to = $this->process_placeholders($rule['to'], $replacements);
            $cc = isset($rule['cc']) ? $this->process_placeholders($rule['cc'], $replacements) : '';
            $bcc = isset($rule['bcc']) ? $this->process_placeholders($rule['bcc'], $replacements) : '';
            $subject = isset($rule['subject']) ? $this->process_placeholders($rule['subject'], $replacements) : 'New Submission';
            $body_template = isset($rule['body']) ? $rule['body'] : '';

            // If body is empty, use default table
            if (empty(trim($body_template))) {
                $message = $this->build_html_email($answers_data, $form_title);
            } else {
                $message = $this->process_placeholders($body_template, $replacements);
                // If {all_fields} is used, we inject the table.
                if (strpos($message, '{all_fields}') !== false) {
                    $qa_html = '';
                    if (is_array($answers_data)) {
                        foreach ($answers_data as $key => $answer) {
                            if (is_array($answer)) {
                                $q = isset($answer['question']) ? esc_html($answer['question']) : esc_html($key);
                                $a = isset($answer['answer']) ? esc_html($answer['answer']) : '';
                            } else {
                                $q = is_numeric($key) ? 'Question ' . (intval($key) + 1) : ucwords(str_replace('_', ' ', $key));
                                $a = esc_html((string) $answer);
                            }
                            $qa_html .= "<div class='qa-item' style='background:white;padding:15px;margin-bottom:15px;border-radius:6px;border-left:4px solid #667eea;'>
                                            <div class='question' style='font-weight:bold;color:#667eea;'>$q</div>
                                            <div class='answer'>$a</div>
                                         </div>";
                        }
                    }
                    $message = str_replace('{all_fields}', $qa_html, $message);
                }
            }

            // Headers
            $headers = array('Content-Type: text/html; charset=UTF-8');
            if ($cc) {
                foreach (explode(',', $cc) as $c)
                    if (is_email(trim($c)))
                        $headers[] = 'Cc: ' . trim($c);
            }
            if ($bcc) {
                foreach (explode(',', $bcc) as $b)
                    if (is_email(trim($b)))
                        $headers[] = 'Bcc: ' . trim($b);
            }

            // Send
            $recipients = explode(',', $to);
            foreach ($recipients as $recipient) {
                $recipient = trim($recipient);
                if (is_email($recipient)) {
                    wp_mail($recipient, $subject, $message, $headers);
                }
            }
        }
    }

    private function process_placeholders($content, $replacements)
    {
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function extract_user_email($answers_data)
    {
        if (is_array($answers_data)) {
            foreach ($answers_data as $answer) {
                if (is_array($answer) && isset($answer['answer'])) {
                    $val = $answer['answer'];
                } else {
                    $val = (string) $answer;
                }

                if (is_email($val)) {
                    return $val;
                }
            }
        }
        return '';
    }

    /**
     * Build HTML email template
     */
    private function build_html_email($answers, $form_name)
    {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .qa-item { background: white; padding: 15px; margin-bottom: 15px; border-radius: 6px; border-left: 4px solid #667eea; }
                .question { font-weight: bold; color: #667eea; margin-bottom: 5px; }
                .answer { color: #333; }
                .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>📝 New Form Submission</h2>
                    <p style="margin: 0;">' . esc_html($form_name) . '</p>
                </div>
                <div class="content">';

        if (is_array($answers)) {
            foreach ($answers as $key => $answer) {
                if (is_array($answer)) {
                    $question = isset($answer['question']) ? esc_html($answer['question']) : esc_html($key);
                    $ans = isset($answer['answer']) ? esc_html($answer['answer']) : '';
                } else {
                    $question = is_numeric($key) ? 'Question ' . (intval($key) + 1) : ucwords(str_replace('_', ' ', $key));
                    $ans = esc_html((string) $answer);
                }
                $html .= '
                    <div class="qa-item">
                        <div class="question">' . esc_html($question) . '</div>
                        <div class="answer">' . $ans . '</div>
                    </div>';
            }
        }

        $html .= '
                </div>
                <div class="footer">
                    <p>Sent via Chat Forms Plugin</p>
                    <p>' . date('F j, Y \a\t g:i a') . '</p>
                </div>
            </div>
        </body>
        </html>';

        return $html;
    }

    /**
     * Build user confirmation email
     */
    private function build_confirmation_email($answers, $form_name)
    {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .success-icon { font-size: 48px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="success-icon">✅</div>
                    <h2>Thank You!</h2>
                    <p style="margin: 0;">Your response has been received</p>
                </div>
                <div class="content">
                    <p>Thank you for completing <strong>' . esc_html($form_name) . '</strong>.</p>
                    <p>Your submission has been recorded successfully. If you have any questions, please don\'t hesitate to contact us.</p>
                </div>
            </div>
        </body>
        </html>';

        return $html;
    }
}
