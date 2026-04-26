<?php
/**
 * WooCommerce Email Full-Template Override System
 *
 * Lets Email Manager show, edit, and fully override the HTML of
 * any WooCommerce transactional email.  Custom templates are stored
 * in WordPress options and applied via the woocommerce_mail_content
 * filter at send-time.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

define('EM_WC_OVERRIDE_PREFIX', 'em_wc_email_override_');

// ─── Send-time hook ──────────────────────────────────────────────────────────

/**
 * Step 1: Capture the WC_Email object BEFORE woocommerce_mail_content fires.
 *
 * woocommerce_email_header fires inside get_content_html() → before style_inline()
 * → before woocommerce_mail_content. Works in all WooCommerce versions.
 */
add_action('woocommerce_email_header', function ($email_heading, $email = null) {
    if ($email && isset($email->id)) {
        $GLOBALS['em_wc_sending_email'] = $email;
    }
}, 5, 2);

// Also capture from the order-table action (order emails)
add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain_text, $email) {
    if ($email && isset($email->id)) {
        $GLOBALS['em_wc_sending_email'] = $email;
    }
}, 5, 4);

/**
 * Step 2: Replace outgoing content with saved override.
 *
 * The filter is registered with 2 args; when WC only passes 1, $email will be
 * null and we fall back to the globally captured object. Reset after use.
 */
add_filter('woocommerce_mail_content', 'em_wc_apply_email_override', 20, 2);

function em_wc_apply_email_override($content, $email = null)
{
    // Prefer the explicitly-passed object; fall back to global capture
    if (!$email || !isset($email->id)) {
        $email = isset($GLOBALS['em_wc_sending_email']) ? $GLOBALS['em_wc_sending_email'] : null;
    }

    // Always reset the global so it doesn't bleed into the next email
    $GLOBALS['em_wc_sending_email'] = null;

    if (!$email || !isset($email->id)) {
        return $content;
    }

    $override = get_option(EM_WC_OVERRIDE_PREFIX . $email->id, '');
    if (empty($override)) {
        return $content;
    }

    return em_wc_process_tokens($override, $email);
}


// ─── WordPress Core Account Email Overrides ──────────────────────────────────

/**
 * Apply override to WP password reset email.
 * Fires on retrieve_password_message (WP core).
 */
add_filter('retrieve_password_message', function ($message, $key, $user_login, $user_data) {
    $override = get_option(EM_WC_OVERRIDE_PREFIX . 'password_reset', '');
    if (empty($override)) return $message;
    $link = network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user_login), 'login');
    return str_replace(
        ['{customer_username}', '{customer_name}', '{password_reset_link}', '{site_title}', '{site_url}'],
        [$user_login, $user_data->display_name, $link, get_bloginfo('name'), home_url()],
        $override
    );
}, 20, 4);

/**
 * Apply override to the new-user admin notification email.
 * Fires on wp_new_user_notification_email_admin (WP 4.9+).
 */
add_filter('wp_new_user_notification_email_admin', function ($email_arr, $user, $blogname) {
    $override = get_option(EM_WC_OVERRIDE_PREFIX . 'new_user_registration', '');
    if (empty($override)) return $email_arr;
    $email_arr['message'] = str_replace(
        ['{customer_name}', '{customer_username}', '{customer_email}', '{site_title}', '{site_url}', '{account_url}'],
        [$user->display_name, $user->user_login, $user->user_email, $blogname, home_url(), admin_url()],
        $override
    );
    return $email_arr;
}, 20, 3);

/**
 * Apply override to the new-user welcome email (sent to the registrant).
 * Fires on wp_new_user_notification_email (WP 4.9+).
 */
