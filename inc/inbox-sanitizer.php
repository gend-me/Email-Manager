<?php
/**
 * Member Inbox: HTML body sanitizer + default-off remote-image blocking.
 *
 * Two concerns, both privacy-and-security wins:
 *
 *   1) The existing wp_kses allowlist (em_inbox_allowed_html_for_message
 *      in inbox-rest-list.php) is generous on style attributes. This
 *      module replaces it with a stricter version that:
 *        - strips javascript:, data:, vbscript: URLs
 *        - strips inline event handlers (already by wp_kses, but explicit)
 *        - strips <style>, <link>, <meta>, <object>, <embed>, <iframe>
 *
 *   2) Remote <img> loads are the classic mail-tracking vector:
 *      open-tracking pixels, sender IP / user-agent leak, "yes that
 *      address is alive" confirmation for spam farms. Modern mail
 *      clients block by default + offer "Always show from …" overrides.
 *      em_inbox_block_remote_images() rewrites every http(s) <img src>
 *      to a transparent 1×1 with data-blocked-src — the React UI
 *      surfaces a banner with "Show in this message" + "Always from
 *      sender" buttons.
 *
 *      cid: images (inline multipart) are left untouched — they're
 *      already in the message payload, no remote fetch involved.
 *
 * Per-user allowlist lives in user_meta key `em_inbox_show_images_from`
 * (JSON array of normalized lowercased sender addresses).
 *
 * @package EmailManager
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Stricter wp_kses allowlist
 * ------------------------------------------------------------------------- */

/**
 * Returns a stricter wp_kses allowed_html map than slice 2c's. Drops
 * style attributes on non-essential tags, keeps the small set required
 * for readable email content.
 */
function em_inbox_strict_allowed_html() {
    return array(
        'a'      => array('href' => true, 'title' => true, 'rel' => true, 'target' => true),
        'b'      => array(), 'strong' => array(),
        'i'      => array(), 'em'     => array(),
        'u'      => array(), 'br'     => array(),
        'p'      => array('class' => true),
        'div'    => array('class' => true),
        'span'   => array('class' => true),
        'ul'     => array(), 'ol' => array(), 'li' => array(),
        'blockquote' => array('cite' => true),
        'pre'    => array(), 'code' => array(),
        'h1'     => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
        'img'    => array(
            'src'    => true,
            'alt'    => true,
            'width'  => true,
            'height' => true,
            'class'  => true,
            // data-blocked-src is the placeholder for blocked remote images
            // — we explicitly allow it so wp_kses doesn't strip it.
            'data-blocked-src' => true,
        ),
        'table'  => array('cellpadding' => true, 'cellspacing' => true, 'border' => true),
        'thead'  => array(), 'tbody' => array(), 'tr' => array(),
        'td'     => array('colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true),
        'th'     => array('colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true),
        'hr'     => array(),
    );
}

/**
 * Strip dangerous URL schemes after wp_kses (defense in depth — wp_kses
 * already blocks these but mail HTML is wild west).
 */
function em_inbox_sanitize_html($html) {
    if ($html === '' || $html === null) return '';
    // wp_kses defaults strip cid: URLs (inline multipart attachment refs).
    // Pass our own protocol allowlist (default WP set + cid) via the
    // third arg so they're preserved inside the inbox-render path.
    $protocols = array_merge(wp_allowed_protocols(), array('cid'));
    $clean = wp_kses($html, em_inbox_strict_allowed_html(), $protocols);
    // Belt-and-suspenders: scrub any javascript:/data:/vbscript:/file: URLs
    // that snuck through (e.g. via a weird percent-encoding).
    $clean = preg_replace_callback(
        '/(href|src|cite)\s*=\s*(["\'])\s*(javascript|data|vbscript|file)\s*:[^"\']*\2/i',
        function ($m) { return $m[1] . '="#blocked-' . strtolower($m[3]) . '"'; },
        $clean
    );
    return $clean;
}

/* -------------------------------------------------------------------------
 * Remote-image blocking
 * ------------------------------------------------------------------------- */

// 1×1 transparent SVG data URL — used as the placeholder src for
// blocked remote images so the layout doesn't collapse.
define('EM_INBOX_BLOCKED_IMG_PIXEL',
    'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect width="16" height="16" fill="%23e8e8e8"/></svg>'));

/**
 * Returns true if the current user has marked $sender as "always show
 * images from". Sender is normalized lowercase.
 */
function em_inbox_user_shows_images_from($user_id, $sender) {
    if (! $user_id || ! $sender) return false;
    $list = get_user_meta((int) $user_id, 'em_inbox_show_images_from', true);
    if (! is_array($list)) return false;
    return in_array(strtolower(trim($sender)), $list, true);
}

/**
 * Rewrite every <img src="http(s)://..."> in $html to a blocked
 * placeholder. cid:, data: (already sanitized above), and any non-
 * remote schemes are left as-is.
 *
 * Returns array(rewritten_html, blocked_count).
 */
function em_inbox_block_remote_images($html) {
    if ($html === '' || $html === null) return array('', 0);
    $count = 0;
    $out = preg_replace_callback(
        '/<img\b([^>]*?)\bsrc\s*=\s*(["\'])(https?:\/\/[^"\']*)\2([^>]*?)>/i',
        function ($m) use (&$count) {
            $count++;
            $before = $m[1]; $url = $m[3]; $after = $m[4];
            // Strip any pre-existing data-blocked-src to avoid stacking.
            $before = preg_replace('/\s+data-blocked-src\s*=\s*(["\'])[^"\']*\1/i', '', $before);
            $after  = preg_replace('/\s+data-blocked-src\s*=\s*(["\'])[^"\']*\1/i', '', $after);
            return '<img' . $before
                 . ' src="' . EM_INBOX_BLOCKED_IMG_PIXEL . '"'
                 . ' data-blocked-src="' . esc_attr($url) . '"'
                 . ' class="em-inbox-blocked-img"'
                 . ' alt="(image blocked)"'
                 . $after . '>';
        },
        $html
    );
    return array($out !== null ? $out : $html, $count);
}

/* -------------------------------------------------------------------------
 * REST: toggle per-sender "always show images"
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/inbox/senders/show-images', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_toggle_show_images',
        'permission_callback' => function () { return is_user_logged_in(); },
    ));
});

function em_inbox_toggle_show_images(WP_REST_Request $request) {
    $body   = $request->get_json_params() ?: array();
    $sender = isset($body['sender']) ? strtolower(trim((string) $body['sender'])) : '';
    $show   = ! empty($body['show']);
    if ($sender === '' || ! is_email($sender)) {
        return new WP_Error('em_inbox_show_images_bad', 'sender required', array('status' => 400));
    }
    $user = wp_get_current_user();
    if (! $user || ! $user->ID) {
        return new WP_Error('em_inbox_show_images_no_user', 'Login required', array('status' => 401));
    }
    $list = get_user_meta($user->ID, 'em_inbox_show_images_from', true);
    if (! is_array($list)) $list = array();
    $list = array_values(array_filter($list, function ($v) use ($sender) { return $v !== $sender; }));
    if ($show) $list[] = $sender;
    update_user_meta($user->ID, 'em_inbox_show_images_from', $list);
    return rest_ensure_response(array('ok' => true, 'sender' => $sender, 'show' => $show, 'count' => count($list)));
}
