<?php
/**
 * Chats module — show chatflow submissions grouped by submitter and let staff
 * read the entire conversation as a chat-bubble transcript in the side drawer.
 *
 * Reuses the existing chat_submission CPT (registered by the forms module)
 * and the existing detail drawer (rendered once in email-manager-admin.php).
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Chats
{
    public function __construct()
    {
        add_action('wp_ajax_em_get_chat_detail', [$this, 'ajax_get_detail']);
    }

    public static function get_chat_form_ids()
    {
        $posts = get_posts([
            'post_type'   => 'chat_form',
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'fields'      => 'ids',
        ]);
        return array_map('intval', $posts ?: []);
    }

    public static function get_chat_submissions()
    {
        $form_ids = self::get_chat_form_ids();
        if (empty($form_ids)) return [];
        return get_posts([
            'post_type'   => 'chat_submission',
            'numberposts' => 300,
            'post_status' => ['publish', 'draft'],
            'meta_query'  => [['key' => '_chat_submission_form_id', 'value' => $form_ids, 'compare' => 'IN']],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
    }

    public static function group_by_member($submissions)
    {
        $groups = [];
        foreach ($submissions as $sub) {
            $answers = get_post_meta($sub->ID, '_chat_submission_data', true);
            $email   = class_exists('EM_Applications') ? EM_Applications::extract_email_from_submission($answers) : '';
            $name    = class_exists('EM_Applications') ? EM_Applications::extract_name_from_submission($answers, $sub->post_title) : ($sub->post_title ?: '(unnamed)');
            $key     = $email ?: ('anon_' . $sub->ID);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name'  => $name ?: '(unnamed)',
                    'email' => $email,
                    'subs'  => [],
                ];
            }
            $groups[$key]['subs'][] = $sub;
        }
        // Sort groups by most recent submission
        uasort($groups, function ($a, $b) {
            $ad = isset($a['subs'][0]) ? strtotime($a['subs'][0]->post_date) : 0;
            $bd = isset($b['subs'][0]) ? strtotime($b['subs'][0]->post_date) : 0;
            return $bd <=> $ad;
        });
        return $groups;
    }

    public function ajax_get_detail()
    {
        check_ajax_referer('em_app_support', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);

        $id = absint($_POST['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) wp_send_json_error(['message' => 'Not found'], 404);

        $answers = get_post_meta($id, '_chat_submission_data', true);
        $form_id = (int) get_post_meta($id, '_chat_submission_form_id', true);

        // Build a chat-style transcript from answers
        $turns = [];
        if (is_array($answers)) {
            foreach ($answers as $key => $val) {
                $q = ''; $a = '';
                if (is_array($val)) {
                    $q = isset($val['question']) ? $val['question'] : (string) $key;
                    $a = isset($val['answer'])   ? $val['answer']   : '';
                } else {
                    $q = is_numeric($key) ? sprintf('Question %d', ((int) $key) + 1) : str_replace('_', ' ', (string) $key);
                    $a = $val;
                }
                $turns[] = ['role' => 'bot',  'text' => (string) $q];
                $turns[] = ['role' => 'user', 'text' => (string) $a];
            }
        }

        wp_send_json_success([
            'id'         => $id,
            'name'       => class_exists('EM_Applications') ? EM_Applications::extract_name_from_submission($answers, $post->post_title) : ($post->post_title ?: '(unnamed)'),
            'email'      => class_exists('EM_Applications') ? EM_Applications::extract_email_from_submission($answers) : '',
            'form_id'    => $form_id,
            'form_title' => $form_id ? get_the_title($form_id) : '',
            'date'       => get_the_date('', $id),
            'turns'      => $turns,
        ]);
    }

    public static function render()
    {
        $submissions = self::get_chat_submissions();
        $groups      = self::group_by_member($submissions);
        ?>
        <div class="gdc-email-panel em-reveal" style="--em-i:0;">
            <div class="gdc-email-panel__header">
                <div>
                    <h3><?php esc_html_e('Chats', 'email-manager'); ?></h3>
                    <p class="description"><?php esc_html_e('Members who completed a chatflow. Click any chat to read the full transcript.', 'email-manager'); ?></p>
                </div>
            </div>

            <?php if (empty($groups)): ?>
                <div class="em-empty">
                    <div class="em-empty__icon"><span class="dashicons dashicons-format-chat"></span></div>
                    <div class="em-empty__title"><?php esc_html_e('No chats yet', 'email-manager'); ?></div>
                    <div><?php esc_html_e('Once members complete a chatflow their conversations appear here.', 'email-manager'); ?></div>
                </div>
            <?php else: ?>
                <div class="gdc-table-wrap">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Member', 'email-manager'); ?></th>
                                <th><?php esc_html_e('Chats', 'email-manager'); ?></th>
                                <th><?php esc_html_e('Latest Flow', 'email-manager'); ?></th>
                                <th><?php esc_html_e('Latest', 'email-manager'); ?></th>
                                <th><?php esc_html_e('History', 'email-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $row_i = 0; foreach ($groups as $group):
                                $latest = $group['subs'][0];
                                $form_id = (int) get_post_meta($latest->ID, '_chat_submission_form_id', true);
                                $form_title = $form_id ? get_the_title($form_id) : '—';
                            ?>
                                <tr class="em-row" style="--em-i:<?php echo (int) $row_i; ?>;">
                                    <td>
                                        <strong><?php echo esc_html($group['name']); ?></strong>
                                        <?php if ($group['email']): ?><br><small><?php echo esc_html($group['email']); ?></small><?php endif; ?>
                                    </td>
                                    <td><span class="em-pill em-pill--info"><?php echo esc_html(count($group['subs'])); ?></span></td>
                                    <td><?php echo esc_html($form_title); ?></td>
                                    <td><?php echo esc_html(get_the_date('', $latest->ID)); ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <?php foreach ($group['subs'] as $sub):
                                                $sub_form_id = (int) get_post_meta($sub->ID, '_chat_submission_form_id', true);
                                                $sub_form_title = $sub_form_id ? get_the_title($sub_form_id) : '—';
                                                $sub_label = sprintf('%s · %s', $sub_form_title, get_the_date(get_option('date_format'), $sub->ID));
                                            ?>
                                                <button type="button" class="button button-small em-view-chat"
                                                    data-submission-id="<?php echo esc_attr($sub->ID); ?>"
                                                    data-name="<?php echo esc_attr($group['name'] ?: $sub_label); ?>"
                                                    title="<?php echo esc_attr($sub_label); ?>">
                                                    <?php echo esc_html(get_the_date('M j', $sub->ID)); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php $row_i++; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

new EM_Chats();
