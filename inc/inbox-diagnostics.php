<?php
/**
 * Member Inbox: admin-only diagnostics page (slice 2m).
 *
 * Server-rendered wp-admin page showing operational health for the
 * inbox subsystem — MTA reachability, inbound/outbound volume + state,
 * retry queue depth, cron schedule, DKIM/SPF/DMARC DNS sanity. No
 * React on this page; one HTTP request to the WP server gives the
 * full picture.
 *
 * Mounts under the same parent menu as the user-facing inbox page.
 * Capability: manage_options (admin only).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    $parent_slug = defined('GS_VERSION') ? 'gs-app' : (defined('GDC_VERSION') ? 'gdc-app' : 'email-manager');
    add_submenu_page(
        $parent_slug,
        __('Inbox Diagnostics', 'email-manager'),
        __('Inbox Diagnostics', 'email-manager'),
        'manage_options',
        'email-manager-inbox-diag',
        'em_inbox_diag_render'
    );
}, 1300);

function em_inbox_diag_render() {
    $data = em_inbox_diag_gather();
    ?>
    <div class="wrap em-inbox-diag-wrap">
      <h1>Inbox Diagnostics</h1>
      <p class="em-inbox-diag-meta">Generated <?php echo esc_html($data['generated_at']); ?> ·
        <a href="<?php echo esc_url(add_query_arg('em_diag_refresh', '1')); ?>">refresh</a></p>

      <div class="em-diag-grid">
        <?php em_inbox_diag_card('MTA submitter',          em_inbox_diag_mta($data['mta'])); ?>
        <?php em_inbox_diag_card('Config',                 em_inbox_diag_config($data['config'])); ?>
        <?php em_inbox_diag_card('Inbound (last 24h)',     em_inbox_diag_inbound($data['inbound'])); ?>
        <?php em_inbox_diag_card('Outbound (last 24h)',    em_inbox_diag_outbound($data['outbound'])); ?>
        <?php em_inbox_diag_card('Retry queue',            em_inbox_diag_queue($data['queue'])); ?>
        <?php em_inbox_diag_card('Drain stats',            em_inbox_diag_drain($data['drain'])); ?>
        <?php em_inbox_diag_card('Cron schedule',          em_inbox_diag_cron($data['cron'])); ?>
        <?php em_inbox_diag_card('Vacation responder',     em_inbox_diag_vacation($data['vacation'])); ?>
        <?php em_inbox_diag_card('Filter engine',          em_inbox_diag_filters($data['filters'])); ?>
        <?php em_inbox_diag_card('User state',             em_inbox_diag_userstate($data['userstate'])); ?>
        <?php em_inbox_diag_card('DNS sanity',             em_inbox_diag_dns($data['dns'])); ?>
        <?php em_inbox_diag_card('Recent inbound (20)',    em_inbox_diag_recent($data['recent_inbound'])); ?>
        <?php em_inbox_diag_card('Recent outbound (20)',   em_inbox_diag_recent($data['recent_outbound'])); ?>
      </div>
    </div>
    <style>
      .em-diag-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; }
      .em-diag-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; }
      .em-diag-card h2 { margin: 0 0 8px; font-size: 14px; color: #1d2327; }
      .em-diag-card table { width: 100%; border-collapse: collapse; font-size: 12px; }
      .em-diag-card td { padding: 3px 0; vertical-align: top; }
      .em-diag-card td:first-child { color: #50575e; padding-right: 12px; white-space: nowrap; }
      .em-diag-ok      { color: #00692b; font-weight: 600; }
      .em-diag-warn    { color: #8a5a00; font-weight: 600; }
      .em-diag-err     { color: #b32d2e; font-weight: 600; }
      .em-diag-mono    { font-family: Consolas, Monaco, monospace; font-size: 11px; word-break: break-all; }
      .em-inbox-diag-meta { color: #646970; font-size: 12px; }
    </style>
    <?php
}

function em_inbox_diag_card($title, $rows_html) {
    echo '<div class="em-diag-card"><h2>' . esc_html($title) . '</h2><table>' . $rows_html . '</table></div>';
}

function em_inbox_diag_row($k, $v_html, $level = '') {
    $cls = $level ? ' class="em-diag-' . esc_attr($level) . '"' : '';
    return '<tr><td>' . esc_html($k) . '</td><td' . $cls . '>' . $v_html . '</td></tr>';
}

/* -------------------------------------------------------------------------
 * Data gathering
 * ------------------------------------------------------------------------- */

