<?php
/**
 * Member Inbox: comprehensive smoke test (slice 2dd).
 *
 * Runs every shipped slice's critical path end-to-end inside the WP
 * process. Designed to be invoked via wp-cli:
 *
 *   wp --allow-root eval-file wp-content/plugins/email-manager/bin/inbox-smoke-test.php
 *
 * Exits non-zero if any check fails. Each section prefixes output with
 * its slice tag so a failure points straight at the responsible code.
 *
 * NOTE: This script bypasses the SMTP relay (it doesn't actually call
 * out to email-mta-submit). It exercises the in-process pipeline:
 * webhook → threading → participants → labels/filters/vacation →
 * outbound queue → drain. Real send/receive verification needs a
 * separate live test that depends on Workspace SMTP allowlist.
 */

defined('ABSPATH') || exit;

global $wpdb, $em_smoke_fail_count, $em_smoke_pass_count;
$em_smoke_fail_count = 0;
$em_smoke_pass_count = 0;

function smoke_assert($tag, $cond, $label, $detail = '') {
    global $em_smoke_fail_count, $em_smoke_pass_count;
    if ($cond) {
        echo "[$tag] PASS  $label\n";
        $em_smoke_pass_count++;
    } else {
        echo "[$tag] !!!FAIL  $label" . ($detail ? " — $detail" : '') . "\n";
        $em_smoke_fail_count++;
    }
}

function post_json($route, $body) {
    $req = new WP_REST_Request('POST', $route);
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(json_encode($body));
    return rest_do_request($req);
}

// ── setup ────────────────────────────────────────────────────────────
$user = get_users(array('role' => 'administrator', 'number' => 1));
if (empty($user)) { echo "FATAL: no admin user\n"; exit(1); }
wp_set_current_user($user[0]->ID);
$uid   = $user[0]->ID;
$inbox = strtolower($user[0]->user_email);
update_user_meta($uid, 'em_inbox_address', $inbox);
echo "ACTING AS uid=$uid email=$inbox\n\n";

$run_tag = 'sm-' . substr(bin2hex(random_bytes(4)), 0, 6);
$created_raw_ids    = array();
$created_thread_ids = array();
$created_label_ids  = array();
$created_filter_ids = array();

// Helper: insert a synthetic inbound raw row + run threading.
function smoke_insert_inbound($from, $subject, $body_plain, $body_html = '', $kind = 'inbound', $extra_headers = array()) {
    global $wpdb, $run_tag;
    $inbox = strtolower(wp_get_current_user()->user_email);
    $message_id = sprintf('<%s.%s@example.invalid>', $run_tag, substr(bin2hex(random_bytes(6)), 0, 12));
    $headers = array_merge(array(
        array('name' => 'From',    'value' => $from),
        array('name' => 'To',      'value' => $inbox),
        array('name' => 'Subject', 'value' => $subject),
    ), $extra_headers);
    $wpdb->insert($wpdb->prefix . 'gdc_inbox_raw', array(
        'message_id'  => $message_id,
        'recipient'   => $inbox,
        'sender'      => $from,
        'subject'     => $subject,
        'raw_headers' => wp_json_encode($headers),
        'body_plain'  => $body_plain,
        'body_html'   => $body_html,
        'size_bytes'  => strlen($body_plain) + strlen($body_html),
        'received_at' => current_time('mysql', 1),
        'processed'   => 0,
        'kind'        => $kind,
    ));
    $raw_id = (int) $wpdb->insert_id;
    em_inbox_thread_one($raw_id);
    return array('raw_id' => $raw_id, 'message_id' => $message_id);
}

// ─── 1. SCHEMA + DB VERSIONS (proves every migrator ran) ─────────────
foreach (array(
    'em_inbox_part_db_version'     => '1.3.0',
    'em_inbox_outq_db_version'     => '1.1.0',
    'em_inbox_filters_db_version'  => '1.0.0',
    'em_inbox_grants_db_version'   => '1.0.0',
) as $opt => $expect) {
    $v = get_option($opt);
    smoke_assert('schema', $v === $expect, "$opt = $expect", "got: " . var_export($v, true));
}

