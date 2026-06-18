<?php
/**
 * Member Inbox: outbound retry queue (slice 2f.2).
 *
 * Adds delivery-state columns to wp_gdc_inbox_raw so a failed relay
 * doesn't drop the message:
 *
 *   delivery_status        pending|sent|failed|retrying
 *   delivery_attempts      int
 *   delivery_last_error    text
 *   delivery_next_attempt_at  datetime — when the cron worker should
 *                             try again
 *   delivery_completed_at  datetime — when status flipped to 'sent'
 *
 * The send endpoint now ALWAYS inserts the mirror row (with the
 * appropriate initial state), so the user sees their sent message
 * in their thread regardless of relay outcome. A cron worker retries
 * 'retrying' rows on an exponential backoff (2m / 5m / 15m / 30m /
 * 1h / 3h / 12h, capped at 8 attempts → 'failed').
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_OUTQ_DB_VERSION', '1.1.0');  // bumped for 'scheduled' enum (slice 2bb)
define('EM_INBOX_OUTQ_MAX_ATTEMPTS', 8);

/* -------------------------------------------------------------------------
 * Schema migration
 * ------------------------------------------------------------------------- */

function em_inbox_outq_maybe_migrate() {
    if (get_option('em_inbox_outq_db_version') === EM_INBOX_OUTQ_DB_VERSION) return;
    global $wpdb;
    $raw = $wpdb->prefix . 'gdc_inbox_raw';

    $desc = $wpdb->get_results("DESCRIBE $raw");
    $cols = array_column($desc, 'Field');
    $needed = array(
        'delivery_status'         => "ENUM('pending','scheduled','sent','failed','retrying') NOT NULL DEFAULT 'sent'",
        'delivery_attempts'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'delivery_last_error'     => 'TEXT NULL',
        'delivery_next_attempt_at'=> 'DATETIME NULL',
        'delivery_completed_at'   => 'DATETIME NULL',
    );
    $adds = array();
    foreach ($needed as $col => $type) {
        if (! in_array($col, $cols, true)) {
            $adds[] = "ADD COLUMN $col $type";
        }
    }
    if ($adds) {
        $wpdb->query("ALTER TABLE $raw " . implode(', ', $adds)
            . ", ADD KEY idx_delivery_retry (delivery_status, delivery_next_attempt_at)");
    }
    // Slice 2bb: column existed but enum needs 'scheduled' added.
    foreach ($desc as $col) {
        if ($col->Field === 'delivery_status' && stripos((string) $col->Type, "'scheduled'") === false) {
            $wpdb->query("ALTER TABLE $raw MODIFY COLUMN delivery_status ENUM('pending','scheduled','sent','failed','retrying') NOT NULL DEFAULT 'sent'");
            break;
        }
    }
    update_option('em_inbox_outq_db_version', EM_INBOX_OUTQ_DB_VERSION);
}
add_action('admin_init',    'em_inbox_outq_maybe_migrate');
add_action('rest_api_init', 'em_inbox_outq_maybe_migrate');

/* -------------------------------------------------------------------------
 * Backoff schedule
 * ------------------------------------------------------------------------- */

/**
 * Return the next-attempt timestamp for an outbound row that just
 * failed its N-th attempt (1-indexed). Caller is responsible for
 * checking attempts < EM_INBOX_OUTQ_MAX_ATTEMPTS first.
 */
function em_inbox_outq_next_attempt_at($attempts) {
    // 2m, 5m, 15m, 30m, 1h, 3h, 12h, then 12h thereafter.
    $minutes = array(2, 5, 15, 30, 60, 180, 720);
    $idx     = max(0, min((int) $attempts - 1, count($minutes) - 1));
    return gmdate('Y-m-d H:i:s', time() + 60 * $minutes[$idx]);
}

/* -------------------------------------------------------------------------
 * Submission helper — called by both inbox-send.php and the cron worker.
 *
 * Returns ['ok' => bool, 'http' => int, 'error' => string|null].
 * ------------------------------------------------------------------------- */

