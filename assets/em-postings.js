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

    // The last step is fixed: it's what happens when the applicant is approved.
    var DEFAULT_FINAL = { title: 'Approved', desc: "You're in! We'll be in touch with your next steps.", final: 1 };
    var DEFAULT_PROCESS = [
        { title: 'Apply',    desc: 'Submit your application through the form below.' },
        { title: 'Review',   desc: 'Our team reviews every application carefully.' },
        DEFAULT_FINAL
    ];

    /* ---------------- Apply Process rows ----------------
     * Each step doubles as a status stage: it can grant a WP role (created
     * from a popup with its own feature-access settings), add the
     * applicant to a mailing list, and send any number of rich-HTML emails
     * (to the applicant, extra team addresses, or both) — replacing the
     * old fixed "Applicant Email" / "Team Email" tabs with per-step
     * configuration. */
    function addProcessRow(step) {
        step = step || {};
        var $row = $(
            '<div class="em-process-row">' +
                '<div class="em-process-row__head">' +
                    '<div class="em-process-row__num"></div>' +
                    '<input type="hidden" name="process_final[]" class="em-process-final" value="0" />' +
                    '<input type="text" name="process_title[]" class="em-process-title" placeholder="Step title" />' +
                    '<span class="em-process-row__final-tag" hidden>On approval</span>' +
                    '<button type="button" class="em-process-remove" aria-label="Remove">&times;</button>' +
                '</div>' +
                // Step 1 only (see renumberProcess): Submission / Role Access sub-tabs.
                '<div class="em-process-subtabs" role="tablist" hidden>' +
                    '<button type="button" class="em-process-subtab is-active" role="tab" data-sub="submission">Submission</button>' +
                    '<button type="button" class="em-process-subtab" role="tab" data-sub="access">Role Access</button>' +
                '</div>' +
                '<div class="em-process-subpane is-active" data-sub="submission">' +
                '<p class="em-pf-hint em-process-row__formline" hidden></p>' +
                '<textarea name="process_desc[]" rows="2" class="em-process-desc" placeholder="What happens at this step… (shown publicly on the landing page)"></textarea>' +
                '<p class="em-pf-hint em-process-row__final-hint" hidden>This step is fixed as the last one. It runs automatically when an applicant is approved — once every step above is complete — and sets what happens then: the list they join, whether a member account is created, and the emails sent. The role they get, and their dashboard access, are set on the Contract tab.</p>' +
                '<div class="em-process-row__extra">' +
                    '<label class="em-pf-field em-process-row__role">' +
                        '<span class="em-pf-label">Grant role</span>' +
                        '<div class="em-pf-inline">' +
                            '<select name="process_role[]" class="em-process-role"></select>' +
                            '<button type="button" class="button em-process-addrole"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Add New Role</button>' +
                        '</div>' +
                    '</label>' +
                    '<label class="em-pf-field em-process-row__list-field">' +
                        '<span class="em-pf-label">Add applicant to list</span>' +
                        '<div class="em-pf-inline">' +
                            '<select name="process_list_id[]" class="em-process-row__list"><option value="0">— None —</option></select>' +
                            '<button type="button" class="button em-process-newlist"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> New</button>' +
                        '</div>' +
                    '</label>' +
                    '<label class="em-pf-switch em-process-row__autocreate">' +
                        '<input type="checkbox" class="em-process-autocreate" value="1" />' +
                        '<span>Create a member if none matches by email</span>' +
                    '</label>' +
                    '<div class="em-pf-field em-process-row__completion">' +
                        '<span class="em-pf-label">This step is completed by</span>' +
                        '<select name="process_completion[]" class="em-process-completion">' +
                            '<option value="approval">Member approval</option>' +
                            '<option value="action">User action</option>' +
                        '</select>' +
                        '<div class="em-process-approval">' +
                            '<div class="em-pf-inline">' +
                                '<select name="process_approver_type[]" class="em-process-approver-type">' +
                                    '<option value="role">Anyone with a role</option>' +
                                    '<option value="member">A specific member</option>' +
                                '</select>' +
                                '<select name="process_approver_role[]" class="em-process-approver-role"></select>' +
                            '</div>' +
                            '<div class="em-process-member-picker">' +
                                '<input type="hidden" name="process_approver_user[]" class="em-process-approver-user" value="0" />' +
                                '<div class="em-process-member-chosen" hidden>' +
                                    '<span class="em-process-member-chosen-text"></span>' +
                                    '<button type="button" class="em-process-member-clear" aria-label="Clear">&times;</button>' +
                                '</div>' +
                                '<input type="text" class="em-process-member-search" placeholder="Search members by name or email…" autocomplete="off" />' +
                                '<div class="em-process-member-menu" hidden></div>' +
                            '</div>' +
                            '<span class="em-pf-hint em-process-approval-hint"></span>' +
                        '</div>' +
                        '<div class="em-process-actionpanel">' +
                            '<input type="text" name="process_action_label[]" class="em-process-action-label" placeholder="What should the applicant do? e.g. Add a cover photo to your profile" />' +
                            '<input type="text" name="process_action_url[]" class="em-process-action-url" placeholder="Link, e.g. https://gend.me/members/me/ (each member’s own profile)" />' +
                            '<select name="process_action_mode[]" class="em-process-action-mode">' + actionModeOptionsHtml + '</select>' +
                            '<div class="em-process-updates">' +
                                '<span class="em-pf-label">The member must do all of these</span>' +
                                '<div class="em-process-updates-grid">' + actionUpdatesHtml + '</div>' +
                            '</div>' +
                            '<span class="em-pf-hint em-process-action-hint"></span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="em-pf-field em-process-row__emails">' +
                        '<span class="em-pf-label">Emails</span>' +
                        '<div class="em-process-emaillist"></div>' +
                        '<input type="hidden" class="em-process-emails-json" name="process_emails_json[]" value="[]" />' +
                        '<button type="button" class="button em-process-addemail" style="margin-top:6px;"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Add New Email</button>' +
                    '</div>' +
                '</div>' +
                '</div>' +
                '<div class="em-process-subpane" data-sub="access"></div>' +
            '</div>'
        );
        var isFinal = !!parseInt(step.final, 10);
        $row.find('.em-process-final').val(isFinal ? 1 : 0);
        $row.toggleClass('em-process-row--final', isFinal);
        if (isFinal) $row.find('.em-process-title').attr('placeholder', 'Approved');
        $row.find('.em-process-title').val(step.title || '');
        $row.find('.em-process-desc').val(step.desc || '');
        $row.find('.em-process-role').html(roleOptionsHtml).val(step.role || '');
        $row.find('.em-process-autocreate').prop('checked', !!parseInt(step.auto_create, 10));
        populateListSelect($row.find('.em-process-row__list'), step.list_id || 0);

        // How this step gets completed (steps after step 1 only — the first
        // step is completed by the form submission itself).
        $row.find('.em-process-completion').val(step.completion === 'action' ? 'action' : 'approval');
        $row.find('.em-process-approver-type').val(step.approver_type === 'member' ? 'member' : 'role');
        $row.find('.em-process-approver-role').html(approverRoleOptionsHtml).val(step.approver_role || 'administrator');
        $row.find('.em-process-action-label').val(step.action_label || '');
        $row.find('.em-process-action-url').val(step.action_url || '');
        $row.find('.em-process-action-mode').val(step.action_mode === 'update' ? 'update' : 'manual');
        var ticked = step.action_updates || [];
        $row.find('.em-process-update').each(function () {
            $(this).prop('checked', ticked.indexOf($(this).val()) !== -1);
        });
        setApproverMember($row, parseInt(step.approver_user, 10) || 0, step.approver_user_name || '');
        toggleCompletionUI($row);

        // Order in the list: the steps, then the Add Step button, then the approved step -- so the approved step
        // is always last and the button always sits just above it.
        var $add = $('#em-process-rows .em-process-addwrap');
        var $final = $('#em-process-rows .em-process-row--final');
        if (isFinal) {
            $('#em-process-rows').append($row);                       // after the button
        } else if ($add.length) {
            $row.insertBefore($add);                                  // before the button
        } else if ($final.length) {
            $row.insertBefore($final);
        } else {
            $('#em-process-rows').append($row);
        }
        setRowEmails($row, step.emails || []);
        renumberProcess();
    }

    /* Show only the controls relevant to the chosen completion method. */
    function toggleCompletionUI($row) {
        var isAction = $row.find('.em-process-completion').val() === 'action';
        $row.find('.em-process-approval').toggle(!isAction);
        $row.find('.em-process-actionpanel').toggle(isAction);

        var byMember = $row.find('.em-process-approver-type').val() === 'member';
        $row.find('.em-process-approver-role').toggle(!byMember);
        $row.find('.em-process-member-picker').toggle(byMember);
        $row.find('.em-process-approval-hint').text(byMember
            ? 'Only this member (and site admins) can approve. They are emailed a one-click approval link.'
            : 'Everyone with this role is emailed a one-click approval link; the first to approve moves the applicant on.');

        var tracked = $row.find('.em-process-action-mode').val() === 'update';
        $row.find('.em-process-updates').toggle(tracked);
        $row.find('.em-process-action-hint').text(tracked
            ? 'Tracked automatically. Cover-page options are checked against the member’s page as it stands; everything else counts only when the member does it after reaching this step. When every ticked action is done the step is achieved. The applicant is emailed the list.'
            : 'Your team marks this done from the Applicants list. The applicant is emailed the instructions above.');
    }

    /* Chosen-member chip for a step's specific approver. */
    function setApproverMember($row, id, name) {
        $row.find('.em-process-approver-user').val(id || 0);
        var $chosen = $row.find('.em-process-member-chosen');
        if (id) {
            $chosen.find('.em-process-member-chosen-text').text(name || ('Member #' + id));
            $chosen.prop('hidden', false);
            $row.find('.em-process-member-search').hide();
        } else {
            $chosen.prop('hidden', true);
            $row.find('.em-process-member-search').show().val('');
        }
        $row.find('.em-process-member-menu').prop('hidden', true).empty();
    }
    function renumberProcess() {
        $('#em-process-rows .em-process-row').each(function (i) {
            var $row = $(this);
            $row.find('.em-process-row__num').text(i + 1);
            $row.find('.em-process-autocreate').attr('name', 'process_auto_create[' + i + ']');
            // Checkbox groups (like auto-create) only serialize when ticked,
            // so key them by row instead of relying on position.
            $row.find('.em-process-update').attr('name', 'process_action_updates[' + i + '][]');
            // Only steps after step 1 have a completion method — step 1 is
            // completed by the submission itself, and the approved step
            // (always last) just runs.
            var isFinal = $row.hasClass('em-process-row--final');
            $row.find('.em-process-row__completion').toggle(i >= 1 && !isFinal);
            $row.find('.em-process-remove').toggle(!isFinal);
            // Its role is chosen on the Contract tab instead.
            $row.find('.em-process-row__role').toggle(!isFinal);
            $row.find('.em-process-row__final-tag, .em-process-row__final-hint').prop('hidden', !isFinal);

            // Step 1 is split into Submission / Role Access; every other step is just its own settings.
            var isFirst = i === 0 && !isFinal;
            $row.toggleClass('em-process-row--tabbed', isFirst);
            $row.find('.em-process-subtabs').prop('hidden', !isFirst);
            $row.find('.em-process-row__formline').prop('hidden', !isFirst);
            if (!isFirst) showRowSubtab($row, 'submission');

            // Step 1 always fires automatically the moment the applicant
            // submits the linked form (see on_form_submission() server-side)
            // — make that explicit rather than leaving it implicit.
            var $hint = $row.find('.em-process-row__trigger-hint');
            if (i === 0 && !isFinal) {
                if (!$hint.length) {
                    $row.find('.em-process-remove').before('<span class="em-process-row__trigger-hint">Runs on submission</span>');
                }
            } else {
                $hint.remove();
            }
        });
        placeEnrollPane();
        updateFormLine();
    }

    /* Role Access — the role that enrols a member in the contract when they earn it.
     * One field for the whole application, shown inside whichever step is first;
     * it is parked outside the rows whenever they are rebuilt or removed. */
    function parkEnrollPane() {
        $('#em-enroll-pane').prop('hidden', true).appendTo('#em-enroll-park');
    }
    function placeEnrollPane() {
        var $first = $('#em-process-rows .em-process-row').first();
        if ($first.length && !$first.hasClass('em-process-row--final')) {
            $('#em-enroll-pane').prop('hidden', false).appendTo($first.find('.em-process-subpane[data-sub="access"]'));
        } else {
            parkEnrollPane();
        }
    }
    function showRowSubtab($row, sub) {
        $row.find('.em-process-subtab').removeClass('is-active').filter('[data-sub="' + sub + '"]').addClass('is-active');
        $row.find('.em-process-subpane').removeClass('is-active').filter('[data-sub="' + sub + '"]').addClass('is-active');
    }
    $doc.on('click', '.em-process-subtab', function () { showRowSubtab($(this).closest('.em-process-row'), $(this).data('sub')); });

    // The Submission tab says which application form step 1 runs for.
    function updateFormLine() {
        var id = parseInt($combo.find('[name="form_id"]').val(), 10) || 0;
        var html;
        if (id) {
            var name = $.trim($combo.find('.em-combo__chosen-text').text());
            var url = /admin-ajax\.php/.test(cfg.ajaxUrl || '') ? cfg.ajaxUrl.replace(/admin-ajax\.php.*$/, 'post.php?post=' + id + '&action=edit') : '';
            html = 'Runs each time this application form is submitted: <strong>' + esc(name) + '</strong>' +
                (url ? ' · <a href="' + esc(url) + '" target="_blank" rel="noopener">Edit form ↗</a>' : '');
        } else {
            html = 'No application form is linked yet. Link one above and this step runs each time it is submitted.';
        }
        $('.em-process-row__formline').html(html);
    }
    function setEnrollRole(slug) {
        var $sel = $('#em-enroll-role');
        $sel.val(slug || '');
        if ($sel.val() === null) $sel.val('');
        syncContractRoleButtons();
    }
    function setProcess(arr) {
        parkEnrollPane();
        $('#em-process-rows .em-process-row').remove();      // the rows only: the Add Step button stays
        (arr && arr.length ? arr : []).forEach(function (s) { addProcessRow(s); });
        if (!$('#em-process-rows .em-process-row--final').length) addProcessRow($.extend({}, DEFAULT_FINAL));
    }

    /* ---------------- Per-step email list ----------------
     * Each step's emails are kept as an in-memory array on the row element
     * (via jQuery .data) and mirrored into a hidden JSON input so the
     * normal form serialize/save picks them up like any other field. */
    function getRowEmails($row) {
        return $row.data('emails') || [];
    }
    function setRowEmails($row, emails) {
        $row.data('emails', emails);
        $row.find('.em-process-emails-json').val(JSON.stringify(emails));
        renderEmailList($row);
    }
    function renderEmailList($row) {
        var emails = getRowEmails($row);
        var $list = $row.find('.em-process-emaillist');
        if (!emails.length) {
            $list.html('<p class="em-pf-hint" style="margin:4px 0;">No emails configured for this step yet.</p>');
            return;
        }
        var html = emails.map(function (e, i) {
            var toParts = [];
            if (e.to_applicant) toParts.push('Applicant');
            if (e.to_emails) toParts.push(e.to_emails);
            return '<div class="em-process-email-item" data-index="' + i + '">' +
                '<div class="em-process-email-item__summary">' +
                    '<strong>' + esc(e.subject || '(no subject)') + '</strong>' +
                    '<small>' + esc(toParts.join(' + ') || 'No recipient set') + '</small>' +
                '</div>' +
                '<button type="button" class="button button-small em-process-email-edit">Edit</button>' +
                '<button type="button" class="button button-small em-process-email-remove">Remove</button>' +
            '</div>';
        }).join('');
        $list.html(html);
    }

    /* ---------------- Mailing lists (per Apply Process step) ---------------- */
    var knownLists = [];

    function populateListSelect($select, selectedId) {
        $select.find('option:not([value="0"])').remove();
        knownLists.forEach(function (l) {
            $select.append($('<option>', { value: l.id, text: l.name + (l.count ? ' (' + l.count + ')' : '') }));
        });
        $select.val(selectedId || 0);
    }
    function setKnownLists(lists) {
        knownLists = lists || [];
        $('#em-process-rows .em-process-row__list').each(function () {
            populateListSelect($(this), $(this).val());
        });
    }

    /* ---------------- Roles (per Apply Process step) ---------------- */
    var knownRoles = [];
    var roleOptionsHtml = '';
    function buildRoleOptionsHtml(roles) {
        var html = '<option value="">— No role —</option>';
        (roles || []).forEach(function (r) {
            html += '<option value="' + esc(r.slug) + '">' + esc(r.name) + '</option>';
        });
        return html;
    }
    var approverRoleOptionsHtml = '';
    function setKnownRoles(roles) {
        knownRoles = roles || [];
        roleOptionsHtml = buildRoleOptionsHtml(knownRoles);
        approverRoleOptionsHtml = buildRoleOptionsHtml(knownRoles).replace('<option value="">— No role —</option>', '');
        $('#em-process-rows .em-process-role').each(function () {
            var cur = $(this).val();
            $(this).html(roleOptionsHtml).val(cur);
        });
        $('#em-process-rows .em-process-approver-role').each(function () {
            var cur = $(this).val();
            $(this).html(approverRoleOptionsHtml).val(cur);
        });
        $('#em-contract-role, #em-enroll-role').each(function () {
            var cur = $(this).val();
            $(this).html(roleOptionsHtml).val(cur);
        });
    }
    // The role members get on approval (Contract tab). An unknown/deleted role falls back to none.
    function setContractRole(slug) {
        var $sel = $('#em-contract-role');
        $sel.val(slug || '');
        if ($sel.val() === null) $sel.val('');
        syncContractRoleButtons();
    }
    // "Edit Access" only makes sense once a role is chosen.
    function syncContractRoleButtons() {
        $('.em-contract-editrole').prop('hidden', !$('#em-contract-role').val());
        $('.em-enroll-editrole').prop('hidden', !$('#em-enroll-role').val());
    }
    $doc.on('change', '#em-contract-role, #em-enroll-role', syncContractRoleButtons);
    setKnownRoles(cfg.roles || []);

    /* "User action" steps: how it's confirmed, and (for "Action update") the
     * member actions that are tracked automatically. */
    var actionModeOptionsHtml = '';
    var modes = cfg.actionModes && Object.keys(cfg.actionModes).length ? cfg.actionModes : { manual: 'Team confirms manually', update: 'Action update' };
    Object.keys(modes).forEach(function (slug) {
        actionModeOptionsHtml += '<option value="' + esc(slug) + '">' + esc(modes[slug]) + '</option>';
    });
    var actionUpdatesHtml = '';
    Object.keys(cfg.actionUpdates || {}).forEach(function (slug) {
        actionUpdatesHtml += '<label class="em-pf-switch em-process-update-row">' +
            '<input type="checkbox" class="em-process-update" value="' + esc(slug) + '" />' +
            '<span>' + esc(cfg.actionUpdates[slug]) + '</span></label>';
    });

    /* ---------------- Drawer / modal open-close ---------------- */
    function openModal($m)  { $m.addClass('is-open').attr('aria-hidden', 'false'); $('body').addClass('em-drawer-locked'); }
    function closeModal($m) {
        // However the email popup closes (Cancel, Save, the backdrop, Escape) its editor goes with it.
        if ($m.is('#em-email-modal')) teardownEmailEditor();
        $m.removeClass('is-open').attr('aria-hidden', 'true');
        if (!$('.em-drawer.is-open').length) $('body').removeClass('em-drawer-locked');
    }

    $doc.on('click', '#em-posting-drawer [data-close], #em-posting-analytics-drawer [data-close]', function () {
        closeModal($(this).closest('.em-drawer'));
    });
    $doc.on('click', '#em-newform-modal [data-close-nf]', function () { closeModal($('#em-newform-modal')); });
    $doc.on('keydown', function (ev) {
        if (ev.key === 'Escape') {
            if ($('#em-detail-drawer').hasClass('is-open')) return;   // the application drawer sits on top: Escape closes just that
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
        updateFormLine();
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
            $open.prop('hidden', true).attr('href', '#');   // don't keep the previous posting's link on the hidden button
        }
    }

    /* ---------------- Contract tab: Commissions / Services sub-tabs ---------------- */
    // Sub-tabs exist in more than one pane (Contract, Postings), so each switch only touches its own pane.
    function showSubTab($pane, name) {
        $pane.find('.em-pf-subtab').removeClass('is-active').filter('[data-substep="' + name + '"]').addClass('is-active');
        $pane.find('.em-pf-subpane').removeClass('is-active').filter('[data-subpane="' + name + '"]').addClass('is-active');
    }
    function showContractTab(name) { showSubTab($form.find('.em-pf-pane[data-pane="contract"]'), name); }
    function showPostingsTab(name) {
        showSubTab($form.find('.em-pf-pane[data-pane="postings"]'), name);
        if (name === 'affiliates') refreshProgramSelects();
    }
    $doc.on('click', '.em-pf-subtab', function () {
        var $pane = $(this).closest('.em-pf-pane');
        showSubTab($pane, $(this).data('substep'));
        if ($pane.data('pane') === 'postings' && $(this).data('substep') === 'affiliates') refreshProgramSelects();
    });

    /* Commissions: any number of contracts. The chosen ones are rows, each
     * carrying a hidden contract_commissions[] input; the dropdown only adds
     * to that list (it posts nothing itself). A stored contract that has
     * since been deleted has no option left, so it is simply left out. */
    var COMMISSION_TYPE_LABELS = { referral: 'Referral', role: 'Role', member: 'Member' };
    function commissionInfo(ref) {
        var $opt = $('#em-contract-commission option').filter(function () { return this.value === ref; }).first();
        return $opt.length ? { ref: ref, name: $opt.text(), group: $opt.parent('optgroup').data('group') || '' } : null;
    }
    function chosenCommissions() {
        return $('#em-cc-chosen input[name="contract_commissions[]"]').map(function () { return this.value; }).get();
    }
    function syncCommissions() {
        var chosen = chosenCommissions();
        // Already-attached contracts can't be picked twice.
        $('#em-contract-commission option').each(function () { if (this.value) this.disabled = chosen.indexOf(this.value) !== -1; });
        $('.em-cc-empty').toggle(!chosen.length);
        refreshProgramSelects();
    }
    function addCommission(ref) {
        var info = commissionInfo(ref);
        if (!info || chosenCommissions().indexOf(ref) !== -1) return;
        var $row = $(
            '<div class="em-cc-item">' +
                '<span class="em-cc-item__type"></span><span class="em-cc-item__name"></span>' +
                '<button type="button" class="em-cc-item__remove" aria-label="Remove">&times;</button>' +
                '<input type="hidden" name="contract_commissions[]" />' +
            '</div>'
        );
        $row.find('.em-cc-item__type').addClass('em-cc-item__type--' + info.group).text(COMMISSION_TYPE_LABELS[info.group] || 'Contract');
        $row.find('.em-cc-item__name').text(info.name);
        $row.find('input').val(ref);
        $('#em-cc-chosen').append($row);
        syncCommissions();
    }
    function setCommissions(refs) {
        $('#em-cc-chosen').empty();
        (refs || []).forEach(function (ref) { addCommission(ref); });
        syncCommissions();
    }
    $doc.on('change', '#em-contract-commission', function () {
        var ref = $(this).val();
        $(this).val('');
        if (ref) addCommission(ref);
    });
    $doc.on('click', '.em-cc-item__remove', function () { $(this).closest('.em-cc-item').remove(); syncCommissions(); });

    /* Dashboard sections grid (the Feature Access catalog): only each menu's
     * checkbox is shown at first. Ticking a menu reveals its pages, all ticked,
     * and any can be unticked; unticking the menu clears and hides them again.
     * (A page can't stay allowed without its menu — the server enforces the
     * same.) The grid appears twice — the Contract tab's Dashboard Access and
     * the role popup — so everything works inside its own .em-da-scope. */
    function contractDaScope() { return $form.find('.em-da-scope'); }
    function daScope(el) { return $(el).closest('.em-da-scope'); }
    // A menu's first page usually shares its slug, so count distinct slugs
    // (what is actually stored), not ticked boxes.
    function daTicked($scope) {
        var seen = {}, list = [];
        $scope.find('.em-da-check:checked').each(function () { if (!seen[this.value]) { seen[this.value] = true; list.push(this.value); } });
        return list;
    }
    function syncDashboardCount($scope) {
        var n = daTicked($scope).length;
        $scope.find('.em-da-count').text(n ? n + ' selected' : 'None selected');
    }
    // Pages show only while their menu is ticked.
    function daSyncCard($card) {
        $card.find('.em-da-children').prop('hidden', !$card.find('.em-da-check--parent').prop('checked'));
    }
    function setDashboardAccess(slugs, $scope) {
        $scope = $scope || contractDaScope();
        var set = {};
        (slugs || []).forEach(function (slug) { set[slug] = true; });
        $scope.find('.em-da-check').each(function () { $(this).prop('checked', !!set[this.value]); });
        // A page can't be allowed without its menu, so a stray page brings its menu along.
        $scope.find('.em-da-card').each(function () {
            var $card = $(this);
            if ($card.find('.em-da-check--child:checked').length) $card.find('.em-da-check--parent').prop('checked', true);
            daSyncCard($card);
        });
        $scope.find('.em-da-filter').val('');
        $scope.find('.em-da-card').prop('hidden', false);
        $scope.find('.em-da-nomatch').prop('hidden', true);
        syncDashboardCount($scope);
    }
    $doc.on('change', '.em-da-check--parent', function () {
        var $card = $(this).closest('.em-da-card');
        $card.find('.em-da-check--child').prop('checked', this.checked);   // ticked: every page starts ticked; unticked: all cleared
        daSyncCard($card);
        syncDashboardCount(daScope(this));
    });
    $doc.on('change', '.em-da-check--child', function () {
        var $card = $(this).closest('.em-da-card');
        if (this.checked) $card.find('.em-da-check--parent').prop('checked', true);
        daSyncCard($card);
        syncDashboardCount(daScope(this));
    });
    // Select all / Clear act on what the filter is currently showing.
    function daBulk(scopeEl, on) {
        var $sc = daScope(scopeEl);
        $sc.find('.em-da-card:not([hidden])').each(function () {
            $(this).find('.em-da-check').prop('checked', on);
            daSyncCard($(this));
        });
        syncDashboardCount($sc);
    }
    $doc.on('click', '.em-da-all', function () { daBulk(this, true); });
    $doc.on('click', '.em-da-none', function () { daBulk(this, false); });
    $doc.on('input', '.em-da-filter', function () {
        var $sc = daScope(this);
        var term = $.trim($(this).val()).toLowerCase();
        var shown = 0;
        $sc.find('.em-da-card').each(function () {
            var match = !term || String($(this).data('search') || '').indexOf(term) !== -1;
            $(this).prop('hidden', !match);
            if (match) shown++;
        });
        $sc.find('.em-da-nomatch').prop('hidden', shown > 0);
    });

    // Select the stored contract; a pick whose contract has since been
    // deleted has no option left, so it falls back to "None".
    function setContractPick(name, value) {
        var $sel = $form.find('[name="' + name + '"]');
        if (!$sel.length) return;
        $sel.val(value || '');
        if ($sel.val() === null) $sel.val('');
    }

    /* ---------------- Reset / fill ---------------- */
    function resetForm() {
        $form[0].reset();
        $form.find('[name="posting_id"]').val('0');
        $form.find('[name="status"]').val('open');
        $form.find('[name="ty_mode"][value="message"]').prop('checked', true);
        $form.find('[name="contract_enabled"]').prop('checked', false);
        $form.find('[name="contract_require"]').prop('checked', true);
        setCommissions([]);
        setContractPick('contract_service', '');
        setDashboardAccess([]);
        setContractRole('');
        setEnrollRole('');
        showContractTab('commissions');
        showPostingsTab('affiliates');
        setBoards([], '');
        setChosenForm(0, '');
        setPageArea('landing', '');
        setPageArea('thankyou', '');
        updateShortcodeBoxes('');
        setKnownLists([]);
        setProcess(DEFAULT_PROCESS);
        syncThankYou();
        showStep('landing');
    }

    function fillForm(d) {
        var t = d.thankyou || {};
        $form.find('[name="posting_id"]').val(d.id || 0);
        $form.find('[name="title"]').val(d.title || '');
        $form.find('[name="status"]').val(d.status || 'open');
        setChosenForm(d.form_id || 0, d.form_title ? (d.form_title + (d.form_type ? ' (' + d.form_type + ')' : '')) : '');

        $form.find('[name="ty_mode"][value="' + (t.mode || 'message') + '"]').prop('checked', true);
        $form.find('[name="ty_message"]').val(t.message || '');
        $form.find('[name="ty_redirect_url"]').val(t.redirect_url || '');

        // Apply Process (roles + lists refreshed first so the rows built
        // from d.process can select the right options immediately).
        setKnownRoles(d.roles || []);
        setKnownLists(d.lists || []);
        setProcess(d.process && d.process.length ? d.process : DEFAULT_PROCESS);

        // Contract
        var c = d.contract || {};
        $form.find('[name="contract_enabled"]').prop('checked', !!parseInt(c.enabled, 10));
        $form.find('[name="contract_title"]').val(c.title || 'Applicant Agreement');
        $form.find('[name="contract_body"]').val(c.body || '');
        $form.find('[name="contract_require"]').prop('checked', c.require_accept === undefined ? true : !!parseInt(c.require_accept, 10));
        setCommissions(c.commissions || (c.commission ? [c.commission] : []));
        setContractPick('contract_service', c.service);
        setDashboardAccess(c.dashboard_access || []);
        setContractRole(c.role || '');
        setEnrollRole(d.enroll_role || '');

        setBoards(d.boards || [], d.landing_url || '');

        updateShortcodeBoxes(d.shortcode || '');
        setPageArea('landing', d.landing_edit_url || '');
        setPageArea('thankyou', d.thankyou_edit_url || '');
        syncThankYou();
    }

    /* ---------------- New / edit ---------------- */
    $doc.on('click', '.em-posting-new', function () {
        editOpenToken++;             // a late response for a posting opened earlier must not fill this one
        editingId = 0;
        setEditorState('ready');
        resetForm();
        $('#em-posting-drawer-title').text(i18n.newPosting || 'New Posting');
        openModal($editor);
    });

    /* Opening a posting for editing.
     *  - The data is fetched over REST (admin-ajax boots the whole wp-admin —
     *    several seconds on this site), falling back to admin-ajax, and starts
     *    on hover so it is usually ready by the click.
     *  - While it loads the form is LOCKED (only the tab strip stays usable):
     *    a blank form must never be edited or saved, and the arriving data must
     *    never overwrite anything typed. The tab you are on is never changed.
     *  - A response for a posting you have since closed or replaced is ignored. */
    var POSTING_FRESH_MS = typeof cfg.postingFreshMs === 'number' ? cfg.postingFreshMs : 15000;   // a just-fetched posting is reused for this long
    var postingCache = {};           // id -> { data, at, req }
    var editOpenToken = 0;           // bumped per open, so only the latest open paints
    var editingId = 0;
    var editorState = 'ready';       // ready | loading | error

    function requestPosting(id) {
        var entry = postingCache[id] || (postingCache[id] = {});
        if (entry.req) return entry.req;
        var dfd = $.Deferred();
        entry.req = dfd.promise();   // set first, so a response that completes at once still clears it
        function done(data) { entry.data = data; entry.at = Date.now(); entry.req = null; dfd.resolve(data); }
        function fail()     { entry.req = null; dfd.reject(); }
        function viaAjax() {
            $.post(cfg.ajaxUrl, { action: 'em_get_posting', posting_id: id, nonce: cfg.nonce })
                .done(function (res) { if (res && res.success) done(res.data); else fail(); })
                .fail(fail);
        }
        if (cfg.restRoot && cfg.restNonce) {
            $.ajax({ url: cfg.restRoot + 'postings/' + id, method: 'GET', dataType: 'json', cache: false, headers: { 'X-WP-Nonce': cfg.restNonce } })
                .done(function (data) { if (data && typeof data === 'object' && 'id' in data) done(data); else viaAjax(); })
                .fail(viaAjax);
        } else {
            viaAjax();
        }
        return dfd.promise();
    }
    function postingIsFresh(id) {
        var e = postingCache[id];
        return !!(e && e.data && e.at && Date.now() - e.at < POSTING_FRESH_MS);
    }
    // Anything that changes what a posting's payload contains (its roles, lists…) makes a cached copy stale.
    function dropPostingCache() { postingCache = {}; }
    $doc.on('mouseenter focusin touchstart', '.em-posting-edit', function () {
        var id = $(this).closest('.em-posting-card').data('posting-id');
        if (id && !postingIsFresh(id) && !(postingCache[id] && postingCache[id].req)) requestPosting(id);
    });

    // ready: normal.  loading: fetching or saving — form locked.  error: could not load — form locked, retry offered.
    function setEditorState(state, label) {
        editorState = state;
        var locked = state !== 'ready';
        $editor.toggleClass('is-loading', state === 'loading').toggleClass('is-load-error', state === 'error');
        $('#em-pf-loadbar').prop('hidden', state !== 'loading');
        $('#em-pf-loaderror').prop('hidden', state !== 'error');
        $('#em-pf-sync').text(state === 'loading' ? (label || 'Loading…') : '');
        // Everything except the tab strip (and Close) is locked; inert also blocks keyboard focus.
        $form.children().not('.em-pf-steps, .em-pf-actions').each(function () {
            if (locked) this.setAttribute('inert', ''); else this.removeAttribute('inert');
        });
        $form.find('button[type="submit"]').prop('disabled', locked);
    }

    function loadPostingIntoEditor(id) {
        var token = ++editOpenToken;
        var stale = function () { return token !== editOpenToken || !$editor.hasClass('is-open'); };
        if (postingIsFresh(id)) {
            fillForm(postingCache[id].data);
            setEditorState('ready');
            return;
        }
        setEditorState('loading');
        requestPosting(id)
            .done(function (data) { if (stale()) return; fillForm(data); setEditorState('ready'); })
            .fail(function () { if (stale()) return; setEditorState('error'); });
    }

    $doc.on('click', '.em-posting-edit', function () {
        var id = $(this).closest('.em-posting-card').data('posting-id');
        if (!id) return;
        resetForm();
        editingId = id;
        $('#em-posting-drawer-title').text(i18n.editPosting || 'Edit Posting');
        openModal($editor);
        loadPostingIntoEditor(id);
    });
    // Retry reloads the data in place — it never resets the form or switches tab.
    $doc.on('click', '.em-pf-retry', function () { if (editingId) loadPostingIntoEditor(editingId); });

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
    $doc.on('click', '.em-process-add', function () { addProcessRow({}); });
    $doc.on('click', '.em-process-remove', function () {
        var $row = $(this).closest('.em-process-row');
        if ($row.hasClass('em-process-row--final')) return;
        parkEnrollPane();
        $row.remove();
        renumberProcess();
    });

    $doc.on('change', '.em-process-completion, .em-process-approver-type, .em-process-action-mode', function () {
        toggleCompletionUI($(this).closest('.em-process-row'));
    });

    /* Specific-approver member search (per step). */
    var memberSearchTimer = null;
    $doc.on('focus input', '.em-process-member-search', function () {
        var $menu = $(this).closest('.em-process-member-picker').find('.em-process-member-menu');
        var q = $(this).val();
        clearTimeout(memberSearchTimer);
        memberSearchTimer = setTimeout(function () {
            $.post(cfg.ajaxUrl, { action: 'em_search_members', q: q, nonce: cfg.nonce }).done(function (res) {
                if (!res || !res.success) return;
                var members = res.data.members || [];
                if (!members.length) {
                    $menu.html('<div class="em-process-member-empty">No members found</div>').prop('hidden', false);
                    return;
                }
                $menu.html(members.map(function (m) {
                    return '<button type="button" class="em-process-member-item" data-id="' + esc(m.id) + '" data-name="' + esc(m.name) + '">' +
                        '<span>' + esc(m.name) + '</span><small>' + esc(m.email) + '</small></button>';
                }).join('')).prop('hidden', false);
            });
        }, 220);
    });
    $doc.on('click', '.em-process-member-item', function () {
        setApproverMember($(this).closest('.em-process-row'), parseInt($(this).attr('data-id'), 10) || 0, $(this).attr('data-name') || '');
    });
    $doc.on('click', '.em-process-member-clear', function () {
        setApproverMember($(this).closest('.em-process-row'), 0, '');
    });
    $doc.on('click', function (e) {
        if (!$(e.target).closest('.em-process-member-picker').length) $('.em-process-member-menu').prop('hidden', true);
    });

    /* ---------------- New mailing list (per Apply Process step) ---------------- */
    $doc.on('click', '.em-process-newlist', function () {
        var $btn = $(this);
        var $select = $btn.closest('.em-process-row').find('.em-process-row__list');
        var name = $form.find('[name="title"]').val() || 'Applicants';
        $btn.prop('disabled', true);
        $.post(cfg.ajaxUrl, { action: 'em_create_list', name: name, nonce: cfg.nonce })
            .done(function (res) {
                if (res && res.success) {
                    knownLists.push(res.data);
                    dropPostingCache();
                    setKnownLists(knownLists);
                    $select.val(res.data.id);
                } else {
                    alert((res && res.data && res.data.message) || 'Could not create list.');
                }
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    /* ---------------- Role popup: create a role / edit a role's access ----------------
     * One popup, two modes. Both show the full feature-access selection: what
     * the role can DO (capabilities) and which dashboard sections it can SEE
     * (the Feature Access catalog). Create is opened from a step's role picker
     * or the Contract tab; Edit Access from the Contract tab once a role is
     * chosen. */
    var $roleModal = $('#em-role-modal');
    var $roleEditTargetSelect = null;
    var roleModalMode = 'create';
    var roleModalSlug = '';

    function roleScope() { return $roleModal.find('.em-da-scope'); }
    function findKnownRole(slug) {
        for (var i = 0; i < knownRoles.length; i++) { if (knownRoles[i].slug === slug) return knownRoles[i]; }
        return null;
    }

    function openRoleModal(mode, $target, role) {
        var edit = mode === 'edit';
        roleModalMode = mode;
        roleModalSlug = edit ? role.slug : '';
        $roleEditTargetSelect = $target;

        $('#em-role-title').text(edit ? 'Edit Role Access' : 'Create Role');
        $('#em-role-name').val(edit ? role.name : '').prop('readonly', edit);
        $roleModal.find('.em-role-create').text(edit ? 'Save Access' : 'Create Role');
        $roleModal.find('.em-role-saving').text(edit ? 'Saving…' : 'Creating…');

        var caps = edit ? (role.caps || []) : [];
        $roleModal.find('.em-role-cap').each(function () {
            $(this).prop('checked', $(this).val() === 'read' || caps.indexOf($(this).val()) !== -1);
        });
        setDashboardAccess(edit ? (role.dashboard || []) : [], roleScope());

        // Administrator-level roles are shown, never edited.
        var locked = edit && !!role.locked;
        $roleModal.find('.em-role-locked').prop('hidden', !locked);
        $roleModal.find('.em-role-access').find('input, button').prop('disabled', locked);
        $roleModal.find('.em-role-cap[value="read"]').prop('disabled', true);   // every role can log in
        $roleModal.find('.em-da-check--same').prop('disabled', true);          // a menu's own page comes with the menu
        $roleModal.find('.em-role-create').prop('disabled', locked);
        openModal($roleModal);
    }

    $doc.on('click', '.em-process-addrole', function () {
        openRoleModal('create', $(this).closest('.em-process-row').find('.em-process-role'));
    });
    $doc.on('click', '.em-contract-addrole', function () {
        openRoleModal('create', $('#em-contract-role'));
    });
    $doc.on('click', '.em-enroll-addrole', function () {
        openRoleModal('create', $('#em-enroll-role'));
    });
    $doc.on('click', '.em-contract-editrole, .em-enroll-editrole', function () {
        var $sel = $(this).hasClass('em-enroll-editrole') ? $('#em-enroll-role') : $('#em-contract-role');
        var role = findKnownRole($sel.val());
        if (!role) { alert('Choose a role first.'); return; }
        openRoleModal('edit', $sel, role);
    });
    $doc.on('click', '#em-role-modal [data-close-role]', function () { closeModal($roleModal); });

    $doc.on('click', '.em-role-create', function () {
        var $btn = $(this);
        var edit = roleModalMode === 'edit';
        var name = ($('#em-role-name').val() || '').trim();
        if (!edit && !name) { alert('Enter a role name.'); return; }
        var caps = [];
        $roleModal.find('.em-role-cap:checked').each(function () { caps.push($(this).val()); });
        var $scope = roleScope();
        var req = edit
            ? { action: 'em_update_role_access', role: roleModalSlug, capabilities: caps, nonce: cfg.nonce }
            : { action: 'em_create_role', name: name, capabilities: caps, nonce: cfg.nonce };
        if ($scope.length) {
            req.dashboard = daTicked($scope);
            req.dashboard_present = 1;   // sent even when empty, so unticking everything is honoured
        }

        $btn.prop('disabled', true);
        $('.em-role-saving').prop('hidden', false);
        $.post(cfg.ajaxUrl, req)
            .done(function (res) {
                if (res && res.success) {
                    if (edit) {
                        knownRoles = knownRoles.map(function (r) { return r.slug === res.data.slug ? res.data : r; });
                    } else {
                        knownRoles.push(res.data);
                    }
                    dropPostingCache();
                    setKnownRoles(knownRoles);
                    if (!edit && $roleEditTargetSelect) $roleEditTargetSelect.val(res.data.slug);
                    syncContractRoleButtons();
                    closeModal($roleModal);
                } else {
                    alert((res && res.data && res.data.message) || (edit ? 'Could not save the role\'s access.' : 'Could not create role.'));
                }
            })
            .fail(function () { alert('Network error.'); })
            .always(function () { $btn.prop('disabled', false); $('.em-role-saving').prop('hidden', true); });
    });

    /* ---------------- New commission / service contract (Contract tab) ----------------
     * Both popups save through the owning plugin's own AJAX handlers (Sales
     * Team for commissions, its Role Contracts store for services) so the new
     * contract also appears on those admin pages, then add it to the select
     * and pick it. */
    var contractCfg = cfg.contracts || {};

    function contractError(res, fallback) {
        return (res && res.data && res.data.message) || fallback;
    }

    // Add the freshly created contract to the select (inside `group` when the
    // select is grouped) and pick it.
    function addContractOption($select, group, value, label) {
        var $opt = $('<option></option>').val(value).text(label);
        var $group = group ? $select.find('optgroup[data-group="' + group + '"]') : $();
        ($group.length ? $group : $select).append($opt);
        $select.val(value);
    }

    // A used-up choice is removed from its popup select; if that was the last
    // one, leave a placeholder rather than an empty dropdown.
    function removeChoice($select, value, emptyText) {
        $select.find('option').filter(function () { return this.value === value; }).remove();
        if (!$select.find('option').length) $select.append($('<option></option>').val('').text(emptyText));
    }

    // -- commissions
    var $ccModal = $('#em-cc-modal');
    var ccParamTouched = false;

    function slugParam(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 24);
    }
    function syncCcType() {
        var type = $('#em-cc-type').val();
        $ccModal.find('[data-cc-panel]').each(function () { $(this).toggle($(this).data('cc-panel') === type); });
    }
    function syncCcRole() {
        var t = $('#em-cc-roletype').val();
        $ccModal.find('.em-cc-role').each(function () { $(this).toggle($(this).data('roletype') === t); });
    }

    $doc.on('click', '.em-cc-add', function () {
        $('#em-cc-name, #em-cc-param').val('');
        $('#em-cc-rate').val('10');
        $('#em-cc-type').val('referral');
        $('#em-cc-roletype').val('wp_role');
        ccParamTouched = false;
        syncCcType();
        syncCcRole();
        openModal($ccModal);
    });
    $doc.on('click', '#em-cc-modal [data-close-cc]', function () { closeModal($ccModal); });
    $doc.on('change', '#em-cc-type', syncCcType);
    $doc.on('change', '#em-cc-roletype', syncCcRole);
    $doc.on('input', '#em-cc-param', function () { ccParamTouched = true; });
    $doc.on('input', '#em-cc-name', function () {
        if (!ccParamTouched) $('#em-cc-param').val(slugParam($(this).val()));
    });

    $doc.on('click', '.em-cc-create', function () {
        var $btn = $(this);
        var type = $('#em-cc-type').val();
        var name = $.trim($('#em-cc-name').val());
        var rate = parseFloat($('#em-cc-rate').val());
        if (!name) { alert('Enter a contract name.'); return; }
        if (isNaN(rate) || rate < 0 || rate > 100) { alert('Enter a commission rate between 0 and 100.'); return; }

        // One flat tier — the baseline every referred sale earns at.
        var tiers = JSON.stringify([{ rate: rate, method: 'referrals', requirement: 0, timeframe: 'lifetime', timevalue: 0, trigger: 'order_complete', days: 7 }]);
        var req, ref, group, roleKey = '';

        if (type === 'referral') {
            var param = $.trim($('#em-cc-param').val());
            if (!param) { alert('Enter a tracking URL parameter.'); return; }
            group = 'referral';
            req = {
                action: 'aas_save_referral_program', security: contractCfg.accNonce, id: '', name: name, url_param: param,
                cookie_duration: 30, signup_bonus: 0, tracking_condition: 'registration', trigger_status: 'wc-completed',
                wait_days: 7, payout_point_type: 'mycred_default', multi_tier_enabled: '', multi_tier_assignment: 'automatic',
                referred_tiers: tiers, team_levels: '[]', product_categories: '[]'
            };
        } else {
            var roleType = $('#em-cc-roletype').val();
            roleKey = $ccModal.find('.em-cc-role[data-roletype="' + roleType + '"]').val();
            if (!roleKey) { alert('Select a role.'); return; }
            group = 'role';
            ref = 'role:' + roleKey;
            req = {
                action: 'stp_contract_save_settings', security: contractCfg.stcNonce, section: 'commissions',
                data: {
                    new_role: roleKey, new_role_type: roleType, new_role_name: name,
                    new_role_multi_tier_enabled: '', new_role_multi_tier_assignment: 'automatic',
                    new_role_referred_tiers: tiers, new_role_team_levels: '[]', new_role_product_categories: '[]'
                }
            };
        }

        $btn.prop('disabled', true);
        $('.em-cc-saving').prop('hidden', false);
        $.post(cfg.ajaxUrl, req)
            .done(function (res) {
                if (res && res.success) {
                    if (type === 'referral') ref = 'referral:' + res.data.program.id;
                    else $ccModal.find('.em-cc-role').each(function () { removeChoice($(this), roleKey, 'No unassigned roles available'); });
                    addContractOption($('#em-contract-commission'), group, ref, name);   // adds the option to the dropdown…
                    $('#em-contract-commission').val('');
                    addCommission(ref);                                                     // …and attaches it to this posting
                    closeModal($ccModal);
                } else {
                    alert(contractError(res, 'Could not create the commission contract.'));
                }
            })
            .fail(function () { alert('Network error.'); })
            .always(function () { $btn.prop('disabled', false); $('.em-cc-saving').prop('hidden', true); });
    });

    // -- services
    var $scModal = $('#em-sc-modal');

    $doc.on('click', '.em-sc-add', function () {
        $('#em-sc-name').val('');
        $('#em-sc-assignment').val('admin_assigns');
        $('#em-sc-credit').val('15');
        $('#em-sc-trigger').val('task_completion');
        $('#em-sc-days').val('0');
        $('#em-sc-instant').prop('checked', false);
        openModal($scModal);
    });
    $doc.on('click', '#em-sc-modal [data-close-sc]', function () { closeModal($scModal); });

    $doc.on('click', '.em-sc-create', function () {
        var $btn = $(this);
        var memberType = $('#em-sc-membertype').val();
        if (!memberType) { alert('Select a member type.'); return; }
        var req = {
            action: 'stp_contract_save_role_contract', security: contractCfg.stcNonce, id: '',
            name: $.trim($('#em-sc-name').val()), member_type: memberType,
            assignment_method: $('#em-sc-assignment').val(), point_type: 'mycred_default',
            credit_value: $('#em-sc-credit').val(), payout_trigger: $('#em-sc-trigger').val(), payout_days: $('#em-sc-days').val()
        };
        if ($('#em-sc-instant').is(':checked')) req.instant_execution = '1';

        $btn.prop('disabled', true);
        $('.em-sc-saving').prop('hidden', false);
        $.post(cfg.ajaxUrl, req)
            .done(function (res) {
                if (res && res.success && res.data && res.data.role_contract) {
                    var rc = res.data.role_contract;
                    var typeLabel = $('#em-sc-membertype option:selected').text();
                    addContractOption($('#em-contract-service'), '', rc.id, rc.name && rc.name !== typeLabel ? rc.name + ' — ' + typeLabel : typeLabel);
                    removeChoice($('#em-sc-membertype'), memberType, 'No member types available');
                    closeModal($scModal);
                } else {
                    alert(contractError(res, 'Could not create the service contract.'));
                }
            })
            .fail(function () { alert('Network error.'); })
            .always(function () { $btn.prop('disabled', false); $('.em-sc-saving').prop('hidden', true); });
    });

    /* ---------------- Postings tab: affiliate sites + job boards ----------------
     * Two sub-tabs, one row design. Affiliates: this application's affiliate program (its Commission Contracts)
     * listed on affiliate networks / directories. Job Boards: postings on job boards. Either way a row is a
     * site, a status, a tracked link, what it costs and what the site itself reports.
     * Rows serialize as parallel arrays (board_id[], board_kind[], board_key[], …) plus the boards_present
     * marker, so the server can tell "removed them all" from "tab not sent". Every row carries every column
     * (job rows an empty board_program[]) so the arrays stay lined up. The tracked link is the landing URL +
     * ?em_src=<row id>; visits and applications arriving through it are counted per row
     * (see track_landing_source()). */
    var BOARD_KINDS = {
        job: {
            rows: '#em-board-rows', medium: 'job_board', catalog: cfg.boards || [],
            titlePh: 'Posting title on the board (optional)', urlPh: 'https://… link to the posting on the board',
            linkHint: 'Use this as the apply URL on the board, so visits and applications from it are counted here.',
            costLabel: 'Cost', reported: ['Board views', 'Board clicks', 'Board applies'],
            reportedHint: 'Board views / clicks / applies are the numbers the board itself reports — optional, for comparison.'
        },
        affiliate: {
            rows: '#em-affiliate-rows', medium: 'affiliate_network', catalog: cfg.affiliates || [],
            titlePh: 'Listing name (optional)', urlPh: 'https://… link to your program on the site',
            linkHint: 'Use this as the link on the site, so visits and applications from it are counted here.',
            costLabel: 'Cost of the listing', reported: ['Site views', 'Site clicks', 'Site applies'],
            reportedHint: 'Site views / clicks / applies are the numbers the site itself reports — optional, for comparison.'
        }
    };
    var boardStatuses = cfg.boardStatuses || { live: 'Live', paused: 'Paused', closed: 'Closed' };
    var boardLandingUrl = '';

    function kindOf(k) { return k === 'affiliate' ? 'affiliate' : 'job'; }
    function boardOptions(kind, selected) {
        return BOARD_KINDS[kind].catalog.map(function (b) {
            return '<option value="' + esc(b.key) + '"' + (b.key === selected ? ' selected' : '') + '>' + esc(b.label) + '</option>';
        }).join('');
    }
    function boardStatusOptions(selected) {
        return Object.keys(boardStatuses).map(function (k) {
            return '<option value="' + esc(k) + '"' + (k === selected ? ' selected' : '') + '>' + esc(boardStatuses[k]) + '</option>';
        }).join('');
    }
    function newBoardId(key) { return key + '-' + Math.random().toString(36).replace(/[^a-z0-9]/g, '').slice(2, 8).padEnd(6, '0'); }

    function boardTrackedLink(id, key, kind) {
        if (!boardLandingUrl) return '';
        return boardLandingUrl + (boardLandingUrl.indexOf('?') === -1 ? '?' : '&') +
            'em_src=' + encodeURIComponent(id) + '&utm_source=' + encodeURIComponent(key) + '&utm_medium=' + BOARD_KINDS[kindOf(kind)].medium;
    }

    // Recognise the site from a pasted URL.
    function detectBoard(url, kind) {
        var m = /^https?:\/\/([^\/?#]+)/i.exec(String(url || '').trim());
        if (!m) return '';
        var host = m[1].toLowerCase().replace(/:\d+$/, '');
        var catalog = BOARD_KINDS[kindOf(kind)].catalog;
        for (var i = 0; i < catalog.length; i++) {
            var hosts = catalog[i].hosts || [];
            for (var j = 0; j < hosts.length; j++) {
                if (host === hosts[j] || host.slice(-(hosts[j].length + 1)) === '.' + hosts[j]) return catalog[i].key;
            }
        }
        return '';
    }

    function refreshBoardLink($row) {
        var link = boardTrackedLink($row.find('.em-board-id').val(), $row.find('.em-board-key').val(), $row.data('kind'));
        $row.find('.em-board-link__input').val(link);
        $row.find('.em-board-copy').prop('disabled', !link);
        $row.find('.em-board-link__note').toggle(!link);
    }
    function syncBoardEmpty() {
        $('.em-board-empty').each(function () {
            $(this).toggle(!$(BOARD_KINDS[kindOf($(this).data('kind'))].rows + ' .em-board-row').length);
        });
    }

    // An affiliate listing can say which of this posting's Commission Contracts it promotes. The choices are
    // whatever is attached on the Contract tab; one that has since been detached stays selectable (marked) so
    // the saved value is never dropped silently.
    function refreshProgramSelects() {
        var chosen = chosenCommissions();
        $('.em-board-program').each(function () {
            var $sel = $(this);
            var current = $sel.val() || $sel.data('saved') || '';
            var html = '<option value="">All commission contracts on this posting</option>';
            chosen.forEach(function (ref) {
                var info = commissionInfo(ref);
                html += '<option value="' + esc(ref) + '">' + esc(info ? info.name : ref) + '</option>';
            });
            if (current && chosen.indexOf(current) === -1) {
                var stale = commissionInfo(current);
                html += '<option value="' + esc(current) + '">' + esc(stale ? stale.name : current) + ' (not attached any more)</option>';
            }
            $sel.html(html).val(current);
            if ($sel.val() === null) $sel.val('');
            $sel.data('saved', '');
            $sel.closest('.em-board-program-field').find('.em-board-program__hint').text(chosen.length
                ? 'The commission contract this listing promotes.'
                : 'Attach commission contracts on the Contract tab to link a listing to one.');
        });
    }

    function addBoardRow(b) {
        b = b || {};
        var kind = kindOf(b.kind);
        var K = BOARD_KINDS[kind];
        var key = b.board || 'other';
        var cur = esc(cfg.currency || '$');
        var rep = b.reported || {};
        var program = kind === 'affiliate'
            ? '<label class="em-pf-field em-board-program-field"><span class="em-pf-label">Program</span>' +
                  '<select name="board_program[]" class="em-board-program"></select>' +
                  '<span class="em-pf-hint em-board-program__hint"></span></label>'
            : '<input type="hidden" name="board_program[]" value="" />';
        var $row = $(
            '<div class="em-board-row em-board-row--' + kind + '" data-kind="' + kind + '">' +
                '<input type="hidden" name="board_id[]" class="em-board-id" />' +
                '<input type="hidden" name="board_kind[]" value="' + kind + '" />' +
                '<div class="em-board-row__head">' +
                    '<select name="board_key[]" class="em-board-key">' + boardOptions(kind, key) + '</select>' +
                    '<select name="board_status[]" class="em-board-status">' + boardStatusOptions(b.status || 'live') + '</select>' +
                    '<button type="button" class="em-process-remove em-board-remove" aria-label="Remove">&times;</button>' +
                '</div>' +
                program +
                '<input type="text" name="board_title[]" class="em-board-title" placeholder="' + esc(K.titlePh) + '" />' +
                '<input type="text" name="board_url[]" class="em-board-url" placeholder="' + esc(K.urlPh) + '" />' +
                '<div class="em-pf-field em-board-link">' +
                    '<span class="em-pf-label">Tracked apply link</span>' +
                    '<div class="em-pf-inline">' +
                        '<input type="text" class="em-board-link__input" readonly />' +
                        '<button type="button" class="button em-board-copy">Copy</button>' +
                    '</div>' +
                    '<span class="em-pf-hint em-board-link__note">Save the posting to generate this link.</span>' +
                    '<span class="em-pf-hint">' + esc(K.linkHint) + '</span>' +
                '</div>' +
                '<div class="em-board-row__grid">' +
                    '<label class="em-pf-field"><span class="em-pf-label">' + esc(K.costLabel) + ' (' + cur + ')</span><input type="number" name="board_cost[]" class="em-board-cost" min="0" step="0.01" /></label>' +
                    '<label class="em-pf-field"><span class="em-pf-label">' + esc(K.reported[0]) + '</span><input type="number" name="board_rviews[]" class="em-board-rviews" min="0" step="1" /></label>' +
                    '<label class="em-pf-field"><span class="em-pf-label">' + esc(K.reported[1]) + '</span><input type="number" name="board_rclicks[]" class="em-board-rclicks" min="0" step="1" /></label>' +
                    '<label class="em-pf-field"><span class="em-pf-label">' + esc(K.reported[2]) + '</span><input type="number" name="board_rapplies[]" class="em-board-rapplies" min="0" step="1" /></label>' +
                '</div>' +
                '<span class="em-pf-hint">' + esc(K.reportedHint) + '</span>' +
            '</div>'
        );
        $row.find('.em-board-id').val(b.id || newBoardId(key));
        $row.find('.em-board-title').val(b.title || '');
        $row.find('.em-board-url').val(b.url || '');
        $row.find('.em-board-cost').val(b.cost ? b.cost : '');
        $row.find('.em-board-rviews').val(rep.views ? rep.views : '');
        $row.find('.em-board-rclicks').val(rep.clicks ? rep.clicks : '');
        $row.find('.em-board-rapplies').val(rep.applies ? rep.applies : '');
        $row.find('.em-board-program').data('saved', b.program || '');
        $(K.rows).append($row);
        refreshBoardLink($row);
        refreshProgramSelects();
        syncBoardEmpty();
        return $row;
    }

    function setBoards(arr, landingUrl) {
        boardLandingUrl = landingUrl || '';
        $('#em-board-rows, #em-affiliate-rows').empty();
        (arr || []).forEach(function (b) { addBoardRow(b); });
        syncBoardEmpty();
    }

    $doc.on('click', '.em-board-chip', function () {
        var $row = addBoardRow({ board: $(this).data('board'), kind: $(this).data('kind') });
        $row.find('.em-board-url').trigger('focus');
    });
    $doc.on('click', '.em-board-remove', function () { $(this).closest('.em-board-row').remove(); syncBoardEmpty(); });
    // The row id stays fixed (its link may already be in use on the site);
    // changing the site only changes the utm_source in the link.
    $doc.on('change', '.em-board-key', function () { refreshBoardLink($(this).closest('.em-board-row')); });
    $doc.on('input', '.em-board-url', function () {
        var $row = $(this).closest('.em-board-row');
        var $key = $row.find('.em-board-key');
        var found = detectBoard($(this).val(), $row.data('kind'));
        if (found && $key.val() === 'other') { $key.val(found); refreshBoardLink($row); }
    });
    $doc.on('click', '.em-board-copy', function () {
        var $btn = $(this);
        var val = $btn.closest('.em-board-link').find('.em-board-link__input').val();
        if (!val) return;
        copyText(val);
        $btn.addClass('is-copied');
        setTimeout(function () { $btn.removeClass('is-copied'); }, 1200);
    });
    $doc.on('focus click', '.em-board-link__input', function () { this.select(); });

    /* ---------------- Per-step email editor (Visual/Text, full HTML) ---------------- */
    var $emailModal = $('#em-email-modal');
    var emailEditTarget = null; // { $row: jQuery, index: number }
    var EMAIL_EDITOR_ID = 'emmailbody';

    // The modal starts display:none, so a statically-initialized TinyMCE
    // would size itself against a hidden, zero-width container. Instead we
    // (re)initialize it via wp.editor fresh on every open — seeding the
    // plain textarea's value first so TinyMCE picks it up as starting
    // content — and tear it down again on close, per WP's documented
    // pattern for editors inside on-demand-shown containers.
    // The classic editor API. On a page where a block-editor script has replaced wp.editor, WordPress keeps
    // the classic one as wp.oldEditor.
    function classicEditorApi() {
        if (!window.wp) return null;
        if (wp.oldEditor && wp.oldEditor.initialize) return wp.oldEditor;
        return (wp.editor && wp.editor.initialize) ? wp.editor : null;
    }
    // The full set of writing tools, all three rows showing at once (WordPress would fold everything after the
    // first row away behind a toggle), with fonts that email clients actually have.
    var EMAIL_EDITOR_SETTINGS = {
        tinymce: {
            wpautop: true,
            height: 280,
            wordpress_adv_hidden: false,
            // How the writing area looks (not what is saved): a clean sans-serif page, like most inboxes.
            content_style: 'body{font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;margin:14px 18px}' +
                'p{margin:0 0 1em}a{color:#4f46e5}',
            toolbar1: 'formatselect,fontselect,fontsizeselect,bold,italic,underline,strikethrough,subscript,superscript',
            toolbar2: 'forecolor,backcolor,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,outdent,indent,blockquote,hr',
            toolbar3: 'link,unlink,charmap,pastetext,removeformat,undo,redo',
            font_formats: 'Arial=arial,helvetica,sans-serif;Georgia=georgia,palatino,serif;Tahoma=tahoma,arial,helvetica,sans-serif;' +
                'Times New Roman=times new roman,times,serif;Trebuchet MS=trebuchet ms,geneva,sans-serif;Verdana=verdana,geneva,sans-serif;' +
                'Courier New=courier new,courier,monospace',
            fontsize_formats: '10px 12px 14px 16px 18px 20px 24px 28px 32px 36px 48px'
        },
        quicktags: true,
        mediaButtons: false
    };
    // TinyMCE works out where to put its tooltips and drop-down menus by measuring the button. When <body> is
    // position:static it reads the real on-screen rectangle. This admin's theme makes <body> position:relative, and
    // TinyMCE then adds up offsetLeft/offsetTop instead -- which knows nothing about the popup's translate(-50%,-50%)
    // centring -- so every label and menu lands half a popup away from its button. While the editor is open, put the
    // body back to static (see em-app-support.css).
    var EMAIL_EDITOR_BODY_CLASS = 'em-mce-static';
    function initEmailEditor() {
        var api = classicEditorApi();
        if (!api) return;
        $('body').addClass(EMAIL_EDITOR_BODY_CLASS);
        api.initialize(EMAIL_EDITOR_ID, $.extend(true, {}, EMAIL_EDITOR_SETTINGS));
        // WordPress labels the raw-code tab "Text"; this one is the HTML source.
        $('#' + EMAIL_EDITOR_ID + '-html').text('HTML');
    }
    function teardownEmailEditor() {
        var api = classicEditorApi();
        if (api) {
            try { api.remove(EMAIL_EDITOR_ID); } catch (err) { /* not initialized */ }
        }
        $('body').removeClass(EMAIL_EDITOR_BODY_CLASS);
    }
    function getEmailEditorContent() {
        if (window.tinymce && tinymce.get(EMAIL_EDITOR_ID)) {
            tinymce.get(EMAIL_EDITOR_ID).save();
        }
        return $('#' + EMAIL_EDITOR_ID).val() || '';
    }
    function openEmailEditor($row, index) {
        var emails = getRowEmails($row);
        var e = emails[index] || {};
        emailEditTarget = { $row: $row, index: index };
        $('#em-email-to-applicant').prop('checked', !!e.to_applicant);
        $('#em-email-to-extra').val(e.to_emails || '');
        $('#em-email-subject').val(e.subject || '');

        // Name the step this email belongs to in the popup's subtitle.
        var stepName = $.trim($row.find('.em-process-title').first().val() || '');
        $emailModal.find('.em-email__step').text(stepName ? '“' + stepName + '”' : 'this step');

        teardownEmailEditor();
        $('#' + EMAIL_EDITOR_ID).val(e.body || '');
        openModal($emailModal);
        initEmailEditor();
    }

    // Placeholder chips: drop the {token} in at the cursor of whichever field is being written in -- the subject line
    // if that has the cursor, otherwise the message (visual or HTML tab). The chip does not take focus itself, so the
    // cursor and any selection stay exactly where they were.
    function insertAtCursor(field, text) {
        var start = field.selectionStart, end = field.selectionEnd, val = field.value;
        if (typeof start !== 'number') { field.value = val + text; return; }
        field.value = val.slice(0, start) + text + val.slice(end);
        field.selectionStart = field.selectionEnd = start + text.length;
    }
    function insertEmailToken(token, intoSubject) {
        if (intoSubject) {
            var subject = document.getElementById('em-email-subject');
            insertAtCursor(subject, token);
            $(subject).trigger('input').trigger('focus');
            return;
        }
        var ed = window.tinymce && tinymce.get(EMAIL_EDITOR_ID);
        if (ed && ed.initialized && !ed.isHidden()) {
            ed.focus();
            ed.execCommand('mceInsertContent', false, token);
            return;
        }
        var ta = document.getElementById(EMAIL_EDITOR_ID);   // the HTML tab (or the editor is still loading)
        if (!ta) return;
        insertAtCursor(ta, token);
        $(ta).trigger('focus');
    }
    $doc.on('mousedown', '#em-email-modal .em-email__chip', function (ev) { ev.preventDefault(); });
    $doc.on('click', '#em-email-modal .em-email__chip', function () {
        insertEmailToken(String($(this).data('token') || ''), document.activeElement === document.getElementById('em-email-subject'));
    });

    $doc.on('click', '.em-process-addemail', function () {
        var $row = $(this).closest('.em-process-row');
        var emails = getRowEmails($row);
        emails.push({ to_applicant: 0, to_emails: '', subject: '', body: '' });
        setRowEmails($row, emails);
        openEmailEditor($row, emails.length - 1);
    });
    $doc.on('click', '.em-process-email-edit', function () {
        var $row = $(this).closest('.em-process-row');
        var index = $(this).closest('.em-process-email-item').data('index');
        openEmailEditor($row, index);
    });
    $doc.on('click', '.em-process-email-remove', function () {
        var $row = $(this).closest('.em-process-row');
        var index = $(this).closest('.em-process-email-item').data('index');
        var emails = getRowEmails($row);
        emails.splice(index, 1);
        setRowEmails($row, emails);
    });
    $doc.on('click', '#em-email-modal [data-close-email]', function () {
        teardownEmailEditor();
        closeModal($emailModal);
    });
    $doc.on('click', '.em-email-save', function () {
        if (!emailEditTarget) return;
        var body = getEmailEditorContent();
        var emails = getRowEmails(emailEditTarget.$row);
        emails[emailEditTarget.index] = {
            to_applicant: $('#em-email-to-applicant').is(':checked') ? 1 : 0,
            to_emails: $('#em-email-to-extra').val() || '',
            subject: $('#em-email-subject').val() || '',
            body: body
        };
        teardownEmailEditor();
        setRowEmails(emailEditTarget.$row, emails);
        closeModal($emailModal);
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
        if (editorState !== 'ready') return;   // still loading (or failed to load): saving now could overwrite the posting with a blank form
        var $saving = $form.find('.em-pf-saving');
        var $saved  = $form.find('.em-pf-saved');
        var $submit = $form.find('button[type="submit"]');
        $saved.prop('hidden', true);
        $saving.prop('hidden', false);
        $submit.prop('disabled', true);

        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'em_save_posting' });
        data.push({ name: 'nonce', value: cfg.nonce });
        // The response refills the whole form, so hold edits until it lands.
        setEditorState('loading', 'Saving…');

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
                    editingId = res.data.id;
                    postingCache[res.data.id] = { data: res.data, at: Date.now() };
                    $saved.prop('hidden', false);
                    setTimeout(function () { $saved.prop('hidden', true); }, 2200);
                } else {
                    alert((res && res.data && res.data.message) || i18n.saveFailed || 'Could not save.');
                }
            })
            .fail(function () { alert(i18n.saveFailed || 'Could not save.'); })
            .always(function () { setEditorState('ready'); $saving.prop('hidden', true); });
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
                    delete postingCache[id];
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
    var currentAnalyticsPostingId = 0;
    var currentAnalyticsProcess = [];
    var currentAnalyticsContractRole = '';

    /* How the drawer loads.
     *  - Fetched over REST: admin-ajax boots the whole wp-admin (several
     *    seconds on this site) before any of our code runs; REST doesn't.
     *    If REST is refused it falls back to admin-ajax.
     *  - Started early: on hover / focus / touch of the Analytics button, and
     *    for the first few postings once the page is idle — so it's usually
     *    ready by the time it's clicked.
     *  - Opens instantly: the drawer shows the title and headline numbers
     *    already on the card, plus a skeleton shaped like the final layout;
     *    reopening shows the last result at once and refreshes quietly.  */
    var ANALYTICS_FRESH_MS = 60000;   // younger than this: no refetch on open
    var WARM_COUNT = 3;               // postings pre-loaded while idle
    var analyticsCache = {};          // posting id -> { data, at, req }
    var analyticsOpenToken = 0;       // bumped per open, so only the latest open paints

    function requestAnalytics(id) {
        var entry = analyticsCache[id] || (analyticsCache[id] = {});
        if (entry.req) return entry.req;   // one request per posting at a time

        var dfd = $.Deferred();
        entry.req = dfd.promise();   // set before starting, so a response that completes at once still clears it
        function done(data) { entry.data = data; entry.at = Date.now(); entry.req = null; dfd.resolve(data); }
        function fail()     { entry.req = null; dfd.reject(); }
        function viaAjax() {
            $.post(cfg.ajaxUrl, { action: 'em_get_posting_analytics', posting_id: id, nonce: cfg.nonce })
                .done(function (res) { if (res && res.success) done(res.data); else fail(); })
                .fail(fail);
        }
        if (cfg.restRoot && cfg.restNonce) {
            $.ajax({ url: cfg.restRoot + 'postings/' + id + '/analytics', method: 'GET', dataType: 'json', cache: false, headers: { 'X-WP-Nonce': cfg.restNonce } })
                .done(function (data) { if (data && typeof data === 'object' && 'views' in data) done(data); else viaAjax(); })
                .fail(viaAjax);
        } else {
            viaAjax();
        }
        return dfd.promise();
    }

    function cardId(el) { return $(el).closest('.em-posting-card').data('posting-id'); }

    function prefetchAnalytics(id) {
        var e = analyticsCache[id];
        if (!id || (e && (e.req || (e.at && Date.now() - e.at < ANALYTICS_FRESH_MS)))) return;
        requestAnalytics(id);
    }
    $doc.on('mouseenter focusin touchstart', '.em-posting-analytics', function () { prefetchAnalytics(cardId(this)); });

    // Pre-load the first few postings, one at a time, once the page is idle.
    function warmAnalytics() {
        if (document.hidden || (navigator.connection && navigator.connection.saveData)) return;
        var ids = $('.em-posting-card').slice(0, WARM_COUNT).map(function () { return $(this).data('posting-id'); }).get();
        (function next() {
            var id = ids.shift();
            if (!id) return;
            prefetchAnalytics(id);
            var e = analyticsCache[id];
            if (e && e.req) e.req.always(function () { setTimeout(next, 250); }); else next();
        })();
    }
    (window.requestIdleCallback || function (fn) { return setTimeout(fn, 1500); })(warmAnalytics, { timeout: 5000 });

    function syncLabel(at, refreshing) {
        var text = '';
        if (refreshing) {
            text = at ? 'Updated ' + ago(at) + ' · refreshing…' : 'Loading…';
        } else if (at) {
            text = 'Updated ' + ago(at);
        }
        $('#em-pa-sync').text(text);
        $('#em-pa-loadbar').prop('hidden', !refreshing);
    }
    function ago(at) {
        var s = Math.max(0, Math.round((Date.now() - at) / 1000));
        if (s < 10) return 'just now';
        if (s < 60) return s + 's ago';
        return Math.round(s / 60) + ' min ago';
    }

    // Skeleton in the shape of the real drawer, with the headline numbers the
    // card already shows so only the detail below is left to arrive.
    function renderAnalyticsSkeleton($card) {
        var $v = $card.find('.em-posting-stat__v');
        var kpi = function (i, label) {
            var t = $.trim($v.eq(i).text());
            return '<div class="em-pa-kpi"><div class="em-pa-kpi__v">' + (t ? esc(t) : '<span class="em-skel em-skel--num"></span>') + '</div><div class="em-pa-kpi__l">' + label + '</div></div>';
        };
        var row = '<div class="em-skel-row"><span class="em-skel em-skel--line" style="width:38%;"></span><span class="em-skel em-skel--line" style="width:14%;"></span><span class="em-skel em-skel--line" style="width:14%;"></span></div>';
        $('#em-pa-body').removeClass('em-pa--swap em-pa--static').addClass('em-pa--loading').html(
            '<div class="em-drawer__section em-pa-skel"><div class="em-pa-kpis">' + kpi(0, 'Views') + kpi(1, 'Applied') + kpi(2, 'Conversion') + '</div></div>' +
            '<div class="em-drawer__section em-pa-skel"><div class="em-drawer__section-title">Job board postings</div>' + row + row + '</div>' +
            '<div class="em-drawer__section em-pa-skel"><div class="em-drawer__section-title">Applicants</div>' + row + row + row + '</div>' +
            '<div class="em-drawer__section em-pa-skel"><div class="em-drawer__section-title">Last 14 days</div><div class="em-skel em-skel--chart"></div></div>'
        );
    }

    function showAnalyticsError(id, $card) {
        syncLabel(0, false);
        $('#em-pa-body').removeClass('em-pa--loading').html(
            '<div class="em-pa-error"><p>Analytics couldn\'t be loaded.</p>' +
            '<button type="button" class="button button-primary em-pa-retry">Try again</button></div>'
        );
    }

    function openAnalytics($card) {
        var id = $card.data('posting-id');
        if (!id) return;
        currentAnalyticsPostingId = id;
        var token = ++analyticsOpenToken;
        var stale = function () { return token !== analyticsOpenToken || !$aDrawer.hasClass('is-open'); };   // closed, switched, or reopened meanwhile
        $('#em-pa-title').text($.trim($card.find('.em-posting-card__title').text()) || 'Analytics');
        openModal($aDrawer);

        var entry = analyticsCache[id] || {};
        var fresh = !!(entry.data && entry.at && Date.now() - entry.at < ANALYTICS_FRESH_MS);
        if (entry.data) {
            renderAnalytics(entry.data, { instant: true });
            syncLabel(entry.at, !fresh);
            if (fresh) return;
        } else {
            renderAnalyticsSkeleton($card);
            syncLabel(0, true);
        }

        var hadData = !!entry.data;
        var shown = entry.data;
        requestAnalytics(id)
            .done(function (data) {
                if (stale()) return;
                // Nothing changed since what's on screen: leave the DOM alone.
                if (hadData && JSON.stringify(shown) === JSON.stringify(data)) { syncLabel(analyticsCache[id].at, false); return; }
                var $body = $('#em-pa-body'), top = $body.scrollTop();
                renderAnalytics(data, hadData ? { refresh: true } : {});
                $body.scrollTop(top);
                syncLabel(analyticsCache[id].at, false);
            })
            .fail(function () {
                if (stale()) return;
                if (hadData) { $('#em-pa-sync').text('Couldn\'t refresh — showing the last result'); $('#em-pa-loadbar').prop('hidden', true); }
                else showAnalyticsError(id, $card);
            });
    }

    $doc.on('click', '.em-posting-analytics', function () { openAnalytics($(this).closest('.em-posting-card')); });
    $doc.on('click', '.em-pa-retry', function () {
        var $card = $('.em-posting-card[data-posting-id="' + currentAnalyticsPostingId + '"]');
        if ($card.length) openAnalytics($card);
    });

    // Keep the cached copy in step when applicant actions refresh the list.
    function cacheApplicants(applicants, postingId) {
        var e = analyticsCache[postingId || currentAnalyticsPostingId];
        if (e && e.data) e.data.applicants = applicants || [];
    }

    /* Show a fresh applicant list (KPIs + table), keeping any ticked rows ticked.
     * If another posting was opened while the request ran, only its cache is updated. */
    function applyApplicants(applicants, postingId) {
        applicants = applicants || [];
        cacheApplicants(applicants, postingId);
        if (postingId && postingId !== currentAnalyticsPostingId) return;
        var keep = {};
        $('#em-pa-applicants-section .em-bulk-row-check:checked').each(function () { keep[this.value] = true; });
        $('#em-pa-applicant-kpis').html(renderApplicantKpis(applicants, currentAnalyticsProcess));
        var $sec = $('#em-pa-applicants-section').html(renderApplicantsList(applicants, currentAnalyticsProcess));
        var $keep = $sec.find('.em-bulk-row-check').filter(function () { return keep[this.value]; }).prop('checked', true);
        if ($keep.length) $keep.first().trigger('change');
    }

    function renderAnalytics(d, opts) {
        opts = opts || {};
        $('#em-pa-title').text(d.title || 'Analytics');
        currentAnalyticsProcess = d.process || [];
        currentAnalyticsContractRole = d.contract_role || '';
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
            '<div class="em-drawer__section-title">Job board postings</div>' +
            renderBoardAnalytics(d.boards, 'job') + '</div>';
        var affiliateHtml = renderBoardAnalytics(d.boards, 'affiliate');
        if (affiliateHtml) {
            html += '<div class="em-drawer__section" style="--em-i:1;">' +
                '<div class="em-drawer__section-title">Affiliate listings</div>' + affiliateHtml + '</div>';
        }
        html += '<div class="em-drawer__section" style="--em-i:2;">' +
            '<div class="em-drawer__section-title">Applicants</div>' +
            '<div id="em-pa-applicant-kpis">' + renderApplicantKpis(d.applicants || [], currentAnalyticsProcess) + '</div>' +
            renderStageSummary(currentAnalyticsProcess) +
            '<div id="em-pa-applicants-section">' + renderApplicantsList(d.applicants || [], currentAnalyticsProcess) + '</div></div>';
        html += '<div class="em-drawer__section" style="--em-i:3;">' +
            '<div class="em-drawer__section-title">Last 14 days</div>' +
            '<div class="em-pa-legend"><span class="em-pa-legend__v">Views</span><span class="em-pa-legend__s">Applications</span></div>' +
            '<div class="em-pa-chart">' + (bars || '<p style="opacity:.6;"><em>No activity yet.</em></p>') + '</div></div>';
        html += '<div class="em-drawer__section" style="--em-i:4;">' +
            '<div class="em-drawer__section-title">Landing page</div>' +
            '<a class="button button-primary" href="' + esc(d.landing_url) + '" target="_blank" rel="noopener">Open landing page</a></div>';

        // Skeleton -> content: quick fade, no re-animating the headline numbers
        // (they were already on screen). Cached/refreshed content is placed
        // without any animation so it never flickers.
        $('#em-pa-body')
            .removeClass('em-pa--loading em-pa--swap em-pa--static')
            .addClass(opts.instant || opts.refresh ? 'em-pa--static' : 'em-pa--swap')
            .html(html);
    }

    /* Per-job-board breakdown: what we measured through each tracked link
     * (visits → applications → conversion, cost per application), what the
     * board itself reported, and how much arrived with no tracked source. */
    function money(cur, n) { return cur + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    /* One table per kind of entry: job board postings (with the "direct & other" line) and affiliate listings
     * (shown only once there is at least one). Each has its own total spend. */
    function renderBoardAnalytics(b, kind) {
        kind = kindOf(kind);
        var list = ((b && b.rows) || []).filter(function (r) { return kindOf(r.kind) === kind; });
        if (!list.length) {
            return kind === 'job'
                ? '<p class="em-pf-hint">No job board postings attached yet. Add them in this application\'s <strong>Postings</strong> tab to see where applicants come from.</p>'
                : '';
        }
        var cur = b.currency || '$';
        var rows = list.map(function (r) {
            var rep = r.reported || {};
            var reported = [];
            if (rep.views)   reported.push(Number(rep.views).toLocaleString() + ' views');
            if (rep.clicks)  reported.push(Number(rep.clicks).toLocaleString() + ' clicks');
            if (rep.applies) reported.push(Number(rep.applies).toLocaleString() + ' applies');
            var open = /^https?:\/\//i.test(r.url || '') ? ' <a href="' + esc(r.url) + '" target="_blank" rel="noopener" title="Open on ' + esc(r.board_label) + '">↗</a>' : '';
            return '<div class="em-pa-board" data-board-id="' + esc(r.id) + '">' +
                '<div class="em-pa-board__name"><strong>' + esc(r.name) + '</strong>' + open +
                    '<br><small>' + esc(r.board_label) + ' · <span class="em-pa-board__status em-pa-board__status--' + esc(r.status) + '">' + esc(boardStatuses[r.status] || r.status) + '</span>' +
                    (r.last ? ' · last visit ' + esc(r.last) + ' ago' : '') + '</small>' +
                    (r.program_label ? '<br><small class="em-pa-board__program">Program: ' + esc(r.program_label) + '</small>' : '') +
                    (reported.length ? '<br><small class="em-pa-board__reported">' + (kind === 'affiliate' ? 'Site' : 'Board') + ' reports: ' + esc(reported.join(' · ')) + '</small>' : '') +
                '</div>' +
                '<div class="em-pa-board__n" data-l="Views">' + Number(r.views).toLocaleString() + '</div>' +
                '<div class="em-pa-board__n" data-l="Applied">' + Number(r.subs).toLocaleString() + '</div>' +
                '<div class="em-pa-board__n" data-l="Conv.">' + r.conversion + '%</div>' +
                '<div class="em-pa-board__n" data-l="Cost / app">' + (r.cpa !== null && r.cpa !== undefined ? money(cur, r.cpa) : '—') + '</div>' +
            '</div>';
        }).join('');
        var d = b.direct || { views: 0, subs: 0, conversion: 0 };
        var direct = '<div class="em-pa-board em-pa-board--direct">' +
            '<div class="em-pa-board__name"><strong>Direct &amp; other</strong><br><small>Arrived without a tracked job board link</small></div>' +
            '<div class="em-pa-board__n" data-l="Views">' + Number(d.views).toLocaleString() + '</div>' +
            '<div class="em-pa-board__n" data-l="Applied">' + Number(d.subs).toLocaleString() + '</div>' +
            '<div class="em-pa-board__n" data-l="Conv.">' + d.conversion + '%</div>' +
            '<div class="em-pa-board__n" data-l="Cost / app">—</div></div>';
        // This kind's own spend (older payloads have no per-kind totals: those were job boards only).
        var tot = (b.by_kind && b.by_kind[kind]) || (kind === 'job' ? { cost: b.total_cost, cpa: b.total_cpa } : { cost: 0, cpa: null });
        var foot = tot.cost > 0
            ? '<p class="em-pf-hint" style="margin-top:8px;">Total spend ' + money(cur, tot.cost) + (tot.cpa !== null && tot.cpa !== undefined ? ' · ' + money(cur, tot.cpa) + ' per tracked application' : '') + '</p>'
            : '';
        return '<div class="em-pa-boards"><div class="em-pa-board em-pa-board--head"><div>' + (kind === 'affiliate' ? 'Listing' : 'Posting') + '</div><div>Views</div><div>Applied</div><div>Conv.</div><div>Cost / app</div></div>' +
            rows + (kind === 'job' ? direct : '') + '</div>' + foot;
    }

    $doc.on('click', '.em-pa-applicant__advance', function () {
        var $btn = $(this);
        var $row = $btn.closest('.em-pa-applicant');
        var submissionId = $row.data('submission-id');
        var stepIndex = $row.find('.em-pa-applicant__stepselect').val();
        if (!submissionId || stepIndex === null || typeof stepIndex === 'undefined' || stepIndex === '') return;
        var origText = $btn.text();
        var pid = currentAnalyticsPostingId;
        $btn.prop('disabled', true).text('Moving…');
        $.post(cfg.ajaxUrl, {
            action: 'em_advance_posting_applicant',
            posting_id: pid,
            submission_id: submissionId,
            step_index: stepIndex,
            nonce: cfg.nonce
        })
            .done(function (res) {
                if (res && res.success) {
                    applyApplicants(res.data.applicants, pid);
                } else {
                    alert((res && res.data && res.data.message) || 'Could not move applicant.');
                }
            })
            .fail(function () { alert('Network error.'); })
            .always(function () { $btn.prop('disabled', false).text(origText); });
    });

    /* Approve / mark-done an applicant's current step from the list. */
    $doc.on('click', '.em-pa-applicant__complete', function () {
        var $btn = $(this);
        var submissionId = $btn.closest('.em-pa-applicant').data('submission-id');
        if (!submissionId) return;
        var origText = $btn.text();
        var pid = currentAnalyticsPostingId;
        $btn.prop('disabled', true).text('Working…');
        $.post(cfg.ajaxUrl, {
            action: 'em_complete_posting_step',
            posting_id: pid,
            submission_id: submissionId,
            nonce: cfg.nonce
        })
            .done(function (res) {
                if (res && res.success) {
                    applyApplicants(res.data.applicants, pid);
                } else {
                    alert((res && res.data && res.data.message) || 'Could not complete this step.');
                    $btn.prop('disabled', false).text(origText);
                }
            })
            .fail(function () { alert('Network error.'); $btn.prop('disabled', false).text(origText); });
    });

    /* Re-run the auto-detected user-action checks right now. */
    $doc.on('click', '.em-pa-check', function () {
        var $btn = $(this);
        var origText = $btn.text();
        var pid = currentAnalyticsPostingId;
        $btn.prop('disabled', true).text('Checking…');
        $.post(cfg.ajaxUrl, { action: 'em_check_posting_actions', posting_id: pid, nonce: cfg.nonce })
            .done(function (res) {
                if (res && res.success) {
                    applyApplicants(res.data.applicants, pid);
                    var n = res.data.moved || 0;
                    $btn.text(n ? (n + ' moved on ✓') : 'Nothing new');
                    setTimeout(function () { $btn.prop('disabled', false).text(origText); }, 2200);
                } else {
                    alert((res && res.data && res.data.message) || 'Could not check progress.');
                    $btn.prop('disabled', false).text(origText);
                }
            })
            .fail(function () { alert('Network error.'); $btn.prop('disabled', false).text(origText); });
    });

    /* Move every ticked applicant to one step (the bar above the table). */
    $doc.on('click', '.em-pa-bulk-apply', function () {
        var $btn = $(this);
        var $scope = $btn.closest('.em-bulk-scope');
        var stepIndex = $scope.find('.em-pa-bulk-step').val();
        var ids = $scope.find('.em-bulk-row-check:checked').map(function () { return this.value; }).get();
        if (!ids.length) { alert('Tick at least one applicant first.'); return; }
        if (stepIndex === '' || stepIndex === null) { alert('Choose the step to move them to.'); return; }
        var step = currentAnalyticsProcess[parseInt(stepIndex, 10)] || {};
        var stepName = step.title || ('Step ' + (parseInt(stepIndex, 10) + 1));
        var ask = 'Move ' + ids.length + ' applicant' + (ids.length === 1 ? '' : 's') + ' to "' + stepName + '"?' +
            (step.final ? '\n\nThis approves them: the contract, role and dashboard access are applied and the approval emails are sent.' : '');
        if (!window.confirm(ask)) return;

        var origText = $btn.text();
        var pid = currentAnalyticsPostingId;
        $btn.prop('disabled', true).text('Moving…');
        $.post(cfg.ajaxUrl, {
            action: 'em_bulk_advance_posting_applicants',
            posting_id: pid,
            step_index: stepIndex,
            submission_ids: ids,
            nonce: cfg.nonce
        })
            .done(function (res) {
                if (res && res.success) {
                    $scope.find('.em-bulk-row-check').prop('checked', false);   // the selection was acted on: start clean
                    applyApplicants(res.data.applicants, pid);
                    if (pid !== currentAnalyticsPostingId) return;
                    var moved = res.data.moved || 0, skipped = res.data.skipped || 0;
                    var note = moved + ' moved to ' + stepName + (skipped ? ' · ' + skipped + ' already there or skipped' : '');
                    var $note = $('<div class="em-notice em-pa-notice" role="status"></div>').text(note);
                    $('#em-pa-applicants-section .em-pa-notice').remove();
                    $('#em-pa-applicants-section').prepend($note);
                    setTimeout(function () { $note.fadeOut(300, function () { $note.remove(); }); }, 5000);
                } else {
                    alert((res && res.data && res.data.message) || 'Could not move the selected applicants.');
                    $btn.prop('disabled', false).text(origText);
                }
            })
            .fail(function () { alert('Network error.'); $btn.prop('disabled', false).text(origText); });
    });

    function approverLabelJs(s) {
        if (s.approver_type === 'member') return s.approver_user_name || 'Admins only';
        var name = s.approver_role || 'administrator';
        knownRoles.forEach(function (r) { if (r.slug === s.approver_role) name = r.name; });
        return 'Role: ' + name;
    }

    /* Compact view of how each step after step 1 gets completed. */
    function renderStageSummary(steps) {
        var chips = '';
        var hasAuto = false;
        (steps || []).forEach(function (s, i) {
            if (i < 1) return;
            var how;
            if (s.final) {
                how = 'Runs automatically on approval';
            } else if (s.completion === 'action') {
                var n = s.action_mode === 'update' ? (s.action_updates || []).length : 0;
                how = 'User action' + (s.action_label ? ': ' + s.action_label : '') + (n ? ' (' + n + ' tracked)' : '');
                if (n) hasAuto = true;
            } else {
                how = 'Approval · ' + approverLabelJs(s);
            }
            chips += '<span class="em-pa-stagechip"><b>' + (i + 1) + '</b> ' + esc(s.title || ('Step ' + (i + 1))) + ' — ' + esc(how) + '</span>';
        });
        if (!chips) return '';
        return '<div class="em-pa-stages">' + chips +
            (hasAuto ? '<button type="button" class="button button-small em-pa-check">Check progress</button>' : '') +
            '</div>';
    }

    /* Status line for one applicant: what they're waiting on + the action to unblock them. */
    function applicantStatusHtml(a) {
        var next = a.next_title ? ' → ' + esc(a.next_title) : '';
        var btn = function (label) {
            return '<button type="button" class="button button-small button-primary em-pa-applicant__complete">' + label + '</button>';
        };
        if (a.state === 'completed') {
            return '<span class="em-pa-status em-pa-status--done">✓ Process completed</span>';
        }
        if (a.waiting_on === 'approval') {
            return '<span class="em-pa-status em-pa-status--wait">⏳ Awaiting approval · ' + esc(a.approver_label) + '</span>' + btn('Approve' + next);
        }
        if (a.waiting_on === 'action') {
            var link = a.action_url ? ' <a href="' + esc(a.action_url) + '" target="_blank" rel="noopener">open link</a>' : '';
            var items = '';
            if (a.action_auto) {
                var doneCount = (a.action_items || []).filter(function (i) { return i.done; }).length;
                items = '<span class="em-pa-progress">' + doneCount + '/' + a.action_items.length + ' done: ' +
                    a.action_items.map(function (i) {
                        return '<span class="' + (i.done ? 'em-pa-progress__yes' : 'em-pa-progress__no') + '">' + (i.done ? '✓ ' : '○ ') + esc(i.label) + '</span>';
                    }).join('') + '</span>';
            }
            return '<span class="em-pa-status em-pa-status--wait">⏳ Awaiting user action' + (a.action_label ? ': ' + esc(a.action_label) : '') + link + '</span>' + items + btn('Mark done' + next);
        }
        return '<span class="em-pa-status">In progress</span>' + btn('Complete step' + next);
    }

    function roleNameJs(slug) {
        var name = slug;
        knownRoles.forEach(function (r) { if (r.slug === slug) name = r.name; });
        return name;
    }

    /* KPI strip, like the Applicants tab: total, then how many are on each step. */
    function renderApplicantKpis(applicants, steps) {
        steps = steps || [];
        var counts = {};
        applicants.forEach(function (a) { counts[a.step_index] = (counts[a.step_index] || 0) + 1; });
        var form = applicants.length && applicants[0].form && applicants[0].form !== '—' ? applicants[0].form : '';
        var html = '<div class="em-kpi-grid em-pa-kpi-grid"><div class="em-kpi"><div class="em-kpi__label">Total Applicants</div>' +
            '<div class="em-kpi__value">' + applicants.length.toLocaleString() + '</div>' +
            '<div class="em-kpi__hint">' + (form ? esc(form) : 'this posting') + '</div></div>';
        steps.forEach(function (st, i) {
            var role = st.final ? currentAnalyticsContractRole : st.role;
            html += '<div class="em-kpi"><div class="em-kpi__label">' + esc(st.title || ('Step ' + (i + 1))) + '</div>' +
                '<div class="em-kpi__value">' + (counts[i] || 0).toLocaleString() + '</div>' +
                '<div class="em-kpi__hint">' + (role ? 'grants ' + esc(roleNameJs(role)) + ' role' : 'no role assigned') + '</div></div>';
        });
        return html + '</div>';
    }

    function renderApplicantsList(applicants, steps) {
        if (!applicants.length) {
            return '<div class="em-empty"><div class="em-empty__icon"><span class="dashicons dashicons-id-alt"></span></div>' +
                '<div class="em-empty__title">No applicants yet</div>' +
                '<div>People who apply through this posting\'s landing page will show up here.</div></div>';
        }
        steps = steps || [];
        var stepOpts = steps.map(function (st, i) {
            return '<option value="' + i + '">' + esc(st.title || ('Step ' + (i + 1))) + '</option>';
        }).join('');

        var rows = applicants.map(function (a) {
            var msgBtn = a.message_url
                ? '<a class="button button-small em-pa-applicant__msg" href="' + esc(a.message_url) + '" target="_blank" rel="noopener">Message</a>'
                : '<button type="button" class="button button-small em-pa-applicant__msg" disabled title="No linked member account yet">Message</button>';

            var moveControls = '&mdash;';
            if (steps.length) {
                var opts = steps.map(function (st, i) {
                    return '<option value="' + i + '"' + (i === a.step_index ? ' selected' : '') + '>' + esc(st.title || ('Step ' + (i + 1))) + '</option>';
                }).join('');
                moveControls =
                    '<div class="em-pa-applicant__move">' +
                    '<select class="em-pa-applicant__stepselect">' + opts + '</select>' +
                    '<button type="button" class="button button-small em-pa-applicant__advance">Apply</button></div>';
            }

            return '<tr class="em-pa-applicant" data-submission-id="' + esc(a.id) + '">' +
                '<td><input type="checkbox" class="em-bulk-row-check" value="' + esc(a.id) + '" aria-label="Select ' + esc(a.name) + '" /></td>' +
                '<td class="em-pa-applicant__who"><strong>' + esc(a.name) + '</strong>' +
                    (a.email ? '<br><small>' + esc(a.email) + '</small>' : '') +
                    (a.source ? '<br><small class="em-pa-applicant__src">via ' + esc(a.source) + '</small>' : '') +
                '</td>' +
                '<td>' + esc(a.form || '—') + '</td>' +
                '<td class="em-pa-applicant__date">' + esc(a.submitted || '') + '</td>' +
                '<td class="em-pa-applicant__stagecell"><span class="em-pill em-pill--info em-pa-applicant__stage">' + esc(a.stage) + '</span>' +
                    '<div class="em-pa-applicant__status">' + applicantStatusHtml(a) + '</div></td>' +
                '<td>' + moveControls + '</td>' +
                '<td class="em-pa-applicant__detail">' +
                    '<button type="button" class="button button-small em-view-applicant" data-submission-id="' + esc(a.id) + '" data-name="' + esc(a.name) + '" title="View the full application" aria-label="View application: ' + esc(a.name) + '"><span class="dashicons dashicons-visibility"></span></button> ' +
                    msgBtn +
                '</td>' +
            '</tr>';
        }).join('');

        return '<div class="em-bulk-scope em-pa-applicants">' +
            '<div class="em-bulk-bar"><span class="em-bulk-bar__count"><strong>0</strong> selected</span>' +
                '<select class="em-pa-bulk-step" aria-label="Move the selected applicants to a step"><option value="">— Move to step —</option>' + stepOpts + '</select>' +
                '<button type="button" class="button button-primary em-pa-bulk-apply">Apply to Selected</button></div>' +
            '<div class="gdc-table-wrap"><table class="widefat striped em-pa-table"><thead><tr>' +
                '<th style="width:32px;"><input type="checkbox" class="em-bulk-select-all" aria-label="Select all applicants" /></th>' +
                '<th>Applicant</th><th>Form</th><th>Submitted</th><th>Stage</th><th>Move To</th><th>Detail</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
    }

}(jQuery));
