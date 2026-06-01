<?php
/**
 * Member Inbox: GCS attachment retrieval.
 *
 * Serves an inbox attachment via a server-side proxy from
 * gs://gend-email-attachments. Authenticates using the GCE metadata
 * server (Workload Identity → email-gcs-reader-sa@gend-me, granted
 * roles/storage.objectViewer on the bucket).
 *
 * Endpoint:
 *   GET /wp-json/em/v1/inbox/message/{msg_id}/attachment/{idx}
 *
 * Access policy:
 *   - administrators (manage_options) always pass
 *   - otherwise the current user's email must match the message's
 *     recipient (case-insensitive). Unauthenticated requests are 401.
 *
 * The token comes from 169.254.169.254 directly (NOT the
 * metadata.google.internal hostname, which doesn't resolve via the
 * cluster's kube-dns — see [[project-gke-dns-quirk]]).
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_METADATA_IP',  '169.254.169.254');
define('EM_INBOX_TOKEN_TTL_BUFFER', 60);  // refresh if <60s remaining

/* -------------------------------------------------------------------------
 * REST route
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/message/(?P<msg_id>\d+)/attachment/(?P<idx>\d+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_serve_attachment',
        'permission_callback' => 'em_inbox_attachment_permission',
        'args' => array(
            'msg_id' => array('type' => 'integer', 'required' => true),
            'idx'    => array('type' => 'integer', 'required' => true),
        ),
    ));
});

/**
 * Permission gate. Defer the actual recipient-matching to the handler
 * since we need the message row to know the recipient.
 */
function em_inbox_attachment_permission(WP_REST_Request $request) {
    return is_user_logged_in();
}

function em_inbox_serve_attachment(WP_REST_Request $request) {
    global $wpdb;
    $msg_id = (int) $request['msg_id'];
    $idx    = (int) $request['idx'];

    $table = $wpdb->prefix . 'gdc_inbox_messages';
    $msg = $wpdb->get_row($wpdb->prepare(
        "SELECT id, recipient, subject, body_plain FROM $table WHERE id = %d",
        $msg_id
    ), ARRAY_A);

    // Fallback to raw rows for messages that haven't been threaded yet.
    if (! $msg) {
        $raw_table = $wpdb->prefix . 'gdc_inbox_raw';
        $raw_msg = $wpdb->get_row($wpdb->prepare(
            "SELECT id, recipient, attachments_json FROM $raw_table WHERE id = %d",
            $msg_id
        ), ARRAY_A);
        if (! $raw_msg) {
            return new WP_Error('em_inbox_msg_404', 'Message not found', array('status' => 404));
        }
        $recipient = $raw_msg['recipient'];
        $attachments_json = $raw_msg['attachments_json'];
    } else {
        $recipient = $msg['recipient'];
        // The threaded message row doesn't carry attachments_json itself;
        // pull from the corresponding raw row.
        $raw_table = $wpdb->prefix . 'gdc_inbox_raw';
        $raw = $wpdb->get_row($wpdb->prepare(
            "SELECT r.attachments_json FROM $raw_table r
             JOIN {$wpdb->prefix}gdc_inbox_messages m ON m.raw_id = r.id
             WHERE m.id = %d",
            $msg_id
        ), ARRAY_A);
        $attachments_json = $raw ? $raw['attachments_json'] : null;
    }

    // Access check — admin OR owner (by em_inbox_address user_meta)
    // OR legacy email-match fallback. Routed through the shared helper
    // so all surfaces share one policy.
    if (function_exists('em_inbox_current_user_can_read_address')) {
        if (! em_inbox_current_user_can_read_address($recipient)) {
            return new WP_Error('em_inbox_forbidden', 'Not authorized for this inbox', array('status' => 403));
        }
    } else {
        // 2b.2 fallback if user-provisioning module not loaded for any reason.
        $user = wp_get_current_user();
        if (! current_user_can('manage_options') &&
            !($user && $user->user_email && strcasecmp($user->user_email, $recipient) === 0)) {
            return new WP_Error('em_inbox_forbidden', 'Not authorized for this inbox', array('status' => 403));
        }
    }

    $attachments = json_decode((string) $attachments_json, true);
    if (! is_array($attachments) || ! isset($attachments[$idx])) {
        return new WP_Error('em_inbox_attachment_404', 'Attachment index out of range', array('status' => 404));
    }
    $att = $attachments[$idx];

    // Slice-1 fallback: inline base64 attachment. Decode + stream directly,
    // no GCS round-trip.
    if (! empty($att['content_b64'])) {
        return em_inbox_stream_inline($att);
    }

    if (empty($att['gcs_bucket']) || empty($att['gcs_object'])) {
        return new WP_Error('em_inbox_attachment_missing', 'Attachment has no retrievable source', array('status' => 500));
    }

    return em_inbox_stream_from_gcs($att);
}

