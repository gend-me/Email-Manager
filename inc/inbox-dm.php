<?php
/**
 * Member Inbox: Direct Messages aggregator (slice 3c).
 *
 * Pluggable bridge between social-platform DMs (Instagram, X, FB
 * Messenger, LinkedIn, etc.) and a unified in-site inbox. Each
 * platform is a "provider" registered through em_inbox_dm_register_provider().
 *
 * Provider contract — every provider returns an array of callables:
 *   array(
 *     'name'          => 'Instagram',
 *     'icon'          => '📷',
 *     'color'         => '#E4405F',
 *     'connect_check' => fn($uid) → bool,           // is this user connected?
 *     'connect_url'   => fn($uid) → string,         // where to send them to OAuth
 *     'list_threads'  => fn($uid) → array of threads,
 *     'get_thread'    => fn($uid, $thread_id) → { thread, messages }|null,
 *     'send_reply'    => fn($uid, $thread_id, $content) → bool,
 *   )
 *
 * Stub providers register the four major platforms by default; their
 * connect_check returns false so the UI prompts the user to connect.
 * Real integrations drop in via the same em_inbox_dm_register_provider
 * call without touching this file.
 *
 * REST surface:
 *   GET  /em/v1/dm/providers          all + their connect status
 *   GET  /em/v1/dm/threads             aggregated across connected providers
 *   GET  /em/v1/dm/threads/{provider}:{id}
 *   POST /em/v1/dm/threads/{provider}:{id}/send  body: {content}
 *
 * BP subnav /messages/dm/ mounts the React panel via wp_footer.
 *
 * @package EmailManager
 * @since   1.7.0
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Provider registry
 * ------------------------------------------------------------------------- */

function em_inbox_dm_register_provider($slug, $config) {
    global $em_inbox_dm_providers;
    if (! is_array($em_inbox_dm_providers)) $em_inbox_dm_providers = array();
    $em_inbox_dm_providers[$slug] = array_merge(array(
        'slug'          => $slug,
        'name'          => $slug,
        'icon'          => '✉️',
        'color'         => '#888',
        'connect_check' => function () { return false; },
        'connect_url'   => function () { return ''; },
        'list_threads'  => function () { return array(); },
        'get_thread'    => function () { return null; },
        'send_reply'    => function () { return false; },
    ), $config);
}

function em_inbox_dm_get_providers() {
    global $em_inbox_dm_providers;
    return is_array($em_inbox_dm_providers) ? $em_inbox_dm_providers : array();
}

function em_inbox_dm_get_provider($slug) {
    $all = em_inbox_dm_get_providers();
    return isset($all[$slug]) ? $all[$slug] : null;
}

/**
 * Build the public-facing description of a provider for the React
 * panel — adds connect status + connect_url at call time.
 */
function em_inbox_dm_describe_provider($slug, $for_user_id) {
    $p = em_inbox_dm_get_provider($slug);
    if (! $p) return null;
    $connected = false;
    try {
        $connected = (bool) call_user_func($p['connect_check'], $for_user_id);
    } catch (\Throwable $e) { $connected = false; }
    $url = '';
    try {
        $url = (string) call_user_func($p['connect_url'], $for_user_id);
    } catch (\Throwable $e) {}
    return array(
        'slug'        => $slug,
        'name'        => $p['name'],
        'icon'        => $p['icon'],
        'color'       => $p['color'],
        'connected'   => $connected,
        'connect_url' => $url,
    );
}

/* -------------------------------------------------------------------------
 * Stub providers — register the major platforms with connect_check=false
 * so the UI surfaces "Connect <platform>" cards. Real integrations
 * override these via em_inbox_dm_register_provider($slug, $real_config).
 * ------------------------------------------------------------------------- */