// ─── 2. INBOUND THREADING (slices 2a/2b/2c) ─────────────────────────
$first = smoke_insert_inbound('alice@example.com', "smoke $run_tag subject A", 'First message body');
$created_raw_ids[] = $first['raw_id'];
$thread1 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT thread_id FROM {$wpdb->prefix}gdc_inbox_messages WHERE raw_id = %d",
    $first['raw_id']
));
$created_thread_ids[] = $thread1;
smoke_assert('thread', $thread1 > 0, 'inbound threading created thread for first message');

// Reply with In-Reply-To should attach to same thread (JWZ).
$reply = smoke_insert_inbound('alice@example.com', "Re: smoke $run_tag subject A", 'Reply body', '',
    'inbound',
    array(array('name' => 'In-Reply-To', 'value' => $first['message_id']))
);
$created_raw_ids[] = $reply['raw_id'];
$thread2 = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT thread_id FROM {$wpdb->prefix}gdc_inbox_messages WHERE raw_id = %d",
    $reply['raw_id']
));
smoke_assert('thread', $thread2 === $thread1, 'reply with In-Reply-To stitched onto same thread', "first=$thread1 reply=$thread2");

// ─── 3. PARTICIPANT STATE (slice 2g/2aa) ─────────────────────────────
$p = $wpdb->get_row($wpdb->prepare(
    "SELECT is_read, is_archived, is_trashed, is_starred, snoozed_until
     FROM {$wpdb->prefix}gdc_inbox_participants
     WHERE thread_id = %d AND user_id = %d", $thread1, $uid
), ARRAY_A);
smoke_assert('part', $p !== null, 'participant row exists for owner');
smoke_assert('part', $p && (int) $p['is_read'] === 0, 'inbound stamps participant as unread');

// State flips via REST.
$res = post_json("/em/v1/inbox/threads/$thread1/read", new stdClass());
smoke_assert('part', $res->get_status() === 200, '/read endpoint 200');
$p = $wpdb->get_row($wpdb->prepare("SELECT is_read FROM {$wpdb->prefix}gdc_inbox_participants WHERE thread_id = %d AND user_id = %d", $thread1, $uid), ARRAY_A);
smoke_assert('part', $p && (int) $p['is_read'] === 1, '/read marks is_read=1');

$res = post_json("/em/v1/inbox/threads/$thread1/star", new stdClass());
smoke_assert('part', $res->get_status() === 200, '/star endpoint 200');

$res = post_json("/em/v1/inbox/threads/$thread1/snooze", array('until' => gmdate('c', time() + 3600)));
smoke_assert('part', $res->get_status() === 200, '/snooze endpoint 200');
$cur = $wpdb->get_var($wpdb->prepare("SELECT snoozed_until FROM {$wpdb->prefix}gdc_inbox_participants WHERE thread_id = %d AND user_id = %d", $thread1, $uid));
smoke_assert('part', ! empty($cur), '/snooze persisted snoozed_until');

$res = post_json("/em/v1/inbox/threads/$thread1/unsnooze", new stdClass());
smoke_assert('part', $res->get_status() === 200, '/unsnooze endpoint 200');
$cur = $wpdb->get_var($wpdb->prepare("SELECT snoozed_until FROM {$wpdb->prefix}gdc_inbox_participants WHERE thread_id = %d AND user_id = %d", $thread1, $uid));
smoke_assert('part', $cur === null, '/unsnooze cleared snoozed_until');

