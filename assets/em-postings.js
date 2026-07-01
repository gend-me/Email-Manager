/* Email Manager — Postings (Applications ▸ Postings sub-tab)
 *
 * Drives the centered posting editor modal (multi-step), an AJAX
 * search-and-select for the application form, an inline form-builder popup,
 * embedded WP page editors for the landing + thank-you pages (with a copyable
 * form-shortcode sidebar), the 3-mode thank-you switch, keep-open save, delete,
 * copy-URL, and the per-posting analytics drawer.
 *
 * Depends on EM_POSTINGS_CONFIG (localized) + shared em-app-support.js styling.
 */
(function ($) {
    'use strict';

    var cfg  = window.EM_POSTINGS_CONFIG || {};
    var i18n = cfg.i18n || {};
    var $doc = $(document);

    function esc(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var $editor = $('#em-posting-drawer');
    var $form   = $('#em-posting-form');
    var $combo  = $('#em-form-combo');

    var DEFAULT_PROCESS = [
        { title: 'Apply',    desc: 'Submit your application through the form below.' },
        { title: 'Review',   desc: 'Our team reviews every application carefully.' },
        { title: 'Decision', desc: "We'll reach out with next steps within a few days." }
    ];

    /* ---------------- Apply Process rows ---------------- */
    function addProcessRow(title, desc) {
        var $row = $(
            '<div class="em-process-row">' +
                '<div class="em-process-row__num"></div>' +
                '<div class="em-process-row__fields">' +
                    '<input type="text" name="process_title[]" class="em-process-title" placeholder="Step title" />' +
                    '<textarea name="process_desc[]" rows="2" class="em-process-desc" placeholder="What happens at this step…"></textarea>' +
                '</div>' +
                '<button type="button" class="em-process-remove" aria-label="Remove">&times;</button>' +
            '</div>'
        );
        $row.find('.em-process-title').val(title || '');
        $row.find('.em-process-desc').val(desc || '');
        $('#em-process-rows').append($row);
        renumberProcess();
    }
    function renumberProcess() {
        $('#em-process-rows .em-process-row').each(function (i) {
            $(this).find('.em-process-row__num').text(i + 1);
        });
    }
    function setProcess(arr) {
        $('#em-process-rows').empty();
        (arr && arr.length ? arr : []).forEach(function (s) { addProcessRow(s.title, s.desc); });
    }

    /* ---------------- Mailing list ---------------- */
    function setLists(lists, selectedId) {
        var $sel = $('#em-pf-list-select');
        $sel.find('option:not([value="0"])').remove();
        (lists || []).forEach(function (l) {
            $sel.append($('<option>', { value: l.id, text: l.name + (l.count ? ' (' + l.count + ')' : '') }));
        });
        $sel.val(selectedId || 0);
    }
    function setSyncFields(fields) {
        var $box = $('#em-pf-syncfields');
        if (!fields || !fields.length) {
            $box.html('<span class="em-pf-syncempty">Link a form to see its fields here.</span>');
            return;
        }
        var html = '';
        fields.forEach(function (f) {
            html += '<span class="em-pf-chip">' + esc(f.label) +
                '<span class="em-pf-chip__type">' + esc(f.type) + '</span></span>';
        });
        $box.html(html);
    }
    function fetchSyncFields(formId) {
        if (!formId) { setSyncFields([]); return; }
        $.post(cfg.ajaxUrl, { action: 'em_get_form_fields', form_id: formId, nonce: cfg.nonce })
            .done(function (res) { if (res && res.success) setSyncFields(res.data.fields || []); });
    }

    /* ---------------- Drawer / modal open-close ---------------- */
    function openModal($m)  { $m.addClass('is-open').attr('aria-hidden', 'false'); $('body').addClass('em-drawer-locked'); }
    function closeModal($m) { $m.removeClass('is-open').attr('aria-hidden', 'true'); if (!$('.em-drawer.is-open').length) $('body').removeClass('em-drawer-locked'); }

    $doc.on('click', '#em-posting-drawer [data-close], #em-posting-analytics-drawer [data-close]', function () {
        closeModal($(this).closest('.em-drawer'));
    });
    $doc.on('click', '#em-newform-modal [data-close-nf]', function () { closeModal($('#em-newform-modal')); });
    $doc.on('keydown', function (ev) {
        if (ev.key === 'Escape') {
            var $top = $('.em-drawer.is-open').last();
            if ($top.length) closeModal($top);
        }
    });

    /* ---------------- Steps ---------------- */
    function showStep(step) {
        $form.find('.em-pf-step').removeClass('is-active').filter('[data-step="' + step + '"]').addClass('is-active');
        $form.find('.em-pf-pane').removeClass('is-active').filter('[data-pane="' + step + '"]').addClass('is-active');
    }
    $doc.on('click', '.em-pf-step', function () { showStep($(this).data('step')); });

    /* ---------------- Form combo (AJAX search-select) ---------------- */
    var searchTimer = null;

    function setChosenForm(id, text) {
        $combo.find('[name="form_id"]').val(id || 0);
        var $chosen = $combo.find('.em-combo__chosen');
        if (id) {
            $chosen.find('.em-combo__chosen-text').text(text);
            $chosen.prop('hidden', false);
            $combo.find('.em-combo__control').hide();
        } else {
            $chosen.prop('hidden', true);
            $combo.find('.em-combo__control').show();
            $combo.find('.em-combo__search').val('');
        }
        $combo.find('.em-combo__menu').prop('hidden', true).empty();
        updateShortcodeBoxes();
        fetchSyncFields(id);
    }

    function renderComboMenu(forms) {
        var $menu = $combo.find('.em-combo__menu');
        if (!forms.length) {
            $menu.html('<div class="em-combo__empty">No forms found</div>').prop('hidden', false);
            return;
        }
        var html = '';
        forms.forEach(function (f) {
            html += '<button type="button" class="em-combo__item" data-id="' + f.id + '" data-text="' + esc(f.title) + '">' +
                '<span class="em-combo__item-title">' + esc(f.title) + '</span>' +
                '<span class="em-combo__item-type">' + (f.type === 'chat_form' ? 'Chat' : 'Form') + '</span>' +
                '</button>';
        });
        $menu.html(html).prop('hidden', false);
    }

    function searchForms(q) {
        $.post(cfg.ajaxUrl, { action: 'em_search_forms', q: q, nonce: cfg.nonce })
            .done(function (res) { if (res && res.success) renderComboMenu(res.data.forms || []); });
    }

    $doc.on('focus', '.em-combo__search', function () { searchForms($(this).val()); });
    $doc.on('input', '.em-combo__search', function () {
        var q = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { searchForms(q); }, 220);
    });
    $doc.on('click', '.em-combo__item', function () {
        setChosenForm($(this).data('id'), $(this).data('text'));
    });
    $doc.on('click', '.em-combo__clear', function () { setChosenForm(0, ''); });
    // Close menu when clicking outside the combo.
    $doc.on('click', function (e) {
        if (!$(e.target).closest('#em-form-combo').length) $combo.find('.em-combo__menu').prop('hidden', true);
    });

    /* ---------------- Shortcode sidebar ---------------- */
    function updateShortcodeBoxes(shortcode) {
        if (typeof shortcode === 'undefined') shortcode = $form.data('shortcode') || '';
        $form.data('shortcode', shortcode);
        $form.find('.em-pf-shortcode__input').val(shortcode);
    }
    $doc.on('click', '.em-pf-shortcode__copy', function () {
        var val = $(this).closest('.em-pf-shortcode').find('.em-pf-shortcode__input').val();
        if (!val) return;
        copyText(val);
        var $b = $(this); $b.addClass('is-copied'); setTimeout(function () { $b.removeClass('is-copied'); }, 1200);
    });

    /* ---------------- Thank-you mode ---------------- */
    function syncThankYou() {
        var mode = $form.find('[name="ty_mode"]:checked').val() || 'message';
        $form.find('[data-ty]').hide();
        $form.find('[data-ty="' + mode + '"]').show();
    }
    $doc.on('change', '[name="ty_mode"]', syncThankYou);

    /* ---------------- Page editor iframes ---------------- */
    function setPageArea(role, editUrl) {
        var $wrap = $form.find('.em-pf-pagewrap[data-role="' + role + '"]');
        var $iframe = $wrap.find('.em-pf-iframe');
        var $open = $form.find('.em-pf-openpage[data-role="' + role + '"]');
        if (editUrl) {
            if ($iframe.attr('src') !== editUrl) $iframe.attr('src', editUrl);
            $iframe.prop('hidden', false);
            $wrap.find('.em-pf-needsave').hide();
            $open.attr('href', editUrl).prop('hidden', false);
        } else {
            $iframe.prop('hidden', true).removeAttr('src');
            $wrap.find('.em-pf-needsave').show();
            $open.prop('hidden', true);
        }
    }

    /* ---------------- Reset / fill ---------------- */
    function resetForm() {
        $form[0].reset();
        $form.find('[name="posting_id"]').val('0');
        $form.find('[name="status"]').val('open');
        $form.find('[name="ty_mode"][value="message"]').prop('checked', true);
        $form.find('[name="email_applicant_enabled"]').prop('checked', true);
        $form.find('[name="email_admin_enabled"]').prop('checked', true);
        $form.find('[name="list_enabled"]').prop('checked', false);
        $form.find('[name="contract_enabled"]').prop('checked', false);
        $form.find('[name="contract_require"]').prop('checked', true);
        setChosenForm(0, '');
        setPageArea('landing', '');
        setPageArea('thankyou', '');
        updateShortcodeBoxes('');
        setProcess(DEFAULT_PROCESS);
        setLists([], 0);
        setSyncFields([]);
        syncThankYou();
        showStep('landing');
    }

    function fillForm(d) {
        var l = d.landing || {}, t = d.thankyou || {}, e = d.emails || {};
        $form.find('[name="posting_id"]').val(d.id || 0);
        $form.find('[name="title"]').val(d.title || '');
        $form.find('[name="status"]').val(d.status || 'open');
        setChosenForm(d.form_id || 0, d.form_title ? (d.form_title + (d.form_type ? ' (' + d.form_type + ')' : '')) : '');

        $form.find('[name="landing_accent"]').val(l.accent || '#6366f1');
        $form.find('[name="landing_accent2"]').val(l.accent2 || '#8b5cf6');

        $form.find('[name="ty_mode"][value="' + (t.mode || 'message') + '"]').prop('checked', true);
        $form.find('[name="ty_message"]').val(t.message || '');
        $form.find('[name="ty_redirect_url"]').val(t.redirect_url || '');

        $form.find('[name="email_applicant_enabled"]').prop('checked', !!parseInt(e.applicant_enabled, 10));
        $form.find('[name="email_applicant_subject"]').val(e.applicant_subject || '');
        $form.find('[name="email_applicant_body"]').val(e.applicant_body || '');
        $form.find('[name="email_admin_enabled"]').prop('checked', !!parseInt(e.admin_enabled, 10));
        $form.find('[name="email_admin_email"]').val(e.admin_email || '');
        $form.find('[name="email_admin_subject"]').val(e.admin_subject || '');
        $form.find('[name="email_admin_body"]').val(e.admin_body || '');

        // Apply Process
        setProcess(d.process && d.process.length ? d.process : DEFAULT_PROCESS);

        // Contract
        var c = d.contract || {};
        $form.find('[name="contract_enabled"]').prop('checked', !!parseInt(c.enabled, 10));
        $form.find('[name="contract_title"]').val(c.title || 'Applicant Agreement');
        $form.find('[name="contract_body"]').val(c.body || '');
        $form.find('[name="contract_require"]').prop('checked', c.require_accept === undefined ? true : !!parseInt(c.require_accept, 10));

        // Mailing list
        var lst = d.list || {};
        $form.find('[name="list_enabled"]').prop('checked', !!parseInt(lst.enabled, 10));
        setLists(d.lists || [], lst.list_id || 0);
        setSyncFields(d.form_fields || []);

        updateShortcodeBoxes(d.shortcode || '');
        setPageArea('landing', d.landing_edit_url || '');
        setPageArea('thankyou', d.thankyou_edit_url || '');
        syncThankYou();
    }

    /* ---------------- New / edit ---------------- */
    $doc.on('click', '.em-posting-new', function () {
        resetForm();
        $('#em-posting-drawer-title').text(i18n.newPosting || 'New Posting');
        openModal($editor);
    });

    $doc.on('click', '.em-posting-edit', function () {
        var id = $(this).closest('.em-posting-card').data('posting-id');
        if (!id) return;
        resetForm();
        $('#em-posting-drawer-title').text(i18n.editPosting || 'Edit Posting');
        openModal($editor);
        $.post(cfg.ajaxUrl, { action: 'em_get_posting', posting_id: id, nonce: cfg.nonce })
            .done(function (res) { if (res && res.success) { fillForm(res.data); showStep('basics'); } });
    });

    /* ---------------- New Form builder popup ---------------- */
    function addQuestionRow(label, type) {
        var tpl = document.getElementById('em-nf-row-tpl');
        var node = tpl.content.firstElementChild.cloneNode(true);
        if (label) node.querySelector('.em-nf-q').value = label;
        if (type) node.querySelector('.em-nf-type').value = type;
        document.getElementById('em-nf-questions').appendChild(node);
    }

    $doc.on('click', '.em-pf-newform', function () {
        // Seed the builder with the posting title + two starter questions.
        $('#em-nf-title').val($form.find('[name="title"]').val() || '');
        $('#em-nf-questions').empty();
        addQuestionRow('What is your full name?', 'text');
        addQuestionRow('What is your email address?', 'email');
        openModal($('#em-newform-modal'));
    });

    $doc.on('click', '.em-nf-add', function () { addQuestionRow('', 'text'); });
    $doc.on('click', '.em-nf-remove', function () { $(this).closest('.em-nf-row').remove(); });

    /* ---------------- Apply Process row handlers ---------------- */
    $doc.on('click', '.em-process-add', function () { addProcessRow('', ''); });
    $doc.on('click', '.em-process-remove', function () { $(this).closest('.em-process-row').remove(); renumberProcess(); });

    /* ---------------- New mailing list ---------------- */
    $doc.on('click', '.em-pf-newlist', function () {
        var $btn = $(this);
        var name = $form.find('[name="title"]').val() || 'Applicants';
        $btn.prop('disabled', true);
        $.post(cfg.ajaxUrl, { action: 'em_create_list', name: name, nonce: cfg.nonce })
            .done(function (res) {
                if (res && res.success) {
                    $('#em-pf-list-select').append($('<option>', { value: res.data.id, text: res.data.name })).val(res.data.id);
                    $form.find('[name="list_enabled"]').prop('checked', true);
                } else {
                    alert((res && res.data && res.data.message) || 'Could not create list.');
                }
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    $doc.on('click', '.em-nf-create', function () {
        var $btn = $(this);
        var data = { action: 'em_create_posting_form', nonce: cfg.nonce, title: $('#em-nf-title').val() };
        var labels = [], types = [];
        $('#em-nf-questions .em-nf-row').each(function () {
            var q = $(this).find('.em-nf-q').val();
            if (q && q.trim()) { labels.push(q); types.push($(this).find('.em-nf-type').val()); }
        });
        data['q_label'] = labels;
        data['q_type'] = types;

        $('.em-nf-saving').prop('hidden', false);
        $btn.prop('disabled', true);
        $.post(cfg.ajaxUrl, $.param(data))
            .done(function (res) {
                if (res && res.success) {
                    var d = res.data;
                    setChosenForm(d.id, d.title + ' (Chat)');
                    $('#em-pf-form-hint').html('Form created — <a href="' + esc(d.edit_url) + '" target="_blank" rel="noopener">edit its questions</a> any time.');
                    closeModal($('#em-newform-modal'));
                } else {
                    alert((res && res.data && res.data.message) || 'Could not create form.');
                }
            })
            .fail(function () { alert('Could not create form.'); })
            .always(function () { $('.em-nf-saving').prop('hidden', true); $btn.prop('disabled', false); });
    });

    /* ---------------- Save ---------------- */
    function doSave() {
        var $saving = $form.find('.em-pf-saving');
        var $saved  = $form.find('.em-pf-saved');
        var $submit = $form.find('button[type="submit"]');
        $saved.prop('hidden', true);
        $saving.prop('hidden', false);
        $submit.prop('disabled', true);

        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'em_save_posting' });
        data.push({ name: 'nonce', value: cfg.nonce });

        $.post(cfg.ajaxUrl, $.param(data))
            .done(function (res) {
                if (res && res.success) {
                    // Update / insert the grid card.
                    var $grid = $('#em-posting-grid');
                    $grid.find('.em-posting-empty').remove();
                    var $existing = $grid.find('.em-posting-card[data-posting-id="' + res.data.id + '"]');
                    var $card = $(res.data.card).css('--em-i', 0);
                    if ($existing.length) $existing.replaceWith($card); else $grid.prepend($card);
                    // Keep modal open and refresh (so page editors become available).
                    fillForm(res.data);
                    $saved.prop('hidden', false);
                    setTimeout(function () { $saved.prop('hidden', true); }, 2200);
                } else {
                    alert((res && res.data && res.data.message) || i18n.saveFailed || 'Could not save.');
                }
            })
            .fail(function () { alert(i18n.saveFailed || 'Could not save.'); })
            .always(function () { $saving.prop('hidden', true); $submit.prop('disabled', false); });
    }

    $doc.on('submit', '#em-posting-form', function (ev) { ev.preventDefault(); doSave(); });
    $doc.on('click', '.em-pf-savenow', function (ev) { ev.preventDefault(); doSave(); });

    /* ---------------- Delete ---------------- */
    $doc.on('click', '.em-posting-delete', function () {
        var $card = $(this).closest('.em-posting-card');
        var id = $card.data('posting-id');
        if (!id) return;
        if (!window.confirm(i18n.confirmDelete || 'Delete this posting?')) return;
        $.post(cfg.ajaxUrl, { action: 'em_delete_posting', posting_id: id, nonce: cfg.nonce })
            .done(function (res) {
                if (res && res.success) {
                    $card.css({ transition: 'opacity .25s, transform .25s', opacity: 0, transform: 'scale(0.95)' });
                    setTimeout(function () { $card.remove(); }, 250);
                }
            });
    });

    /* ---------------- Copy landing URL ---------------- */
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text); return; }
        var tmp = $('<input>').val(text).appendTo('body').select();
        try { document.execCommand('copy'); } catch (e) {}
        tmp.remove();
    }
    $doc.on('click', '.em-posting-copy', function () {
        var $btn = $(this);
        copyText($btn.data('url'));
        $btn.addClass('is-copied');
        setTimeout(function () { $btn.removeClass('is-copied'); }, 1400);
    });

    /* ---------------- Analytics drawer ---------------- */
    var $aDrawer = $('#em-posting-analytics-drawer');

    $doc.on('click', '.em-posting-analytics', function () {
        var id = $(this).closest('.em-posting-card').data('posting-id');
        if (!id) return;
        $('#em-pa-title').text('Analytics');
        $('#em-pa-body').html(
            '<div class="em-shimmer" style="width:90%;"></div><div class="em-shimmer" style="width:70%;"></div>' +
            '<div class="em-shimmer" style="width:80%;"></div><div class="em-shimmer" style="width:50%;"></div>'
        );
        openModal($aDrawer);
        $.post(cfg.ajaxUrl, { action: 'em_get_posting_analytics', posting_id: id, nonce: cfg.nonce })
            .done(function (res) {
                if (res && res.success) renderAnalytics(res.data);
                else $('#em-pa-body').html('<p style="color:#fca5a5;">Failed to load analytics.</p>');
            })
            .fail(function () { $('#em-pa-body').html('<p style="color:#fca5a5;">Network error.</p>'); });
    });

    function renderAnalytics(d) {
        $('#em-pa-title').text(d.title || 'Analytics');
        var series = d.series || [];
        var max = 1;
        series.forEach(function (p) { max = Math.max(max, p.views, p.subs); });

        var bars = '';
        series.forEach(function (p, i) {
            var vh = Math.round((p.views / max) * 100);
            var sh = Math.round((p.subs / max) * 100);
            bars += '<div class="em-pa-bar" style="--em-i:' + i + ';" title="' + esc(p.label) + ' — ' + p.views + ' views, ' + p.subs + ' applied">' +
                '<div class="em-pa-bar__track">' +
                '<span class="em-pa-bar__v" style="height:' + vh + '%;"></span>' +
                '<span class="em-pa-bar__s" style="height:' + sh + '%;"></span>' +
                '</div><div class="em-pa-bar__lbl">' + esc(p.label) + '</div></div>';
        });

        var html = '';
        html += '<div class="em-drawer__section" style="--em-i:0;"><div class="em-pa-kpis">' +
            '<div class="em-pa-kpi"><div class="em-pa-kpi__v">' + Number(d.views).toLocaleString() + '</div><div class="em-pa-kpi__l">Views</div></div>' +
            '<div class="em-pa-kpi"><div class="em-pa-kpi__v">' + Number(d.submissions).toLocaleString() + '</div><div class="em-pa-kpi__l">Applied</div></div>' +
            '<div class="em-pa-kpi"><div class="em-pa-kpi__v">' + d.conversion + '%</div><div class="em-pa-kpi__l">Conversion</div></div>' +
            '</div></div>';
        html += '<div class="em-drawer__section" style="--em-i:1;">' +
            '<div class="em-drawer__section-title">Last 14 days</div>' +
            '<div class="em-pa-legend"><span class="em-pa-legend__v">Views</span><span class="em-pa-legend__s">Applications</span></div>' +
            '<div class="em-pa-chart">' + (bars || '<p style="opacity:.6;"><em>No activity yet.</em></p>') + '</div></div>';
        html += '<div class="em-drawer__section" style="--em-i:2;">' +
            '<div class="em-drawer__section-title">Landing page</div>' +
            '<a class="button button-primary" href="' + esc(d.landing_url) + '" target="_blank" rel="noopener">Open landing page</a></div>';

        $('#em-pa-body').html(html);
    }

}(jQuery));
