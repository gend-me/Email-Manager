<?php
/**
 * Applications module — assign forms as application forms, manage applicants
 * through configurable stages, grant WP roles on stage transitions, optionally
 * auto-create user accounts, and email applicants on stage changes.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Applications
{
    const FORM_PURPOSE_META    = '_em_form_purpose';
    const APPLICANT_STAGE_META = '_em_application_stage';
    const APPLICANT_HISTORY_META = '_em_application_history';
    const APPLICANT_USER_META  = '_em_application_user_id';
    const STAGES_OPTION        = 'em_application_stages';
    const SETTINGS_OPTION      = 'em_application_settings';
    const PURPOSE_VALUE        = 'application';

    public function __construct()
    {
        add_action('admin_post_em_save_application_form_purposes', [$this, 'handle_save_form_purposes']);
        add_action('admin_post_em_save_application_stages',        [$this, 'handle_save_stages']);
        add_action('admin_post_em_save_application_settings',      [$this, 'handle_save_settings']);
        add_action('admin_post_em_advance_applicant',              [$this, 'handle_advance_applicant']);
        add_action('admin_post_em_bulk_advance_applicants',        [$this, 'handle_bulk_advance']);

        add_action('wp_ajax_em_get_applicant_detail',              [$this, 'ajax_get_detail']);
    }

    /* ================================================================
       Defaults / accessors
       ================================================================ */

    public static function default_stages()
    {
        return [
            ['label' => 'Submitted',    'role' => '',           'auto_create' => 0],
            ['label' => 'Under Review', 'role' => '',           'auto_create' => 0],
            ['label' => 'Approved',     'role' => 'subscriber', 'auto_create' => 1],
            ['label' => 'Rejected',     'role' => '',           'auto_create' => 0],
        ];
    }

    public static function get_stages()
    {
        $stages = get_option(self::STAGES_OPTION, []);
        if (!is_array($stages) || empty($stages)) {
            return self::default_stages();
        }
        $clean = [];
        foreach ($stages as $s) {
            if (!is_array($s) || empty($s['label'])) continue;
            $clean[] = [
                'label'       => sanitize_text_field($s['label']),
                'role'        => isset($s['role']) ? sanitize_key($s['role']) : '',
                'auto_create' => !empty($s['auto_create']) ? 1 : 0,
            ];
        }
        return $clean ?: self::default_stages();
    }

    public static function default_settings()
    {
        return [
            'notify_applicant'    => 1,
            'notify_admin'        => 1,
            'admin_email'         => get_option('admin_email'),
            'subject_template'    => 'Your application status has been updated',
            'body_template'       => "Hi {applicant_name},\n\nYour application has moved to: {stage}.\n\nWe'll be in touch with next steps.\n\nThanks,\n{site_name}",
        ];
    }

    public static function get_settings()
    {
        $stored = get_option(self::SETTINGS_OPTION, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::default_settings());
    }

    public static function get_application_form_ids()
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            self::FORM_PURPOSE_META,
            self::PURPOSE_VALUE
        ));
        return array_map('intval', $ids);
    }

    public static function get_all_form_ids()
    {
        $posts = get_posts([
            'post_type'   => ['chat_form', 'basic_form'],
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        return array_map('intval', $posts ?: []);
    }

    public static function extract_email_from_submission($answers)
    {
        if (!is_array($answers)) return '';
        foreach ($answers as $key => $val) {
            $candidate = is_array($val) ? (isset($val['answer']) ? $val['answer'] : '') : $val;
            $qkey      = is_array($val) && isset($val['question']) ? strtolower($val['question']) : strtolower((string) $key);
            if (is_string($candidate) && is_email($candidate)) {
                return sanitize_email($candidate);
            }
            if (strpos($qkey, 'email') !== false && is_string($candidate)) {
                $maybe = sanitize_email($candidate);
                if ($maybe) return $maybe;
            }
        }
        return '';
    }

    public static function extract_name_from_submission($answers, $fallback = '')
    {
        if (!is_array($answers)) return $fallback;
        foreach ($answers as $key => $val) {
            $candidate = is_array($val) ? (isset($val['answer']) ? $val['answer'] : '') : $val;
            $qkey      = is_array($val) && isset($val['question']) ? strtolower($val['question']) : strtolower((string) $key);
            if (strpos($qkey, 'name') !== false && is_string($candidate) && trim($candidate) !== '') {
                return sanitize_text_field($candidate);
            }
        }
        return $fallback;
    }

    /* ================================================================
       Stage transition core
       ================================================================ */

    private function transition_applicant($submission_id, $stage_label)
    {
        $stages = self::get_stages();
        $matched_stage = null;
        foreach ($stages as $s) {
            if ($s['label'] === $stage_label) {
                $matched_stage = $s;
                break;
            }
        }
        if (!$matched_stage) return false;

        $previous = get_post_meta($submission_id, self::APPLICANT_STAGE_META, true);
        update_post_meta($submission_id, self::APPLICANT_STAGE_META, $stage_label);

        // Append to history
        $history = get_post_meta($submission_id, self::APPLICANT_HISTORY_META, true);
        if (!is_array($history)) $history = [];
        $history[] = [
            'stage' => $stage_label,
            'time'  => current_time('mysql'),
            'by'    => get_current_user_id(),
        ];
        update_post_meta($submission_id, self::APPLICANT_HISTORY_META, $history);

        // Role assignment + optional user creation
        $answers = get_post_meta($submission_id, '_chat_submission_data', true);
        $email   = self::extract_email_from_submission($answers);
        $name    = self::extract_name_from_submission($answers, $email);
        $user    = $email ? get_user_by('email', $email) : null;

        if (!$user && $email && !empty($matched_stage['auto_create'])) {
            $username = self::unique_username_from_email($email);
            $user_id  = wp_create_user($username, wp_generate_password(20), $email);
            if (!is_wp_error($user_id)) {
                $user = get_user_by('id', $user_id);
                wp_update_user(['ID' => $user_id, 'display_name' => $name ?: $username]);
                wp_new_user_notification($user_id, null, 'user'); // sends reset-password email
            }
        }

        if ($user && !empty($matched_stage['role'])) {
            $user->add_role($matched_stage['role']);
            update_post_meta($submission_id, self::APPLICANT_USER_META, $user->ID);
        }

        // Notifications (skip if same as previous to avoid spam on no-op saves)
        if ($previous !== $stage_label) {
            $this->send_stage_email($submission_id, $stage_label, $email, $name);
        }
        return true;
    }

    private static function unique_username_from_email($email)
    {
        $base = sanitize_user(strstr($email, '@', true), true) ?: 'user';
        $username = $base;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i;
            $i++;
        }
        return $username;
    }

    private function send_stage_email($submission_id, $stage_label, $email, $name)
    {
        $settings = self::get_settings();
        $tokens = [
            '{applicant_name}' => $name ?: 'there',
            '{stage}'          => $stage_label,
            '{site_name}'      => get_bloginfo('name'),
            '{site_url}'       => home_url(),
        ];

        $subject = strtr($settings['subject_template'], $tokens);
        $body    = strtr($settings['body_template'], $tokens);

        if (!empty($settings['notify_applicant']) && $email) {
            wp_mail($email, $subject, $body);
        }
        if (!empty($settings['notify_admin']) && !empty($settings['admin_email'])) {
            $admin_subject = sprintf('[%s] Applicant moved to %s', get_bloginfo('name'), $stage_label);
            $admin_body    = sprintf("Submission #%d moved to: %s\n\nApplicant: %s\nEmail: %s\n",
                $submission_id, $stage_label, $name ?: '(unknown)', $email ?: '(none)');
            wp_mail($settings['admin_email'], $admin_subject, $admin_body);
        }
    }

    /* ================================================================
       Form handlers
       ================================================================ */

    public function handle_save_form_purposes()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_save_application_form_purposes');

        $marked_ids   = isset($_POST['application_form_ids']) ? array_map('intval', (array) $_POST['application_form_ids']) : [];
        $all_form_ids = self::get_all_form_ids();

        foreach ($all_form_ids as $form_id) {
            $current = get_post_meta($form_id, self::FORM_PURPOSE_META, true);
            if (in_array($form_id, $marked_ids, true)) {
                update_post_meta($form_id, self::FORM_PURPOSE_META, self::PURPOSE_VALUE);
            } elseif ($current === self::PURPOSE_VALUE) {
                delete_post_meta($form_id, self::FORM_PURPOSE_META);
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'application_forms'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_stages()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_save_application_stages');

        $labels       = isset($_POST['stage_label']) ? (array) $_POST['stage_label'] : [];
        $roles        = isset($_POST['stage_role']) ? (array) $_POST['stage_role'] : [];
        $auto_creates = isset($_POST['stage_auto_create']) ? (array) $_POST['stage_auto_create'] : [];

        $stages = [];
        foreach ($labels as $i => $label) {
            $label = sanitize_text_field(wp_unslash($label));
            if ($label === '') continue;
            $stages[] = [
                'label'       => $label,
                'role'        => isset($roles[$i]) ? sanitize_key($roles[$i]) : '',
                'auto_create' => !empty($auto_creates[$i]) ? 1 : 0,
            ];
        }
        if (empty($stages)) {
            $stages = self::default_stages();
        }
        update_option(self::STAGES_OPTION, $stages);

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'application_stages'], admin_url('admin.php')));
        exit;
    }

    public function handle_save_settings()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_save_application_settings');

        $settings = [
            'notify_applicant' => !empty($_POST['notify_applicant']) ? 1 : 0,
            'notify_admin'     => !empty($_POST['notify_admin']) ? 1 : 0,
            'admin_email'      => sanitize_email(wp_unslash($_POST['admin_email'] ?? '')),
            'subject_template' => sanitize_text_field(wp_unslash($_POST['subject_template'] ?? '')),
            'body_template'    => wp_kses_post(wp_unslash($_POST['body_template'] ?? '')),
        ];
        update_option(self::SETTINGS_OPTION, $settings);

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'application_settings'], admin_url('admin.php')));
        exit;
    }

    public function handle_advance_applicant()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        $stage_label   = isset($_POST['stage']) ? sanitize_text_field(wp_unslash($_POST['stage'])) : '';
        check_admin_referer('em_advance_applicant_' . $submission_id);

        if ($submission_id && $stage_label !== '') {
            $this->transition_applicant($submission_id, $stage_label);
        }
        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'applicant_advanced'], admin_url('admin.php')));
        exit;
    }

    public function handle_bulk_advance()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_bulk_advance_applicants');

        $ids   = isset($_POST['applicant_ids']) ? array_map('intval', (array) $_POST['applicant_ids']) : [];
        $stage = isset($_POST['bulk_stage']) ? sanitize_text_field(wp_unslash($_POST['bulk_stage'])) : '';

        if (!empty($ids) && $stage !== '') {
            foreach ($ids as $id) {
                $this->transition_applicant($id, $stage);
            }
        }
        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'bulk_applicants'], admin_url('admin.php')));
        exit;
    }

    public function ajax_get_detail()
    {
        check_ajax_referer('em_app_support', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);

        $id = absint($_POST['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) wp_send_json_error(['message' => 'Not found'], 404);

        $answers = get_post_meta($id, '_chat_submission_data', true);
        $stage   = get_post_meta($id, self::APPLICANT_STAGE_META, true);
        $user_id = (int) get_post_meta($id, self::APPLICANT_USER_META, true);

        wp_send_json_success([
            'id'      => $id,
            'stage'   => $stage ?: 'Submitted',
            'email'   => self::extract_email_from_submission($answers),
            'user_id' => $user_id,
            'stages'  => self::get_stages(),
            'answers' => is_array($answers) ? $answers : [],
        ]);
    }

    /* ================================================================
       Render
       ================================================================ */

    public static function render()
    {
        $form_ids       = self::get_all_form_ids();
        $application_ids = self::get_application_form_ids();
        $stages          = self::get_stages();
        $settings        = self::get_settings();

        $submissions = [];
        if (!empty($application_ids)) {
            $submissions = get_posts([
                'post_type'   => 'chat_submission',
                'numberposts' => 200,
                'post_status' => ['publish', 'draft'],
                'meta_query'  => [['key' => '_chat_submission_form_id', 'value' => $application_ids, 'compare' => 'IN']],
            ]);
        }

        // KPI counts
        $by_stage = [];
        foreach ($submissions as $sub) {
            $st = get_post_meta($sub->ID, self::APPLICANT_STAGE_META, true) ?: ($stages[0]['label'] ?? 'Submitted');
            $by_stage[$st] = ($by_stage[$st] ?? 0) + 1;
        }
        ?>
        <div class="em-app-tab">

            <div class="gdc-subtabs">
                <button type="button" class="gdc-subtab active" data-subtab="postings" style="--em-i:0;">
                    <?php esc_html_e('Postings', 'email-manager'); ?>
                </button>
                <button type="button" class="gdc-subtab" data-subtab="applicants" style="--em-i:1;">
                    <?php esc_html_e('Applicants', 'email-manager'); ?>
                </button>
                <button type="button" class="gdc-subtab" data-subtab="application-stages" style="--em-i:2;">
                    <?php esc_html_e('Stages &amp; Roles', 'email-manager'); ?>
                </button>
                <button type="button" class="gdc-subtab" data-subtab="application-settings" style="--em-i:3;">
                    <?php esc_html_e('Settings', 'email-manager'); ?>
                </button>
            </div>

            <?php
            if (class_exists('EM_Postings')) {
                EM_Postings::render_panel();
            }
            ?>
            <?php self::render_applicants_panel($submissions, $stages, $by_stage, count($application_ids)); ?>
            <?php self::render_stages_panel($stages); ?>
            <?php self::render_settings_panel($settings); ?>
        </div>
        <?php
    }

    private static function render_kpi_strip($total, $by_stage, $stages, $form_count)
    {
        ?>
        <div class="em-kpi-grid">
            <div class="em-kpi" style="--em-i:0;">
                <div class="em-kpi__label"><?php esc_html_e('Total Applicants', 'email-manager'); ?></div>
                <div class="em-kpi__value"><?php echo esc_html(number_format_i18n($total)); ?></div>
                <div class="em-kpi__hint"><?php echo esc_html(sprintf(_n('%d application form', '%d application forms', $form_count, 'email-manager'), $form_count)); ?></div>
            </div>
            <?php $i = 1; foreach ($stages as $s): $count = $by_stage[$s['label']] ?? 0; ?>
                <div class="em-kpi" style="--em-i:<?php echo (int) $i; ?>;">
                    <div class="em-kpi__label"><?php echo esc_html($s['label']); ?></div>
                    <div class="em-kpi__value"><?php echo esc_html(number_format_i18n($count)); ?></div>
                    <div class="em-kpi__hint"><?php echo $s['role'] ? esc_html(sprintf(__('grants %s role', 'email-manager'), $s['role'])) : esc_html__('no role assigned', 'email-manager'); ?></div>
                </div>
            <?php $i++; endforeach; ?>
        </div>
        <?php
    }

    private static function render_applicants_panel($submissions, $stages, $by_stage = [], $form_count = 0)
    {
        ?>
        <div class="gdc-subtab-panel" data-subpanel="applicants" hidden>
            <?php self::render_kpi_strip(count($submissions), $by_stage, $stages, $form_count); ?>
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Applicants', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Click any row to open the full application in a side drawer.', 'email-manager'); ?></p>
                    </div>
                </div>

                <?php if (empty($submissions)): ?>
                    <div class="em-empty">
                        <div class="em-empty__icon"><span class="dashicons dashicons-id-alt"></span></div>
                        <div class="em-empty__title"><?php esc_html_e('No applicants yet', 'email-manager'); ?></div>
                        <div><?php esc_html_e('Mark forms as application forms to start collecting applications.', 'email-manager'); ?></div>
                    </div>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="em-bulk-scope">
                        <?php wp_nonce_field('em_bulk_advance_applicants'); ?>
                        <input type="hidden" name="action" value="em_bulk_advance_applicants" />
                        <div class="em-bulk-bar">
                            <span class="em-bulk-bar__count"><strong>0</strong> <?php esc_html_e('selected', 'email-manager'); ?></span>
                            <select name="bulk_stage">
                                <option value=""><?php esc_html_e('— Move to stage —', 'email-manager'); ?></option>
                                <?php foreach ($stages as $s): ?>
                                    <option value="<?php echo esc_attr($s['label']); ?>"><?php echo esc_html($s['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Apply to Selected', 'email-manager'); ?></button>
                        </div>
                        <div class="gdc-table-wrap">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th style="width:32px;"><input type="checkbox" class="em-bulk-select-all" /></th>
                                        <th><?php esc_html_e('Applicant', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Form', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Submitted', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Stage', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Move To', 'email-manager'); ?></th>
                                        <th><?php esc_html_e('Detail', 'email-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $i => $sub):
                                        $form_id    = (int) get_post_meta($sub->ID, '_chat_submission_form_id', true);
                                        $answers    = get_post_meta($sub->ID, '_chat_submission_data', true);
                                        $email      = self::extract_email_from_submission($answers);
                                        $name       = self::extract_name_from_submission($answers, $sub->post_title ?: '(unnamed)');
                                        $stage      = get_post_meta($sub->ID, self::APPLICANT_STAGE_META, true) ?: ($stages[0]['label'] ?? 'Submitted');
                                        $form_title = $form_id ? get_the_title($form_id) : '—';
                                    ?>
                                        <tr class="em-row" style="--em-i:<?php echo (int) $i; ?>;">
                                            <td><input type="checkbox" class="em-bulk-row-check" name="applicant_ids[]" value="<?php echo esc_attr($sub->ID); ?>" /></td>
                                            <td>
                                                <strong><?php echo esc_html($name); ?></strong>
                                                <?php if ($email): ?><br><small><?php echo esc_html($email); ?></small><?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($form_title); ?></td>
                                            <td><?php echo esc_html(get_the_date('', $sub->ID)); ?></td>
                                            <td><span class="em-pill em-pill--info"><?php echo esc_html($stage); ?></span></td>
                                            <td>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;gap:6px;">
                                                    <?php wp_nonce_field('em_advance_applicant_' . $sub->ID); ?>
                                                    <input type="hidden" name="action" value="em_advance_applicant" />
                                                    <input type="hidden" name="submission_id" value="<?php echo esc_attr($sub->ID); ?>" />
                                                    <select name="stage">
                                                        <?php foreach ($stages as $s): ?>
                                                            <option value="<?php echo esc_attr($s['label']); ?>" <?php selected($stage, $s['label']); ?>>
                                                                <?php echo esc_html($s['label']); ?><?php if (!empty($s['role'])): ?> (+<?php echo esc_html($s['role']); ?>)<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="button button-small"><?php esc_html_e('Apply', 'email-manager'); ?></button>
                                                </form>
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small em-view-applicant"
                                                    data-submission-id="<?php echo esc_attr($sub->ID); ?>"
                                                    data-name="<?php echo esc_attr($name); ?>">
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
        <div class="gdc-subtab-panel" data-subpanel="application-forms" hidden>
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Application Forms', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Mark which forms or chatflows produce applications.', 'email-manager'); ?></p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('em_save_application_form_purposes'); ?>
                    <input type="hidden" name="action" value="em_save_application_form_purposes" />
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
                                        $purpose = get_post_meta($fid, self::FORM_PURPOSE_META, true);
                                        $checked = ($purpose === self::PURPOSE_VALUE);
                                    ?>
                                        <tr class="em-row" style="--em-i:<?php echo (int) $i; ?>;">
                                            <td><input type="checkbox" name="application_form_ids[]" value="<?php echo esc_attr($fid); ?>" <?php checked($checked); ?> /></td>
                                            <td><strong><?php echo esc_html($post->post_title ?: '(no title)'); ?></strong></td>
                                            <td><?php echo esc_html($post->post_type === 'chat_form' ? __('Chatflow', 'email-manager') : __('Form', 'email-manager')); ?></td>
                                            <td>
                                                <?php
                                                if ($purpose === self::PURPOSE_VALUE) {
                                                    echo '<span class="em-pill em-pill--success">' . esc_html__('Application', 'email-manager') . '</span>';
                                                } elseif ($purpose === 'support') {
                                                    echo '<span class="em-pill em-pill--info">' . esc_html__('Support', 'email-manager') . '</span>';
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
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Application Forms', 'email-manager'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_stages_panel($stages)
    {
        $editable_roles = function_exists('get_editable_roles') ? get_editable_roles() : wp_roles()->roles;
        ?>
        <div class="gdc-subtab-panel" data-subpanel="application-stages" hidden>
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Application Stages &amp; Roles', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Order matters. When an applicant moves to a stage with a role, that role is added to the matching WordPress user. Optionally auto-create the user if no match.', 'email-manager'); ?></p>
                    </div>
                </div>

                <div class="em-stage-flow">
                    <?php foreach ($stages as $i => $s): ?>
                        <span class="em-stage-chip" style="--em-i:<?php echo (int) $i; ?>;">
                            <?php echo esc_html($s['label']); ?>
                            <?php if ($s['role']): ?><span class="em-stage-chip__role">+<?php echo esc_html($s['role']); ?></span><?php endif; ?>
                            <?php if ($i < count($stages) - 1): ?><span class="em-stage-chip__arrow">→</span><?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('em_save_application_stages'); ?>
                    <input type="hidden" name="action" value="em_save_application_stages" />
                    <div class="gdc-table-wrap">
                        <table class="widefat striped" id="em-stages-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Stage Label', 'email-manager'); ?></th>
                                    <th><?php esc_html_e('Role to Add', 'email-manager'); ?></th>
                                    <th><?php esc_html_e('Auto-create User', 'email-manager'); ?></th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stages as $i => $s): ?>
                                    <tr class="em-row" style="--em-i:<?php echo (int) $i; ?>;">
                                        <td><input type="text" name="stage_label[]" value="<?php echo esc_attr($s['label']); ?>" required /></td>
                                        <td>
                                            <select name="stage_role[]">
                                                <option value=""><?php esc_html_e('— No role —', 'email-manager'); ?></option>
                                                <?php foreach ($editable_roles as $role_key => $role_data):
                                                    $rname = is_array($role_data) && isset($role_data['name']) ? $role_data['name'] : ucfirst($role_key);
                                                    ?>
                                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($s['role'], $role_key); ?>><?php echo esc_html($rname); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><label><input type="checkbox" name="stage_auto_create[<?php echo (int) $i; ?>]" value="1" <?php checked(!empty($s['auto_create'])); ?> /> <?php esc_html_e('If no user matches by email', 'email-manager'); ?></label></td>
                                        <td><button type="button" class="button button-small em-remove-stage">&times;</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p style="margin-top:12px;display:flex;gap:8px;">
                        <button type="button" class="button" id="em-add-stage"><?php esc_html_e('+ Add Stage', 'email-manager'); ?></button>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Stages', 'email-manager'); ?></button>
                    </p>
                </form>
                <script>
                    jQuery(function ($) {
                        var $tbl = $('#em-stages-table tbody');
                        function reindexAutoCreate() {
                            $tbl.find('tr').each(function (i) {
                                $(this).find('input[name^="stage_auto_create"]').attr('name', 'stage_auto_create[' + i + ']');
                            });
                        }
                        $('#em-add-stage').on('click', function () {
                            var $row = $tbl.find('tr').last().clone();
                            $row.find('input[type="text"]').val('');
                            $row.find('select').val('');
                            $row.find('input[type="checkbox"]').prop('checked', false);
                            $tbl.append($row);
                            reindexAutoCreate();
                        });
                        $tbl.on('click', '.em-remove-stage', function () {
                            if ($tbl.find('tr').length > 1) {
                                $(this).closest('tr').remove();
                                reindexAutoCreate();
                            }
                        });
                    });
                </script>
            </div>
        </div>
        <?php
    }

    private static function render_settings_panel($settings)
    {
        ?>
        <div class="gdc-subtab-panel" data-subpanel="application-settings" hidden>
            <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                <div class="gdc-email-panel__header">
                    <div>
                        <h3><?php esc_html_e('Notification Settings', 'email-manager'); ?></h3>
                        <p class="description"><?php esc_html_e('Customize the emails sent when an applicant moves to a new stage. Tokens: {applicant_name}, {stage}, {site_name}, {site_url}.', 'email-manager'); ?></p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('em_save_application_settings'); ?>
                    <input type="hidden" name="action" value="em_save_application_settings" />

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Email applicant on stage change', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Sends to the email captured in the application form.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <label><input type="checkbox" name="notify_applicant" value="1" <?php checked(!empty($settings['notify_applicant'])); ?> /> <?php esc_html_e('Enabled', 'email-manager'); ?></label>
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Notify admin', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Sends a quiet copy to the address below.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <label style="display:block;margin-bottom:8px;"><input type="checkbox" name="notify_admin" value="1" <?php checked(!empty($settings['notify_admin'])); ?> /> <?php esc_html_e('Enabled', 'email-manager'); ?></label>
                            <input type="email" name="admin_email" value="<?php echo esc_attr($settings['admin_email']); ?>" placeholder="admin@example.com" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Subject template', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="text" name="subject_template" value="<?php echo esc_attr($settings['subject_template']); ?>" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Body template', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <textarea name="body_template"><?php echo esc_textarea($settings['body_template']); ?></textarea>
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

new EM_Applications();
