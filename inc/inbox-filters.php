<?php
/**
 * Member Inbox: filters engine (slice 2cc).
 *
 * Per-user rules that auto-apply to inbound messages BEFORE the user
 * sees them. A filter is a set of conditions (all must match — AND) +
 * a set of actions to take when the conditions match.
 *
 * Conditions: { field, op, value }
 *   field: from | to | subject | body | any (= matches in any of from/to/subject/body)
 *   op:    contains | equals | starts_with | ends_with | matches (regex)
 *   value: string
 *
 * Actions: { type, value? }
 *   type=label    value=<label_id>      — attach label to thread
 *   type=archive  (no value)            — auto-archive
 *   type=trash    (no value)            — move to trash
 *   type=star     (no value)            — star the thread
 *   type=read     (no value)            — mark read (skip the unread bump)
 *   type=forward  value=<email>         — forward via outbound queue
 *
 * Evaluation runs from the em_inbox_message_inserted action at
 * priority 35 — after participants (20, default unread state) and
 * before vacation (40, auto-reply). That ordering means: a filter
 * that auto-archives a message still triggers vacation (correct —
 * sender deserves an OOO reply even if YOU don't want to see it),
 * but a filter that trashes it should also explicitly request no
 * vacation reply (out of scope for v1; ship without that polish).
 *
 * @package EmailManager
 * @since   1.3.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_FILTERS_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema
 * ------------------------------------------------------------------------- */

function em_inbox_filters_maybe_create_table() {
    if (get_option('em_inbox_filters_db_version') === EM_INBOX_FILTERS_DB_VERSION) return;
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'gdc_inbox_filters';
    $sql = "CREATE TABLE $table (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL,
        name            VARCHAR(190) NOT NULL DEFAULT '',
        enabled         TINYINT(1) NOT NULL DEFAULT 1,
        sort_order      INT NOT NULL DEFAULT 0,
        conditions_json LONGTEXT NULL,
        actions_json    LONGTEXT NULL,
        last_matched_at DATETIME NULL,
        match_count     INT UNSIGNED NOT NULL DEFAULT 0,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id, enabled, sort_order)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('em_inbox_filters_db_version', EM_INBOX_FILTERS_DB_VERSION);
}
add_action('admin_init',    'em_inbox_filters_maybe_create_table');
add_action('rest_api_init', 'em_inbox_filters_maybe_create_table');