function em_inbox_outq_submit_one($from, $to, $subject, $body_plain, $body_html,
                                  $headers, $message_id, $attachments, $extras = array()) {
    $cc  = is_array($extras['cc']  ?? null) ? $extras['cc']  : array();
    $bcc = is_array($extras['bcc'] ?? null) ? $extras['bcc'] : array();

    // ── Mailgun transport (MAIL-04) ──────────────────────────────────────
    // Preferred outbound rail when the Mailgun connection (gend-mailgun.php
    // mu-plugin) is configured on this pod. Replaces ONLY the relay leg; the
    // wp_gdc_inbox_raw sent-mirror + threading live in the caller
    // (em_inbox_send_as) and are untouched. Returns the SAME
    // ['ok','http','error','relay'] contract as the email-mta branch below,
    // so the caller's status logic and the cron retry worker work identically.
    //
    // The whole branch is gated on function_exists('gend_mailgun_enabled')
    // && gend_mailgun_enabled() — Phase 32/35 lesson: php -l cannot catch an
    // undefined gend_mailgun_* symbol, so every getter call is reached only
    // after the function_exists gate confirms the mu-plugin is present.
    // When Mailgun is NOT configured (empty Secret) the gate is false and we
    // fall through to the existing email-mta path (graceful degrade).
    if (function_exists('gend_mailgun_enabled') && gend_mailgun_enabled()) {
        return em_inbox_outq_submit_via_mailgun(
            $from, $to, $subject, $body_plain, $body_html,
            $headers, $message_id, $attachments, $cc, $bcc
        );
    }

    // ── email-mta relay (fallback when Mailgun is not configured) ─────────
    $secret = get_option('em_inbox_hmac_secret');
    if (! $secret) return array('ok' => false, 'http' => 500, 'error' => 'HMAC secret missing');

    $body_json = wp_json_encode(array(
        'from'        => $from,
        'to'          => $to,
        'cc'          => $cc,
        'bcc'         => $bcc,
        'subject'     => $subject,
        'body_plain'  => $body_plain,
        'body_html'   => $body_html,
        'headers'     => $headers,
        'message_id'  => $message_id,
        'attachments' => $attachments,
    ));
    $ts  = (string) time();
    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body_json, $secret);

    $url = apply_filters('em_inbox_submit_url', defined('EM_INBOX_SUBMIT_URL_DEFAULT') ? EM_INBOX_SUBMIT_URL_DEFAULT : 'http://email-mta-submit:8080/submit');
    $resp = wp_remote_post($url, array(
        'headers' => array(
            'Content-Type'             => 'application/json',
            'X-EM-Submit-Timestamp'    => $ts,
            'X-EM-Submit-Signature'    => $sig,
        ),
        'body'    => $body_json,
        'timeout' => 30,
    ));
    if (is_wp_error($resp)) {
        return array('ok' => false, 'http' => 0, 'error' => $resp->get_error_message(), 'relay' => null);
    }
    $code  = wp_remote_retrieve_response_code($resp);
    $rbody = wp_remote_retrieve_body($resp);
    $relay = json_decode($rbody, true);
    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'http' => $code, 'error' => null, 'relay' => $relay);
    }
    $err = is_array($relay) && ! empty($relay['error']) ? $relay['error'] : ('HTTP ' . $code);
    return array('ok' => false, 'http' => $code, 'error' => substr($err, 0, 500), 'relay' => $relay);
}

