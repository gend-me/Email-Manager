<?php
/**
 * Admin Page Rendering for Email Manager
 *
 * @package EmailManager
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

function em_render_email_manager_page()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        return;
    }

    $gdc_email_embed_single = false; // Standalone usually false unless embedded elsewhere
    $gdc_email_wrap_class = '';
    $gdc_email_embed_panel = '';

    // Define tabs
    $tabs = array(
        'engagement' => __('Engagement', 'email-manager'),
        'proposals' => __('Proposals', 'email-manager'),
        'system-emails' => __('System Emails', 'email-manager'),
        'app-template' => __('App Template', 'email-manager'),
        'logs' => __('Logs', 'email-manager'),
        'sending-settings' => __('Sending Settings', 'email-manager'),
    );

    // Get Mock Data or Actual Data
    // For System Emails, we need to fetch them or define them.
    // In GenD Core, they came from global variables or helper functions.
    // We will define empty arrays or mock data for now to avoid errors, 
    // as fetching logic might be complex dependency in other files.
    $gdc_store_emails = array();
    $gdc_community_emails = array();
    $gdc_reward_emails = array();

    // Placeholder URL for edit links
    $gdc_nurture_embed_url = admin_url('admin.php?page=email-manager&view=nurture'); // Example

    ?>
    <div class="wrap gdc-admin-dashboard gdc-app-content">

        <!-- Email Tab (Complete Port) -->
        <div class="gdc-tabpanel" data-panel="email"> <!-- Removed hidden attribute as this is the main page -->
            <div class="gdc-admin-surface gdc-email-page<?php echo esc_attr($gdc_email_wrap_class); ?>"
                data-default-panel="<?php echo esc_attr($gdc_email_embed_panel); ?>">
                <?php if ($gdc_email_embed_single): ?>
                    <style>
                        .gdc-email-page--embed .gdc-page-header,
                        .gdc-email-page--embed .gdc-tabs.gdc-tabs--pills {
                            display: none !important;
                        }

                        .gdc-email-page--embed .gdc-tabpanels {
                            margin-top: 0 !important;
                        }

                        .gdc-email-page--embed .gdc-sub-tabpanel {
                            padding-top: 0 !important;
                        }
                    </style>
                <?php endif; ?>
                <div class="gdc-page-header">
                    <div class="gdc-page-header__text">
                        <p class="gdc-kicker">
                            <?php esc_html_e('Messaging Control Center', 'email-manager'); ?>
                        </p>
                        <h1>
                            <?php esc_html_e('Email Workspace', 'email-manager'); ?>
                        </h1>
                        <p class="gdc-lead">
                            <?php esc_html_e('Align every automated email - from store receipts to community alerts - with your brand voice.', 'email-manager'); ?>
                        </p>
                    </div>
                    <div class="gdc-page-header__meta">
                        <span class="gdc-pill">
                            <?php esc_html_e('Live Automations', 'email-manager'); ?>
                        </span>
                        <span class="gdc-pill gdc-pill--accent">
                            <?php esc_html_e('App-wide Branding', 'email-manager'); ?>
                        </span>
                    </div>
                </div>
                <div class="gdc-tabs gdc-tabs--pills" role="tablist"
                    aria-label="<?php esc_attr_e('Email Sections', 'email-manager'); ?>">
                    <?php
                    // New Tab Structure
                    $first = true;
                    foreach ($tabs as $slug => $label):
                        ?>
                        <button type="button" class="gdc-sub-tab <?php echo $first ? 'active' : ''; ?>"
                            data-tab="<?php echo esc_attr($slug); ?>" role="tab"
                            aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                            <?php echo esc_html($label); ?>
                        </button>
                        <?php
                        $first = false;
                    endforeach;
                    ?>
                </div>

                <div class="gdc-tabpanels">
                    <!-- Engagement Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="engagement" <?php echo $gdc_email_embed_single ? 'hidden' : ''; ?>>
                        <div class="gdc-subtabs" style="margin-bottom: 20px; border-bottom: 1px solid #e0e0e0;">
                            <button type="button" class="gdc-subtab active" data-subtab="lists"
                                style="background:none; border:none; border-bottom: 2px solid #6366f1; padding: 10px 15px; cursor: pointer; font-weight: 500; color: #6366f1;">
                                <?php esc_html_e('Lists', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="onboarding"
                                style="background:none; border:none; border-bottom: 2px solid transparent; padding: 10px 15px; cursor: pointer; font-weight: 500; color: #64748b;">
                                <?php esc_html_e('Onboarding Emails', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="newsletters"
                                style="background:none; border:none; border-bottom: 2px solid transparent; padding: 10px 15px; cursor: pointer; font-weight: 500; color: #64748b;">
                                <?php esc_html_e('Newsletters', 'email-manager'); ?>
                            </button>
                        </div>

                        <!-- Lists Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="lists">
                            <div class="gdc-email-panel">
                                <div class="gdc-email-panel__header">
                                    <div class="gdc-email-search">
                                        <label class="screen-reader-text" for="gdc-email-lists-search">
                                            <?php esc_html_e('Search lists', 'email-manager'); ?>
                                        </label>
                                        <input type="search" id="gdc-email-lists-search"
                                            placeholder="<?php esc_attr_e('Search subscriber lists…', 'email-manager'); ?>">
                                    </div>
                                    <button type="button" class="button button-primary" id="gdc-email-add-list">
                                        <?php esc_html_e('Add New List', 'email-manager'); ?>
                                    </button>
                                </div>
                                <div class="gdc-table-wrap">
                                    <table class="widefat striped" id="gdc-email-lists-table">
                                        <thead>
                                            <tr>
                                                <th>
                                                    <?php esc_html_e('Name', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Description', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Subscriber Count', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Created At', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Actions', 'email-manager'); ?>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Onboarding Emails Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="onboarding" hidden>
                            <?php
                            $gdc_onboarding_rows = array();
                            $gdc_onboarding_rows[] = array(
                                'type' => __('Onboarding', 'email-manager'),
                                'type_slug' => 'timed',
                                'trigger' => __('Role gain or product purchase', 'email-manager'),
                                'status' => __('Draft', 'email-manager'),
                                'enabled' => true,
                                'sequence' => 1,
                                'edit_url' => $gdc_nurture_embed_url,
                                'edit_title' => __('Open nurture workspace', 'email-manager'),
                                'email_data' => array(
                                    'section' => 'timed',
                                    'label' => __('Welcome Series', 'email-manager'),
                                    'trigger' => __('New User Registration', 'email-manager'),
                                    'status' => __('Draft', 'email-manager'),
                                    'description' => __('Welcome new users and introduce them to the platform.', 'email-manager'),
                                    'subject' => __('Welcome to the community!', 'email-manager'),
                                ),
                            );
                            ?>
                            <div class="gdc-email-panel">
                                <div class="gdc-email-panel__header">
                                    <h3>
                                        <?php esc_html_e('Onboarding Sequences', 'email-manager'); ?>
                                    </h3>
                                    <button type="button" class="button button-primary" id="gdc-email-add-sequence">
                                        <?php esc_html_e('Add New Sequence', 'email-manager'); ?>
                                    </button>
                                </div>
                                <div class="gdc-table-wrap">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th>
                                                    <?php esc_html_e('Campaign Name', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Trigger', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Status', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Actions', 'email-manager'); ?>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($gdc_onboarding_rows as $row): ?>
                                                <tr>
                                                    <td><strong>
                                                            <?php echo esc_html($row['email_data']['label']); ?>
                                                        </strong></td>
                                                    <td>
                                                        <?php echo esc_html($row['trigger']); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo esc_html($row['status']); ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="button button-small gdc-email-open-editor"
                                                            data-email-section="<?php echo esc_attr($row['type_slug']); ?>"
                                                            data-edit-url="<?php echo esc_url($row['edit_url']); ?>"
                                                            data-edit-title="<?php echo esc_attr($row['edit_title']); ?>"
                                                            data-email='<?php echo json_encode($row['email_data']); ?>'>
                                                            <?php esc_html_e('Edit', 'email-manager'); ?>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Newsletters Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="newsletters" hidden>
                            <?php
                            $gdc_newsletter_rows = array();
                            $gdc_newsletter_rows[] = array(
                                'type' => __('Newsletter', 'email-manager'),
                                'type_slug' => 'timed',
                                'trigger' => __('Scheduled Time', 'email-manager'),
                                'status' => __('Scheduled', 'email-manager'),
                                'enabled' => true,
                                'sequence' => 1,
                                'edit_url' => $gdc_nurture_embed_url,
                                'edit_title' => __('Open nurture workspace', 'email-manager'),
                                'email_data' => array(
                                    'section' => 'timed',
                                    'label' => __('Weekly Digest', 'email-manager'),
                                    'trigger' => __('Every Monday', 'email-manager'),
                                    'status' => __('Scheduled', 'email-manager'),
                                    'description' => __('Weekly summary of top content.', 'email-manager'),
                                    'subject' => __('Your Weekly Update', 'email-manager'),
                                ),
                            );
                            ?>
                            <div class="gdc-email-panel">
                                <div class="gdc-email-panel__header">
                                    <h3>
                                        <?php esc_html_e('Scheduled Newsletters', 'email-manager'); ?>
                                    </h3>
                                    <button type="button" id="gdc-email-add-newsletter" class="button button-primary">
                                        <?php esc_html_e('Create Newsletter', 'email-manager'); ?>
                                    </button>
                                </div>
                                <div class="gdc-table-wrap">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th>
                                                    <?php esc_html_e('Subject', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Send Date', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Status', 'email-manager'); ?>
                                                </th>
                                                <th>
                                                    <?php esc_html_e('Actions', 'email-manager'); ?>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($gdc_newsletter_rows as $row): ?>
                                                <tr>
                                                    <td><strong>
                                                            <?php echo esc_html($row['email_data']['label']); ?>
                                                        </strong></td>
                                                    <td>
                                                        <?php echo esc_html($row['trigger']); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo esc_html($row['status']); ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="button button-small gdc-email-open-editor"
                                                            data-email-section="<?php echo esc_attr($row['type_slug']); ?>"
                                                            data-edit-url="<?php echo esc_url($row['edit_url']); ?>"
                                                            data-edit-title="<?php echo esc_attr($row['edit_title']); ?>"
                                                            data-email='<?php echo json_encode($row['email_data']); ?>'>
                                                            <?php esc_html_e('Edit', 'email-manager'); ?>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <!-- Inline Script for Subtabs -->
                        <script>
                            jQuery(document).ready(function ($) {
                                // Default subtab active state inline styles
                                var activeStyle = 'border-bottom: 2px solid #6366f1; color: #6366f1;';
                                var inactiveStyle = 'border-bottom: 2px solid transparent; color: #64748b;';

                                // Ensure initial state
                                //$('.gdc-subtab.active').attr('style', $('.gdc-subtab.active').attr('style') + activeStyle);

                                $('.gdc-subtab').on('click', function () {
                                    var subtab = $(this).data('subtab');
                                    var $parent = $(this).closest('.gdc-sub-tabpanel');

                                    // Reset all buttons in this panel
                                    $parent.find('.gdc-subtab').removeClass('active').css('border-bottom-color', 'transparent').css('color', '#64748b');
                                    $parent.find('.gdc-subtab-panel').hide();

                                    // Activate clicked
                                    $(this).addClass('active').css('border-bottom-color', '#6366f1').css('color', '#6366f1');
                                    $parent.find('.gdc-subtab-panel[data-subpanel="' + subtab + '"]').show();
                                });

                                // Quick Tab Switcher for Main Tabs (Pills)
                                $('.gdc-sub-tab').on('click', function () {
                                    var tab = $(this).data('tab');
                                    $('.gdc-sub-tab').removeClass('active').attr('aria-selected', 'false');
                                    $(this).addClass('active').attr('aria-selected', 'true');

                                    $('.gdc-sub-tabpanel').hide();
                                    $('.gdc-sub-tabpanel[data-panel="' + tab + '"]').show();
                                });
                            });
                        </script>
                    </section>

                    <!-- Proposals Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="proposals" hidden>
                        <div class="gdc-email-panel">
                            <div class="gdc-email-panel__header">
                                <div>
                                    <h3>
                                        <?php esc_html_e('Proposal Emails', 'email-manager'); ?>
                                    </h3>
                                    <p class="description">
                                        <?php esc_html_e('Customize the emails sent when proposals are created, viewed, or accepted.', 'email-manager'); ?>
                                    </p>
                                </div>
                                <button type="button" id="gdc-email-add-proposal" class="button button-primary">
                                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                                    <?php esc_html_e('Add New Email', 'email-manager'); ?>
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- System Emails Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="system-emails" hidden>
                        <p><?php esc_html_e('No system emails found.', 'email-manager'); ?></p>
                    </section>

                    <!-- App Template Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="app-template" hidden>
                        <?php
                        if (class_exists('EM_Email_Templates')) {
                            $templates = new EM_Email_Templates();
                            $templates->render();
                        }
                        ?>
                    </section>

                    <!-- Logs Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="logs" hidden>
                        <?php
                        if (class_exists('EM_Email_Logs')) {
                            EM_Email_Logs::render_logs_tab();
                        }
                        ?>
                    </section>

                    <!-- Sending Settings Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="sending-settings" hidden>
                        <?php
                        if (class_exists('EM_Email_SMTP')) {
                            EM_Email_SMTP::render_smtp_tab();
                        }
                        ?>
                    </section>
                </div> <!-- .gdc-tabpanels -->
            </div>
        </div>
    </div>
    <?php
}