// ─── 4. LISTING + COUNTS (slice 2g + 2aa) ─────────────────────────────
$req = new WP_REST_Request('GET', '/em/v1/inbox/threads');
$req->set_query_params(array('inbox' => $inbox));
$res = rest_do_request($req);
$d = $res->get_data();
smoke_assert('list', $res->get_status() === 200, '/threads list 200');
smoke_assert('list', isset($d['counts']['snoozed']), 'counts include snoozed key');

// ─── 5. LABELS (slice 2r.1) ──────────────────────────────────────────
$lname = "smoke_$run_tag";
$wpdb->insert($wpdb->prefix . 'gdc_inbox_labels', array(
    'user_id' => $uid, 'name' => $lname, 'color' => '#5524a7',
    'created_at' => current_time('mysql', 1),
));
$label_id = (int) $wpdb->insert_id;
$created_label_ids[] = $label_id;
smoke_assert('labels', $label_id > 0, 'label create insert returned id');

$res = post_json("/em/v1/inbox/threads/$thread1/labels", array('label_ids' => array($label_id)));
smoke_assert('labels', $res->get_status() === 200, 'set thread labels 200');
$cnt = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}gdc_inbox_thread_labels WHERE thread_id = %d AND label_id = %d", $thread1, $label_id
));
smoke_assert('labels', $cnt === 1, 'thread_labels row created');

// ─── 6. CONTACTS (slice 2t) ──────────────────────────────────────────
$req = new WP_REST_Request('GET', '/em/v1/inbox/contacts');
$req->set_query_params(array('q' => 'alice'));
$res = rest_do_request($req);
$d = $res->get_data();
smoke_assert('contacts', $res->get_status() === 200, '/contacts 200');
$has_alice = false;
foreach ((array) $d as $c) { if (is_array($c) && isset($c['email']) && stripos($c['email'], 'alice') === 0) { $has_alice = true; break; } }
smoke_assert('contacts', $has_alice, 'auto-extracted alice@example.com from inbound');

// ─── 7. SEARCH (slice 2h + 2ff ranking) ──────────────────────────────
$req = new WP_REST_Request('GET', '/em/v1/inbox/search');
$req->set_query_params(array('q' => $run_tag));
$res = rest_do_request($req);
$d = $res->get_data();
smoke_assert('search', $res->get_status() === 200, '/search 200');
smoke_assert('search', isset($d['items']) && count($d['items']) > 0, 'search returns >=1 hit for run-tagged subject');

// Slice 2ff: results are deduped by thread (we inserted both a parent
// and a reply with the same run_tag — they share a thread, should be
// one hit).
if (! empty($d['items'])) {
    $thread_ids = array_column($d['items'], 'thread_id');
    smoke_assert('search', count($thread_ids) === count(array_unique($thread_ids)), 'search dedup: 1 result per thread');
    // Snippet contains <mark> highlighting around the matched run_tag.
    $first = $d['items'][0];
    smoke_assert('search', stripos($first['snippet'] . $first['subject'], $run_tag) !== false, 'snippet or subject contains query term');
    // Sender match should boost: insert a synthetic row where the
    // sender matches the query but body does not, and verify it scores
    // above a body-only hit.
    $unique = 'sboost' . substr(bin2hex(random_bytes(3)), 0, 6);
    smoke_insert_inbound("$unique@example.com", "ordinary subject for boost test", 'plain body content');
    smoke_insert_inbound('other@example.com', "ordinary subject mentions $unique here", 'plain body content');
    $req = new WP_REST_Request('GET', '/em/v1/inbox/search');
    $req->set_query_params(array('q' => $unique));
    $res = rest_do_request($req);
    $d2 = $res->get_data();
    if (count($d2['items']) >= 2) {
        // Sender-match row should rank first because of the 2.0 boost.
        $first_match = $d2['items'][0];
        smoke_assert('search', stripos($first_match['sender'], $unique) !== false, 'sender-matching row ranks above subject-only match');
    } else {
        smoke_assert('search', false, 'sender-boost test: not enough hits returned', 'got ' . count($d2['items']));
    }
    // Cleanup synthetic rows
    $wpdb->query("DELETE FROM {$wpdb->prefix}gdc_inbox_messages WHERE subject LIKE '%$unique%' OR sender LIKE '$unique@%'");
    $wpdb->query("DELETE FROM {$wpdb->prefix}gdc_inbox_raw WHERE subject LIKE '%$unique%' OR sender LIKE '$unique@%'");
}

