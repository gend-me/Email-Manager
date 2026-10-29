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
        add_action('wp_ajax_em_save_recaptcha', [$this, 'handle_save_recaptcha']);
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
        // Strip whitespace - Google (and most providers) display App
        // Passwords as "xxxx xxxx xxxx xxxx" for readability, but the
        // real credential has no spaces. A copy-pasted password with
        // the display spaces still in it fails SMTP auth silently
        // (confirmed live: 19-char stored value = 16-char app password
        // + 3 spaces, every send rejected with "Could not authenticate").
        $phpmailer->Password = preg_replace('/\s+/', '', $settings['password'] ?? '');
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
                        password: $('#em-smtp-pass').val()
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
            // Strip whitespace so a password copy-pasted straight from
            // Google's "xxxx xxxx xxxx xxxx" App Password display doesn't
            // silently break SMTP auth (see configure_phpmailer()).
            'password' => preg_replace('/\s+/', '', sanitize_text_field($_POST['password'])),
        ];
        // reCAPTCHA keys share this option but are edited in their own popup
        // (render_recaptcha_modal) — carry them over untouched.
        $existing = get_option(self::OPTION_SMTP, []);
        $settings['recaptcha_site_key']   = $existing['recaptcha_site_key'] ?? '';
        $settings['recaptcha_secret_key'] = $existing['recaptcha_secret_key'] ?? '';
        update_option(self::OPTION_SMTP, $settings);
        wp_send_json_success();
    }

    /** AJAX: save the Google reCAPTCHA v3 keys from the popup. A blank secret keeps the stored one. */
    public function handle_save_recaptcha()
    {
        check_ajax_referer('em_recaptcha_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(['message' => __('You are not allowed to change these settings.', 'email-manager')], 403);

        $settings = get_option(self::OPTION_SMTP, []);
        if (!is_array($settings))
            $settings = [];
        $settings['recaptcha_site_key'] = sanitize_text_field(wp_unslash($_POST['site_key'] ?? ''));
        $secret = sanitize_text_field(wp_unslash($_POST['secret_key'] ?? ''));
        if ($secret !== '' || !empty($_POST['clear_secret']))
            $settings['recaptcha_secret_key'] = $secret;
        update_option(self::OPTION_SMTP, $settings);
        wp_send_json_success([
            'site_key'   => $settings['recaptcha_site_key'],
            'has_secret' => !empty($settings['recaptcha_secret_key']),
        ]);
    }

    /**
     * "reCAPTCHA Settings" popup, opened by any [data-em-recaptcha-open]
     * button (the Chatflows list toolbar and the chat form's Security
     * Settings box). Prints once per page.
     */
    public static function render_recaptcha_modal()
    {
        static $done = false;
        if ($done || !current_user_can('manage_options'))
            return;
        $done = true;
        $settings   = get_option(self::OPTION_SMTP, []);
        $site_key   = is_array($settings) ? ($settings['recaptcha_site_key'] ?? '') : '';
        $has_secret = is_array($settings) && !empty($settings['recaptcha_secret_key']);
        ?>
        <div class="em-recap-modal" id="em-recaptcha-modal" role="dialog" aria-modal="true" aria-labelledby="em-recap-title" hidden>
            <div class="em-recap-modal__panel">
                <div class="em-recap-modal__head">
                    <h2 id="em-recap-title"><?php esc_html_e('Google reCAPTCHA v3', 'email-manager'); ?></h2>
                    <button type="button" class="em-recap-modal__x" data-em-recaptcha-close aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <p class="em-recap-modal__desc">
                    <?php
                    printf(
                        /* translators: %s: Google reCAPTCHA admin console link */
                        esc_html__('Spam protection for chatflows and forms. Create v3 keys in the %s, then turn reCAPTCHA on per form in its Security Settings box.', 'email-manager'),
                        '<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">' . esc_html__('Google reCAPTCHA console', 'email-manager') . '</a>'
                    );
                    ?>
                </p>
                <label for="em-recap-site"><?php esc_html_e('Site Key', 'email-manager'); ?></label>
                <input type="text" id="em-recap-site" autocomplete="off" value="<?php echo esc_attr($site_key); ?>" />
                <label for="em-recap-secret"><?php esc_html_e('Secret Key', 'email-manager'); ?></label>
                <input type="password" id="em-recap-secret" autocomplete="new-password" value=""
                       placeholder="<?php echo $has_secret ? esc_attr__('Saved — leave blank to keep it', 'email-manager') : esc_attr__('Paste your secret key', 'email-manager'); ?>" />
                <div class="em-recap-modal__status" data-em-recap-status role="status"></div>
                <div class="em-recap-modal__foot">
                    <button type="button" class="button" data-em-recaptcha-close><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                    <button type="button" class="button button-primary" data-em-recaptcha-save><?php esc_html_e('Save Settings', 'email-manager'); ?></button>
                </div>
            </div>
        </div>
        <style>
            .em-recap-modal[hidden] { display: none !important; }
            .em-recap-modal { position: fixed; inset: 0; z-index: 100100; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(2, 6, 23, .72); }
            .em-recap-modal__panel { width: 100%; max-width: 520px; background: #0f172a; color: #e2e8f0; border: 1px solid rgba(148, 163, 184, .25); border-radius: 16px; padding: 22px 24px; box-shadow: 0 30px 80px rgba(0, 0, 0, .6); }
            .em-recap-modal__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
            .em-recap-modal__head h2 { margin: 0; font-size: 18px; color: #f8fafc; }
            .em-recap-modal__x { width: 32px; height: 32px; border-radius: 999px; border: 1px solid rgba(148, 163, 184, .3); background: transparent; color: #e2e8f0; font-size: 18px; line-height: 1; cursor: pointer; }
            .em-recap-modal__desc { margin: 0 0 16px; color: #94a3b8; font-size: 13px; line-height: 1.55; }
            .em-recap-modal__desc a { color: #818cf8; }
            .em-recap-modal label { display: block; margin: 12px 0 6px; font-weight: 600; color: #e2e8f0; }
            .em-recap-modal input[type=text], .em-recap-modal input[type=password] { width: 100%; padding: 9px 12px; border-radius: 10px; background: rgba(2, 6, 23, .65) !important; color: #e2e8f0 !important; border: 1px solid rgba(125, 211, 252, .22) !important; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
            .em-recap-modal input::placeholder { color: #64748b; font-family: inherit; }
            .em-recap-modal__status { min-height: 18px; margin-top: 12px; font-size: 13px; }
            .em-recap-modal__status.is-ok { color: #86efac; }
            .em-recap-modal__status.is-err { color: #fca5a5; }
            .em-recap-modal__foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        </style>
        <script>
        jQuery(function ($) {
            var $m = $('#em-recaptcha-modal'), $status = $m.find('[data-em-recap-status]'), nonce = <?php echo wp_json_encode(wp_create_nonce('em_recaptcha_nonce')); ?>;
            if (!$m.parent().is('body')) { $m.appendTo('body'); }
            function close() { $m.prop('hidden', true); }
            $(document).on('click', '[data-em-recaptcha-open]', function (e) {
                e.preventDefault();
                $status.text('').removeClass('is-ok is-err');
                $m.prop('hidden', false);
                setTimeout(function () { $('#em-recap-site').trigger('focus'); }, 30);
            });
            $m.on('click', '[data-em-recaptcha-close]', close);
            $m.on('click', function (e) { if (e.target === this) { close(); } });
            $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$m.prop('hidden')) { close(); } });
            $m.on('click', '[data-em-recaptcha-save]', function () {
                var $b = $(this).prop('disabled', true).text(<?php echo wp_json_encode(__('Saving…', 'email-manager')); ?>);
                $.post(ajaxurl, { action: 'em_save_recaptcha', nonce: nonce, site_key: $('#em-recap-site').val(), secret_key: $('#em-recap-secret').val() })
                    .done(function (res) {
                        if (res && res.success) {
                            $('#em-recap-secret').val('').attr('placeholder', res.data.has_secret ? <?php echo wp_json_encode(__('Saved — leave blank to keep it', 'email-manager')); ?> : <?php echo wp_json_encode(__('Paste your secret key', 'email-manager')); ?>);
                            $status.text(<?php echo wp_json_encode(__('Saved.', 'email-manager')); ?>).removeClass('is-err').addClass('is-ok');
                            $(document).trigger('em:recaptcha-saved', [res.data]);
                        } else {
                            $status.text((res && res.data && res.data.message) || <?php echo wp_json_encode(__('Could not save the settings.', 'email-manager')); ?>).removeClass('is-ok').addClass('is-err');
                        }
                    })
                    .fail(function () { $status.text(<?php echo wp_json_encode(__('Could not save the settings.', 'email-manager')); ?>).removeClass('is-ok').addClass('is-err'); })
                    .always(function () { $b.prop('disabled', false).text(<?php echo wp_json_encode(__('Save Settings', 'email-manager')); ?>); });
            });
        });
        </script>
        <?php
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
