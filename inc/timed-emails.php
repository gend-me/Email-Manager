<?php
/**
 * Onboarding emails and newsletters (Talk Flows > Email).
 *
 * Both are "timed" emails kept in one option, em_timed_emails:
 * - Onboarding: a series sent to new members of this site, each step a set number of days after they join. A step
 *   only sends while it is switched on, and only to members who joined after it was switched on, so turning one on
 *   never emails the existing membership.
 * - Newsletters: written here and sent from the editor to the people chosen there.
 *
 * Every one of these emails carries an unsubscribe link, and nobody who has used it is sent another. The bodies can
 * use {first_name}; {unsubscribe_url} is also filled, for a body that wants its own link.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

define('EM_TIMED_EMAILS_OPTION', 'em_timed_emails');

// ─── Storage ─────────────────────────────────────────────────────────────────

/**
 * Every onboarding email and newsletter, or one kind.
 *
 * @param string $kind onboarding, newsletter, or empty for both.
 * @return array
 */
function em_timed_emails($kind = '')
{
    $emails = get_option(EM_TIMED_EMAILS_OPTION, array());
    $emails = is_array($emails) ? array_values(array_filter($emails, 'is_array')) : array();
    if ($kind !== '') {
        $emails = array_values(array_filter($emails, function ($e) use ($kind) {
            return isset($e['kind']) && $e['kind'] === $kind;
        }));
    }
    if ($kind === 'onboarding') {
        usort($emails, function ($a, $b) {
            return (int) $a['days'] - (int) $b['days'];
        });
    }
    return $emails;
}

/**
 * One timed email.
 *
 * @param string $id Id.
 * @return array|null
 */
function em_timed_email($id)
{
    foreach (em_timed_emails() as $email) {
        if ($email['id'] === $id) {
            return $email;
        }
    }
    return null;
}

/**
 * Create or change a timed email. Only the fields given change.
 *
 * @param array $data id (empty = new), kind, label, subject, preheader, html, status, days.
 * @return array The saved email.
 */
function em_timed_email_save($data)
{
    $emails = em_timed_emails();
    $id     = isset($data['id']) ? sanitize_key($data['id']) : '';
    $now    = current_time('mysql', true);
    $email  = null;
    foreach ($emails as $i => $existing) {
        if ($id !== '' && $existing['id'] === $id) {
            $email = $existing;
            $at    = $i;
            break;
        }
    }
    if ($email === null) {
        $kind  = (isset($data['kind']) && $data['kind'] === 'onboarding') ? 'onboarding' : 'newsletter';
        $email = array(
            'id' => $id !== '' ? $id : uniqid($kind === 'onboarding' ? 'onb_' : 'news_'),
            'kind' => $kind, 'label' => '', 'subject' => '', 'preheader' => '', 'html' => '',
            'status' => 'draft', 'days' => 1, 'activated_at' => '', 'sent_at' => '', 'created_at' => $now,
        );
        $at = count($emails);
    }
    foreach (array('label', 'subject', 'preheader') as $field) {
        if (isset($data[$field])) {
            $email[$field] = sanitize_text_field($data[$field]);
        }
    }
    if (isset($data['html'])) {
        $email['html'] = wp_kses_post($data['html']);
    }
    if (isset($data['days'])) {
        $email['days'] = max(0, min(365, (int) $data['days']));
    }
    if (isset($data['status'])) {
        $allowed = $email['kind'] === 'onboarding' ? array('draft', 'active') : array('draft', 'scheduled', 'sent');
        $status  = in_array($data['status'], $allowed, true) ? $data['status'] : 'draft';
        // A step sends to members who join from the moment it is switched on.
        if ($status === 'active' && $email['status'] !== 'active') {
            $email['activated_at'] = $now;
        }
        if (in_array($status, array('sent', 'scheduled'), true)) {
            $email['sent_at'] = $now;
        }
        $email['status'] = $status;
    }
    if ($email['label'] === '') {
        $email['label'] = $email['kind'] === 'onboarding' ? __('Onboarding email', 'email-manager') : __('Newsletter', 'email-manager');
    }
    $email['updated_at'] = $now;
    $emails[$at] = $email;
    update_option(EM_TIMED_EMAILS_OPTION, array_values($emails), false);
    return $email;
}