// ─── 8. SANITIZER + IMAGE BLOCK (slice 2q) ──────────────────────────
if (function_exists('em_inbox_sanitize_html')) {
    $dirty  = '<p>hi<script>alert(1)</script></p>';
    $clean  = em_inbox_sanitize_html($dirty);
    smoke_assert('sanit', stripos($clean, '<script') === false, 'sanitizer strips <script>');
}
if (function_exists('em_inbox_block_remote_images')) {
    $html = '<p>hi <img src="https://tracker.example.com/p.gif"></p>';
    list($blocked, $blocked_count) = em_inbox_block_remote_images($html);
    smoke_assert('sanit', $blocked_count === 1, 'remote-image blocking counted 1 image');
    // The new src= must be the inline data: pixel, not the original https:// URL.
    smoke_assert('sanit', preg_match('/<img[^>]+\bsrc\s*=\s*["\']data:image\//i', (string) $blocked) === 1, 'remote img src rewritten to inline data: pixel');
    smoke_assert('sanit', strpos((string) $blocked, 'data-blocked-src="https://tracker.example.com') !== false, 'remote img stashed in data-blocked-src');
}

// ─── 9. FILTERS (slice 2cc) ──────────────────────────────────────────
$res = post_json('/em/v1/inbox/filters', array(
    'name' => "smoke filter $run_tag",
    'enabled' => 1,
    'conditions' => array(array('field' => 'subject', 'op' => 'contains', 'value' => $run_tag . '-filter-target')),
    'actions' => array(array('type' => 'star')),
));
$d = $res->get_data();
smoke_assert('filt', $res->get_status() === 200 && ! empty($d['id']), 'filter create 200 + id');
$filter_id = (int) $d['id'];
$created_filter_ids[] = $filter_id;

$hit = smoke_insert_inbound('bob@example.com', "$run_tag-filter-target hit subject", 'body');
$created_raw_ids[] = $hit['raw_id'];
$hit_tid = (int) $wpdb->get_var($wpdb->prepare("SELECT thread_id FROM {$wpdb->prefix}gdc_inbox_messages WHERE raw_id = %d", $hit['raw_id']));
$p2 = $wpdb->get_row($wpdb->prepare("SELECT is_starred FROM {$wpdb->prefix}gdc_inbox_participants WHERE thread_id = %d AND user_id = %d", $hit_tid, $uid), ARRAY_A);
smoke_assert('filt', $p2 && (int) $p2['is_starred'] === 1, 'filter star action fired on matching inbound');

// ─── 10. OUTBOUND QUEUE (slice 2f.2/2y/2bb) — STATE MACHINE ONLY ────
// Schedule a send for +2s, but don't actually drain (relay would fail).
$res = post_json('/em/v1/inbox/send', array(
    'to' => array('nowhere@example.invalid'),
    'subject' => "smoke $run_tag scheduled",
    'body_plain' => 'x',
    'body_html'  => '<p>x</p>',
    'send_at' => gmdate('c', time() + 600),
));
$d = $res->get_data();
smoke_assert('outq', $res->get_status() === 200, 'scheduled send 200');
smoke_assert('outq', ($d['delivery_status'] ?? '') === 'scheduled', 'mirror row stamped scheduled');
$sched_raw_id = (int) ($d['raw_id'] ?? 0);
$created_raw_ids[] = $sched_raw_id;

