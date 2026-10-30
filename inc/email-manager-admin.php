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
 * Readable name for a BuddyPress email type (the type slug is all BuddyPress stores as its name).
 */
if (!function_exists('em_bp_email_label')) {
    function em_bp_email_label($term)
    {
        $labels = array(
            'core-user-activation'                        => __('Account Activated', 'email-manager'),
            'core-user-registration'                      => __('Activate Your Account', 'email-manager'),
            'core-user-registration-with-blog'            => __('Activate Your Account and Site', 'email-manager'),
            'activity-comment'                            => __('Reply to an Update', 'email-manager'),
            'activity-comment-author'                     => __('Reply to a Comment', 'email-manager'),
            'activity-at-message'                         => __('Mentioned in an Update', 'email-manager'),
            'groups-at-message'                           => __('Mentioned in a Group Update', 'email-manager'),
            'friends-request'                             => __('Connection Request', 'email-manager'),
            'friends-request-accepted'                    => __('Connection Accepted', 'email-manager'),
            'groups-details-updated'                      => __('Group Details Updated', 'email-manager'),
            'groups-invitation'                           => __('Group Invitation', 'email-manager'),
            'groups-member-promoted'                      => __('Promoted in a Group', 'email-manager'),
            'groups-membership-request'                   => __('Request to Join a Group', 'email-manager'),
            'groups-membership-request-accepted'          => __('Group Request Accepted', 'email-manager'),
            'groups-membership-request-rejected'          => __('Group Request Declined', 'email-manager'),
            'groups-membership-request-accepted-by-admin' => __('Group Request Accepted by an Admin', 'email-manager'),
            'groups-membership-request-rejected-by-admin' => __('Group Request Declined by an Admin', 'email-manager'),
            'messages-unread'                             => __('New Private Message', 'email-manager'),
            'settings-verify-email-change'                => __('Verify a New Email Address', 'email-manager'),
            'bp-members-invitation'                       => __('Site Invitation', 'email-manager'),
            'members-membership-request'                  => __('Request to Join the Site', 'email-manager'),
            'members-membership-request-rejected'         => __('Site Request Declined', 'email-manager'),
            'yz-admin-new-report'                         => __('Moderation: New Report', 'email-manager'),
            'yz-admin-item-hidden'                        => __('Moderation: Item Hidden', 'email-manager'),
            'yz-user-item-hidden'                         => __('Moderation: Your Item Is Under Review', 'email-manager'),
            'yz-user-item-deleted'                        => __('Moderation: Your Item Was Removed', 'email-manager'),
            'yz-user-item-restored'                       => __('Moderation: Your Item Was Restored', 'email-manager'),
            'yz-reporter-item-deleted'                    => __('Moderation: Reported Item Removed', 'email-manager'),
            'yz-reporter-item-restored'                   => __('Moderation: Reported Item Restored', 'email-manager'),
            'yz-official-restrictions'                    => __('Moderation: Account Restricted', 'email-manager'),
            'yz-temporary-restrictions'                   => __('Moderation: Temporary Restriction', 'email-manager'),
            'yzbbm-user-blocked'                          => __('Member Blocked', 'email-manager'),
        );
        if (isset($labels[$term->slug])) {
            return $labels[$term->slug];
        }
        return $term->name !== $term->slug ? $term->name : ucwords(str_replace(array('-', '_'), ' ', $term->slug));
    }
}

/**
 * Who receives a BuddyPress email type.
 */
if (!function_exists('em_bp_email_recipient')) {
    function em_bp_email_recipient($slug)
    {
        if (in_array($slug, array('yz-admin-new-report', 'yz-admin-item-hidden', 'yzbbm-user-blocked', 'members-membership-request'), true)) {
            return __('Site Admins', 'email-manager');
        }
        if ($slug === 'groups-membership-request') {
            return __('Group Admins', 'email-manager');
        }
        if (in_array($slug, array('bp-members-invitation', 'core-user-registration', 'core-user-registration-with-blog'), true)) {
            return __('Invitee / New Member', 'email-manager');
        }
        return __('Member', 'email-manager');
    }
}

/**
 * Tokens a BuddyPress email can use: the ones in its own template and BuddyPress's default
 * template for that type, plus the ones BuddyPress adds to every email.
 */