/* -------------------------------------------------------------------------
 * REST CRUD
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/filters', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_filters_list',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'em_inbox_filters_create',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
    register_rest_route('em/v1', '/inbox/filters/(?P<id>\d+)', array(
        array(
            'methods'             => array('PUT', 'PATCH', 'POST'),
            'callback'            => 'em_inbox_filters_update',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'em_inbox_filters_delete',
            'permission_callback' => function () { return is_user_logged_in(); },
        ),
    ));
    register_rest_route('em/v1', '/inbox/filters/(?P<id>\d+)/test', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_filters_test',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_filters_list(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_filters_no_user', 'Login required', array('status' => 401));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}gdc_inbox_filters WHERE user_id = %d ORDER BY sort_order ASC, id ASC",
        (int) $u->ID
    ), ARRAY_A);
    foreach ($rows as &$row) {
        $row['conditions'] = json_decode((string) $row['conditions_json'], true) ?: array();
        $row['actions']    = json_decode((string) $row['actions_json'],    true) ?: array();
        unset($row['conditions_json'], $row['actions_json']);
    }
    unset($row);
    return rest_ensure_response(array('items' => $rows));
}

function em_inbox_filters_create(WP_REST_Request $r) {
    return em_inbox_filters_upsert($r, 0);
}
function em_inbox_filters_update(WP_REST_Request $r) {
    return em_inbox_filters_upsert($r, (int) $r['id']);
}

function em_inbox_filters_upsert(WP_REST_Request $r, $id) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_filters_no_user', 'Login required', array('status' => 401));

    $body = $r->get_json_params();
    if (! is_array($body) && ! empty($r->get_params())) $body = $r->get_params();
    if (! is_array($body)) $body = array();

    $name       = trim((string) ($body['name'] ?? ''));
    $enabled    = ! empty($body['enabled']) || (string) ($body['enabled'] ?? '1') === '1';
    $sort_order = (int) ($body['sort_order'] ?? 0);
    $conditions = is_array($body['conditions'] ?? null) ? $body['conditions'] : array();
    $actions    = is_array($body['actions']    ?? null) ? $body['actions']    : array();

    if ($name === '')             return new WP_Error('em_filters_bad_name',  'name is required', array('status' => 400));
    if (! count($conditions))     return new WP_Error('em_filters_no_cond',   'at least one condition is required', array('status' => 400));
    if (! count($actions))        return new WP_Error('em_filters_no_action', 'at least one action is required', array('status' => 400));

    $conds_clean = array();
    foreach ($conditions as $c) {
        if (! is_array($c)) continue;
        $f = strtolower((string) ($c['field'] ?? ''));
        $o = strtolower((string) ($c['op']    ?? ''));
        $v = (string) ($c['value'] ?? '');
        if (! in_array($f, array('from','to','subject','body','any'), true)) continue;
        if (! in_array($o, array('contains','equals','starts_with','ends_with','matches'), true)) continue;
        if ($v === '') continue;
        if ($o === 'matches') {
            // Validate regex compiles.
            if (@preg_match('/' . str_replace('/', '\/', $v) . '/u', '') === false) {
                return new WP_Error('em_filters_bad_regex', 'Invalid regex in condition value: ' . $v, array('status' => 400));
            }
        }
        $conds_clean[] = array('field' => $f, 'op' => $o, 'value' => $v);
    }
    if (! count($conds_clean)) return new WP_Error('em_filters_no_cond', 'at least one valid condition is required', array('status' => 400));

    $acts_clean = array();
    foreach ($actions as $a) {
        if (! is_array($a)) continue;
        $t = strtolower((string) ($a['type'] ?? ''));
        $v = (string) ($a['value'] ?? '');
        if (! in_array($t, array('label','archive','trash','star','read','forward'), true)) continue;
        if ($t === 'label' && (int) $v <= 0) continue;
        if ($t === 'forward' && ! is_email($v)) continue;
        $acts_clean[] = array('type' => $t, 'value' => $v);
    }
    if (! count($acts_clean)) return new WP_Error('em_filters_no_action', 'at least one valid action is required', array('status' => 400));

    $now = current_time('mysql', 1);
    $data = array(
        'user_id'         => (int) $u->ID,
        'name'            => mb_substr($name, 0, 190),
        'enabled'         => $enabled ? 1 : 0,
        'sort_order'      => $sort_order,
        'conditions_json' => wp_json_encode($conds_clean),
        'actions_json'    => wp_json_encode($acts_clean),
        'updated_at'      => $now,
    );
    $table = $wpdb->prefix . 'gdc_inbox_filters';
    if ($id > 0) {
        $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE id = %d", $id));
        if ($owner === 0)            return new WP_Error('em_filters_404', 'Filter not found', array('status' => 404));
        if ($owner !== (int) $u->ID) return new WP_Error('em_filters_forbidden', 'Not your filter', array('status' => 403));
        $wpdb->update($table, $data, array('id' => $id));
    } else {
        $data['created_at'] = $now;
        $wpdb->insert($table, $data);
        $id = (int) $wpdb->insert_id;
    }
    return rest_ensure_response(array('ok' => true, 'id' => $id));
}

function em_inbox_filters_delete(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_filters_no_user', 'Login required', array('status' => 401));
    $id = (int) $r['id'];
    $table = $wpdb->prefix . 'gdc_inbox_filters';
    $owner = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE id = %d", $id));
    if (! $owner)                return new WP_Error('em_filters_404', 'Filter not found', array('status' => 404));
    if ($owner !== (int) $u->ID) return new WP_Error('em_filters_forbidden', 'Not your filter', array('status' => 403));
    $wpdb->delete($table, array('id' => $id), array('%d'));
    return rest_ensure_response(array('ok' => true, 'id' => $id, 'deleted' => true));
}

function em_inbox_filters_test(WP_REST_Request $r) {
    global $wpdb;
    $u = wp_get_current_user();
    if (! $u || ! $u->ID) return new WP_Error('em_filters_no_user', 'Login required', array('status' => 401));
    $id = (int) $r['id'];
    $filter = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}gdc_inbox_filters WHERE id = %d AND user_id = %d",
        $id, (int) $u->ID
    ), ARRAY_A);
    if (! $filter) return new WP_Error('em_filters_404', 'Filter not found', array('status' => 404));

    $body = $r->get_json_params() ?: $r->get_params() ?: array();
    $msg = array(
        'from'    => (string) ($body['from']    ?? ''),
        'to'      => (string) ($body['to']      ?? ''),
        'subject' => (string) ($body['subject'] ?? ''),
        'body'    => (string) ($body['body']    ?? ''),
    );
    $conds = json_decode((string) $filter['conditions_json'], true) ?: array();
    $match = em_inbox_filters_evaluate_conditions($conds, $msg);
    return rest_ensure_response(array('match' => $match, 'message' => $msg, 'conditions' => $conds));
}

/* -------------------------------------------------------------------------
 * Evaluation
 * ------------------------------------------------------------------------- */

