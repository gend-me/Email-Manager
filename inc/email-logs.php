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

        // If we want to capture emails, we need to hook into wp_mail, 
        // but for now we focus on viewing existing or manually created logs.
        // GenD core likely hooked wp_mail. We might need to duplicate that if we want *new* logs.
        // For now, let's just enable viewing.
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
        </style>
        <div class="gdc-email-panel">
            <h3>
                <?php esc_html_e('Email Logs', 'email-manager'); ?>
            </h3>
            <p class="description">
                <?php esc_html_e('History of all emails sent.', 'email-manager'); ?>
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
                    style="padding:20px; overflow:auto; height:calc(100% - 60px); background:#fff; border:1px solid #ddd;">
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('.em-view-log').on('click', function () {
                    var id = $(this).data('log-id');
                    $('#em-log-modal').prop('hidden', false);
                    $('.em-log-content').html('Loading...');

                    $.ajax({
                        url: bpaAdmin.ajaxUrl, // Reuse generic ajax url if available or define new
                        data: { action: 'em_get_log_body', id: id, nonce: '<?php echo wp_create_nonce('em_log_nonce'); ?>' },
                        success: function (res) {
                            if (res.success) {
                                $('.em-log-content').html(res.data.body);
                            } else {
                                $('.em-log-content').html('Error loading content.');
                            }
                        }
                    });
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
    $id = absint($_GET['id']);
    $post = get_post($id);
    if ($post) {
        wp_send_json_success(['body' => $post->post_content]);
    }
    wp_send_json_error();
});
