<?php
/**
 * Email Logs Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class EM_Email_Logs
{

    private const POST_TYPE = 'em_email_log';

    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);

        // 2026-11-15: logging of sent mail restored. The old writer (GenD Core, gdc_email_log) was not ported when
        // the email features moved into this plugin, so nothing was logged after 2026-02-11. Hub (main site) only.
        add_action('wp_mail_succeeded', [$this, 'log_sent']);
        add_action('wp_mail_failed', [$this, 'log_failed']);
        add_action(self::PURGE_HOOK, [$this, 'purge_old_logs']);
        add_action('init', [$this, 'schedule_purge']);
    }

    private const PURGE_HOOK = 'em_email_log_purge';
    private const RETENTION_DAYS = 90;
    private const BODY_MAX = 51200; // bytes kept per logged body
    private static $writing = false;

    private static function enabled(): bool
    {
        return is_main_site() && (bool) apply_filters('em_email_log_enabled', true);
    }

    public function log_sent($data)
    {
        if (is_array($data)) {
            self::write_log($data, 'sent', '');
        }
    }

    public function log_failed($error)
    {
        if (!is_wp_error($error)) {
            return;
        }
        $data = $error->get_error_data();
        self::write_log(is_array($data) ? $data : [], 'failed', $error->get_error_message());
    }

    /** Records one email as an em_email_log post (meta: _em_log_status/_to/_subject/_time/_body/_error). */
    private static function write_log(array $data, string $status, string $error)
    {
        if (self::$writing || !self::enabled()) {
            return;
        }
        self::$writing = true;
        try {
            $to = $data['to'] ?? '';
            $to = is_array($to) ? implode(', ', array_map('strval', $to)) : (string) $to;
            $to = sanitize_text_field(substr($to, 0, 1000));
            $subject = sanitize_text_field(substr((string) ($data['subject'] ?? ''), 0, 500));
            $body = self::safe_body($subject, (string) ($data['message'] ?? ''));
            $now = current_time('mysql');

            $id = wp_insert_post(wp_slash([
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => ($to !== '' ? $to : '(no recipient)') . ' - ' . $subject,
                'post_author' => 0,
            ]), true);
            if (is_wp_error($id) || !$id) {
                return;
            }
            add_post_meta($id, '_em_log_status', $status);
            add_post_meta($id, '_em_log_to', wp_slash($to));
            add_post_meta($id, '_em_log_subject', wp_slash($subject));
            add_post_meta($id, '_em_log_time', $now);
            add_post_meta($id, '_em_log_body', wp_slash($body));
            if ($error !== '') {
                add_post_meta($id, '_em_log_error', wp_slash(sanitize_text_field(substr($error, 0, 500))));
            }
            if (!empty($data['transport'])) {
                add_post_meta($id, '_em_log_transport', sanitize_key((string) $data['transport']));
            }
        } catch (\Throwable $e) {
            // Logging must never break sending.
        } finally {
            self::$writing = false;
        }
    }

    /** Body as stored: capped, and with sign-in / reset credentials removed (never keep tokens in logs). */
    private static function safe_body(string $subject, string $body): string
    {
        if (preg_match('/password|reset|verif|one[- ]time|login link|sign[- ]in link|magic link|security code|\b2fa\b|\botp\b/i', $subject)) {
            return '[Body not stored: this email can contain a sign-in, reset or verification credential.]';
        }
        // Token-like query values in links (key=, token=, code=, sig=, nonce=, hash= ...).
        $body = preg_replace('/((?:[?&]|&amp;)[a-z0-9_\-]*(?:key|token|code|otp|sig|signature|hash|nonce|secret|auth)=)[^&"\'\s<>]+/i', '$1[redacted]', $body);
        if (strlen($body) > self::BODY_MAX) {
            $body = substr($body, 0, self::BODY_MAX) . "\n<!-- [truncated] -->";
        }
        return $body;
    }

    public function schedule_purge()
    {
        if (is_main_site() && !wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }
    }

    /** Deletes em_email_log posts older than RETENTION_DAYS (batched). */
    public function purge_old_logs()
    {
        if (!is_main_site()) {
            return;
        }
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);
        for ($i = 0; $i < 20; $i++) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_date_gmt < %s LIMIT 200",
                self::POST_TYPE,
                $cutoff
            ));
            if (empty($ids)) {
                break;
            }
            foreach ($ids as $id) {
                wp_delete_post((int) $id, true);
            }
        }
    }

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Email Logs', 'email-manager'),
                'singular_name' => __('Email Log', 'email-manager'),
            ],
            'public' => false,
            'show_ui' => false, // Custom UI
            'supports' => ['title', 'editor', 'custom-fields'],
        ]);
    }

    public static function render_logs_tab()
    {
        ?>
        <style>
            .em-pill {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                background: rgba(100, 116, 139, 0.12);
                color: #475569;
                border: 1px solid rgba(100, 116, 139, 0.2);
            }

            .em-pill--success {
                background: rgba(34, 197, 94, 0.15);
                color: #15803d;
                border: 1px solid rgba(34, 197, 94, 0.25);
            }

            .em-pill--error {
                background: rgba(239, 68, 68, 0.15);
                color: #b91c1c;
                border: 1px solid rgba(239, 68, 68, 0.25);
            }

            .em-pill--info {
                background: rgba(59, 130, 246, 0.15);
                color: #1d4ed8;
                border: 1px solid rgba(59, 130, 246, 0.25);
            }

            #em-log-modal.gdc-email-modal {
                position: fixed;
                inset: 0;
                background: rgba(11, 14, 20, 0.8);
                z-index: 99999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }

            #em-log-modal[hidden] {
                display: none !important;
            }

            #em-log-modal .gdc-email-modal__dialog {
                position: relative;
                background: #fff;
                border-radius: 12px;
                width: 100%;
                max-width: 800px;
                height: 80vh;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
                padding: 24px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            #em-log-modal .gdc-email-modal__dialog > h3 {
                margin: 0 0 16px;
                padding-right: 40px;
            }

            #em-log-modal .gdc-close {
                position: absolute;
                top: 12px;
                right: 12px;
                width: 32px;
                height: 32px;
                border-radius: 8px;
                border: 1px solid rgba(0, 0, 0, 0.1);
                background: rgba(0, 0, 0, 0.04);
                font-size: 20px;
                line-height: 1;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            #em-log-modal .em-log-content {
                flex: 1 1 auto;
                min-height: 0;
                height: auto !important;
            }
        </style>
        <div class="gdc-email-panel">
            <h3>
                <?php esc_html_e('Email Logs', 'email-manager'); ?>
            </h3>
            <p class="description">
                <?php esc_html_e('The 20 most recent logged emails.', 'email-manager'); ?>
            </p>

            <?php
            $logs = get_posts([
                'post_type' => [self::POST_TYPE, 'gdc_email_log'], // Support both for migration
                'posts_per_page' => 20,
                'post_status' => 'any',
            ]);
            ?>

            <div class="gdc-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>
                                <?php esc_html_e('Time', 'email-manager'); ?>
                            </th>
                            <th>
                                <?php esc_html_e('Recipient', 'email-manager'); ?>
                            </th>
                            <th>
                                <?php esc_html_e('Subject', 'email-manager'); ?>
                            </th>
                            <th>
                                <?php esc_html_e('Status', 'email-manager'); ?>
                            </th>
                            <th>
                                <?php esc_html_e('View', 'email-manager'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5">
                                    <?php esc_html_e('No logs found.', 'email-manager'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log):
                                $status = get_post_meta($log->ID, '_em_log_status', true) ?: get_post_meta($log->ID, '_gdc_log_status', true);
                                $to = get_post_meta($log->ID, '_em_log_to', true) ?: get_post_meta($log->ID, '_gdc_log_to', true);
                                $title_parts = explode(' - ', $log->post_title, 2);
                                $subject = isset($title_parts[1]) ? $title_parts[1] : $log->post_title;
                                if (empty($to) && isset($title_parts[0]))
                                    $to = str_replace('To: ', '', $title_parts[0]);
                                ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html($log->post_date); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($to); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($subject); ?>
                                    </td>
                                    <td>
                                        <?php if ($status === 'sent'): ?>
                                            <span class="em-pill em-pill--success">
                                                <?php esc_html_e('Sent', 'email-manager'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="em-pill em-pill--error">
                                                <?php echo esc_html(ucfirst($status ?: 'Unknown')); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small em-view-log"
                                            data-log-id="<?php echo esc_attr($log->ID); ?>">
                                            <span class="dashicons dashicons-visibility"></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Log View Modal -->
        <div class="gdc-email-modal" id="em-log-modal" hidden>
            <div class="gdc-email-modal__dialog" style="max-width: 800px; height: 80vh;">
                <button class="gdc-close" onclick="document.getElementById('em-log-modal').hidden=true">&times;</button>
                <h3>
                    <?php esc_html_e('Email Content', 'email-manager'); ?>
                </h3>
                <div class="em-log-content"
                    style="padding:0; overflow:auto;">
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var ajaxUrl = (typeof bpaAdmin !== 'undefined' && bpaAdmin.ajaxUrl) ? bpaAdmin.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');

                function closeLogModal() {
                    $('#em-log-modal').prop('hidden', true);
                }

                $('.em-view-log').on('click', function () {
                    var id = $(this).data('log-id');
                    $('#em-log-modal').prop('hidden', false);
                    $('.em-log-content').html('Loading...');

                    $.ajax({
                        url: ajaxUrl,
                        data: { action: 'em_get_log_body', id: id, nonce: '<?php echo wp_create_nonce('em_log_nonce'); ?>' },
                        success: function (res) {
                            if (res.success) {
                                // Email HTML renders in a sandboxed frame (no scripts, no access to wp-admin).
                                var fr = document.createElement('iframe');
                                fr.setAttribute('sandbox', '');
                                fr.setAttribute('title', 'Email content');
                                fr.style.cssText = 'width:100%;height:100%;min-height:60vh;border:0;border-radius:10px;background:#fff;';
                                fr.srcdoc = res.data.body || '';
                                $('.em-log-content').empty().append(fr);
                            } else {
                                $('.em-log-content').html('Error loading content.');
                            }
                        },
                        error: function () {
                            $('.em-log-content').html('Error loading content.');
                        }
                    });
                });

                $('#em-log-modal').on('click', function (e) {
                    if (e.target === this) closeLogModal();
                });

                $(document).on('keydown', function (e) {
                    if (e.key === 'Escape' && !$('#em-log-modal').prop('hidden')) closeLogModal();
                });
            });
        </script>
        <?php
    }
}

new EM_Email_Logs();

// AJAX for log body
add_action('wp_ajax_em_get_log_body', function () {
    check_ajax_referer('em_log_nonce', 'nonce');
    // Security 2026-11-02: only email logs, only for site managers (this returned any post's content).
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }
    $id = absint($_GET['id']);
    $post = get_post($id);
    // The Logs tab lists both types (gdc_email_log = pre-migration logs); allowing only em_email_log broke every View.
    if ($post && in_array($post->post_type, ['em_email_log', 'gdc_email_log'], true)) {
        // Pre-migration logs (gdc_email_log) keep the body in meta, not post_content.
        $body = $post->post_content !== '' ? $post->post_content : (string) (get_post_meta($id, '_em_log_body', true) ?: get_post_meta($id, '_gdc_log_body', true));
        wp_send_json_success(['body' => $body]);
    }
    wp_send_json_error();
});
