<?php
/**
 * Support module — assign forms/chatflows as support intake forms, manage
 * resulting tickets (status + priority), reply to customers (stored as native
 * WP comments on the chat_submission post), and email notifications.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Support
{
    const PURPOSE_VALUE        = 'support';
    const TICKET_STATUS_META   = '_em_ticket_status';
    const TICKET_PRIORITY_META = '_em_ticket_priority';
    const SETTINGS_OPTION      = 'em_support_settings';
    const COMMENT_INTERNAL_META = '_em_internal_note';

    public function __construct()
    {
        add_action('init', [$this, 'enable_comments_on_submissions']);

        add_action('admin_post_em_save_support_form_purposes', [$this, 'handle_save_form_purposes']);
        add_action('admin_post_em_update_ticket',              [$this, 'handle_update_ticket']);
        add_action('admin_post_em_bulk_update_tickets',        [$this, 'handle_bulk_update']);
        add_action('admin_post_em_save_support_settings',      [$this, 'handle_save_settings']);

        add_action('wp_ajax_em_get_ticket_detail', [$this, 'ajax_get_detail']);
        add_action('wp_ajax_em_add_ticket_reply',  [$this, 'ajax_add_reply']);
    }

    public function enable_comments_on_submissions()
    {
        add_post_type_support('chat_submission', 'comments');
    }

    public static function statuses()
    {
        return [
            'open'        => __('Open', 'email-manager'),
            'in_progress' => __('In Progress', 'email-manager'),
            'resolved'    => __('Resolved', 'email-manager'),
            'closed'      => __('Closed', 'email-manager'),
        ];
    }

    public static function priorities()
    {
        return [
            'low'    => __('Low', 'email-manager'),
            'medium' => __('Medium', 'email-manager'),
            'high'   => __('High', 'email-manager'),
        ];
    }

    public static function default_settings()
    {
        return [
            'notify_customer_status' => 1,
            'notify_admin'           => 1,
            'admin_email'            => get_option('admin_email'),
            'reply_subject_template' => 'Re: your support request',
            'status_subject_template' => 'Your support ticket status: {status}',
            'status_body_template'   => "Hi {customer_name},\n\nYour ticket is now: {status}.\n\nRegards,\n{site_name}",
        ];
    }

    public static function get_settings()
    {
        $stored = get_option(self::SETTINGS_OPTION, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::default_settings());
    }

    public static function get_support_form_ids()
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            EM_Applications::FORM_PURPOSE_META,
            self::PURPOSE_VALUE
        ));
        return array_map('intval', $ids);
    }

    /* ================================================================
       Status transitions
       ================================================================ */

    private function update_ticket_status($submission_id, $status, $priority = null)
    {
        $valid_status   = array_keys(self::statuses());
        $valid_priority = array_keys(self::priorities());
        if (!in_array($status, $valid_status, true)) return false;

        $previous = get_post_meta($submission_id, self::TICKET_STATUS_META, true);
        update_post_meta($submission_id, self::TICKET_STATUS_META, $status);
        if ($priority !== null && in_array($priority, $valid_priority, true)) {
            update_post_meta($submission_id, self::TICKET_PRIORITY_META, $priority);
        }

        if ($previous !== $status) {
            $this->send_status_email($submission_id, $status);
        }
        return true;
    }

    private function send_status_email($submission_id, $status)
    {
        $settings = self::get_settings();
        if (empty($settings['notify_customer_status']) && empty($settings['notify_admin'])) return;

        $answers = get_post_meta($submission_id, '_chat_submission_data', true);
        $email   = EM_Applications::extract_email_from_submission($answers);
        $name    = EM_Applications::extract_name_from_submission($answers, $email);
        $statuses = self::statuses();
        $status_label = $statuses[$status] ?? $status;

        $tokens = [
            '{customer_name}' => $name ?: 'there',
            '{status}'        => $status_label,
            '{site_name}'     => get_bloginfo('name'),
            '{site_url}'      => home_url(),
        ];

        if (!empty($settings['notify_customer_status']) && $email) {
            $subject = strtr($settings['status_subject_template'], $tokens);
            $body    = strtr($settings['status_body_template'], $tokens);
            wp_mail($email, $subject, $body);
        }
        if (!empty($settings['notify_admin']) && !empty($settings['admin_email'])) {
            $admin_subject = sprintf('[%s] Ticket #%d → %s', get_bloginfo('name'), $submission_id, $status_label);
            $admin_body    = sprintf("Ticket #%d status changed to: %s\nCustomer: %s <%s>\n", $submission_id, $status_label, $name ?: '(unknown)', $email ?: '(none)');
            wp_mail($settings['admin_email'], $admin_subject, $admin_body);
        }
    }

    /* ================================================================
       Form handlers
       ================================================================ */

    public function handle_save_form_purposes()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_save_support_form_purposes');

        $marked_ids   = isset($_POST['support_form_ids']) ? array_map('intval', (array) $_POST['support_form_ids']) : [];
        $all_form_ids = EM_Applications::get_all_form_ids();

        foreach ($all_form_ids as $form_id) {
            $current = get_post_meta($form_id, EM_Applications::FORM_PURPOSE_META, true);
            if (in_array($form_id, $marked_ids, true)) {
                update_post_meta($form_id, EM_Applications::FORM_PURPOSE_META, self::PURPOSE_VALUE);
            } elseif ($current === self::PURPOSE_VALUE) {
                delete_post_meta($form_id, EM_Applications::FORM_PURPOSE_META);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'support_forms'], admin_url('admin.php')));
        exit;
    }

    public function handle_update_ticket()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        check_admin_referer('em_update_ticket_' . $submission_id);

        $status_keys   = array_keys(self::statuses());
        $priority_keys = array_keys(self::priorities());
        $status        = isset($_POST['status']) && in_array($_POST['status'], $status_keys, true) ? $_POST['status'] : 'open';
        $priority      = isset($_POST['priority']) && in_array($_POST['priority'], $priority_keys, true) ? $_POST['priority'] : 'medium';

        if ($submission_id) $this->update_ticket_status($submission_id, $status, $priority);

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'ticket_updated'], admin_url('admin.php')));
        exit;
    }

    public function handle_bulk_update()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_bulk_update_tickets');

        $ids    = isset($_POST['ticket_ids']) ? array_map('intval', (array) $_POST['ticket_ids']) : [];
        $status = isset($_POST['bulk_status']) ? sanitize_key($_POST['bulk_status']) : '';

        if (!empty($ids) && in_array($status, array_keys(self::statuses()), true)) {
            foreach ($ids as $id) {
                $this->update_ticket_status($id, $status);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'bulk_tickets'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_settings()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_save_support_settings');

        $settings = [
            'notify_customer_status'  => !empty($_POST['notify_customer_status']) ? 1 : 0,
            'notify_admin'            => !empty($_POST['notify_admin']) ? 1 : 0,
            'admin_email'             => sanitize_email(wp_unslash($_POST['admin_email'] ?? '')),
            'reply_subject_template'  => sanitize_text_field(wp_unslash($_POST['reply_subject_template'] ?? '')),
            'status_subject_template' => sanitize_text_field(wp_unslash($_POST['status_subject_template'] ?? '')),
            'status_body_template'    => wp_kses_post(wp_unslash($_POST['status_body_template'] ?? '')),
        ];
        update_option(self::SETTINGS_OPTION, $settings);

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'support_settings'], admin_url('admin.php')));
        exit;
    }

    /* ================================================================
       Ajax — detail + replies
       ================================================================ */

    public function ajax_get_detail()
    {
        check_ajax_referer('em_app_support', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);

        $id = absint($_POST['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) wp_send_json_error(['message' => 'Not found'], 404);

        wp_send_json_success($this->build_ticket_payload($id));
    }

    public function ajax_add_reply()
    {
        check_ajax_referer('em_app_support', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);

        $id      = absint($_POST['id'] ?? 0);
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
        $internal = !empty($_POST['internal']);
        if (!$id || $content === '') wp_send_json_error(['message' => 'Missing data'], 400);

        $current_user = wp_get_current_user();
        $comment_id = wp_insert_comment([
            'comment_post_ID'      => $id,
            'comment_author'       => $current_user ? $current_user->display_name : 'Staff',
            'comment_author_email' => $current_user ? $current_user->user_email : '',
            'comment_content'      => $content,
            'comment_type'         => 'em_ticket_reply',
            'comment_approved'     => 1,
            'user_id'              => $current_user ? $current_user->ID : 0,
        ]);

        if (!$comment_id) wp_send_json_error(['message' => 'Failed to save reply'], 500);

        if ($internal) {
            update_comment_meta($comment_id, self::COMMENT_INTERNAL_META, 1);
        } else {
            // Email the customer
            $settings = self::get_settings();
            $answers  = get_post_meta($id, '_chat_submission_data', true);
            $email    = EM_Applications::extract_email_from_submission($answers);
            $name     = EM_Applications::extract_name_from_submission($answers, $email);
            if ($email) {
                $tokens = [
                    '{customer_name}' => $name ?: 'there',
                    '{site_name}'     => get_bloginfo('name'),
                    '{site_url}'      => home_url(),
                ];
                $subject = strtr($settings['reply_subject_template'], $tokens);
                wp_mail($email, $subject, $content);
            }
        }

        wp_send_json_success($this->build_ticket_payload($id));
    }

    private function build_ticket_payload($id)
    {
        $answers   = get_post_meta($id, '_chat_submission_data', true);
        $status    = get_post_meta($id, self::TICKET_STATUS_META, true) ?: 'open';
        $priority  = get_post_meta($id, self::TICKET_PRIORITY_META, true) ?: 'medium';
        $statuses  = self::statuses();
        $priorities = self::priorities();

        $comments = get_comments([
            'post_id' => $id,
            'type'    => 'em_ticket_reply',
            'status'  => 'approve',
            'orderby' => 'comment_date',
            'order'   => 'ASC',
        ]);
        $replies = [];
        foreach ($comments as $c) {
            $replies[] = [
                'author'   => $c->comment_author,
                'date'     => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $c->comment_date),
                'content'  => $c->comment_content,
                'internal' => (bool) get_comment_meta($c->comment_ID, self::COMMENT_INTERNAL_META, true),
            ];
        }

        return [
            'id'             => $id,
            'status'         => $status,
            'status_label'   => $statuses[$status] ?? $status,
            'priority'       => $priority,
            'priority_label' => $priorities[$priority] ?? $priority,
            'email'          => EM_Applications::extract_email_from_submission($answers),
            'answers'        => is_array($answers) ? $answers : [],
            'replies'        => $replies,
        ];
    }

    /* ================================================================
       Render
       ================================================================ */

    public static function render()
    {
        $form_ids    = EM_Applications::get_all_form_ids();
        $support_ids = self::get_support_form_ids();
        $statuses    = self::statuses();
        $priorities  = self::priorities();
        $settings    = self::get_settings();

        $tickets = [];
        if (!empty($support_ids)) {
            $tickets = get_posts([
                'post_type'   => 'chat_submission',
                'numberposts' => 200,
                'post_status' => ['publish', 'draft'],
                'meta_query'  => [['key' => '_chat_submission_form_id', 'value' => $support_ids, 'compare' => 'IN']],
            ]);
        }

        $by_status = [];
        foreach ($tickets as $t) {
            $st = get_post_meta($t->ID, self::TICKET_STATUS_META, true) ?: 'open';
            $by_status[$st] = ($by_status[$st] ?? 0) + 1;
        }
        ?>
        <div class="em-support-tab">

            <?php self::render_kpi_strip(count($tickets), $by_status, $statuses, count($support_ids)); ?>

            <div class="gdc-subtabs">
                <button type="button" class="gdc-subtab active" data-subtab="tickets" style="--em-i:0;">
                    <?php esc_html_e('Tickets', 'email-manager'); ?>
                </button>
                <button type="button" class="gdc-subtab" data-subtab="support-forms" style="--em-i:1;">
                    <?php esc_html_e('Forms', 'email-manager'); ?>
                </button>
                <button type="button" class="gdc-subtab" data-subtab="support-settings" style="--em-i:2;">
                    <?php esc_html_e('Settings', 'email-manager'); ?>
                </button>
            </div>

            <?php self::render_tickets_panel($tickets, $statuses, $priorities); ?>
            <?php self::render_forms_panel($form_ids); ?>
            <?php self::render_settings_panel($settings); ?>
        </div>
        <?php
    }

    private static function render_kpi_strip($total, $by_status, $statuses, $form_count)
    {
        ?>
        <div class="em-kpi-grid">
            <div class="em-kpi" style="--em-i:0;">
                <div class="em-kpi__label"><?php esc_html_e('Total Tickets', 'email-manager'); ?></div>
                <div class="em-kpi__value"><?php echo esc_html(number_format_i18n($total)); ?></div>
                <div class="em-kpi__hint"><?php echo esc_html(sprintf(_n('%d support form', '%d support forms', $form_count, 'email-manager'), $form_count)); ?></div>
            </div>
            <?php $i = 1; foreach ($statuses as $key => $label): $count = $by_status[$key] ?? 0; ?>
                <div class="em-kpi" style="--em-i:<?php echo (int) $i; ?>;">
                    <div class="em-kpi__label"><?php echo esc_html($label); ?></div>
                    <div class="em-kpi__value"><?php echo esc_html(number_format_i18n($count)); ?></div>
                    <div class="em-kpi__hint"><?php echo esc_html($key === 'open' ? __('needs attention', 'email-manager') : __('current bucket', 'email-manager')); ?></div>
                </div>
            <?php $i++; endforeach; ?>
        </div>
        <?php
    }

    private static function render_tickets_panel($tickets, $statuses, $priorities)
    {
        ?>
        <div class="gdc-subtab-panel" data-subpanel="tickets">
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Support Tickets', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Click any row to view the conversation and reply.', 'email-manager'); ?></p>
                    </div>
                </div>

                <?php if (empty($tickets)): ?>
                    <div class="em-empty">
                        <div class="em-empty__icon"><span class="dashicons dashicons-sos"></span></div>
                        <div class="em-empty__title"><?php esc_html_e('No tickets yet', 'email-manager'); ?></div>
                        <div><?php esc_html_e('Mark forms or chatflows as support forms to start receiving tickets.', 'email-manager'); ?></div>
                    </div>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="em-bulk-scope">
                        <?php wp_nonce_field('em_bulk_update_tickets'); ?>
                        <input type="hidden" name="action" value="em_bulk_update_tickets" />
                        <div class="em-bulk-bar">
                            <span class="em-bulk-bar__count"><strong>0</strong> <?php esc_html_e('selected', 'email-manager'); ?></span>
                            <select name="bulk_status">
                                <option value=""><?php esc_html_e('— Set status —', 'email-manager'); ?></option>
                                <?php foreach ($statuses as $k => $label): ?>
                                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Apply to Selected', 'email-manager'); ?></button>
                        </div>
                        <div class="gdc-table-wrap">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th style="width:32px;"><input type="checkbox" class="em-bulk-select-all" /></th>
                                        <th><?php esc_html_e('Subject', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Source', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Submitted', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Status', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Priority', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Update', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('View', 'email-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tickets as $i => $t):
                                        $form_id    = (int) get_post_meta($t->ID, '_chat_submission_form_id', true);
                                        $form_title = $form_id ? get_the_title($form_id) : '—';
                                        $status     = get_post_meta($t->ID, self::TICKET_STATUS_META, true) ?: 'open';
                                        $priority   = get_post_meta($t->ID, self::TICKET_PRIORITY_META, true) ?: 'medium';
                                        $status_class = ($status === 'closed' || $status === 'resolved') ? 'em-pill--success' : ($status === 'open' ? 'em-pill--error' : 'em-pill--warning');
                                        $priority_class = $priority === 'high' ? 'em-pill--error' : ($priority === 'low' ? 'em-pill' : 'em-pill--info');
                                    ?>
                                        <tr class="em-row" style="--em-i:<?php echo (int) $i; ?>;">
                                            <td><input type="checkbox" class="em-bulk-row-check" name="ticket_ids[]" value="<?php echo esc_attr($t->ID); ?>" /></td>
                                            <td><strong><?php echo esc_html($t->post_title ?: '(unnamed)'); ?></strong></td>
                                            <td><?php echo esc_html($form_title); ?></td>
                                            <td><?php echo esc_html(get_the_date('', $t->ID)); ?></td>
                                            <td><span class="em-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html($statuses[$status] ?? $status); ?></span></td>
                                            <td><span class="em-pill <?php echo esc_attr($priority_class); ?>"><?php echo esc_html($priorities[$priority] ?? $priority); ?></span></td>
                                            <td>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;gap:6px;flex-wrap:wrap;">
                                                    <?php wp_nonce_field('em_update_ticket_' . $t->ID); ?>
                                                    <input type="hidden" name="action" value="em_update_ticket" />
                                                    <input type="hidden" name="submission_id" value="<?php echo esc_attr($t->ID); ?>" />
                                                    <select name="status">
                                                        <?php foreach ($statuses as $k => $label): ?>
                                                            <option value="<?php echo esc_attr($k); ?>" <?php selected($status, $k); ?>><?php echo esc_html($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select name="priority">
                                                        <?php foreach ($priorities as $k => $label): ?>
                                                            <option value="<?php echo esc_attr($k); ?>" <?php selected($priority, $k); ?>><?php echo esc_html($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="button button-small"><?php esc_html_e('Save', 'email-manager'); ?></button>
                                                </form>
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small em-view-ticket"
                                                    data-submission-id="<?php echo esc_attr($t->ID); ?>"
                                                    data-name="<?php echo esc_attr($t->post_title); ?>">
                                                    <span class="dashicons dashicons-visibility"></span>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_forms_panel($form_ids)
    {
        ?>
        <div class="gdc-subtab-panel" data-subpanel="support-forms" hidden>
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Support Forms', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Mark forms or chatflows whose submissions become support tickets.', 'email-manager'); ?></p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('em_save_support_form_purposes'); ?>
                    <input type="hidden" name="action" value="em_save_support_form_purposes" />
                    <div class="gdc-table-wrap">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:40px;"></th>
                                    <th><?php esc_html_e('Form', 'email-manager'); ?></th>
                                    <th><?php esc_html_e('Type', 'email-manager'); ?></th>
                                    <th><?php esc_html_e('Current Purpose', 'email-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($form_ids)): ?>
                                    <tr><td colspan="4"><?php esc_html_e('No forms exist yet.', 'email-manager'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($form_ids as $i => $fid):
                                        $post = get_post($fid);
                                        if (!$post) continue;
                                        $purpose = get_post_meta($fid, EM_Applications::FORM_PURPOSE_META, true);
                                        $checked = ($purpose === self::PURPOSE_VALUE);
                                    ?>
                                        <tr class="em-row" style="--em-i:<?php echo (int) $i; ?>;">
                                            <td><input type="checkbox" name="support_form_ids[]" value="<?php echo esc_attr($fid); ?>" <?php checked($checked); ?> /></td>
                                            <td><strong><?php echo esc_html($post->post_title ?: '(no title)'); ?></strong></td>
                                            <td><?php echo esc_html($post->post_type === 'chat_form' ? __('Chatflow', 'email-manager') : __('Form', 'email-manager')); ?></td>
                                            <td>
                                                <?php
                                                if ($purpose === self::PURPOSE_VALUE) {
                                                    echo '<span class="em-pill em-pill--info">' . esc_html__('Support', 'email-manager') . '</span>';
                                                } elseif ($purpose === EM_Applications::PURPOSE_VALUE) {
                                                    echo '<span class="em-pill em-pill--success">' . esc_html__('Application', 'email-manager') . '</span>';
                                                } else {
                                                    echo '<span class="em-pill">' . esc_html__('Regular', 'email-manager') . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p style="margin-top:12px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Support Forms', 'email-manager'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_settings_panel($settings)
    {
        ?>
        <div class="gdc-subtab-panel" data-subpanel="support-settings" hidden>
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Support Notification Settings', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Customize automatic emails for status changes and replies. Tokens: {customer_name}, {status}, {site_name}, {site_url}.', 'email-manager'); ?></p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('em_save_support_settings'); ?>
                    <input type="hidden" name="action" value="em_save_support_settings" />

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Email customer on status change', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Sends to the email captured in the form submission.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <label><input type="checkbox" name="notify_customer_status" value="1" <?php checked(!empty($settings['notify_customer_status'])); ?> /> <?php esc_html_e('Enabled', 'email-manager'); ?></label>
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Notify admin', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('A copy of every status change goes here.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="notify_admin" value="1" <?php checked(!empty($settings['notify_admin'])); ?> /> <?php esc_html_e('Enabled', 'email-manager'); ?></label>
                            <input type="email" name="admin_email" value="<?php echo esc_attr($settings['admin_email']); ?>" placeholder="admin@example.com" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Reply email subject', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Used when an admin sends a reply to the customer.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="text" name="reply_subject_template" value="<?php echo esc_attr($settings['reply_subject_template']); ?>" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Status change email subject', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="text" name="status_subject_template" value="<?php echo esc_attr($settings['status_subject_template']); ?>" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Status change email body', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <textarea name="status_body_template"><?php echo esc_textarea($settings['status_body_template']); ?></textarea>
                        </div>
                    </div>

                    <p style="margin-top:16px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'email-manager'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}

new EM_Support();
