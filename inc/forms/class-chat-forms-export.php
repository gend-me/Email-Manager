<?php
/**
 * Chat Forms Export Handler
 * Handles CSV export of form submissions
 */

class Chat_Forms_Export
{
    public function __construct()
    {
        add_action('admin_init', array($this, 'handle_export'));
        add_action('admin_footer', array($this, 'add_export_button_script'));
    }

    /**
     * Add export button to submissions page
     */
    public function add_export_button_script()
    {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'chat_submission') {
            ?>
            <script>
                jQuery(document).ready(function ($) {
                    // Add export button to submissions page
                    $('.wrap h1').after('<a href="<?php echo admin_url('edit.php?post_type=chat_submission&export=csv'); ?>" class="page-title-action" style="background: #2271b1; color: white;">📥 Export to CSV</a>');
                        });
            </script>
            <?php
        }
    }

    /**
     * Handle CSV export request
     */
    public function handle_export()
    {
        if (!isset($_GET['export']) || $_GET['export'] !== 'csv') {
            return;
        }

        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'chat_submission') {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Get all submissions
        $args = array(
            'post_type' => 'chat_submission',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $submissions = get_posts($args);

        if (empty($submissions)) {
            wp_die('No submissions to export');
        }

        // Generate CSV
        $this->generate_csv($submissions);
    }

    /**
     * Generate and download CSV file
     */
    private function generate_csv($submissions)
    {
        // Set headers for download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=chat-forms-submissions-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Collect all unique questions across all submissions
        $all_questions = array();
        foreach ($submissions as $submission) {
            $answers = get_post_meta($submission->ID, '_chat_form_answers', true);
            if (is_array($answers)) {
                foreach ($answers as $question_id => $answer_data) {
                    $question_text = isset($answer_data['question']) ? $answer_data['question'] : 'Question ' . $question_id;
                    if (!in_array($question_text, $all_questions)) {
                        $all_questions[] = $question_text;
                    }
                }
            }
        }

        // Build header row
        $headers = array('Submission ID', 'Form Name', 'Date', 'Time');
        foreach ($all_questions as $question) {
            $headers[] = $question;
        }
        fputcsv($output, $headers);

        // Build data rows
        foreach ($submissions as $submission) {
            $form_id = get_post_meta($submission->ID, '_chat_form_id', true);
            $form_name = $form_id ? get_the_title($form_id) : 'Unknown Form';
            $answers = get_post_meta($submission->ID, '_chat_form_answers', true);

            $row = array(
                $submission->ID,
                $form_name,
                get_the_date('Y-m-d', $submission),
                get_the_time('H:i:s', $submission)
            );

            // Add answers in order of questions
            foreach ($all_questions as $question) {
                $answer_value = '';
                if (is_array($answers)) {
                    foreach ($answers as $answer_data) {
                        $q_text = isset($answer_data['question']) ? $answer_data['question'] : '';
                        if ($q_text === $question) {
                            $answer_value = isset($answer_data['answer']) ? $answer_data['answer'] : '';
                            break;
                        }
                    }
                }
                $row[] = $answer_value;
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}

// Initialize
new Chat_Forms_Export();