if (!function_exists('em_bp_email_tokens')) {
    function em_bp_email_tokens($slug, $post)
    {
        $text = $post->post_title . ' ' . $post->post_content . ' ' . $post->post_excerpt;
        if (function_exists('bp_email_get_schema')) {
            $schema = bp_email_get_schema();
            if (isset($schema[$slug])) {
                $text .= ' ' . implode(' ', array_map('strval', array_intersect_key($schema[$slug], array_flip(array('post_title', 'post_content', 'post_excerpt')))));
            }
        }
        preg_match_all('/\{\{\{?[a-z0-9_.\-]+\}?\}\}/i', $text, $m);
        $tokens = array_merge($m[0], array('{{recipient.name}}', '{{recipient.username}}', '{{site.name}}', '{{{site.url}}}', '{{site.description}}', '{{{unsubscribe}}}'));
        $seen = array();
        foreach ($tokens as $t) {
            $seen[trim($t, '{}')] = isset($seen[trim($t, '{}')]) ? $seen[trim($t, '{}')] : $t;
        }
        return array_values($seen);
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

    // Define tabs. Slice 2zz.4: the Inbox top-level tab was retired and
    // the App view was moved INTO the Email tab as its first sub-tab
    // (see the "Email Tab" section below). The Diagnostics sub-tab was
    // also dropped — em_inbox_diag_render() still exists for direct
    // wp-cli/programmatic use, but no UI surfaces it here.
    $tabs = array(
        'email'        => __('Email', 'email-manager'),
        'forms'        => __('Forms', 'email-manager'),
        'chatflows'    => __('Chatflows', 'email-manager'),
        'applications' => __('Applications', 'email-manager'),
        'support'      => __('Support', 'email-manager'),
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

    // WooCommerce store emails. WC() returns WooCommerce::instance(), which
    // fatals if the WooCommerce class itself never loaded (the "WooCommerce
    // installation incomplete" mode this install sometimes lands in). Guard
    // with class_exists('WooCommerce'), and broaden catch to \Throwable so
    // any other Error (not just Exception) downgrades to "no WC emails".
    $gdc_store_emails = array();
    try {
        if (class_exists('WC_Emails')) {
            $gdc_store_emails = WC_Emails::instance()->get_emails();
        } elseif (class_exists('WooCommerce') && function_exists('WC') && WC()->mailer()) {
            $gdc_store_emails = WC()->mailer()->get_emails();
        }
    } catch (\Throwable $e) {
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
                        'title'       => em_bp_email_label($term),
                        'description' => $term->description !== '' ? $term->description : trim($post->post_title),
                        'recipient'   => em_bp_email_recipient($term->slug),
                        'section'     => 'community',
                        'subject'     => trim($post->post_title),
                        'html'        => $post->post_content,
                        'tokens'      => em_bp_email_tokens($term->slug, $post),
                    );
                }
            }
        }
    }

    // Placeholder URL for edit links
    $gdc_nurture_embed_url = admin_url('admin.php?page=talk-flows&view=nurture'); // Example

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
                            <?php esc_html_e('Communication Command Center', 'email-manager'); ?>
                        </span>
                        <h1 class="dashboard-intro-title">Talk<br>Flows</h1>

                        <p class="dashboard-intro-lead">
                            <?php esc_html_e('One command center for every conversation your web app has — branded email campaigns and lists, AI-powered chatflows and forms, agent messaging, connected-device AI prompts, and a full support-ticket desk.', 'email-manager'); ?>
                        </p>

                    </div>
                    <style>
                        /* Compact hero (operator directive 2026-08-25): no
                           jump-chip row, tighter frame — the real tab pills
                           land in the first viewport. */
                        .gdc-email-page .email-dashboard-intro {
                            min-height: 0 !important;
                            padding: 34px 24px !important;
                            margin-bottom: 20px !important;
                            border-radius: 28px !important;
                        }
                        .gdc-email-page .dashboard-intro-title {
                            font-size: clamp(2.2rem, 4.5vw, 3.2rem) !important;
                            line-height: 1.02 !important;
                            margin: 8px 0 12px !important;
                        }
                        .gdc-email-page .dashboard-intro-lead {
                            font-size: .95rem !important;
                            max-width: 760px;
                            margin: 0 !important;
                        }
                    </style>
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
                        <?php
                        // gend.me Email hosting plans (storage + sending): GenD Society's dashboard Email hosting card,
                        // rendered by gend-society so the markup, CSS and Change Plan popup are the same ones. Absent-safe.
                        if ( function_exists( 'gend_society_render_email_plan_card' ) && current_user_can( 'manage_options' ) ) {
                            gend_society_render_email_plan_card( array( 'context' => 'talk-flows' ) );
                        }
                        ?>
                        <div class="gdc-subtabs">
                            <button type="button" class="gdc-subtab active" data-subtab="email-inbox">
                                <?php esc_html_e('Inbox', 'email-manager'); ?>
                            </button>
                            <button type="button" class="gdc-subtab" data-subtab="lists">
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

                        <!-- Inbox Subtab Content (slice 2zz.4 — moved from top-level Inbox tab) -->
                        <div class="gdc-subtab-panel" data-subpanel="email-inbox">
                            <div class="em-inbox-wrap"><div id="em-inbox-root" data-loading="1"><?php esc_html_e('Loading inbox…', 'email-manager'); ?></div></div>
                        </div>

                        <!-- Lists Subtab Content -->
                        <div class="gdc-subtab-panel" data-subpanel="lists" hidden>
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
                                            'tokens'      => $e['tokens'],
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
                                    // Slice 2zz.4: ALSO drop the hidden attribute so the JS
                                    // visibility toggle isn't fighting the HTML attribute.
                                    $parent.find('.gdc-subtab-panel').attr('hidden', true).hide();

                                    // Activate clicked
                                    $(this).addClass('active');
                                    $parent.find('.gdc-subtab-panel[data-subpanel="' + subtab + '"]').removeAttr('hidden').show();
                                });

                                // Quick Tab Switcher for Main Tabs (Pills)
                                $('.gdc-sub-tab').on('click', function () {
                                    var tab = $(this).data('tab');
                                    $('.gdc-sub-tab').removeClass('active').attr('aria-selected', 'false');
                                    $(this).addClass('active').attr('aria-selected', 'true');

                                    $('.gdc-sub-tabpanel').hide();
                                    $('.gdc-sub-tabpanel[data-panel="' + tab + '"]').show();
                                });

                                // Deep-link: admin.php?page=talk-flows#tab=chatflows
                                // lands directly on that tab (used by the flow
                                // editor's Back button).
                                // Also: &subtab=<sub-tab>&section=<element id>, e.g.
                                // #tab=email&subtab=sending-settings&section=em-recaptcha-options
                                // (the chat form's Security Settings link).
                                var emHash = window.location.hash || '';
                                var emTabHash = emHash.match(/(?:^#|&)tab=([a-z0-9_-]+)/i);
                                if (emTabHash) {
                                    var $emTabBtn = $('.gdc-sub-tab[data-tab="' + emTabHash[1] + '"]');
                                    if ($emTabBtn.length) { $emTabBtn.trigger('click'); }
                                    var emSub = emHash.match(/&subtab=([a-z0-9_-]+)/i);
                                    if (emSub) {
                                        $('.gdc-sub-tabpanel[data-panel="' + emTabHash[1] + '"] .gdc-subtab[data-subtab="' + emSub[1] + '"]').trigger('click');
                                    }
                                    var emSec = emHash.match(/&section=([a-z0-9_-]+)/i);
                                    var emSecEl = emSec ? document.getElementById(emSec[1]) : null;
                                    if (emSecEl) { setTimeout(function () { emSecEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150); }
                                }
                            });
                        </script>
                    </section>

                    <!-- Slice 2zz.4: top-level Inbox tab + its App/Diagnostics sub-tabs
                         removed. The React SPA mount has been hoisted into the Email
                         tab's first sub-tab (data-subpanel="email-inbox") above. -->

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
                            <?php /* Sub-tab bar removed (operator directive
                                     2026-08-24): the Chatflows tab shows ONLY
                                     the Flows content. The other sub-panels
                                     stay in the markup but permanently hidden
                                     so nothing that referenced them breaks. */ ?>
                            <!-- Personas subtab (hidden) -->
                            <div class="gdc-subtab-panel" data-subpanel="cf-personas" hidden>
                                <?php
                                if (class_exists('EM_Personas')) {
                                    EM_Personas::render();
                                }
                                ?>
                            </div>

                            <!-- Flows subtab (the ONLY visible Chatflows content) -->
                            <div class="gdc-subtab-panel" data-subpanel="cf-flows">
                                <div class="gdc-email-panel em-reveal" style="--em-i:0;">
                                    <div class="gdc-email-panel__header">
                                        <h3><?php esc_html_e('Chatflows', 'email-manager'); ?></h3>
                                        <div class="gdc-email-search em-cf-list-search">
                                            <label class="screen-reader-text" for="em-cf-list-search"><?php esc_html_e('Search chatflows', 'email-manager'); ?></label>
                                            <input type="search" id="em-cf-list-search" autocomplete="off" placeholder="<?php esc_attr_e('Search chatflows…', 'email-manager'); ?>" />
                                        </div>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                            <?php if (current_user_can('manage_options') && class_exists('EM_Email_SMTP')) : ?>
                                                <button type="button" class="button" data-em-recaptcha-open>
                                                    <span class="dashicons dashicons-shield" style="margin-top: 3px;"></span>
                                                    <?php esc_html_e('reCAPTCHA Settings', 'email-manager'); ?>
                                                </button>
                                            <?php endif; ?>
                                            <a href="<?php echo admin_url('post-new.php?post_type=chat_form'); ?>" class="button button-primary">
                                                <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                                                <?php esc_html_e('Create Chatflow', 'email-manager'); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <?php if (class_exists('EM_Email_SMTP')) EM_Email_SMTP::render_recaptcha_modal(); ?>
                                    <div class="em-cf-del-modal" id="em-cf-del-modal" role="alertdialog" aria-modal="true" aria-labelledby="em-cf-del-title" aria-describedby="em-cf-del-desc" hidden>
                                        <div class="em-cf-del-modal__panel">
                                            <div class="em-cf-del-modal__icon" aria-hidden="true"><span class="dashicons dashicons-trash"></span></div>
                                            <h2 id="em-cf-del-title"><?php esc_html_e('Delete chatflow?', 'email-manager'); ?></h2>
                                            <p id="em-cf-del-desc">
                                                <?php esc_html_e('You are about to delete', 'email-manager'); ?> <strong data-cf-del-name></strong>.
                                                <?php esc_html_e('It moves to the Trash, where it can be restored.', 'email-manager'); ?>
                                            </p>
                                            <div class="em-cf-del-modal__status" data-cf-del-status role="status"></div>
                                            <div class="em-cf-del-modal__foot">
                                                <button type="button" class="em-cf-del-modal__btn em-cf-del-modal__btn--ghost" data-cf-del-cancel><?php esc_html_e('Cancel', 'email-manager'); ?></button>
                                                <button type="button" class="em-cf-del-modal__btn em-cf-del-modal__btn--danger" data-cf-del-confirm><?php esc_html_e('Delete Chatflow', 'email-manager'); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                    <style>
                                        .em-cf-del-modal[hidden] { display: none !important; }
                                        .em-cf-del-modal { position: fixed; inset: 0; z-index: 100100; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(2, 6, 23, .72); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
                                        .em-cf-del-modal__panel { width: 100%; max-width: 440px; text-align: center; background: linear-gradient(170deg, #111827, #0b1020); color: #e2e8f0; border: 1px solid rgba(148, 163, 184, .22); border-radius: 18px; padding: 28px 26px 22px; box-shadow: 0 30px 80px rgba(0, 0, 0, .6); animation: emCfDelIn .18s ease-out; }
                                        @keyframes emCfDelIn { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
                                        @media (prefers-reduced-motion: reduce) { .em-cf-del-modal__panel { animation: none; } }
                                        .em-cf-del-modal__icon { width: 54px; height: 54px; margin: 0 auto 14px; border-radius: 999px; display: flex; align-items: center; justify-content: center; background: rgba(239, 68, 68, .14); border: 1px solid rgba(248, 113, 113, .35); color: #fca5a5; }
                                        .em-cf-del-modal__icon .dashicons { width: 26px; height: 26px; font-size: 26px; }
                                        .em-cf-del-modal h2 { margin: 0 0 8px; font-size: 20px; color: #f8fafc; }
                                        .em-cf-del-modal p { margin: 0; color: #94a3b8; font-size: 14px; line-height: 1.6; }
                                        .em-cf-del-modal p strong { color: #f1f5f9; word-break: break-word; }
                                        .em-cf-del-modal__status { min-height: 20px; margin: 10px 0 0; color: #fca5a5; font-size: 13px; }
                                        .em-cf-del-modal__foot { display: flex; gap: 10px; margin-top: 12px; }
                                        .em-cf-del-modal__btn { flex: 1; height: 42px; font-family: inherit; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s ease, border-color .15s ease; }
                                        .em-cf-del-modal__btn--ghost { background: transparent; color: #e2e8f0; border: 1px solid rgba(148, 163, 184, .35); }
                                        .em-cf-del-modal__btn--ghost:hover { background: rgba(148, 163, 184, .12); }
                                        .em-cf-del-modal__btn--danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border: 0; box-shadow: 0 10px 24px rgba(239, 68, 68, .3); }
                                        .em-cf-del-modal__btn--danger:hover { filter: brightness(1.08); }
                                        .em-cf-del-modal__btn:disabled { opacity: .7; cursor: default; }
                                        .em-cf-del-modal__btn:focus-visible { outline: 2px solid #818cf8; outline-offset: 2px; }
                                    </style>
                                    <style>
                                        .em-cf-list-search { flex: 1; max-width: 340px; margin: 0 12px 0 auto; }
                                        .em-cf-list-search input { width: 100%; height: 38px; padding: 0 12px; border-radius: 10px; }
                                        #em-cf-list-table .em-cf-delete-btn { color: #fca5a5 !important; background: rgba(239,68,68,.1) !important; border: 1px solid rgba(248,113,113,.35) !important; }
                                        #em-cf-list-table .em-cf-delete-btn:hover { color: #fff !important; background: rgba(239,68,68,.35) !important; }
                                        #em-cf-list-table tr[hidden] { display: none !important; }
                                        @media (max-width: 782px) { .em-cf-list-search { order: 3; flex-basis: 100%; max-width: none; margin: 8px 0 0; } }
                                    </style>
                                    <script>
                                    jQuery(function ($) {
                                        var $table = $('#em-cf-list-table');
                                        // Every chatflow is already on the page — filter rows as you type.
                                        function filter() {
                                            var q = $.trim($('#em-cf-list-search').val()).toLowerCase(), shown = 0;
                                            $table.find('tr.em-cf-list-row').each(function () {
                                                var hit = !q || String($(this).attr('data-cf-title')).indexOf(q) !== -1;
                                                $(this).prop('hidden', !hit);
                                                if (hit) { shown++; }
                                            });
                                            $table.find('tr.em-cf-list-nomatch').prop('hidden', !q || shown > 0);
                                        }
                                        $('#em-cf-list-search').on('input', filter);
                                        // Styled confirm popup (replaces the browser's confirm()).
                                        var $dm = $('#em-cf-del-modal').appendTo('body'), $dmStatus = $dm.find('[data-cf-del-status]'),
                                            $dmGo = $dm.find('[data-cf-del-confirm]'), delBtn = null, lastFocus = null;
                                        function closeDel() {
                                            if ($dmGo.prop('disabled')) { return; }
                                            $dm.prop('hidden', true);
                                            if (lastFocus) { lastFocus.focus(); }
                                        }
                                        $table.on('click', '.em-cf-delete-btn', function () {
                                            delBtn = $(this);
                                            lastFocus = this;
                                            $dm.find('[data-cf-del-name]').text(delBtn.attr('data-cf-name') || <?php echo wp_json_encode(__('(no title)', 'email-manager')); ?>);
                                            $dmStatus.text('');
                                            $dmGo.prop('disabled', false).text(<?php echo wp_json_encode(__('Delete Chatflow', 'email-manager')); ?>);
                                            $dm.prop('hidden', false);
                                            setTimeout(function () { $dm.find('[data-cf-del-cancel]').trigger('focus'); }, 30);
                                        });
                                        $dm.on('click', '[data-cf-del-cancel]', closeDel);
                                        $dm.on('click', function (e) { if (e.target === this) { closeDel(); } });
                                        $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$dm.prop('hidden')) { closeDel(); } });
                                        $dmGo.on('click', function () {
                                            if (!delBtn) { return; }
                                            var $row = delBtn.closest('tr');
                                            $dmGo.prop('disabled', true).text(<?php echo wp_json_encode(__('Deleting…', 'email-manager')); ?>);
                                            $dmStatus.text('');
                                            $.post(ajaxurl, { action: 'em_cf_delete_chatflow', nonce: $table.attr('data-delete-nonce'), id: delBtn.attr('data-cf-delete') })
                                                .done(function (res) {
                                                    if (res && res.success) {
                                                        $row.remove();
                                                        filter();
                                                        $dmGo.prop('disabled', false);
                                                        lastFocus = null;
                                                        closeDel();
                                                    } else {
                                                        $dmStatus.text((res && res.data && res.data.message) || <?php echo wp_json_encode(__('Could not delete this chatflow.', 'email-manager')); ?>);
                                                        $dmGo.prop('disabled', false).text(<?php echo wp_json_encode(__('Try Again', 'email-manager')); ?>);
                                                    }
                                                })
                                                .fail(function () {
                                                    $dmStatus.text(<?php echo wp_json_encode(__('Could not delete this chatflow.', 'email-manager')); ?>);
                                                    $dmGo.prop('disabled', false).text(<?php echo wp_json_encode(__('Try Again', 'email-manager')); ?>);
                                                });
                                        });
                                    });
                                    </script>
                                    <?php
                                    // ── Runs per chatflow (completed chat_submission posts) ──
                                    global $wpdb;
                                    $em_cf_runs = array();
                                    foreach ((array) $wpdb->get_results(
                                        "SELECT pm.meta_value AS fid, COUNT(*) AS c
                                         FROM {$wpdb->postmeta} pm
                                         JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                                              AND p.post_type = 'chat_submission' AND p.post_status = 'publish'
                                         WHERE pm.meta_key = '_chat_submission_form_id'
                                         GROUP BY pm.meta_value", ARRAY_A
                                    ) as $em_cf_r) {
                                        $em_cf_runs[(int) $em_cf_r['fid']] = (int) $em_cf_r['c'];
                                    }
                                    // ── The front-end mini chat runs on THIS admin page for the
                                    //    Test column (same handles inject_widgets_admin uses, so
                                    //    no duplicate loads when a widget already targets admin).
                                    wp_enqueue_style('chat_forms_frontend_css', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.css', array(), '1.3.2', 'all');
                                    wp_enqueue_style('chat_forms_widget_launcher_css', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.css', array('chat_forms_frontend_css'), '1.3.0', 'all');
                                    wp_enqueue_script('chat_forms_frontend_js', EMAIL_MANAGER_URL . 'assets/forms/chat-frontend.js', array('jquery'), '1.10', true);
                                    wp_enqueue_script('chat_forms_widget_launcher_js', EMAIL_MANAGER_URL . 'assets/forms/chat-widget-launcher.js', array('jquery', 'chat_forms_frontend_js'), '1.0.0', true);
                                    $em_cf_cu = wp_get_current_user();
                                    wp_localize_script('chat_forms_frontend_js', 'chatFormsPublic', array(
                                        'ajaxUrl'     => admin_url('admin-ajax.php'),
                                        'nonce'       => wp_create_nonce('chat_forms_submit_nonce'),
                                        'isLoggedIn'  => is_user_logged_in(),
                                        'currentUser' => is_user_logged_in() ? array(
                                            'user_id'      => $em_cf_cu->ID,
                                            'username'     => $em_cf_cu->user_login,
                                            'display_name' => $em_cf_cu->display_name,
                                            'avatar_url'   => get_avatar_url($em_cf_cu->ID, array('size' => 96)),
                                            'email'        => $em_cf_cu->user_email,
                                        ) : array(),
                                        'brand'       => wp_parse_args(get_option('em_chat_brand_colors', array()), array(
                                            'primary'   => '#6366f1',
                                            'secondary' => '#8b5cf6',
                                            'surface'   => 'rgba(15, 23, 42, 0.9)',
                                            'text'      => '#f8fafc',
                                        )),
                                    ));
                                    ?>
                                    <div class="gdc-table-wrap">
                                        <table class="widefat striped" id="em-cf-list-table" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('em_cf_delete_chatflow')); ?>">
                                            <thead>
                                                <tr>
                                                    <th><?php esc_html_e('Title', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Runs', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Test', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Actions', 'email-manager'); ?></th>
                                                    <th><?php esc_html_e('Delete', 'email-manager'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $chat_forms = get_posts(array('post_type' => 'chat_form', 'numberposts' => -1, 'post_status' => 'publish,draft'));
                                                if ($chat_forms):
                                                    foreach ($chat_forms as $i => $post):
                                                        $em_cf_count = isset($em_cf_runs[$post->ID]) ? $em_cf_runs[$post->ID] : 0;
                                                        // Hidden front-end launcher — the Test button clicks it, so the
                                                        // REAL mini chat opens exactly as a visitor would see it.
                                                        $em_cf_cfg = get_post_meta($post->ID, '_chat_form_widget_config', true);
                                                        if (!is_array($em_cf_cfg)) $em_cf_cfg = array();
                                                        $em_cf_cfg = wp_parse_args($em_cf_cfg, array(
                                                            'image_url' => '', 'border_color' => '#6366f1',
                                                            'border_radius' => 16, 'position' => 'bottom-right',
                                                        ));
                                                        $em_cf_q = get_post_meta($post->ID, '_chat_form_questions', true);
                                                        if (!is_array($em_cf_q)) $em_cf_q = array();
                                                        $em_cf_form = array(
                                                            'id'           => (int) $post->ID,
                                                            'title'        => get_the_title($post->ID),
                                                            'image'        => $em_cf_cfg['image_url'],
                                                            'borderColor'  => $em_cf_cfg['border_color'],
                                                            'borderRadius' => (int) $em_cf_cfg['border_radius'],
                                                            'position'     => $em_cf_cfg['position'],
                                                            'botAvatar'    => get_post_meta($post->ID, '_chat_form_bot_avatar', true),
                                                            'questions'    => $em_cf_q,
                                                            'thankYou'     => get_post_meta($post->ID, '_chat_form_thank_you_message', true),
                                                        );
                                                ?>
                                                        <tr class="em-row em-cf-list-row" style="--em-i:<?php echo (int) $i; ?>;" data-cf-title="<?php echo esc_attr(strtolower($post->post_title)); ?>">
                                                            <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                                                            <td>
                                                                <button type="button" class="button button-small em-cf-runs-btn"
                                                                    data-cf-runs="<?php echo esc_attr($post->ID); ?>"
                                                                    <?php disabled(0 === $em_cf_count); ?>>
                                                                    <?php echo esc_html(sprintf(_n('%d run', '%d runs', $em_cf_count, 'email-manager'), $em_cf_count)); ?>
                                                                </button>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="button button-small em-cf-test-btn"
                                                                    data-cf-test="<?php echo esc_attr($post->ID); ?>">💬 <?php esc_html_e('Test', 'email-manager'); ?></button>
                                                                <div class="chat-widget-launcher" style="display:none;"
                                                                     data-form-id="<?php echo esc_attr($post->ID); ?>"
                                                                     data-position="<?php echo esc_attr($em_cf_cfg['position']); ?>"
                                                                     data-form='<?php echo esc_attr(wp_json_encode($em_cf_form)); ?>'
                                                                     style="--cw-border-color:<?php echo esc_attr($em_cf_cfg['border_color']); ?>;--cw-border-radius:<?php echo esc_attr($em_cf_cfg['border_radius']); ?>px;">
                                                                    <button type="button" class="chat-widget-launcher__btn" aria-label="<?php echo esc_attr(get_the_title($post->ID)); ?>"></button>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <a href="<?php echo admin_url('post.php?post=' . $post->ID . '&action=edit'); ?>"
                                                                    class="button button-small">
                                                                    <?php esc_html_e('Edit', 'email-manager'); ?>
                                                                </a>
                                                            </td>
                                                            <td>
                                                                <?php if (current_user_can('delete_post', $post->ID)) : ?>
                                                                    <button type="button" class="button button-small em-cf-delete-btn"
                                                                        data-cf-delete="<?php echo esc_attr($post->ID); ?>"
                                                                        data-cf-name="<?php echo esc_attr($post->post_title); ?>"><?php esc_html_e('Delete', 'email-manager'); ?></button>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach;
                                                else: ?>
                                                    <tr>
                                                        <td colspan="5"><?php esc_html_e('No chatflows found.', 'email-manager'); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                                <tr class="em-cf-list-nomatch" hidden>
                                                    <td colspan="5"><?php esc_html_e('No chatflows match your search.', 'email-manager'); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- ── Runs popup: completed runs by member → full transcript ── -->
                                    <div class="em-cf-runs-modal" id="em-cf-runs-modal" hidden>
                                        <div class="em-cf-runs-backdrop" data-cf-close></div>
                                        <div class="em-cf-runs-dialog" role="dialog" aria-modal="true">
                                            <div class="em-cf-runs-head">
                                                <h3 data-cf-runs-title><?php esc_html_e('Completed runs', 'email-manager'); ?></h3>
                                                <button type="button" class="em-cf-runs-x" data-cf-close aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                                            </div>
                                            <div class="em-cf-runs-body">
                                                <div data-cf-runs-list></div>
                                                <div data-cf-run-detail hidden></div>
                                            </div>
                                        </div>
                                    </div>
                                    <style>
                                        .em-cf-runs-modal { position: fixed; inset: 0; z-index: 100100; display: flex; align-items: center; justify-content: center; padding: 24px; }
                                        .em-cf-runs-modal[hidden] { display: none; }
                                        .em-cf-runs-backdrop { position: absolute; inset: 0; background: rgba(2,6,23,.8); backdrop-filter: blur(5px); }
                                        .em-cf-runs-dialog { position: relative; width: min(640px, 100%); max-height: 82vh; display: flex; flex-direction: column; background: #0a1019; border: 1px solid rgba(125,211,252,.25); border-radius: 16px; box-shadow: 0 50px 140px rgba(0,0,0,.6); }
                                        .em-cf-runs-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid rgba(125,211,252,.14); }
                                        .em-cf-runs-head h3 { margin: 0; color: #f8fafc; font-size: 15px; }
                                        .em-cf-runs-x { background: rgba(15,23,42,.85); color: #f1f5f9; border: 1px solid rgba(148,163,184,.3); border-radius: 999px; width: 30px; height: 30px; font-size: 18px; line-height: 1; cursor: pointer; }
                                        .em-cf-runs-body { padding: 16px 20px; overflow-y: auto; }
                                        .em-cf-muted { color: #94a3b8; font-size: 13px; }
                                        .em-cf-run-row { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; padding: 10px 12px; margin-bottom: 8px; background: rgba(2,6,23,.5); border: 1px solid rgba(125,211,252,.14); border-radius: 12px; cursor: pointer; color: #cbd5e1; }
                                        .em-cf-run-row:hover { border-color: rgba(125,211,252,.4); }
                                        .em-cf-run-row img { width: 34px; height: 34px; border-radius: 999px; background: rgba(125,211,252,.1); }
                                        .em-cf-run-row strong { color: #f1f5f9; display: block; }
                                        .em-cf-run-row small { color: #64748b; }
                                        .em-cf-run-row .em-cf-run-go { margin-left: auto; color: #7dd3fc; font-size: 12px; white-space: nowrap; }
                                        .em-cf-bubble { max-width: 85%; padding: 10px 14px; border-radius: 14px; margin: 8px 0; font-size: 13px; line-height: 1.5; }
                                        .em-cf-bubble.is-q { background: rgba(125,211,252,.1); border: 1px solid rgba(125,211,252,.2); color: #e2e8f0; margin-right: auto; }
                                        .em-cf-bubble.is-a { background: rgba(99,102,241,.18); border: 1px solid rgba(99,102,241,.35); color: #f1f5f9; margin-left: auto; }
                                    </style>
                                    <script>
                                    (function ($) {
                                        var nonce = <?php echo wp_json_encode(wp_create_nonce('em_cf_runs')); ?>;
                                        // Test → click the row's hidden front-end launcher, so the
                                        // REAL mini chat opens right on this admin page.
                                        $(document).on('click', '.em-cf-test-btn', function () {
                                            var id = $(this).attr('data-cf-test');
                                            var $btn = $('.chat-widget-launcher[data-form-id="' + id + '"] .chat-widget-launcher__btn').first();
                                            if ($btn.length) { $btn.trigger('click'); }
                                        });
                                        var $modal = $('#em-cf-runs-modal');
                                        function openM() { $modal.removeAttr('hidden'); }
                                        function closeM() { $modal.attr('hidden', 'hidden'); }
                                        $modal.on('click', '[data-cf-close]', closeM);
                                        $(document).on('keydown', function (e) { if (e.key === 'Escape') { closeM(); } });
                                        function escT(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
                                        $(document).on('click', '.em-cf-runs-btn', function () {
                                            var id = $(this).attr('data-cf-runs');
                                            $modal.find('[data-cf-runs-title]').text($(this).closest('tr').find('td:first strong').text());
                                            $modal.find('[data-cf-run-detail]').attr('hidden', 'hidden');
                                            var $list = $modal.find('[data-cf-runs-list]').removeAttr('hidden').html('<p class="em-cf-muted"><?php echo esc_js(__('Loading…', 'email-manager')); ?></p>');
                                            openM();
                                            $.post(ajaxurl, { action: 'em_cf_runs_list', nonce: nonce, form_id: id }, function (resp) {
                                                if (!resp || !resp.success) { $list.html('<p class="em-cf-muted"><?php echo esc_js(__('Could not load runs.', 'email-manager')); ?></p>'); return; }
                                                var rows = (resp.data && resp.data.runs) || [];
                                                if (!rows.length) { $list.html('<p class="em-cf-muted"><?php echo esc_js(__('No completed runs yet.', 'email-manager')); ?></p>'); return; }
                                                $list.html(rows.map(function (r) {
                                                    return '<button type="button" class="em-cf-run-row" data-sub="' + parseInt(r.id, 10) + '">'
                                                        + '<img src="' + escT(r.avatar) + '" alt="" />'
                                                        + '<span><strong>' + escT(r.member) + '</strong><small>' + escT(r.date) + '</small></span>'
                                                        + '<span class="em-cf-run-go"><?php echo esc_js(__('View transcript →', 'email-manager')); ?></span>'
                                                        + '</button>';
                                                }).join(''));
                                            });
                                        });
                                        $modal.on('click', '.em-cf-run-row', function () {
                                            var sid = $(this).attr('data-sub');
                                            $modal.find('[data-cf-runs-list]').attr('hidden', 'hidden');
                                            var $det = $modal.find('[data-cf-run-detail]').removeAttr('hidden').html('<p class="em-cf-muted"><?php echo esc_js(__('Loading transcript…', 'email-manager')); ?></p>');
                                            $.post(ajaxurl, { action: 'em_cf_run_detail', nonce: nonce, submission_id: sid }, function (resp) {
                                                if (!resp || !resp.success) { $det.html('<p class="em-cf-muted"><?php echo esc_js(__('Could not load the transcript.', 'email-manager')); ?></p>'); return; }
                                                var d = resp.data || {};
                                                var out = '<button type="button" class="button button-small" data-cf-back><?php echo esc_js(__('← Back to runs', 'email-manager')); ?></button>'
                                                    + '<p class="em-cf-muted" style="margin:10px 0;">' + escT((d.member || '') + ' · ' + (d.date || '')) + '</p>';
                                                (d.rows || []).forEach(function (r) {
                                                    if (r.q) { out += '<div class="em-cf-bubble is-q">' + escT(r.q) + '</div>'; }
                                                    out += '<div class="em-cf-bubble is-a">' + escT(r.a) + '</div>';
                                                });
                                                $det.html(out);
                                            });
                                        });
                                        $modal.on('click', '[data-cf-back]', function () {
                                            $modal.find('[data-cf-run-detail]').attr('hidden', 'hidden');
                                            $modal.find('[data-cf-runs-list]').removeAttr('hidden');
                                        });
                                    })(jQuery);
                                    </script>
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


// ─── Chatflows → Flows: Runs popup data (completed submissions + transcript) ──
add_action('wp_ajax_em_cf_runs_list', 'em_cf_runs_list_ajax');
function em_cf_runs_list_ajax()
{
    check_ajax_referer('em_cf_runs', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);
    $fid = isset($_POST['form_id']) ? (int) $_POST['form_id'] : 0;
    if (!$fid) wp_send_json_error(array('message' => 'no_form'), 400);
    $subs = get_posts(array(
        'post_type'   => 'chat_submission',
        'numberposts' => 100,
        'post_status' => 'publish',
        'meta_key'    => '_chat_submission_form_id',
        'meta_value'  => $fid,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ));
    $out = array();
    foreach ($subs as $s) {
        $u = $s->post_author ? get_userdata((int) $s->post_author) : null;
        $out[] = array(
            'id'     => (int) $s->ID,
            'date'   => get_the_date('M j, Y H:i', $s),
            'member' => $u ? (string) $u->display_name : __('Guest visitor', 'email-manager'),
            'avatar' => $u ? get_avatar_url($u->ID, array('size' => 48)) : '',
        );
    }
    wp_send_json_success(array('runs' => $out));
}

add_action('wp_ajax_em_cf_run_detail', 'em_cf_run_detail_ajax');
function em_cf_run_detail_ajax()
{
    check_ajax_referer('em_cf_runs', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'forbidden'), 403);
    $sid = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
    $sub = $sid ? get_post($sid) : null;
    if (!$sub || 'chat_submission' !== $sub->post_type) wp_send_json_error(array('message' => 'not_found'), 404);

    $data = get_post_meta($sid, '_chat_submission_data', true);
    $rows = array();
    if (is_array($data)) {
        // Answers arrive either as a list of {question, answer} entries or as
        // a question => answer map — normalize both into Q/A rows.
        foreach ($data as $k => $v) {
            if (is_array($v) && (isset($v['question']) || isset($v['answer']))) {
                $ans = isset($v['answer']) ? $v['answer'] : '';
                $rows[] = array(
                    'q' => (string) ($v['question'] ?? ''),
                    'a' => is_scalar($ans) ? (string) $ans : wp_json_encode($ans),
                );
            } else {
                $rows[] = array(
                    'q' => is_string($k) ? $k : '',
                    'a' => is_scalar($v) ? (string) $v : wp_json_encode($v),
                );
            }
        }
    }
    $u = $sub->post_author ? get_userdata((int) $sub->post_author) : null;
    wp_send_json_success(array(
        'member' => $u ? (string) $u->display_name : __('Guest visitor', 'email-manager'),
        'date'   => get_the_date('M j, Y H:i', $sub),
        'rows'   => $rows,
    ));
}
