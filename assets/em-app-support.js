/* Email Manager — Applications & Support shared behaviors
 *
 * Provides:
 *   - Re-trigger entrance animations when a subtab/tab becomes visible
 *   - Detail drawer (open/close, populate via admin-ajax)
 *   - Bulk action handling (select-all, count, submit)
 *   - Inline ticket reply form (admin-ajax)
 *   - ESC + backdrop close
 *
 * Depends on global EM_AS_CONFIG (localized).
 */
(function ($) {
    'use strict';

    var $document = $(document);
    var cfg = window.EM_AS_CONFIG || {};

    /* ---------- Re-trigger animations on tab/subtab switch ---------- */
    function retriggerAnimations($scope) {
        var $animated = $scope.find('.em-reveal, .em-row, .em-kpi, .em-stage-chip, .gdc-subtab, .em-drawer__section');
        $animated.each(function () {
            var el = this;
            var animation = window.getComputedStyle(el).animationName;
            if (!animation || animation === 'none') return;
            // Reset by removing/forcing reflow then re-adding the class.
            el.style.animation = 'none';
            // Force reflow.
            void el.offsetWidth;
            el.style.animation = '';
        });
    }

    // Use event delegation since the existing admin-page JS binds globally.
    $document.on('click', '.gdc-sub-tab', function () {
        var tab = $(this).data('tab');
        if (!tab) return;
        var $panel = $('.gdc-sub-tabpanel[data-panel="' + tab + '"]');
        if ($panel.length) {
            // Slight delay so the panel is visible first.
            setTimeout(function () { retriggerAnimations($panel); }, 30);
        }
    });

    $document.on('click', '.gdc-subtab', function () {
        var subtab = $(this).data('subtab');
        var $parent = $(this).closest('.gdc-sub-tabpanel');
        if (!subtab || !$parent.length) return;
        var $panel = $parent.find('.gdc-subtab-panel[data-subpanel="' + subtab + '"]');
        if ($panel.length) {
            setTimeout(function () { retriggerAnimations($panel); }, 30);
        }
    });

    /* ---------- Detail drawer ---------- */
    var $drawer = $('#em-detail-drawer');

    function openDrawer(title) {
        $('#em-drawer-title').text(title || 'Details');
        $('#em-drawer-body').html(
            '<div class="em-shimmer" style="width:80%;"></div>' +
            '<div class="em-shimmer" style="width:60%;"></div>' +
            '<div class="em-shimmer" style="width:90%;"></div>' +
            '<div class="em-shimmer" style="width:50%;"></div>'
        );
        $drawer.addClass('is-open');
        $('body').addClass('em-drawer-locked');
    }

    function closeDrawer() {
        $drawer.removeClass('is-open');
        $('body').removeClass('em-drawer-locked');
        $('#em-drawer-body').empty();
    }

    $document.on('click', '#em-drawer-close, .em-drawer__backdrop', closeDrawer);
    $document.on('keydown', function (e) {
        if (e.key === 'Escape' && $drawer.hasClass('is-open')) closeDrawer();
    });

    /* ---------- Renderers ---------- */
    function escapeHtml(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderAnswers(answers) {
        if (!answers || typeof answers !== 'object') {
            return '<p><em>No data recorded for this submission.</em></p>';
        }
        var html = '';
        var i = 0;
        Object.keys(answers).forEach(function (key) {
            var v = answers[key];
            var q = '';
            var a = '';
            if (v && typeof v === 'object') {
                q = v.question || key;
                a = v.answer || '';
            } else {
                q = isNaN(parseInt(key, 10)) ? key.replace(/_/g, ' ') : 'Question ' + (parseInt(key, 10) + 1);
                a = v;
            }
            html += '<div class="em-answer-card" style="--em-i:' + i + ';">' +
                '<div class="em-answer-card__q">' + escapeHtml(q) + '</div>' +
                '<div class="em-answer-card__a">' + (a ? escapeHtml(a) : '<em>No answer</em>') + '</div>' +
                '</div>';
            i++;
        });
        return html || '<p><em>No data recorded for this submission.</em></p>';
    }

    function renderApplicantDetail(d) {
        var stagesHtml = '';
        if (d.stages && d.stages.length) {
            d.stages.forEach(function (s, i) {
                stagesHtml += '<div class="em-stage-chip" style="--em-i:' + i + ';">' +
                    escapeHtml(s.label) +
                    (s.role ? '<span class="em-stage-chip__role">+' + escapeHtml(s.role) + '</span>' : '') +
                    (i < d.stages.length - 1 ? '<span class="em-stage-chip__arrow">→</span>' : '') +
                    '</div>';
            });
        }

        var html = '';
        html += '<div class="em-drawer__section" style="--em-i:0;">' +
            '<div class="em-drawer__section-title">Status</div>' +
            '<p style="margin:0;color:var(--em-text-primary);">' +
            '<span class="em-pill em-pill--info">' + escapeHtml(d.stage || 'Submitted') + '</span>' +
            (d.email ? ' &middot; <strong>' + escapeHtml(d.email) + '</strong>' : '') +
            (d.user_id ? ' &middot; user #' + parseInt(d.user_id, 10) : '') +
            '</p>' +
            '</div>';

        if (stagesHtml) {
            html += '<div class="em-drawer__section" style="--em-i:1;">' +
                '<div class="em-drawer__section-title">Process</div>' +
                '<div class="em-stage-flow">' + stagesHtml + '</div>' +
                '</div>';
        }

        html += '<div class="em-drawer__section" style="--em-i:2;">' +
            '<div class="em-drawer__section-title">Application Answers</div>' +
            renderAnswers(d.answers) +
            '</div>';

        $('#em-drawer-body').html(html);
    }

    function renderTicketDetail(d) {
        var thread = '';
        if (d.replies && d.replies.length) {
            d.replies.forEach(function (r) {
                thread += '<div class="em-msg' + (r.internal ? ' em-msg--internal' : '') + '">' +
                    '<div class="em-msg__head">' +
                    '<span class="em-msg__author">' + escapeHtml(r.author || 'Staff') + (r.internal ? ' · internal note' : '') + '</span>' +
                    '<span class="em-msg__time">' + escapeHtml(r.date || '') + '</span>' +
                    '</div>' +
                    '<div class="em-msg__body">' + escapeHtml(r.content) + '</div>' +
                    '</div>';
            });
        } else {
            thread = '<p style="color:var(--em-text-secondary);opacity:0.7;margin:0;"><em>No replies yet.</em></p>';
        }

        var html = '';
        html += '<div class="em-drawer__section" style="--em-i:0;">' +
            '<div class="em-drawer__section-title">Ticket</div>' +
            '<p style="margin:0;color:var(--em-text-primary);">' +
            '<span class="em-pill em-pill--info">' + escapeHtml(d.status_label || d.status || 'Open') + '</span>' +
            (d.priority_label ? ' &middot; <strong>' + escapeHtml(d.priority_label) + '</strong>' : '') +
            (d.email ? '<br><span style="opacity:0.75;">From: ' + escapeHtml(d.email) + '</span>' : '') +
            '</p>' +
            '</div>';

        html += '<div class="em-drawer__section" style="--em-i:1;">' +
            '<div class="em-drawer__section-title">Original Submission</div>' +
            renderAnswers(d.answers) +
            '</div>';

        html += '<div class="em-drawer__section" style="--em-i:2;">' +
            '<div class="em-drawer__section-title">Conversation</div>' +
            '<div class="em-thread">' + thread + '</div>' +
            '<form class="em-reply-form" id="em-reply-form" data-ticket-id="' + parseInt(d.id, 10) + '">' +
            '<textarea name="reply" placeholder="Type a reply…" required></textarea>' +
            '<div class="em-reply-form__actions">' +
            '<label><input type="checkbox" name="internal" /> Internal note (not emailed)</label>' +
            '<button type="submit" class="button button-primary">Send Reply</button>' +
            '</div>' +
            '</form>' +
            '</div>';

        $('#em-drawer-body').html(html);
    }

    /* ---------- Detail fetchers ---------- */
    $document.on('click', '.em-view-applicant', function (e) {
        e.preventDefault();
        var id = parseInt($(this).data('submission-id'), 10);
        var name = $(this).data('name') || 'Applicant';
        if (!id) return;
        openDrawer('Applicant — ' + name);
        $.post(cfg.ajaxUrl, {
            action: 'em_get_applicant_detail',
            id: id,
            nonce: cfg.nonce
        }).done(function (res) {
            if (res && res.success) renderApplicantDetail(res.data);
            else $('#em-drawer-body').html('<p style="color:#fca5a5;">Failed to load applicant.</p>');
        }).fail(function () {
            $('#em-drawer-body').html('<p style="color:#fca5a5;">Network error.</p>');
        });
    });

    $document.on('click', '.em-view-ticket', function (e) {
        e.preventDefault();
        var id = parseInt($(this).data('submission-id'), 10);
        var name = $(this).data('name') || 'Ticket';
        if (!id) return;
        openDrawer('Ticket — ' + name);
        $.post(cfg.ajaxUrl, {
            action: 'em_get_ticket_detail',
            id: id,
            nonce: cfg.nonce
        }).done(function (res) {
            if (res && res.success) renderTicketDetail(res.data);
            else $('#em-drawer-body').html('<p style="color:#fca5a5;">Failed to load ticket.</p>');
        }).fail(function () {
            $('#em-drawer-body').html('<p style="color:#fca5a5;">Network error.</p>');
        });
    });

    /* ---------- Chat transcript ---------- */
    function renderChatDetail(d) {
        var meta = '';
        meta += '<div class="em-chat-meta">';
        if (d.form_title) meta += '<span class="em-pill em-pill--info">' + escapeHtml(d.form_title) + '</span>';
        if (d.email)      meta += '<strong>' + escapeHtml(d.email) + '</strong>';
        if (d.date)       meta += '<span style="opacity:0.7;">' + escapeHtml(d.date) + '</span>';
        meta += '</div>';

        var bubbles = '';
        if (d.turns && d.turns.length) {
            d.turns.forEach(function (t, i) {
                if (!t.text || !String(t.text).trim()) return;
                var cls = t.role === 'user' ? 'em-bubble--user' : 'em-bubble--bot';
                var label = t.role === 'user' ? 'Member' : 'Flow';
                bubbles += '<div class="em-bubble ' + cls + '" style="--em-i:' + i + ';">' +
                    '<div class="em-bubble__role">' + label + '</div>' +
                    escapeHtml(t.text) +
                    '</div>';
            });
        } else {
            bubbles = '<p style="color:var(--em-text-secondary);opacity:0.7;"><em>No conversation captured.</em></p>';
        }

        var html = '<div class="em-drawer__section" style="--em-i:0;">' + meta +
            '<div class="em-chat-transcript">' + bubbles + '</div>' +
            '</div>';
        $('#em-drawer-body').html(html);
    }

    $document.on('click', '.em-view-chat', function (e) {
        e.preventDefault();
        var id = parseInt($(this).data('submission-id'), 10);
        var name = $(this).data('name') || 'Chat';
        if (!id) return;
        openDrawer('Chat — ' + name);
        $.post(cfg.ajaxUrl, {
            action: 'em_get_chat_detail',
            id: id,
            nonce: cfg.nonce
        }).done(function (res) {
            if (res && res.success) renderChatDetail(res.data);
            else $('#em-drawer-body').html('<p style="color:#fca5a5;">Failed to load chat.</p>');
        }).fail(function () {
            $('#em-drawer-body').html('<p style="color:#fca5a5;">Network error.</p>');
        });
    });

    /* ---------- Reply submission ---------- */
    $document.on('submit', '#em-reply-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var ticketId = parseInt($form.data('ticket-id'), 10);
        var content = $form.find('textarea[name="reply"]').val();
        var internal = $form.find('input[name="internal"]').is(':checked') ? 1 : 0;
        if (!content || !content.trim()) return;
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Sending…');
        $.post(cfg.ajaxUrl, {
            action: 'em_add_ticket_reply',
            id: ticketId,
            content: content,
            internal: internal,
            nonce: cfg.nonce
        }).done(function (res) {
            if (res && res.success) {
                renderTicketDetail(res.data);
            } else {
                $btn.prop('disabled', false).text('Send Reply');
                alert((res && res.data && res.data.message) || 'Failed to send reply.');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Send Reply');
            alert('Network error.');
        });
    });

    /* ---------- Bulk select ---------- */
    $document.on('change', '.em-bulk-select-all', function () {
        var $scope = $(this).closest('.em-bulk-scope');
        var checked = $(this).is(':checked');
        $scope.find('.em-bulk-row-check').prop('checked', checked);
        updateBulkCount($scope);
    });

    $document.on('change', '.em-bulk-row-check', function () {
        updateBulkCount($(this).closest('.em-bulk-scope'));
    });

    function updateBulkCount($scope) {
        var n = $scope.find('.em-bulk-row-check:checked').length;
        $scope.find('.em-bulk-bar__count strong').text(n);
        $scope.find('.em-bulk-bar').toggleClass('is-active', n > 0);
    }

    /* ---------- Drawer-locked body styling (prevent scroll bleed) ---------- */
    var styleLock = document.createElement('style');
    styleLock.textContent = 'body.em-drawer-locked { overflow: hidden; }';
    document.head.appendChild(styleLock);

    /* ---------- First-paint animation kick ---------- */
    $(function () {
        // Make the active panel re-trigger so anything cached from earlier renders.
        var $active = $('.gdc-sub-tabpanel:visible').first();
        if ($active.length) retriggerAnimations($active);
    });

}(jQuery));