/**
 * Remove a timed email.
 *
 * @param string $id Id.
 */
function em_timed_email_delete($id)
{
    update_option(EM_TIMED_EMAILS_OPTION, array_values(array_filter(em_timed_emails(), function ($e) use ($id) {
        return $e['id'] !== $id;
    })), false);
}

// ─── REST ────────────────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/timed-emails', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => function () {
                return array('success' => true, 'emails' => em_timed_emails());
            },
            'permission_callback' => 'em_rest_permission_check',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => function (WP_REST_Request $request) {
                $params = (array) $request->get_json_params();
                if (isset($params['_id']) && !isset($params['id'])) {
                    $params['id'] = $params['_id'];
                }
                if (!empty($params['id']) && !em_timed_email(sanitize_key($params['id']))) {
                    return new WP_Error('not_found', 'No such email', array('status' => 404));
                }
                return array('success' => true, 'email' => em_timed_email_save($params));
            },
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));
    register_rest_route('em/v1', '/timed-emails/(?P<id>[a-z0-9_]+)', array(
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => function (WP_REST_Request $request) {
                em_timed_email_delete(sanitize_key($request['id']));
                return array('success' => true);
            },
            'permission_callback' => 'em_rest_permission_check',
        ),
    ));
});

// ─── Unsubscribe ─────────────────────────────────────────────────────────────

/**
 * The key unsubscribe links are signed with. Kept in the database: wp-config salts are not stable on every host, and
 * a link has to keep working long after its email was sent.
 *
 * @return string
 */
function em_unsub_secret()
{
    $secret = (string) get_option('em_unsub_secret', '');
    if (strlen($secret) < 32) {
        $secret = wp_generate_password(64, false, false);
        update_option('em_unsub_secret', $secret, false);
    }
    return $secret;
}

/**
 * A recipient's unsubscribe link, good for 400 days.
 *
 * @param string $email Recipient.
 * @return string
 */
function em_unsub_url($email)
{
    $payload = rtrim(strtr(base64_encode(strtolower(trim($email))), '+/', '-_'), '=') . '.' . (time() + 400 * DAY_IN_SECONDS);
    return add_query_arg('em_unsub', $payload . '.' . hash_hmac('sha256', $payload, em_unsub_secret()), home_url('/'));
}

/**
 * The address inside an unsubscribe link, when the link is genuine and in date.
 *
 * @param string $token The em_unsub value.
 * @return string Empty when not valid.
 */
function em_unsub_email_from_token($token)
{
    $parts = explode('.', (string) $token);
    if (count($parts) !== 3 || !ctype_digit($parts[1]) || (int) $parts[1] < time()) {
        return '';
    }
    if (!hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], em_unsub_secret()), $parts[2])) {
        return '';
    }
    $email = (string) base64_decode(strtr($parts[0], '-_', '+/'), true);
    return is_email($email) ? $email : '';
}

/**
 * Has this address unsubscribed from onboarding emails and newsletters?
 *
 * @param string $email Address.
 * @return bool
 */
function em_is_unsubscribed($email)
{
    $list = get_option('em_unsubscribed', array());
    return is_array($list) && isset($list[hash('sha256', strtolower(trim($email)))]);
}

/**
 * Record an unsubscribe. The address is kept as a hash; a matching list subscriber is marked unsubscribed too.
 *
 * @param string $email Address.
 */
function em_unsubscribe($email)
{
    $list = get_option('em_unsubscribed', array());
    $list = is_array($list) ? $list : array();
    $list[hash('sha256', strtolower(trim($email)))] = time();
    update_option('em_unsubscribed', $list, false);
    if (function_exists('em_get_subscriber') && function_exists('em_update_subscriber_status')) {
        $subscriber = em_get_subscriber($email);
        if ($subscriber && !empty($subscriber->id)) {
            em_update_subscriber_status((int) $subscriber->id, 'unsubscribed');
        }
    }
}

