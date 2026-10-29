<?php
if (!function_exists('gend_recaptcha_required')) {
    /**
     * reCAPTCHA is only for visitors we can't trust yet: logged out, or logged in with the
     * WP Ultimo email verification still pending. Verified members never load or answer it.
     */
    function gend_recaptcha_required()
    {
        if (!is_user_logged_in()) return (bool) apply_filters('gend_recaptcha_required', true, 0);
        $uid = get_current_user_id();
        $pending = false;
        if (function_exists('wu_get_customer_by_user_id') && ($c = wu_get_customer_by_user_id($uid)) && method_exists($c, 'get_email_verification')) {
            $pending = $c->get_email_verification() === 'pending';
        }
        return (bool) apply_filters('gend_recaptcha_required', $pending, $uid);
    }
}


class Chat_Forms_Ajax
{

    public function __construct()
    {
        add_action('wp_ajax_chat_forms_get_questions', array($this, 'get_questions'));
        add_action('wp_ajax_nopriv_chat_forms_get_questions', array($this, 'get_questions'));

        add_action('wp_ajax_chat_forms_submit_entry', array($this, 'submit_entry'));
        add_action('wp_ajax_nopriv_chat_forms_submit_entry', array($this, 'submit_entry'));

        add_action('wp_ajax_nopriv_chat_forms_account_login', array($this, 'account_login'));
        add_action('wp_ajax_chat_forms_account_login', array($this, 'account_login'));
    }

    /**
     * Handle login AJAX request from account_registration question type.
     */
    public function account_login()
    {
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($username) || empty($password)) {
            wp_send_json_error('Please enter your username and password.');
        }