function em_inbox_diag_gather() {
    global $wpdb;
    $raw = $wpdb->prefix . 'gdc_inbox_raw';

    // MTA reachability
    $submit_url = apply_filters('em_inbox_submit_url', defined('EM_INBOX_SUBMIT_URL_DEFAULT') ? EM_INBOX_SUBMIT_URL_DEFAULT : 'http://email-mta-submit:8080/submit');
    $start  = microtime(true);
    $resp   = wp_remote_get($submit_url, array('timeout' => 5));
    $ms     = (int) round((microtime(true) - $start) * 1000);
    $mta = array('url' => $submit_url, 'latency_ms' => $ms, 'reachable' => false, 'detail' => '');
    if (is_wp_error($resp)) {
        $mta['detail'] = 'wp_remote_get error: ' . $resp->get_error_message();
    } else {
        $code = wp_remote_retrieve_response_code($resp);
        // GET / returns 404 (only POST /submit is handled) — that 404
        // proves the listener IS up; treat 404 as "reachable".
        $mta['reachable'] = ($code === 404 || ($code >= 200 && $code < 500));
        $mta['detail']    = 'HTTP ' . $code;
    }

    // Config
    $domain = (string) get_option('em_inbox_default_domain', '');
    $secret = (string) get_option('em_inbox_hmac_secret', '');
    $config = array(
        'default_domain'    => $domain,
        'hmac_present'      => $secret !== '',
        'hmac_length'       => strlen($secret),
        'hmac_fingerprint'  => $secret ? substr(hash('sha256', $secret), 0, 16) : null,
        'registry_enabled'  => function_exists('em_inbox_registry_enabled') && em_inbox_registry_enabled(),
    );

    // Volume counts (last 24h)
    $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
    $inbound = array(
        'total'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $raw WHERE kind = 'inbound'"),
        'last_24h' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $raw WHERE kind = 'inbound' AND received_at >= %s", $cutoff)),
        'top_senders' => $wpdb->get_results($wpdb->prepare(
            "SELECT sender, COUNT(*) AS n FROM $raw WHERE kind = 'inbound' AND received_at >= %s GROUP BY sender ORDER BY n DESC LIMIT 5",
            $cutoff
        ), ARRAY_A),
    );
    $outbound = array(
        'total'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $raw WHERE kind = 'outbound'"),
        'last_24h' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $raw WHERE kind = 'outbound' AND received_at >= %s", $cutoff)),
        'by_status'=> $wpdb->get_results("SELECT delivery_status AS s, COUNT(*) AS n FROM $raw WHERE kind = 'outbound' GROUP BY delivery_status", ARRAY_A),
    );

    // Retry queue
    $queue = array(
        'retrying' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $raw WHERE kind = 'outbound' AND delivery_status = 'retrying'"),
        'failed'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM $raw WHERE kind = 'outbound' AND delivery_status = 'failed'"),
        'next_due' => $wpdb->get_var("SELECT MIN(delivery_next_attempt_at) FROM $raw WHERE kind = 'outbound' AND delivery_status = 'retrying'"),
    );

    // Cron
    $cron = array(
        'thread_cron_next'  => wp_next_scheduled('em_inbox_thread_cron'),
        'outq_cron_next'    => wp_next_scheduled('em_inbox_outq_cron'),
    );

    // Recent rows
    $recent_in  = $wpdb->get_results("SELECT id, recipient, sender, LEFT(subject,80) AS subject, received_at FROM $raw WHERE kind = 'inbound'  ORDER BY id DESC LIMIT 20", ARRAY_A);
    $recent_out = $wpdb->get_results("SELECT id, recipient, sender, LEFT(subject,80) AS subject, delivery_status, delivery_attempts, received_at FROM $raw WHERE kind = 'outbound' ORDER BY id DESC LIMIT 20", ARRAY_A);

    // DNS sanity
    $dns = em_inbox_diag_dns_check($domain);

    // Slice 2rr: drain freshness
    $drain = array(
        'last_at'         => get_option('em_inbox_outq_drain_last_at',         null),
        'last_processed'  => (int) get_option('em_inbox_outq_drain_last_processed',  0),
        'processed_total' => (int) get_option('em_inbox_outq_drain_processed_total', 0),
    );

    // Slice 2rr: vacation responder activity (table is optional - guard)
    $vac_table = $wpdb->prefix . 'gdc_inbox_vacation_log';
    $vacation = array('enabled' => false, 'fired_24h' => 0, 'fired_total' => 0, 'last_at' => null);
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $vac_table)) === $vac_table) {
        $vacation['fired_24h']   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $vac_table WHERE sent_at >= %s", $cutoff));
        $vacation['fired_total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $vac_table");
        $vacation['last_at']     = $wpdb->get_var("SELECT MAX(sent_at) FROM $vac_table");
    }
    // Count how many users currently have vacation enabled via user_meta.
    $vacation['enabled_users'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'em_inbox_vacation' AND meta_value LIKE '%\"enabled\";b:1%'"
    );

    // Slice 2rr: filter engine activity (guard — table is per slice 2cc).
    $filt_table = $wpdb->prefix . 'gdc_inbox_filters';
    $filters = array('total' => 0, 'enabled' => 0, 'matches_total' => 0, 'matches_24h' => 0, 'top' => array());
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $filt_table)) === $filt_table) {
        $filters['total']         = (int) $wpdb->get_var("SELECT COUNT(*) FROM $filt_table");
        $filters['enabled']       = (int) $wpdb->get_var("SELECT COUNT(*) FROM $filt_table WHERE enabled = 1");
        $filters['matches_total'] = (int) $wpdb->get_var("SELECT COALESCE(SUM(match_count), 0) FROM $filt_table");
        $filters['matches_24h']   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $filt_table WHERE last_matched_at >= %s", $cutoff));
        $filters['top']           = $wpdb->get_results("SELECT name, match_count FROM $filt_table WHERE match_count > 0 ORDER BY match_count DESC LIMIT 5", ARRAY_A);
    }

    // Slice 2rr: snooze + drafts + grants (small counters; tables optional).
    $part_table   = $wpdb->prefix . 'gdc_inbox_participants';
    $drafts_table = $wpdb->prefix . 'gdc_inbox_drafts';
    $grants_table = $wpdb->prefix . 'gdc_inbox_grants';
    $userstate = array(
        'snoozed_active'        => 0,
        'snoozed_resurface_next'=> null,
        'drafts_total'          => 0,
        'grants_active'         => 0,
    );
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $part_table)) === $part_table) {
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $part_table");
        if (in_array('snoozed_until', $cols, true)) {
            $userstate['snoozed_active']         = (int) $wpdb->get_var("SELECT COUNT(*) FROM $part_table WHERE snoozed_until IS NOT NULL AND snoozed_until > UTC_TIMESTAMP()");
            $userstate['snoozed_resurface_next'] =        $wpdb->get_var("SELECT MIN(snoozed_until) FROM $part_table WHERE snoozed_until IS NOT NULL AND snoozed_until > UTC_TIMESTAMP()");
        }
    }
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $drafts_table)) === $drafts_table) {
        $userstate['drafts_total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $drafts_table");
    }
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $grants_table)) === $grants_table) {
        $userstate['grants_active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $grants_table WHERE expires_at IS NULL OR expires_at > UTC_TIMESTAMP()");
    }

    return array(
        'generated_at'    => gmdate('Y-m-d H:i:s') . ' UTC',
        'mta'             => $mta,
        'config'          => $config,
        'inbound'         => $inbound,
        'outbound'        => $outbound,
        'queue'           => $queue,
        'drain'           => $drain,
        'cron'            => $cron,
        'vacation'        => $vacation,
        'filters'         => $filters,
        'userstate'       => $userstate,
        'dns'             => $dns,
        'recent_inbound'  => $recent_in,
        'recent_outbound' => $recent_out,
    );
}