/* -------------------------------------------------------------------------
 * Mailgun transport (MAIL-04)
 *
 * POSTs the inbox payload directly to Mailgun's /v3/<domain>/messages API
 * using the existing gend-mailgun.php config getters. Reuses the same POST
 * recipe gend-mailgun.php's wp_mail() override proved in production
 * (Basic api:<key> auth, application/x-www-form-urlencoded, repeated
 * to=/cc=/bcc= fields — Mailgun ignores foo[0]= scalar-array serialization)
 * but builds the body from the inbox locals so the agent From, the
 * synthesized Message-Id, and threading headers are preserved.
 *
 * Callers MUST gate on gend_mailgun_enabled() before invoking this (the
 * getters are only safe to call once the mu-plugin is confirmed present).
 *
 * Returns the SAME ['ok','http','error','relay'] contract as the email-mta
 * branch. 'relay' is the decoded Mailgun JSON ({id,message} on success) so
 * the caller's mirror + UAT can read the Mailgun message id.
 *
 * DELIVERY NOTE: Mailgun only accepts a from= whose domain is a verified
 * Mailgun sending domain. When the agent address domain (em_inbox_address,
 * e.g. agent-<slug>@mail-test.gend.me) is NOT the configured MAILGUN_DOMAIN
 * (or a subdomain of it), we send ON the Mailgun domain and set
 * h:Reply-To:<agent address> so replies still reach the agent mailbox.
 * True From=agent-address requires the agent domain be Mailgun-verified —
 * an infra action (deferred); once verified, branch (i) auto-upgrades with
 * zero code change.
 * ------------------------------------------------------------------------- */