        $creds = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        );

        $user = wp_signon($creds, is_ssl());

        if (is_wp_error($user)) {
            wp_send_json_error('Invalid username or password. Please try again.');
        }

        wp_send_json_success(array(
            'user_id'      => $user->ID,
            'username'     => $user->user_login,
            'display_name' => $user->display_name,
        ));
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
                    'validation' => isset($q['validation']) ? $q['validation'] : array(),
                    // New question-type payload
                    'content'       => isset($q['content']) ? $q['content'] : '',
                    'prompt'        => isset($q['prompt'])  ? $q['prompt']  : '',
                    'pays'          => isset($q['pays'])          ? $q['pays']          : 'site',
                    'pays_user_id'  => isset($q['pays_user_id'])  ? (int) $q['pays_user_id'] : 0,
                    'terms'         => isset($q['terms']) && is_array($q['terms']) ? $q['terms'] : array(),
                );

                // Handle options - support both old string format and new array format
                if (isset($q['options'])) {
                    if (is_array($q['options']) && !empty($q['options'])) {
                        // New format: array of objects with label/value/image
                        foreach ($q['options'] as $opt) {
                            if (is_array($opt)) {
                                // New format: array with label, value, and optional image
                                $questionData['options'][] = array(
                                    'label'         => isset($opt['label']) ? $opt['label'] : '',
                                    'value'         => isset($opt['value']) ? $opt['value'] : '',
                                    'image'         => isset($opt['image']) ? $opt['image'] : '',
                                    'response_html' => isset($opt['response_html']) ? $opt['response_html'] : '',
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
            'redirect_url' => $redirect_url,
            'bot_avatar' => function_exists('em_cf_bot_avatar') ? em_cf_bot_avatar($form_id) : ''
        ));
    }

    /**
     * When the Sales Team plugin is active and the visitor has no referral
     * cookie, read its programs' URL params (e.g. ?ref=12) from the chat page
     * (the AJAX request's same-site Referer) and seed the cookie values the
     * plugin's own user_register/checkout hooks read, so its rules decide.
     */
    private function seed_affiliate_referral()
    {
        // The site's own program may use per-site cookie names (aas_ck()).
        $ck_ref  = function_exists('aas_ck') ? aas_ck('aas_referrer_id') : 'aas_referrer_id';
        $ck_prog = function_exists('aas_ck') ? aas_ck('aas_referrer_program_id') : 'aas_referrer_program_id';
        if (!function_exists('aas_get_referral_programs') || !empty($_COOKIE[$ck_ref])) {
            return;
        }
        $page = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ($page === '' || wp_parse_url($page, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return;
        }
        parse_str((string) wp_parse_url($page, PHP_URL_QUERY), $query);
        foreach (aas_get_referral_programs() as $program) {
            $param = isset($program['url_param']) ? (string) $program['url_param'] : '';
            if ($param === '' || !isset($query[$param])) {
                continue;
            }
            $ref_id = (int) $query[$param];
            if ($ref_id <= 0 || !get_userdata($ref_id)) {
                return;
            }
            $_COOKIE[$ck_ref]  = (string) $ref_id;
            $_COOKIE[$ck_prog] = (string) $program['id'];
            // Persist it too, so a later checkout is credited the same way.
            $expires = time() + DAY_IN_SECONDS * (int) ($program['cookie_duration'] ?? 30);
            setcookie($ck_ref, (string) $ref_id, $expires, COOKIEPATH, COOKIE_DOMAIN);
            setcookie($ck_prog, (string) $program['id'], $expires, COOKIEPATH, COOKIE_DOMAIN);
            return; // first matching program wins, same as the plugin
        }
    }

    public function submit_entry()
    {
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $answers = isset($_POST['answers']) ? $_POST['answers'] : array();

        if (!$form_id) {
            wp_send_json_error('Invalid form ID');
        }

        // Security 2026-10-18: only real, published chat forms accept submissions, and
        // the form nonce printed by Chat_Forms_Public (chatFormsPublic.nonce / hidden
        // "nonce" input) must be valid.
        if (get_post_type($form_id) !== 'chat_form' || get_post_status($form_id) !== 'publish') {
            wp_send_json_error('Invalid form ID');
        }
        if (!check_ajax_referer('chat_forms_submit_nonce', 'nonce', false)) {
            wp_send_json_error('Your session expired. Please reload the page and try again.');
        }
        if (!is_array($answers)) {
            $answers = array();
        }

        // reCAPTCHA verification
        // Security 2026-10-18: enforce for every visitor gend_recaptcha_required() flags
        // (logged out / unverified) whenever the site has reCAPTCHA keys, not only when
        // the per-form toggle is on. The frontend already sends a token whenever the
        // site key is configured and reCAPTCHA is required.
        $em_rc_settings = get_option('em_smtp_settings', []);
        $em_rc_keys = !empty($em_rc_settings['recaptcha_site_key']) && !empty($em_rc_settings['recaptcha_secret_key']);
        $recaptcha_enabled = gend_recaptcha_required()
            && (get_post_meta($form_id, '_chat_form_enable_recaptcha', true) === '1' || $em_rc_keys);
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

        // Handle account_registration question type — process new user registration before saving submission
        $registration_key  = null;
        $registration_data = null;

        foreach ($answers as $key => $raw_answer) {
            $answer_str = is_array($raw_answer) ? (isset($raw_answer['answer']) ? $raw_answer['answer'] : '') : (string) $raw_answer;
            // $_POST is slashed by WordPress — unslash or the JSON never decodes.
            $decoded    = json_decode(wp_unslash($answer_str), true);

            if (is_array($decoded) && isset($decoded['action']) && $decoded['action'] === 'register') {
                $registration_key  = $key;
                $registration_data = $decoded;
                break;
            }
        }

        // Security 2026-10-18: only create/log in accounts when the form really has an
        // Account Registration step, the visitor is logged out, and the site allows
        // registration (multisite: network "registration" setting user/all).
        if ($registration_data) {
            $em_form_registers = false;
            foreach ((array) get_post_meta($form_id, '_chat_form_questions', true) as $em_fq) {
                if (is_array($em_fq) && ($em_fq['type'] ?? '') === 'account_registration') {
                    $em_form_registers = true;
                    break;
                }
            }
            if (is_multisite()) {
                $em_site_registers = in_array(get_site_option('registration'), array('user', 'all'), true);
            } else {
                $em_site_registers = (bool) get_option('users_can_register');
            }
            if (!$em_form_registers || is_user_logged_in()) {
                wp_send_json_error('Registration is not available on this form.');
            }
            if (!$em_site_registers) {
                wp_send_json_error('New account registration is currently disabled on this site.');
            }
        }

        if ($registration_data) {
            $reg_username = sanitize_user($registration_data['username'] ?? '');
            $reg_email    = sanitize_email($registration_data['email'] ?? '');
            $reg_password = $registration_data['password'] ?? '';

            if (empty($reg_username) || empty($reg_email) || empty($reg_password)) {
                wp_send_json_error('Registration data is incomplete.');
            }

            if (username_exists($reg_username)) {
                wp_send_json_error('That username is already taken. Please choose a different one.');
            }

            if (email_exists($reg_email)) {
                wp_send_json_error('An account with that email address already exists.');
            }

            // Terms & conditions set on the form's Account Registration step
            // must be accepted before the account is created.
            $reg_terms = null;
            foreach ((array) get_post_meta($form_id, '_chat_form_questions', true) as $fq) {
                if (is_array($fq) && ($fq['type'] ?? '') === 'account_registration' && !empty($fq['terms']['enabled'])) {
                    $reg_terms = $fq['terms'];
                    break;
                }
            }
            if ($reg_terms && empty($registration_data['terms_accepted'])) {
                wp_send_json_error('Please accept the terms and conditions to create your account.');
            }

            // Sales Team affiliate links: its user_register hook assigns the new
            // account to the referrer from the aas_referrer_id cookie. If that
            // cookie never got set (cached page, blocked cookie) fall back to the
            // referral param on the page the chat runs on.
            $this->seed_affiliate_referral();

            $user_id = wp_create_user($reg_username, $reg_password, $reg_email);

            if (is_wp_error($user_id)) {
                wp_send_json_error($user_id->get_error_message());
            }

            if ($reg_terms) {
                update_user_meta($user_id, '_chat_form_terms_accepted', array(
                    'form_id' => (int) $form_id,
                    'at'      => current_time('mysql'),
                    'mode'    => $reg_terms['mode'] ?? 'popup',
                    'url'     => $reg_terms['url'] ?? '',
                    // Fingerprint of the exact popup text that was accepted.
                    'hash'    => md5((string) ($reg_terms['content'] ?? '')),
                ));
            }

            // Send new-user notifications (admin + welcome email to user)
            wp_new_user_notification($user_id, null, 'both');

            // Log the new user in for the current request
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);

            // Replace the answer with sanitized data — strip the password from stored submissions
            $safe_reg_answer = json_encode(array(
                'action'   => 'registered',
                'user_id'  => $user_id,
                'username' => $reg_username,
                'email'    => $reg_email,
            ));

            if (is_array($answers[$registration_key])) {
                $answers[$registration_key]['answer'] = $safe_reg_answer;
            } else {
                $answers[$registration_key] = $safe_reg_answer;
            }
        }

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

        /**
         * Action fired after a chat form is successfully submitted.
         * 
         * @param int   $form_id       The ID of the form.
         * @param array $answers       The array of submitted answers.
         * @param int   $submission_id The ID of the created submission post.
         */
        do_action('chat_form_submission', $form_id, $answers, $submission_id);

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