function em_inbox_filters_evaluate_conditions($conditions, $msg) {
    if (! is_array($conditions) || empty($conditions)) return false;
    foreach ($conditions as $c) {
        if (! is_array($c)) return false;
        $field = $c['field'] ?? '';
        $op    = $c['op']    ?? '';
        $val   = (string) ($c['value'] ?? '');
        if ($field === 'any') {
            $haystacks = array(
                (string) ($msg['from']    ?? ''),
                (string) ($msg['to']      ?? ''),
                (string) ($msg['subject'] ?? ''),
                (string) ($msg['body']    ?? ''),
            );
            $any_match = false;
            foreach ($haystacks as $h) {
                if (em_inbox_filters_match_one($h, $op, $val)) { $any_match = true; break; }
            }
            if (! $any_match) return false;
        } else {
            $haystack = (string) ($msg[$field] ?? '');
            if (! em_inbox_filters_match_one($haystack, $op, $val)) return false;
        }
    }
    return true;
}

function em_inbox_filters_match_one($haystack, $op, $needle) {
    $h = mb_strtolower($haystack);
    $n = mb_strtolower($needle);
    switch ($op) {
        case 'contains':    return ($n !== '') && mb_strpos($h, $n) !== false;
        case 'equals':      return $h === $n;
        case 'starts_with': return ($n !== '') && mb_strpos($h, $n) === 0;
        case 'ends_with':   return ($n !== '') && substr($h, -mb_strlen($n)) === $n;
        case 'matches':
            // Compile errors are suppressed during create (validated up front),
            // but pattern compilation can still fail at runtime if input is
            // weird — return false rather than throw.
            $pattern = '/' . str_replace('/', '\/', $needle) . '/iu';
            $r = @preg_match($pattern, $haystack);
            return $r === 1;
    }
    return false;
}

/* -------------------------------------------------------------------------
 * Action application
 * ------------------------------------------------------------------------- */

/**
 * Apply $actions to ($thread_id, $user_id) for an incoming message.
 * Returns an array of applied action descriptors for logging.
 */
