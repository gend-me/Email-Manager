(function ($) {
    const cfg = window.bpaAdmin || {
        ajaxUrl: '',
        nonce: '',
        pointTypes: {},
        templates: [],
        bpMemberTypes: [],
        theme: {},
        lastId: 0,
    };
    const state = {
        selectedUsers: {},
        selectedEmails: new Set(),
        templates: cfg.templates || [],
        activeTemplate: cfg.activeTemplate || '',
        unmatchedEmails: [],
    };

    const $tabButtons = $('.bpa-tab');
    const $tabPanels = $('.bpa-tab-panel');
    const $selectedList = $('#bpa-selected-list');
    const $selectedCount = $('#bpa-selected-count');
    const $hiddenUserIds = $('#bpa-selected-user-ids');
    const $hiddenEmails = $('#bpa-selected-emails');
    const $unmatchedWrap = $('#bpa-unmatched');
    const $unmatchedList = $('#bpa-unmatched-list');
    const $statusMembers = $('#bpa-status-members');
    const $statusPoints = $('#bpa-status-points');
    const $statusLog = $('#bpa-status-log');
    const $statusEmail = $('#bpa-status-email');
    const $statusMethod = $('#bpa-status-method');
    const $statusEta = $('#bpa-status-eta');
    const $bpSelect = $('#bpa-bp-types');
    const $roleSelect = $('#bpa-user-roles');
    const $progressSelect = $('#bpa-progress-select');
    const $templateNewBtn = $('#bpa-template-new');
    const $sendTestBtn = $('#bpa-send-test');
    const $sendMode = $('#bpa-send-mode');
    const $scheduleWrap = $('#bpa-schedule-wrap');
    const $scheduleInput = $('#bpa-schedule');
    const $statusCard = $('#bpa-status-card');
    const $templateCreateHeader = $('#bpa-template-create-header');
    const $csvMatchBtn = $('#bpa-csv-match');
    const $csvStatus = $('#bpa-upload-status');
    const $csvInput = $('#bpa-csv-upload');
    let prevBpSelection = new Set(getBpSelection());
    let prevRoleSelection = new Set(getRoleSelection());
    let pollTimer = null;
    let audienceCount = 0;

    function switchTab(targetId) {
        const panelSelector = `#${targetId}`;
        $tabButtons.removeClass('active');
        $tabPanels.removeClass('active');
        $tabButtons.filter(`[data-tab="${targetId}"]`).addClass('active');
        $(panelSelector).addClass('active');
    }

    $tabButtons.on('click', function () {
        const target = $(this).data('tab');
        switchTab(target);
    });

    function renderSelected() {
        $selectedList.empty();
        Object.values(state.selectedUsers).forEach((user) => {
            $selectedList.append(
                $('<span/>', { class: 'bpa-pill' }).append(
                    `${user.name} (${user.email}) `,
                    $('<button type="button" aria-label="Remove">x</button>').on('click', () => {
                        delete state.selectedUsers[user.id];
                        renderSelected();
                    })
                )
            );
        });
        const selectedUserEmails = new Set(
            Object.values(state.selectedUsers)
                .map((u) => (u.email || '').toLowerCase())
                .filter(Boolean)
        );
        state.selectedEmails.forEach((email) => {
            const lower = (email || '').toLowerCase();
            if (selectedUserEmails.has(lower)) {
                return;
            }
            $selectedList.append(
                $('<span/>', { class: 'bpa-pill' }).append(
                    `${email} `,
                    $('<button type="button" aria-label="Remove">x</button>').on('click', () => {
                        state.selectedEmails.delete(lower);
                        renderSelected();
                    })
                )
            );
        });
        const directSelected = Object.keys(state.selectedUsers).length + state.selectedEmails.size;
        const withAudience = directSelected + audienceCount;
        $selectedCount.text(withAudience);
        $hiddenUserIds.val(Object.keys(state.selectedUsers).join(','));
        $hiddenEmails.val(Array.from(state.selectedEmails).join(','));
        $statusMembers.text(withAudience);
        renderUnmatched();
        renderSelectedMeta();
    }

    function renderUnmatched() {
        $unmatchedList.empty();
        // Drop any unmatched that are already selected as users/emails
        const selectedEmailSet = new Set(
            Object.values(state.selectedUsers)
                .map((u) => (u.email || '').toLowerCase())
                .filter(Boolean)
        );
        state.unmatchedEmails = state.unmatchedEmails.filter((email) => {
            const lower = (email || '').toLowerCase();
            return !selectedEmailSet.has(lower) && !state.selectedEmails.has(lower);
        });
        if (!state.unmatchedEmails.length) {
            $unmatchedWrap.hide();
            return;
        }
        $unmatchedWrap.show();
        state.unmatchedEmails.forEach((email) => {
            const item = $('<div/>', { class: 'bpa-unmatched-item' }).append(
                $('<span/>').text(email),
                $('<button/>', {
                    type: 'button',
                    class: 'bpa-unmatched-remove',
                    'aria-label': 'Remove unmatched email',
                }).text('×').on('click', () => {
                    state.unmatchedEmails = state.unmatchedEmails.filter((em) => em !== email);
                    state.selectedEmails.delete(email);
                    renderSelected();
                })
            );
            $unmatchedList.append(item);
        });
    }

    function renderSelectedMeta() {
        const $roles = $('#bpa-selected-roles').empty();
        const $types = $('#bpa-selected-types').empty();
        const bpValues = getBpSelection();
        const roleValues = getRoleSelection();

        bpValues.forEach((type) => {
            $types.append(
                $('<span/>', { class: 'bpa-pill' }).text(`Type: ${type}`)
            );
        });
        roleValues.forEach((role) => {
            $roles.append(
                $('<span/>', { class: 'bpa-pill' }).text(`Role: ${role}`)
            );
        });
    }

    function renderTemplates() {
        const $listWrap = $('#bpa-template-list').empty().addClass('bpa-template-list-wrap');
        const $list = $('<div/>', { class: 'bpa-template-list-col' });
        $listWrap.append($list);
        // Ensure list view shows the create button and hides the back button
        $('#bpa-template-create-header').show();
        $('#bpa-template-back').hide();
        if (!state.templates.length) {
            $list.append($('<p/>').text('No templates saved yet.'));
            return;
        }
        state.templates.forEach((tpl) => {
            const isActive = state.activeTemplate && tpl.name === state.activeTemplate;
            const item = $('<div/>', { class: `bpa-template-item bpa-template-card${isActive ? ' is-active' : ''}`, title: 'Click to preview' });
            const left = $('<div/>', { class: 'bpa-template-card-left' }).append(
                $('<strong/>').text(tpl.name)
            );
            if (isActive) {
                left.append($('<div/>', { class: 'bpa-template-badge' }).text('Active'));
            }
            const actions = $('<div/>').addClass('bpa-template-actions');
            actions.append(
                $('<button/>', { type: 'button', class: 'button bpa-action-use' })
                    .text('Use')
                    .on('click', (e) => {
                        e.stopPropagation();
                        const setActive = () =>
                            $.post(cfg.ajaxUrl, {
                                action: 'bpa_set_active_template',
                                nonce: cfg.nonce,
                                name: tpl.name,
                            });

                        const applySelection = () => {
                            state.activeTemplate = tpl.name;
                            if (window.bpaAdmin) {
                                window.bpaAdmin.activeTemplate = tpl.name;
                            }
                            $('#bpa-email-template').val(tpl.name);
                            $('#bpa-template-name').text(tpl.name);
                            setEditorContent('bpa_email_body', tpl.body || '');
                            $('#bpa-template-body').val(tpl.body || '');
                            renderSelectedTemplateCard(tpl);
                            renderTemplates();
                            $(document).trigger('bpa:templateSelected', { name: tpl.name });
                            closeTemplateModal();
                        };

                        if (cfg.ajaxUrl && cfg.nonce) {
                            setActive()
                                .done((resp) => {
                                    if (resp && resp.success) {
                                        applySelection();
                                    } else {
                                        applySelection();
                                    }
                                })
                                .fail(() => applySelection());
                        } else {
                            applySelection();
                        }
                    })
            );
            actions.append(
                $('<button/>', { type: 'button', class: 'button bpa-action-edit' })
                    .text('Edit')
                    .on('click', (e) => {
                        e.stopPropagation();
                        $('#bpa-new-template-name').val(tpl.name);
                        $('#bpa-email-template').val(tpl.name);
                        $('#bpa-template-name').text(tpl.name);
                        setEditorContent('bpa_email_body', tpl.body || '');
                        $('#bpa-template-body').val(tpl.body || '');
                        renderSelectedTemplateCard(tpl);
                        openTemplateModal(true);
                    })
            );
            actions.append(
                $('<button/>', { type: 'button', class: 'button button-secondary bpa-action-delete' })
                    .text('Delete')
                    .on('click', (e) => {
                        e.stopPropagation();
                        if (!window.confirm('Delete this template?')) return;
                        $.post(cfg.ajaxUrl, {
                            action: 'bpa_delete_template',
                            nonce: cfg.nonce,
                            name: tpl.name,
                        }).done((resp) => {
                            if (resp.success && resp.data.templates) {
                                state.templates = resp.data.templates;
                                renderTemplates();
                            }
                        });
                    })
            );
            left.append(actions);
            const rightPreview = $('<div/>', {
                class: 'bpa-template-thumb',
                role: 'button',
                'aria-label': `Preview ${tpl.name}`,
            }).html(previewTemplate(tpl, true));
            rightPreview.on('click', (e) => {
                e.stopPropagation();
                const html = previewTemplate(tpl);
                $('#bpa-template-preview-only').html(html);
                $('#bpa-template-preview-modal').attr('aria-hidden', 'false');
            });
            item.append(left, rightPreview);
            item.on('click', () => {
                const html = previewTemplate(tpl);
                $('#bpa-template-preview-only').html(html);
                $('#bpa-template-preview-modal').attr('aria-hidden', 'false');
            });
            $list.append(item);
        });
    }
    function openTemplateModal(configMode = false, previewOnly = false) {
        renderTemplates();
        hydrateThemeForm();
        $('#bpa-template-browse').show();
        $('#bpa-template-config').hide();
        // Toggle header buttons: list gets create, edit gets back
        if (previewOnly) {
            $('#bpa-template-create-header').hide();
            $('#bpa-template-back').hide();
        } else if (configMode) {
            $('#bpa-template-create-header').hide();
            $('#bpa-template-back').show();
        } else {
            $('#bpa-template-create-header').show();
            $('#bpa-template-back').hide();
        }
        if (configMode) {
            $('#bpa-template-browse').hide();
            $('#bpa-template-config').show();
        } else {
            $('#bpa-template-browse').show();
            $('#bpa-template-config').hide();
            $('#bpa-template-create-header').show();
            $('#bpa-template-back').hide();
        }
        if (previewOnly) {
            $('#bpa-template-browse').hide();
            $('#bpa-template-config').hide();
            renderThemePreview(false);
        }
    $('#bpa-template-modal').attr('aria-hidden', 'false');
        renderThemePreview(configMode && !previewOnly);
        updateFooterButtons();
        updateFooterEditorStyles();
    }

    function closeTemplateModal() {
        if (cfg.embedTemplates) {
            $('#bpa-template-browse').show();
            $('#bpa-template-config').hide();
            $('#bpa-template-back').hide();
            $('#bpa-template-create-header').show();
            return;
        }
        $('#bpa-template-modal').attr('aria-hidden', 'true');
        $('#bpa-new-template-name').val('');
    }

    $('#bpa-template-picker').on('click', () => openTemplateModal(false, false));
    $('#bpa-template-change').on('click', () => openTemplateModal(false, false));
    if (!cfg.embedTemplates) {
        $('#bpa-template-modal .bpa-modal-close').on('click', closeTemplateModal);
        $('#bpa-template-modal').on('click', function (e) {
            if (e.target === this) {
                closeTemplateModal();
            }
        });
    }

    $templateNewBtn.on('click', function () {
        $('#bpa-new-template-name').val('');
        $('#bpa-email-template').val('');
        $('#bpa-template-name').text('');
        setEditorContent('bpa_email_body', '');
        $('#bpa-template-body').val('');
        renderSelectedTemplateCard(null);
        openTemplateModal(true, false);
    });

    $('[data-close="#bpa-template-preview-modal"]').on('click', function () {
        $('#bpa-template-preview-modal').attr('aria-hidden', 'true');
    });

    $('.bpa-template-settings').on('input change', 'input, select, textarea', function () {
        renderThemePreview(true);
        updateColorChips();
        updateFooterEditorStyles();
    });

    // Embedded template manager (gdc-app-email app-template tab)
    if (cfg.embedTemplates) {
        openTemplateModal(false, false);
        $('#bpa-template-modal').attr('aria-hidden', 'false');
    }

    $('#bpa-footer-columns').on('change', updateFooterButtons);
    function updateFooterButtons() {
        const val = $('#bpa-footer-columns').val();
        if (val === '2') {
            $('#bpa-footer-edit-2').show();
            $('#bpa-footer-editor-2').show();
            $('#bpa-footer-layout-visual').removeClass('one-col');
        } else {
            $('#bpa-footer-edit-2').hide();
            $('#bpa-footer-editor-2').hide();
            $('#bpa-footer-layout-visual').addClass('one-col');
        }
        updateFooterEditorStyles();
    }
    updateFooterButtons();
    updateFooterEditorStyles();

    $('#bpa-footer-edit-1-inline').on('click', () => openFooterEditor(1));
    $('#bpa-footer-edit-2-inline').on('click', () => openFooterEditor(2));
    $('#bpa-footer-editor-1, #bpa-footer-editor-2').on('input change', 'input', function () {
        updateFooterEditorStyles();
        renderThemePreview(true);
    });

    function openFooterEditor(col) {
        $('#bpa-footer-modal-title').text(col === 2 ? 'Edit Footer Column 2' : 'Edit Footer Column 1');
        $('#bpa-footer-editor-1, #bpa-footer-editor-2').hide();
        $(`#bpa-footer-editor-${col}`).show();
        $('#bpa-footer-modal').attr('aria-hidden', 'false');
        updateFooterEditorStyles();
    }

    $('#bpa-footer-modal .bpa-modal-close').on('click', () => $('#bpa-footer-modal').attr('aria-hidden', 'true'));
    $('#bpa-footer-modal').on('click', function (e) {
        if (e.target === this) {
            $('#bpa-footer-modal').attr('aria-hidden', 'true');
        }
    });

    $('#bpa-footer-save').on('click', function () {
        $('#bpa-footer-modal').attr('aria-hidden', 'true');
        renderThemePreview(true);
    });


    let headerFrame = null;
    $('#bpa-theme-header-upload, #bpa-theme-footer-upload').on('click', function (e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) {
            alert('Media library is not available.');
            return;
        }
        if (!headerFrame) {
            headerFrame = wp.media({
                title: 'Select Image',
                button: { text: 'Use this image' },
                multiple: false,
            });
            headerFrame.on('select', function () {
                const attachment = headerFrame.state().get('selection').first().toJSON();
                const target = e.target.id === 'bpa-theme-footer-upload' ? '#bpa-theme-footer-url' : '#bpa-theme-header-url';
                $(target).val(attachment.url);
                renderThemePreview(true);
            });
        }
        headerFrame.open();
    });

    $('#bpa-template-back').on('click', function () {
        $('#bpa-template-config').hide();
        $('#bpa-template-browse').show();
        $('#bpa-template-back').hide();
        $('#bpa-template-create-header').show();
    });

    $templateCreateHeader.off('click').on('click', function () {
        $('#bpa-new-template-name').val('');
        $('#bpa-template-body').val('');
        setEditorContent('bpa_email_body', '');
        renderSelectedTemplateCard(null);
        $('#bpa-email-template').val('');
        $('#bpa-template-name').text('');
        openTemplateModal(true, false);
    });

    function getEditorContent(editorId) {
        const editor = window.tinyMCE ? tinyMCE.get(editorId) : null;
        if (editor && !editor.isHidden()) {
            return editor.getContent();
        }
        return $('#' + editorId).val();
    }

    function setEditorContent(editorId, content) {
        const editor = window.tinyMCE ? tinyMCE.get(editorId) : null;
        if (editor) {
            editor.setContent(content);
        }
        $('#' + editorId).val(content);
    }

    $('#bpa-save-template').on('click', () => {
        const name = $('#bpa-new-template-name').val().trim();
        if (!name) {
            return;
        }
        const body = getEditorContent('bpa_email_body') || '';
        $('#bpa-template-body').val(body);
        $.post(cfg.ajaxUrl, {
            action: 'bpa_save_template',
            nonce: cfg.nonce,
            name,
            body,
        }).done((resp) => {
            if (resp.success && resp.data && resp.data.templates) {
                state.templates = resp.data.templates;
                $('#bpa-email-template').val(name);
                $('#bpa-template-name').text(name);
                $('#bpa-template-body').val(body);
                renderTemplates();
                if (cfg.embedTemplates) {
                    $('#bpa-template-config').hide();
                    $('#bpa-template-browse').show();
                    $('#bpa-template-back').hide();
                    $('#bpa-template-create-header').show();
                } else {
                    closeTemplateModal();
                }
            }
        });
    });

    $('#bpa-save-theme').off('click').on('click', function () {
        const payload = collectThemeForm();
        $.post(cfg.ajaxUrl, {
            action: 'bpa_save_theme',
            nonce: cfg.nonce,
            ...payload,
        }).done((resp) => {
            if (resp.success && resp.data.theme) {
                renderThemePreview();
                if (!$('#bpa-theme-toast').length) {
                    $('body').append('<div id="bpa-theme-toast" class="bpa-toast">Theme saved</div>');
                }
                const $toast = $('#bpa-theme-toast');
                $toast.addClass('show');
                setTimeout(() => $toast.removeClass('show'), 1800);
            } else {
                alert('Failed to save theme.');
            }
        });
    });

    function collectThemeForm() {
        const typeDefaults = {
            paragraph: { font: 'sans-serif', color: '#0f1724', size: 14, bold: '', italic: '', underline: '', align: 'left' },
            h1: { font: 'sans-serif', color: '#0f1724', size: 28, bold: '1', italic: '', underline: '', align: 'left' },
            h2: { font: 'sans-serif', color: '#ef5c06', size: 24, bold: '1', italic: '', underline: '', align: 'left' },
            h3: { font: 'sans-serif', color: '#0f1724', size: 20, bold: '1', italic: '', underline: '', align: 'left' },
            h4: { font: 'sans-serif', color: '#0f1724', size: 18, bold: '', italic: '', underline: '', align: 'left' },
            h5: { font: 'sans-serif', color: '#0f1724', size: 16, bold: '', italic: '', underline: '', align: 'left' },
            h6: { font: 'sans-serif', color: '#0f1724', size: 14, bold: '', italic: '', underline: '', align: 'left' },
        };
        const textTypes = Object.keys(typeDefaults);
        const typePayload = {};
        textTypes.forEach((type) => {
            typePayload[`${type}_font`] = $(`#bpa-font-${type}`).val() || typeDefaults[type].font;
            typePayload[`${type}_color`] = $(`#bpa-color-${type}`).val() || typeDefaults[type].color;
            typePayload[`${type}_size`] = $(`#bpa-size-${type}`).val() || typeDefaults[type].size;
            typePayload[`${type}_bold`] = $(`#bpa-bold-${type}`).is(':checked') ? '1' : '';
            typePayload[`${type}_italic`] = $(`#bpa-italic-${type}`).is(':checked') ? '1' : '';
            typePayload[`${type}_underline`] = $(`#bpa-underline-${type}`).is(':checked') ? '1' : '';
            typePayload[`${type}_align`] = $(`#bpa-align-${type}`).val() || typeDefaults[type].align;
        });
        return {
            container_bg: $('#bpa-theme-container-bg').val(),
            copy_bg: $('#bpa-theme-copy-bg').val(),
            body_color: $('#bpa-theme-body-color').val(),
            link_color: $('#bpa-theme-link-color').val(),
            link_weight: $('#bpa-theme-link-weight').val(),
            header_url: $('#bpa-theme-header-url').val(),
            header_height: $('#bpa-theme-header-height').val(),
            header_width: $('#bpa-theme-header-width').val(),
            header_space: $('#bpa-theme-header-space').val(),
            header_link: $('#bpa-theme-header-link').val(),
            header_align: $('#bpa-theme-header-align').val(),
            ...typePayload,
            footer_url: $('#bpa-theme-footer-url').val(),
            footer_height: $('#bpa-theme-footer-height').val(),
            footer_width: $('#bpa-theme-footer-width').val(),
            footer_space: $('#bpa-theme-footer-space').val(),
            footer_link: $('#bpa-theme-footer-link').val(),
            footer_text: $('#bpa-theme-footer-text').val(),
            footer_col1_bg: $('#bpa-footer1-bg').val(),
            footer_col1_copy: $('#bpa-footer1-copy').val(),
            footer_col1_bg_pad: parseInt($('#bpa-footer1-bg-pad').val() || '10', 10),
            footer_col1_bg_radius: parseInt($('#bpa-footer1-bg-radius').val() || '10', 10),
            footer_col1_copy_radius: parseInt($('#bpa-footer1-copy-radius').val() || '6', 10),
            footer_col2_bg: $('#bpa-footer2-bg').val(),
            footer_col2_copy: $('#bpa-footer2-copy').val(),
            footer_col2_bg_pad: parseInt($('#bpa-footer2-bg-pad').val() || '10', 10),
            footer_col2_bg_radius: parseInt($('#bpa-footer2-bg-radius').val() || '10', 10),
            footer_col2_copy_radius: parseInt($('#bpa-footer2-copy-radius').val() || '6', 10),
        };
    }

    function updateFooterEditorStyles() {
        const col1Bg = $('#bpa-footer1-bg').val() || '#ffffff';
        const col1Pad = parseInt($('#bpa-footer1-bg-pad').val() || '10', 10);
        const col1BgRadius = parseInt($('#bpa-footer1-bg-radius').val() || '10', 10);
        const col1Copy = $('#bpa-footer1-copy').val() || '#f7f7f7';
        const col1CopyRadius = parseInt($('#bpa-footer1-copy-radius').val() || '6', 10);
        const col2Bg = $('#bpa-footer2-bg').val() || '#ffffff';
        const col2Pad = parseInt($('#bpa-footer2-bg-pad').val() || '10', 10);
        const col2BgRadius = parseInt($('#bpa-footer2-bg-radius').val() || '10', 10);
        const col2Copy = $('#bpa-footer2-copy').val() || '#f7f7f7';
        const col2CopyRadius = parseInt($('#bpa-footer2-copy-radius').val() || '6', 10);

        [
            { id: 1, bg: col1Bg, pad: col1Pad, radius: col1BgRadius, copy: col1Copy, copyRadius: col1CopyRadius },
            { id: 2, bg: col2Bg, pad: col2Pad, radius: col2BgRadius, copy: col2Copy, copyRadius: col2CopyRadius },
        ].forEach((cfg) => {
            const $wrap = $(`#bpa-footer-editor-${cfg.id}`);
            const $editor = $wrap.find('.wp-editor-container');
            $wrap.css({
                background: cfg.bg,
                padding: `${cfg.pad}px`,
                borderRadius: `${cfg.radius}px`,
                boxShadow: '0 10px 24px rgba(0,0,0,0.25)',
            });
            $editor.css({
                background: cfg.copy,
                borderRadius: `${cfg.copyRadius}px`,
                padding: '6px',
            });
        });
    }

    function hydrateThemeForm() {
        const t = cfg.theme || {};
        const typeDefaults = {
            paragraph: { font: 'sans-serif', color: '#0f1724', size: 14, bold: '', italic: '', underline: '', align: 'left' },
            h1: { font: 'sans-serif', color: '#0f1724', size: 28, bold: '1', italic: '', underline: '', align: 'left' },
            h2: { font: 'sans-serif', color: '#ef5c06', size: 24, bold: '1', italic: '', underline: '', align: 'left' },
            h3: { font: 'sans-serif', color: '#0f1724', size: 20, bold: '1', italic: '', underline: '', align: 'left' },
            h4: { font: 'sans-serif', color: '#0f1724', size: 18, bold: '', italic: '', underline: '', align: 'left' },
            h5: { font: 'sans-serif', color: '#0f1724', size: 16, bold: '', italic: '', underline: '', align: 'left' },
            h6: { font: 'sans-serif', color: '#0f1724', size: 14, bold: '', italic: '', underline: '', align: 'left' },
        };
        $('#bpa-theme-container-bg').val(t.container_bg || '#ffffff');
        $('#bpa-theme-copy-bg').val(t.copy_bg || '#f7f7f7');
        $('#bpa-theme-body-color').val(t.body_color || '#0f1724');
        $('#bpa-theme-link-color').val(t.link_color || '#ef5c06');
        $('#bpa-theme-link-weight').val(t.link_weight || '600');
        $('#bpa-theme-header-url').val(t.header_url || '');
        $('#bpa-theme-header-height').val(t.header_height || 120);
        $('#bpa-theme-header-width').val(t.header_width || 600);
        $('#bpa-theme-header-space').val(t.header_space || 24);
        $('#bpa-theme-header-link').val(t.header_link || '');
        $('#bpa-theme-header-align').val(t.header_align || 'center');
        const textTypes = Object.keys(typeDefaults);
        textTypes.forEach((type) => {
            const defaults = typeDefaults[type];
            $(`#bpa-font-${type}`).val(t[`${type}_font`] || defaults.font);
            $(`#bpa-color-${type}`).val(t[`${type}_color`] || defaults.color);
            $(`#bpa-size-${type}`).val(t[`${type}_size`] || defaults.size);
            $(`#bpa-bold-${type}`).prop('checked', (t[`${type}_bold`] || defaults.bold) === '1');
            $(`#bpa-italic-${type}`).prop('checked', (t[`${type}_italic`] || defaults.italic) === '1');
            $(`#bpa-underline-${type}`).prop('checked', (t[`${type}_underline`] || defaults.underline) === '1');
            $(`#bpa-align-${type}`).val(t[`${type}_align`] || defaults.align);
        });
        $('#bpa-theme-footer-url').val(t.footer_url || '');
        $('#bpa-theme-footer-height').val(t.footer_height || 80);
        $('#bpa-theme-footer-width').val(t.footer_width || 260);
        $('#bpa-theme-footer-space').val(t.footer_space || 16);
        $('#bpa-theme-footer-link').val(t.footer_link || '');
        $('#bpa-theme-footer-text').val(t.footer_text || '');
        $('#bpa-footer1-bg').val(t.footer_col1_bg || '#ffffff');
        $('#bpa-footer1-copy').val(t.footer_col1_copy || '#f7f7f7');
        $('#bpa-footer1-bg-pad').val(t.footer_col1_bg_pad || 10);
        $('#bpa-footer1-bg-radius').val(t.footer_col1_bg_radius || 10);
        $('#bpa-footer1-copy-radius').val(t.footer_col1_copy_radius || 6);
        $('#bpa-footer2-bg').val(t.footer_col2_bg || '#ffffff');
        $('#bpa-footer2-copy').val(t.footer_col2_copy || '#f7f7f7');
        $('#bpa-footer2-bg-pad').val(t.footer_col2_bg_pad || 10);
        $('#bpa-footer2-bg-radius').val(t.footer_col2_bg_radius || 10);
        $('#bpa-footer2-copy-radius').val(t.footer_col2_copy_radius || 6);
        updateColorChips();
        updateFooterEditorStyles();
    }

    function renderThemePreview(configMode = true) {
        const t = collectThemeForm();
        const body = getEditorContent('bpa_email_body') || '';
        const footerLayout = $('#bpa-footer-columns').val() || '1';
        const footerCol1 = getEditorContent('bpa_footer_col1') || '';
        const footerCol2 = getEditorContent('bpa_footer_col2') || '';
        const col1Bg = t.footer_col1_bg || t.copy_bg;
        const col1Copy = t.footer_col1_copy || t.copy_bg;
        const col1Pad = t.footer_col1_bg_pad || 10;
        const col1BgRadius = t.footer_col1_bg_radius || 10;
        const col1CopyRadius = t.footer_col1_copy_radius || 6;
        const col2Bg = t.footer_col2_bg || t.copy_bg;
        const col2Copy = t.footer_col2_copy || t.copy_bg;
        const col2Pad = t.footer_col2_bg_pad || 10;
        const col2BgRadius = t.footer_col2_bg_radius || 10;
        const col2CopyRadius = t.footer_col2_copy_radius || 6;
        let footerHtml = '';
        if (footerLayout === '2') {
            footerHtml = `
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="width:50%;vertical-align:top;padding-right:8px;">
                            <div style="background:${col1Bg};padding:${col1Pad}px;border-radius:${col1BgRadius}px;">
                                <div style="background:${col1Copy};padding:10px;border-radius:${col1CopyRadius}px;">${footerCol1 || ''}</div>
                            </div>
                        </td>
                        <td style="width:50%;vertical-align:top;padding-left:8px;">
                            <div style="background:${col2Bg};padding:${col2Pad}px;border-radius:${col2BgRadius}px;">
                                <div style="background:${col2Copy};padding:10px;border-radius:${col2CopyRadius}px;">${footerCol2 || ''}</div>
                            </div>
                        </td>
                    </tr>
                </table>
            `;
        } else if (footerCol1) {
            footerHtml = `<div style="background:${col1Bg};padding:${col1Pad}px;border-radius:${col1BgRadius}px;"><div style="background:${col1Copy};padding:10px;border-radius:${col1CopyRadius}px;">${footerCol1}</div></div>`;
        }
        const styleBlock = `
            <style>
                .bpa-preview-wrap h1 { font-family:${t.h1_font}; color:${t.h1_color || t.body_color}; font-size:${t.h1_size || 28}px; font-weight:${t.h1_bold === '1' ? '700' : '400'}; font-style:${t.h1_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h1_underline === '1' ? 'underline' : 'none'}; text-align:${t.h1_align || 'left'}; }
                .bpa-preview-wrap h2 { font-family:${t.h2_font}; color:${t.h2_color || t.body_color}; font-size:${t.h2_size || 24}px; font-weight:${t.h2_bold === '1' ? '700' : '400'}; font-style:${t.h2_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h2_underline === '1' ? 'underline' : 'none'}; text-align:${t.h2_align || 'left'}; }
                .bpa-preview-wrap h3 { font-family:${t.h3_font || t.h2_font}; color:${t.h3_color || t.body_color}; font-size:${t.h3_size || 20}px; font-weight:${t.h3_bold === '1' ? '700' : '400'}; font-style:${t.h3_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h3_underline === '1' ? 'underline' : 'none'}; text-align:${t.h3_align || 'left'}; }
                .bpa-preview-wrap h4 { font-family:${t.h4_font || t.h2_font}; color:${t.h4_color || t.body_color}; font-size:${t.h4_size || 18}px; font-weight:${t.h4_bold === '1' ? '700' : '400'}; font-style:${t.h4_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h4_underline === '1' ? 'underline' : 'none'}; text-align:${t.h4_align || 'left'}; }
                .bpa-preview-wrap h5 { font-family:${t.h5_font || t.h2_font}; color:${t.h5_color || t.body_color}; font-size:${t.h5_size || 16}px; font-weight:${t.h5_bold === '1' ? '700' : '400'}; font-style:${t.h5_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h5_underline === '1' ? 'underline' : 'none'}; text-align:${t.h5_align || 'left'}; }
                .bpa-preview-wrap h6 { font-family:${t.h6_font || t.h2_font}; color:${t.h6_color || t.body_color}; font-size:${t.h6_size || 14}px; font-weight:${t.h6_bold === '1' ? '700' : '400'}; font-style:${t.h6_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h6_underline === '1' ? 'underline' : 'none'}; text-align:${t.h6_align || 'left'}; }
                .bpa-preview-wrap p, .bpa-preview-wrap div { font-family:${t.paragraph_font}; color:${t.paragraph_color || t.body_color}; font-size:${t.paragraph_size || 14}px; font-weight:${t.paragraph_bold === '1' ? '700' : '400'}; font-style:${t.paragraph_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.paragraph_underline === '1' ? 'underline' : 'none'}; text-align:${t.paragraph_align || 'left'}; }
            </style>
        `;
        const preview = `
            ${styleBlock}
            <div class="bpa-preview-wrap" style="background:${t.container_bg};padding:16px;font-family:${t.paragraph_font}">
                ${t.header_url ? `<div style="text-align:${t.header_align};margin-bottom:${t.header_space}px;"><a href="${t.header_link || '#'}"><img src="${t.header_url}" height="${t.header_height}" width="${t.header_width}" style="height:${t.header_height}px;width:${t.header_width}px;"></a></div>` : ''}
                <div style="background:${t.copy_bg};padding:16px;color:${t.paragraph_color || t.body_color};border-radius:8px;font-family:${t.paragraph_font}">
                    <h1>Headline</h1>
                    <h2>Subheadline</h2>
                    <div>${body || '<em>No content saved.</em>'}</div>
                    <a href="#" style="color:${t.link_color};font-weight:${t.link_weight};display:inline-block;margin-top:12px;">CTA Link</a>
                </div>
                ${t.footer_url ? `<div style="text-align:center;margin-top:${t.footer_space}px;"><a href="${t.footer_link || '#'}"><img src="${t.footer_url}" height="${t.footer_height}" width="${t.footer_width}" style="height:${t.footer_height}px;width:${t.footer_width}px;"></a></div>` : ''}
                ${footerHtml ? `<div style="margin-top:8px;color:${t.body_color};font-size:12px;">${footerHtml}</div>` : ''}
            </div>
        `;
        const target = configMode ? '#bpa-template-preview-config' : '#bpa-template-preview';
        $(target).html(preview);
        updateColorChips();
    }

    function updateColorChips() {
        $('#bpa-chip-template').css('background', `linear-gradient(90deg, ${$('#bpa-theme-container-bg').val() || '#111'}, ${$('#bpa-theme-copy-bg').val() || '#222'})`);
        $('#bpa-chip-header').css('background', $('#bpa-theme-header-url').val() ? 'linear-gradient(90deg,#c92021,#ef5c06)' : ($('#bpa-theme-container-bg').val() || '#111'));
        $('#bpa-chip-content').css('background', $('#bpa-theme-link-color').val() || '#ef5c06');
        $('#bpa-chip-footer').css('background', $('#bpa-theme-footer-url').val() ? 'linear-gradient(90deg,#ef5c06,#bdbdbf)' : ($('#bpa-theme-body-color').val() || '#bdbdbf'));
    }

    function previewTemplate(tpl, compact = false) {
        const t = cfg.theme || {};
        const body = tpl.body || getEditorContent('bpa_email_body') || '';
        const footerLayout = $('#bpa-footer-columns').val() || '1';
        const footerCol1 = getEditorContent('bpa_footer_col1') || '';
        const footerCol2 = getEditorContent('bpa_footer_col2') || '';
        const col1Bg = t.footer_col1_bg || t.copy_bg;
        const col1Copy = t.footer_col1_copy || t.copy_bg;
        const col1Pad = t.footer_col1_bg_pad || 10;
        const col1BgRadius = t.footer_col1_bg_radius || 10;
        const col1CopyRadius = t.footer_col1_copy_radius || 6;
        const col2Bg = t.footer_col2_bg || t.copy_bg;
        const col2Copy = t.footer_col2_copy || t.copy_bg;
        const col2Pad = t.footer_col2_bg_pad || 10;
        const col2BgRadius = t.footer_col2_bg_radius || 10;
        const col2CopyRadius = t.footer_col2_copy_radius || 6;
        let footerHtml = '';
        if (footerLayout === '2') {
            footerHtml = `
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="width:50%;vertical-align:top;padding-right:8px;">
                            <div style="background:${col1Bg};padding:${col1Pad}px;border-radius:${col1BgRadius}px;">
                                <div style="background:${col1Copy};padding:10px;border-radius:${col1CopyRadius}px;">${footerCol1 || ''}</div>
                            </div>
                        </td>
                        <td style="width:50%;vertical-align:top;padding-left:8px;">
                            <div style="background:${col2Bg};padding:${col2Pad}px;border-radius:${col2BgRadius}px;">
                                <div style="background:${col2Copy};padding:10px;border-radius:${col2CopyRadius}px;">${footerCol2 || ''}</div>
                            </div>
                        </td>
                    </tr>
                </table>`; 
        } else if (footerCol1) {
            footerHtml = `<div style="background:${col1Bg};padding:${col1Pad}px;border-radius:${col1BgRadius}px;"><div style="background:${col1Copy};padding:10px;border-radius:${col1CopyRadius}px;">${footerCol1}</div></div>`;
        }
        const wrapStyle = compact ? 'max-height:180px;overflow:hidden;border-radius:10px;' : 'border-radius:12px;';
        const styleBlock = compact ? '' : `
            <style>
                .bpa-preview-wrap h1 { font-family:${t.h1_font || 'sans-serif'}; color:${t.h1_color || t.body_color || '#0f1724'}; font-size:${t.h1_size || 28}px; font-weight:${t.h1_bold === '1' ? '700' : '400'}; font-style:${t.h1_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h1_underline === '1' ? 'underline' : 'none'}; text-align:${t.h1_align || 'left'}; }
                .bpa-preview-wrap h2 { font-family:${t.h2_font || 'sans-serif'}; color:${t.h2_color || t.body_color || '#0f1724'}; font-size:${t.h2_size || 24}px; font-weight:${t.h2_bold === '1' ? '700' : '400'}; font-style:${t.h2_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h2_underline === '1' ? 'underline' : 'none'}; text-align:${t.h2_align || 'left'}; }
                .bpa-preview-wrap h3 { font-family:${t.h3_font || t.h2_font || 'sans-serif'}; color:${t.h3_color || t.body_color || '#0f1724'}; font-size:${t.h3_size || 20}px; font-weight:${t.h3_bold === '1' ? '700' : '400'}; font-style:${t.h3_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h3_underline === '1' ? 'underline' : 'none'}; text-align:${t.h3_align || 'left'}; }
                .bpa-preview-wrap h4 { font-family:${t.h4_font || t.h2_font || 'sans-serif'}; color:${t.h4_color || t.body_color || '#0f1724'}; font-size:${t.h4_size || 18}px; font-weight:${t.h4_bold === '1' ? '700' : '400'}; font-style:${t.h4_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h4_underline === '1' ? 'underline' : 'none'}; text-align:${t.h4_align || 'left'}; }
                .bpa-preview-wrap h5 { font-family:${t.h5_font || t.h2_font || 'sans-serif'}; color:${t.h5_color || t.body_color || '#0f1724'}; font-size:${t.h5_size || 16}px; font-weight:${t.h5_bold === '1' ? '700' : '400'}; font-style:${t.h5_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h5_underline === '1' ? 'underline' : 'none'}; text-align:${t.h5_align || 'left'}; }
                .bpa-preview-wrap h6 { font-family:${t.h6_font || t.h2_font || 'sans-serif'}; color:${t.h6_color || t.body_color || '#0f1724'}; font-size:${t.h6_size || 14}px; font-weight:${t.h6_bold === '1' ? '700' : '400'}; font-style:${t.h6_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.h6_underline === '1' ? 'underline' : 'none'}; text-align:${t.h6_align || 'left'}; }
                .bpa-preview-wrap p, .bpa-preview-wrap div { font-family:${t.paragraph_font || 'sans-serif'}; color:${t.paragraph_color || t.body_color || '#0f1724'}; font-size:${t.paragraph_size || 14}px; font-weight:${t.paragraph_bold === '1' ? '700' : '400'}; font-style:${t.paragraph_italic === '1' ? 'italic' : 'normal'}; text-decoration:${t.paragraph_underline === '1' ? 'underline' : 'none'}; text-align:${t.paragraph_align || 'left'}; }
            </style>
        `;
        return `
            ${styleBlock}
            <div class="bpa-preview-wrap" style="background:${t.container_bg || '#ffffff'};padding:12px;font-family:${t.paragraph_font || 'Helvetica Neue'};${wrapStyle}">
                ${t.header_url ? `<div style="text-align:${t.header_align || 'center'};margin-bottom:${t.header_space || 0}px;"><a href="${t.header_link || '#'}"><img src="${t.header_url}" alt="Header image" style="width:100%;height:auto;max-height:${t.header_height || 120}px;border:0;"></a></div>` : ''}
                <div style="background:${t.copy_bg || '#f7f7f7'};padding:10px;color:${t.paragraph_color || t.body_color || '#0f1724'};border-radius:8px;">
                    <h1>Headline</h1>
                    <h2>Subheadline</h2>
                    <div>${body || ''}</div>
                </div>
                ${t.footer_url ? `<div style="text-align:center;margin-top:${t.footer_space || 10}px;"><a href="${t.footer_link || '#'}"><img src="${t.footer_url}" alt="Footer image" style="width:100%;height:auto;max-width:${t.footer_width || 260}px;border:0;"></a></div>` : ''}
                ${footerHtml ? `<div style="margin-top:8px;color:${t.body_color || '#0f1724'};font-size:12px;">${footerHtml}</div>` : ''}
            </div>`;
    }
function renderSelectedTemplateCard(tpl) {
        const card = $('#bpa-selected-template-card');
        if (!tpl || !tpl.name) {
            card.hide().empty();
            $('#bpa-template-picker').show();
            $('#bpa-template-change').hide();
            return;
        }
        card.show().html(`
            <div class="bpa-status-line">
                <strong>${tpl.name}</strong>
                <button type="button" class="button" id="bpa-inline-preview">Preview</button>
            </div>
        `);
        $('#bpa-template-picker').hide();
        $('#bpa-template-change').show();
        $('#bpa-inline-preview').on('click', function () {
            $('#bpa-template-preview-only').html(previewTemplate(tpl));
            $('#bpa-template-preview-modal').attr('aria-hidden', 'false');
        });
    }

    function appendSearchResults(users) {
        const $results = $('#bpa-search-results').empty();
        if (!users.length) {
            $results.append($('<span/>', { class: 'bpa-muted' }).text('No users found.'));
            return;
        }
        const selectedEmailSet = new Set(
            Object.values(state.selectedUsers)
                .map((u) => (u.email || '').toLowerCase())
                .filter(Boolean)
        );
        state.selectedEmails.forEach((em) => selectedEmailSet.add((em || '').toLowerCase()));
        users.forEach((user) => {
            const emailLower = (user.email || '').toLowerCase();
            if (state.selectedUsers[user.id] || selectedEmailSet.has(emailLower)) {
                return;
            }
            const pill = $('<span/>', { class: 'bpa-pill' }).append(
                `${user.name} (${user.email}) `,
                $('<button/>', { type: 'button' }).text('+').on('click', () => {
                    state.selectedUsers[user.id] = user;
                    renderSelected();
                    // Remove from search list once selected
                    pill.remove();
                })
            );
            $results.append(pill);
        });
    }

    function searchUsers(term) {
        $.get(cfg.ajaxUrl, {
            action: 'bpa_search_users',
            nonce: cfg.nonce,
            term,
        }).done((resp) => {
            if (resp.success) {
                appendSearchResults(resp.data.results || []);
            }
        }).always(() => $('#bpa-search-loading').hide());
    }

    function matchEmails(emails) {
        const cleaned = Array.from(
            new Set(
                (emails || [])
                    .map((email) => (email || '').trim().toLowerCase())
                    .filter((email) => email && email.includes('@'))
            )
        );
        if (!cleaned.length) return;
        $.post(cfg.ajaxUrl, {
            action: 'bpa_match_emails',
            nonce: cfg.nonce,
            emails: cleaned,
        }).done((resp) => {
            if (resp.success && resp.data) {
                const unmatchedSet = new Set();
                const selectedEmailSet = new Set(
                    Object.values(state.selectedUsers)
                        .map((u) => u.email)
                        .filter(Boolean)
                        .map((em) => em.toLowerCase())
                );
                state.selectedEmails.forEach((email) => selectedEmailSet.add((email || '').toLowerCase()));
                (resp.data.matched || []).forEach((user) => {
                    if (user && user.id) {
                        state.selectedUsers[user.id] = user;
                        selectedEmailSet.add((user.email || '').toLowerCase());
                        state.selectedEmails.delete((user.email || '').toLowerCase());
                    }
                });
                (resp.data.unmatched || []).forEach((email) => {
                    const lower = (email || '').toLowerCase();
                    if (!selectedEmailSet.has(lower)) {
                        unmatchedSet.add(lower);
                    }
                });
                state.unmatchedEmails = Array.from(unmatchedSet);
                renderSelected();
                $('#bpa-upload-status').text('Matching complete').show();
                $('#bpa-upload-progress').css({ width: '100%' }).removeClass('active').addClass('success');
                $('#bpa-upload-progress-wrap').addClass('success');
                return;
            }
            cleaned.forEach((email) => state.selectedEmails.add(email));
            renderSelected();
        }).fail(() => {
            cleaned.forEach((email) => state.selectedEmails.add(email));
            renderSelected();
        });
    }

    $('#bpa-user-search-btn').on('click', function () {
        const term = $('#bpa-user-search').val().trim();
        if (term.length < 2) {
            $('#bpa-search-results').empty();
            $('#bpa-search-loading').hide();
            return;
        }
        $('#bpa-search-loading').show();
        searchUsers(term);
    });

    let searchTimer = null;
    $('#bpa-user-search').on('keyup', function (e) {
        const term = $(this).val().trim();
        if (term.length < 2) {
            $('#bpa-search-results').empty();
            $('#bpa-search-loading').hide();
            return;
        }
        if (searchTimer) {
            clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(() => searchUsers(term), 250);
    }).on('keypress', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#bpa-user-search-btn').click();
        }
    });

    $('#bpa-csv-upload').on('change', function () {
        csvFile = this.files && this.files[0] ? this.files[0] : null;
        $('#bpa-upload-progress-wrap').removeClass('show success');
        $('#bpa-upload-progress').removeClass('success active').css({ width: '0%' });
        $('#bpa-upload-status').removeClass('bpa-loading bpa-status-done').text('').hide();
    });

    $('#bpa-csv-match').on('click', function () {
        if (!csvFile) {
            alert('Please choose a CSV or XLSX file first.');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'bpa_parse_upload');
        formData.append('nonce', cfg.nonce);
        formData.append('file', csvFile);
        $('#bpa-csv-match').prop('disabled', true).text('Matching...');
        $('#bpa-upload-status').removeClass('bpa-status-done').addClass('bpa-loading').text('Matching members...').show();
        $('#bpa-upload-progress-wrap').addClass('show').removeClass('success');
        $('#bpa-upload-progress').removeClass('success').css({ width: '15%' }).addClass('active');
        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
        }).done((resp) => {
            if (resp.success && resp.data && Array.isArray(resp.data.emails)) {
                matchEmails(resp.data.emails);
                $('#bpa-upload-status').text(`Parsed ${resp.data.emails.length} emails. Matching...`);
            } else {
                const msg = resp.data && resp.data.message ? resp.data.message : 'Could not parse file.';
                $('#bpa-upload-status').text(msg);
            }
        }).fail(() => {
            $('#bpa-upload-status').text('Upload failed. Please try again.');
        }).always(() => {
            $('#bpa-csv-match').prop('disabled', false).text('Match Members');
            setTimeout(() => {
                $('#bpa-upload-status').removeClass('bpa-loading').addClass('bpa-status-done').text('Matching complete').show();
                $('#bpa-upload-progress')
                    .css({ width: '100%' })
                    .removeClass('active')
                    .addClass('success');
                $('#bpa-upload-progress-wrap')
                    .removeClass('active')
                    .addClass('success');
            }, 400);
        });
    });

    $('#bpa-bulk-form').on('submit', function () {
        syncAudienceHiddenInputs();
        const points = parseInt($('input[name="bpa_points"]').val() || '0', 10);
        $statusPoints.text(points);
        $statusLog.text('Queued for processing');
        $statusEmail.text($('#bpa-schedule').val() ? 'Scheduled' : 'Sending');
        triggerProgress(cfg.lastId || 0);
        $statusCard.show();
    });

    $('.bpa-merge-tag').off('click').on('click', function () {
        const tag = $(this).data('tag') || '';
        insertAtCursor('bpa_email_body', tag);
        renderThemePreview(true);
    });

    function insertAtCursor(editorId, text) {
        const editor = window.tinyMCE ? tinyMCE.get(editorId) : null;
        if (editor && !editor.isHidden()) {
            editor.focus();
            editor.execCommand('mceInsertContent', false, text);
            return;
        }
        const $textarea = $('#' + editorId);
        const el = $textarea.get(0);
        if (!el) return;
        const start = el.selectionStart || 0;
        const end = el.selectionEnd || 0;
        const value = $textarea.val() || '';
        const newValue = value.substring(0, start) + text + value.substring(end);
        $textarea.val(newValue);
        const caret = start + text.length;
        el.selectionStart = el.selectionEnd = caret;
        $textarea.focus();
    }

    $sendTestBtn.off('click').on('click', function () {
        const toEmail = $('#bpa-test-email').val().trim();
        const subject = $('#bpa-email-subject').val().trim();
        const body = getEditorContent('bpa_email_body');
        const reason = $('input[name="bpa_reason"]').val().trim();
        const pointType = $('select[name="bpa_point_type"]').val() || '';
        if (!toEmail) {
            alert('Enter a test email address.');
            return;
        }
        if ($sendTestBtn.data('sending')) {
            return;
        }
        $sendTestBtn.data('sending', true);
        $sendTestBtn.prop('disabled', true).text('Sending...');
        $.post(cfg.ajaxUrl, {
            action: 'bpa_send_test_email',
            nonce: cfg.nonce,
            email: toEmail,
            subject,
            body,
            reason,
            point_type: pointType,
        }).done((resp) => {
            if (resp.success) {
                alert('Test email sent.');
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Failed to send test email.');
            }
        }).always(() => {
            $sendTestBtn.prop('disabled', false).text('Send Test').data('sending', false);
        });
    });

    function getBpSelection() {
        const selected = [];
        $('#bpa-bp-types .bpa-type-chip.active').each(function () {
            selected.push($(this).data('type'));
        });
        return selected;
    }

    function syncAudienceHiddenInputs() {
        const $wrap = $('#bpa-audience-hidden');
        if (!$wrap.length) return;
        $wrap.empty();
        getBpSelection().forEach((type) => {
            $('<input/>', { type: 'hidden', name: 'bpa_bp_member_types[]', value: type }).appendTo($wrap);
        });
        getRoleSelection().forEach((role) => {
            $('<input/>', { type: 'hidden', name: 'bpa_user_roles[]', value: role }).appendTo($wrap);
        });
    }

    function getRoleSelection() {
        const selected = [];
        $('#bpa-user-roles .bpa-role-chip.active').each(function () {
            selected.push($(this).data('role'));
        });
        return selected;
    }

    $('#bpa-bp-types').on('click', '.bpa-type-chip', function () {
        $(this).toggleClass('active');
        const selected = new Set(getBpSelection());
        prevBpSelection = selected;
        renderSelectedMeta();
        syncAudienceHiddenInputs();
        fetchAudienceCount();
    });

    $('#bpa-user-roles').on('click', '.bpa-role-chip', function () {
        $(this).toggleClass('active');
        const selected = new Set(getRoleSelection());
        prevRoleSelection = selected;
        renderSelectedMeta();
        syncAudienceHiddenInputs();
        fetchAudienceCount();
    });

    // Click-to-toggle multi-select so users don't need CTRL/CMD
    $('select.bpa-multi').off('mousedown click').on('mousedown', 'option', function (e) {
        e.preventDefault();
        const option = $(this);
        option.prop('selected', !option.prop('selected'));
        option.parent().trigger('change');
    });

    $('.bpa-accordion-header').on('click', function () {
        const $body = $(this).next('.bpa-accordion-body');
        $('.bpa-accordion-body').not($body).removeClass('open');
        $body.toggleClass('open');
    });

    function fetchAudienceCount() {
        const types = getBpSelection();
        const roles = getRoleSelection();
        if (!types.length && !roles.length) {
            audienceCount = 0;
            renderSelected();
            return;
        }
        $.get(cfg.ajaxUrl, {
            action: 'bpa_count_audience',
            nonce: cfg.nonce,
            types,
            roles,
        }).done((resp) => {
            if (resp.success) {
                audienceCount = resp.data.unique_total || 0;
                renderSelected();
            }
        });
    }

    $('.bpa-history-row').on('click', function () {
        const meta = $(this).data('meta');
        if (!meta) return;
        const $detail = $('#bpa-history-detail').empty();
        const header = $('<div/>', { class: 'bpa-history-head' }).append(
            $('<h3/>').text(meta.reason || 'Bulk Assign')
        );
        if (meta.author_info) {
            const info = meta.author_info;
            const authorCard = $('<div/>', { class: 'bpa-author-card' }).append(
                $('<div/>', { class: 'bpa-muted', style: 'text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:0.6px;' }).text('Sent by'),
                $('<div/>', { class: 'bpa-author-name' }).text(info.name || 'Unknown'),
                $('<div/>', { class: 'bpa-muted' }).text(info.username ? `@${info.username}` : ''),
                $('<div/>', { class: 'bpa-muted' }).text(info.email || '')
            );
            header.append(authorCard);
        }
        const rows = [
            ['Status', meta.status || 'Pending'],
            ['Reason', meta.reason],
            ['Point Type', meta.point_type],
            ['Points', meta.points],
            ['Schedule', meta.schedule || 'Immediate'],
            ['Members', meta.member_count],
            ['Email Status', meta.email_status || 'Pending'],
            ['Template', meta.template || '-'],
            ['Emails', (meta.emails || []).join(', ') || '-'],
        ];
        const grid = $('<div/>', { class: 'bpa-history-grid' });
        rows.forEach(([label, value]) => {
            grid.append(
                $('<div/>', { class: 'bpa-history-card' }).append(
                    $('<h4/>').text(label),
                    $('<span/>', { class: 'bpa-card-value' }).text(value)
                )
            );
        });
        $detail.append(header, grid);
        $('#bpa-history-logs').empty().text('Loading logs...');
        fetchLogs(meta.id);
        $('#bpa-history-modal').attr('aria-hidden', 'false');
    });

    $('.bpa-cell-members').on('click', function () {
        const meta = $(this).closest('tr').data('meta');
        if (!meta || !meta.recipients) return;
        const $wrap = $('#bpa-members-list').empty();
        meta.recipients.forEach((rec) => {
            $wrap.append(
                $('<div/>', { class: 'bpa-status-line' }).append(
                    $('<strong/>').text(rec.name || `User ${rec.id || ''}`),
                    $('<span/>').text(rec.email || '')
                )
            );
        });
        $('#bpa-members-modal').attr('aria-hidden', 'false');
    });

    $('.bpa-cell-log').on('click', function () {
        const meta = $(this).closest('tr').data('meta');
        if (!meta) return;
        $('#bpa-history-logs').empty().text('Loading logs...');
        fetchLogs(meta.id);
        $('#bpa-history-modal').attr('aria-hidden', 'false');
    });

    $('#bpa-history-modal .bpa-modal-close').on('click', function () {
        $('#bpa-history-modal').attr('aria-hidden', 'true');
    });

    $('#bpa-members-modal .bpa-modal-close').on('click', function () {
        $('#bpa-members-modal').attr('aria-hidden', 'true');
    });

    $('#bpa-history-modal').on('click', function (e) {
        if (e.target === this) {
            $('#bpa-history-modal').attr('aria-hidden', 'true');
        }
    });

    $('#bpa-members-modal').on('click', function (e) {
        if (e.target === this) {
            $('#bpa-members-modal').attr('aria-hidden', 'true');
        }
    });

    function triggerProgress(postId) {
        const bar = $('#bpa-progress-bar');
        bar.removeClass('is-complete').addClass('is-busy');
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (!postId) {
            setTimeout(() => bar.addClass('is-complete'), 1800);
            return;
        }
        pollProgress(postId);
    }

    function pollProgress(postId) {
        $.get(cfg.ajaxUrl, {
            action: 'bpa_progress',
            nonce: cfg.nonce,
            post_id: postId,
        }).done((resp) => {
            if (!resp.success) return;
            const data = resp.data;
            const bar = $('#bpa-progress-bar');
            bar.css('width', `${data.percent}%`);
            updateMethodAndEta(data);
            if (data.status === 'completed' || data.percent >= 100) {
                bar.removeClass('is-busy').addClass('is-complete');
                $statusLog.text('Completed');
                $statusEmail.text('Sent');
                $statusMembers.text(data.member_count);
                pollTimer = null;
                return;
            }
            pollTimer = setTimeout(() => pollProgress(postId), 2000);
        });
    }

    function updateMethodAndEta(data) {
        const methodLabel = data.schedule_method === 'action_scheduler'
            ? 'Action Scheduler'
            : data.schedule_method === 'wp_cron'
                ? 'WP-Cron'
                : 'Immediate';
        $statusMethod.text(methodLabel);

        const eta = computeEta(data);
        $statusEta.text(eta);
    }

    function computeEta(data) {
        if (data.status === 'completed' || data.percent >= 100) {
            return 'Done';
        }
        const percent = Math.max(1, data.percent || 1);
        const start = data.created_at ? Number(data.created_at) * 1000 : Date.now();
        const elapsedMs = Date.now() - start;
        if (elapsedMs <= 0) return 'Estimating...';
        const remainingMs = elapsedMs * (100 / percent - 1);
        const mins = Math.round(remainingMs / 60000);
        if (mins <= 1) return '< 1 min';
        if (mins < 60) return `${mins} mins`;
        const hours = Math.round(mins / 60);
        return `${hours}h+`;
    }

    function fetchLogs(postId) {
        $.get(cfg.ajaxUrl, {
            action: 'bpa_fetch_logs',
            nonce: cfg.nonce,
            post_id: postId,
        }).done((resp) => {
            const $logs = $('#bpa-history-logs');
            if (!resp.success) {
                $logs.text('No logs found.');
                return;
            }
            const logs = resp.data.logs || [];
            if (!logs.length) {
                $logs.text('No logs found.');
                return;
            }
            $logs.empty();
            logs.forEach((log) => {
                const date = log.time ? new Date(log.time * 1000).toLocaleString() : '';
                $logs.append(
                    $('<div/>', { class: 'bpa-status-line' }).append(
                        $('<strong/>').text(`${date} · User ${log.user_id}`),
                        $('<span/>').text(`${log.creds} (${log.entry || ''})`)
                    )
                );
            });
        });
    }

    // Hover sounds removed per request

    if (cfg.lastId) {
        triggerProgress(cfg.lastId);
    }

    $progressSelect.on('change', function () {
        const id = parseInt($(this).val(), 10);
        if (!id) return;
        triggerProgress(id);
    });

    $sendMode.on('change', function () {
        const mode = $(this).val();
        if (mode === 'schedule') {
            $scheduleWrap.show();
            seedScheduleDefaults();
        } else {
            $scheduleWrap.hide();
            $('#bpa-schedule').val('');
        }
    });

    // Only open the native picker when the user clicks/taps the field
    $scheduleInput.on('focus click', function (e) {
        if (this.showPicker) {
            this.showPicker();
            e.preventDefault();
        }
    });

    function seedScheduleDefaults() {
        if ($scheduleInput.val()) return;
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const dateStr = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const timeStr = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
        $scheduleInput.val(`${dateStr}T${timeStr}`);
    }
})(jQuery);