add_action('plugins_loaded', 'em_inbox_dm_register_stub_providers', 50);
function em_inbox_dm_register_stub_providers() {
    $stub = function ($meta_key) {
        return function ($uid) use ($meta_key) {
            return (bool) get_user_meta($uid, $meta_key, true);
        };
    };
    $connect_via_settings = function ($slug) {
        return function ($uid) use ($slug) {
            return admin_url('admin.php?page=em-dm-connect&provider=' . rawurlencode($slug));
        };
    };
    em_inbox_dm_register_provider('instagram', array(
        'name'          => 'Instagram',
        'icon'          => '📷',
        'color'         => '#E4405F',
        'connect_check' => $stub('em_dm_instagram_token'),
        'connect_url'   => $connect_via_settings('instagram'),
    ));
    em_inbox_dm_register_provider('twitter', array(
        'name'          => 'X (Twitter)',
        'icon'          => '𝕏',
        'color'         => '#1DA1F2',
        'connect_check' => $stub('em_dm_twitter_token'),
        'connect_url'   => $connect_via_settings('twitter'),
    ));
    em_inbox_dm_register_provider('messenger', array(
        'name'          => 'Messenger',
        'icon'          => '💬',
        'color'         => '#0084FF',
        'connect_check' => $stub('em_dm_messenger_token'),
        'connect_url'   => $connect_via_settings('messenger'),
    ));
    em_inbox_dm_register_provider('linkedin', array(
        'name'          => 'LinkedIn',
        'icon'          => '🟦',
        'color'         => '#0A66C2',
        'connect_check' => $stub('em_dm_linkedin_token'),
        'connect_url'   => $connect_via_settings('linkedin'),
    ));
    em_inbox_dm_register_provider('tiktok', array(
        'name'          => 'TikTok',
        'icon'          => '🎵',
        'color'         => '#FE2C55',
        'connect_check' => $stub('em_dm_tiktok_token'),
        'connect_url'   => $connect_via_settings('tiktok'),
    ));
    do_action('em_inbox_dm_register_providers');
}

/* -------------------------------------------------------------------------
 * REST endpoints
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('em/v1', '/dm/providers', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_dm_rest_providers',
        'permission_callback' => 'em_inbox_dm_perm_logged_in',
    ));
    register_rest_route('em/v1', '/dm/threads', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'em_inbox_dm_rest_threads',
        'permission_callback' => 'em_inbox_dm_perm_logged_in',
    ));
    register_rest_route('em/v1', '/dm/threads/(?P<key>[a-z0-9_-]+):(?P<id>[A-Za-z0-9_\-]+)', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'em_inbox_dm_rest_thread',
            'permission_callback' => 'em_inbox_dm_perm_logged_in',
        ),
    ));
    register_rest_route('em/v1', '/dm/threads/(?P<key>[a-z0-9_-]+):(?P<id>[A-Za-z0-9_\-]+)/send', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'em_inbox_dm_rest_send',
        'permission_callback' => 'em_inbox_dm_perm_logged_in',
    ));
});

function em_inbox_dm_perm_logged_in() {
    return is_user_logged_in();
}

function em_inbox_dm_rest_providers(WP_REST_Request $r) {
    $u = wp_get_current_user();
    $uid = ($u && $u->ID) ? (int) $u->ID : 0;
    if ($uid <= 0) return new WP_Error('em_dm_no_user', 'Login required', array('status' => 401));
    $items = array();
    foreach (em_inbox_dm_get_providers() as $slug => $_p) {
        $items[] = em_inbox_dm_describe_provider($slug, $uid);
    }
    return rest_ensure_response(array('items' => $items));
}

function em_inbox_dm_rest_threads(WP_REST_Request $r) {
    $u = wp_get_current_user();
    $uid = ($u && $u->ID) ? (int) $u->ID : 0;
    if ($uid <= 0) return new WP_Error('em_dm_no_user', 'Login required', array('status' => 401));

    $threads = array();
    $providers = array();
    foreach (em_inbox_dm_get_providers() as $slug => $p) {
        $desc = em_inbox_dm_describe_provider($slug, $uid);
        $providers[] = $desc;
        if (! $desc['connected']) continue;
        try {
            $rows = (array) call_user_func($p['list_threads'], $uid);
        } catch (\Throwable $e) { $rows = array(); }
        foreach ($rows as $row) {
            $row = (array) $row;
            $row['provider'] = $slug;
            $row['provider_name']  = $p['name'];
            $row['provider_icon']  = $p['icon'];
            $row['provider_color'] = $p['color'];
            // Key the thread as "provider:id" so the rest of the
            // surface can identify it uniquely.
            $tid = isset($row['id']) ? (string) $row['id'] : '';
            $row['key'] = $slug . ':' . $tid;
            $threads[] = $row;
        }
    }
    // Sort by last_at desc when set.
    usort($threads, function ($a, $b) {
        $ax = isset($a['last_at']) ? (int) strtotime((string) $a['last_at']) : 0;
        $bx = isset($b['last_at']) ? (int) strtotime((string) $b['last_at']) : 0;
        return $bx - $ax;
    });
    return rest_ensure_response(array(
        'providers' => $providers,
        'threads'   => $threads,
    ));
}

function em_inbox_dm_rest_thread(WP_REST_Request $r) {
    $u = wp_get_current_user();
    $uid = ($u && $u->ID) ? (int) $u->ID : 0;
    if ($uid <= 0) return new WP_Error('em_dm_no_user', 'Login required', array('status' => 401));
    $slug = (string) $r['key'];
    $tid  = (string) $r['id'];
    $p = em_inbox_dm_get_provider($slug);
    if (! $p) return new WP_Error('em_dm_no_provider', 'unknown provider', array('status' => 404));
    try {
        $data = call_user_func($p['get_thread'], $uid, $tid);
    } catch (\Throwable $e) { $data = null; }
    if (! $data) return new WP_Error('em_dm_404', 'thread not found', array('status' => 404));
    return rest_ensure_response($data);
}

function em_inbox_dm_rest_send(WP_REST_Request $r) {
    $u = wp_get_current_user();
    $uid = ($u && $u->ID) ? (int) $u->ID : 0;
    if ($uid <= 0) return new WP_Error('em_dm_no_user', 'Login required', array('status' => 401));
    $slug = (string) $r['key'];
    $tid  = (string) $r['id'];
    $body = $r->get_json_params();
    if (! is_array($body)) $body = $r->get_params() ?: array();
    $content = trim((string) ($body['content'] ?? ''));
    if ($content === '') return new WP_Error('em_dm_empty', 'message required', array('status' => 400));
    $p = em_inbox_dm_get_provider($slug);
    if (! $p) return new WP_Error('em_dm_no_provider', 'unknown provider', array('status' => 404));
    try {
        $ok = call_user_func($p['send_reply'], $uid, $tid, $content);
    } catch (\Throwable $e) { $ok = false; }
    if (! $ok) return new WP_Error('em_dm_send_failed', 'send failed', array('status' => 500));
    return rest_ensure_response(array('ok' => true));
}

/* -------------------------------------------------------------------------
 * BP subnav + screen — /members/<user>/messages/dm/
 * ------------------------------------------------------------------------- */