// /scheduled lists it
$req = new WP_REST_Request('GET', '/em/v1/inbox/scheduled');
$res = rest_do_request($req);
$d = $res->get_data();
$ids = array_map('intval', array_column($d['items'] ?? array(), 'raw_id'));
smoke_assert('outq', in_array($sched_raw_id, $ids, true), '/scheduled lists our raw_id');

// Cancel
$req = new WP_REST_Request('DELETE', "/em/v1/inbox/messages/$sched_raw_id/cancel");
$res = rest_do_request($req);
smoke_assert('outq', $res->get_status() === 200, 'cancel scheduled 200');
$still = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}gdc_inbox_raw WHERE id = %d", $sched_raw_id));
smoke_assert('outq', $still === 0, 'cancel removed the mirror row');

// ─── 11. VACATION RESPONDER (slice 2z) ──────────────────────────────
$req = new WP_REST_Request('GET', '/em/v1/inbox/vacation');
$res = rest_do_request($req);
smoke_assert('vac', $res->get_status() === 200 && is_array($res->get_data()), '/vacation GET 200 + array');

// ─── 12. SIGNATURE (slice 2v) ────────────────────────────────────────
$req = new WP_REST_Request('GET', '/em/v1/inbox/signature');
$res = rest_do_request($req);
smoke_assert('sig', $res->get_status() === 200, '/signature GET 200');

// ─── 13. DIAGNOSTICS (slice 2m) ──────────────────────────────────────
smoke_assert('diag', function_exists('em_inbox_diag_render'), 'diagnostics render function loaded');
smoke_assert('diag', function_exists('em_inbox_diag_gather'), 'diagnostics gather function loaded');

// ─── 14. IDEMPOTENCY LEDGER (slice 2o) ──────────────────────────────
smoke_assert('idem', function_exists('em_inbox_ledger_record'), 'idempotency ledger record function loaded');
smoke_assert('idem', function_exists('em_inbox_ledger_maybe_create_table'), 'idempotency ledger migrate function loaded');

// ─── 15. TRACKING (slice 2s) ────────────────────────────────────────
smoke_assert('track', function_exists('em_inbox_track_inject_pixel'), 'tracking inject_pixel loaded');

// ─── 16. GRANTS / DELEGATION (slice 2ee) ─────────────────────────────
// Create a second user so we have someone to grant to. If a smoke-test
// grantee already exists, reuse it.
$grantee_email = 'smoke-grantee-' . $run_tag . '@example.invalid';
$grantee = get_user_by('email', $grantee_email);
if (! $grantee) {
    $grantee_id = wp_create_user(
        'smoke_grantee_' . substr(bin2hex(random_bytes(3)), 0, 6),
        wp_generate_password(20, true),
        $grantee_email
    );
    $grantee = get_user_by('id', $grantee_id);
}
smoke_assert('grant', $grantee && $grantee->ID > 0, 'grantee user created/found');

// Grant read access from current user to grantee.
$res = post_json('/em/v1/inbox/grants', array(
    'grantee_email' => $grantee_email,
    'scope' => 'read',
));
$d = $res->get_data();
smoke_assert('grant', $res->get_status() === 200 && ! empty($d['id']), 'grant create 200 + id');
$grant_id = (int) ($d['id'] ?? 0);

// Verify grants_received_by returns the grant for grantee.
$received = em_inbox_grants_received_by((int) $grantee->ID);
smoke_assert('grant', count($received) >= 1, 'grants_received_by returns >=1');

// Verify the address-permission check passes for the grantee.
$prev_user_id = get_current_user_id();
wp_set_current_user((int) $grantee->ID);
smoke_assert('grant', em_inbox_current_user_can_read_address($inbox), 'grantee passes read predicate');
smoke_assert('grant', ! em_inbox_current_user_can_send_as($inbox), 'grantee with read-only cannot send-as owner');