/* -------------------------------------------------------------------------
 * Streaming
 * ------------------------------------------------------------------------- */

function em_inbox_stream_inline($att) {
    $bytes = base64_decode($att['content_b64'], true);
    if ($bytes === false) {
        return new WP_Error('em_inbox_b64_decode', 'inline attachment is not valid base64', array('status' => 500));
    }
    em_inbox_emit_headers($att, strlen($bytes));
    echo $bytes;
    exit;
}

function em_inbox_stream_from_gcs($att) {
    $token = em_inbox_get_gcs_access_token();
    if (! $token) {
        return new WP_Error('em_inbox_no_token', 'Could not obtain GCS access token', array('status' => 500));
    }

    $object_url = sprintf(
        'https://storage.googleapis.com/storage/v1/b/%s/o/%s?alt=media',
        rawurlencode($att['gcs_bucket']),
        rawurlencode($att['gcs_object'])  // rawurlencode escapes '/' too — required by the JSON API
    );

    $ch = curl_init($object_url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => false,        // stream to stdout
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
            echo $data;
            return strlen($data);
        },
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use ($att) {
            // Pass through Content-Length from GCS; everything else we set
            // ourselves (Content-Type from our row, since the GCS object
            // metadata may have been overridden differently).
            if (stripos($header, 'Content-Length:') === 0) {
                header(trim($header));
            }
            return strlen($header);
        },
    ));

    em_inbox_emit_headers($att, null);  // Content-Length comes from curl header callback above
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        // We've already started emitting headers/body — best we can do is
        // log and exit. The client sees a truncated response.
        error_log("em_inbox: GCS GET returned HTTP $code for {$att['gcs_object']}");
    }
    exit;
}

function em_inbox_emit_headers($att, $content_length) {
    $filename = isset($att['filename']) && $att['filename'] !== '' ? $att['filename'] : 'attachment';
    $ctype    = isset($att['content_type']) && $att['content_type'] !== '' ? $att['content_type'] : 'application/octet-stream';
    header('Content-Type: ' . $ctype);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: private, max-age=300');
    if ($content_length !== null) {
        header('Content-Length: ' . (int) $content_length);
    }
    // Prevent WP from emitting its own JSON envelope.
    if (function_exists('nocache_headers')) nocache_headers();
}

/* -------------------------------------------------------------------------
 * Token cache
 * ------------------------------------------------------------------------- */

/**
 * Fetch (and cache) an access token for the SA bound to the pod's KSA via
 * Workload Identity. Cached in a transient until ~60s before expiry to
 * avoid hammering the metadata server.
 */
function em_inbox_get_gcs_access_token() {
    $cached = get_transient('em_inbox_gcs_token');
    if (is_array($cached) && isset($cached['access_token'], $cached['expires_at'])
        && $cached['expires_at'] > time() + EM_INBOX_TOKEN_TTL_BUFFER) {
        return $cached['access_token'];
    }

    $ch = curl_init('http://' . EM_INBOX_METADATA_IP . '/computeMetadata/v1/instance/service-accounts/default/token');
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => array('Metadata-Flavor: Google'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || ! $body) return null;
    $data = json_decode($body, true);
    if (! is_array($data) || empty($data['access_token']) || empty($data['expires_in'])) return null;

    $cache = array(
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int) $data['expires_in'],
    );
    set_transient('em_inbox_gcs_token', $cache, (int) $data['expires_in'] - EM_INBOX_TOKEN_TTL_BUFFER);
    return $cache['access_token'];
}
