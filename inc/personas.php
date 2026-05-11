<?php
/**
 * Personas module — define AI personas with role/tone/prompt-sequence,
 * link them to a WP user, optionally bind to a chatflow that auto-replies
 * when the linked user receives a BuddyPress private message.
 *
 * @package EmailManager
 */

defined('ABSPATH') || exit;

class EM_Personas
{
    const POST_TYPE = 'em_persona';
    const META_ROLE         = '_em_persona_role';
    const META_TONE         = '_em_persona_tone';
    const META_DESCRIPTION  = '_em_persona_description';
    const META_AVATAR       = '_em_persona_avatar_url';
    const META_LINKED_USER  = '_em_persona_linked_user_id';
    const META_LINKED_EMAIL = '_em_persona_linked_email';
    const META_CHATFLOW     = '_em_persona_main_chatflow_id';
    const META_PROMPTS      = '_em_persona_prompts';
    const USER_META_PERSONA = '_em_persona_id';

    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);

        add_action('admin_post_em_save_persona',   [$this, 'handle_save']);
        add_action('admin_post_em_delete_persona', [$this, 'handle_delete']);

        add_action('wp_ajax_em_get_persona', [$this, 'ajax_get_persona']);

        // Social plugin auto-reply hook (BuddyPress / BuddyBoss messaging)
        add_action('messages_message_sent', [$this, 'maybe_auto_reply'], 20, 1);
    }

    /* ================================================================
       Capability detection
       ================================================================ */

    public static function social_active()
    {
        return function_exists('bp_loaded') || class_exists('BuddyPress') || function_exists('bp_messages_get_message_thread_id');
    }

    /* ================================================================
       CPT
       ================================================================ */

    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'          => __('Personas', 'email-manager'),
                'singular_name' => __('Persona', 'email-manager'),
            ],
            'public'   => false,
            'show_ui'  => false,
            'supports' => ['title'],
        ]);
    }

    /* ================================================================
       Accessors
       ================================================================ */

    public static function get_personas()
    {
        return get_posts([
            'post_type'   => self::POST_TYPE,
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
    }

    public static function get_persona_data($post_id)
    {
        $post = get_post($post_id);
        if (!$post) return null;

        $linked_user_id = (int) get_post_meta($post_id, self::META_LINKED_USER, true);
        $linked_email   = get_post_meta($post_id, self::META_LINKED_EMAIL, true);
        if ($linked_user_id && !$linked_email) {
            $u = get_user_by('id', $linked_user_id);
            if ($u) $linked_email = $u->user_email;
        }

        $prompts = get_post_meta($post_id, self::META_PROMPTS, true);
        if (!is_array($prompts)) $prompts = [];

        return [
            'id'            => $post_id,
            'name'          => $post->post_title,
            'role'          => get_post_meta($post_id, self::META_ROLE, true),
            'tone'          => get_post_meta($post_id, self::META_TONE, true),
            'description'   => get_post_meta($post_id, self::META_DESCRIPTION, true),
            'avatar_url'    => get_post_meta($post_id, self::META_AVATAR, true),
            'linked_user_id' => $linked_user_id,
            'linked_email'  => $linked_email,
            'main_chatflow_id' => (int) get_post_meta($post_id, self::META_CHATFLOW, true),
            'prompts'       => $prompts,
        ];
    }

    public static function get_chatflow_options()
    {
        $posts = get_posts([
            'post_type'   => 'chat_form',
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $p) {
            $out[$p->ID] = $p->post_title ?: sprintf('(no title #%d)', $p->ID);
        }
        return $out;
    }

    /* ================================================================
       Handlers
       ================================================================ */

    public function handle_save()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        $persona_id = isset($_POST['persona_id']) ? absint($_POST['persona_id']) : 0;
        check_admin_referer('em_save_persona_' . $persona_id);

        $name        = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $role        = sanitize_text_field(wp_unslash($_POST['role'] ?? ''));
        $tone        = sanitize_text_field(wp_unslash($_POST['tone'] ?? ''));
        $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));
        $avatar      = esc_url_raw(wp_unslash($_POST['avatar_url'] ?? ''));
        $email       = sanitize_email(wp_unslash($_POST['linked_email'] ?? ''));
        $chatflow_id = absint($_POST['main_chatflow_id'] ?? 0);
        $prompts_raw = isset($_POST['prompts']) && is_array($_POST['prompts']) ? $_POST['prompts'] : [];

        if ($name === '') {
            wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'persona_error'], admin_url('admin.php')));
            exit;
        }

        // Resolve linked user via email
        $linked_user_id = 0;
        if ($email) {
            $u = get_user_by('email', $email);
            if ($u) $linked_user_id = $u->ID;
        }

        // Sanitize prompts
        $prompts = [];
        $i = 0;
        foreach ($prompts_raw as $row) {
            if (!is_array($row)) continue;
            $text = wp_kses_post(wp_unslash($row['text'] ?? ''));
            if (trim($text) === '') continue;
            $assign = isset($row['assignTo']) && in_array($row['assignTo'], ['next', 'final'], true) ? $row['assignTo'] : 'next';
            $prompts[] = [
                'id'       => isset($row['id']) ? sanitize_text_field(wp_unslash($row['id'])) : 'p_' . wp_generate_uuid4(),
                'text'     => $text,
                'assignTo' => $assign,
            ];
            $i++;
        }

        // Insert or update post
        $post_args = [
            'post_type'   => self::POST_TYPE,
            'post_title'  => $name,
            'post_status' => 'publish',
        ];
        if ($persona_id) {
            $post_args['ID'] = $persona_id;
            wp_update_post($post_args);
        } else {
            $persona_id = wp_insert_post($post_args);
            if (is_wp_error($persona_id) || !$persona_id) {
                wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'persona_error'], admin_url('admin.php')));
                exit;
            }
        }

        // Detach previous user link if it changed
        $previous_user = (int) get_post_meta($persona_id, self::META_LINKED_USER, true);
        if ($previous_user && $previous_user !== $linked_user_id) {
            $prev_meta = (int) get_user_meta($previous_user, self::USER_META_PERSONA, true);
            if ($prev_meta === $persona_id) {
                delete_user_meta($previous_user, self::USER_META_PERSONA);
            }
        }

        update_post_meta($persona_id, self::META_ROLE, $role);
        update_post_meta($persona_id, self::META_TONE, $tone);
        update_post_meta($persona_id, self::META_DESCRIPTION, $description);
        update_post_meta($persona_id, self::META_AVATAR, $avatar);
        update_post_meta($persona_id, self::META_LINKED_USER, $linked_user_id);
        update_post_meta($persona_id, self::META_LINKED_EMAIL, $email);
        update_post_meta($persona_id, self::META_CHATFLOW, $chatflow_id);
        update_post_meta($persona_id, self::META_PROMPTS, $prompts);

        if ($linked_user_id) {
            update_user_meta($linked_user_id, self::USER_META_PERSONA, $persona_id);
        }

        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'persona_saved'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete()
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        $persona_id = isset($_POST['persona_id']) ? absint($_POST['persona_id']) : 0;
        check_admin_referer('em_delete_persona_' . $persona_id);

        if ($persona_id) {
            $linked = (int) get_post_meta($persona_id, self::META_LINKED_USER, true);
            if ($linked) {
                $stored = (int) get_user_meta($linked, self::USER_META_PERSONA, true);
                if ($stored === $persona_id) delete_user_meta($linked, self::USER_META_PERSONA);
            }
            wp_delete_post($persona_id, true);
        }
        wp_safe_redirect(add_query_arg(['page' => 'email-manager', 'updated' => 'persona_deleted'], admin_url('admin.php')));
        exit;
    }

    public function ajax_get_persona()
    {
        check_ajax_referer('em_app_support', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        $id = absint($_POST['id'] ?? 0);
        $data = $id ? self::get_persona_data($id) : null;
        if (!$data) wp_send_json_error(['message' => 'Not found'], 404);
        wp_send_json_success($data);
    }

    /* ================================================================
       BuddyPress auto-reply
       ================================================================ */

    public function maybe_auto_reply($message)
    {
        if (!self::social_active()) return;
        if (!is_object($message) || empty($message->recipients)) return;

        foreach ($message->recipients as $recipient) {
            $user_id = is_object($recipient) ? (int) $recipient->user_id : (int) $recipient;
            if (!$user_id) continue;

            $persona_id = (int) get_user_meta($user_id, self::USER_META_PERSONA, true);
            if (!$persona_id) continue;

            $persona = self::get_persona_data($persona_id);
            if (!$persona) continue;

            $reply_text = '';
            if (!empty($persona['prompts']) && isset($persona['prompts'][0]['text'])) {
                $reply_text = $persona['prompts'][0]['text'];
            } elseif (!empty($persona['description'])) {
                $reply_text = $persona['description'];
            } else {
                $reply_text = sprintf(__('Hi — this is %s. I will be in touch shortly.', 'email-manager'), $persona['name']);
            }

            // Send a private reply via BuddyPress
            if (function_exists('messages_new_message')) {
                messages_new_message([
                    'sender_id'  => $user_id,
                    'thread_id'  => isset($message->thread_id) ? $message->thread_id : 0,
                    'subject'    => sprintf(__('Re: %s', 'email-manager'), isset($message->subject) ? $message->subject : ''),
                    'content'    => $reply_text,
                    'recipients' => [$message->sender_id],
                ]);
            }
        }
    }

    /* ================================================================
       Render
       ================================================================ */

    public static function render()
    {
        $personas = self::get_personas();
        $editing_id = isset($_GET['edit_persona']) ? absint($_GET['edit_persona']) : 0;
        $editing_data = $editing_id ? self::get_persona_data($editing_id) : null;
        $is_new = isset($_GET['new_persona']);
        $bp_active = self::social_active();
        ?>
        <div class="em-personas">

            <?php if ($bp_active): ?>
                <div class="em-notice em-notice--info" style="--em-i:0;">
                    <strong><?php esc_html_e('Social messaging detected', 'email-manager'); ?></strong> —
                    <?php esc_html_e('Personas linked to a member will auto-reply when they receive a private message.', 'email-manager'); ?>
                </div>
            <?php endif; ?>

            <?php if ($editing_data || $is_new): ?>
                <?php self::render_editor($editing_data); ?>
            <?php else: ?>
                <?php self::render_list($personas, $bp_active); ?>
            <?php endif; ?>

        </div>
        <?php
    }

    private static function render_list($personas, $bp_active)
    {
        ?>
        <div class="gdc-email-panel em-reveal" style="--em-i:0;">
            <div class="gdc-email-panel__header">
                <div>
                    <h3><?php esc_html_e('Personas', 'email-manager'); ?></h3>
                    <p class="description"><?php esc_html_e('Each persona has a role, tone, prompt sequence, and an optional linked member account.', 'email-manager'); ?></p>
                </div>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'email-manager', 'new_persona' => 1], admin_url('admin.php'))); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt" style="margin-top:3px;"></span>
                    <?php esc_html_e('New Persona', 'email-manager'); ?>
                </a>
            </div>

            <?php if (empty($personas)): ?>
                <div class="em-empty">
                    <div class="em-empty__icon"><span class="dashicons dashicons-businessman"></span></div>
                    <div class="em-empty__title"><?php esc_html_e('No personas yet', 'email-manager'); ?></div>
                    <div><?php esc_html_e('Create one to define how an AI agent should respond.', 'email-manager'); ?></div>
                </div>
            <?php else: ?>
                <div class="em-persona-grid">
                    <?php foreach ($personas as $i => $p):
                        $data = self::get_persona_data($p->ID);
                        $linked_user = $data['linked_user_id'] ? get_user_by('id', $data['linked_user_id']) : null;
                        $chatflow_title = $data['main_chatflow_id'] ? get_the_title($data['main_chatflow_id']) : '';
                        ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'email-manager', 'edit_persona' => $p->ID], admin_url('admin.php'))); ?>" class="em-persona-card em-reveal" style="--em-i:<?php echo (int) $i; ?>;">
                            <div class="em-persona-card__avatar">
                                <?php if ($data['avatar_url']): ?>
                                    <img src="<?php echo esc_url($data['avatar_url']); ?>" alt="" />
                                <?php else: ?>
                                    <span><?php echo esc_html(self::initials($data['name'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="em-persona-card__body">
                                <div class="em-persona-card__name"><?php echo esc_html($data['name']); ?></div>
                                <?php if ($data['role']): ?><div class="em-persona-card__role"><?php echo esc_html($data['role']); ?></div><?php endif; ?>
                                <?php if ($data['tone']): ?><div class="em-persona-card__tone">"<?php echo esc_html($data['tone']); ?>"</div><?php endif; ?>
                                <div class="em-persona-card__meta">
                                    <span class="em-pill em-pill--info"><?php echo esc_html(sprintf(_n('%d prompt', '%d prompts', count($data['prompts']), 'email-manager'), count($data['prompts']))); ?></span>
                                    <?php if ($linked_user): ?>
                                        <span class="em-pill em-pill--success">@<?php echo esc_html($linked_user->user_login); ?></span>
                                    <?php endif; ?>
                                    <?php if ($chatflow_title): ?>
                                        <span class="em-pill"><?php echo esc_html($chatflow_title); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_editor($data)
    {
        $is_new = empty($data);
        $persona_id = $is_new ? 0 : $data['id'];
        $name        = $is_new ? '' : $data['name'];
        $role        = $is_new ? '' : $data['role'];
        $tone        = $is_new ? '' : $data['tone'];
        $description = $is_new ? '' : $data['description'];
        $avatar      = $is_new ? '' : $data['avatar_url'];
        $linked_email = $is_new ? '' : $data['linked_email'];
        $chatflow_id = $is_new ? 0  : $data['main_chatflow_id'];
        $prompts     = $is_new ? [] : $data['prompts'];

        $chatflows = self::get_chatflow_options();
        $back_url  = add_query_arg(['page' => 'email-manager'], admin_url('admin.php'));
        ?>
        <div class="gdc-email-panel em-reveal" style="--em-i:0;">
            <div class="gdc-email-panel__header">
                <div>
                    <h3><?php echo $is_new ? esc_html__('Create Persona', 'email-manager') : esc_html(sprintf(__('Edit: %s', 'email-manager'), $name)); ?></h3>
                </div>
                <a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php esc_html_e('Back to list', 'email-manager'); ?></a>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="em-persona-form">
                <?php wp_nonce_field('em_save_persona_' . $persona_id); ?>
                <input type="hidden" name="action" value="em_save_persona" />
                <input type="hidden" name="persona_id" value="<?php echo esc_attr($persona_id); ?>" />

                <div class="em-persona-grid-2">
                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Name', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Shown to staff and used in auto-replies.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="text" name="name" value="<?php echo esc_attr($name); ?>" required />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Role', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('e.g. "Customer support specialist".', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="text" name="role" value="<?php echo esc_attr($role); ?>" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Tone of Voice', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Short phrase, e.g. "Friendly, concise, on-brand".', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="text" name="tone" value="<?php echo esc_attr($tone); ?>" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Description', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Background, personality, anything that informs how the persona behaves.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <textarea name="description" rows="4"><?php echo esc_textarea($description); ?></textarea>
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Avatar URL', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('Optional image URL. Leave blank for initials.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="url" name="avatar_url" value="<?php echo esc_attr($avatar); ?>" placeholder="https://…" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Linked Member Email', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('If a WP user has this email, the persona is bound to their profile. Leave blank to skip.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <input type="email" name="linked_email" value="<?php echo esc_attr($linked_email); ?>" placeholder="member@example.com" />
                        </div>
                    </div>

                    <div class="em-setting-row">
                        <div>
                            <div class="em-setting-row__label"><?php esc_html_e('Main Chatflow', 'email-manager'); ?></div>
                            <div class="em-setting-row__hint"><?php esc_html_e('When a member messages this profile via the social network, this chatflow is referenced for auto-reply.', 'email-manager'); ?></div>
                        </div>
                        <div class="em-setting-row__control">
                            <select name="main_chatflow_id">
                                <option value="0"><?php esc_html_e('— None —', 'email-manager'); ?></option>
                                <?php foreach ($chatflows as $id => $title): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($chatflow_id, $id); ?>><?php echo esc_html($title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <h3 style="color:var(--em-text-primary);margin-top:24px;"><?php esc_html_e('Prompt Sequence', 'email-manager'); ?></h3>
                <p class="description" style="color:var(--em-text-secondary);"><?php esc_html_e('Each prompt runs in order. Use "Pass to next" to feed the response into the next prompt.', 'email-manager'); ?></p>

                <div id="em-prompt-list" class="em-prompt-list">
                    <?php if (empty($prompts)): ?>
                        <?php self::render_prompt_row(0, ['id' => '', 'text' => '', 'assignTo' => 'next']); ?>
                    <?php else: ?>
                        <?php foreach ($prompts as $i => $prompt): ?>
                            <?php self::render_prompt_row($i, $prompt); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="button" id="em-add-prompt"><?php esc_html_e('+ Add Prompt', 'email-manager'); ?></button>
                    <button type="submit" class="button button-primary"><?php echo $is_new ? esc_html__('Create Persona', 'email-manager') : esc_html__('Save Changes', 'email-manager'); ?></button>
                </p>

                <?php if (!$is_new): ?>
                    <hr style="margin-top:32px;border-color:rgba(255,255,255,0.05);" />
                    <details style="margin-top:16px;">
                        <summary style="color:var(--em-danger);cursor:pointer;font-size:0.9rem;"><?php esc_html_e('Delete persona', 'email-manager'); ?></summary>
                        <div style="margin-top:8px;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php esc_attr_e('Delete this persona permanently?', 'email-manager'); ?>');">
                                <?php wp_nonce_field('em_delete_persona_' . $persona_id); ?>
                                <input type="hidden" name="action" value="em_delete_persona" />
                                <input type="hidden" name="persona_id" value="<?php echo esc_attr($persona_id); ?>" />
                                <button type="submit" class="button" style="color:#fca5a5;"><?php esc_html_e('Delete persona', 'email-manager'); ?></button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </form>
        </div>

        <template id="em-prompt-row-template">
            <?php self::render_prompt_row('__INDEX__', ['id' => '', 'text' => '', 'assignTo' => 'next']); ?>
        </template>

        <script>
            jQuery(function ($) {
                var $list = $('#em-prompt-list');
                var $tpl  = $('#em-prompt-row-template');

                function reindex() {
                    $list.children('.em-prompt-row').each(function (i) {
                        $(this).find('.em-prompt-num').text(i + 1);
                        $(this).find('input,textarea,select').each(function () {
                            var name = $(this).attr('name');
                            if (!name) return;
                            $(this).attr('name', name.replace(/prompts\[\d+\]/, 'prompts[' + i + ']').replace(/prompts\[__INDEX__\]/, 'prompts[' + i + ']'));
                        });
                    });
                }

                $('#em-add-prompt').on('click', function () {
                    var html = $tpl.html().replace(/__INDEX__/g, $list.children('.em-prompt-row').length);
                    var $row = $(html);
                    $list.append($row);
                    reindex();
                });

                $list.on('click', '.em-prompt-remove', function () {
                    if ($list.children('.em-prompt-row').length <= 1) return;
                    $(this).closest('.em-prompt-row').remove();
                    reindex();
                });

                $list.on('click', '.em-prompt-up', function () {
                    var $row = $(this).closest('.em-prompt-row');
                    var $prev = $row.prev('.em-prompt-row');
                    if ($prev.length) { $row.insertBefore($prev); reindex(); }
                });

                $list.on('click', '.em-prompt-down', function () {
                    var $row = $(this).closest('.em-prompt-row');
                    var $next = $row.next('.em-prompt-row');
                    if ($next.length) { $row.insertAfter($next); reindex(); }
                });
            });
        </script>
        <?php
    }

    private static function render_prompt_row($i, $prompt)
    {
        $id   = isset($prompt['id']) ? $prompt['id'] : '';
        $text = isset($prompt['text']) ? $prompt['text'] : '';
        $assign = isset($prompt['assignTo']) ? $prompt['assignTo'] : 'next';
        $idx_label = is_int($i) ? ($i + 1) : 1;
        ?>
        <div class="em-prompt-row" style="--em-i:<?php echo is_int($i) ? (int) $i : 0; ?>;">
            <div class="em-prompt-row__head">
                <span class="em-prompt-num"><?php echo esc_html($idx_label); ?></span>
                <select name="prompts[<?php echo esc_attr($i); ?>][assignTo]" class="em-prompt-assign">
                    <option value="next" <?php selected($assign, 'next'); ?>><?php esc_html_e('Pass response to next prompt', 'email-manager'); ?></option>
                    <option value="final" <?php selected($assign, 'final'); ?>><?php esc_html_e('Final response (don\'t chain)', 'email-manager'); ?></option>
                </select>
                <span class="em-prompt-row__spacer"></span>
                <button type="button" class="button button-small em-prompt-up" title="<?php esc_attr_e('Move up', 'email-manager'); ?>">↑</button>
                <button type="button" class="button button-small em-prompt-down" title="<?php esc_attr_e('Move down', 'email-manager'); ?>">↓</button>
                <button type="button" class="button button-small em-prompt-remove" title="<?php esc_attr_e('Remove', 'email-manager'); ?>">×</button>
                <input type="hidden" name="prompts[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr($id); ?>" />
            </div>
            <textarea name="prompts[<?php echo esc_attr($i); ?>][text]" rows="3" placeholder="<?php esc_attr_e('Prompt text. Use {{previous}} to inject the prior response.', 'email-manager'); ?>"><?php echo esc_textarea($text); ?></textarea>
        </div>
        <?php
    }

    private static function initials($name)
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        $out = '';
        foreach ($parts as $p) {
            if ($p === '') continue;
            $out .= mb_substr($p, 0, 1);
            if (mb_strlen($out) >= 2) break;
        }
        return $out ?: '?';
    }
}

new EM_Personas();
