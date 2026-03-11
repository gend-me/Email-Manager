<?php
/**
 * Email SMTP Settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class EM_Email_SMTP
{

    private const OPTION_SMTP = 'em_smtp_settings';

    public function __construct()
    {
        add_action('wp_ajax_em_save_smtp', [$this, 'handle_save_smtp']);
        add_action('wp_ajax_em_test_smtp', [$this, 'handle_test_smtp']);

        // Hooks to override wp_mail behavior - High priority to override other plugins
        add_action('phpmailer_init', [$this, 'configure_phpmailer'], 999);
        add_filter('wp_mail_from', [$this, 'get_from_email'], 999);
        add_filter('wp_mail_from_name', [$this, 'get_from_name'], 999);
    }

    public function configure_phpmailer($phpmailer)
    {
        $settings = get_option(self::OPTION_SMTP, []);
        if (empty($settings)) {
            $settings = get_option('gdc_smtp_settings', []);
        }

        if (($settings['enabled'] ?? 'no') !== 'yes') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $settings['host'] ?? '';
        $phpmailer->SMTPAuth = true;
        $phpmailer->Port = $settings['port'] ?? 587;
        $phpmailer->Username = $settings['username'] ?? '';
        $phpmailer->Password = $settings['password'] ?? '';
        $phpmailer->SMTPSecure = ($settings['encryption'] ?? 'tls') === 'none' ? '' : ($settings['encryption'] ?? 'tls');
        $phpmailer->From = $settings['from_email'] ?? get_option('admin_email');
        $phpmailer->FromName = $settings['from_name'] ?? get_bloginfo('name');

        // Ensure the envelope from matches
        $phpmailer->Sender = $phpmailer->From;

        // Disable debug output in AJAX responses to prevent breaking JSON
        $phpmailer->SMTPDebug = 0;
    }

    public function get_from_email($original_email)
    {
        $settings = get_option(self::OPTION_SMTP, []);
        if (empty($settings)) {
            $settings = get_option('gdc_smtp_settings', []);
        }
        return $settings['from_email'] ?? $original_email;
    }

    public function get_from_name($original_name)
    {
        $settings = get_option(self::OPTION_SMTP, []);
        if (empty($settings)) {
            $settings = get_option('gdc_smtp_settings', []);
        }
        return $settings['from_name'] ?? $original_name;
    }

    public static function render_smtp_tab()
    {
        $settings = get_option(self::OPTION_SMTP, []);
        // Fallback to GDC settings if empty
        if (empty($settings))
            $settings = get_option('gdc_smtp_settings', []);

        ?>
        <div class="gdc-email-panel">
            <h3>
                <?php esc_html_e('Sending Settings', 'email-manager'); ?>
            </h3>
            <p class="description">
                <?php esc_html_e('Configure sender identity and SMTP.', 'email-manager'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><label>
                            <?php esc_html_e('From Name', 'email-manager'); ?>
                        </label></th>
                    <td><input type="text" id="em-smtp-from-name" class="regular-text"
                            value="<?php echo esc_attr($settings['from_name'] ?? get_bloginfo('name')); ?>"></td>
                </tr>
                <tr>
                    <th><label>
                            <?php esc_html_e('From Email', 'email-manager'); ?>
                        </label></th>
                    <td><input type="email" id="em-smtp-from-email" class="regular-text"
                            value="<?php echo esc_attr($settings['from_email'] ?? get_option('admin_email')); ?>"></td>
                </tr>
                <tr>
                    <th><label>
                            <?php esc_html_e('Method', 'email-manager'); ?>
                        </label></th>
                    <td>
                        <select id="em-smtp-method">
                            <option value="wp">
                                <?php esc_html_e('WordPress Default', 'email-manager'); ?>
                            </option>
                            <option value="smtp" <?php selected($settings['enabled'] ?? '', 'yes'); ?>>
                                <?php esc_html_e('SMTP', 'email-manager'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </table>

            <div id="em-smtp-options" style="<?php echo ($settings['enabled'] ?? '') === 'yes' ? '' : 'display:none;'; ?>">
                <h4>
                    <?php esc_html_e('SMTP Configuration', 'email-manager'); ?>
                </h4>
                <table class="form-table">
                    <tr>
                        <th><label>
                                <?php esc_html_e('Host', 'email-manager'); ?>
                            </label></th>
                        <td><input type="text" id="em-smtp-host" class="regular-text"
                                value="<?php echo esc_attr($settings['host'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>
                                <?php esc_html_e('Port', 'email-manager'); ?>
                            </label></th>
                        <td><input type="number" id="em-smtp-port" class="small-text"
                                value="<?php echo esc_attr($settings['port'] ?? '587'); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>
                                <?php esc_html_e('Encryption', 'email-manager'); ?>
                            </label></th>
                        <td>
                            <select id="em-smtp-enc">
                                <option value="tls" <?php selected($settings['encryption'] ?? '', 'tls'); ?>>TLS</option>
                                <option value="ssl" <?php selected($settings['encryption'] ?? '', 'ssl'); ?>>SSL</option>
                                <option value="none" <?php selected($settings['encryption'] ?? '', 'none'); ?>>None</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>
                                <?php esc_html_e('Username', 'email-manager'); ?>
                            </label></th>
                        <td><input type="text" id="em-smtp-user" class="regular-text"
                                value="<?php echo esc_attr($settings['username'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>
                                <?php esc_html_e('Password', 'email-manager'); ?>
                            </label></th>
                        <td><input type="password" id="em-smtp-pass" class="regular-text"
                                value="<?php echo esc_attr($settings['password'] ?? ''); ?>"></td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button" id="em-test-smtp">
                        <?php esc_html_e('Test Connection', 'email-manager'); ?>
                    </button>
                    <span id="em-test-result"></span>
                </p>
            </div>

            <div id="em-recaptcha-options" class="gdc-security-section">
                <h4>
                    <?php esc_html_e('Google reCAPTCHA v3', 'email-manager'); ?>
                </h4>
                <p class="description">
                    <?php esc_html_e('Used for spam protection in forms.', 'email-manager'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><label>
                                <?php esc_html_e('Site Key', 'email-manager'); ?>
                            </label></th>
                        <td><input type="text" id="em-recaptcha-site-key" class="regular-text"
                                value="<?php echo esc_attr($settings['recaptcha_site_key'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>
                                <?php esc_html_e('Secret Key', 'email-manager'); ?>
                            </label></th>
                        <td><input type="password" id="em-recaptcha-secret-key" class="regular-text"
                                value="<?php echo esc_attr($settings['recaptcha_secret_key'] ?? ''); ?>"></td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="button" class="button button-primary" id="em-save-smtp">
                    <?php esc_html_e('Save Settings', 'email-manager'); ?>
                </button>
            </p>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('#em-smtp-method').change(function () {
                    if ($(this).val() === 'smtp') $('#em-smtp-options').slideDown();
                    else $('#em-smtp-options').slideUp();
                });

                $('#em-save-smtp').click(function () {
                    var btn = $(this);
                    btn.prop('disabled', true).text('Saving...');
                    var data = {
                        action: 'em_save_smtp',
                        nonce: '<?php echo wp_create_nonce('em_smtp_nonce'); ?>',
                        from_name: $('#em-smtp-from-name').val(),
                        from_email: $('#em-smtp-from-email').val(),
                        enabled: $('#em-smtp-method').val() === 'smtp' ? 'yes' : 'no',
                        host: $('#em-smtp-host').val(),
                        port: $('#em-smtp-port').val(),
                        encryption: $('#em-smtp-enc').val(),
                        username: $('#em-smtp-user').val(),
                        password: $('#em-smtp-pass').val(),
                        recaptcha_site_key: $('#em-recaptcha-site-key').val(),
                        recaptcha_secret_key: $('#em-recaptcha-secret-key').val()
                    };
                    $.post(ajaxurl, data, function (res) {
                        btn.prop('disabled', false).text('Save Settings');
                        if (res.success) alert('Settings Saved');
                        else alert('Error saving settings');
                    });
                });

                $('#em-test-smtp').click(function () {
                    console.log('Test SMTP button clicked');
                    var email = prompt("Enter email to send test to:");
                    console.log('Prompt result:', email);
                    if (email === null) return; // Cancelled
                    if (!email) {
                        alert("Please enter a valid email.");
                        return;
                    }

                    var btn = $(this);
                    var originalText = btn.text();
                    btn.prop('disabled', true).text('Sending...');
                    $('#em-test-result').text('');

                    $.post(ajaxurl, {
                        action: 'em_test_smtp',
                        nonce: '<?php echo wp_create_nonce('em_smtp_nonce'); ?>',
                        to: email
                    })
                        .done(function (res) {
                            btn.prop('disabled', false).text(originalText);
                            if (res.success) {
                                $('#em-test-result').css('color', 'green').text(res.data.message);
                            } else {
                                var errorMsg = res.data && res.data.message ? res.data.message : 'Unknown error';
                                $('#em-test-result').css('color', 'red').text('Error: ' + errorMsg);
                                console.error('SMTP Test Error:', res);
                            }
                        })
                        .fail(function (xhr, status, error) {
                            btn.prop('disabled', false).text(originalText);
                            $('#em-test-result').css('color', 'red').text('Server Error: ' + status);
                            console.error('AJAX Fail:', xhr.responseText);
                            alert("A server error occurred. Check console for details.");
                        });
                });
            });
        </script>
        <?php
    }

    public function handle_save_smtp()
    {
        check_ajax_referer('em_smtp_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();

        $settings = [
            'from_name' => sanitize_text_field($_POST['from_name']),
            'from_email' => sanitize_email($_POST['from_email']),
            'enabled' => sanitize_text_field($_POST['enabled']),
            'host' => sanitize_text_field($_POST['host']),
            'port' => sanitize_text_field($_POST['port']),
            'encryption' => sanitize_text_field($_POST['encryption']),
            'username' => sanitize_text_field($_POST['username']),
            'password' => sanitize_text_field($_POST['password']),
            'recaptcha_site_key' => sanitize_text_field($_POST['recaptcha_site_key']),
            'recaptcha_secret_key' => sanitize_text_field($_POST['recaptcha_secret_key']),
        ];
        update_option(self::OPTION_SMTP, $settings);
        wp_send_json_success();
    }

    public function handle_test_smtp()
    {
        check_ajax_referer('em_smtp_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(['message' => 'Unauthorized']);

        $to = sanitize_email($_POST['to']);
        if (!$to) {
            wp_send_json_error(['message' => 'Invalid email address']);
        }

        // Capture any PHPMailer errors
        add_action('wp_mail_failed', function ($error) {
            wp_send_json_error([
                'message' => $error->get_error_message(),
                'details' => $error->get_error_data()
            ]);
        });

        $sent = wp_mail($to, 'Test Email', 'This is a test email from Email Manager (Community Emails).');

        if ($sent) {
            wp_send_json_success(['message' => 'Test email sent successfully!']);
        } else {
            wp_send_json_error(['message' => 'Failed to send email. Check your SMTP settings.']);
        }
    }
}

new EM_Email_SMTP();