function em_inbox_outq_submit_via_mailgun($from, $to, $subject, $body_plain, $body_html,
                                          $headers, $message_id, $attachments, $cc, $bcc) {
    $api_key  = gend_mailgun_api_key();
    $endpoint = gend_mailgun_endpoint();
    if ($api_key === '' || $endpoint === '') {
        // gend_mailgun_enabled() should have prevented this, but never fatal.
        return array('ok' => false, 'http' => 500, 'error' => 'Mailgun not configured', 'relay' => null);
    }

    // ── Conditional From (preserve agent identity as far as Mailgun allows) ─
    $agent_addr   = (string) $from;                           // agent-<slug>@<agent domain>
    $agent_domain = '';
    $at = strrchr($agent_addr, '@');
    if ($at !== false) $agent_domain = substr($at, 1);
    $mg_domain = gend_mailgun_domain();

    // Authorized when the From domain IS the Mailgun domain, or a subdomain of it.
    $from_is_authorized = ($agent_domain !== '' && $mg_domain !== '' && (
        strcasecmp($agent_domain, $mg_domain) === 0
        || (bool) preg_match('/(^|\.)' . preg_quote($mg_domain, '/') . '$/i', $agent_domain)
    ));
    // Escape hatch: list additional verified domains via filter (e.g. once
    // mail-test.gend.me is verified in Mailgun, return true here).
    $from_is_authorized = (bool) apply_filters('em_inbox_mailgun_from_authorized', $from_is_authorized, $agent_addr, $mg_domain);

    // Display name: best-effort from the agent's WP user (login = local part);
    // avoid get_user_by('email') due to the vendor-app-manager fix_user_query gotcha.
    $display_name = '';
    $local        = ($at !== false) ? strstr($agent_addr, '@', true) : $agent_addr;
    if ($local !== '' && function_exists('get_user_by')) {
        $u = get_user_by('login', $local);
        if ($u && ! empty($u->display_name)) $display_name = (string) $u->display_name;
    }

    if ($from_is_authorized) {
        // (i) Best case — send AS the agent address (true identity, DKIM-aligned).
        $mail_from = ($display_name !== '') ? sprintf('%s <%s>', $display_name, $agent_addr) : $agent_addr;
        $reply_to  = $agent_addr;
    } else {
        // (ii) Send on the Mailgun domain; carry identity via display name +
        //      Reply-To so replies land back in the agent's mailbox.
        $name      = ($display_name !== '') ? $display_name : ($local !== '' ? $local : 'GenD');
        $mail_from = sprintf('%s <no-reply@%s>', $name, $mg_domain);
        $reply_to  = $agent_addr;
    }

    // ── Build the urlencoded form body (repeated to=/cc=/bcc= fields) ──────
    $to  = is_array($to)  ? $to  : (array) $to;
    $cc  = is_array($cc)  ? $cc  : array();
    $bcc = is_array($bcc) ? $bcc : array();

    $form = array();
    $form[] = 'from=' . urlencode($mail_from);
    foreach ($to  as $addr) { $addr = trim((string) $addr); if ($addr !== '') $form[] = 'to='  . urlencode($addr); }
    foreach ($cc  as $addr) { $addr = trim((string) $addr); if ($addr !== '') $form[] = 'cc='  . urlencode($addr); }
    foreach ($bcc as $addr) { $addr = trim((string) $addr); if ($addr !== '') $form[] = 'bcc=' . urlencode($addr); }
    $form[] = 'subject=' . urlencode((string) $subject);

    // Body: send html AND text when both exist (Mailgun accepts both); fall
    // back to whichever is present.
    if ((string) $body_html !== '')  $form[] = 'html=' . urlencode((string) $body_html);
    if ((string) $body_plain !== '') $form[] = 'text=' . urlencode((string) $body_plain);
    if ((string) $body_html === '' && (string) $body_plain === '') {
        $form[] = 'text=' . urlencode('');
    }

    // Preserve the synthesized Message-Id + threading so the wire copy, the
    // mirror, and the recipient's threading all agree (Mailgun overrides
    // Message-Id unless h:Message-Id is passed).
    if ((string) $message_id !== '') {
        $form[] = 'h:Message-Id=' . urlencode((string) $message_id);
    }
    if ($reply_to !== '') {
        $form[] = 'h:Reply-To=' . urlencode($reply_to);
    }

    // Re-emit threading / audit headers from the {name,value} header array.
    // Skip envelope headers that become first-class Mailgun fields.
    $skip = array('from', 'to', 'subject', 'cc', 'bcc', 'reply-to', 'message-id');
    if (is_array($headers)) {
        foreach ($headers as $h) {
            if (! isset($h['name'], $h['value'])) continue;
            $name = (string) $h['name'];
            $key  = strtolower($name);
            if (in_array($key, $skip, true)) continue;
            // Pass threading + audit headers through (In-Reply-To, References,
            // X-EM-Acted-By, etc.) as Mailgun custom headers.
            $form[] = 'h:' . $name . '=' . urlencode((string) $h['value']);
        }
    }

    // Attachments: v8.1 sends body only — match gend-mailgun.php's documented
    // limitation (error_log + drop). Never fail the send because of them.
    if (! empty($attachments) && is_array($attachments)) {
        error_log('[em-inbox-mailgun] attachments not yet supported; dropped ' . count($attachments) . ' file(s).');
    }

    $resp = wp_remote_post($endpoint, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode('api:' . $api_key),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ),
        'body'    => implode('&', $form),
    ));

    if (is_wp_error($resp)) {
        return array('ok' => false, 'http' => 0, 'error' => substr($resp->get_error_message(), 0, 500), 'relay' => null);
    }
    $code  = (int) wp_remote_retrieve_response_code($resp);
    $rbody = (string) wp_remote_retrieve_body($resp);
    $relay = json_decode($rbody, true);
    if ($code >= 200 && $code < 300) {
        return array('ok' => true, 'http' => $code, 'error' => null, 'relay' => $relay);
    }
    $err = is_array($relay) && ! empty($relay['message'])
        ? $relay['message']
        : ('Mailgun HTTP ' . $code . ($rbody !== '' ? ': ' . $rbody : ''));
    return array('ok' => false, 'http' => $code, 'error' => substr($err, 0, 500), 'relay' => $relay);
}

/* -------------------------------------------------------------------------
 * Cron worker — drains the retry queue
 * ------------------------------------------------------------------------- */

add_action('cron_schedules', function ($schedules) {
    if (! isset($schedules['em_inbox_minute'])) {
        $schedules['em_inbox_minute'] = array('interval' => 60, 'display' => 'EM Inbox — every minute');
    }
    return $schedules;
});
add_action('init', function () {
    if (! wp_next_scheduled('em_inbox_outq_cron')) {
        wp_schedule_event(time() + 60, 'em_inbox_minute', 'em_inbox_outq_cron');
    }
});
add_action('em_inbox_outq_cron', 'em_inbox_outq_drain');