function em_inbox_diag_dns_check($domain) {
    if ($domain === '') return array('skipped' => true);
    $out = array('domain' => $domain);
    foreach (array(
        'MX'    => array('host' => $domain,                       'type' => 'MX'),
        'SPF'   => array('host' => $domain,                       'type' => 'TXT'),
        'DKIM'  => array('host' => 'default._domainkey.' . $domain, 'type' => 'TXT'),
        'DMARC' => array('host' => '_dmarc.' . $domain,           'type' => 'TXT'),
    ) as $label => $q) {
        $rows = em_inbox_diag_doh_query($q['host'], $q['type']);
        // SPF and DMARC are TXT records — filter to ones matching the expected prefix.
        if ($label === 'SPF')   { $rows = array_values(array_filter($rows, function ($r) { return stripos($r, 'v=spf1') === 0; })); }
        if ($label === 'DMARC') { $rows = array_values(array_filter($rows, function ($r) { return stripos($r, 'v=DMARC1') === 0; })); }
        if ($label === 'DKIM')  { $rows = array_values(array_filter($rows, function ($r) { return stripos($r, 'v=DKIM1') === 0; })); }
        $out[$label] = array(
            'ok'    => ! empty($rows),
            'count' => count($rows),
            'first' => $rows ? mb_substr($rows[0], 0, 160) : null,
        );
    }
    return $out;
}

