/* Chat Widget Launcher
 *
 * - Click FAB → opens glassmorphic panel containing the chat
 * - Reuses the existing initChatForm() from chat-frontend.js when present
 * - Preview mode (admin edit page): opens centered, marks as preview, no submit
 */
(function ($) {
    'use strict';

    var $document = $(document);

    function buildPanelMarkup(formData, opts) {
        opts = opts || {};
        var pos = formData.position || 'bottom-right';
        var radius = formData.borderRadius || 16;
        var color = formData.borderColor || '#6366f1';
        var isPreview = !!opts.preview;
        var posAttr = isPreview ? 'preview' : pos;
        var styles = '--cw-border-color:' + color + ';--cw-border-radius:' + radius + 'px;';
        if (isPreview) {
            styles += 'top:50%;left:50%;transform:translate(-50%,-50%);height:80vh;max-height:720px;';
        }
        var html = ''
            + '<div class="chat-widget-panel" data-position="' + posAttr + '" data-form-id="' + formData.id + '" style="' + styles + '">'
            +   '<div class="chat-widget-panel__header">'
            +     '<h3 class="chat-widget-panel__title">' + escapeHtml(formData.title || 'Chat') + '</h3>'
            +     '<button type="button" class="chat-widget-panel__close" aria-label="Close">×</button>'
            +   '</div>'
            +   '<div class="chat-widget-panel__body">'
            +     (isPreview ? '<div class="chat-preview-banner">🧪 Preview mode — submissions are not recorded.</div>' : '')
            +     '<div id="chat-form-' + formData.id + (isPreview ? '-preview' : '') + '" class="chat-form-container glassmorphism" '
            +          'data-form-id="' + formData.id + '" '
            +          'data-bot-avatar="' + escapeAttr(formData.botAvatar || '') + '" '
            +          (isPreview ? 'data-preview-mode="1" ' : '')
            +          'data-bootstrap=\'' + escapeAttr(JSON.stringify({ questions: formData.questions || [], thankYou: formData.thankYou || '' })) + '\'>'
            +       '<div class="chat-messages"></div>'
            +       '<div class="chat-input-area">'
            +         '<input type="text" class="chat-input" placeholder="Type your answer..." />'
            +         '<button class="chat-submit-btn">Send</button>'
            +       '</div>'
            +     '</div>'
            +   '</div>'
            + '</div>';
        return html;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function openWidget($launcher) {
        var formDataRaw = $launcher.attr('data-form');
        if (!formDataRaw) return;
        var formData;
        try { formData = JSON.parse(formDataRaw); } catch (e) { return; }

        // Toggle: if already open, close
        var existing = $('.chat-widget-panel[data-form-id="' + formData.id + '"]');
        if (existing.length) { existing.remove(); return; }

        var $panel = $(buildPanelMarkup(formData, { preview: false }));
        $('body').append($panel);
        $panel.find('.chat-widget-panel__close').on('click', function () { $panel.remove(); });

        // Init the chat — reuse existing initChatForm or fall back to fetching questions
        var $container = $panel.find('.chat-form-container');
        if (typeof window.chatFormsInitContainer === 'function') {
            window.chatFormsInitContainer($container);
        } else if (typeof window.jQuery !== 'undefined') {
            // chat-frontend.js binds initChatForm on its closure; we trigger via DOM-ready re-scan
            $(document).trigger('chat_forms_init', [$container]);
        }
    }

    function openPreview(formId, $sourceBtn) {
        // Build form data from the admin edit page's current question fields (live, unsaved)
        var formData = collectFormDataFromAdmin(formId);
        if (!formData) return;
        formData.id = formId;
        formData.title = $('input#title').val() || 'Preview';

        // Backdrop + centered panel
        var $backdrop = $('<div class="chat-preview-backdrop"></div>');
        var $panel = $(buildPanelMarkup(formData, { preview: true }));
        $('body').append($backdrop).append($panel);

        function close() {
            $panel.remove();
            $backdrop.remove();
            $document.off('keydown.chatPreview');
        }
        $panel.find('.chat-widget-panel__close').on('click', close);
        $backdrop.on('click', close);
        $document.on('keydown.chatPreview', function (e) { if (e.key === 'Escape') close(); });

        var $container = $panel.find('.chat-form-container');
        // chat-frontend.js will read data-bootstrap and skip the AJAX fetch
        if (typeof window.chatFormsInitContainer === 'function') {
            window.chatFormsInitContainer($container);
        } else {
            $(document).trigger('chat_forms_init', [$container]);
        }
    }

    function collectFormDataFromAdmin(formId) {
        // Walk the questions metabox and snapshot whatever is currently entered
        var $questions = $('#chat-forms-questions-container .chat-form-question');
        if (!$questions.length) return { questions: [], thankYou: '' };
        var qs = [];
        $questions.each(function () {
            var $q = $(this);
            var type = $q.find('.question-type').val() || 'text';
            var item = {
                text: $q.find('.question-text').val() || '',
                type: type,
                validation: {
                    required: $q.find('input[name*="[validation][required]"]').is(':checked'),
                    min_length: parseInt($q.find('input[name*="[validation][min_length]"]').val(), 10) || 0,
                    max_length: parseInt($q.find('input[name*="[validation][max_length]"]').val(), 10) || 0,
                    error_message: $q.find('input[name*="[validation][error_message]"]').val() || ''
                }
            };
            if (type === 'multiple') {
                item.options = [];
                $q.find('.option-item').each(function () {
                    var $o = $(this);
                    item.options.push({
                        label: $o.find('.option-label').val() || '',
                        value: $o.find('.option-value').val() || '',
                        image: $o.find('.option-image-url').val() || '',
                        response_html: $o.find('.option-response-html').val() || ''
                    });
                });
            }
            if (type === 'info_block') {
                // Pull from TinyMCE if available, else textarea
                var editorId = 'info_block_' + ($q.index());
                if (window.tinymce && tinymce.get(editorId)) {
                    item.content = tinymce.get(editorId).getContent();
                } else {
                    item.content = $q.find('textarea[name*="[content]"]').val() || '';
                }
            }
            if (type === 'prompt_response') {
                item.prompt = $q.find('.prompt-response-template').val() || '';
            }
            qs.push(item);
        });
        return {
            questions: qs,
            thankYou: '',
            botAvatar: $('#chat-form-bot-avatar-url').val() || '',
            borderColor: ($('input[name="chat_form_widget_config[border_color]"]').val() || '#6366f1'),
            borderRadius: parseInt($('input[name="chat_form_widget_config[border_radius]"]').val(), 10) || 16
        };
    }

    /* ---------- Bindings ---------- */
    $document.on('click', '.chat-widget-launcher__btn', function () {
        openWidget($(this).closest('.chat-widget-launcher'));
    });

    // Admin live preview button
    $document.on('click', '#preview-form-btn', function (e) {
        e.preventDefault();
        var formId = parseInt($(this).data('form-id'), 10);
        if (!formId) return;
        openPreview(formId, $(this));
    });

    // Expose so admin.js can also trigger preview if needed
    window.chatWidgetOpenPreview = openPreview;

}(jQuery));