/**
 * The unsubscribe page. Opening the link only asks: mail scanners follow links, so the unsubscribe itself needs the
 * button (or a mail app's one-click request, which arrives as a POST).
 */
add_action('template_redirect', function () {
    if (!isset($_GET['em_unsub'])) {
        return;
    }
    $token = sanitize_text_field(wp_unslash($_GET['em_unsub']));
    $email = em_unsub_email_from_token($token);
    $name  = get_bloginfo('name');
    nocache_headers();
    if ($email === '') {
        wp_die(esc_html__('This unsubscribe link is no longer valid. Please use the link in a more recent email.', 'email-manager'), esc_html__('Unsubscribe', 'email-manager'), array('response' => 400));
    }
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        em_unsubscribe($email);
        wp_die(
            '<h1>' . esc_html__('You’re unsubscribed', 'email-manager') . '</h1><p>' . esc_html(sprintf(
                /* translators: 1: email address, 2: site name */
                __('%1$s won’t get onboarding emails or newsletters from %2$s any more. Emails about your account, orders and payments still arrive.', 'email-manager'),
                $email,
                $name
            )) . '</p>',
            esc_html__('Unsubscribed', 'email-manager'),
            array('response' => 200)
        );
    }
    wp_die(
        '<h1>' . esc_html__('Unsubscribe', 'email-manager') . '</h1><p>' . esc_html(sprintf(
            /* translators: 1: email address, 2: site name */
            __('Stop onboarding emails and newsletters from %2$s to %1$s?', 'email-manager'),
            $email,
            $name
        )) . '</p><form method="post"><button type="submit" style="padding:10px 22px;font-size:15px;cursor:pointer;">' . esc_html__('Yes, unsubscribe me', 'email-manager') . '</button></form>',
        esc_html__('Unsubscribe', 'email-manager'),
        array('response' => 200)
    );
}, 1);

// ─── Sending ─────────────────────────────────────────────────────────────────

/**
 * Send one onboarding email or newsletter to one address: placeholders filled, unsubscribe link and header added.
 *
 * @param array  $email Timed email (subject, preheader, html, kind).
 * @param string $to    Recipient.
 * @return bool Accepted for delivery. False for an address that has unsubscribed.
 */
function em_timed_email_send($email, $to)
{
    if (!is_email($to) || em_is_unsubscribed($to)) {
        return false;
    }
    $user  = get_user_by('email', $to);
    $first = $user ? (trim((string) $user->first_name) !== '' ? $user->first_name : $user->display_name) : '';
    $unsub = em_unsub_url($to);
    $fill  = array(
        '{first_name}'      => esc_html($first !== '' ? $first : __('there', 'email-manager')),
        '{unsubscribe_url}' => esc_url($unsub),
    );
    $subject = wp_strip_all_tags(strtr((string) $email['subject'], array('{first_name}' => $first !== '' ? $first : __('there', 'email-manager'))));
    $html    = em_outgoing_email_html(strtr((string) $email['html'], $fill), $subject, (string) $email['preheader'], $to, array(
        'unsubscribe' => $unsub,
        'label'       => $email['kind'] === 'onboarding' ? __('Welcome', 'email-manager') : __('News', 'email-manager'),
    ));
    // Without a site email template the link still has to be in the email.
    if (strpos($html, 'em_unsub=') === false) {
        $html .= '<p style="font-size:12px;color:#777;text-align:center;"><a href="' . esc_url($unsub) . '" style="color:#777;">' . esc_html__('Unsubscribe from these emails', 'email-manager') . '</a></p>';
    }
    return (bool) wp_mail($to, $subject, $html, array(
        'Content-Type: text/html; charset=UTF-8',
        'List-Unsubscribe: <' . esc_url_raw($unsub) . '>',
        'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
    ));
}

// ─── Onboarding series ───────────────────────────────────────────────────────

add_action('init', function () {
    if (!wp_next_scheduled('em_onboarding_tick')) {
        wp_schedule_event(time() + 600, 'hourly', 'em_onboarding_tick');
    }
});

