/**
 * Talk Flows › Applications › Applicants (email-manager inc/postings-ats.php).
 * List + pipeline views over every posting's applicants, status filters, search, sort, bulk actions, CSV export and
 * an applicant drawer. Moves/approvals call the postings' own endpoints; the rest goes to em_ats_action.
 */
(function () {
    'use strict';
    var root = document.getElementById('em-ats');
    if (!root) return;
    var cfg = {};
    try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) { cfg = {}; }
    var body = root.querySelector('[data-ats-body]');

    var S = {
        postings: [], apps: [], byPosting: {},
        view: 'list', status: 'active', posting: 'all', q: '', sort: 'new', page: 1, per: 25,
        sel: {}, open: 0, boardPosting: 0
    };
    try { var saved = JSON.parse(localStorage.getItem('emAtsPrefs') || '{}'); ['view', 'status', 'posting', 'sort', 'per'].forEach(function (k) { if (saved[k]) S[k] = saved[k]; }); } catch (e) {}
    function savePrefs() { try { localStorage.setItem('emAtsPrefs', JSON.stringify({ view: S.view, status: S.status, posting: S.posting, sort: S.sort, per: S.per })); } catch (e) {} }

    /* ---------- helpers ---------- */
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function post(data) {
        var fd = new FormData();
        fd.append('nonce', cfg.nonce);
        if (cfg.group) { fd.append('em_group', cfg.group); fd.append('gnonce', cfg.gnonce); } // group Careers tab (2026-11-14)
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v);
        });
        return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .catch(function () { return { success: false, data: { message: 'Network error. Please try again.' } }; });
    }
    function ago(ts) {
        if (!ts) return '';
        var s = Math.max(0, Date.now() / 1000 - ts), u = [[31536000, 'y'], [2592000, 'mo'], [604800, 'w'], [86400, 'd'], [3600, 'h'], [60, 'm']];
        for (var i = 0; i < u.length; i++) if (s >= u[i][0]) return Math.floor(s / u[i][0]) + u[i][1] + ' ago';
        return 'just now';
    }
    function dt(ts) { return ts ? new Date(ts * 1000).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''; }
    function initials(n) { return String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0].toUpperCase(); }).join('') || '?'; }
    function avatar(a, size) {
        return '<span class="em-ats-av" style="--s:' + (size || 38) + 'px">' + (a.avatar ? '<img src="' + esc(a.avatar) + '" alt="" loading="lazy">' : '') + '<i>' + esc(initials(a.name)) + '</i></span>';
    }
    function toast(msg, bad) {
        var t = document.createElement('div');
        t.className = 'em-ats-toast' + (bad ? ' is-bad' : '');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('is-in'); }, 10);
        setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 3200);
    }
    function postingOf(a) { return S.byPosting[a.posting_id] || { title: '', steps: [], accent: '#6366f1' }; }

    /* What state an applicant is in, for filters and the pill. */
    function status(a) {
        if (a.rejected) return 'rejected';
        if (a.state === 'completed') return 'approved';
        var p = postingOf(a), st = p.steps[a.step_index] || {};
        if (st.final) return 'approved';
        if (a.email_hold) return 'email';
        if (a.waiting_on === 'approval') return 'review';
        if (a.waiting_on === 'action') return 'applicant';
        return 'review';
    }
    var STATUS = {
        active: { label: 'All active', test: function (s) { return s !== 'rejected' && s !== 'approved'; } },
        review: { label: 'Needs your review', test: function (s) { return s === 'review'; }, tone: 'amber' },
        applicant: { label: 'Waiting on applicant', test: function (s) { return s === 'applicant'; }, tone: 'sky' },
        email: { label: 'Email not confirmed', test: function (s) { return s === 'email'; }, tone: 'violet' },
        approved: { label: 'Approved', test: function (s) { return s === 'approved'; }, tone: 'green' },
        rejected: { label: 'Not selected', test: function (s) { return s === 'rejected'; }, tone: 'red' },
        all: { label: 'Everyone', test: function () { return true; } }
    };
    var PILL = {
        review: ['amber', 'Needs your review'], applicant: ['sky', 'Waiting on applicant'], email: ['violet', 'Email not confirmed'],
        approved: ['green', 'Approved'], rejected: ['red', 'Not selected']
    };
    function pill(a) {
        var s = status(a), p = PILL[s];
        var sub = '';
        if (s === 'applicant' && a.action_label) sub = a.action_label;
        if (s === 'review' && a.approver_label) sub = a.approver_label;
        return '<span class="em-ats-pill is-' + p[0] + '">' + p[1] + '</span>' + (sub ? '<small class="em-ats-sub">' + esc(sub) + '</small>' : '');
    }

    /* The one action that moves an applicant on, by state. */
    function primary(a) {
        var s = status(a), p = postingOf(a), next = p.steps[a.step_index + 1];
        var nextT = next ? (next.final ? 'Approve' : next.title) : '';
        if (s === 'rejected') return { op: 'restore', label: 'Restore', tone: 'ghost' };
        if (s === 'approved') return null;
        if (s === 'email') return { op: 'resend_email', label: 'Resend email', tone: 'ghost' };
        if (s === 'review') return { op: 'complete', label: next && next.final ? 'Approve' : 'Approve → ' + nextT, tone: 'go' };
        if (s === 'applicant') return { op: 'complete', label: 'Mark done → ' + nextT, tone: 'ghost' };
        return null;
    }

    function stepper(a) {
        var p = postingOf(a), n = p.steps.length || 1, cur = a.step_index, done = a.state === 'completed';
        var dots = p.steps.map(function (st, i) {
            var c = done || i < cur ? 'is-done' : (i === cur ? 'is-here' : '');
            return '<i class="' + c + '" title="' + esc(st.title) + '"></i>';
        }).join('');
        var title = (p.steps[cur] || {}).title || a.stage;
        return '<div class="em-ats-steps"><span class="em-ats-dots">' + dots + '</span><small>' + (done ? 'Complete' : 'Step ' + (cur + 1) + ' of ' + n + ' · ' + esc(title)) + '</small></div>';
    }

    function stars(n, live) {
        var h = '';
        for (var i = 1; i <= 5; i++) h += '<button type="button" class="' + (i <= n ? 'is-on' : '') + '"' + (live ? ' data-rate="' + i + '"' : ' tabindex="-1"') + ' aria-label="' + i + ' star' + (i > 1 ? 's' : '') + '">★</button>';
        return '<span class="em-ats-stars' + (live ? ' is-live' : '') + '">' + h + '</span>';
    }

    /* ---------- filtering ---------- */
    function filtered(ignoreStatus) {
        var q = S.q.trim().toLowerCase();
        var rows = S.apps.filter(function (a) {
            if (S.posting !== 'all' && String(a.posting_id) !== String(S.posting)) return false;
            if (!ignoreStatus && !STATUS[S.status].test(status(a))) return false;
            if (q) {
                var hay = (a.name + ' ' + a.email + ' ' + postingOf(a).title + ' ' + (a.source || '') + ' ' + a.stage).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
        var by = {
            'new': function (x, y) { return (y.ts || 0) - (x.ts || 0); },
            old: function (x, y) { return (x.ts || 0) - (y.ts || 0); },
            name: function (x, y) { return String(x.name).localeCompare(String(y.name)); },
            rating: function (x, y) { return (y.rating || 0) - (x.rating || 0) || (y.ts || 0) - (x.ts || 0); },
            activity: function (x, y) { return (y.last_ts || 0) - (x.last_ts || 0); },
            stage: function (x, y) { return (x.posting_id - y.posting_id) || (y.step_index - x.step_index); }
        };
        return rows.sort(by[S.sort] || by['new']);
    }

    /* ---------- render ---------- */
    function render() {
        var base = filtered(true);
        var counts = {};
        Object.keys(STATUS).forEach(function (k) { counts[k] = base.filter(function (a) { return STATUS[k].test(status(a)); }).length; });
        var postingOpts = '<option value="all">All postings (' + S.apps.length + ')</option>' + S.postings.map(function (p) {
            var n = S.apps.filter(function (a) { return a.posting_id === p.id; }).length;
            return '<option value="' + p.id + '"' + (String(S.posting) === String(p.id) ? ' selected' : '') + '>' + esc(p.title) + ' (' + n + ')</option>';
        }).join('');
        var chips = Object.keys(STATUS).map(function (k) {
            return '<button type="button" class="em-ats-chip' + (S.status === k ? ' is-on' : '') + (STATUS[k].tone ? ' is-' + STATUS[k].tone : '') + '" data-status="' + k + '">' + esc(STATUS[k].label) + '<b>' + counts[k] + '</b></button>';
        }).join('');
        body.innerHTML =
            '<div class="em-ats-chips" role="tablist">' + chips + '</div>' +
            '<div class="em-ats-bar">' +
                '<label class="em-ats-search"><span class="dashicons dashicons-search"></span><input type="search" placeholder="Search name, email, posting or source" value="' + esc(S.q) + '" data-q></label>' +
                '<select data-posting aria-label="Posting">' + postingOpts + '</select>' +
                '<select data-sort aria-label="Sort">' +
                    [['new', 'Newest first'], ['old', 'Oldest first'], ['activity', 'Recent activity'], ['rating', 'Highest rated'], ['name', 'Name A–Z'], ['stage', 'Furthest along']].map(function (o) { return '<option value="' + o[0] + '"' + (S.sort === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
                '</select>' +
            '</div>' +
            '<div class="em-ats-bulk" data-bulk hidden></div>' +
            '<div data-view-body></div>';
        renderView();
    }

    function renderView() {
        var vb = body.querySelector('[data-view-body]');
        if (!vb) return;
        root.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-view') === S.view); });
        if (S.view === 'board') { vb.innerHTML = board(); } else { vb.innerHTML = list(); }
        renderBulk();
    }

    function list() {
        var rows = filtered(false);
        if (!S.apps.length) return empty('No applications yet', 'People who apply through any posting appear here, with everything you need to review and move them on.');
        if (!rows.length) return empty('Nobody here', 'No applicants match these filters. Try another status or clear the search.');
        var pages = Math.max(1, Math.ceil(rows.length / S.per));
        S.page = Math.min(S.page, pages);
        var slice = rows.slice((S.page - 1) * S.per, S.page * S.per);
        var allOn = slice.length && slice.every(function (a) { return S.sel[a.id]; });
        var tr = slice.map(function (a) {
            var p = postingOf(a), pr = primary(a);
            return '<tr class="em-ats-row' + (S.sel[a.id] ? ' is-sel' : '') + (a.rejected ? ' is-muted' : '') + '" data-id="' + a.id + '">' +
                '<td class="c-check"><input type="checkbox" data-sel="' + a.id + '"' + (S.sel[a.id] ? ' checked' : '') + ' aria-label="Select ' + esc(a.name) + '"></td>' +
                '<td class="c-who"><button type="button" class="em-ats-who" data-open="' + a.id + '">' + avatar(a) + '<span><b>' + esc(a.name) + '</b><small>' + esc(a.email || 'no email') +
                    (a.email_status === 'verified' ? ' <em class="em-ats-ok" title="Email confirmed">✓</em>' : (a.user_id ? ' <em class="em-ats-warn" title="Email not confirmed">!</em>' : '')) + '</small></span></button></td>' +
                '<td class="c-post"><span class="em-ats-post" style="--pc:' + esc(p.accent) + '">' + esc(p.title) + '</span>' + (a.source ? '<small class="em-ats-sub">via ' + esc(a.source) + '</small>' : '') + '</td>' +
                '<td class="c-stage">' + stepper(a) + '</td>' +
                '<td class="c-status">' + pill(a) + '</td>' +
                '<td class="c-rate">' + stars(a.rating, false) + (a.notes_n ? '<small class="em-ats-sub">' + a.notes_n + ' note' + (a.notes_n > 1 ? 's' : '') + '</small>' : '') + '</td>' +
                '<td class="c-date" title="' + esc(dt(a.ts)) + '">' + esc(ago(a.ts)) + '</td>' +
                '<td class="c-act">' + (pr ? '<button type="button" class="em-ats-btn is-' + pr.tone + ' is-sm" data-do="' + pr.op + '" data-id="' + a.id + '">' + esc(pr.label) + '</button>' : '<span class="em-ats-done">✓ Approved</span>') +
                    '<button type="button" class="em-ats-icon" data-open="' + a.id + '" aria-label="Open ' + esc(a.name) + '"><span class="dashicons dashicons-arrow-right-alt2"></span></button></td>' +
            '</tr>';
        }).join('');
        var pager = pages > 1 ? '<div class="em-ats-pager"><button type="button" class="em-ats-btn is-ghost is-sm" data-page="' + (S.page - 1) + '"' + (S.page === 1 ? ' disabled' : '') + '>‹ Previous</button><span>Page ' + S.page + ' of ' + pages + ' · ' + rows.length + ' applicants</span><button type="button" class="em-ats-btn is-ghost is-sm" data-page="' + (S.page + 1) + '"' + (S.page === pages ? ' disabled' : '') + '>Next ›</button></div>'
            : '<div class="em-ats-pager"><span>' + rows.length + ' applicant' + (rows.length === 1 ? '' : 's') + '</span></div>';
        return '<div class="em-ats-table"><table><thead><tr>' +
            '<th class="c-check"><input type="checkbox" data-selall' + (allOn ? ' checked' : '') + ' aria-label="Select all on this page"></th>' +
            '<th>Applicant</th><th>Posting</th><th>Progress</th><th>Status</th><th>Rating</th><th>Applied</th><th></th>' +
            '</tr></thead><tbody>' + tr + '</tbody></table></div>' + pager;
    }

    function board() {
        var pid = S.posting !== 'all' ? +S.posting : (S.boardPosting || (S.postings[0] && S.postings[0].id));
        S.boardPosting = pid;
        var p = S.byPosting[pid];
        if (!p) return empty('No postings yet', 'Create a posting to see its pipeline.');
        var q = S.q.trim().toLowerCase();
        var rows = S.apps.filter(function (a) { return a.posting_id === pid && (!q || (a.name + ' ' + a.email).toLowerCase().indexOf(q) !== -1); });
        var tabs = S.posting === 'all' ? '<div class="em-ats-boardtabs">' + S.postings.map(function (x) {
            return '<button type="button" class="' + (x.id === pid ? 'is-on' : '') + '" data-board="' + x.id + '" style="--pc:' + esc(x.accent) + '">' + esc(x.title) + '</button>';
        }).join('') + '</div>' : '';
        var cols = p.steps.map(function (st, i) {
            var inCol = rows.filter(function (a) { return !a.rejected && a.step_index === i; });
            return col(i, st.title, st.final ? 'Approved: role, contract and welcome email' : (i === 0 ? 'Submitted · email confirmation' : (st.completion === 'approval' ? 'Waiting on your team' : 'Waiting on the applicant')), inCol, st.final);
        }).join('') + col('rejected', 'Not selected', 'Drop here to reject', rows.filter(function (a) { return a.rejected; }), false);
        return tabs + '<p class="em-ats-hint">Drag a card to another column to move the applicant. Moving into <b>' + esc((p.steps[p.steps.length - 1] || {}).title || 'the last step') + '</b> approves them.</p><div class="em-ats-board" style="--cols:' + (p.steps.length + 1) + '">' + cols + '</div>';
    }
    function col(key, title, hint, rows, final) {
        var cards = rows.map(function (a) {
            return '<article class="em-ats-card is-' + status(a) + '" draggable="true" data-card="' + a.id + '" data-open="' + a.id + '">' +
                '<div class="em-ats-card__top">' + avatar(a, 32) + '<span><b>' + esc(a.name) + '</b><small>' + esc(ago(a.ts)) + '</small></span></div>' +
                '<div class="em-ats-card__meta">' + pill(a) + stars(a.rating, false) + '</div></article>';
        }).join('') || '<div class="em-ats-colempty">Nobody here</div>';
        return '<section class="em-ats-col' + (final ? ' is-final' : '') + (key === 'rejected' ? ' is-rej' : '') + '" data-col="' + key + '"><header><b>' + esc(title) + '</b><span>' + rows.length + '</span><small>' + esc(hint) + '</small></header><div class="em-ats-col__cards">' + cards + '</div></section>';
    }
    function empty(t, d) { return '<div class="em-ats-empty"><span class="dashicons dashicons-groups"></span><b>' + esc(t) + '</b><p>' + esc(d) + '</p></div>'; }

    function selected() { return S.apps.filter(function (a) { return S.sel[a.id]; }); }
    function renderBulk() {
        var bar = body.querySelector('[data-bulk]');
        if (!bar) return;
        var sel = selected();
        if (!sel.length || S.view !== 'list') { bar.hidden = true; bar.innerHTML = ''; return; }
        var pids = {};
        sel.forEach(function (a) { pids[a.posting_id] = 1; });
        var one = Object.keys(pids).length === 1 ? S.byPosting[Object.keys(pids)[0]] : null;
        var moveOpts = one ? one.steps.map(function (st, i) { return '<option value="' + i + '">' + esc(st.title) + (st.final ? ' (approve)' : '') + '</option>'; }).join('') : '';
        bar.hidden = false;
        bar.innerHTML = '<b>' + sel.length + ' selected</b>' +
            '<button type="button" class="em-ats-btn is-go is-sm" data-bulk-do="complete">Move each on one step</button>' +
            (one ? '<span class="em-ats-bulkmove"><select data-bulk-step><option value="">Move to step…</option>' + moveOpts + '</select><button type="button" class="em-ats-btn is-sm" data-bulk-do="move">Move</button></span>'
                : '<span class="em-ats-sub">Select one posting to move to a specific step</span>') +
            '<button type="button" class="em-ats-btn is-ghost is-sm" data-bulk-do="resend_email">Resend confirmation</button>' +
            '<button type="button" class="em-ats-btn is-danger is-sm" data-bulk-do="reject">Not selected…</button>' +
            '<button type="button" class="em-ats-btn is-ghost is-sm" data-bulk-do="restore">Restore</button>' +
            '<button type="button" class="em-ats-btn is-ghost is-sm" data-bulk-do="export">Export</button>' +
            '<button type="button" class="em-ats-link" data-bulk-do="clear">Clear</button>';
    }

    /* ---------- data ---------- */
    function load(keepOpen) {
        return post({ action: 'em_ats_list' }).then(function (j) {
            if (!j || !j.success) { body.innerHTML = empty('Could not load applicants', (j && j.data && j.data.message) || 'Please refresh the page.'); return; }
            take(j.data);
            render();
            if (keepOpen && S.open) openDrawer(S.open, true);
        });
    }
    function take(d) {
        if (d.postings) { S.postings = d.postings; S.byPosting = {}; d.postings.forEach(function (p) { S.byPosting[p.id] = p; }); }
        if (d.applicants) {
            S.apps = d.applicants;
            var ids = {};
            S.apps.forEach(function (a) { ids[a.id] = 1; });
            Object.keys(S.sel).forEach(function (k) { if (!ids[k]) delete S.sel[k]; });
        }
    }
    function find(id) { id = +id; for (var i = 0; i < S.apps.length; i++) if (+S.apps[i].id === id) return S.apps[i]; return null; }

    /* Approve / mark done / move: the postings' own endpoints (one posting per call). */
    function complete(a) {
        return post({ action: 'em_complete_posting_step', posting_id: a.posting_id, submission_id: a.id });
    }
    function move(a, step) {
        return post({ action: 'em_advance_posting_applicant', posting_id: a.posting_id, submission_id: a.id, step_index: step });
    }
    function act(op, ids, extra) {
        var d = { action: 'em_ats_action', op: op, ids: ids };
        Object.keys(extra || {}).forEach(function (k) { d[k] = extra[k]; });
        return post(d).then(function (j) {
            if (j && j.success) { take({ applicants: j.data.applicants }); render(); if (S.open) openDrawer(S.open, true); }
            return j;
        });
    }
    function runOne(op, a, btn) {
        if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Working…'; }
        var done = function (j, okMsg) {
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
            if (j && j.success) toast(okMsg); else toast((j && j.data && j.data.message) || 'That did not work.', true);
        };
        if (op === 'complete') {
            var p = postingOf(a), next = p.steps[a.step_index + 1];
            if (next && next.final && !confirm('Approve ' + a.name + ' for ' + p.title + '?\n\nTheir role, contract and dashboards switch on and the welcome email is sent.')) { if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; } return; }
            return complete(a).then(function (j) { done(j, next ? (next.final ? a.name + ' approved' : a.name + ' moved to ' + next.title) : 'Done'); if (j && j.success) load(true); });
        }
        if (op === 'restore') return act('restore', [a.id]).then(function (j) { done(j, a.name + ' restored'); });
        if (op === 'resend_email') return act('resend_email', [a.id]).then(function (j) { done(j && j.success && j.data.done ? j : { success: false, data: { message: 'Already sent in the last minute, or the email is confirmed.' } }, 'Confirmation email sent to ' + a.email); });
        if (op === 'confirm_email') {
            if (!confirm('Mark ' + a.email + ' as confirmed? Use this only when you know the address is theirs. Their application moves on straight away.')) { if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; } return; }
            return act('confirm_email', [a.id]).then(function (j) { done(j, 'Email confirmed'); });
        }
        if (op === 'reject') { if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; } return rejectDialog([a]); }
    }

    /* ---------- reject dialog ---------- */
    function rejectDialog(list) {
        var m = document.createElement('div');
        m.className = 'em-ats-modal';
        var who = list.length === 1 ? esc(list[0].name) : list.length + ' applicants';
        m.innerHTML = '<div class="em-ats-modal__box" role="dialog" aria-modal="true"><button type="button" class="em-ats-x" data-x>&times;</button>' +
            '<h3>Mark ' + who + ' as not selected</h3><p class="em-ats-sub">They leave the active list. You can restore them at any time.</p>' +
            '<label>Private note for your team (optional)<textarea rows="2" data-reason placeholder="Why, for whoever reviews this later"></textarea></label>' +
            '<label class="em-ats-check"><input type="checkbox" data-notify checked> Email the applicant</label>' +
            '<div data-mail><label>Subject<input type="text" data-subject value="' + esc(cfg.reject.subject) + '"></label>' +
            '<label>Message <small>{applicant_name}, {posting_title} and {site_name} are filled in for each person</small><textarea rows="8" data-body>' + esc(cfg.reject.body) + '</textarea></label></div>' +
            '<div class="em-ats-modal__foot"><button type="button" class="em-ats-btn is-ghost" data-x>Cancel</button><button type="button" class="em-ats-btn is-danger" data-go>Mark as not selected</button></div></div>';
        document.body.appendChild(m);
        var notify = m.querySelector('[data-notify]');
        notify.addEventListener('change', function () { m.querySelector('[data-mail]').hidden = !notify.checked; });
        m.addEventListener('click', function (e) {
            if (e.target === m || e.target.closest('[data-x]')) { m.remove(); return; }
            var go = e.target.closest('[data-go]');
            if (!go) return;
            go.disabled = true; go.textContent = 'Saving…';
            act('reject', list.map(function (a) { return a.id; }), {
                reason: m.querySelector('[data-reason]').value, notify: notify.checked ? 1 : '',
                subject: m.querySelector('[data-subject]').value, body: m.querySelector('[data-body]').value
            }).then(function (j) {
                if (j && j.success) { toast(j.data.done + ' marked as not selected' + (notify.checked ? ' and emailed' : '')); S.sel = {}; render(); m.remove(); }
                else { toast((j && j.data && j.data.message) || 'That did not work.', true); go.disabled = false; go.textContent = 'Mark as not selected'; }
            });
        });
    }

    /* ---------- drawer ---------- */
    var drawer = document.createElement('div');
    drawer.className = 'em-ats-drawer';
    drawer.hidden = true;
    drawer.innerHTML = '<div class="em-ats-drawer__back" data-dx></div><aside class="em-ats-drawer__panel" role="dialog" aria-modal="true" aria-label="Applicant"><div data-dbody></div></aside>';
    document.body.appendChild(drawer);
    var dbody = drawer.querySelector('[data-dbody]');

    function openDrawer(id, quiet) {
        var a = find(id);
        if (!a) { closeDrawer(); return; }
        S.open = a.id;
        drawer.hidden = false;
        document.documentElement.classList.add('em-ats-lock');
        if (!quiet) dbody.innerHTML = drawerHead(a) + '<div class="em-ats-loading"><span></span>Loading the application…</div>';
        post({ action: 'em_ats_detail', id: a.id }).then(function (j) {
            if (S.open !== a.id) return;
            if (!j || !j.success) { dbody.innerHTML = drawerHead(a) + empty('Could not load', (j && j.data && j.data.message) || ''); return; }
            dbody.innerHTML = drawerHead(j.data.row) + drawerBody(j.data);
        });
    }
    function closeDrawer() { drawer.hidden = true; S.open = 0; document.documentElement.classList.remove('em-ats-lock'); }
    function neighbours(a) {
        var rows = filtered(false), i = -1;
        rows.forEach(function (r, k) { if (r.id === a.id) i = k; });
        return { prev: i > 0 ? rows[i - 1] : null, next: i !== -1 && i < rows.length - 1 ? rows[i + 1] : null, pos: i + 1, n: rows.length };
    }
    function drawerHead(a) {
        var p = postingOf(a), nb = neighbours(a), pr = primary(a);
        var moveOpts = p.steps.map(function (st, i) { return '<option value="' + i + '"' + (i === a.step_index ? ' selected' : '') + '>' + esc(st.title) + (st.final ? ' (approve)' : '') + '</option>'; }).join('');
        return '<header class="em-ats-dh" style="--pc:' + esc(p.accent) + '">' +
            '<div class="em-ats-dh__nav"><button type="button" class="em-ats-icon" data-nav="' + (nb.prev ? nb.prev.id : '') + '"' + (nb.prev ? '' : ' disabled') + ' aria-label="Previous applicant"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                '<button type="button" class="em-ats-icon" data-nav="' + (nb.next ? nb.next.id : '') + '"' + (nb.next ? '' : ' disabled') + ' aria-label="Next applicant"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                (nb.pos ? '<small>' + nb.pos + ' of ' + nb.n + '</small>' : '') + '<button type="button" class="em-ats-x" data-dx aria-label="Close">&times;</button></div>' +
            '<div class="em-ats-dh__who">' + avatar(a, 64) + '<div><h3>' + esc(a.name) + '</h3><p>' + esc(a.email) +
                (a.email_status === 'verified' ? ' <span class="em-ats-pill is-green">Email confirmed</span>' : (a.user_id ? ' <span class="em-ats-pill is-violet">Email not confirmed</span>' : '')) + '</p>' +
                '<p class="em-ats-sub"><span class="em-ats-post" style="--pc:' + esc(p.accent) + '">' + esc(p.title) + '</span> Applied ' + esc(dt(a.ts)) + (a.source ? ' · via ' + esc(a.source) : '') + '</p></div></div>' +
            '<div class="em-ats-dh__acts">' + (pr ? '<button type="button" class="em-ats-btn is-' + pr.tone + '" data-do="' + pr.op + '" data-id="' + a.id + '">' + esc(pr.label) + '</button>' : '<span class="em-ats-done">✓ Approved</span>') +
                '<span class="em-ats-move"><select data-move aria-label="Move to step">' + moveOpts + '</select><button type="button" class="em-ats-btn is-ghost is-sm" data-do="move" data-id="' + a.id + '">Move</button></span>' +
                (a.email_hold || (a.user_id && a.email_status !== 'verified') ? '<button type="button" class="em-ats-btn is-ghost is-sm" data-do="resend_email" data-id="' + a.id + '">Resend email</button><button type="button" class="em-ats-btn is-ghost is-sm" data-do="confirm_email" data-id="' + a.id + '">Mark email confirmed</button>' : '') +
                (a.message_url ? '<a class="em-ats-btn is-ghost is-sm" href="' + esc(a.message_url) + '" target="_blank" rel="noopener">Message</a>' : '') +
                (a.profile ? '<a class="em-ats-btn is-ghost is-sm" href="' + esc(a.profile) + '" target="_blank" rel="noopener">Profile ↗</a>' : '') +
                (a.rejected ? '' : '<button type="button" class="em-ats-btn is-danger is-sm" data-do="reject" data-id="' + a.id + '">Not selected…</button>') +
            '</div></header>';
    }
    function drawerBody(d) {
        var a = d.row, p = postingOf(a);
        var rej = d.rejection ? '<div class="em-ats-alert is-red"><b>Not selected</b> ' + esc(dt(d.rejection.ts)) + (d.rejection.by ? ' by ' + esc(d.rejection.by) : '') + (d.rejection.emailed ? ' · applicant emailed' : '') + (d.rejection.reason ? '<p>' + esc(d.rejection.reason) + '</p>' : '') + '</div>' : '';
        var hold = a.email_hold ? '<div class="em-ats-alert is-violet"><b>Waiting for the email confirmation.</b> The application moves on by itself the moment they click the link in the confirmation email.</div>' : '';
        var steps = p.steps.map(function (st, i) {
            var c = a.state === 'completed' || i < a.step_index ? 'is-done' : (i === a.step_index ? 'is-here' : '');
            var when = '';
            d.events.forEach(function (e) { if (e.stage === st.title && e.event === 'entered') when = e.ts; });
            return '<li class="' + c + '"><i>' + (c === 'is-done' ? '✓' : i + 1) + '</i><b>' + esc(st.title) + '</b><small>' + (when ? esc(dt(when)) : (c ? '' : 'Not yet')) + '</small></li>';
        }).join('');
        var checks = d.checks && d.checks.length ? '<section class="em-ats-sec"><h4>Profile</h4><div class="em-ats-checks">' + d.checks.map(function (c) {
            return '<a class="' + (c.done ? 'is-done' : '') + '" href="' + esc(c.url) + '" target="_blank" rel="noopener"><i>' + (c.done ? '✓' : '○') + '</i><b>' + esc(c.title) + '</b><small>' + esc(c.done ? 'Ready to review' : 'Not done yet') + '</small></a>';
        }).join('') + '</div></section>' : '';
        var answers = d.answers.length ? d.answers.map(function (x) { return '<div class="em-ats-qa"><dt>' + esc(x.q) + '</dt><dd>' + esc(x.a) + '</dd></div>'; }).join('') : '<p class="em-ats-sub">No answers on this form.</p>';
        var notes = d.notes.slice().reverse().map(function (n) { return '<li><p>' + esc(n.text) + '</p><small>' + esc(n.by || 'Team') + ' · ' + esc(dt(n.ts)) + '</small></li>'; }).join('');
        var labels = { entered: 'Entered', completed: 'Completed', rejected: 'Marked as not selected', restored: 'Restored', email_confirmed: 'Email confirmed' };
        var events = d.events.slice().reverse().map(function (e) {
            var m = e.method === 'submission' ? 'by submitting the form' : (e.method === 'member' ? 'by the applicant' : (e.method === 'approval' || e.method === 'manual' || e.method === 'admin' ? (e.by ? 'by ' + e.by : 'by the team') : (e.method === 'auto' ? 'automatically' : '')));
            return '<li><b>' + esc((labels[e.event] || e.event) + ': ' + e.stage) + '</b><small>' + esc(dt(e.ts)) + (m ? ' · ' + esc(m) : '') + '</small></li>';
        }).join('');
        var member = d.member ? '<p class="em-ats-sub">Member since ' + esc(new Date(d.member.since * 1000).toLocaleDateString()) + ' · @' + esc(d.member.login) + '</p>' : '';
        return '<div class="em-ats-db">' + rej + hold +
            '<section class="em-ats-sec"><h4>Progress</h4><ol class="em-ats-track">' + steps + '</ol></section>' +
            '<section class="em-ats-sec em-ats-sec--row"><div><h4>Your rating</h4>' + stars(a.rating, true) + '</div><div>' + member + '</div></section>' +
            checks +
            '<section class="em-ats-sec"><h4>Application</h4><dl>' + answers + '</dl></section>' +
            '<section class="em-ats-sec"><h4>Team notes</h4><div class="em-ats-note"><textarea rows="2" data-note placeholder="Add a note only your team can see"></textarea><button type="button" class="em-ats-btn is-sm" data-do="note" data-id="' + a.id + '">Add note</button></div><ul class="em-ats-notes">' + notes + '</ul></section>' +
            '<section class="em-ats-sec"><h4>Activity</h4><ul class="em-ats-log">' + events + '</ul></section></div>';
    }

    /* ---------- events ---------- */
    root.addEventListener('click', function (e) {
        var v = e.target.closest('[data-view]');
        if (v) { S.view = v.getAttribute('data-view'); savePrefs(); renderView(); return; }
        var a = e.target.closest('[data-act]');
        if (a) {
            var k = a.getAttribute('data-act');
            if (k === 'refresh') { a.classList.add('is-spin'); load(false).then(function () { a.classList.remove('is-spin'); }); }
            if (k === 'export') exportCsv(filtered(false));
            return;
        }
        var c = e.target.closest('[data-status]');
        if (c) { S.status = c.getAttribute('data-status'); S.page = 1; savePrefs(); render(); return; }
        var pg = e.target.closest('[data-page]');
        if (pg) { S.page = +pg.getAttribute('data-page'); renderView(); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
        var bt = e.target.closest('[data-board]');
        if (bt) { S.boardPosting = +bt.getAttribute('data-board'); renderView(); return; }
        var bd = e.target.closest('[data-bulk-do]');
        if (bd) { bulk(bd.getAttribute('data-bulk-do'), bd); return; }
        var d = e.target.closest('[data-do]');
        if (d) { runOne(d.getAttribute('data-do'), find(d.getAttribute('data-id')), d); return; }
        if (e.target.closest('input,select,a,label')) return;
        var o = e.target.closest('[data-open]');
        if (o) openDrawer(o.getAttribute('data-open'));
    });
    root.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches('[data-sel]')) { if (t.checked) S.sel[t.getAttribute('data-sel')] = 1; else delete S.sel[t.getAttribute('data-sel')]; t.closest('tr').classList.toggle('is-sel', t.checked); renderBulk(); }
        else if (t.matches('[data-selall]')) { root.querySelectorAll('[data-sel]').forEach(function (x) { x.checked = t.checked; if (t.checked) S.sel[x.getAttribute('data-sel')] = 1; else delete S.sel[x.getAttribute('data-sel')]; x.closest('tr').classList.toggle('is-sel', t.checked); }); renderBulk(); }
        else if (t.matches('[data-posting]')) { S.posting = t.value; S.page = 1; savePrefs(); render(); }
        else if (t.matches('[data-sort]')) { S.sort = t.value; savePrefs(); renderView(); }
    });
    var qt;
    root.addEventListener('input', function (e) {
        if (!e.target.matches('[data-q]')) return;
        clearTimeout(qt);
        qt = setTimeout(function () { S.q = e.target.value; S.page = 1; renderView(); }, 150);
    });

    drawer.addEventListener('click', function (e) {
        if (e.target.closest('[data-dx]')) { closeDrawer(); return; }
        var n = e.target.closest('[data-nav]');
        if (n && n.getAttribute('data-nav')) { openDrawer(n.getAttribute('data-nav')); return; }
        var r = e.target.closest('[data-rate]');
        if (r) { var a0 = find(S.open), v = +r.getAttribute('data-rate'); act('rate', [S.open], { rating: a0 && a0.rating === v ? 0 : v }); return; }
        var d = e.target.closest('[data-do]');
        if (!d) return;
        var a = find(d.getAttribute('data-id')), op = d.getAttribute('data-do');
        if (!a) return;
        if (op === 'note') {
            var ta = drawer.querySelector('[data-note]');
            if (!ta.value.trim()) { ta.focus(); return; }
            d.disabled = true;
            act('note', [a.id], { text: ta.value }).then(function (j) { d.disabled = false; if (j && j.success) toast('Note added'); });
            return;
        }
        if (op === 'move') {
            var step = +drawer.querySelector('[data-move]').value, st = postingOf(a).steps[step] || {};
            if (step === a.step_index) { toast('They are already on ' + st.title); return; }
            if (st.final && !confirm('Move ' + a.name + ' to ' + st.title + '? This approves them: their role, contract and dashboards switch on and the welcome email is sent.')) return;
            d.disabled = true;
            move(a, step).then(function (j) { d.disabled = false; if (j && j.success) { toast(a.name + ' moved to ' + st.title); load(true); } else toast((j && j.data && j.data.message) || 'Could not move them.', true); });
            return;
        }
        runOne(op, a, d);
    });
    document.addEventListener('keydown', function (e) {
        if (drawer.hidden) return;
        if (e.key === 'Escape') { closeDrawer(); return; }
        if (e.target.closest && e.target.closest('textarea,input,select')) return;
        var a = find(S.open);
        if (!a) return;
        var nb = neighbours(a);
        if ((e.key === 'ArrowDown' || e.key === 'j') && nb.next) { e.preventDefault(); openDrawer(nb.next.id); }
        if ((e.key === 'ArrowUp' || e.key === 'k') && nb.prev) { e.preventDefault(); openDrawer(nb.prev.id); }
    });

    /* Pipeline drag and drop */
    var dragId = 0;
    root.addEventListener('dragstart', function (e) { var c = e.target.closest('[data-card]'); if (!c) return; dragId = +c.getAttribute('data-card'); c.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(dragId)); } catch (x) {} });
    root.addEventListener('dragend', function (e) { var c = e.target.closest('[data-card]'); if (c) c.classList.remove('is-drag'); root.querySelectorAll('.em-ats-col.is-over').forEach(function (x) { x.classList.remove('is-over'); }); });
    root.addEventListener('dragover', function (e) { var c = e.target.closest('[data-col]'); if (!c || !dragId) return; e.preventDefault(); root.querySelectorAll('.em-ats-col.is-over').forEach(function (x) { if (x !== c) x.classList.remove('is-over'); }); c.classList.add('is-over'); });
    root.addEventListener('drop', function (e) {
        var c = e.target.closest('[data-col]');
        if (!c || !dragId) return;
        e.preventDefault();
        c.classList.remove('is-over');
        var a = find(dragId), key = c.getAttribute('data-col');
        dragId = 0;
        if (!a) return;
        if (key === 'rejected') { if (!a.rejected) rejectDialog([a]); return; }
        var step = +key, st = postingOf(a).steps[step] || {};
        if (a.rejected) { act('restore', [a.id]).then(function () { if (step !== a.step_index) move(a, step).then(function () { load(); }); }); return; }
        if (step === a.step_index) return;
        if (st.final && !confirm('Approve ' + a.name + '? Their role, contract and dashboards switch on and the welcome email is sent.')) return;
        move(a, step).then(function (j) { if (j && j.success) { toast(a.name + ' moved to ' + st.title); load(); } else toast((j && j.data && j.data.message) || 'Could not move them.', true); });
    });

    /* Bulk */
    function bulk(op, btn) {
        var sel = selected();
        if (op === 'clear') { S.sel = {}; renderView(); return; }
        if (op === 'export') { exportCsv(sel); return; }
        if (!sel.length) return;
        if (op === 'reject') { rejectDialog(sel.filter(function (a) { return !a.rejected; })); return; }
        if (op === 'restore' || op === 'resend_email') {
            act(op, sel.map(function (a) { return a.id; })).then(function (j) { if (j && j.success) toast(j.data.done + ' done' + (j.data.skipped ? ' · ' + j.data.skipped + ' skipped' : '')); });
            return;
        }
        if (op === 'move') {
            var step = body.querySelector('[data-bulk-step]').value;
            if (step === '') { toast('Choose a step first', true); return; }
            var p = postingOf(sel[0]), st = p.steps[+step];
            if (!confirm('Move ' + sel.length + ' applicant' + (sel.length > 1 ? 's' : '') + ' to ' + st.title + '?' + (st.final ? '\n\nThis approves them: roles, contracts and welcome emails go out.' : ''))) return;
            btn.disabled = true;
            post({ action: 'em_bulk_advance_posting_applicants', posting_id: sel[0].posting_id, step_index: step, submission_ids: sel.map(function (a) { return a.id; }) }).then(function (j) {
                btn.disabled = false;
                if (j && j.success) { toast((j.data.moved || 0) + ' moved to ' + st.title); S.sel = {}; load(); } else toast((j && j.data && j.data.message) || 'Could not move them.', true);
            });
            return;
        }
        if (op === 'complete') {
            var todo = sel.filter(function (a) { var s = status(a); return s === 'review' || s === 'applicant'; });
            var finals = todo.filter(function (a) { var n = postingOf(a).steps[a.step_index + 1]; return n && n.final; }).length;
            if (!todo.length) { toast('None of these are waiting on a step you can complete', true); return; }
            if (!confirm('Move ' + todo.length + ' applicant' + (todo.length > 1 ? 's' : '') + ' on by one step?' + (finals ? '\n\n' + finals + ' of them will be approved: roles, contracts and welcome emails go out.' : ''))) return;
            btn.disabled = true;
            var ok = 0;
            todo.reduce(function (ch, a) { return ch.then(function () { return complete(a).then(function (j) { if (j && j.success) ok++; }); }); }, Promise.resolve()).then(function () {
                btn.disabled = false; toast(ok + ' moved on'); S.sel = {}; load();
            });
        }
    }

    function exportCsv(rows) {
        var head = ['Name', 'Email', 'Email confirmed', 'Posting', 'Step', 'Status', 'Source', 'Rating', 'Notes', 'Applied', 'Profile'];
        var lines = [head].concat(rows.map(function (a) {
            return [a.name, a.email, a.email_status === 'verified' ? 'yes' : 'no', postingOf(a).title, (postingOf(a).steps[a.step_index] || {}).title || a.stage, PILL[status(a)][1], a.source || '', a.rating || '', a.notes_n || 0, a.ts ? new Date(a.ts * 1000).toISOString().slice(0, 10) : '', a.profile || ''];
        })).map(function (r) { return r.map(function (v) { v = String(v == null ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\n');
        var blob = new Blob(['﻿' + lines], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'applicants-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(link); link.click(); link.remove();
        toast(rows.length + ' applicant' + (rows.length === 1 ? '' : 's') + ' exported');
    }

    // Deep link from the approval emails: admin.php?page=talk-flows&ats_open=<application id>#tab=applications
    load(false).then(function () {
        var m = /[?&]ats_open=(\d+)/.exec(location.search);
        if (m && find(m[1])) openDrawer(m[1]);
    });
}());
