<?php
/**
 * Member Inbox: full-text search across messages (slice 2h).
 *
 * Adds a FULLTEXT key on wp_gdc_inbox_messages(subject, sender,
 * body_plain) and exposes GET /em/v1/inbox/search?q=…&inbox=…&limit=N.
 *
 * MySQL/MariaDB FULLTEXT requires the table engine to support it
 * (InnoDB since 5.6 — every modern WP install). Migration is gated so
 * the index is added once + skipped on later boots.
 *
 * Permission gate matches the rest of the inbox surface: admin can
 * search any inbox; non-admins are scoped to addresses they own
 * (em_inbox_address or matching user_email).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_SEARCH_DB_VERSION', '1.0.0');

/* -------------------------------------------------------------------------
 * Schema: add FULLTEXT key
 * ------------------------------------------------------------------------- */

function em_inbox_search_maybe_index() {
    if (get_option('em_inbox_search_db_version') === EM_INBOX_SEARCH_DB_VERSION) return;
    global $wpdb;
    $table = $wpdb->prefix . 'gdc_inbox_messages';
    $existing = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'ft_inbox_search'");
    if (empty($existing)) {
        $wpdb->query("ALTER TABLE $table ADD FULLTEXT KEY ft_inbox_search (subject, sender, body_plain)");
    }
    update_option('em_inbox_search_db_version', EM_INBOX_SEARCH_DB_VERSION);
}
add_action('admin_init',    'em_inbox_search_maybe_index');
add_action('rest_api_init', 'em_inbox_search_maybe_index');

/* -------------------------------------------------------------------------
 * REST: GET /em/v1/inbox/search
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/search', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_search_handler',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args' => array(
            'q'     => array('type' => 'string', 'required' => true),
            'inbox' => array('type' => 'string'),
            'limit' => array('type' => 'integer', 'default' => 25),
        ),
    ));
});

function em_inbox_search_handler(WP_REST_Request $request) {
    global $wpdb;
    $q     = trim((string) $request->get_param('q'));
    $inbox = trim((string) $request->get_param('inbox'));
    $limit = max(1, min(100, (int) $request->get_param('limit')));
    if (mb_strlen($q) < 2) {
        return rest_ensure_response(array('items' => array(), 'query' => $q));
    }

    // Inbox scoping
    if ($inbox !== '' && function_exists('em_inbox_user_can_read_inbox') && ! em_inbox_user_can_read_inbox($inbox)) {
        return new WP_Error('em_inbox_search_forbidden', 'Cannot search that inbox', array('status' => 403));
    }
    $inbox_clause = '';
    $args         = array($q, $q);  // MATCH used twice (SELECT score + WHERE)
    if ($inbox !== '') {
        $inbox_clause = ' AND m.recipient = %s';
        $args[]       = $inbox;
    } elseif (! current_user_can('manage_options')) {
        // Non-admin without explicit inbox: limit to addresses they own.
        $u = wp_get_current_user();
        if (! $u || ! $u->ID) return rest_ensure_response(array('items' => array(), 'query' => $q));
        $candidates = array();
        if ($u->user_email) $candidates[] = $u->user_email;
        $meta = get_user_meta($u->ID, 'em_inbox_address', true);
        if ($meta) $candidates[] = $meta;
        $candidates = array_unique(array_filter($candidates));
        if (empty($candidates)) return rest_ensure_response(array('items' => array(), 'query' => $q));
        $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
        $inbox_clause = " AND m.recipient IN ($placeholders)";
        $args = array_merge($args, $candidates);
    }
    // Pull a generous superset from FULLTEXT so we have something to
    // re-rank PHP-side. The final list is capped at $limit after the
    // sender-boost + thread-dedup pass.
    $fetch_n = max($limit * 4, 100);
    $args[] = $fetch_n;

    $messages = $wpdb->prefix . 'gdc_inbox_messages';
    $threads  = $wpdb->prefix . 'gdc_inbox_threads';
    $sql = $wpdb->prepare(
        "SELECT
            m.id          AS message_id,
            m.thread_id   AS thread_id,
            m.subject     AS subject,
            m.sender      AS sender,
            m.recipient   AS recipient,
            m.received_at AS received_at,
            m.body_plain  AS body_plain,
            t.subject_first AS thread_subject,
            t.message_count AS thread_message_count,
            MATCH(m.subject, m.sender, m.body_plain) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
         FROM $messages m
         LEFT JOIN $threads t ON t.id = m.thread_id
         WHERE MATCH(m.subject, m.sender, m.body_plain) AGAINST (%s IN NATURAL LANGUAGE MODE)
           $inbox_clause
         ORDER BY score DESC, m.received_at DESC
         LIMIT %d",
        ...$args
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);

    // ── Re-rank: sender/subject boost + snippet + dedup-by-thread ─────
    $rows = em_inbox_search_rerank($rows ?: array(), $q, $limit);
    return rest_ensure_response(array(
        'items' => $rows,
        'query' => $q,
    ));
}

/* -------------------------------------------------------------------------
 * Re-ranking helpers (slice 2ff)
 * ------------------------------------------------------------------------- */