function em_inbox_outq_drain($limit = 10) {
    global $wpdb;
    $raw = $wpdb->prefix . 'gdc_inbox_raw';
    // Slice 2rr: track when drain last ran + how many rows it touched
    // so the ops health card can surface drain freshness. Always write
    // the timestamp even on no-op runs (proves cron is firing); the
    // counter is cumulative across drains.
    update_option('em_inbox_outq_drain_last_at', current_time('mysql', 1), false);

    // Picks up 'pending' (post-undo-window first-attempts, slice 2y),
    // 'scheduled' (user-deferred send, slice 2bb), and 'retrying' (failed
    // deliveries waiting on backoff, slice 2f.2).
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $raw
         WHERE kind = 'outbound'
           AND delivery_status IN ('pending','scheduled','retrying')
           AND (delivery_next_attempt_at IS NULL OR delivery_next_attempt_at <= UTC_TIMESTAMP())
         ORDER BY delivery_next_attempt_at ASC, id ASC
         LIMIT %d", $limit
    ), ARRAY_A);

    $now = current_time('mysql', 1);
    foreach ($rows as $row) {
        $headers     = json_decode((string) $row['raw_headers'], true);
        $attachments = json_decode((string) $row['attachments_json'], true);
        if (! is_array($headers))     $headers     = array();
        if (! is_array($attachments)) $attachments = array();

        $to_addrs = array();
        foreach ($headers as $h) {
            if (isset($h['name']) && strcasecmp($h['name'], 'To') === 0 && isset($h['value'])) {
                foreach (preg_split('/[,;\s]+/', (string) $h['value'], -1, PREG_SPLIT_NO_EMPTY) as $a) {
                    if (is_email($a)) $to_addrs[] = strtolower($a);
                }
            }
        }
        if (empty($to_addrs)) {
            // Can't retry without a recipient — give up.
            $wpdb->update($raw, array(
                'delivery_status'     => 'failed',
                'delivery_last_error' => 'No To: header on retry',
            ), array('id' => (int) $row['id']), array('%s', '%s'), array('%d'));
            continue;
        }

        $result = em_inbox_outq_submit_one(
            $row['sender'], $to_addrs, $row['subject'],
            $row['body_plain'], $row['body_html'],
            $headers, $row['message_id'], $attachments
        );

        $attempts = (int) $row['delivery_attempts'] + 1;
        if ($result['ok']) {
            $wpdb->update($raw, array(
                'delivery_status'       => 'sent',
                'delivery_attempts'     => $attempts,
                'delivery_completed_at' => $now,
                'delivery_last_error'   => null,
                'delivery_next_attempt_at' => null,
            ), array('id' => (int) $row['id']), array('%s','%d','%s','%s','%s'), array('%d'));
        } elseif ($attempts >= EM_INBOX_OUTQ_MAX_ATTEMPTS) {
            $wpdb->update($raw, array(
                'delivery_status'     => 'failed',
                'delivery_attempts'   => $attempts,
                'delivery_last_error' => (string) $result['error'],
                'delivery_next_attempt_at' => null,
            ), array('id' => (int) $row['id']), array('%s','%d','%s','%s'), array('%d'));
        } else {
            $wpdb->update($raw, array(
                'delivery_status'        => 'retrying',
                'delivery_attempts'      => $attempts,
                'delivery_last_error'    => (string) $result['error'],
                'delivery_next_attempt_at' => em_inbox_outq_next_attempt_at($attempts),
            ), array('id' => (int) $row['id']), array('%s','%d','%s','%s'), array('%d'));
        }
    }
    $n = count($rows);
    if ($n > 0) {
        $prev = (int) get_option('em_inbox_outq_drain_processed_total', 0);
        update_option('em_inbox_outq_drain_processed_total', $prev + $n, false);
        update_option('em_inbox_outq_drain_last_processed', $n, false);
    }
    return $n;
}
