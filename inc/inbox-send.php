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
    $normalize = function ($raw) {
        if (is_string($raw)) $raw = preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($raw)) return array();
        $out = array();
        foreach ($raw as $addr) {
            // Tokens from the React FormTokenField are "Name <addr>" or "addr".
            $addr = trim((string) $addr);
            if (preg_match('/<([^<>]+)>/', $addr, $m)) $addr = trim($m[1]);
            if ($addr !== '' && is_email($addr)) $out[] = strtolower($addr);
        }
        return $out;
    };
    $to  = $normalize($payload['to']  ?? array());
    $cc  = $normalize($payload['cc']  ?? array());
    $bcc = $normalize($payload['bcc'] ?? array());
    if (empty($to) && empty($cc) && empty($bcc)) {
        return new WP_Error('em_inbox_send_no_to', 'At least one recipient is required', array('status' => 400));
    }

    $subject    = (string) ($payload['subject']    ?? '');
    $body_plain = (string) ($payload['body_plain'] ?? '');
    $body_html  = (string) ($payload['body_html']  ?? '');
    if ($body_plain === '' && $body_html === '') {
        return new WP_Error('em_inbox_send_empty', 'Message body is empty', array('status' => 400));
    }
    $track_open = ! empty($payload['track_open']);
    // Slice 2y: optional undo-send window. The send endpoint inserts
    // the mirror with delivery_status='pending' + a deferred
    // next_attempt_at so the cron worker doesn't deliver for N
    // seconds. The React UI shows an Undo snackbar; if pressed within
    // the window, /cancel deletes the mirror before relay.
    $undo_seconds = isset($payload['undo_seconds']) ? (int) $payload['undo_seconds'] : 10;
    if ($undo_seconds < 0)   $undo_seconds = 0;
    if ($undo_seconds > 300) $undo_seconds = 300;

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

    // Synthesize Message-ID before relay so the mirror + the wire copy share it.
    $message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)),
        explode('@', $from)[1] ?? 'mail.local');

    // ── First-attempt relay via the shared queue helper ──────────────
    if (! function_exists('em_inbox_outq_submit_one')) {
        return new WP_Error('em_inbox_send_outq_missing', 'Outbound queue module not loaded', array('status' => 500));
    }
    // With undo window > 0 we DON'T attempt immediate relay — the cron
    // worker picks up 'pending' rows whose next_attempt_at has passed.
    // With undo=0 we attempt synchronously, same as before slice 2y.
    if ($undo_seconds > 0) {
        $result = array('ok' => false, 'http' => 0, 'error' => null, 'relay' => null);
        $relay = null;
    } else {
        $result = em_inbox_outq_submit_one(
            $from, $to, $subject, $body_plain, $body_html,
            $extra_headers, $message_id, $attachments,
            array('cc' => $cc, 'bcc' => $bcc)
        );
        $relay  = $result['relay'] ?? null;
    }

    // ── mirror into wp_gdc_inbox_raw so it shows in the sender's thread,
    // regardless of whether the relay succeeded. Status reflects what
    // happened on this attempt; the cron worker re-tries 'retrying'
    // rows on a backoff schedule.
    $raw_table = $wpdb->prefix . 'gdc_inbox_raw';
    $mirror_headers = array_merge(
        $extra_headers,
        array(
            array('name' => 'From',    'value' => $from),
            array('name' => 'To',      'value' => implode(', ', $to)),
        )
    );
    if (! empty($cc))  $mirror_headers[] = array('name' => 'Cc',  'value' => implode(', ', $cc));
    if (! empty($bcc)) $mirror_headers[] = array('name' => 'Bcc', 'value' => implode(', ', $bcc));
    $mirror_headers[]  = array('name' => 'Subject', 'value' => $subject);
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

    $now           = current_time('mysql', 1);
    if ($undo_seconds > 0) {
        // Pending until cron picks it up after the undo window.
        $delivery_data = array(
            'delivery_status'         => 'pending',
            'delivery_attempts'       => 0,
            'delivery_completed_at'   => null,
            'delivery_last_error'     => null,
            'delivery_next_attempt_at'=> gmdate('Y-m-d H:i:s', time() + $undo_seconds),
        );
    } else {
        $delivery_data = $result['ok']
            ? array(
                'delivery_status'         => 'sent',
                'delivery_attempts'       => 1,
                'delivery_completed_at'   => $now,
                'delivery_last_error'     => null,
                'delivery_next_attempt_at'=> null,
            )
            : array(
                'delivery_status'         => 'retrying',
                'delivery_attempts'       => 1,
                'delivery_completed_at'   => null,
                'delivery_last_error'     => (string) ($result['error'] ?? 'unknown'),
                'delivery_next_attempt_at'=> em_inbox_outq_next_attempt_at(1),
            );
    }

    $wpdb->insert($raw_table, array_merge(array(
        'message_id'  => $message_id,
        'recipient'   => $from,                  // route this row into sender's inbox view
        'sender'      => $from,
        'subject'     => mb_substr($subject, 0, 998),
        'raw_headers' => wp_json_encode($mirror_headers),
        'body_plain'  => $body_plain,
        'body_html'   => $body_html,
        'attachments_json' => $mirror_attachments ? wp_json_encode($mirror_attachments) : null,
        'size_bytes'  => strlen($body_plain) + strlen($body_html) + $total_bytes,
        'received_at' => $now,
        'processed'   => 0,
        'kind'        => 'outbound',
    ), $delivery_data));
    $raw_id = (int) $wpdb->insert_id;

    // If the user opted into open-tracking, AND the relay attempt
    // failed (status='retrying'), we want the cron-retry to use the
    // tracked version of the HTML. So we re-build the message via
    // em_inbox_track_inject_pixel and persist the tracked body on
    // the raw row for retry attempts to pick up. For 'sent' status
    // we already shipped the pre-pixel version; subsequent retries
    // won't happen.
    if ($track_open && function_exists('em_inbox_track_inject_pixel') && $raw_id && $body_html !== '') {
        $tracked = em_inbox_track_inject_pixel($body_html, $raw_id);
        if ($tracked !== $body_html) {
            $wpdb->update($raw_table, array('body_html' => $tracked), array('id' => $raw_id), array('%s'), array('%d'));
        }
    }

    if (function_exists('em_inbox_thread_one') && $raw_id) {
        em_inbox_thread_one($raw_id);
    }

    // If track_open was requested AND the first send succeeded, we have
    // a tradeoff: the version we already sent does NOT have the pixel
    // (because raw_id is generated only after the INSERT). For slice 2s
    // we keep it simple — track_open works for the retry path. A
    // future polish (2s.1) would either generate the raw_id pre-insert
    // (e.g. via UUID) or do a two-phase send.

    return rest_ensure_response(array(
        'ok'              => $undo_seconds > 0 ? true : $result['ok'],
        'message_id'      => $message_id,
        'raw_id'          => $raw_id,
        'delivery_status' => $delivery_data['delivery_status'],
        'delivery_error'  => $delivery_data['delivery_last_error'],
        'undo_seconds'    => $undo_seconds,
        'undo_until'      => $delivery_data['delivery_next_attempt_at'],
        'tracking'        => $track_open,
        'relay'           => $relay,
    ));
}

