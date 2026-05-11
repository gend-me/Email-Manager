<?php
/**
 * LEO AI integration — proxy chatform "Prompt Response" questions through the
 * LEO plugin on gend.me. Each call is paid via the user's LEO token balance.
 *
 * Assumed LEO HTTP API (configurable in admin):
 *   POST {base}/leo/v1/complete   { prompt, max_tokens?, temperature?, model? }
 *     Headers: Authorization: Bearer <site_token>, X-EM-Site: <site_url>
 *     200 -> { success: true,  data: { text, tokens_used, balance } }
 *     402 -> { success: false, data: { code: 'insufficient_tokens', message } }
 *   GET  {base}/leo/v1/balance     -> { success, data: { balance, tier } }
 *
 * If the real LEO endpoint differs, change EM_LEO_DEFAULT_BASE or override
 * `em_leo_endpoint_base` / `em_leo_endpoint_complete` filters.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Leo
{
    const SETTINGS_OPTION = 'em_leo_settings';
    const DEFAULT_BASE    = 'https://gend.me/wp-json';
    const PURCHASE_URL    = 'https://gend.me/leo/tokens';
    const HUB_DEFAULT     = 'https://gend.me';

    public function __construct()
    {
        add_action('admin_post_em_save_leo_settings',       [$this, 'handle_save_settings']);
        add_action('wp_ajax_em_leo_complete',               [$this, 'ajax_complete']);
        add_action('wp_ajax_nopriv_em_leo_complete',        [$this, 'ajax_complete']);
        add_action('wp_ajax_em_leo_check_balance',          [$this, 'ajax_check_balance']);
    }

    /* ================================================================
       Settings
       ================================================================ */

    public static function default_settings()
    {
        return [
            'enabled'             => 0,
            'base_url'            => self::DEFAULT_BASE,
            'site_token'          => '',
            'default_model'       => 'leo-default',
            'max_tokens'          => 600,
            'temperature'         => 0.7,
            'oauth_client_id'     => '',
            'oauth_client_secret' => '',
        ];
    }

    public static function get_settings()
    {
        $stored = get_option(self::SETTINGS_OPTION, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::default_settings());
    }

    public static function is_enabled()
    {
        $s = self::get_settings();
        return !empty($s['enabled']) && !empty($s['site_token']);
    }

    public function handle_save_settings()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('em_save_leo_settings');

        $settings = [
            'enabled'             => !empty($_POST['enabled']) ? 1 : 0,
            'base_url'            => esc_url_raw(wp_unslash($_POST['base_url'] ?? self::DEFAULT_BASE)),
            'site_token'          => sanitize_text_field(wp_unslash($_POST['site_token'] ?? '')),
            'default_model'       => sanitize_text_field(wp_unslash($_POST['default_model'] ?? 'leo-default')),
            'max_tokens'          => max(1, min(4000, absint($_POST['max_tokens'] ?? 600))),
            'temperature'         => max(0, min(2, floatval($_POST['temperature'] ?? 0.7))),
            'oauth_client_id'     => sanitize_text_field(wp_unslash($_POST['oauth_client_id'] ?? '')),
            'oauth_client_secret' => sanitize_text_field(wp_unslash($_POST['oauth_client_secret'] ?? '')),
        ];
        update_option(self::SETTINGS_OPTION, $settings);

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'leo_settings'], admin_url('admin.php')));
        exit;
    }

    /* ================================================================
       API
       ================================================================ */

    /**
     * Resolve which Bearer token to use. Priority:
     *   1. $args['paying_user_id']  -> that user's OAuth token (`_em_leo_oauth_token`),
     *                                  fallback to legacy `_em_leo_user_token`
     *   2. $args['use_request_user'] -> headers/cookie/current-user OAuth chain
     *                                   via EM_Leo_OAuth::resolve_user_token()
     *   3. settings['site_token']    (final fallback)
     *
     * Returns array { token, user_id, used_fallback }.
     */
    public static function resolve_token($args = [])
    {
        $settings = self::get_settings();
        $site_token = $settings['site_token'];

        if (!empty($args['paying_user_id'])) {
            $uid = (int) $args['paying_user_id'];
            // Prefer LEO's shared user meta key — same one the LEO plugin's
            // OAuth flow writes to. Falls back to legacy keys for any prior
            // tokens stored before the share was wired up.
            $shared_token = (string) get_user_meta($uid, 'gend_oauth_token', true);
            if ($shared_token !== '') {
                return ['token' => $shared_token, 'user_id' => $uid, 'used_fallback' => false];
            }
            $legacy_oauth = (string) get_user_meta($uid, '_em_leo_oauth_token', true);
            if ($legacy_oauth !== '') {
                return ['token' => $legacy_oauth, 'user_id' => $uid, 'used_fallback' => false];
            }
            $legacy_manual = (string) get_user_meta($uid, '_em_leo_user_token', true);
            if ($legacy_manual !== '') {
                return ['token' => $legacy_manual, 'user_id' => $uid, 'used_fallback' => false];
            }
            return ['token' => $site_token, 'user_id' => 0, 'used_fallback' => true];
        }

        if (!empty($args['use_request_user']) && class_exists('EM_Leo_OAuth')) {
            $req_token = EM_Leo_OAuth::resolve_user_token();
            if ($req_token !== '') {
                return ['token' => $req_token, 'user_id' => get_current_user_id(), 'used_fallback' => false];
            }
        }

        return ['token' => $site_token, 'user_id' => 0, 'used_fallback' => false];
    }

    public static function complete($prompt, $args = [])
    {
        if (!self::is_enabled()) {
            return new WP_Error('leo_disabled', __('LEO integration is not configured.', 'email-manager'));
        }

        $settings = self::get_settings();
        $base = apply_filters('em_leo_endpoint_base', untrailingslashit($settings['base_url']));
        // Default to LEO's actual proxy endpoint on gend.me (/aipa/v1/ai-proxy/complete).
        // Override via the em_leo_endpoint_complete filter if your hub differs.
        $url  = apply_filters('em_leo_endpoint_complete', $base . '/aipa/v1/ai-proxy/complete');

        $body = [
            'prompt'      => (string) $prompt,
            'max_tokens'  => isset($args['max_tokens']) ? (int) $args['max_tokens'] : (int) $settings['max_tokens'],
            'temperature' => isset($args['temperature']) ? (float) $args['temperature'] : (float) $settings['temperature'],
            'model'       => isset($args['model']) ? (string) $args['model'] : $settings['default_model'],
        ];

        $auth = self::resolve_token($args);
        if (empty($auth['token'])) {
            return new WP_Error('leo_no_token', __('No LEO token available for this request.', 'email-manager'));
        }

        $headers = [
            'Authorization' => 'Bearer ' . $auth['token'],
            'X-GenD-Token'  => $auth['token'],   // LEO accepts both Authorization and X-GenD-Token
            'X-EM-Site'     => home_url(),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        if ($auth['user_id']) {
            $headers['X-EM-User-Id'] = (string) $auth['user_id'];
        }

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 402 || (isset($payload['data']['code']) && $payload['data']['code'] === 'insufficient_tokens')) {
            return new WP_Error('insufficient_tokens', isset($payload['data']['message']) ? $payload['data']['message'] : __('Insufficient LEO tokens.', 'email-manager'));
        }
        if ($code < 200 || $code >= 300 || empty($payload['success'])) {
            $msg = isset($payload['data']['message']) ? $payload['data']['message'] : sprintf(__('LEO request failed (HTTP %d).', 'email-manager'), $code);
            return new WP_Error('leo_request_failed', $msg);
        }

        return [
            'text'        => isset($payload['data']['text']) ? (string) $payload['data']['text'] : '',
            'tokens_used' => isset($payload['data']['tokens_used']) ? (int) $payload['data']['tokens_used'] : 0,
            'balance'     => isset($payload['data']['balance']) ? (int) $payload['data']['balance'] : null,
        ];
    }

    public static function fetch_balance()
    {
        if (!self::is_enabled()) return new WP_Error('leo_disabled', 'LEO not configured');
        $settings = self::get_settings();
        $base = apply_filters('em_leo_endpoint_base', untrailingslashit($settings['base_url']));
        $url  = apply_filters('em_leo_endpoint_balance', $base . '/aipa/v1/ai-proxy/balance');

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['site_token'],
                'X-GenD-Token'  => $settings['site_token'],
                'X-EM-Site'     => home_url(),
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) return $response;
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($payload['success'])) return new WP_Error('leo_balance_failed', 'Balance request failed');

        return [
            'balance' => isset($payload['data']['balance']) ? (int) $payload['data']['balance'] : 0,
            'tier'    => isset($payload['data']['tier']) ? (string) $payload['data']['tier'] : 'free',
        ];
    }

    /* ================================================================
       Token resolver — used by Prompt Response question type
       ================================================================ */

    public static function resolve_tokens($template, $context)
    {
        if (!is_string($template) || $template === '') return '';
        $tokens = [
            '{site_name}'  => get_bloginfo('name'),
            '{site_url}'   => home_url(),
            '{user_email}' => isset($context['user_email']) ? $context['user_email'] : '',
            '{user_name}'  => isset($context['user_name']) ? $context['user_name'] : '',
        ];

        // {answer:N} and {question:N} for previous answers (1-indexed)
        if (!empty($context['answers']) && is_array($context['answers'])) {
            $i = 1;
            foreach ($context['answers'] as $a) {
                $q = is_array($a) && isset($a['question']) ? $a['question'] : '';
                $v = is_array($a) && isset($a['answer'])   ? $a['answer']   : (is_string($a) ? $a : '');
                $tokens['{question:' . $i . '}'] = (string) $q;
                $tokens['{answer:' . $i . '}']   = (string) $v;
                $i++;
            }
        }

        // {previous} = last answer's value
        if (!empty($context['answers']) && is_array($context['answers'])) {
            $last = end($context['answers']);
            $tokens['{previous}'] = is_array($last) ? (string) ($last['answer'] ?? '') : (string) $last;
        }

        return strtr($template, $tokens);
    }

    /* ================================================================
       AJAX endpoints — called from chat-frontend.js
       ================================================================ */

    public function ajax_complete()
    {
        check_ajax_referer('chat_forms_submit_nonce', 'nonce');

        if (!self::is_enabled()) {
            wp_send_json_error(['message' => __('AI integration is not enabled.', 'email-manager')], 503);
        }

        $template = isset($_POST['prompt_template']) ? wp_unslash($_POST['prompt_template']) : '';
        $answers  = isset($_POST['answers']) ? (array) $_POST['answers'] : [];
        $form_id  = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $pays     = isset($_POST['pays']) ? sanitize_key($_POST['pays']) : 'site';
        $client_pays_uid = isset($_POST['pays_user_id']) ? absint($_POST['pays_user_id']) : 0;

        // Sanitize incoming answers shallowly
        $clean_answers = [];
        foreach ($answers as $a) {
            if (!is_array($a)) continue;
            $clean_answers[] = [
                'question' => isset($a['question']) ? sanitize_text_field(wp_unslash($a['question'])) : '',
                'answer'   => isset($a['answer'])   ? sanitize_textarea_field(wp_unslash($a['answer']))   : '',
            ];
        }

        $context = ['answers' => $clean_answers];
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $context['user_email'] = $u->user_email;
            $context['user_name']  = $u->display_name;
        }

        $resolved = self::resolve_tokens($template, $context);
        if (trim($resolved) === '') wp_send_json_error(['message' => 'Empty prompt'], 400);

        // Resolve which user's LEO balance pays. We never trust the client to
        // unilaterally specify another user's id without the question saying so.
        // - 'site'      -> no user, fall back to site_token
        // - 'chat_user' -> chat user's OAuth token chain (cookie/header/user_meta).
        //                  Anonymous users can still pay if they have the cookie set.
        // - 'member'    -> only the per-question pays_user_id sent from the client,
        //                  re-validated against the form's saved questions.
        $complete_args = [];
        if ($pays === 'chat_user') {
            $complete_args['use_request_user'] = true;
        } elseif ($pays === 'admin' && $form_id) {
            // Bill the admin who built (and last saved) this chat form.
            $author_id = (int) get_post_field('post_author', $form_id);
            if ($author_id) {
                $complete_args['paying_user_id'] = $author_id;
            }
        } elseif ($pays === 'member' && $form_id && $client_pays_uid) {
            $stored = get_post_meta($form_id, '_chat_form_questions', true);
            if (is_array($stored)) {
                foreach ($stored as $q) {
                    if (isset($q['type']) && $q['type'] === 'prompt_response'
                        && isset($q['pays']) && $q['pays'] === 'member'
                        && isset($q['pays_user_id']) && (int) $q['pays_user_id'] === $client_pays_uid) {
                        $complete_args['paying_user_id'] = $client_pays_uid;
                        break;
                    }
                }
            }
        }

        $result = self::complete($resolved, $complete_args);
        $paying_user_id = isset($complete_args['paying_user_id']) ? (int) $complete_args['paying_user_id']
                        : (!empty($complete_args['use_request_user']) ? get_current_user_id() : 0);
        if (is_wp_error($result)) {
            $code = $result->get_error_code() === 'insufficient_tokens' ? 402 : 500;
            wp_send_json_error(['code' => $result->get_error_code(), 'message' => $result->get_error_message()], $code);
        }

        wp_send_json_success([
            'text'        => $result['text'],
            'tokens_used' => $result['tokens_used'],
            'balance'     => $result['balance'],
            'form_id'     => $form_id,
            'paid_by'     => $paying_user_id ?: 'site',
        ]);
    }

    public function ajax_check_balance()
    {
        check_ajax_referer('em_app_support', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);

        $result = self::fetch_balance();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /* ================================================================
       Settings UI — rendered inside the email-manager admin page (Sending
       Settings subtab? No — gets its own subtab via render_panel below.)
       Currently surfaced through the ChatForm edit page sidebar.
       ================================================================ */

    public static function render_settings_panel()
    {
        $settings = self::get_settings();
        ?>
        <div class="gdc-email-panel em-reveal" style="--em-i:0;">
            <div class="gdc-email-panel__header">
                <div>
                    <h3><?php esc_html_e('LEO AI Integration', 'email-manager'); ?></h3>
                    <p class="description"><?php esc_html_e('Power Prompt Response questions with the LEO plugin on gend.me. Token packages are billed against the configured site token.', 'email-manager'); ?></p>
                </div>
                <a href="<?php echo esc_url(self::PURCHASE_URL); ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e('Buy LEO Tokens', 'email-manager'); ?> ↗</a>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('em_save_leo_settings'); ?>
                <input type="hidden" name="action" value="em_save_leo_settings" />

                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('Enable LEO', 'email-manager'); ?></div>
                        <div class="em-setting-row__hint"><?php esc_html_e('When off, Prompt Response questions show a placeholder.', 'email-manager'); ?></div>
                    </div>
                    <div class="em-setting-row__control">
                        <label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> /> <?php esc_html_e('Enabled', 'email-manager'); ?></label>
                    </div>
                </div>

                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('API Base URL', 'email-manager'); ?></div>
                        <div class="em-setting-row__hint"><?php esc_html_e('Defaults to https://gend.me/wp-json. Change only if instructed.', 'email-manager'); ?></div>
                    </div>
                    <div class="em-setting-row__control">
                        <input type="url" name="base_url" value="<?php echo esc_attr($settings['base_url']); ?>" placeholder="https://gend.me/wp-json" />
                    </div>
                </div>

                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('Site Token', 'email-manager'); ?></div>
                        <div class="em-setting-row__hint"><?php esc_html_e('Bearer token issued from your gend.me account that authorises this site to spend LEO tokens. Used as the fallback when no per-user OAuth token is present.', 'email-manager'); ?></div>
                    </div>
                    <div class="em-setting-row__control">
                        <input type="text" name="site_token" value="<?php echo esc_attr($settings['site_token']); ?>" placeholder="leo_…" autocomplete="off" />
                    </div>
                </div>

                <?php
                $detected_client_id = class_exists('EM_Leo_OAuth') ? EM_Leo_OAuth::client_id() : '';
                $leo_native         = class_exists('AIPA_GenD_OAuth');
                $shared_option      = (string) get_site_option('aipa_oauth_client_id', '');
                $detection_source   = $leo_native ? 'LEO plugin' : ($shared_option !== '' ? 'shared site option (aipa_oauth_client_id)' : 'email-manager fallback');
                ?>
                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('OAuth Client', 'email-manager'); ?></div>
                        <div class="em-setting-row__hint">
                            <?php
                            if ($detected_client_id !== '') {
                                printf(
                                    esc_html__('Detected from %s. The same OAuth client + redirect bridge that LEO uses is shared here.', 'email-manager'),
                                    '<strong>' . esc_html($detection_source) . '</strong>'
                                );
                            } else {
                                esc_html_e('No OAuth client detected. Either install/activate the LEO plugin or set the aipa_oauth_client_id site option.', 'email-manager');
                            }
                            ?>
                        </div>
                    </div>
                    <div class="em-setting-row__control">
                        <?php if ($detected_client_id !== ''): ?>
                            <code style="background:rgba(255,255,255,0.06);padding:6px 10px;border-radius:6px;color:var(--em-text-primary);"><?php echo esc_html($detected_client_id); ?></code>
                        <?php else: ?>
                            <p class="description"><?php esc_html_e('Override (rare):', 'email-manager'); ?></p>
                            <input type="text" name="oauth_client_id" value="<?php echo esc_attr($settings['oauth_client_id']); ?>" placeholder="em_xxx…" autocomplete="off" />
                            <input type="password" name="oauth_client_secret" value="<?php echo esc_attr($settings['oauth_client_secret']); ?>" placeholder="client secret (optional)" autocomplete="off" style="margin-top:6px;" />
                        <?php endif; ?>
                    </div>
                </div>

                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('Default Model', 'email-manager'); ?></div>
                    </div>
                    <div class="em-setting-row__control">
                        <input type="text" name="default_model" value="<?php echo esc_attr($settings['default_model']); ?>" placeholder="leo-default" />
                    </div>
                </div>

                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('Max Tokens', 'email-manager'); ?></div>
                    </div>
                    <div class="em-setting-row__control">
                        <input type="number" name="max_tokens" value="<?php echo esc_attr($settings['max_tokens']); ?>" min="1" max="4000" />
                    </div>
                </div>

                <div class="em-setting-row">
                    <div>
                        <div class="em-setting-row__label"><?php esc_html_e('Temperature', 'email-manager'); ?></div>
                    </div>
                    <div class="em-setting-row__control">
                        <input type="number" step="0.05" min="0" max="2" name="temperature" value="<?php echo esc_attr($settings['temperature']); ?>" />
                    </div>
                </div>

                <p style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save LEO Settings', 'email-manager'); ?></button>
                    <button type="button" class="button" id="em-leo-check-balance"><?php esc_html_e('Check Balance', 'email-manager'); ?></button>
                    <span id="em-leo-balance-result" style="margin-left:8px;color:var(--em-text-secondary);align-self:center;"></span>
                </p>
            </form>

            <hr style="margin:24px 0;border:0;border-top:1px solid rgba(255,255,255,0.05);" />

            <div class="em-leo-personal">
                <h3 style="margin:0 0 6px;color:var(--em-text-primary);">🔐 <?php esc_html_e('Your personal LEO account', 'email-manager'); ?></h3>
                <p class="description" style="color:var(--em-text-secondary);margin:0 0 12px;">
                    <?php esc_html_e('Connect your gend.me account to spend LEO tokens from your own balance. When a Prompt Response is set to bill the admin, this token is used.', 'email-manager'); ?>
                </p>
                <div id="em-leo-oauth-status" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <span class="em-leo-conn-state" style="color:var(--em-text-secondary);">Checking…</span>
                </div>
            </div>

            <script>
                jQuery(function ($) {
                    var $state = $('#em-leo-oauth-status');
                    var hubUrl = '<?php echo esc_js(class_exists('EM_Leo_OAuth') ? EM_Leo_OAuth::hub_url() : 'https://gend.me'); ?>';
                    var clientId = <?php echo wp_json_encode($settings['oauth_client_id']); ?>;

                    function refreshStatus() {
                        $.get(<?php echo wp_json_encode(rest_url('em/v1/oauth/status')); ?>).done(function (s) {
                            renderStatus(s);
                        });
                    }

                    function renderStatus(s) {
                        if (!s.configured) {
                            $state.html('<span style="color:#fcd34d;">⚠️ Configure the OAuth Client ID first.</span>');
                            return;
                        }
                        if (!s.connected) {
                            $state.html('<button type="button" class="button button-primary" id="em-leo-connect">🔗 Connect to gend.me</button>' +
                                ' <span style="color:var(--em-text-secondary);">Not connected.</span>');
                            return;
                        }
                        // Connected — fetch balance
                        $.get(<?php echo wp_json_encode(rest_url('em/v1/oauth/balance')); ?>).done(function (b) {
                            var balance = (typeof b.balance === 'number') ? b.balance.toFixed(2) : '—';
                            var label = b.token_label || 'Leo Tokens';
                            var topup = b.topup_url || (hubUrl + '/leo/tokens');
                            $state.html(
                                '<span style="color:#86efac;font-weight:700;">✅ Connected</span>' +
                                ' <span style="color:var(--em-text-primary);font-weight:700;">💎 ' + balance + ' ' + label + '</span>' +
                                ' <a href="' + topup + '" target="_blank" rel="noopener" class="button">💳 Top up</a>' +
                                ' <button type="button" class="button" id="em-leo-disconnect">Disconnect</button>'
                            );
                        });
                    }

                    function startOAuth() {
                        if (!clientId) { alert('Set the OAuth Client ID first.'); return; }
                        var redirectUri = 'https://gend.me/oauth-bridge/';
                        var b64url = function (bytes) {
                            var s = ''; bytes.forEach(function (b) { s += String.fromCharCode(b); });
                            return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                        };
                        var stBytes = new Uint8Array(32); crypto.getRandomValues(stBytes);
                        var vBytes  = new Uint8Array(32); crypto.getRandomValues(vBytes);
                        var state = b64url(stBytes);
                        var verifier = b64url(vBytes);
                        crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier)).then(function (digest) {
                            var challenge = b64url(new Uint8Array(digest));
                            try { sessionStorage.setItem('em_leo_oauth_attempt', JSON.stringify({ state: state, codeVerifier: verifier, ts: Date.now() })); }
                            catch (e) { alert('Session storage unavailable.'); return; }
                            var params = new URLSearchParams({
                                client_id: clientId,
                                response_type: 'code',
                                redirect_uri: redirectUri,
                                state: state,
                                code_challenge: challenge,
                                code_challenge_method: 'S256'
                            });
                            var authorizeUrl = hubUrl + '/oauth/authorize?' + params.toString();
                            var w = 600, h = 700;
                            var l = (window.innerWidth / 2) - w / 2;
                            var t = (window.innerHeight / 2) - h / 2;
                            var win = window.open(authorizeUrl, 'GenDLogin', 'width=' + w + ',height=' + h + ',left=' + l + ',top=' + t);
                            var hubOrigin = new URL(hubUrl).origin;
                            var onMsg = function (ev) {
                                if (ev.origin !== hubOrigin) return;
                                var d = ev.data || {};
                                if (d.type !== 'gdc-auth' || !d.code) return;
                                window.removeEventListener('message', onMsg);
                                if (win) try { win.close(); } catch(_) {}
                                var attempt = {};
                                try { attempt = JSON.parse(sessionStorage.getItem('em_leo_oauth_attempt') || '{}'); } catch(_){}
                                sessionStorage.removeItem('em_leo_oauth_attempt');
                                if (!attempt.state || attempt.state !== d.state) {
                                    alert('Login aborted: state mismatch.'); return;
                                }
                                $.ajax({
                                    url: <?php echo wp_json_encode(rest_url('em/v1/oauth/exchange')); ?>,
                                    method: 'POST',
                                    contentType: 'application/json',
                                    data: JSON.stringify({ code: d.code, redirect_uri: redirectUri, code_verifier: attempt.codeVerifier, state: d.state })
                                }).done(function () { refreshStatus(); })
                                  .fail(function (x) { alert('Exchange failed: ' + ((x.responseJSON && x.responseJSON.message) || x.statusText)); });
                            };
                            window.addEventListener('message', onMsg);
                        });
                    }

                    $(document).on('click', '#em-leo-connect', startOAuth);
                    $(document).on('click', '#em-leo-disconnect', function () {
                        $.post(<?php echo wp_json_encode(rest_url('em/v1/oauth/revoke')); ?>).done(refreshStatus);
                    });

                    refreshStatus();
                });
            </script>

            <script>
                jQuery(function ($) {
                    $('#em-leo-check-balance').on('click', function () {
                        var $btn = $(this);
                        var $out = $('#em-leo-balance-result');
                        $btn.prop('disabled', true).text('Checking…');
                        $out.text('');
                        $.post(ajaxurl, {
                            action: 'em_leo_check_balance',
                            nonce: (window.EM_AS_CONFIG && window.EM_AS_CONFIG.nonce) || ''
                        }).done(function (res) {
                            if (res && res.success) {
                                $out.text('Balance: ' + res.data.balance + ' tokens · tier: ' + res.data.tier);
                            } else {
                                $out.text((res && res.data && res.data.message) || 'Failed.');
                            }
                        }).fail(function () { $out.text('Network error.'); })
                          .always(function () { $btn.prop('disabled', false).text('Check Balance'); });
                    });
                });
            </script>
        </div>
        <?php
    }
}

new EM_Leo();
