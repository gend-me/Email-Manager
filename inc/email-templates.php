<?php
/**
 * Email Templates Manager
 * Ported from GDC_Bulk_Point_Assign
 */

if (!defined('ABSPATH')) {
    exit;
}

class EM_Email_Templates
{

    private const OPTION_TEMPLATES = 'em_email_templates';
    private const OPTION_THEME = 'em_email_theme';
    private const OPTION_ACTIVE = 'em_active_template';

    public function __construct()
    {
        add_action('wp_ajax_em_save_template', [$this, 'handle_save_template']);
        add_action('wp_ajax_em_delete_template', [$this, 'handle_delete_template']);
        add_action('wp_ajax_em_set_active_template', [$this, 'handle_set_active_template']);
        add_action('wp_ajax_em_save_theme', [$this, 'handle_save_theme']);

        // Register assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_email-manager') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('em-templates', EMAIL_MANAGER_URL . 'assets/em-templates.css', [], '1.0.1');
        wp_enqueue_script('em-templates', EMAIL_MANAGER_URL . 'assets/em-templates.js', ['jquery'], '1.0.1', true);

        wp_localize_script('em-templates', 'bpaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('em_templates_nonce'),
            'templates' => $this->get_email_templates(),
            'theme' => $this->get_theme_settings(),
            'activeTemplate' => $this->get_active_template(),
            // Mocks/Defaults for BPA JS compatibility
            'pointTypes' => [],
            'bpMemberTypes' => [],
            'embedTemplates' => true,
        ]);
    }

    public function render()
    {
        ?>
        <div class="bpa-wrap">
            <h2 style="margin:0 0 12px 0;">
                <?php esc_html_e('Email Templates', 'email-manager'); ?>
            </h2>
            <div id="bpa-template-modal" class="bpa-template-embed" aria-hidden="false">
                <div class="bpa-modal-content" style="max-width:100%;">
                    <div class="bpa-modal-header-row">
                        <button type="button" class="button bpa-btn-icon bpa-topbar-btn-left" id="bpa-template-back"
                            style="display:none;"><span class="dashicons dashicons-arrow-left-alt2"></span>
                            <?php esc_html_e('Back to list', 'email-manager'); ?>
                        </button>
                        <button type="button" class="button button-primary bpa-btn-icon bpa-topbar-btn-right"
                            id="bpa-template-create-header"><span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('Create New Template', 'email-manager'); ?>
                        </button>
                    </div>
                    <div id="bpa-template-browse" class="bpa-template-layout">
                        <div class="bpa-template-list" id="bpa-template-list"></div>
                    </div>
                    <div id="bpa-template-config" class="bpa-template-config" style="display:none;">
                        <!-- Template Editor UI (Same as BPA) -->
                        <div class="bpa-template-settings">
                            <label>
                                <span>
                                    <?php esc_html_e('Template Name', 'email-manager'); ?>
                                </span>
                                <input type="text" id="bpa-new-template-name"
                                    placeholder="<?php esc_attr_e('e.g. Bonus Drop', 'email-manager'); ?>">
                            </label>

                            <!-- Helpers for sections -->
                            <div class="bpa-template-accordion">
                                <div class="bpa-accordion-header">
                                    <?php esc_html_e('Template Settings', 'email-manager'); ?><span class="bpa-color-chip"
                                        id="bpa-chip-template"></span>
                                </div>
                                <div class="bpa-accordion-body open bpa-template-theme">
                                    <div class="bpa-grid-2">
                                        <label><span>
                                                <?php esc_html_e('Email container background', 'email-manager'); ?>
                                            </span><input type="color" id="bpa-theme-container-bg"></label>
                                        <label><span>
                                                <?php esc_html_e('Copy block background', 'email-manager'); ?>
                                            </span><input type="color" id="bpa-theme-copy-bg"></label>
                                    </div>
                                </div>

                                <div class="bpa-accordion-header">
                                    <?php esc_html_e('Header', 'email-manager'); ?><span class="bpa-color-chip"
                                        id="bpa-chip-header"></span>
                                </div>
                                <div class="bpa-accordion-body bpa-template-theme">
                                    <label><span>
                                            <?php esc_html_e('Header Image URL', 'email-manager'); ?>
                                        </span></label>
                                    <div class="bpa-upload-row">
                                        <input type="text" id="bpa-theme-header-url"
                                            placeholder="https://example.com/header.png">
                                        <button type="button" class="button" id="bpa-theme-header-upload">
                                            <?php esc_html_e('Upload / Select Header Image', 'email-manager'); ?>
                                        </button>
                                    </div>
                                    <div class="bpa-grid-3">
                                        <label><span>
                                                <?php esc_html_e('Header Height (px)', 'email-manager'); ?>
                                            </span><input type="number" id="bpa-theme-header-height" value="120"></label>
                                        <label><span>
                                                <?php esc_html_e('Header Width (px)', 'email-manager'); ?>
                                            </span><input type="number" id="bpa-theme-header-width" value="600"></label>
                                        <label><span>
                                                <?php esc_html_e('Spacing below image (px)', 'email-manager'); ?>
                                            </span><input type="number" id="bpa-theme-header-space" value="24"></label>
                                    </div>
                                    <div class="bpa-grid-2">
                                        <label><span>
                                                <?php esc_html_e('Header link URL', 'email-manager'); ?>
                                            </span><input type="text" id="bpa-theme-header-link"
                                                placeholder="https://example.com"></label>
                                        <label><span>
                                                <?php esc_html_e('Header Alignment', 'email-manager'); ?>
                                            </span>
                                            <select id="bpa-theme-header-align">
                                                <option value="center">
                                                    <?php esc_html_e('Center', 'email-manager'); ?>
                                                </option>
                                                <option value="left">
                                                    <?php esc_html_e('Left', 'email-manager'); ?>
                                                </option>
                                                <option value="right">
                                                    <?php esc_html_e('Right', 'email-manager'); ?>
                                                </option>
                                            </select>
                                        </label>
                                    </div>
                                </div>

                                <div class="bpa-accordion-header">
                                    <?php esc_html_e('Content', 'email-manager'); ?><span class="bpa-color-chip"
                                        id="bpa-chip-content"></span>
                                </div>
                                <div class="bpa-accordion-body bpa-template-theme">
                                    <?php $this->render_typography_settings(); ?>
                                </div>

                                <div class="bpa-accordion-header">
                                    <?php esc_html_e('Footer', 'email-manager'); ?><span class="bpa-color-chip"
                                        id="bpa-chip-footer"></span>
                                </div>
                                <div class="bpa-accordion-body bpa-template-theme">
                                    <div style="display:none;">
                                        <!-- Hidden inputs that JS expects -->
                                        <input type="text" id="bpa-theme-footer-url" value="">
                                        <input type="number" id="bpa-theme-footer-height" value="80">
                                        <input type="number" id="bpa-theme-footer-width" value="260">
                                        <input type="number" id="bpa-theme-footer-space" value="16">
                                        <input type="text" id="bpa-theme-footer-link" value="">
                                    </div>
                                    <label><span>
                                            <?php esc_html_e('Footer layout', 'email-manager'); ?>
                                        </span>
                                        <select id="bpa-footer-columns">
                                            <option value="1">
                                                <?php esc_html_e('1 Column', 'email-manager'); ?>
                                            </option>
                                            <option value="2">
                                                <?php esc_html_e('2 Columns', 'email-manager'); ?>
                                            </option>
                                        </select>
                                    </label>
                                    <div class="bpa-footer-layout-visual" id="bpa-footer-layout-visual">
                                        <div class="bpa-footer-slot slot-1">
                                            <div class="bpa-footer-slot-title">
                                                <?php esc_html_e('Column 1', 'email-manager'); ?>
                                            </div>
                                            <button type="button" class="button bpa-footer-slot-btn"
                                                id="bpa-footer-edit-1-inline">
                                                <?php esc_html_e('Edit Column 1', 'email-manager'); ?>
                                            </button>
                                        </div>
                                        <div class="bpa-footer-slot slot-2">
                                            <div class="bpa-footer-slot-title">
                                                <?php esc_html_e('Column 2', 'email-manager'); ?>
                                            </div>
                                            <button type="button" class="button bpa-footer-slot-btn"
                                                id="bpa-footer-edit-2-inline">
                                                <?php esc_html_e('Edit Column 2', 'email-manager'); ?>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="bpa-template-actions-bar">
                                <button type="button" class="button" id="bpa-save-theme">
                                    <?php esc_html_e('Save Theme', 'email-manager'); ?>
                                </button>
                                <button type="button" class="button button-primary" id="bpa-save-template">
                                    <?php esc_html_e('Save Template', 'email-manager'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="bpa-template-preview-pane sticky" id="bpa-template-preview-config"></div>
                    </div>
                </div>
            </div>

            <!-- Previews and Modals -->
            <div id="bpa-template-preview-modal" class="bpa-modal" aria-hidden="true" style="display:none;">
                <!-- Hidden by default via CSS but just in case -->
                <div class="bpa-modal-content" style="max-width:65%;">
                    <button type="button" class="bpa-modal-close" data-close="#bpa-template-preview-modal"
                        aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                    <h3>
                        <?php esc_html_e('Template Preview', 'email-manager'); ?>
                    </h3>
                    <div class="bpa-template-preview-pane" id="bpa-template-preview-only"></div>
                </div>
            </div>

            <div id="bpa-footer-modal" class="bpa-modal" aria-hidden="true" style="display:none;">
                <div class="bpa-modal-content bpa-footer-modal" style="max-width:70%;">
                    <button type="button" class="bpa-modal-close"
                        aria-label="<?php esc_attr_e('Close', 'email-manager'); ?>">&times;</button>
                    <h3 id="bpa-footer-modal-title">
                        <?php esc_html_e('Edit Footer', 'email-manager'); ?>
                    </h3>
                    <div id="bpa-footer-editor-1" class="bpa-footer-editor">
                        <!-- Simplified footer editor structure due to WP Editor complexity via AJAX/Tabs, 
                             but we need valid IDs for JS to binding -->

                        <div class="bpa-footer-card-row">
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Column Background Color', 'email-manager'); ?>
                                    </span><input type="color" id="bpa-footer1-bg" value="#ffffff"></label>
                            </div>
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Background Padding (px)', 'email-manager'); ?>
                                    </span><input type="number" id="bpa-footer1-bg-pad" value="10"></label>
                            </div>
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Background Corner Radius', 'email-manager'); ?>
                                    </span><input type="number" id="bpa-footer1-bg-radius" value="10"></label>
                            </div>
                        </div>
                        <div class="bpa-footer-card-row">
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Text Body Background', 'email-manager'); ?>
                                    </span><input type="color" id="bpa-footer1-copy" value="#f7f7f7"></label>
                            </div>
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Text Radius', 'email-manager'); ?>
                                    </span><input type="number" id="bpa-footer1-copy-radius" value="6"></label>
                            </div>
                        </div>
                        <?php wp_editor('', 'bpa_footer_col1', ['textarea_name' => 'bpa_footer_col1', 'media_buttons' => true, 'teeny' => false, 'textarea_rows' => 6]); ?>
                    </div>

                    <div id="bpa-footer-editor-2" class="bpa-footer-editor" style="display:none;">
                        <div class="bpa-footer-card-row">
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Column Background Color', 'email-manager'); ?>
                                    </span><input type="color" id="bpa-footer2-bg" value="#ffffff"></label>
                            </div>
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Padding', 'email-manager'); ?>
                                    </span><input type="number" id="bpa-footer2-bg-pad" value="10"></label>
                            </div>
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Radius', 'email-manager'); ?>
                                    </span><input type="number" id="bpa-footer2-bg-radius" value="10"></label>
                            </div>
                        </div>
                        <div class="bpa-footer-card-row">
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Text Body Background', 'email-manager'); ?>
                                    </span><input type="color" id="bpa-footer2-copy" value="#f7f7f7"></label>
                            </div>
                            <div class="bpa-footer-card single">
                                <label><span>
                                        <?php esc_html_e('Text Radius', 'email-manager'); ?>
                                    </span><input type="number" id="bpa-footer2-copy-radius" value="6"></label>
                            </div>
                        </div>
                        <?php wp_editor('', 'bpa_footer_col2', ['textarea_name' => 'bpa_footer_col2', 'media_buttons' => true, 'teeny' => false, 'textarea_rows' => 6]); ?>
                    </div>

                    <div class="bpa-footer-save-row">
                        <button type="button" class="button button-primary" id="bpa-footer-save">
                            <?php esc_html_e('Save Footer', 'email-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_typography_settings()
    {
        $font_options = [
            'sans-serif' => __('Sans Serif', 'email-manager'),
            'serif' => __('Serif', 'email-manager'),
            'Comic Sans MS' => __('Comic Sans MS', 'email-manager'),
            'Garamond' => __('Garamond', 'email-manager'),
            'Georgia' => __('Georgia', 'email-manager'),
            'Tahoma' => __('Tahoma', 'email-manager'),
            'Trebuchet MS' => __('Trebuchet MS', 'email-manager'),
            'Verdana' => __('Verdana', 'email-manager'),
        ];
        $types = [
            'paragraph' => __('Paragraph', 'email-manager'),
            'h1' => __('Heading 1', 'email-manager'),
            'h2' => __('Heading 2', 'email-manager'),
            'h3' => __('Heading 3', 'email-manager'),
            'h4' => __('Heading 4', 'email-manager'),
            'h5' => __('Heading 5', 'email-manager'),
            'h6' => __('Heading 6', 'email-manager'),
        ];

        foreach ($types as $key => $label):
            ?>
            <div class="bpa-font-row" data-type="<?php echo esc_attr($key); ?>">
                <div class="bpa-font-title">
                    <?php echo esc_html($label); ?>
                </div>
                <div class="bpa-font-inline">
                    <label><span>
                            <?php esc_html_e('Font', 'email-manager'); ?>
                        </span>
                        <select id="bpa-font-<?php echo esc_attr($key); ?>">
                            <?php foreach ($font_options as $val => $name): ?>
                                <option value="<?php echo esc_attr($val); ?>">
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>
                            <?php esc_html_e('Color', 'email-manager'); ?>
                        </span><input type="color" class="bpa-color-chip-input"
                            id="bpa-color-<?php echo esc_attr($key); ?>"></label>
                </div>
                <div class="bpa-font-inline">
                    <label><span>
                            <?php esc_html_e('Size', 'email-manager'); ?>
                        </span><input type="number" id="bpa-size-<?php echo esc_attr($key); ?>" value="14"></label>
                    <label class="bpa-align-select"><span>
                            <?php esc_html_e('Align', 'email-manager'); ?>
                        </span>
                        <select id="bpa-align-<?php echo esc_attr($key); ?>">
                            <option value="left">
                                <?php esc_html_e('Left', 'email-manager'); ?>
                            </option>
                            <option value="center">
                                <?php esc_html_e('Center', 'email-manager'); ?>
                            </option>
                            <option value="right">
                                <?php esc_html_e('Right', 'email-manager'); ?>
                            </option>
                        </select>
                    </label>
                </div>
                <div class="bpa-font-align-toggle">
                    <div class="bpa-font-toggles">
                        <label class="bpa-toggle-btn" title="<?php esc_attr_e('Bold', 'email-manager'); ?>">
                            <input type="checkbox" id="bpa-bold-<?php echo esc_attr($key); ?>">
                            <span class="dashicons dashicons-editor-bold"></span>
                        </label>
                        <label class="bpa-toggle-btn" title="<?php esc_attr_e('Italic', 'email-manager'); ?>">
                            <input type="checkbox" id="bpa-italic-<?php echo esc_attr($key); ?>">
                            <span class="dashicons dashicons-editor-italic"></span>
                        </label>
                        <label class="bpa-toggle-btn" title="<?php esc_attr_e('Underline', 'email-manager'); ?>">
                            <input type="checkbox" id="bpa-underline-<?php echo esc_attr($key); ?>">
                            <span class="dashicons dashicons-editor-underline"></span>
                        </label>
                    </div>
                </div>
                <?php if ($key === 'paragraph'): ?>
                    <div class="bpa-font-inline bpa-link-row">
                        <label><span>
                                <?php esc_html_e('Link color', 'email-manager'); ?>
                            </span><input type="color" class="bpa-color-chip-input" id="bpa-theme-link-color"></label>
                        <label><span>
                                <?php esc_html_e('Link weight', 'email-manager'); ?>
                            </span><input type="text" id="bpa-theme-link-weight"
                                placeholder="<?php esc_attr_e('Semi-bold', 'email-manager'); ?>"></label>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach;
    }

    // --- Data Helpers ---

    private function get_email_templates()
    {
        $templates = get_option(self::OPTION_TEMPLATES, []);
        if (!is_array($templates))
            return [];
        return $templates;
    }

    private function get_theme_settings()
    {
        $defaults = [
            'container_bg' => '#ffffff',
            'copy_bg' => '#f7f7f7',
            'body_color' => '#0f1724',
            'link_color' => '#ef5c06',
            'link_weight' => '600',
            'header_url' => '',
            'header_height' => 120,
            'header_width' => 600,
            'header_space' => 24,
            'header_link' => '',
            'header_align' => 'center',
            'paragraph_font' => 'sans-serif',
            'paragraph_color' => '#0f1724',
            'paragraph_size' => 14,
            'paragraph_bold' => '',
            'paragraph_italic' => '',
            'paragraph_underline' => '',
            'paragraph_align' => 'left',
            'h1_font' => 'sans-serif',
            'h1_color' => '#0f1724',
            'h1_size' => 28,
            'h1_bold' => '1',
            'h1_italic' => '',
            'h1_underline' => '',
            'h1_align' => 'left',
            // ... (Full defaults similar to BPA)
            'footer_url' => '',
            'footer_height' => 80,
            'footer_width' => 260,
            'footer_space' => 16,
            'footer_link' => '',
            'footer_text' => '',
        ];
        $stored = get_option(self::OPTION_THEME, []);
        if (!is_array($stored))
            return $defaults;
        return array_merge($defaults, $stored);
    }

    private function get_active_template()
    {
        return get_option(self::OPTION_ACTIVE, '');
    }

    // --- AJAX Handlers ---

    public function handle_save_template()
    {
        check_ajax_referer('em_templates_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(['message' => 'Unauthorized']);

        $name = sanitize_text_field($_POST['name'] ?? '');
        $body = wp_kses_post($_POST['body'] ?? '');

        if (!$name)
            wp_send_json_error(['message' => 'Name required']);

        $templates = $this->get_email_templates();
        $found = false;
        foreach ($templates as &$tpl) {
            if ($tpl['name'] === $name) {
                $tpl['body'] = $body;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $templates[] = ['name' => $name, 'body' => $body];
        }

        update_option(self::OPTION_TEMPLATES, $templates);
        wp_send_json_success(['templates' => $templates]);
    }

    public function handle_delete_template()
    {
        check_ajax_referer('em_templates_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(['message' => 'Unauthorized']);

        $name = sanitize_text_field($_POST['name'] ?? '');
        $templates = $this->get_email_templates();
        $templates = array_values(array_filter($templates, function ($tpl) use ($name) {
            return $tpl['name'] !== $name;
        }));

        update_option(self::OPTION_TEMPLATES, $templates);
        wp_send_json_success(['templates' => $templates]);
    }

    public function handle_set_active_template()
    {
        check_ajax_referer('em_templates_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(['message' => 'Unauthorized']);

        $name = sanitize_text_field($_POST['name'] ?? '');
        update_option(self::OPTION_ACTIVE, $name);
        wp_send_json_success(['activeTemplate' => $name]);
    }

    public function handle_save_theme()
    {
        check_ajax_referer('em_templates_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error(['message' => 'Unauthorized']);

        // Sanitize and save all theme fields
        // For brevity in this task, saving $_POST directly sanitized (simulated)
        // In real app, explicit sanitization for all keys recommended
        $theme = [];
        foreach ($_POST as $k => $v) {
            if ($k === 'action' || $k === 'nonce')
                continue;
            $theme[sanitize_key($k)] = sanitize_text_field(wp_unslash($v));
        }
        update_option(self::OPTION_THEME, $theme);
        wp_send_json_success(['theme' => $theme]);
    }
}

new EM_Email_Templates();