/* -------------------------------------------------------------------------
 * Cancel a pending outbound (slice 2y undo-send)
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/messages/(?P<raw_id>\d+)/cancel', array(
        'methods'             => array('DELETE', 'POST'),
        'callback'            => 'em_inbox_cancel_pending',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_cancel_pending(WP_REST_Request $request) {
    global $wpdb;
    $raw_id = (int) $request['raw_id'];
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_cancel_no_user', 'Login required', array('status' => 401));

    $raw = $wpdb->prefix . 'gdc_inbox_raw';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, sender, kind, delivery_status FROM $raw WHERE id = %d", $raw_id
    ), ARRAY_A);
    if (! $row) return new WP_Error('em_cancel_404', 'Message not found', array('status' => 404));

    // Only the sender (or admin) can cancel.
    $is_admin = current_user_can('manage_options');
    $meta_addr = get_user_meta($u->ID, 'em_inbox_address', true);
    $is_sender = ($u->user_email && strcasecmp($u->user_email, $row['sender']) === 0)
              || ($meta_addr   && strcasecmp($meta_addr,        $row['sender']) === 0);
    if (! ($is_admin || $is_sender)) {
        return new WP_Error('em_cancel_forbidden', 'Not authorized', array('status' => 403));
    }
    if ($row['kind'] !== 'outbound' || $row['delivery_status'] !== 'pending') {
        return new WP_Error('em_cancel_not_pending',
            'Cancel only works on outbound messages in "pending" state — this one is ' . $row['delivery_status'],
            array('status' => 409));
    }

    // Wipe the mirror row + its message/thread linkage. Threading
    // worker may have already inserted a messages row; clean that too.
    $msg_table = $wpdb->prefix . 'gdc_inbox_messages';
    $thread_id = (int) $wpdb->get_var($wpdb->prepare("SELECT thread_id FROM $msg_table WHERE raw_id = %d", $raw_id));
    $wpdb->query($wpdb->prepare("DELETE FROM $msg_table WHERE raw_id = %d", $raw_id));
    $wpdb->query($wpdb->prepare("DELETE FROM $raw WHERE id = %d", $raw_id));

    // If that was the only message in its thread, drop the thread too.
    if ($thread_id) {
        $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $msg_table WHERE thread_id = %d", $thread_id));
        if (! $remaining) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}gdc_inbox_threads WHERE id = %d", $thread_id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}gdc_inbox_participants WHERE thread_id = %d", $thread_id));
        }
    }
    return rest_ensure_response(array('ok' => true, 'raw_id' => $raw_id, 'cancelled' => true));
}
