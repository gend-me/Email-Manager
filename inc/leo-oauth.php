<?php
/**
 * LEO OAuth client — uses the SAME OAuth client + token state as the LEO
 * plugin so a single connection powers both plugins.
 *
 * Shared with LEO:
 *   - User meta key:        gend_oauth_token  (+ gend_oauth_refresh_token)
 *   - Cookie name:          gend_oauth_token  (base64-encoded, httpOnly)
 *   - Site options:         aipa_oauth_client_id, aipa_oauth_client_secret,
 *                           aipa_central_hub_url, aipa_central_hub_oauth_token
 *   - Bridge URL:           https://gend.me/oauth-bridge/  (LEO already runs this)
 *
 * When the LEO plugin is loaded on the same site (i.e. on gend.me), all
 * resolution / save / revoke calls delegate to AIPA_GenD_OAuth so the two
 * plugins are byte-for-byte identical and a login from either is shared.
 *
 * When LEO is NOT installed (any external site running email-manager only),
 * we replicate LEO's flow ourselves but write to the same key names so a
 * future LEO install picks up existing tokens transparently.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Leo_OAuth
{
    const USER_META_TOKEN   = 'gend_oauth_token';
    const USER_META_REFRESH = 'gend_oauth_refresh_token';
    const COOKIE_NAME       = 'gend_oauth_token';
    const REDIRECT_URI      = 'https://gend.me/oauth-bridge/';
    const HUB_DEFAULT       = 'https://gend.me';
    const SITE_TOKEN_OPT    = 'aipa_central_hub_oauth_token';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route('em/v1', '/oauth/exchange', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_exchange'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('em/v1', '/oauth/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('em/v1', '/oauth/revoke', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_revoke'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('em/v1', '/oauth/balance', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_balance'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* ================================================================
       Configuration accessors — defer to LEO when present, then to
       gend.me-shared site options, then to local email-manager fallback.
       ================================================================ */

    public static function hub_url()
    {
        if (class_exists('AIPA_GenD_OAuth')) return AIPA_GenD_OAuth::hub_url();
        $opt = (string) get_site_option('aipa_central_hub_url', '');
        return $opt !== '' ? rtrim($opt, '/') : self::HUB_DEFAULT;
    }

    public static function client_id()
    {
        if (class_exists('AIPA_GenD_OAuth')) return AIPA_GenD_OAuth::client_id();
        $shared = (string) get_site_option('aipa_oauth_client_id', '');
        if ($shared !== '') return $shared;
        $settings = class_exists('EM_Leo') ? EM_Leo::get_settings() : [];
        return isset($settings['oauth_client_id']) ? (string) $settings['oauth_client_id'] : '';
    }

    public static function client_secret()
    {
        $shared = (string) get_site_option('aipa_oauth_client_secret', '');
        if ($shared !== '') return $shared;
        $settings = class_exists('EM_Leo') ? EM_Leo::get_settings() : [];
        return isset($settings['oauth_client_secret']) ? (string) $settings['oauth_client_secret'] : '';
    }

    /** Whether the LEO plugin (and therefore the canonical OAuth helpers) is loaded. */
    public static function leo_native()
    {
        return class_exists('AIPA_GenD_OAuth');
    }

    /* ================================================================
       Token resolution — single source of truth across both plugins.
       ================================================================ */

    public static function resolve_user_token($user_id = null)
    {
        if (self::leo_native()) {
            return AIPA_GenD_OAuth::get_token($user_id);
        }

        // Same priority order as LEO: header → cookie → user meta → site fallback
        if (!empty($_SERVER['HTTP_X_GEND_TOKEN'])) {
            return sanitize_text_field($_SERVER['HTTP_X_GEND_TOKEN']);
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])
            && preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            return $m[1];
        }
        $c = self::cookie_token();
        if ($c !== '') return $c;

        $uid = $user_id ?: get_current_user_id();
        if ($uid) {
            $t = (string) get_user_meta($uid, self::USER_META_TOKEN, true);
            if ($t !== '') return $t;
        }
        return (string) get_site_option(self::SITE_TOKEN_OPT, '');
    }

    public static function is_user_connected($user_id = null)
    {
        return self::resolve_user_token($user_id) !== '';
    }

    /* ================================================================
       Cookie + token persistence
       ================================================================ */

    public static function set_cookie($token, $expires_in = 3600)
    {
        // LEO already provides this exact helper — use it for byte-parity
        // with the cookie LEO sets (same SameSite / httponly / secure rules).
        if (function_exists('aipa_oauth_set_token_cookie')) {
            aipa_oauth_set_token_cookie($token, $expires_in);
            return;
        }

        $name   = self::COOKIE_NAME;
        $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        if ($token === '') {
            setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            return;
        }
        setcookie($name, base64_encode($token), [
            'expires'  => time() + max(60, (int) $expires_in),
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function cookie_token()
    {
        if (function_exists('aipa_oauth_cookie_token')) return aipa_oauth_cookie_token();
        if (empty($_COOKIE[self::COOKIE_NAME])) return '';
        $raw = (string) $_COOKIE[self::COOKIE_NAME];
        $dec = base64_decode($raw, true);
        if ($dec === false) return '';
        if (!preg_match('/^[\x20-\x7E]{8,4096}$/', $dec)) return '';
        return $dec;
    }

    public static function save_token($access_token, $refresh_token = '', $expires_in = 3600)
    {
        if (self::leo_native()) {
            AIPA_GenD_OAuth::save_token($access_token, null, $refresh_token);
            // LEO sets its own cookie inside save_token paths; defensively also set it here
            self::set_cookie($access_token, $expires_in);
            return;
        }

        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            update_user_meta($uid, self::USER_META_TOKEN, $access_token);
            if ($refresh_token !== '') {
                update_user_meta($uid, self::USER_META_REFRESH, $refresh_token);
            }
        } else {
            update_site_option(self::SITE_TOKEN_OPT, $access_token);
        }
        self::set_cookie($access_token, $expires_in);
    }

    public static function revoke_token($user_id = null)
    {
        if (self::leo_native() && method_exists('AIPA_GenD_OAuth', 'revoke_token')) {
            AIPA_GenD_OAuth::revoke_token();
            self::set_cookie('');
            return;
        }
        $uid = $user_id ?: get_current_user_id();
        if ($uid) {
            delete_user_meta($uid, self::USER_META_TOKEN);
            delete_user_meta($uid, self::USER_META_REFRESH);
        }
        self::set_cookie('');
    }

    /* ================================================================
       REST handlers
       ================================================================ */

    public function rest_status(WP_REST_Request $request)
    {
        return new WP_REST_Response([
            'connected'  => self::is_user_connected(),
            'hub_url'    => self::hub_url(),
            'client_id'  => self::client_id(),
            'user_id'    => get_current_user_id(),
            'configured' => self::client_id() !== '',
            'native'     => self::leo_native(),
        ], 200);
    }

    public function rest_revoke(WP_REST_Request $request)
    {
        self::revoke_token();
        return new WP_REST_Response(['success' => true], 200);
    }

    public function rest_exchange(WP_REST_Request $request)
    {
        $code          = sanitize_text_field((string) $request->get_param('code'));
        $redirect_uri  = sanitize_url((string) $request->get_param('redirect_uri'));
        $code_verifier = (string) $request->get_param('code_verifier');

        if ($code === '') {
            return new WP_Error('missing_code', 'Authorization code is required', ['status' => 400]);
        }
        if ($code_verifier !== '' && !preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $code_verifier)) {
            return new WP_Error('invalid_verifier', 'code_verifier has an invalid format.', ['status' => 400]);
        }

        $client_id     = self::client_id();
        $client_secret = self::client_secret();
        if ($client_id === '') {
            return new WP_Error('missing_config', 'OAuth Client ID is not configured.', ['status' => 500]);
        }

        $token_url = self::hub_url() . '/oauth/token';
        $body = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri ?: self::REDIRECT_URI,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ];
        if ($code_verifier !== '') {
            $body['code_verifier'] = $code_verifier;
        }

        $response = wp_remote_post($token_url, [
            'timeout' => 15,
            'body'    => $body,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('exchange_failed', $response->get_error_message(), ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = trim(str_replace("\xEF\xBB\xBF", '', wp_remote_retrieve_body($response)));
        $payload = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE && preg_match('/(\{.*\})/s', $raw, $m)) {
            $payload = json_decode($m[1], true);
        }
        if ($status !== 200 || empty($payload['access_token'])) {
            $msg = isset($payload['error_description']) ? $payload['error_description']
                 : (isset($payload['error']) ? $payload['error'] : ('Token exchange failed (HTTP ' . $status . ')'));
            return new WP_REST_Response([
                'error'   => isset($payload['error']) ? $payload['error'] : 'exchange_failed',
                'message' => $msg,
            ], $status ?: 502);
        }

        $access_token  = (string) $payload['access_token'];
        $refresh_token = isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : '';
        $expires_in    = isset($payload['expires_in']) ? (int) $payload['expires_in'] : 3600;

        self::save_token($access_token, $refresh_token, $expires_in);

        return new WP_REST_Response([
            'access_token' => $access_token,
            'token_type'   => isset($payload['token_type']) ? $payload['token_type'] : 'Bearer',
            'expires_in'   => $expires_in,
            'has_refresh'  => $refresh_token !== '',
        ], 200);
    }

    public function rest_balance(WP_REST_Request $request)
    {
        $token = self::resolve_user_token();
        if ($token === '') {
            return new WP_REST_Response([
                'connected' => false,
                'message'   => 'Not connected to LEO.',
            ], 200);
        }

        $hub = self::hub_url();
        $url = $hub . '/wp-json/aipa/v1/ai-proxy/balance';
        $url = apply_filters('em_leo_endpoint_balance', $url);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'X-GenD-Token'  => $token,
                'X-EM-Site'     => home_url(),
                'Accept'        => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            return new WP_REST_Response(['connected' => true, 'error' => $response->get_error_message()], 502);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 401 || $code === 403) {
            self::revoke_token();
            return new WP_REST_Response(['connected' => false, 'message' => 'Session expired'], 200);
        }
        if ($code !== 200 || !is_array($payload)) {
            return new WP_REST_Response(['connected' => true, 'error' => 'Balance request failed'], 502);
        }

        return new WP_REST_Response([
            'connected'      => true,
            'balance'        => isset($payload['balance']) ? (float) $payload['balance'] : 0,
            'last_deduction' => isset($payload['last_deduction']) ? $payload['last_deduction'] : null,
            'user_id'        => isset($payload['user_id']) ? (int) $payload['user_id'] : 0,
            'currency'       => isset($payload['currency']) ? $payload['currency'] : 'USD',
            'token_label'    => isset($payload['token_label']) ? $payload['token_label'] : 'Leo Tokens',
            'topup_url'      => $hub . '/leo/tokens',
            'native'         => self::leo_native(),
        ], 200);
    }
}

new EM_Leo_OAuth();