add_filter('wp_new_user_notification_email', function ($email_arr, $user, $blogname) {
    $override = get_option(EM_WC_OVERRIDE_PREFIX . 'new_user_welcome', '');
    if (empty($override)) return $email_arr;
    $email_arr['message'] = str_replace(
        ['{customer_name}', '{customer_username}', '{customer_email}', '{site_title}', '{site_url}', '{account_url}'],
        [$user->display_name, $user->user_login, $user->user_email, $blogname, home_url(), get_edit_user_link($user->ID)],
        $override
    );
    return $email_arr;
}, 20, 3);

// ─── Account token list ───────────────────────────────────────────────────────

/**
 * Returns token strings shown as chips for WP core account emails.
 */
function em_wp_account_tokens($email_id = '')
{
    $shared = ['{customer_name}', '{customer_username}', '{customer_email}', '{site_title}', '{site_url}', '{account_url}'];
    if ($email_id === 'password_reset') {
        return array_merge($shared, ['{password_reset_link}']);
    }
    return $shared;
}

// ─── Account email default templates (used by render endpoint) ───────────────

/**
 * Return a WP account email default template for the render endpoint fallback.
 * These show the real WP email structure with {token} placeholders.
 */
function em_wp_account_default_template($email_id)
{
    $site  = get_bloginfo('name');
    $url   = home_url();

    $configs = [
        'password_reset' => [
            'heading' => 'Password Reset',
            'body'    => "<p>Hi {customer_username},</p>\n<p>Someone has requested a new password for the following account on <strong>{site_title}</strong>.</p>\n<p>If you didn&rsquo;t make this request, just ignore this email.</p>\n<p>To reset your password, visit the following address:<br><a href=\"{password_reset_link}\" style=\"color:#cc0000;\">{password_reset_link}</a></p>",
        ],
        'new_user_registration' => [
            'heading' => 'New User Registration',
            'body'    => "<p>New user registration on <strong>{site_title}</strong>:</p>\n<p><strong>Username:</strong> {customer_username}<br><strong>Email:</strong> {customer_email}</p>\n<p><a href=\"{account_url}\" style=\"color:#cc0000;\">View in Dashboard</a></p>",
        ],
        'new_user_welcome' => [
            'heading' => 'Welcome to {site_title}',
            'body'    => "<p>Hi {customer_name},</p>\n<p>Thanks for registering on <strong><a href=\"{site_url}\">{site_title}</a></strong>. Your username is <strong>{customer_username}</strong>.</p>\n<p><a href=\"{account_url}\" style=\"color:#cc0000;\">View your account</a></p>",
        ],
        'email_change_confirmation' => [
            'heading' => 'Email Change Request',
            'body'    => "<p>Hi {customer_name},</p>\n<p>You recently requested to change the email address on your <strong>{site_title}</strong> account.</p>\n<p>If this is correct, please confirm by clicking the link in the email sent to your new address.</p>",
        ],
    ];

    $cfg     = isset($configs[$email_id]) ? $configs[$email_id] : ['heading' => 'Notification', 'body' => '<p>You have a notification from <strong>{site_title}</strong>.</p>'];
    $heading = $cfg['heading'];
    $body    = $cfg['body'];

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f7f7f7;font-family:Arial,sans-serif;">
<table cellpadding="0" cellspacing="0" width="100%" style="padding:30px 0;background:#f7f7f7;">
  <tr><td align="center">
    <table cellpadding="0" cellspacing="0" width="600" style="background:#ffffff;border:1px solid #e4e4e4;">
      <tr><td style="background:#cc0000;padding:30px 40px;">
        <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;">$heading</h1>
      </td></tr>
      <tr><td style="padding:30px 40px;color:#333333;font-size:14px;line-height:1.6;">
        $body
      </td></tr>
      <tr><td style="padding:20px 40px;background:#f7f7f7;border-top:1px solid #e4e4e4;text-align:center;font-size:12px;color:#777777;">
        <p><a href="$url" style="color:#777777;">$site</a></p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ─── BuddyPress email save handler ───────────────────────────────────────────

/**
 * POST /em/v1/bp-email-save
 *
 * Updates the content (template) of a BuddyPress bp-email CPT post.
 * BuddyPress reads post_content at send-time, so saving the post is sufficient.
 */
function em_bp_email_save_handler(WP_REST_Request $request)
{
    $body    = json_decode($request->get_body(), true);
    $slug    = isset($body['email_id'])           ? sanitize_text_field($body['email_id'])      : '';
    $subject = isset($body['subject'])            ? sanitize_text_field($body['subject'])        : null;
    $html    = isset($body['additional_content']) ? em_sanitize_email_html($body['additional_content']) : null;

    if (empty($slug)) {
        return new WP_Error('missing_id', 'email_id is required', ['status' => 400]);
    }

    if (!post_type_exists('bp-email')) {
        return new WP_Error('bp_inactive', 'BuddyPress is not active', ['status' => 503]);
    }

    // Find the post by its slug (post_name)
    $posts = get_posts(['name' => $slug, 'post_type' => 'bp-email', 'post_status' => 'publish', 'numberposts' => 1]);
    if (empty($posts)) {
        return new WP_Error('not_found', 'BuddyPress email not found: ' . $slug, ['status' => 404]);
    }

    $post_id = $posts[0]->ID;
    $update  = ['ID' => $post_id];

    if ($subject !== null) {
        // BuddyPress stores the subject in post_excerpt
        $update['post_excerpt'] = $subject;
    }
    if ($html !== null) {
        $update['post_content'] = $html;
    }

    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        return new WP_Error('save_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['success' => true, 'email_id' => $slug, 'post_id' => $post_id];
}

// ─── Token replacement ───────────────────────────────────────────────────────


/**
 * Replace {token} placeholders with real order/site data.
 */
function em_wc_process_tokens($template, $email)
{
    $replacements = em_wc_get_token_replacements($email);
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * Build the token → value map for the given email object.
 */
function em_wc_get_token_replacements($email)
{
    $order = (isset($email->object) && is_a($email->object, 'WC_Abstract_Order'))
        ? $email->object
        : null;

    $map = [
        '{site_title}'   => get_bloginfo('name'),
        '{site_url}'     => home_url(),
        '{site_address}' => wp_parse_url(home_url(), PHP_URL_HOST),
    ];

    if ($order) {
        $map['{customer_first_name}'] = esc_html($order->get_billing_first_name());
        $map['{customer_last_name}']  = esc_html($order->get_billing_last_name());
        $map['{customer_name}']       = esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
        $map['{customer_email}']      = esc_html($order->get_billing_email());
        $map['{customer_phone}']      = esc_html($order->get_billing_phone());
        $map['{order_number}']        = esc_html($order->get_order_number());
        $map['{order_date}']          = esc_html(wc_format_datetime($order->get_date_created()));
        $map['{order_total}']         = $order->get_formatted_order_total();
        $map['{order_subtotal}']      = wc_price($order->get_subtotal());
        $map['{payment_method}']      = esc_html($order->get_payment_method_title());
        $map['{shipping_method}']     = esc_html($order->get_shipping_method());
        $map['{order_table}']         = em_wc_build_order_table($order);
        $map['{billing_address}']     = em_wc_wrap_address($order->get_formatted_billing_address());
        $map['{shipping_address}']    = em_wc_wrap_address($order->get_formatted_shipping_address());
        $map['{order_link}']          = '<a href="' . esc_url($order->get_view_order_url()) . '">'
            . esc_html(sprintf(__('Order #%s', 'email-manager'), $order->get_order_number()))
            . '</a>';
    }

    return apply_filters('em_wc_token_replacements', $map, $email, $order);
}

/**
 * Returns the flat list of token strings used in the popup token chips.
 * Used by em_get_wc_email_tokens() (alias below) and also called from admin pages.
 */
function em_wc_all_tokens()
{
    return [
        '{customer_first_name}',
        '{customer_last_name}',
        '{customer_name}',
        '{customer_email}',
        '{customer_phone}',
        '{order_number}',
        '{order_date}',
        '{order_total}',
        '{order_subtotal}',
        '{order_table}',
        '{order_link}',
        '{billing_address}',
        '{shipping_address}',
        '{payment_method}',
        '{shipping_method}',
        '{site_title}',
        '{site_url}',
        '{site_address}',
    ];
}

/**
 * Alias used by email-manager-admin.php and store-admin.php.
 * Replaces any previously sparse version of this function.
 *
 * @param WC_Email $email
 * @return string[]
 */
function em_get_wc_email_tokens($email)
{
    return em_wc_all_tokens();
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Sanitize full email HTML for admin users.
 *
 * wp_kses_post() strips <!DOCTYPE>, <html>, <head>, <body>, <style>, <meta>
 * which are all essential in email documents.
 *
 * Since this endpoint already requires manage_options we can be permissive:
 * strip only <script> tags and on* event attributes (which email clients
 * ignore anyway) and leave everything else intact.
 *
 * @param  string $html Raw HTML from admin.
 * @return string Cleaned HTML ready for wp_mail delivery.
 */
function em_sanitize_email_html($html)
{
    if (!is_string($html) || $html === '') {
        return '';
    }

    // Remove <script> blocks (they don't work in email clients anyway)
    $html = preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $html);

    // Remove inline event handlers (onclick, onload, onerror, etc.)
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>\/]*)/i', '', $html);

    // Remove javascript: URLs
    $html = preg_replace('/\bhref\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', 'href="#"', $html);

    return $html;
}




/** Build a styled HTML order items table. */
function em_wc_build_order_table($order)
{
    $td = 'style="border:1px solid #e8e8e8;padding:10px 15px;text-align:left;font-size:14px;"';
    $th = 'style="border:1px solid #e8e8e8;padding:10px 15px;text-align:left;font-size:13px;background:#f8f8f8;"';

    $html  = '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;border:1px solid #e8e8e8;margin:20px 0;">';
    $html .= "<thead><tr><th $th>" . __('Product', 'email-manager') . "</th><th $th>" . __('Quantity', 'email-manager') . "</th><th $th>" . __('Price', 'email-manager') . "</th></tr></thead><tbody>";

    foreach ($order->get_items() as $item) {
        $html .= "<tr><td $td>" . esc_html($item->get_name()) . "</td>"
               . "<td $td>" . esc_html($item->get_quantity()) . "</td>"
               . "<td $td>" . wc_price($item->get_total()) . "</td></tr>";
    }

    // Totals rows
    $totals = [
        __('Subtotal', 'email-manager') => wc_price($order->get_subtotal()),
    ];
    foreach ($order->get_tax_totals() as $tax) {
        $totals[esc_html($tax->label)] = wp_kses_post($tax->formatted_amount);
    }
    if ($order->get_total_shipping() > 0) {
        $totals[__('Shipping', 'email-manager')] = wc_price($order->get_total_shipping());
    }
    if ($order->get_discount_total() > 0) {
        $totals[__('Discount', 'email-manager')] = '-' . wc_price($order->get_discount_total());
    }
    $totals['<strong>' . __('Total', 'email-manager') . '</strong>'] = '<strong>' . $order->get_formatted_order_total() . '</strong>';

    foreach ($totals as $label => $value) {
        $html .= "<tr><td $td colspan=\"2\">$label</td><td $td>$value</td></tr>";
    }

    $html .= '</tbody></table>';
    return $html;
}

/** Wrap a formatted address string in a styled div. */
function em_wc_wrap_address($addr)
{
    if (empty($addr)) return '';
    return '<div style="border:1px solid #e8e8e8;padding:15px;font-size:14px;line-height:1.6;">'
        . wp_kses_post(nl2br($addr)) . '</div>';
}

/** Return a generic fallback template (used when WC cannot render). */
function em_wc_default_template($heading)
{
    $site = get_bloginfo('name');
    $url  = home_url();
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f7f7f7;font-family:Arial,sans-serif;">
<table cellpadding="0" cellspacing="0" width="100%" style="padding:30px 0;background:#f7f7f7;">
  <tr><td align="center">
    <table cellpadding="0" cellspacing="0" width="600" style="background:#ffffff;border:1px solid #e4e4e4 ;">
      <tr><td style="background:#cc0000;padding:30px 40px;">
        <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;">{$heading}</h1>
      </td></tr>
      <tr><td style="padding:30px 40px;color:#333333;font-size:14px;line-height:1.6;">
        <p>Hi {customer_first_name},</p>
        <p>Thank you for your order. Here are your order details:</p>
        {order_table}
        <h2 style="color:#cc0000;font-size:16px;margin-top:30px;">Billing address</h2>
        {billing_address}
        <p style="margin-top:30px;">Thanks for using <a href="{$url}" style="color:#cc0000;">{$site}</a>!</p>
      </td></tr>
      <tr><td style="padding:20px 40px;background:#f7f7f7;border-top:1px solid #e4e4e4;text-align:center;font-size:12px;color:#777777;">
        <p><a href="{$url}" style="color:#777777;">{$site}</a></p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ─── REST: render full email HTML ────────────────────────────────────────────

/**
 * GET /em/v1/wc-email-render?email_id=...
 *
 * Returns either the user's saved override HTML or WooCommerce's default
 * rendered HTML (using the most recent order as a live data source).
 */
function em_wc_email_render_handler(WP_REST_Request $request)
{
    $email_id = sanitize_key($request->get_param('email_id'));
    if (empty($email_id)) {
        return new WP_Error('missing_id', 'email_id is required', ['status' => 400]);
    }

    // Return saved custom template if it exists
    $override = get_option(EM_WC_OVERRIDE_PREFIX . $email_id, '');
    if (!empty($override)) {
        return ['success' => true, 'html' => $override, 'source' => 'override'];
    }

    // Is it a WP core account email?
    $core_emails = ['password_reset', 'new_user_registration', 'new_user_welcome', 'email_change_confirmation'];
    if (in_array($email_id, $core_emails)) {
        return [
            'success' => true,
            'html'    => em_wp_account_default_template($email_id),
            'source'  => 'woocommerce', // reuse the same notice logic
            'notice'  => 'This is a preview of the default WordPress email structure. Edit the HTML and click Save to create your custom version.'
        ];
    }

    // Render WooCommerce's default template
    if (!class_exists('WC_Emails')) {
        return new WP_Error('wc_inactive', 'WooCommerce is not active', ['status' => 503]);
    }

    $wc_emails = WC_Emails::instance()->get_emails();
    $email = null;
    foreach ($wc_emails as $e) {
        if ($e->id === $email_id) {
            $email = $e;
            break;
        }
    }

    if (!$email) {
        return new WP_Error('not_found', 'Email type not found', ['status' => 404]);
    }

    // Attach the most recent order so the template renders real data
    $orders = wc_get_orders(['limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'any']);
    $html = '';

    if (!empty($orders)) {
        $email->object = $orders[0];
        if (method_exists($email, 'set_email_strings')) {
            try { $email->set_email_strings(); } catch (Exception $ex) { /* ignore */ }
        }
        try {
            ob_start();
            $html = $email->get_content_html();
            if (ob_get_level()) ob_end_clean();
        } catch (Exception $ex) {
            if (ob_get_level()) ob_end_clean();
            $html = '';
        }
    }

    if (empty($html)) {
        $heading = method_exists($email, 'get_heading') ? $email->get_heading() : $email->get_title();
        $html = em_wc_default_template($heading);
    }

    return [
        'success' => true,
        'html'    => $html,
        'source'  => 'woocommerce',
        'notice'  => 'This is a live preview of the default WooCommerce email. Edit and Save to create your custom version. Use {token} chips to insert dynamic order data.',
    ];
}