add_action('bp_setup_nav', 'em_inbox_dm_setup_subnav', 110);
function em_inbox_dm_setup_subnav() {
    if (! function_exists('bp_core_new_subnav_item') || ! function_exists('bp_get_messages_slug')) return;
    if (! function_exists('bp_loggedin_user_id') || ! function_exists('bp_displayed_user_domain')) return;
    $displayed_uid = bp_displayed_user_id();
    if ($displayed_uid <= 0) return;
    $own_profile = function_exists('bp_is_my_profile') ? bp_is_my_profile() : ($displayed_uid === get_current_user_id());
    if (! $own_profile) return;

    bp_core_new_subnav_item(array(
        'name'             => __('Direct Messages', 'email-manager'),
        'slug'             => 'dm',
        'parent_url'       => trailingslashit(bp_displayed_user_domain() . bp_get_messages_slug()),
        'parent_slug'      => bp_get_messages_slug(),
        'screen_function'  => 'em_inbox_dm_screen',
        'position'         => 6,
        'user_has_access'  => true,
        'show_in_admin_bar'=> false,
    ));
}

function em_inbox_dm_screen() {
    add_action('bp_template_title',   '__return_empty_string');
    add_action('bp_template_content', 'em_inbox_dm_screen_content');
    bp_core_load_template(array('members/single/plugins'));
}

function em_inbox_dm_screen_content() {
    echo '<div class="em-dm-wrap em-dm-wrap--frontend"><div id="em-dm-root" data-loading="1">'
       . esc_html__('Loading direct messages…', 'email-manager')
       . '</div></div>';
}

/* -------------------------------------------------------------------------
 * Frontend asset enqueue
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'em_inbox_dm_enqueue', 22);
function em_inbox_dm_enqueue() {
    if (! function_exists('bp_is_messages_component') || ! bp_is_messages_component()) return;
    if (! is_user_logged_in()) return;

    wp_enqueue_style(
        'em-dm-panel',
        EMAIL_MANAGER_URL . 'assets/dm-panel.css',
        array(),
        EMAIL_MANAGER_VERSION
    );
    wp_enqueue_script(
        'em-dm-panel',
        EMAIL_MANAGER_URL . 'assets/dm-panel.js',
        array('wp-element', 'wp-i18n', 'wp-api-fetch'),
        EMAIL_MANAGER_VERSION,
        true
    );
    wp_localize_script('em-dm-panel', 'EM_DM_CONFIG', array(
        'restRoot'      => esc_url_raw(rest_url('em/v1/dm/')),
        'nonce'         => wp_create_nonce('wp_rest'),
    ));
}