/**
 * Tokenize a free-text query into lowercase search terms. Quoted
 * phrases survive as single tokens. Stop tokens shorter than 2 chars
 * (matches the inner FULLTEXT threshold).
 */
function em_inbox_search_tokenize($q) {
    $tokens = array();
    if (preg_match_all('/"([^"]+)"|(\S+)/u', $q, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $tok = ! empty($hit[1]) ? $hit[1] : $hit[2];
            $tok = mb_strtolower(trim($tok));
            if (mb_strlen($tok) >= 2) $tokens[] = $tok;
        }
    }
    return array_values(array_unique($tokens));
}

/**
 * Build a snippet of ~$window chars around the first term hit in
 * $haystack, with each token wrapped in <mark>...</mark>. If no token
 * matches, fall back to the prefix.
 */
function em_inbox_search_snippet($haystack, $tokens, $window = 160) {
    $haystack = (string) $haystack;
    if ($haystack === '') return '';
    $lower = mb_strtolower($haystack);
    $first_pos = false;
    foreach ($tokens as $t) {
        $p = mb_strpos($lower, $t);
        if ($p !== false && ($first_pos === false || $p < $first_pos)) $first_pos = $p;
    }
    if ($first_pos === false) {
        $snippet = mb_substr($haystack, 0, $window);
    } else {
        $start = max(0, $first_pos - intdiv($window, 3));
        $snippet = mb_substr($haystack, $start, $window);
        if ($start > 0) $snippet = '…' . $snippet;
    }
    if (mb_strlen($snippet) >= $window) $snippet .= '…';
    // Highlight every token (case-insensitive). Build the regex
    // carefully so token punctuation can't break out.
    $esc = array_map(function ($t) { return preg_quote($t, '/'); }, $tokens);
    if (! empty($esc)) {
        $pattern = '/(' . implode('|', $esc) . ')/iu';
        $snippet = preg_replace($pattern, '<mark>$1</mark>', $snippet);
    }
    return $snippet;
}

/**
 * Boost score based on which field matched. Sender/subject matches are
 * worth more than body matches. Multiple-token hits compound.
 */
function em_inbox_search_boost_score($base_score, $row, $tokens) {
    $score = (float) $base_score;
    $subject = mb_strtolower((string) $row['subject']);
    $sender  = mb_strtolower((string) $row['sender']);
    $body    = mb_strtolower((string) $row['body_plain']);
    foreach ($tokens as $t) {
        if ($t === '') continue;
        if (mb_strpos($sender,  $t) !== false) $score += 2.0;  // sender match: heavy boost
        if (mb_strpos($subject, $t) !== false) $score += 1.5;  // subject match: moderate
        if (mb_strpos($body,    $t) !== false) $score += 0.25; // body: small (already counted in FT)
    }
    return $score;
}

function em_inbox_search_rerank($rows, $q, $limit) {
    $tokens = em_inbox_search_tokenize($q);
    if (empty($tokens) || empty($rows)) return array();

    // Compute boosted score + snippet, group by thread to dedup.
    $by_thread = array();
    foreach ($rows as $r) {
        $tid = (int) $r['thread_id'];
        $boosted = em_inbox_search_boost_score($r['score'], $r, $tokens);
        $body_plain = (string) $r['body_plain'];
        // Strip any leftover HTML before snippet so quoted-reply markup
        // doesn't show through (rare but possible if body_plain ever
        // shared content with body_html).
        $body_plain = wp_strip_all_tags($body_plain);
        $snippet = em_inbox_search_snippet($body_plain, $tokens);
        $candidate = array(
            'message_id'           => (int) $r['message_id'],
            'thread_id'            => $tid,
            'subject'              => (string) $r['subject'],
            'sender'               => (string) $r['sender'],
            'recipient'            => (string) $r['recipient'],
            'received_at'          => (string) $r['received_at'],
            'snippet'              => $snippet,
            'thread_subject'       => (string) ($r['thread_subject'] ?? ''),
            'thread_message_count' => (int) ($r['thread_message_count'] ?? 0),
            'score'                => $boosted,
            'matched_tokens'       => $tokens,
        );
        if (! isset($by_thread[$tid]) || $candidate['score'] > $by_thread[$tid]['score']) {
            $by_thread[$tid] = $candidate;
        }
    }
    $items = array_values($by_thread);
    usort($items, function ($a, $b) {
        if ($a['score'] === $b['score']) return strcmp($b['received_at'], $a['received_at']);
        return ($a['score'] < $b['score']) ? 1 : -1;
    });
    return array_slice($items, 0, $limit);
}
