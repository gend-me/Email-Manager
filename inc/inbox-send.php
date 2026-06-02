<?php
/**
 * Member Inbox: outbound send endpoint (slice 2f).
 *
 *   POST /em/v1/inbox/send
 *     { thread_id?, to:[…], subject, body_plain?, body_html? }
 *
 * The sender is always derived from the current WP user's
 * em_inbox_address — clients can't spoof an arbitrary From. Admins
 * can impersonate an arbitrary inbox via `from_override`.
 *
 * For a reply, pass thread_id; we look up the most recent message in
 * that thread and inject In-Reply-To + References automatically.
 *
 * Submission path: POST to http://email-mta-submit:8080/submit (the
 * cluster-internal Service exposed by http_submitter on the MTA pod),
 * HMAC-SHA256 signed with the same em_inbox_hmac_secret that inbound
 * webhooks use. The MTA delivers directly to recipient MX with DKIM.
 *
 * After successful submission, the message is mirrored into
 * wp_gdc_inbox_raw with recipient=sender so it threads into the
 * sender's own view of the conversation. The synchronous threading
 * worker stitches it onto the existing thread via In-Reply-To.
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_SUBMIT_URL_DEFAULT', 'http://email-mta-submit:8080/submit');

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/send', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_send_handler',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_send_handler(WP_REST_Request $request) {
    global $wpdb;
    $payload = $request->get_json_params() ?: array();

    // ── derive sender ────────────────────────────────────────────────
    $user = wp_get_current_user();
    $from = '';
    if (current_user_can('manage_options') && ! empty($payload['from_override'])) {
        $from = strtolower(trim((string) $payload['from_override']));
    } else {
        $from = strtolower(trim((string) get_user_meta($user->ID, 'em_inbox_address', true)));
    }
    if ($from === '' || ! is_email($from)) {
        return new WP_Error('em_inbox_send_no_address',
            'You do not have a configured inbox address. The site admin must set em_inbox_default_domain and run `wp em-inbox backfill`.',
            array('status' => 400));
    }

    // ── normalize recipients ─────────────────────────────────────────
    $to_raw = isset($payload['to']) ? $payload['to'] : array();
    if (is_string($to_raw)) $to_raw = preg_split('/[,;\s]+/', $to_raw, -1, PREG_SPLIT_NO_EMPTY);
    if (! is_array($to_raw) || count($to_raw) === 0) {
        return new WP_Error('em_inbox_send_no_to', 'At least one recipient is required', array('status' => 400));
    }
    $to = array();
    foreach ($to_raw as $addr) {
        $addr = trim((string) $addr);
        if ($addr !== '' && is_email($addr)) $to[] = strtolower($addr);
    }
    if (empty($to)) {
        return new WP_Error('em_inbox_send_bad_to', 'No valid recipient addresses', array('status' => 400));
    }

    $subject    = (string) ($payload['subject']    ?? '');
    $body_plain = (string) ($payload['body_plain'] ?? '');
    $body_html  = (string) ($payload['body_html']  ?? '');
    if ($body_plain === '' && $body_html === '') {
        return new WP_Error('em_inbox_send_empty', 'Message body is empty', array('status' => 400));
    }

    // Attachments: client sends as base64. Cap total size to keep
    // memory bounded; 25 MiB matches typical inbound limits.
    $attachments_in = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : array();
    $total_bytes    = 0;
    $attachments    = array();
    foreach ($attachments_in as $att) {
        if (empty($att['content_b64'])) continue;
        $bin = base64_decode((string) $att['content_b64'], true);
        if ($bin === false) {
            return new WP_Error('em_inbox_send_bad_attachment', 'Attachment base64 decode failed', array('status' => 400));
        }
        $total_bytes += strlen($bin);
        if ($total_bytes > 26214400) {
            return new WP_Error('em_inbox_send_too_large', 'Attachments exceed 25 MiB', array('status' => 413));
        }
        $attachments[] = array(
            'filename'     => isset($att['filename'])     ? (string) $att['filename']     : 'attachment',
            'content_type' => isset($att['content_type']) ? (string) $att['content_type'] : 'application/octet-stream',
            'content_b64'  => $att['content_b64'],   // pass through to submitter
            'size'         => strlen($bin),
        );
    }

    // ── threading headers (auto-derived for replies) ─────────────────
    $extra_headers = array();
    $thread_id     = isset($payload['thread_id']) ? (int) $payload['thread_id'] : 0;
    if ($thread_id) {
        $msg_table = $wpdb->prefix . 'gdc_inbox_messages';
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT m.message_id, m.refs_json
             FROM $msg_table m
             WHERE m.thread_id = %d
             ORDER BY m.received_at DESC, m.id DESC
             LIMIT 1",
            $thread_id
        ), ARRAY_A);
        if ($latest) {
            $extra_headers[] = array('name' => 'In-Reply-To', 'value' => $latest['message_id']);
            $refs = array();
            if (! empty($latest['refs_json'])) {
                $decoded = json_decode($latest['refs_json'], true);
                if (is_array($decoded)) $refs = $decoded;
            }
            $refs[] = $latest['message_id'];
            // Cap at 50 references to keep header sane.
            $refs = array_slice(array_unique($refs), -50);
            $extra_headers[] = array('name' => 'References', 'value' => implode(' ', $refs));
            // Subject inherits "Re: …" if not explicitly overridden.
            if ($subject === '') {
                $thread = $wpdb->get_row($wpdb->prepare(
                    "SELECT subject_first FROM {$wpdb->prefix}gdc_inbox_threads WHERE id = %d", $thread_id
                ), ARRAY_A);
                if ($thread && $thread['subject_first']) {
                    $subject = 'Re: ' . preg_replace('/^\s*Re:\s*/i', '', $thread['subject_first']);
                }
            }
        }
    }

    // ── HMAC-sign + POST to the MTA submitter ────────────────────────
    $secret = get_option('em_inbox_hmac_secret');
    if (! $secret) {
        return new WP_Error('em_inbox_send_no_secret', 'Inbox HMAC not seeded — visit wp-admin once to trigger activation', array('status' => 500));
    }
    $message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)),
        explode('@', $from)[1] ?? 'mail.local');

    $submit_payload = array(
        'from'        => $from,
        'to'          => $to,
        'subject'     => $subject,
        'body_plain'  => $body_plain,
        'body_html'   => $body_html,
        'headers'     => $extra_headers,
        'message_id'  => $message_id,
        'attachments' => $attachments,
    );
    $body_json = wp_json_encode($submit_payload);
    $ts  = (string) time();
    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body_json, $secret);

    $submit_url = apply_filters('em_inbox_submit_url', EM_INBOX_SUBMIT_URL_DEFAULT);
    $resp = wp_remote_post($submit_url, array(
        'headers' => array(
            'Content-Type'             => 'application/json',
            'X-EM-Submit-Timestamp'    => $ts,
            'X-EM-Submit-Signature'    => $sig,
        ),
        'body'    => $body_json,
        'timeout' => 30,
    ));
    if (is_wp_error($resp)) {
        return new WP_Error('em_inbox_send_relay_fail',
            'Relay error: ' . $resp->get_error_message(),
            array('status' => 502));
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('em_inbox_send_relay_rejected',
            'Relay returned HTTP ' . $code . ': ' . substr($body, 0, 200),
            array('status' => 502));
    }
    $relay = json_decode($body, true);

    // ── mirror into wp_gdc_inbox_raw so it shows in the sender's thread
    $raw_table = $wpdb->prefix . 'gdc_inbox_raw';
    $mirror_headers = array_merge(
        $extra_headers,
        array(
            array('name' => 'From',    'value' => $from),
            array('name' => 'To',      'value' => implode(', ', $to)),
            array('name' => 'Subject', 'value' => $subject),
        )
    );
    // For the sent-mail mirror, prefer GCS refs returned by the MTA
    // (object keys it uploaded as part of relay). Falls back to the
    // base64 we already had so attachments still render in the thread
    // even when the MTA didn't (or couldn't) upload to GCS.
    $mirror_attachments = array();
    if (is_array($relay) && ! empty($relay['attachments'])) {
        $mirror_attachments = $relay['attachments'];
    } else {
        foreach ($attachments as $a) {
            $mirror_attachments[] = array(
                'filename'     => $a['filename'],
                'content_type' => $a['content_type'],
                'size'         => $a['size'],
                'content_b64'  => $a['content_b64'],
            );
        }
    }

    $wpdb->insert($raw_table, array(
        'message_id'  => $message_id,
        'recipient'   => $from,                  // route this row into sender's inbox view
        'sender'      => $from,
        'subject'     => mb_substr($subject, 0, 998),
        'raw_headers' => wp_json_encode($mirror_headers),
        'body_plain'  => $body_plain,
        'body_html'   => $body_html,
        'attachments_json' => $mirror_attachments ? wp_json_encode($mirror_attachments) : null,
        'size_bytes'  => strlen($body_plain) + strlen($body_html) + $total_bytes,
        'received_at' => current_time('mysql', 1),
        'processed'   => 0,
        'kind'        => 'outbound',
    ), array('%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s'));
    $raw_id = (int) $wpdb->insert_id;

    if (function_exists('em_inbox_thread_one') && $raw_id) {
        em_inbox_thread_one($raw_id);
    }

    return rest_ensure_response(array(
        'ok'         => true,
        'message_id' => $message_id,
        'raw_id'     => $raw_id,
        'relay'      => $relay,
    ));
}