function em_inbox_filters_apply_actions($actions, $thread_id, $user_id, $raw_row) {
    global $wpdb;
    $applied = array();
    $part = $wpdb->prefix . 'gdc_inbox_participants';
    $now  = current_time('mysql', 1);

    // Ensure participant row exists.
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $part WHERE thread_id = %d AND user_id = %d", $thread_id, $user_id
    ));
    if (! $exists) {
        $wpdb->insert($part, array(
            'thread_id'  => $thread_id, 'user_id' => $user_id,
            'is_read'    => 0,
            'created_at' => $now, 'updated_at' => $now,
        ));
    }

    foreach ($actions as $a) {
        $type = $a['type'] ?? '';
        $val  = $a['value'] ?? '';
        switch ($type) {
            case 'read':
                $wpdb->update($part, array('is_read' => 1, 'last_read_at' => $now, 'updated_at' => $now),
                    array('thread_id' => $thread_id, 'user_id' => $user_id));
                $applied[] = 'read';
                break;
            case 'star':
                $wpdb->update($part, array('is_starred' => 1, 'updated_at' => $now),
                    array('thread_id' => $thread_id, 'user_id' => $user_id));
                $applied[] = 'star';
                break;
            case 'archive':
                $wpdb->update($part, array('is_archived' => 1, 'updated_at' => $now),
                    array('thread_id' => $thread_id, 'user_id' => $user_id));
                $applied[] = 'archive';
                break;
            case 'trash':
                $wpdb->update($part, array('is_trashed' => 1, 'trashed_at' => $now, 'updated_at' => $now),
                    array('thread_id' => $thread_id, 'user_id' => $user_id));
                $applied[] = 'trash';
                break;
            case 'label':
                $label_id = (int) $val;
                if ($label_id <= 0) break;
                $labels = $wpdb->prefix . 'gdc_inbox_labels';
                $thread_labels = $wpdb->prefix . 'gdc_inbox_thread_labels';
                // Verify label belongs to this user.
                $own = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM $labels WHERE id = %d", $label_id
                ));
                if ($own !== (int) $user_id) break;
                // Idempotent attach. Schema: thread_id, label_id, user_id only.
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $thread_labels (thread_id, label_id, user_id) VALUES (%d, %d, %d)",
                    $thread_id, $label_id, $user_id
                ));
                $applied[] = 'label:' . $label_id;
                break;
            case 'forward':
                if (! is_email($val)) break;
                // Fire-and-forget via the outbound queue helper, marked as
                // an automated forward (X-EM-Forwarded-By header).
                if (function_exists('em_inbox_outq_submit_one')) {
                    $from    = (string) $raw_row['recipient'];
                    $subject = (string) $raw_row['subject'];
                    $body_p  = (string) $raw_row['body_plain'];
                    $body_h  = (string) $raw_row['body_html'];
                    $headers = array(
                        array('name' => 'X-EM-Forwarded-By', 'value' => 'em-filter:user=' . $user_id),
                    );
                    $message_id = sprintf('<%s.fwd.%s@%s>', time(), bin2hex(random_bytes(6)),
                        explode('@', $from)[1] ?? 'mail.local');
                    em_inbox_outq_submit_one($from, array($val),
                        '[Fwd] ' . $subject, $body_p, $body_h, $headers, $message_id, array());
                }
                $applied[] = 'forward:' . $val;
                break;
        }
    }
    return $applied;
}

/* -------------------------------------------------------------------------
 * Hook into the threading pipeline
 * ------------------------------------------------------------------------- */

add_action('em_inbox_message_inserted', 'em_inbox_filters_on_message', 35, 3);

function em_inbox_filters_on_message($msg_id, $thread_id, $raw) {
    global $wpdb;
    // Outbound mirror rows don't trigger filters — filters are for
    // incoming mail.
    $kind = $raw['kind'] ?? 'inbound';
    if ($kind !== 'inbound') return;

    // Map thread → owner user (filters are per-user).
    $threads = $wpdb->prefix . 'gdc_inbox_threads';
    $owner = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT owner_user_id FROM $threads WHERE id = %d", $thread_id
    ));
    if ($owner <= 0) return;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, conditions_json, actions_json FROM {$wpdb->prefix}gdc_inbox_filters
         WHERE user_id = %d AND enabled = 1
         ORDER BY sort_order ASC, id ASC",
        $owner
    ), ARRAY_A);
    if (! $rows) return;

    // Strip HTML from body so contains/matches work against plain text.
    $body_plain = (string) ($raw['body_plain'] ?? '');
    if ($body_plain === '' && ! empty($raw['body_html'])) {
        $body_plain = wp_strip_all_tags((string) $raw['body_html']);
    }
    $msg = array(
        'from'    => (string) ($raw['sender']    ?? ''),
        'to'      => (string) ($raw['recipient'] ?? ''),
        'subject' => (string) ($raw['subject']   ?? ''),
        'body'    => $body_plain,
    );

    foreach ($rows as $f) {
        $conds = json_decode((string) $f['conditions_json'], true) ?: array();
        if (! em_inbox_filters_evaluate_conditions($conds, $msg)) continue;
        $acts = json_decode((string) $f['actions_json'], true) ?: array();
        em_inbox_filters_apply_actions($acts, $thread_id, $owner, $raw);
        // Bookkeeping: bump match_count + last_matched_at.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}gdc_inbox_filters
             SET match_count = match_count + 1, last_matched_at = %s
             WHERE id = %d",
            current_time('mysql', 1), (int) $f['id']
        ));
    }
}