// Upgrade to read_send.
wp_set_current_user($prev_user_id);
$res = post_json('/em/v1/inbox/grants', array(
    'grantee_email' => $grantee_email,
    'scope' => 'read_send',
));
$d = $res->get_data();
smoke_assert('grant', $res->get_status() === 200 && ! empty($d['updated']), 'upsert flips scope (updated=true)');

wp_set_current_user((int) $grantee->ID);
smoke_assert('grant', em_inbox_current_user_can_send_as($inbox), 'grantee with read_send CAN send-as owner');

// Self-grant rejected.
wp_set_current_user($prev_user_id);
$res = post_json('/em/v1/inbox/grants', array(
    'grantee_email' => $inbox,
    'scope' => 'read',
));
smoke_assert('grant', $res->get_status() === 400, 'self-grant rejected with 400');

// Unknown grantee rejected.
$res = post_json('/em/v1/inbox/grants', array(
    'grantee_email' => 'nobody-' . $run_tag . '@example.invalid',
    'scope' => 'read',
));
smoke_assert('grant', $res->get_status() === 404, 'unknown grantee rejected with 404');

// Bad expiry rejected.
$res = post_json('/em/v1/inbox/grants', array(
    'grantee_email' => $grantee_email,
    'scope' => 'read',
    'expires_at' => gmdate('c', time() - 60),
));
smoke_assert('grant', $res->get_status() === 400, 'past expires_at rejected with 400');

// /inboxes from grantee perspective includes owner's address with shared=true.
wp_set_current_user((int) $grantee->ID);
$req = new WP_REST_Request('GET', '/em/v1/inbox/inboxes');
$res = rest_do_request($req);
$d = $res->get_data();
$shared_found = false;
foreach ((array) $d as $row) {
    if (! empty($row['shared']) && strtolower($row['inbox_address']) === $inbox) { $shared_found = true; break; }
}
smoke_assert('grant', $shared_found, '/inboxes from grantee includes owner inbox with shared=true');

// Revoke.
wp_set_current_user($prev_user_id);
$req = new WP_REST_Request('DELETE', "/em/v1/inbox/grants/$grant_id");
$res = rest_do_request($req);
smoke_assert('grant', $res->get_status() === 200, 'revoke 200');
$still = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}gdc_inbox_grants WHERE id = %d", $grant_id));
smoke_assert('grant', $still === 0, 'revoke removed the row');

// After revoke, grantee no longer passes the read predicate.
wp_set_current_user((int) $grantee->ID);
smoke_assert('grant', ! em_inbox_current_user_can_read_address($inbox), 'grantee read predicate fails after revoke');

// Cleanup: delete the grantee user.
wp_set_current_user($prev_user_id);
wp_delete_user((int) $grantee->ID);

// ─── CLEANUP ─────────────────────────────────────────────────────────
foreach ($created_filter_ids as $id) $wpdb->delete($wpdb->prefix . 'gdc_inbox_filters', array('id' => $id));
foreach ($created_label_ids as $id) {
    $wpdb->delete($wpdb->prefix . 'gdc_inbox_thread_labels', array('label_id' => $id));
    $wpdb->delete($wpdb->prefix . 'gdc_inbox_labels', array('id' => $id));
}
foreach ($created_raw_ids as $id) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}gdc_inbox_messages WHERE raw_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}gdc_inbox_raw WHERE id = %d", $id));
}
foreach ($created_thread_ids as $id) {
    $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}gdc_inbox_messages WHERE thread_id = %d", $id));
    if ($remaining === 0) {
        $wpdb->delete($wpdb->prefix . 'gdc_inbox_threads', array('id' => $id));
        $wpdb->delete($wpdb->prefix . 'gdc_inbox_participants', array('thread_id' => $id));
    }
}

echo "\n========================================\n";
echo "PASS: $em_smoke_pass_count   FAIL: $em_smoke_fail_count\n";
echo "========================================\n";
if ($em_smoke_fail_count > 0) exit(1);