/**
 * DNS-over-HTTPS via Google's public resolver — bypasses the cluster
 * kube-dns stubDomain rewrite for gend.me that masks our public
 * record set. Returns an array of the rdata strings.
 */
function em_inbox_diag_doh_query($host, $type) {
    $url = 'https://dns.google/resolve?name=' . rawurlencode($host) . '&type=' . rawurlencode($type);
    $resp = wp_remote_get($url, array('timeout' => 5, 'headers' => array('Accept' => 'application/dns-json')));
    if (is_wp_error($resp)) return array();
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    if (! is_array($data) || empty($data['Answer'])) return array();
    $out = array();
    foreach ($data['Answer'] as $a) {
        if (! isset($a['data'])) continue;
        $val = (string) $a['data'];
        // TXT records are returned with quotes preserved (e.g. '"v=spf1 …"');
        // strip quotes + collapse split strings into one.
        if (in_array($type, array('TXT', 'DKIM'), true)) {
            $val = preg_replace('/^"|"$/', '', $val);
            $val = preg_replace('/" *"/', '', $val);
        }
        $out[] = $val;
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Card renderers — each returns an HTML <tr>… block
 * ------------------------------------------------------------------------- */

function em_inbox_diag_mta($d) {
    $level = $d['reachable'] ? 'ok' : 'err';
    $out  = em_inbox_diag_row('Reachable',  ($d['reachable'] ? '✓ yes' : '✗ no'), $level);
    $out .= em_inbox_diag_row('URL',        '<span class="em-diag-mono">' . esc_html($d['url']) . '</span>');
    $out .= em_inbox_diag_row('Latency',    esc_html($d['latency_ms']) . ' ms');
    $out .= em_inbox_diag_row('Detail',     esc_html($d['detail']));
    return $out;
}

function em_inbox_diag_config($c) {
    $out  = em_inbox_diag_row('Default domain', $c['default_domain'] ? '<span class="em-diag-mono">' . esc_html($c['default_domain']) . '</span>' : '<span class="em-diag-err">(not set)</span>');
    $out .= em_inbox_diag_row('HMAC secret',    $c['hmac_present'] ? '✓ ' . esc_html($c['hmac_length']) . ' chars' : '✗ missing', $c['hmac_present'] ? 'ok' : 'err');
    $out .= em_inbox_diag_row('HMAC fingerprint', $c['hmac_fingerprint'] ? '<span class="em-diag-mono">' . esc_html($c['hmac_fingerprint']) . '</span>' : '—');
    $out .= em_inbox_diag_row('Hub registry',   $c['registry_enabled'] ? '✓ enabled' : '— disabled', $c['registry_enabled'] ? 'ok' : '');
    return $out;
}

function em_inbox_diag_inbound($i) {
    $out  = em_inbox_diag_row('Last 24h',       esc_html($i['last_24h']));
    $out .= em_inbox_diag_row('Total all-time', esc_html($i['total']));
    if (! empty($i['top_senders'])) {
        $list = '<ul style="margin:0;padding-left:14px;">';
        foreach ($i['top_senders'] as $r) {
            $list .= '<li><span class="em-diag-mono">' . esc_html($r['sender']) . '</span> · ' . (int) $r['n'] . '</li>';
        }
        $list .= '</ul>';
        $out .= em_inbox_diag_row('Top senders 24h', $list);
    }
    return $out;
}

function em_inbox_diag_outbound($o) {
    $out  = em_inbox_diag_row('Last 24h',       esc_html($o['last_24h']));
    $out .= em_inbox_diag_row('Total all-time', esc_html($o['total']));
    $statusLines = array();
    foreach ($o['by_status'] as $r) {
        $level = $r['s'] === 'sent' ? 'ok' : ($r['s'] === 'retrying' ? 'warn' : ($r['s'] === 'failed' ? 'err' : ''));
        $statusLines[] = '<span class="em-diag-' . esc_attr($level) . '">' . esc_html($r['s']) . '=' . (int) $r['n'] . '</span>';
    }
    $out .= em_inbox_diag_row('By status', $statusLines ? implode(' · ', $statusLines) : '(none)');
    return $out;
}

function em_inbox_diag_queue($q) {
    $level = $q['retrying'] > 0 ? 'warn' : 'ok';
    $out  = em_inbox_diag_row('Retrying', esc_html($q['retrying']), $level);
    $out .= em_inbox_diag_row('Failed',   esc_html($q['failed']), $q['failed'] > 0 ? 'err' : 'ok');
    $out .= em_inbox_diag_row('Next due', $q['next_due'] ? esc_html($q['next_due']) . ' UTC' : '(none)');
    return $out;
}

function em_inbox_diag_cron($c) {
    $thread_next = $c['thread_cron_next'] ? gmdate('Y-m-d H:i:s', $c['thread_cron_next']) . ' UTC (' . human_time_diff(time(), $c['thread_cron_next']) . ')' : '<span class="em-diag-err">not scheduled</span>';
    $outq_next   = $c['outq_cron_next']   ? gmdate('Y-m-d H:i:s', $c['outq_cron_next'])   . ' UTC (' . human_time_diff(time(), $c['outq_cron_next'])   . ')' : '<span class="em-diag-err">not scheduled</span>';
    $out  = em_inbox_diag_row('Threading cron (next)',     $thread_next);
    $out .= em_inbox_diag_row('Retry queue cron (next)',   $outq_next);
    return $out;
}

function em_inbox_diag_dns($d) {
    if (! empty($d['skipped'])) return em_inbox_diag_row('—', 'em_inbox_default_domain not set', 'warn');
    $out = '';
    foreach (array('MX','SPF','DKIM','DMARC') as $type) {
        $r     = $d[$type];
        $level = $r['ok'] ? 'ok' : 'err';
        $cell  = ($r['ok'] ? '✓ ' : '✗ ') . ($r['first'] ? '<span class="em-diag-mono">' . esc_html($r['first']) . '</span>' : '(no record)');
        $out  .= em_inbox_diag_row($type, $cell, $level);
    }
    return $out;
}

function em_inbox_diag_drain($d) {
    // Health rule: drain should have run within the last 5 min (cron
    // schedule is every minute). Anything older → warn, >30 min → err.
    $last_at = $d['last_at'];
    $level = 'ok';
    $detail = '(never)';
    if ($last_at) {
        $age = time() - strtotime($last_at . ' UTC');
        if      ($age > 1800) $level = 'err';
        elseif  ($age > 300)  $level = 'warn';
        $detail = esc_html($last_at) . ' UTC (' . human_time_diff(time() - $age, time()) . ' ago)';
    } else {
        $level = 'err';
    }
    $out  = em_inbox_diag_row('Last drain',           $detail, $level);
    $out .= em_inbox_diag_row('Last processed',       esc_html($d['last_processed']) . ' row' . ($d['last_processed'] === 1 ? '' : 's'));
    $out .= em_inbox_diag_row('Processed all-time',   esc_html($d['processed_total']));
    return $out;
}

function em_inbox_diag_vacation($v) {
    $out  = em_inbox_diag_row('Users with vacation ON', esc_html($v['enabled_users']), $v['enabled_users'] > 0 ? 'warn' : 'ok');
    $out .= em_inbox_diag_row('Auto-replies 24h',       esc_html($v['fired_24h']));
    $out .= em_inbox_diag_row('Auto-replies all-time',  esc_html($v['fired_total']));
    $out .= em_inbox_diag_row('Last auto-reply',        $v['last_at'] ? esc_html($v['last_at']) . ' UTC' : '(none)');
    return $out;
}

function em_inbox_diag_filters($f) {
    $out  = em_inbox_diag_row('Filters defined', esc_html($f['total']));
    $out .= em_inbox_diag_row('Enabled',         esc_html($f['enabled']));
    $out .= em_inbox_diag_row('Matches 24h',     esc_html($f['matches_24h']));
    $out .= em_inbox_diag_row('Matches all-time',esc_html($f['matches_total']));
    if (! empty($f['top'])) {
        $list = '<ul style="margin:0;padding-left:14px;">';
        foreach ($f['top'] as $r) {
            $list .= '<li>' . esc_html($r['name']) . ' · ' . (int) $r['match_count'] . '</li>';
        }
        $list .= '</ul>';
        $out .= em_inbox_diag_row('Top firing filters', $list);
    }
    return $out;
}

function em_inbox_diag_userstate($u) {
    $next = $u['snoozed_resurface_next'];
    $out  = em_inbox_diag_row('Active snoozed threads', esc_html($u['snoozed_active']));
    $out .= em_inbox_diag_row('Next resurface',         $next ? esc_html($next) . ' UTC' : '(none)');
    $out .= em_inbox_diag_row('Drafts saved',           esc_html($u['drafts_total']));
    $out .= em_inbox_diag_row('Active delegations',     esc_html($u['grants_active']));
    return $out;
}

function em_inbox_diag_recent($rows) {
    if (! $rows) return em_inbox_diag_row('—', '(no recent messages)');
    $out = '';
    foreach ($rows as $r) {
        $status = isset($r['delivery_status']) ? ' · ' . esc_html($r['delivery_status']) : '';
        $line   = esc_html($r['received_at']) . ' · ' . esc_html($r['sender']) . ' → ' . esc_html($r['recipient']) . ' · "' . esc_html($r['subject']) . '"' . $status;
        $out   .= '<tr><td colspan="2" style="font-size:11px;">' . $line . '</td></tr>';
    }
    return $out;
}
