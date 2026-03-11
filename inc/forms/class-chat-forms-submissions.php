<?php

class Chat_Forms_Submissions
{

    public function __construct()
    {
        add_filter('manage_chat_submission_posts_columns', array($this, 'set_custom_columns'));
        add_action('manage_chat_submission_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
        add_filter('manage_edit-chat_submission_sortable_columns', array($this, 'sortable_columns'));
        add_action('add_meta_boxes', array($this, 'add_submission_metabox'));

        // Admin Footer for Modal
        add_action('admin_footer', array($this, 'render_submission_modal'));

        // AJAX for submission details
        add_action('wp_ajax_chat_forms_get_submission_details', array($this, 'get_submission_details'));
    }

    public function get_submission_details()
    {
        // Verify nonce usually, but for admin ajax we rely on capability check
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Unauthorized');
        }

        $post_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        if (!$post_id) {
            wp_send_json_error('Invalid ID');
        }

        // Reuse the metabox rendering logic but capture output
        ob_start();
        $this->render_submission_details_metabox(get_post($post_id));
        $content = ob_get_clean();

        wp_send_json_success(array('content' => $content));
    }

    public function add_submission_metabox()
    {
        add_meta_box(
            'chat_forms_submission_details',
            __('Submission Details', 'chat-forms'),
            array($this, 'render_submission_details_metabox'),
            'chat_submission',
            'normal',
            'high'
        );
    }

    public function render_submission_details_metabox($post)
    {
        $form_id = get_post_meta($post->ID, '_chat_submission_form_id', true);
        $answers = get_post_meta($post->ID, '_chat_submission_data', true);
        $questions = array();

        if ($form_id) {
            $questions = get_post_meta($form_id, '_chat_form_questions', true);
            if (!is_array($questions)) {
                $questions = array();
            }
        }
        ?>
        <style>
            .submission-details-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }

            .submission-details-table th,
            .submission-details-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #e5e5e5;
            }

            .submission-details-table th {
                background: #f9f9f9;
                font-weight: 600;
                width: 30%;
            }

            .submission-details-table tr:hover {
                background: #fafafa;
            }
        </style>

        <div class="submission-details-wrapper">
            <?php if ($form_id): ?>
                <p><strong><?php _e('Form:', 'chat-forms'); ?></strong>
                    <a href="<?php echo get_edit_post_link($form_id); ?>"><?php echo get_the_title($form_id); ?></a>
                </p>
            <?php endif; ?>

            <?php if (is_array($answers) && !empty($answers)): ?>
                <table class="submission-details-table">
                    <thead>
                        <tr>
                            <th><?php _e('Question', 'chat-forms'); ?></th>
                            <th><?php _e('Answer', 'chat-forms'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($answers as $question_id => $answer): ?>
                            <tr>
                                <td>
                                    <?php
                                    // Try to get actual question text
                                    if (isset($questions[$question_id]['text'])) {
                                        echo esc_html($questions[$question_id]['text']);
                                    } else {
                                        echo esc_html('Question ' . ($question_id + 1));
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($answer); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No submission data available.', 'chat-forms'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function set_custom_columns($columns)
    {
        // Remove default columns
        unset($columns['date']);

        // Add custom columns
        $new_columns = array(
            'cb' => $columns['cb'],
            'title' => __('Submission ID', 'chat-forms'),
            'form_name' => __('Form Name', 'chat-forms'),
            'answers_preview' => __('Answers Preview', 'chat-forms'),
            'submitted_date' => __('Submitted', 'chat-forms'),
            'view_submission' => __('Actions', 'chat-forms'),
        );

        return $new_columns;
    }

    public function custom_column_content($column, $post_id)
    {
        switch ($column) {
            case 'form_name':
                $form_id = get_post_meta($post_id, '_chat_submission_form_id', true);
                if ($form_id) {
                    $form_title = get_the_title($form_id);
                    echo '<a href="' . get_edit_post_link($form_id) . '">' . esc_html($form_title) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'answers_preview':
                $answers = get_post_meta($post_id, '_chat_submission_data', true);
                if (is_array($answers) && !empty($answers)) {
                    $first_answer = reset($answers);
                    echo '<span class="answer-preview">' . esc_html(wp_trim_words($first_answer, 10)) . '</span>';
                    if (count($answers) > 1) {
                        echo ' <span class="answer-count">+' . (count($answers) - 1) . ' more</span>';
                    }
                } else {
                    echo '—';
                }
                break;

            case 'submitted_date':
                $date = get_the_date('M j, Y @ g:i a', $post_id);
                echo esc_html($date);
                break;

            case 'view_submission':
                echo '<button type="button" class="button button-secondary view-submission-btn" data-id="' . $post_id . '">';
                echo '<span class="dashicons dashicons-visibility" style="margin-top:4px;"></span> ' . __('View', 'chat-forms');
                echo '</button>';
                break;
        }
    }

    public function sortable_columns($columns)
    {
        $columns['form_name'] = 'form_name';
        $columns['submitted_date'] = 'date';
        return $columns;
    }

    public function render_submission_modal()
    {
        $screen = get_current_screen();
        if ($screen->post_type !== 'chat_submission')
            return;
        ?>
        <div id="chat-submission-modal"
            style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:99999;">
            <div class="chat-modal-backdrop"
                style="position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6);"></div>
            <div class="chat-modal-content"
                style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); width:600px; max-width:90%; background:#fff; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); overflow:hidden; animation: slideIn 0.3s ease;">
                <div class="chat-modal-header"
                    style="padding:15px 20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; background:#f5f5f5;">
                    <h3 style="margin:0; font-size:16px;"><?php _e('Submission Details', 'chat-forms'); ?></h3>
                    <button type="button" class="chat-modal-close"
                        style="background:none; border:none; cursor:pointer; font-size:20px; color:#666;">&times;</button>
                </div>
                <div class="chat-modal-body" style="padding:20px; max-height:70vh; overflow-y:auto;">
                    <div class="chat-modal-loading" style="text-align:center; padding:20px; color:#666;">
                        <span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span>
                        <?php _e('Loading...', 'chat-forms'); ?>
                    </div>
                    <div class="chat-modal-data"></div>
                </div>
            </div>
        </div>
        <?php
    }
}
