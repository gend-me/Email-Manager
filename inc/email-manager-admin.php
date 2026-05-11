<?php
/**
 * Admin Page Rendering for Email Manager
 *
 * @package EmailManager
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Build a representative plain-text email body for a WooCommerce email object.
 * Shows the template structure with available {tokens} so the AI editor has meaningful context.
 */
if (!function_exists('em_get_wc_email_body_template')) {
    function em_get_wc_email_body_template($email)
    {
        $id = $email->id;
        $templates = array(
            'new_order'                  => "A new order has been placed.\n\nOrder #{order_number} from {customer_name} — {order_date}\n\n[Order Details Table]\n\n[Customer Billing & Shipping Details]\n\n",
            'cancelled_order'            => "Order #{order_number} has been cancelled.\n\nOrder from {customer_name} — {order_date}\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'failed_order'               => "Payment for order #{order_number} from {customer_name} has failed.\n\nThe order was as follows:\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_on_hold_order'     => "Hi {customer_first_name},\n\nThank you for your order. Your order is on-hold until we confirm payment has been received. In the meantime, here is a reminder of what you ordered:\n\nOrder #{order_number} — {order_date}\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_processing_order'  => "Hi {customer_first_name},\n\nJust to let you know — we've received your order #{order_number}, and it is now being processed:\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_completed_order'   => "Hi {customer_first_name},\n\nYour {site_title} order has been completed. Your order details are shown below for your reference:\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_refunded_order'    => "Hi {customer_first_name},\n\nYour order #{order_number} from {site_title} has been refunded. There are more details below for your reference:\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_cancelled_order'   => "Hi {customer_first_name},\n\nYour order #{order_number} from {site_title} has been cancelled. Your order details are shown below for your reference:\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_failed_order'      => "Hi {customer_first_name},\n\nUnfortunately, we couldn't complete your order due to an issue with your payment method.\n\nIf you'd like to continue with your purchase, please return to {site_title} and try a different payment method.\n\nYour order details are as follows:\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_note'              => "Hi {customer_first_name},\n\nThe following note has been added to your order #{order_number}:\n\n{customer_note}\n\n[Order Details Table]\n\n",
            'customer_invoice'           => "Hi {customer_first_name},\n\nAn invoice has been created for order #{order_number} from {order_date}. Payment is required.\n\n[Order Details Table]\n\n[Customer Details]\n\n",
            'customer_new_account'       => "Hi {customer_name},\n\nThanks for creating an account on {site_title}. Your username is {customer_username}.\n\nYou can access your account area to view orders, change your password, and more at:\n{account_url}\n\n",
            'customer_reset_password'    => "Hi {customer_username},\n\nSomeone has requested a new password for the following account on {site_title}.\n\nIf you didn't make this request, just ignore this email.\n\nTo reset your password, visit the following address:\n{password_reset_link}\n\n",
        );
        $body = isset($templates[$id]) ? $templates[$id] : "[Email body for '{$id}']\n\n";
        $additional = $email->get_option('additional_content', '');
        if (empty($additional) && method_exists($email, 'get_default_additional_content')) {
            try { $additional = $email->get_default_additional_content(); } catch (Exception $e) { $additional = ''; }
        }
        if (!empty($additional)) {
            $body .= $additional . "\n\n";
        }
        return $body;
    }
}

/**
 * Return an array of available token strings for a WooCommerce email object.
 * Merges the email's own placeholders with global WC email tokens.
 */
if (!function_exists('em_get_wc_email_tokens')) {
    function em_get_wc_email_tokens($email)
    {
        $global_tokens = array(
            '{site_title}', '{site_address}', '{site_url}', '{store_email}',
            '{admin_email}', '{order_date}', '{order_number}',
        );
        $email_tokens = method_exists($email, 'get_placeholders') ? array_keys($email->get_placeholders()) : array();
        // Merge, deduplicate, and filter out empty
        $all = array_unique(array_merge($email_tokens, $global_tokens));
        return array_values(array_filter($all));
    }
}