/**
 * Hourly: each switched-on step goes to the members of this site who joined after it was switched on, are due it,
 * and have not had it. A member is claimed before the send, so a slow or repeated run never sends a step twice.
 */
add_action('em_onboarding_tick', function () {
    foreach (em_timed_emails('onboarding') as $step) {
        if ($step['status'] !== 'active' || empty($step['activated_at']) || $step['html'] === '') {
            continue;
        }
        $days  = (int) $step['days'];
        // Not before the step was switched on, and never more than three days late: a step that missed its day is skipped.
        $after = max(strtotime($step['activated_at'] . ' UTC'), time() - ($days + 3) * DAY_IN_SECONDS);
        $users = get_users(array(
            'date_query' => array(array(
                'after'     => gmdate('Y-m-d H:i:s', $after),
                'before'    => gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS),
                'inclusive' => true,
            )),
            'meta_query' => array(array('key' => '_em_onb_' . $step['id'], 'compare' => 'NOT EXISTS')),
            'number'     => 40,
            'fields'     => array('ID', 'user_email'),
        ));
        foreach ($users as $user) {
            if (add_user_meta($user->ID, '_em_onb_' . $step['id'], time(), true)) {
                em_timed_email_send($step, $user->user_email);
            }
        }
    }
});

// ─── Admin rows ──────────────────────────────────────────────────────────────

/**
 * What the editor needs to open a timed email.
 *
 * @param array $email Timed email.
 * @return string JSON for the Edit button's data-email.
 */
function em_timed_email_editor_data($email)
{
    return wp_json_encode(array(
        'section'   => 'timed',
        '_id'       => $email['id'],
        'kind'      => $email['kind'],
        'label'     => $email['label'],
        'subject'   => $email['subject'],
        'preheader' => $email['preheader'],
        'html'      => $email['html'],
        'status'    => $email['status'],
        'days'      => (int) $email['days'],
    ));
}

/**
 * The rows of the Onboarding Emails or Newsletters table.
 *
 * @param string $kind onboarding or newsletter.
 */
function em_render_timed_email_rows($kind)
{
    $emails = em_timed_emails($kind);
    if (!$emails) {
        echo '<tr><td colspan="4">' . esc_html($kind === 'onboarding'
            ? __('No onboarding emails yet. Add the first step of your welcome series.', 'email-manager')
            : __('No newsletters yet. Create one to get started.', 'email-manager')) . '</td></tr>';
        return;
    }
    foreach ($emails as $email) {
        $on = $email['status'] === 'active';
        echo '<tr data-timed-id="' . esc_attr($email['id']) . '">';
        echo '<td><strong class="gdc-list-title">' . esc_html($email['label']) . '</strong><br><span class="description">' . esc_html($email['subject']) . '</span></td>';
        if ($kind === 'onboarding') {
            echo '<td><label><input type="number" class="small-text em-timed-days" min="0" max="365" value="' . esc_attr((int) $email['days']) . '"> ' . esc_html__('days after joining', 'email-manager') . '</label></td>';
            echo '<td><label><input type="checkbox" class="em-timed-toggle" ' . checked($on, true, false) . '> <span class="gdc-list-pill is-' . ($on ? 'active' : 'draft') . '">' . esc_html($on ? __('On', 'email-manager') : __('Off', 'email-manager')) . '</span></label></td>';
        } else {
            $when = $email['sent_at'] !== '' ? mysql2date(get_option('date_format'), get_date_from_gmt($email['sent_at'])) : '—';
            echo '<td>' . esc_html($when) . '</td>';
            echo '<td><span class="gdc-list-pill is-' . esc_attr($email['status']) . '">' . esc_html(ucfirst($email['status'])) . '</span></td>';
        }
        echo '<td><button type="button" class="button button-small gdc-email-open-editor" data-email-section="timed" data-email="' . esc_attr(em_timed_email_editor_data($email)) . '">' . esc_html__('Edit', 'email-manager') . '</button> ';
        echo '<button type="button" class="button button-small button-link-delete em-timed-delete">' . esc_html__('Delete', 'email-manager') . '</button></td>';
        echo '</tr>';
    }
}