/**
 * Render a single system email table row with a popup Configure button.
 */
if (!function_exists('em_render_system_email_row')) {
    function em_render_system_email_row($title, $description, $recipient, $enabled, $email_data_arr)
    {
        $email_json = esc_attr(wp_json_encode($email_data_arr));
        $email_b64 = base64_encode(wp_json_encode($email_data_arr));
        ?>
        <tr>
            <td><strong><?php echo esc_html($title); ?></strong></td>
            <td><?php echo esc_html($description); ?></td>
            <td><?php echo esc_html($recipient); ?></td>
            <td>
                <?php if ($enabled): ?>
                    <span style="color:#00a32a;font-weight:600;"><?php esc_html_e('Enabled', 'email-manager'); ?></span>
                <?php else: ?>
                    <span style="color:#d63638;font-weight:600;"><?php esc_html_e('Disabled', 'email-manager'); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <button type="button" class="button button-small gdc-email-open-editor"
                    data-email="<?php echo $email_json; ?>"
                    data-email-b64="<?php echo $email_b64; ?>">
                    <?php esc_html_e('Configure', 'email-manager'); ?>
                </button>
            </td>
        </tr>
        <?php
    }
}

/**
 * Render a titled system email group table.
 */
if (!function_exists('em_system_email_table')) {
    function em_system_email_table($title, $rows_callback)
    {
        ?>
        <div class="gdc-email-panel" style="margin-bottom:24px;">
            <div class="gdc-email-panel__header">
                <h3><?php echo esc_html($title); ?></h3>
            </div>
            <div class="gdc-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Email', 'email-manager'); ?></th>
                            <th><?php esc_html_e('Description', 'email-manager'); ?></th>
                            <th><?php esc_html_e('Recipient(s)', 'email-manager'); ?></th>
                            <th><?php esc_html_e('Status', 'email-manager'); ?></th>
                            <th><?php esc_html_e('Actions', 'email-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rows_callback(); ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}

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
        'email' => __('Email', 'email-manager'),
        'forms' => __('Forms', 'email-manager'),
        'chatflows' => __('Chatflows', 'email-manager'),
        'applications' => __('Applications', 'email-manager'),
        'support' => __('Support', 'email-manager'),
    );

    // WordPress core system emails (static – these are built into WP core)
    $gdc_wp_core_emails = array(
        array(
            'id'          => 'new_user_registration',
            'title'       => __('New User Registration', 'email-manager'),
            'description' => __('Sent to the site admin when a new user registers.', 'email-manager'),
            'recipient'   => __('Admin', 'email-manager'),
            'section'     => 'store',
            'subject'     => sprintf('[%s] New User Registration', get_bloginfo('name')),
        ),
        array(
            'id'          => 'new_user_welcome',
            'title'       => __('New User Welcome', 'email-manager'),
            'description' => __('Sent to the new user with their login credentials.', 'email-manager'),
            'recipient'   => __('New User', 'email-manager'),
            'section'     => 'store',
            'subject'     => sprintf('[%s] Your username and password', get_bloginfo('name')),
        ),
        array(
            'id'          => 'password_reset',
            'title'       => __('Password Reset', 'email-manager'),
            'description' => __('Sent to users who request a password reset link.', 'email-manager'),
            'recipient'   => __('User', 'email-manager'),
            'section'     => 'store',
            'subject'     => sprintf('[%s] Password Reset', get_bloginfo('name')),
        ),
        array(
            'id'          => 'email_change_confirmation',
            'title'       => __('Email Change Confirmation', 'email-manager'),
            'description' => __('Sent to users when they change their email address.', 'email-manager'),
            'recipient'   => __('User', 'email-manager'),
            'section'     => 'store',
            'subject'     => sprintf('[%s] Email Change Request', get_bloginfo('name')),
        ),
    );

    // WooCommerce store emails
    $gdc_store_emails = array();
    try {
        if (class_exists('WC_Emails')) {
            $gdc_store_emails = WC_Emails::instance()->get_emails();
        } elseif (function_exists('WC') && WC()->mailer()) {
            $gdc_store_emails = WC()->mailer()->get_emails();
        }
    } catch (Exception $e) {
        $gdc_store_emails = array();
    }

    // Social network / BuddyPress emails — query terms then posts to get the real situation name
    $gdc_bp_emails = array();
    if (post_type_exists('bp-email') && taxonomy_exists('bp-email-type')) {
        $bp_terms = get_terms(array(
            'taxonomy'   => 'bp-email-type',
            'hide_empty' => false,
        ));
        
        if (!is_wp_error($bp_terms)) {
            foreach ($bp_terms as $term) {
                $bp_posts = get_posts(array(
                    'post_type'   => 'bp-email',
                    'post_status' => 'publish',
                    'numberposts' => 1,
                    'tax_query'   => array(
                        array(
                            'taxonomy' => 'bp-email-type',
                            'field'    => 'term_id',
                            'terms'    => $term->term_id,
                        ),
                    ),
                ));
                
                if (!empty($bp_posts)) {
                    $post = $bp_posts[0];
                    $gdc_bp_emails[] = array(
                        'id'          => $post->post_name,
                        'title'       => $term->name,
                        'description' => trim($post->post_title),
                        'recipient'   => __('Member', 'email-manager'),
                        'section'     => 'community',
                        'subject'     => trim($post->post_title),
                        'html'        => $post->post_content,
                    );
                }
            }
        }
    }

    // Placeholder URL for edit links
    $gdc_nurture_embed_url = admin_url('admin.php?page=email-manager&view=nurture'); // Example

    ?>
    <div class="wrap gdc-admin-dashboard gdc-app-content">

        <!-- Shared detail drawer (used by Applications + Support tabs) -->
        <div class="em-drawer" id="em-detail-drawer" aria-hidden="true">
            <div class="em-drawer__backdrop"></div>
            <div class="em-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="em-drawer-title">
                <div class="em-drawer__header">
                    <h3 class="em-drawer__title" id="em-drawer-title"><?php esc_html_e('Details', 'email-manager'); ?></h3>
                    <button type="button" class="em-drawer__close" id="em-drawer-close" aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                </div>
                <div class="em-drawer__body" id="em-drawer-body"></div>
            </div>
        </div>

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
                <section class="email-dashboard-intro">
                    <div class="dashboard-bg"></div>

                    <div class="dashboard-content-frame reveal">
                        <span class="control-center-tag">
                            <?php esc_html_e('Messaging Control Center', 'email-manager'); ?>
                        </span>
                        <h1 class="dashboard-intro-title">Email<br>Workspace</h1>
                        
                        <p class="dashboard-intro-lead">
                            <?php esc_html_e('Align every automated email—from store receipts to community alerts—with your unique brand voice.', 'email-manager'); ?>
                        </p>

                        <div class="dashboard-action-row">
                            <a href="#" class="pill-btn"><?php esc_html_e('Live Automations', 'email-manager'); ?></a>
                            <a href="#" class="pill-btn secondary"><?php esc_html_e('App-Wide Branding', 'email-manager'); ?></a>
                        </div>
                    </div>
                </section>

                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const observerOptions = {
                            threshold: 0.2
                        };

                        const observer = new IntersectionObserver((entries) => {
                            entries.forEach(entry => {
                                if (entry.isIntersecting) {
                                    entry.target.classList.add('active');
                                }
                            });
                        }, observerOptions);

                        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
                    });
                </script>
                <div class="gdc-tabs gdc-tabs--pills" role="tablist"
                    aria-label="<?php esc_attr_e('Email Sections', 'email-manager'); ?>">
                    <?php
                    // Define icons for tabs
                    $tab_icons = array(
                        'email'        => 'dashicons-email',
                        'forms'        => 'dashicons-feedback',
                        'chatflows'    => 'dashicons-format-chat',
                        'applications' => 'dashicons-id-alt',
                        'support'      => 'dashicons-sos',
                    );

                    // New Tab Structure
                    $first = true;
                    foreach ($tabs as $slug => $label):
                        $icon_class = isset($tab_icons[$slug]) ? $tab_icons[$slug] : 'dashicons-admin-generic';
                        ?>
                        <button type="button" class="gdc-sub-tab <?php echo $first ? 'active' : ''; ?>"
                            data-tab="<?php echo esc_attr($slug); ?>" role="tab"
                            aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                            <span class="dashicons <?php echo esc_attr($icon_class); ?>"></span>
                            <?php echo esc_html($label); ?>
                        </button>
                        <?php
                        $first = false;
                    endforeach;
                    ?>
                </div>

                <div class="gdc-tabpanels">
                    <!-- Email Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="email" <?php echo $gdc_email_embed_single ? 'hidden' : ''; ?>>
                        <div class="gdc-subtabs">
                            <button type="button" class="gdc-subtab active" data-subtab="lists">
                                <?php esc_html_e('Lists', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="onboarding">
                                <?php esc_html_e('Onboarding Emails', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="newsletters">
                                <?php esc_html_e('Newsletters', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="system-emails">
                                <?php esc_html_e('System Emails', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="app-template">
                                <?php esc_html_e('App Template', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="logs">
                                <?php esc_html_e('Logs', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="sending-settings">
                                <?php esc_html_e('Sending Settings', 'email-manager'); ?>
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

                        <!-- App Template Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="app-template" hidden>
                            <?php
                            if (class_exists('EM_Email_Templates')) {
                                $templates = new EM_Email_Templates();
                                $templates->render();
                            }
                            ?>
                        </div>

                        <!-- Logs Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="logs" hidden>
                            <?php
                            if (class_exists('EM_Email_Logs')) {
                                EM_Email_Logs::render_logs_tab();
                            }
                            ?>
                        </div>

                        <!-- Sending Settings Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="sending-settings" hidden>
                            <?php
                            if (class_exists('EM_Email_SMTP')) {
                                EM_Email_SMTP::render_smtp_tab();
                            }
                            ?>
                        </div>

                        <!-- System Emails Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="system-emails" hidden>
                            <?php // --- WordPress Core Emails --- ?>
                            <?php em_system_email_table(__('Login & Account Emails', 'email-manager'), function() use ($gdc_wp_core_emails) {
                                foreach ($gdc_wp_core_emails as $e) {
                                    $override_key = 'em_wc_email_override_' . $e['id'];
                                    $body = get_option($override_key, '');
                                    em_render_system_email_row($e['title'], $e['description'], $e['recipient'], true, array(
                                        'section'     => 'store', // treated same as WC for render/save flow
                                        'id'          => $e['id'],
                                        'label'       => $e['title'],
                                        'subject'     => $e['subject'],
                                        'preheader'   => $e['title'],
                                        'description' => $e['description'],
                                        'html'        => $body,
                                        'is_enabled'  => true,
                                        'tokens'      => function_exists('em_wp_account_tokens') ? em_wp_account_tokens($e['id']) : array(),
                                    ));
                                }
                            }); ?>

                            <?php // --- WooCommerce Store Emails --- ?>
                            <?php if (!empty($gdc_store_emails)): ?>
                                <?php em_system_email_table(__('Store Emails', 'email-manager'), function() use ($gdc_store_emails) {
                                    foreach ($gdc_store_emails as $email) {
                                        $subject = $email->get_option('subject', '');
                                        if (empty($subject) && method_exists($email, 'get_default_subject')) {
                                            try { $subject = $email->get_default_subject(); } catch (Exception $e) { $subject = ''; }
                                        }
                                        $heading = $email->get_option('heading', '');
                                        if (empty($heading) && method_exists($email, 'get_default_heading')) {
                                            try { $heading = $email->get_default_heading(); } catch (Exception $e) { $heading = ''; }
                                        }
                                        // Use saved override HTML if it exists; otherwise send empty so JS fetches the render
                                        $override_key = defined('EM_WC_OVERRIDE_PREFIX') ? EM_WC_OVERRIDE_PREFIX . $email->id : 'em_wc_email_override_' . $email->id;
                                        $body = get_option($override_key, '');
                                        if ($body === '' && method_exists($email, 'get_default_additional_content')) {
                                            // Provide default additional_content as a hint but JS will fetch full render
                                            try { $body = $email->get_default_additional_content(); } catch (Exception $e) { $body = ''; }
                                        }
                                        $em_is_customer_email    = (strpos($email->id, 'customer_') === 0);
                                        $em_raw_recip            = $email->get_recipient();
                                        $em_display_recip        = $em_is_customer_email
                                            ? __('Customer', 'email-manager')
                                            : ($em_raw_recip ?: get_option('admin_email'));
                                        em_render_system_email_row(
                                            $email->get_title(),
                                            $email->get_description(),
                                            $em_display_recip,
                                            $email->is_enabled(),
                                            array(
                                                'section'          => 'store',
                                                'id'               => $email->id,
                                                'label'            => $email->get_title(),
                                                'subject'          => $subject,
                                                'preheader'        => $heading,
                                                'html'             => $body,
                                                'send_to_customer' => $em_is_customer_email,
                                                'wc_recipient'     => $em_display_recip,
                                                'is_enabled'       => $email->is_enabled(),
                                                'tokens'           => function_exists('em_wc_all_tokens') ? em_wc_all_tokens() : (function_exists('em_get_wc_email_tokens') ? em_get_wc_email_tokens($email) : array()),
                                            )
                                        );
                                    }
                                }); ?>
                            <?php endif; ?>

                            <?php // --- Social Network / BuddyPress Emails --- ?>
                            <?php if (!empty($gdc_bp_emails)): ?>
                                <?php em_system_email_table(__('Social Network Emails', 'email-manager'), function() use ($gdc_bp_emails) {
                                    foreach ($gdc_bp_emails as $e) {
                                        em_render_system_email_row($e['title'], $e['description'], $e['recipient'], true, array(
                                            'section'     => 'community',
                                            'id'          => $e['id'],
                                            'label'       => $e['title'],
                                            'subject'     => $e['subject'],
                                            'preheader'   => $e['subject'],
                                            'description' => $e['description'],
                                            'html'        => isset($e['html']) ? $e['html'] : '',
                                            'is_enabled'  => true,
                                            'tokens'      => array(
                                                '{{sender.name}}', '{{sender.username}}',
                                                '{{recipient.name}}', '{{recipient.username}}',
                                                '{{site.name}}', '{{site.url}}',
                                                '{{group.name}}', '{{group.url}}',
                                                '{{activity.content}}', '{{comment.content}}',
                                                '{{thread.subject}}', '{{message.content}}',
                                                '{{friendship.initiator.name}}',
                                            ),
                                        ));
                                    }
                                }); ?>
                            <?php endif; ?>
                        </div>
                        <!-- Inline Script for Subtabs -->
                        <script>
                            jQuery(document).ready(function ($) {
                                $('.gdc-subtab').on('click', function () {
                                    var subtab = $(this).data('subtab');
                                    var $parent = $(this).closest('.gdc-sub-tabpanel');

                                    // Reset all buttons in this panel
                                    $parent.find('.gdc-subtab').removeClass('active');
                                    $parent.find('.gdc-subtab-panel').hide();

                                    // Activate clicked
                                    $(this).addClass('active');
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

                    <!-- Forms Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="forms" hidden>
                        <div class="gdc-subtabs">
                            <button type="button" class="gdc-subtab active" data-subtab="basic-forms">
                                <?php esc_html_e('Forms', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="submissions">
                                <?php esc_html_e('Submissions', 'email-manager'); ?>
                            </button>
                        </div>

                        <!-- Basic Forms Subtab -->
                        <div class="gdc-subtab-panel" data-subpanel="basic-forms">
                            <div class="gdc-email-panel">
                                <div class="gdc-email-panel__header">
                                    <h3><?php esc_html_e('Standard Forms', 'email-manager'); ?></h3>
                                    <a href="<?php echo admin_url('post-new.php?post_type=basic_form'); ?>"
                                        class="button button-primary">
                                        <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                                        <?php esc_html_e('Create Form', 'email-manager'); ?>
                                    </a>
                                </div>
                                <div class="gdc-table-wrap">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Title', 'email-manager'); ?></th>
                                                <th><?php esc_html_e('Shortcode', 'email-manager'); ?></th>
                                                <th><?php esc_html_e('Date', 'email-manager'); ?></th>
                                                <th><?php esc_html_e('Actions', 'email-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $basic_forms = get_posts(array('post_type' => 'basic_form', 'numberposts' => -1, 'post_status' => 'publish,draft'));
                                            if ($basic_forms):
                                                foreach ($basic_forms as $post): ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                                                        <td><input type="text" readonly
                                                                value="[basic_form id='<?php echo esc_attr($post->ID); ?>']"
                                                                style="width:100%;" onclick="this.select();" /></td>
                                                        <td><?php echo esc_html(get_the_date('', $post->ID)); ?></td>
                                                        <td>
                                                            <a href="<?php echo admin_url('post.php?post=' . $post->ID . '&action=edit'); ?>"
                                                                class="button button-small">
                                                                <?php esc_html_e('Edit', 'email-manager'); ?>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach;
                                            else: ?>
                                                <tr>
                                                    <td colspan="4"><?php esc_html_e('No forms found.', 'email-manager'); ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Submissions Subtab -->
                        <div class="gdc-subtab-panel" data-subpanel="submissions" hidden>
                            <div class="gdc-email-panel">
                                <div class="gdc-email-panel__header">
                                    <h3><?php esc_html_e('Form Submissions', 'email-manager'); ?></h3>
                                </div>
                                <div class="gdc-table-wrap">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Submission Title', 'email-manager'); ?></th>
                                                <th><?php esc_html_e('Form', 'email-manager'); ?></th>
                                                <th><?php esc_html_e('Date Received', 'email-manager'); ?></th>
                                                <th><?php esc_html_e('Actions', 'email-manager'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $submissions = get_posts(array('post_type' => 'chat_submission', 'numberposts' => 50, 'post_status' => 'publish,draft'));
                                            if ($submissions):
                                                foreach ($submissions as $post):
                                                    $form_id = get_post_meta($post->ID, '_chat_submission_form_id', true);
                                                    $form_title = $form_id ? get_the_title($form_id) : 'Unknown Form';
                                                    $sub_data = get_post_meta($post->ID, '_chat_submission_data', true);
                                                    $json_data = wp_json_encode($sub_data ?: array());
                                                    ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                                                        <td><?php echo esc_html($form_title); ?></td>
                                                        <td><?php echo esc_html(get_the_date('', $post->ID)); ?></td>
                                                        <td>
                                                            <button type="button" class="button button-small gdc-view-submission-btn"
                                                                data-title="<?php echo esc_attr($post->post_title); ?>"
                                                                data-submission="<?php echo esc_attr($json_data); ?>">
                                                                <?php esc_html_e('View Data', 'email-manager'); ?>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach;
                                            else: ?>
                                                <tr>
                                                    <td colspan="4">
                                                        <?php esc_html_e('No submissions found.', 'email-manager'); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                </div>
                                
                                <!-- Submission Modal -->
                                <div id="gdc-submission-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(11, 14, 20, 0.8); backdrop-filter: var(--em-glass-blur); z-index:99999; align-items:center; justify-content:center;">
                                    <div style="background: var(--em-glass-bg); border: 1px solid var(--em-glass-border); border-radius: 20px; max-width: 650px; width: 90%; max-height: 85vh; display: flex; flex-direction: column; box-shadow: var(--em-panel-shadow); position: relative; overflow: hidden;">
                                        <div style="padding: 24px 32px; border-bottom: 1px solid var(--em-glass-border); display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.02);">
                                            <h2 id="gdc-submission-modal-title" style="margin:0; font-size:1.4rem; font-weight:700; color: var(--em-text-primary); letter-spacing: -0.01em;">Submission Data</h2>
                                            <button type="button" id="gdc-close-submission-modal" style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); font-size:20px; line-height:1; color: var(--em-text-secondary); cursor:pointer; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;">&times;</button>
                                        </div>
                                        <div id="gdc-submission-modal-content" style="padding: 32px; overflow-y: auto; flex-grow: 1; scrollbar-width: thin; scrollbar-color: var(--em-glass-border) transparent;"></div>
                                    </div>
                                </div>
                                <script>
                                jQuery(document).ready(function($) {
                                    $('.gdc-view-submission-btn').on('click', function() {
                                        var rawData = $(this).attr('data-submission');
                                        var title = $(this).attr('data-title');
                                        var data;

                                        try {
                                            data = JSON.parse(rawData);
                                        } catch (e) {
                                            data = {};
                                        }

                                        $('#gdc-submission-modal-title').text(title);
                                        var contentHtml = '';
                                        
                                        if (data && typeof data === 'object' && Object.keys(data).length > 0) {
                                            $.each(data, function(key, val) {
                                                var q = '', a = '';
                                                if (typeof val === 'object' && val !== null) {
                                                    q = val.question || key;
                                                    a = val.answer || '';
                                                } else {
                                                    q = isNaN(key) ? key.replace(/_/g, ' ') : 'Question ' + (parseInt(key)+1);
                                                    a = val;
                                                }
                                                // Convert HTML entities back safely or format newlines
                                                a = $('<div>').text(a).html().replace(/\n/g, '<br/>');

                                                contentHtml += '<div style="margin-bottom:20px; padding:20px; background: rgba(30, 41, 59, 0.4); border-radius:12px; border: 1px solid var(--em-glass-border); border-left:4px solid #6366f1;">';
                                                contentHtml += '<div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.1em; font-weight:700; color:#818cf8; margin-bottom:10px;">' + q + '</div>';
                                                contentHtml += '<div style="color: var(--em-text-primary); font-size:1.05rem; line-height:1.6; font-weight: 500;">' + (a || '<em style="color: var(--em-text-secondary); opacity: 0.6;">No answer provided</em>') + '</div>';
                                                contentHtml += '</div>';
                                            });
                                        } else {
                                            contentHtml = '<div style="text-align:center; color:#64748b; padding:20px;">No data recorded for this submission.</div>';
                                        }
                                        
                                        $('#gdc-submission-modal-content').html(contentHtml);
                                        $('#gdc-submission-modal').css('display', 'flex').hide().fadeIn(200);
                                    });
                                    
                                    $('#gdc-close-submission-modal').on('click', function() {
                                        $('#gdc-submission-modal').fadeOut(200);
                                    });

                                    // Close on click outside
                                    $('#gdc-submission-modal').on('click', function(e) {
                                        if (e.target.id === 'gdc-submission-modal') {
                                            $(this).fadeOut(200);
                                        }
                                    });
                                });
                                </script>

                            </div>
                        </div>
                    </section>

                    <!-- Chatflows Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="chatflows" hidden>
                        <div class="em-app-tab">
                            <div class="gdc-subtabs">
                                <button type="button" class="gdc-subtab active" data-subtab="cf-personas" style="--em-i:0;">
                                    <?php esc_html_e('Personas', 'email-manager'); ?>
                                </button>
                                <button type="button" class="gdc-subtab" data-subtab="cf-flows" style="--em-i:1;">
                                    <?php esc_html_e('Flows', 'email-manager'); ?>
                                </button>
                                <button type="button" class="gdc-subtab" data-subtab="cf-chats" style="--em-i:2;">
                                    <?php esc_html_e('Chats', 'email-manager'); ?>
                                </button>
                                <button type="button" class="gdc-subtab" data-subtab="cf-ai-integration" style="--em-i:3;">
                                    <?php esc_html_e('AI Integration', 'email-manager'); ?>
                                </button>
                            </div>

                            <!-- Personas subtab -->
                            <div class="gdc-subtab-panel" data-subpanel="cf-personas">
                                <?php
                                if (class_exists('EM_Personas')) {
                                    EM_Personas::render();
                                }
                                ?>
                            </div>

                            <!-- Flows subtab -->
                            <div class="gdc-subtab-panel" data-subpanel="cf-flows" hidden>
                                <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                                    <div class="gdc-email-panel__header">
                                        <h3><?php esc_html_e('Chatflows', 'email-manager'); ?></h3>
                                        <a href="<?php echo admin_url('post-new.php?post_type=chat_form'); ?>" class="button button-primary">
                                            <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                                            <?php esc_html_e('Create Chatflow', 'email-manager'); ?>
                                        </a>
                                    </div>
                                    <div class="gdc-table-wrap">
                                        <table class="widefat striped">
                                            <thead>
                                                <tr>
                                                    <th><?php esc_html_e('Title', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Shortcode', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Date', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Actions', 'email-manager'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $chat_forms = get_posts(array('post_type' => 'chat_form', 'numberposts' => -1, 'post_status' => 'publish,draft'));
                                                if ($chat_forms):
                                                    foreach ($chat_forms as $i => $post): ?>
                                                        <tr class="em-row" style="--em-i:<?php echo (int) $i; ?>;">
                                                            <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                                                            <td><input type="text" readonly
                                                                    value="[chat_form id='<?php echo esc_attr($post->ID); ?>']"
                                                                    style="width:100%;" onclick="this.select();" /></td>
                                                            <td><?php echo esc_html(get_the_date('', $post->ID)); ?></td>
                                                            <td>
                                                                <a href="<?php echo admin_url('post.php?post=' . $post->ID . '&action=edit'); ?>"
                                                                    class="button button-small">
                                                                    <?php esc_html_e('Edit', 'email-manager'); ?>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach;
                                                else: ?>
                                                    <tr>
                                                        <td colspan="4"><?php esc_html_e('No chatflows found.', 'email-manager'); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Chats subtab -->
                            <div class="gdc-subtab-panel" data-subpanel="cf-chats" hidden>
                                <?php
                                if (class_exists('EM_Chats')) {
                                    EM_Chats::render();
                                }
                                ?>
                            </div>

                            <!-- AI Integration subtab -->
                            <div class="gdc-subtab-panel" data-subpanel="cf-ai-integration" hidden>
                                <?php
                                if (class_exists('EM_Leo')) {
                                    EM_Leo::render_settings_panel();
                                }
                                ?>
                            </div>
                        </div>
                    </section>

                    <!-- Applications Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="applications" hidden>
                        <?php
                        if (class_exists('EM_Applications')) {
                            EM_Applications::render();
                        }
                        ?>
                    </section>

                    <!-- Support Tab -->
                    <section class="gdc-sub-tabpanel" data-panel="support" hidden>
                        <?php
                        if (class_exists('EM_Support')) {
                            EM_Support::render();
                        }
                        ?>
                    </section>

                </div> <!-- .gdc-tabpanels -->
            </div>
        </div>
    </div>
    <?php
}
