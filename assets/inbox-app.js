/* eslint-disable no-undef */
/**
 * Member Inbox — read-only React UI (slice 2c).
 *
 * No build step. Uses wp.element (React wrapper shipped with WP) +
 * htm (tagged template literal for JSX-like syntax) + wp.components.
 * Bundle stays under a few KB; production-friendly with no toolchain.
 */
(function () {
    'use strict';

    if (!window.wp || !wp.element || !wp.components || !wp.apiFetch) {
        var fallback = document.getElementById('em-inbox-root');
        if (fallback) fallback.textContent = 'Inbox UI needs the WordPress block editor scripts (wp-element / wp-components / wp-api-fetch).';
        console.error('em-inbox: wp.element / wp.components / wp.apiFetch missing');
        return;
    }
    var cfg = window.EM_INBOX_CONFIG || {};
    if (!cfg.restRoot) {
        console.error('em-inbox: EM_INBOX_CONFIG.restRoot missing');
        return;
    }

    // ── htm: 1KB JSX-replacement via tagged template literals ────────────
    // Inlined here so we don't ship a separate <script> just for it.
    // Source: https://github.com/developit/htm (MIT, ~1KB minified).
    // The line below IS the entire htm 'mini' build — a self-executing
    // IIFE that returns the htm function. Don't wrap it in another IIFE.
    var htm = (function(){var n=function(t,s,r,e){var u;s[0]=0;for(var h=1;h<s.length;h++){var p=s[h++],a=s[h]?(s[0]|=p?1:2,r[s[h++]]):s[++h];3===p?e[0]=a:4===p?e[1]=Object.assign(e[1]||{},a):5===p?(e[1]=e[1]||{})[s[++h]]=a:6===p?e[1][s[++h]]+=a+"":p?(u=t.apply(a,n(t,a,r,["",null])),e.push(u),a[0]?s[0]|=2:(s[h-2]=0,s[h]=u)):e.push(a)}return e},t=new Map;return function(s){var r=t.get(this);return r||(r=new Map,t.set(this,r)),(r=n(this,r.get(s)||(r.set(s,r=function(n){for(var t,s,r=1,e="",u="",h=[0],p=function(n){1===r&&(n||(e=e.replace(/^\s*\n\s*|\s*\n\s*$/g,"")))?h.push(0,n,e):3===r&&(n||e)?(h.push(3,n,e),r=2):2===r&&"..."===e&&n?h.push(4,n,0):2===r&&e&&!n?h.push(5,0,!0,e):r>=5&&((e||!n&&5===r)&&(h.push(r,0,e,s),r=6),n&&(h.push(r,n,0,s),r=6)),e=""},a=0;a<n.length;a++){a&&(1===r&&p(),p(a));for(var l=0;l<n[a].length;l++)t=n[a][l],1===r?"<"===t?(p(),h=[h],r=3):e+=t:4===r?"--"===e&&">"===t?(r=1,e=""):e=t+e[0]:u?t===u?u="":e+=t:'"'===t||"'"===t?u=t:">"===t?(p(),r=1):r&&("="===t?(r=5,s=e,e=""):"/"===t&&(r<5||">"===n[a][l+1])?(p(),3===r&&(h=h[0]),r=h,(h=h[0]).push(2,0,r),r=0):" "===t||"\t"===t||"\n"===t||"\r"===t?(p(),r=2):e+=t),3===r&&"!--"===e&&(r=4,h=h[0])}return p(),h}(s)),r),arguments,[])).length>1?r:r[0]}})();

    var el      = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var Fragment = wp.element.Fragment;
    var html = htm.bind(el);
    var apiFetch = wp.apiFetch;
    apiFetch.use(apiFetch.createNonceMiddleware(cfg.nonce));

    var Spinner = wp.components.Spinner;
    var Notice  = wp.components.Notice;
    var SelectControl = wp.components.SelectControl;
    var Button = wp.components.Button;
    var Card = wp.components.Card;
    var CardBody = wp.components.CardBody;
    var CardHeader = wp.components.CardHeader;
    var Modal = wp.components.Modal;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var FormTokenField = wp.components.FormTokenField;

    // ── REST helpers ────────────────────────────────────────────────────
    function restGet(path) {
        // path is relative to the inbox root (e.g. 'threads?inbox=foo' or 'threads/3')
        return apiFetch({ url: cfg.restRoot + path, method: 'GET' });
    }
    function restPost(path, data) {
        return apiFetch({ url: cfg.restRoot + path, method: 'POST', data: data });
    }

    // ── Components ──────────────────────────────────────────────────────

    // ── Undo-send snackbar (slice 2y) ──────────────────────────────
    function UndoSnack(props) {
        var remainState = useState(Math.max(0, Math.round((props.state.deadline - Date.now()) / 1000)));
        var remain = remainState[0], setRemain = remainState[1];
        useEffect(function () {
            var t = setInterval(function () {
                var s = Math.max(0, Math.round((props.state.deadline - Date.now()) / 1000));
                setRemain(s);
                if (s <= 0) { clearInterval(t); props.onExpired && props.onExpired(); }
            }, 250);
            return function () { clearInterval(t); };
        }, [props.state.deadline]);
        function undo() {
            apiFetch({ url: cfg.restRoot + 'messages/' + props.state.rawId + '/cancel', method: 'DELETE' })
                .then(function () { props.onUndone && props.onUndone(); })
                .catch(function (e) {
                    // If the cron already picked it up (status != pending), the
                    // API returns 409 — just dismiss the snack so the user
                    // sees the actual delivery status.
                    props.onUndone && props.onUndone();
                });
        }
        return html`
          <div class="em-inbox-undo-snack" role="status" aria-live="assertive">
            <span>Sent. Cancel in ${remain}s</span>
            <button type="button" class="em-inbox-undo-btn" onClick=${undo}>Undo</button>
          </div>
        `;
    }

    // ── Keyboard shortcuts (slice 2x) ──────────────────────────────
    // Returns true when key events should be IGNORED (because the user
    // is typing in an editable surface).
    function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
        if (el.isContentEditable) return true;
        return false;
    }

    function ShortcutHelpOverlay(props) {
        var rows = [
            ['j / k',         'next / previous thread'],
            ['Enter',         'open focused thread'],
            ['u',             'back to feed'],
            ['c',             'compose'],
            ['r',             'reply (within thread)'],
            ['a',             'reply all (within thread)'],
            ['f',             'forward (within thread)'],
            ['e',             'archive focused / open thread'],
            ['#',             'trash focused / open thread'],
            ['s',             'star / unstar focused / open'],
            ['g i',           'go to All inbox'],
            ['g u',           'go to Unread'],
            ['g s',           'go to Snoozed'],
            ['g z',           'go to Archived'],
            ['g t',           'go to Trash'],
            ['/',             'focus search bar'],
            ['Esc',           'close modal / overlay'],
            ['?',             'this help'],
        ];
        return html`
          <${Modal} title="Keyboard shortcuts" onRequestClose=${props.onClose} className="em-inbox-help-modal">
            <table class="em-inbox-help-table">
              ${rows.map(function (r, i) {
                  return html`<tr key=${i}><th>${r[0]}</th><td>${r[1]}</td></tr>`;
              })}
            </table>
            <p class="em-inbox-help-foot">Shortcuts are disabled while you're typing in an input, textarea, or rich-text composer.</p>
          <//>
        `;
    }

    // ── Vacation responder modal (slice 2z) ────────────────────────
    function VacationModal(props) {
        var st = useState({ loading: true, cfg: null, savedAt: 0, err: null });
        var state = st[0], setState = st[1];
        useEffect(function () {
            restGet('vacation').then(function (d) {
                setState({ loading: false, cfg: d, savedAt: 0, err: null });
            }).catch(function (e) { setState({ loading: false, cfg: null, savedAt: 0, err: e.message || 'load failed' }); });
        }, []);
        function update(k, v) {
            setState(Object.assign({}, state, { cfg: Object.assign({}, state.cfg, k === '_obj' ? v : (function () { var o = {}; o[k] = v; return o; })()) }));
        }
        function save() {
            setState(Object.assign({}, state, { err: null }));
            restPost('vacation', state.cfg).then(function (d) {
                setState({ loading: false, cfg: d, savedAt: Date.now(), err: null });
                props.onChanged && props.onChanged(d);
            }).catch(function (e) { setState(Object.assign({}, state, { err: e.message || 'save failed' })); });
        }
        if (state.loading) return html`<${Modal} title="Vacation responder" onRequestClose=${props.onClose}><${Spinner} /><//>`;
        if (!state.cfg)    return html`<${Modal} title="Vacation responder" onRequestClose=${props.onClose}><${Notice} status="error" isDismissible=${false}>${state.err || 'load failed'}<//><//>`;
        var c = state.cfg;
        return html`
          <${Modal} title="Vacation responder" onRequestClose=${props.onClose} className="em-inbox-vacation-modal">
            <label class="em-inbox-track-toggle">
              <input type="checkbox" checked=${!!c.enabled} onChange=${function (e) { update('enabled', e.target.checked); }} />
              <span>Auto-reply to incoming messages</span>
            </label>
            <div class="em-vac-row">
              <${TextControl} type="date" label="Start" value=${c.start_at || ''} onChange=${function (v) { update('start_at', v); }} __nextHasNoMarginBottom=${true} />
              <${TextControl} type="date" label="End"   value=${c.end_at   || ''} onChange=${function (v) { update('end_at',   v); }} __nextHasNoMarginBottom=${true} />
            </div>
            <${TextControl} label="Subject" value=${c.subject || ''} onChange=${function (v) { update('subject', v); }} __nextHasNoMarginBottom=${true} />
            <${TextareaControl} label="Plain-text body" value=${c.body_plain || ''} rows=${4} onChange=${function (v) { update('body_plain', v); }} __nextHasNoMarginBottom=${true} />
            <label class="em-inbox-rte-label">HTML body (optional — overrides plain when recipient supports HTML)</label>
            <${RichTextEditor} value=${c.body_html || ''} onChange=${function (v) { update('body_html', v); }} />
            ${state.err && html`<${Notice} status="error"   isDismissible=${false}>${state.err}<//>`}
            ${state.savedAt > 0 && html`<${Notice} status="success" isDismissible=${false}>Saved.<//>`}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose}>Close<//>
              <${Button} variant="primary"  onClick=${save}>Save<//>
            </div>
          <//>
        `;
    }

    // ── Notification bell (slice 2ii) — polls /unread-count every 30s
    //    while the tab is visible. Pulses + plays a soft chime when the
    //    unread total increases. Click toggles a snackbar showing the
    //    latest_at timestamp (no auto-filter switch — the user still
    //    has explicit filter tabs).
    function NotificationBell(props) {
        var countState = useState(null);   var count = countState[0], setCount = countState[1];
        var pulseState = useState(false);  var pulse = pulseState[0], setPulse = pulseState[1];
        // Track previous count via ref so the polling closure doesn't
        // stale-close over an old setCount.
        var prevRef = wp.element.useRef ? wp.element.useRef(0) : { current: 0 };

        function chime() {
            // Short soft three-note chime (WebAudio is more robust than
            // a base64 <audio> tag here — works without preloading).
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (! Ctx) return;
                var ctx = new Ctx();
                var now = ctx.currentTime;
                [880, 1100].forEach(function (freq, i) {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.0001, now + i * 0.12);
                    gain.gain.exponentialRampToValueAtTime(0.06, now + i * 0.12 + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.12 + 0.18);
                    osc.connect(gain).connect(ctx.destination);
                    osc.start(now + i * 0.12);
                    osc.stop(now + i * 0.12 + 0.2);
                });
                // Garbage-collect the AudioContext after the chime finishes.
                setTimeout(function () { try { ctx.close(); } catch (e) {} }, 800);
            } catch (e) { /* audio not available — silent */ }
        }

        useEffect(function () {
            if (! props.inbox) return;
            var live = true;
            function tick() {
                if (document.visibilityState !== 'visible') return;
                restGet('unread-count?inbox=' + encodeURIComponent(props.inbox))
                    .then(function (d) {
                        if (! live) return;
                        var n = Number(d.unread || 0);
                        setCount(n);
                        var prev = prevRef.current;
                        if (prev !== 0 && n > prev) {
                            setPulse(true);
                            chime();
                            setTimeout(function () { setPulse(false); }, 1800);
                        }
                        prevRef.current = n;
                    })
                    .catch(function () { /* non-fatal */ });
            }
            tick();
            var handle = setInterval(tick, 30000);
            // Tab-visibility resume: re-poll immediately when the tab
            // regains focus so the count is fresh without waiting up
            // to 30s for the next interval.
            function onVis() { if (document.visibilityState === 'visible') tick(); }
            document.addEventListener('visibilitychange', onVis);
            return function () { live = false; clearInterval(handle); document.removeEventListener('visibilitychange', onVis); };
        }, [props.inbox]);

        return html`
          <button
            type="button"
            class="em-inbox-bell ${pulse ? 'is-pulsing' : ''} ${count > 0 ? 'has-unread' : ''}"
            title=${count != null ? count + ' unread' : 'New mail indicator'}
            aria-label=${count != null ? count + ' unread message' + (count === 1 ? '' : 's') : 'New mail indicator'}
            onClick=${function () { props.onClick && props.onClick(); }}
          >
            <span aria-hidden="true">🔔</span>
            ${count > 0 ? html`<span class="em-inbox-bell-badge" aria-hidden="true">${count > 99 ? '99+' : count}</span>` : null}
            <span class="screen-reader-text" aria-live="polite">${count != null ? count + ' unread' : ''}</span>
          </button>
        `;
    }

    // ── Admin: add new inbox modal (slice 2ss) ────────────────────────
    function AddInboxModal(props) {
        var formState = useState({ email: '', display_name: '', mode: 'new_user', role: 'subscriber', send_invite: true });
        var form = formState[0], setForm = formState[1];
        var busyState = useState(false); var busy = busyState[0], setBusy = busyState[1];
        var msgState = useState(null);   var msg  = msgState[0],  setMsg  = msgState[1];

        function submit() {
            setBusy(true); setMsg(null);
            apiFetch({ url: cfg.restRoot + 'admin/inboxes', method: 'POST', data: form })
                .then(function (res) {
                    setBusy(false);
                    if (res && res.created) {
                        setMsg({ status: 'success', text: 'Created user (' + (res.login || '') + ') for ' + res.email + (res.invite_sent ? ' — invite email sent.' : ' — no invite sent.') });
                    } else if (res && res.already_existed) {
                        setMsg({ status: 'info', text: 'User for ' + res.email + ' already existed. Stamped em_inbox_address user_meta.' });
                    } else {
                        setMsg({ status: 'success', text: 'Assigned inbox ' + res.email + ' to existing user.' });
                    }
                    props.onCreated && props.onCreated(res);
                })
                .catch(function (e) {
                    setBusy(false);
                    setMsg({ status: 'error', text: (e && e.message) || 'Add-inbox failed' });
                });
        }

        return html`
          <${Modal} title="Add a new inbox" onRequestClose=${props.onClose} className="em-inbox-addinbox-modal">
            <p class="em-inbox-addinbox-help">
              Creates a new WP user whose email <strong>is</strong> the inbox address (or
              assigns the address to an existing user). The address is stamped onto
              <code>em_inbox_address</code> user_meta, which is what threading uses to map
              inbound messages to an owner.
            </p>
            <${TextControl}
              label="Email address"
              help="Becomes both the user's login email AND their inbox address."
              type="email"
              value=${form.email}
              onChange=${function (v) { setForm(Object.assign({}, form, { email: v })); }}
              __nextHasNoMarginBottom=${true} />
            <${TextControl}
              label="Display name (optional)"
              value=${form.display_name}
              onChange=${function (v) { setForm(Object.assign({}, form, { display_name: v })); }}
              __nextHasNoMarginBottom=${true} />
            <label class="em-inbox-rte-label">Mode</label>
            <select value=${form.mode} onChange=${function (e) { setForm(Object.assign({}, form, { mode: e.target.value })); }}>
              <option value="new_user">Create new WP user</option>
              <option value="existing_user">Assign to existing WP user</option>
            </select>
            ${form.mode === 'new_user' && html`
              <label class="em-inbox-rte-label" style=${{ marginTop: '12px' }}>Role</label>
              <select value=${form.role} onChange=${function (e) { setForm(Object.assign({}, form, { role: e.target.value })); }}>
                <option value="subscriber">Subscriber</option>
                <option value="contributor">Contributor</option>
                <option value="author">Author</option>
                <option value="editor">Editor</option>
                <option value="administrator">Administrator</option>
              </select>
              <label class="em-inbox-track-toggle" style=${{ marginTop: '12px' }}>
                <input type="checkbox" checked=${!!form.send_invite} onChange=${function (e) { setForm(Object.assign({}, form, { send_invite: e.target.checked })); }} />
                <span>Send invite email (password-reset link)</span>
              </label>
            `}
            ${msg && html`<${Notice} status=${msg.status} isDismissible=${false}>${msg.text}<//>`}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose} disabled=${busy}>Close<//>
              <${Button} variant="primary" onClick=${submit} disabled=${busy || ! form.email}>
                ${busy ? html`<${Spinner} />` : (form.mode === 'new_user' ? 'Create inbox' : 'Assign')}
              <//>
            </div>
          <//>
        `;
    }

    // ── Sharing / delegation modal (slice 2ee) ────────────────────────
    function SharingModal(props) {
        var st = useState({ loading: true, given: [], received: [], err: null });
        var state = st[0], setState = st[1];
        var formState = useState({ grantee_email: '', scope: 'read', expires_at: '' });
        var form = formState[0], setForm = formState[1];
        var saveErrState = useState(null); var saveErr = saveErrState[0], setSaveErr = saveErrState[1];

        function reload() {
            setState(Object.assign({}, state, { loading: true, err: null }));
            restGet('grants')
                .then(function (d) { setState({ loading: false, given: d.given || [], received: d.received || [], err: null }); })
                .catch(function (e) { setState({ loading: false, given: [], received: [], err: e.message || 'load failed' }); });
        }
        useEffect(reload, []);

        function grant() {
            setSaveErr(null);
            apiFetch({ url: cfg.restRoot + 'grants', method: 'POST', data: form })
                .then(function () {
                    setForm({ grantee_email: '', scope: 'read', expires_at: '' });
                    reload();
                })
                .catch(function (e) { setSaveErr((e && e.message) || 'grant failed'); });
        }
        function revoke(id) {
            if (! window.confirm('Revoke this access? The other person will lose access immediately.')) return;
            apiFetch({ url: cfg.restRoot + 'grants/' + id, method: 'DELETE' }).then(reload);
        }

        return html`
          <${Modal} title="Sharing — inbox access" onRequestClose=${props.onClose} className="em-inbox-filters-modal">
            ${state.loading ? html`<${Spinner} />`
              : state.err   ? html`<${Notice} status="error" isDismissible=${false}>${state.err}<//>`
              : html`<div>
                <h3 class="em-inbox-filters-h3">People you've given access to</h3>
                ${state.given.length === 0
                  ? html`<p class="em-inbox-empty">You haven't shared your inbox with anyone.</p>`
                  : html`<ul class="em-inbox-filters-list">
                      ${state.given.map(function (g) {
                          return html`
                            <li key=${g.id} class="em-inbox-filters-row-item">
                              <div class="em-inbox-filters-meta">
                                <div class="em-inbox-filters-name">${g.grantee_email}</div>
                                <div class="em-inbox-filters-cond">scope: <strong>${g.scope}</strong>${g.expires_at ? ' · expires ' + formatDate(g.expires_at) : ''}</div>
                              </div>
                              <button type="button" class="components-button is-tertiary em-inbox-filters-rm" onClick=${function () { revoke(g.id); }}>Revoke</button>
                            </li>
                          `;
                      })}
                    </ul>`}

                <h3 class="em-inbox-filters-h3">Grant new access</h3>
                <${TextControl}
                  label="Grantee email"
                  help="Must be an existing member account on this site."
                  value=${form.grantee_email}
                  onChange=${function (v) { setForm(Object.assign({}, form, { grantee_email: v })); }}
                  __nextHasNoMarginBottom=${true} />
                <label class="em-inbox-rte-label">Scope</label>
                <select value=${form.scope} onChange=${function (e) { setForm(Object.assign({}, form, { scope: e.target.value })); }}>
                  <option value="read">Read only</option>
                  <option value="read_send">Read + send as me</option>
                </select>
                <${TextControl}
                  type="datetime-local"
                  label="Expires (optional)"
                  value=${form.expires_at}
                  onChange=${function (v) { setForm(Object.assign({}, form, { expires_at: v })); }}
                  __nextHasNoMarginBottom=${true} />
                ${saveErr && html`<${Notice} status="error" isDismissible=${false}>${saveErr}<//>`}
                <div class="em-inbox-composer-actions">
                  <${Button} variant="primary" onClick=${grant} disabled=${! form.grantee_email}>Grant access<//>
                </div>

                <h3 class="em-inbox-filters-h3">Access others have given you</h3>
                ${state.received.length === 0
                  ? html`<p class="em-inbox-empty">Nobody has shared their inbox with you.</p>`
                  : html`<ul class="em-inbox-filters-list">
                      ${state.received.map(function (g) {
                          return html`
                            <li key=${g.id} class="em-inbox-filters-row-item">
                              <div class="em-inbox-filters-meta">
                                <div class="em-inbox-filters-name">${g.owner_email}</div>
                                <div class="em-inbox-filters-cond">scope: <strong>${g.scope}</strong>${g.expires_at ? ' · expires ' + formatDate(g.expires_at) : ''}</div>
                              </div>
                              <button type="button" class="components-button is-tertiary em-inbox-filters-rm" onClick=${function () { revoke(g.id); }}>Remove</button>
                            </li>
                          `;
                      })}
                    </ul>`}
              </div>`}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose}>Close<//>
            </div>
          <//>
        `;
    }

    // ── Filters engine modal (slice 2cc) ────────────────────────
    function FiltersModal(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        var editingState = useState(null); var editing = editingState[0], setEditing = editingState[1];

        function reload() {
            setState({ loading: true, items: state.items, err: null });
            restGet('filters').then(function (d) {
                setState({ loading: false, items: d.items || [], err: null });
            }).catch(function (e) { setState({ loading: false, items: [], err: e.message || 'load failed' }); });
        }
        useEffect(reload, []);

        function blankFilter() {
            return {
                id: 0,
                name: '',
                enabled: true,
                conditions: [{ field: 'from', op: 'contains', value: '' }],
                actions: [{ type: 'label', value: '' }],
            };
        }
        function startNew()         { setEditing(blankFilter()); }
        function edit(f) {
            setEditing({
                id: Number(f.id),
                name: f.name || '',
                enabled: Number(f.enabled) === 1,
                conditions: (f.conditions && f.conditions.length) ? f.conditions : [{ field: 'from', op: 'contains', value: '' }],
                actions:    (f.actions    && f.actions.length)    ? f.actions    : [{ type: 'label', value: '' }],
            });
        }
        function saveEditing() {
            var payload = {
                name: editing.name,
                enabled: editing.enabled ? 1 : 0,
                conditions: editing.conditions.filter(function (c) { return c.value !== ''; }),
                actions:    editing.actions.filter(function (a) { return a.type === 'label' || a.type === 'forward' ? a.value !== '' : true; }),
            };
            var url = editing.id > 0 ? 'filters/' + editing.id : 'filters';
            apiFetch({ url: cfg.restRoot + url, method: editing.id > 0 ? 'PUT' : 'POST', data: payload })
                .then(function () { setEditing(null); reload(); })
                .catch(function (e) { alert((e && e.message) || 'save failed'); });
        }
        function remove(id) {
            if (! window.confirm('Delete this filter?')) return;
            apiFetch({ url: cfg.restRoot + 'filters/' + id, method: 'DELETE' }).then(reload);
        }
        function toggleEnabled(f) {
            apiFetch({
                url: cfg.restRoot + 'filters/' + f.id, method: 'PUT',
                data: { name: f.name, enabled: Number(f.enabled) === 1 ? 0 : 1, conditions: f.conditions || [], actions: f.actions || [] }
            }).then(reload);
        }

        var labels = props.labels || [];

        if (editing) {
            return html`
              <${Modal} title=${editing.id > 0 ? 'Edit filter' : 'New filter'} onRequestClose=${function () { setEditing(null); }} className="em-inbox-filters-modal">
                <${TextControl} label="Name" value=${editing.name} onChange=${function (v) { setEditing(Object.assign({}, editing, { name: v })); }} __nextHasNoMarginBottom=${true} />
                <label class="em-inbox-track-toggle">
                  <input type="checkbox" checked=${!!editing.enabled} onChange=${function (e) { setEditing(Object.assign({}, editing, { enabled: e.target.checked })); }} />
                  <span>Enabled</span>
                </label>
                <h3 class="em-inbox-filters-h3">When ALL of these match</h3>
                ${editing.conditions.map(function (c, i) {
                    return html`
                      <div class="em-inbox-filters-row" key=${'c'+i}>
                        <select value=${c.field} onChange=${function (e) {
                            var next = editing.conditions.slice(); next[i] = Object.assign({}, next[i], { field: e.target.value });
                            setEditing(Object.assign({}, editing, { conditions: next }));
                        }}>
                          <option value="from">From</option>
                          <option value="to">To</option>
                          <option value="subject">Subject</option>
                          <option value="body">Body</option>
                          <option value="any">Any of above</option>
                        </select>
                        <select value=${c.op} onChange=${function (e) {
                            var next = editing.conditions.slice(); next[i] = Object.assign({}, next[i], { op: e.target.value });
                            setEditing(Object.assign({}, editing, { conditions: next }));
                        }}>
                          <option value="contains">contains</option>
                          <option value="equals">equals</option>
                          <option value="starts_with">starts with</option>
                          <option value="ends_with">ends with</option>
                          <option value="matches">matches regex</option>
                        </select>
                        <input type="text" value=${c.value} placeholder="value" onChange=${function (e) {
                            var next = editing.conditions.slice(); next[i] = Object.assign({}, next[i], { value: e.target.value });
                            setEditing(Object.assign({}, editing, { conditions: next }));
                        }} />
                        <button type="button" class="em-inbox-row-rm" aria-label="Remove row" onClick=${function () {
                            var next = editing.conditions.slice(); next.splice(i, 1);
                            if (! next.length) next.push({ field: 'from', op: 'contains', value: '' });
                            setEditing(Object.assign({}, editing, { conditions: next }));
                        }}>×</button>
                      </div>
                    `;
                })}
                <button type="button" class="em-inbox-row-add" onClick=${function () {
                    setEditing(Object.assign({}, editing, { conditions: editing.conditions.concat([{ field: 'from', op: 'contains', value: '' }]) }));
                }}>+ Add condition</button>
                <h3 class="em-inbox-filters-h3">Do this</h3>
                ${editing.actions.map(function (a, i) {
                    return html`
                      <div class="em-inbox-filters-row" key=${'a'+i}>
                        <select value=${a.type} onChange=${function (e) {
                            var next = editing.actions.slice(); next[i] = { type: e.target.value, value: '' };
                            setEditing(Object.assign({}, editing, { actions: next }));
                        }}>
                          <option value="label">Apply label</option>
                          <option value="archive">Auto-archive</option>
                          <option value="trash">Move to trash</option>
                          <option value="star">Star</option>
                          <option value="read">Mark read</option>
                          <option value="forward">Forward to…</option>
                        </select>
                        ${a.type === 'label' ? html`
                          <select value=${a.value} onChange=${function (e) {
                              var next = editing.actions.slice(); next[i] = Object.assign({}, next[i], { value: e.target.value });
                              setEditing(Object.assign({}, editing, { actions: next }));
                          }}>
                            <option value="">(pick label)</option>
                            ${labels.map(function (l) { return html`<option key=${l.id} value=${l.id}>${l.name}</option>`; })}
                          </select>
                        ` : a.type === 'forward' ? html`
                          <input type="email" value=${a.value} placeholder="forward@example.com" onChange=${function (e) {
                              var next = editing.actions.slice(); next[i] = Object.assign({}, next[i], { value: e.target.value });
                              setEditing(Object.assign({}, editing, { actions: next }));
                          }} />
                        ` : html`<span class="em-inbox-filters-noval">(no parameter)</span>`}
                        <button type="button" class="em-inbox-row-rm" aria-label="Remove row" onClick=${function () {
                            var next = editing.actions.slice(); next.splice(i, 1);
                            if (! next.length) next.push({ type: 'label', value: '' });
                            setEditing(Object.assign({}, editing, { actions: next }));
                        }}>×</button>
                      </div>
                    `;
                })}
                <button type="button" class="em-inbox-row-add" onClick=${function () {
                    setEditing(Object.assign({}, editing, { actions: editing.actions.concat([{ type: 'archive', value: '' }]) }));
                }}>+ Add action</button>
                <div class="em-inbox-composer-actions">
                  <${Button} variant="tertiary" onClick=${function () { setEditing(null); }}>Cancel<//>
                  <${Button} variant="primary"  onClick=${saveEditing} disabled=${! editing.name.trim()}>Save<//>
                </div>
              <//>
            `;
        }

        return html`
          <${Modal} title="Filter rules" onRequestClose=${props.onClose} className="em-inbox-filters-modal">
            ${state.loading ? html`<${Spinner} />`
              : state.err   ? html`<${Notice} status="error" isDismissible=${false}>${state.err}<//>`
              : state.items.length === 0 ? html`<p class="em-inbox-empty">No filters yet. Create one to auto-label, archive, or forward incoming messages.</p>`
              : html`
                <ul class="em-inbox-filters-list">
                  ${state.items.map(function (f) {
                      var summary = (f.conditions || []).map(function (c) { return c.field + ' ' + c.op + ' "' + c.value + '"'; }).join(' AND ');
                      var actsum = (f.actions || []).map(function (a) {
                          if (a.type === 'label')   { var l = labels.filter(function (x) { return Number(x.id) === Number(a.value); })[0]; return 'label: ' + (l ? l.name : a.value); }
                          if (a.type === 'forward') return 'forward: ' + a.value;
                          return a.type;
                      }).join(', ');
                      return html`
                        <li key=${f.id} class="em-inbox-filters-row-item">
                          <label class="em-inbox-filters-toggle">
                            <input type="checkbox" checked=${Number(f.enabled) === 1} onChange=${function () { toggleEnabled(f); }} />
                          </label>
                          <div class="em-inbox-filters-meta">
                            <div class="em-inbox-filters-name">${f.name}</div>
                            <div class="em-inbox-filters-cond">${summary || '(no conditions)'}</div>
                            <div class="em-inbox-filters-acts">→ ${actsum || '(no actions)'}</div>
                            <div class="em-inbox-filters-stats">matched ${f.match_count || 0}× · ${f.last_matched_at ? 'last: ' + formatDate(f.last_matched_at) : 'never'}</div>
                          </div>
                          <button type="button" class="components-button is-tertiary" onClick=${function () { edit(f); }}>Edit</button>
                          <button type="button" class="components-button is-tertiary em-inbox-filters-rm" onClick=${function () { remove(f.id); }}>×</button>
                        </li>
                      `;
                  })}
                </ul>
              `}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose}>Close<//>
              <${Button} variant="primary"  onClick=${startNew}>+ New filter<//>
            </div>
          <//>
        `;
    }

    function App() {
        var inboxState = useState([]);            var inboxes = inboxState[0], setInboxes = inboxState[1];
        var selectedState = useState('');         var selected = selectedState[0], setSelected = selectedState[1];
        var threadState = useState(null);         var openThreadId = threadState[0], setOpenThreadId = threadState[1];
        var loadingState = useState(true);        var loading = loadingState[0], setLoading = loadingState[1];
        var errState = useState(null);            var err = errState[0], setErr = errState[1];
        var composerState = useState(null);       var composerProps = composerState[0], setComposerProps = composerState[1];
        var undoState = useState(null);           var undoSnack = undoState[0], setUndoSnack = undoState[1];
        var searchQState = useState('');          var searchQ = searchQState[0], setSearchQ = searchQState[1];
        var labelFilterState = useState(0);       var labelFilterId = labelFilterState[0], setLabelFilterId = labelFilterState[1];
        var labelsState = useState([]);           var labels = labelsState[0], setLabels = labelsState[1];
        var manageLabelsState = useState(false);  var showManageLabels = manageLabelsState[0], setShowManageLabels = manageLabelsState[1];
        var helpState = useState(false);          var showHelp = helpState[0], setShowHelp = helpState[1];
        var vacationState = useState(false);      var showVacation = vacationState[0], setShowVacation = vacationState[1];
        var vacationCfgState = useState(null);    var vacationCfg = vacationCfgState[0], setVacationCfg = vacationCfgState[1];
        useEffect(function () { restGet('vacation').then(setVacationCfg); }, [tick]);
        var filtersState = useState(false);       var showFilters = filtersState[0], setShowFilters = filtersState[1];
        var sharingState = useState(false);       var showSharing = sharingState[0], setShowSharing = sharingState[1];
        var addInboxState = useState(false);      var showAddInbox = addInboxState[0], setShowAddInbox = addInboxState[1];
        // Slice 2uu: lift filter + counts + other-party email so the
        // left rail (filters or customer card) and the feed view share
        // them. Other-party email is derived by ThreadView from the
        // thread's messages and pushed up via onOtherParty.
        var filterState  = useState('all');       var filter = filterState[0], setFilter = filterState[1];
        var countsState  = useState(null);        var counts = countsState[0], setCounts = countsState[1];
        var otherEmailState = useState(null);     var otherPartyEmail = otherEmailState[0], setOtherPartyEmail = otherEmailState[1];
        // Slice 2hh: list of addresses the user can send AS (own inbox
        // + every read_send grant). Refreshed when the SharingModal
        // closes or tick bumps. Used by Composer to render the From
        // dropdown when there's more than one option.
        var fromsState = useState([]);            var availableFroms = fromsState[0], setAvailableFroms = fromsState[1];
        useEffect(function () {
            restGet('grants').then(function (d) {
                var list = [];
                // Own address is always available — derive from cfg
                // (server-injected current-user email).
                if (cfg.currentUserEmail) list.push({ address: cfg.currentUserEmail, label: cfg.currentUserEmail + ' (me)', self: true });
                (d.received || []).forEach(function (g) {
                    if (g.scope === 'read_send' && g.owner_email) {
                        list.push({ address: g.owner_email, label: g.owner_email + ' (delegated)', self: false });
                    }
                });
                setAvailableFroms(list);
            }).catch(function () { /* non-fatal */ });
        }, [tick]);
        var focusedThreadState = useState(null);  var focusedThreadId = focusedThreadState[0], setFocusedThreadId = focusedThreadState[1];
        // searchInputRef so '/' can focus the search bar.
        var searchInputRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        // Refresh counter — bumped after a successful send so the feed
        // re-fetches and the new sent message shows up immediately.
        var refreshTick = useState(0);            var tick = refreshTick[0], bumpTick = refreshTick[1];

        function reloadLabels() {
            restGet('labels').then(function (rows) { setLabels(rows || []); });
        }
        useEffect(function () { reloadLabels(); }, [tick]);

        // ── slice 2x: keyboard shortcuts ──────────────────────────
        // Slice 2jj: tiny state machine for "g + letter" two-key
        // shortcuts (gi=inbox, gu=unread, gs=snoozed, gt=trash, gz=archive).
        // gPendingRef.current holds the timestamp when 'g' was last
        // pressed; expires after 1.5s.
        var gPendingRef = wp.element.useRef ? wp.element.useRef(0) : { current: 0 };
        useEffect(function () {
            function clickByKey(key) {
                // Click whichever action button (Reply / Reply-all /
                // Forward) is currently in the DOM. Uses
                // data-em-key="<key>" attribute on the button.
                var el = document.querySelector('[data-em-key="' + key + '"]');
                if (el && typeof el.click === 'function') { el.click(); return true; }
                return false;
            }
            function onKey(e) {
                // Escape ALWAYS works (close help / composer / overlays).
                if (e.key === 'Escape') {
                    if (showHelp) { setShowHelp(false); return; }
                    if (composerProps) { setComposerProps(null); return; }
                    if (showManageLabels) { setShowManageLabels(false); return; }
                    if (showSharing) { setShowSharing(false); return; }
                    if (showFilters) { setShowFilters(false); return; }
                    if (openThreadId) { setOpenThreadId(null); return; }
                }
                if (isTypingTarget(e.target)) return;
                // Don't fire when ctrl/meta/alt — let browser shortcuts work.
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                var k = e.key;

                // Two-key "g + letter" sequence (Gmail-style).
                if (Date.now() - gPendingRef.current < 1500) {
                    gPendingRef.current = 0;  // consume
                    if (k === 'i') { /* go to inbox */ window.dispatchEvent(new CustomEvent('em-inbox-filter', { detail: 'all' })); e.preventDefault(); return; }
                    if (k === 'u') { window.dispatchEvent(new CustomEvent('em-inbox-filter', { detail: 'unread' })); e.preventDefault(); return; }
                    if (k === 's') { window.dispatchEvent(new CustomEvent('em-inbox-filter', { detail: 'snoozed' })); e.preventDefault(); return; }
                    if (k === 't') { window.dispatchEvent(new CustomEvent('em-inbox-filter', { detail: 'trashed' })); e.preventDefault(); return; }
                    if (k === 'z') { window.dispatchEvent(new CustomEvent('em-inbox-filter', { detail: 'archived' })); e.preventDefault(); return; }
                    // unrecognized — fall through to single-key handling
                }
                if (k === 'g') { gPendingRef.current = Date.now(); e.preventDefault(); return; }

                if (k === '?')     { setShowHelp(true); e.preventDefault(); return; }
                if (k === 'c')     { setComposerProps({ from: selected, mode: 'new' }); e.preventDefault(); return; }
                if (k === '/')     { if (searchInputRef.current) { searchInputRef.current.focus(); e.preventDefault(); } return; }
                if (k === 'u')     { setOpenThreadId(null); e.preventDefault(); return; }
                // Thread-scoped actions need a focused or open thread.
                var actId = openThreadId || focusedThreadId;
                if (!actId) {
                    return;
                }
                if (k === 'Enter') { setOpenThreadId(focusedThreadId || actId); e.preventDefault(); return; }
                if (k === 'e')     { restPost('threads/' + actId + '/archive', {}).then(function () { setOpenThreadId(null); bumpTick(tick + 1); }); e.preventDefault(); return; }
                if (k === '#')     { restPost('threads/' + actId + '/trash',   {}).then(function () { setOpenThreadId(null); bumpTick(tick + 1); }); e.preventDefault(); return; }
                if (k === 's')     { restPost('threads/' + actId + '/star',    {}).then(function () { bumpTick(tick + 1); }); e.preventDefault(); return; }
                // Slice 2jj: r/a/f only work when a thread is open in
                // the reader (the buttons need to be in the DOM).
                if (openThreadId) {
                    if (k === 'r') { if (clickByKey('reply'))     { e.preventDefault(); } return; }
                    if (k === 'a') { if (clickByKey('reply-all')) { e.preventDefault(); } return; }
                    if (k === 'f') { if (clickByKey('forward'))   { e.preventDefault(); } return; }
                }
            }
            window.addEventListener('keydown', onKey);
            return function () { window.removeEventListener('keydown', onKey); };
        }, [openThreadId, focusedThreadId, composerProps, showHelp, showManageLabels, showSharing, showFilters, selected, tick]);

        useEffect(function () {
            setLoading(true);
            restGet('inboxes').then(function (rows) {
                setInboxes(rows || []);
                if (rows && rows.length && !selected) setSelected(rows[0].inbox_address);
                setLoading(false);
            }).catch(function (e) {
                setErr(e.message || 'Failed to load inboxes');
                setLoading(false);
            });
        }, []);

        if (loading) return html`<${Spinner} />`;
        if (err)     return html`<${Notice} status="error" isDismissible=${false}>${err}<//>`;
        if (!inboxes.length) {
            return html`<${Notice} status="info" isDismissible=${false}>No inboxes yet. Inbound mail at any provisioned address will appear here.<//>`;
        }

        var inboxOptions = inboxes.map(function (r) {
            var label = r.inbox_address;
            if (r.shared) label += ' (delegated)';
            if (r.unread_count !== null && r.unread_count !== undefined && Number(r.unread_count) > 0) {
                label += ' · ' + r.unread_count + ' unread';
            } else {
                label += ' (' + r.thread_count + ')';
            }
            return { value: r.inbox_address, label: label };
        });
        // Slice 2oo: prepend an "All inboxes" option when there's more
        // than one to merge. Selecting it passes inbox='*' to /threads.
        if (inboxes.length > 1) {
            inboxOptions = [{ value: '*', label: '— All inboxes —' }].concat(inboxOptions);
        }

        return html`
          <div class="em-inbox">
            <div class="em-inbox-toolbar">
              <${SelectControl}
                label="Inbox"
                value=${selected}
                options=${inboxOptions}
                onChange=${function (v) { setSelected(v); setOpenThreadId(null); setSearchQ(''); setOtherPartyEmail(null); }}
              />
              <${Button}
                variant="primary"
                onClick=${function () { setComposerProps({ from: selected, mode: 'new' }); }}
              >Compose<//>
              <button
                type="button"
                class="em-inbox-help-btn"
                title="Keyboard shortcuts (?)"
                onClick=${function () { setShowHelp(true); }}
              >?</button>
              <button
                type="button"
                class="em-inbox-vac-btn ${vacationCfg && vacationCfg.enabled ? 'is-active' : ''}"
                title=${vacationCfg && vacationCfg.enabled ? 'Vacation responder ON' : 'Vacation responder'}
                onClick=${function () { setShowVacation(true); }}
              >${vacationCfg && vacationCfg.enabled ? '🌴 ON' : '🌴'}</button>
              <button
                type="button"
                class="em-inbox-filters-btn"
                title="Filter rules"
                onClick=${function () { setShowFilters(true); }}
              >⚙ Filters</button>
              <button
                type="button"
                class="em-inbox-filters-btn"
                title="Sharing — grant or revoke inbox access"
                onClick=${function () { setShowSharing(true); }}
              >👥 Sharing</button>
              ${cfg.isAdmin && html`<button
                type="button"
                class="em-inbox-addinbox-btn"
                title="Add a new inbox (admin only)"
                onClick=${function () { setShowAddInbox(true); }}
              >+ Inbox</button>`}
              ${selected && html`<${NotificationBell}
                inbox=${selected}
                onClick=${function () { /* clicking the bell switches to unread view */
                    /* The FeedView filter state is internal; bumpTick to force re-render so
                       the bell re-pulls. The actual filter switch is just a hint — the
                       user still has the explicit filter tabs. */
                    bumpTick(tick + 1);
                }} />`}
            </div>
            <div class="em-inbox-body em-inbox-body--three-col">
              <${LeftRail}
                searchQ=${searchQ}
                onSearchQChange=${function (v) { setSearchQ(v); if (openThreadId) setOpenThreadId(null); }}
                searchInputRef=${searchInputRef}
                filter=${filter}
                onFilterChange=${function (k) { setFilter(k); }}
                counts=${counts}
                openThreadId=${openThreadId}
                otherPartyEmail=${otherPartyEmail}
                labels=${labels}
                labelFilterId=${labelFilterId}
                onLabelFilter=${setLabelFilterId}
                onManageLabels=${function () { setShowManageLabels(true); }} />
              ${openThreadId
                ? html`<${ThreadView}
                    threadId=${openThreadId}
                    labels=${labels}
                    onBack=${function () { setOpenThreadId(null); setOtherPartyEmail(null); }}
                    onOtherParty=${setOtherPartyEmail}
                    onReply=${function (thread, lastMsg) {
                        setComposerProps({
                            from: thread.inbox_address,
                            mode: 'reply',
                            threadId: thread.id,
                            to: lastMsg && lastMsg.sender ? [lastMsg.sender] : [],
                            subject: thread.subject_first ? ('Re: ' + thread.subject_first.replace(/^\s*Re:\s*/i, '')) : '',
                        });
                    }}
                    onReplyAll=${function (thread, lastMsg) {
                        // Reply-All: keep everyone on the conversation except
                        // the inbox owner themselves. Parse To/Cc from the
                        // latest message's headers and merge.
                        var hdrs = (lastMsg && lastMsg.headers) || [];
                        function pickAddrs(name) {
                            for (var i = 0; i < hdrs.length; i++) {
                                if (hdrs[i].name && hdrs[i].name.toLowerCase() === name) {
                                    return String(hdrs[i].value || '').split(/[,;]/)
                                        .map(function (s) { var m = s.match(/<([^>]+)>/); return (m ? m[1] : s).trim(); })
                                        .filter(Boolean);
                                }
                            }
                            return [];
                        }
                        var own = (thread.inbox_address || '').toLowerCase();
                        var to_addrs = ((lastMsg && lastMsg.sender) ? [lastMsg.sender] : []).concat(pickAddrs('to'));
                        var cc_addrs = pickAddrs('cc');
                        to_addrs = to_addrs.filter(function (a, i) { return a && a.toLowerCase() !== own && to_addrs.indexOf(a) === i; });
                        cc_addrs = cc_addrs.filter(function (a, i) { return a && a.toLowerCase() !== own && cc_addrs.indexOf(a) === i; });
                        setComposerProps({
                            from: thread.inbox_address,
                            mode: 'reply',
                            threadId: thread.id,
                            to: to_addrs,
                            cc: cc_addrs,
                            subject: thread.subject_first ? ('Re: ' + thread.subject_first.replace(/^\s*Re:\s*/i, '')) : '',
                        });
                    }}
                    onForward=${function (thread, lastMsg) {
                        // Pre-quote the latest message's content, attribution-stamped.
                        var attr = '';
                        if (lastMsg) {
                            attr = '<br><br><blockquote style="margin:0 0 0 12px;padding-left:12px;border-left:3px solid #c3c4c7;">' +
                                   '<p style="color:#646970;">On ' + (lastMsg.received_at || '?') + ' UTC, ' +
                                   (lastMsg.sender || '?').replace(/[<>&]/g, '') + ' wrote:</p>' +
                                   (lastMsg.body_html ? lastMsg.body_html : ('<pre>' + (lastMsg.body_plain || '') + '</pre>')) +
                                   '</blockquote>';
                        }
                        setComposerProps({
                            from: thread.inbox_address,
                            mode: 'forward',
                            // NOTE: forward intentionally does NOT pass threadId — a forward starts a new thread
                            to: [],
                            subject: thread.subject_first ? ('Fwd: ' + thread.subject_first.replace(/^\s*(Fwd?|Re):\s*/i, '')) : '',
                            bodyHtml: attr,
                            atts: (lastMsg && lastMsg.attachments && lastMsg.attachments.length)
                                ? lastMsg.attachments.map(function (a) {
                                    // Forwarded attachments: we have filename + content_type + size but
                                    // NOT the bytes. For MVP 2u, forward-with-attachments would require
                                    // server-side fetch of the original; punt to 2u.1 with a UI note.
                                    return { filename: a.filename, content_type: a.content_type, size: a.size, content_b64: null };
                                }).filter(function (a) { return false; })  // strip forwarded atts for MVP
                                : [],
                        });
                    }}
                    onArchived=${function () { setOpenThreadId(null); bumpTick(tick + 1); }}
                    onMarkedUnread=${function () { setOpenThreadId(null); bumpTick(tick + 1); }}
                    onLoaded=${function () { bumpTick(tick + 1); }}
                    refreshKey=${tick} />`
                : searchQ.length >= 2
                ? html`<${SearchResults}
                    query=${searchQ}
                    inbox=${selected}
                    openThreadId=${openThreadId}
                    onOpenThread=${setOpenThreadId} />`
                : html`<${FeedView}
                    inbox=${selected}
                    openThreadId=${openThreadId}
                    labelFilterId=${labelFilterId}
                    labels=${labels}
                    onLabelFilter=${setLabelFilterId}
                    onManageLabels=${function () { setShowManageLabels(true); }}
                    onOpenThread=${setOpenThreadId}
                    hideHeader=${true}
                    filter=${filter}
                    onFilterChange=${setFilter}
                    onCountsChange=${setCounts}
                    onBulkApplied=${function () { bumpTick(tick + 1); }}
                    onOpenDraft=${function (d) {
                        setComposerProps({
                            draftId: d.id,
                            from: d.from_address || selected,
                            mode: d.thread_id ? 'reply' : 'new',
                            threadId: d.thread_id || undefined,
                            to: d.to || [],
                            cc: d.cc || [],
                            bcc: d.bcc || [],
                            subject: d.subject || '',
                            bodyHtml: d.body_html || '',
                            atts: d.attachments || [],
                        });
                    }}
                    focusedThreadId=${focusedThreadId}
                    onFocusedChange=${setFocusedThreadId}
                    refreshKey=${tick} />`}
            </div>
            ${composerProps && html`
              <${Composer}
                initial=${composerProps}
                availableFroms=${availableFroms}
                onClose=${function () { setComposerProps(null); }}
                onSent=${function (res) {
                    setComposerProps(null);
                    bumpTick(tick + 1);
                    // Slice 2y: if the send was deferred (pending),
                    // surface the undo snackbar with a countdown.
                    if (res && res.delivery_status === 'pending' && res.raw_id && res.undo_seconds > 0) {
                        setUndoSnack({ rawId: res.raw_id, deadline: Date.now() + res.undo_seconds * 1000 });
                    }
                }} />
            `}
            ${undoSnack && html`<${UndoSnack}
              state=${undoSnack}
              onExpired=${function () { setUndoSnack(null); }}
              onUndone=${function () { setUndoSnack(null); bumpTick(tick + 1); }} />`}
            ${showManageLabels && html`
              <${ManageLabels}
                labels=${labels}
                onClose=${function () { setShowManageLabels(false); }}
                onChanged=${function () { reloadLabels(); bumpTick(tick + 1); }} />
            `}
            ${showHelp && html`<${ShortcutHelpOverlay} onClose=${function () { setShowHelp(false); }} />`}
            ${showVacation && html`<${VacationModal} onClose=${function () { setShowVacation(false); }} onChanged=${setVacationCfg} />`}
            ${showFilters && html`<${FiltersModal} labels=${labels} onClose=${function () { setShowFilters(false); }} />`}
            ${showSharing && html`<${SharingModal} onClose=${function () { setShowSharing(false); bumpTick(tick + 1); }} />`}
            ${showAddInbox && html`<${AddInboxModal} onClose=${function () { setShowAddInbox(false); }} onCreated=${function () { setShowAddInbox(false); bumpTick(tick + 1); }} />`}
          </div>
        `;
    }

    function ManageLabels(props) {
        var nameState  = useState(''); var name = nameState[0], setName = nameState[1];
        var busyState  = useState(false); var busy = busyState[0], setBusy = busyState[1];
        var errState   = useState(null); var err = errState[0], setErr = errState[1];
        function create() {
            if (!name.trim()) return;
            setBusy(true); setErr(null);
            restPost('labels', { name: name.trim() })
                .then(function () { setName(''); setBusy(false); props.onChanged && props.onChanged(); })
                .catch(function (e) { setErr((e && e.message) || 'Create failed'); setBusy(false); });
        }
        function remove(id) {
            if (! window.confirm('Delete this label? Threads will lose this label.')) return;
            apiFetch({ url: cfg.restRoot + 'labels/' + id, method: 'DELETE' })
                .then(function () { props.onChanged && props.onChanged(); });
        }
        return html`
          <${Modal} title="Manage labels" onRequestClose=${props.onClose} className="em-inbox-labels-modal">
            <ul class="em-inbox-labels-list">
              ${(props.labels || []).map(function (l) {
                  return html`<li key=${l.id}>
                    <span class="em-inbox-chip" style=${{ backgroundColor: l.color }}>${l.name}</span>
                    <button type="button" class="em-inbox-label-delete" onClick=${function () { remove(l.id); }}>Delete</button>
                  </li>`;
              })}
              ${(props.labels || []).length === 0 && html`<li class="em-inbox-empty">No labels yet.</li>`}
            </ul>
            <div class="em-inbox-label-create">
              <${TextControl} label="New label name" value=${name} onChange=${setName} __nextHasNoMarginBottom=${true} />
              ${err && html`<${Notice} status="error" isDismissible=${false}>${err}<//>`}
              <${Button} variant="primary" disabled=${busy || !name.trim()} onClick=${create}>${busy ? html`<${Spinner} />` : 'Add'}<//>
            </div>
          <//>
        `;
    }

    function ThreadLabelPicker(props) {
        // Inline popover under the thread header: list user's labels with
        // checkboxes, save replaces the full set.
        var openState = useState(false); var open = openState[0], setOpen = openState[1];
        var selected = (props.threadLabels || []).map(function (l) { return Number(l.id); });
        function toggle(id) {
            var next = selected.indexOf(id) >= 0
                ? selected.filter(function (n) { return n !== id; })
                : selected.concat([id]);
            apiFetch({ url: cfg.restRoot + 'threads/' + props.threadId + '/labels', method: 'PUT', data: { label_ids: next } })
                .then(function (res) { props.onChanged && props.onChanged(res); });
        }
        if (!props.labels || props.labels.length === 0) return null;
        return html`
          <div class="em-inbox-label-picker">
            <button type="button" class="em-inbox-label-picker-btn" onClick=${function () { setOpen(!open); }}>
              ${(props.threadLabels || []).length > 0 ? (props.threadLabels.length + ' label' + (props.threadLabels.length === 1 ? '' : 's')) : 'Add labels'}
            </button>
            ${open && html`
              <div class="em-inbox-label-picker-menu">
                ${props.labels.map(function (l) {
                    return html`<label key=${l.id} class="em-inbox-label-picker-row">
                      <input type="checkbox" checked=${selected.indexOf(Number(l.id)) >= 0} onChange=${function () { toggle(Number(l.id)); }} />
                      <span class="em-inbox-chip" style=${{ backgroundColor: l.color }}>${l.name}</span>
                    </label>`;
                })}
              </div>
            `}
          </div>
        `;
    }

    function SnoozeButton(props) {
        var openState = useState(false); var open = openState[0], setOpen = openState[1];
        var snoozed = !!props.snoozedUntil;
        function snoozeUntil(date) {
            apiFetch({ url: cfg.restRoot + 'threads/' + props.threadId + '/snooze', method: 'POST', data: { until: date.toISOString() } })
                .then(function () { setOpen(false); props.onChanged && props.onChanged(); });
        }
        function unsnooze() {
            apiFetch({ url: cfg.restRoot + 'threads/' + props.threadId + '/unsnooze', method: 'POST', data: {} })
                .then(function () { setOpen(false); props.onChanged && props.onChanged(); });
        }
        var now = new Date();
        var presets = [
            ['In 1 hour',  new Date(now.getTime() + 60 * 60 * 1000)],
            ['Tomorrow 9am', (function () { var d = new Date(now); d.setDate(d.getDate() + 1); d.setHours(9,0,0,0); return d; })()],
            ['Next week',  (function () { var d = new Date(now); d.setDate(d.getDate() + 7); d.setHours(9,0,0,0); return d; })()],
        ];
        return html`
          <div class="em-inbox-snooze-wrap">
            <button type="button" class="components-button is-tertiary" onClick=${function () { setOpen(!open); }}>
              ${snoozed ? '⏰ Snoozed' : 'Snooze'}
            </button>
            ${open && html`
              <div class="em-inbox-snooze-menu">
                ${presets.map(function (p, i) {
                    return html`<button key=${i} type="button" onClick=${function () { snoozeUntil(p[1]); }}>${p[0]} <span class="em-snooze-when">${em_inbox_fmt_tz(p[1], {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'})}</span></button>`;
                })}
                ${snoozed && html`
                  <button type="button" class="em-snooze-unsnooze" onClick=${unsnooze}>↺ Unsnooze (was: ${props.snoozedUntil} UTC)</button>
                `}
              </div>
            `}
          </div>
        `;
    }

    function ContactTokenField(props) {
        var sugState = useState([]);  var suggestions = sugState[0], setSuggestions = sugState[1];
        var lastFetchRef = wp.element.useRef ? wp.element.useRef('') : { current: '' };
        function onInput(q) {
            // FormTokenField calls onInputChange on every keystroke. Debounce
            // via the last-fetched-query ref so we only hit the server when
            // the partial actually changes meaningfully.
            var clean = String(q || '').trim();
            if (clean === lastFetchRef.current) return;
            lastFetchRef.current = clean;
            if (clean.length < 2 && clean.length !== 0) { setSuggestions([]); return; }
            restGet('contacts' + (clean ? '?q=' + encodeURIComponent(clean) : ''))
                .then(function (rows) {
                    var labels = (rows || []).map(function (r) {
                        return r.display_name ? r.display_name + ' <' + r.email + '>' : r.email;
                    });
                    setSuggestions(labels);
                })
                .catch(function () { setSuggestions([]); });
        }
        // Trigger once on mount so the dropdown has data before the user types.
        useEffect(function () { onInput(''); }, []);
        return html`
          <${FormTokenField}
            label=${props.label}
            __experimentalExpandOnFocus=${true}
            value=${props.value}
            suggestions=${suggestions}
            onChange=${props.onChange}
            onInputChange=${onInput}
            placeholder="Add recipients…"
            __next40pxDefaultSize=${true}
          />
          ${props.help && html`<p class="components-form-token-field__help">${props.help}</p>`}
        `;
    }

    function SignatureEditor(props) {
        var st = useState({ loading: true, html: '', err: null, savedAt: 0 });
        var state = st[0], setState = st[1];
        useEffect(function () {
            restGet('signature').then(function (d) {
                setState({ loading: false, html: (d && d.html) || '', err: null, savedAt: 0 });
            }).catch(function (e) { setState({ loading: false, html: '', err: e.message || 'load failed', savedAt: 0 }); });
        }, []);
        function save() {
            setState(Object.assign({}, state, { err: null }));
            restPost('signature', { html: state.html })
                .then(function (d) { setState({ loading: false, html: (d && d.html) || '', err: null, savedAt: Date.now() }); props.onSaved && props.onSaved(); })
                .catch(function (e) { setState(Object.assign({}, state, { err: e.message || 'save failed' })); });
        }
        return html`
          <${Modal} title="Email signature" onRequestClose=${props.onClose} className="em-inbox-sig-modal">
            ${state.loading
              ? html`<${Spinner} />`
              : html`
                <p class="em-inbox-help">Appears at the bottom of every new compose, reply, or forward unless you uncheck "Include signature" before sending.</p>
                <${RichTextEditor} value=${state.html} onChange=${function (v) { setState(Object.assign({}, state, { html: v })); }} />
                ${state.err && html`<${Notice} status="error" isDismissible=${false}>${state.err}<//>`}
                ${state.savedAt > 0 && html`<${Notice} status="success" isDismissible=${false}>Saved.<//>`}
                <div class="em-inbox-composer-actions">
                  <${Button} variant="tertiary" onClick=${props.onClose}>Close<//>
                  <${Button} variant="primary"  onClick=${save}>Save<//>
                </div>
              `}
          <//>
        `;
    }

    function Composer(props) {
        var initial = props.initial || {};
        // Slice 2hh: when the user has delegated read_send grants, the
        // From line becomes a dropdown so they can compose as the
        // delegated owner. Default to initial.from (the inbox they're
        // viewing) if it's in availableFroms, else the first option.
        var availableFroms = Array.isArray(props.availableFroms) ? props.availableFroms : [];
        var defaultFrom = initial.from || (availableFroms[0] && availableFroms[0].address) || '';
        if (availableFroms.length > 0 && ! availableFroms.find(function (f) { return f.address === defaultFrom; })) {
            // initial.from refers to an inbox not in our send-as list — fall back to own.
            var self = availableFroms.find(function (f) { return f.self; });
            defaultFrom = self ? self.address : availableFroms[0].address;
        }
        var fromState = useState(defaultFrom);                     var from = fromState[0], setFrom = fromState[1];
        var toState = useState(initial.to || []);                  var to = toState[0], setTo = toState[1];
        var includeSigState = useState(true);                      var includeSig = includeSigState[0], setIncludeSig = includeSigState[1];
        var sigState = useState('');                               var sig = sigState[0], setSig = sigState[1];
        var sigEditState = useState(false);                        var showSigEdit = sigEditState[0], setShowSigEdit = sigEditState[1];
        // Fetch the user's signature once per Composer mount so we can
        // append it on submit. Storing in state means a "preview" of
        // what's about to ship is also possible later.
        useEffect(function () {
            restGet('signature').then(function (d) { setSig((d && d.html) || ''); });
        }, []);

        // Slice 2kk + 2pp: auto-save to /drafts on idle, write through
        // to IndexedDB so an offline edit isn't lost. Debounced 1500ms.
        var firstDraftRenderRef = wp.element.useRef ? wp.element.useRef(true) : { current: true };
        useEffect(function () {
            if (firstDraftRenderRef.current) { firstDraftRenderRef.current = false; return; }
            if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
            saveTimerRef.current = setTimeout(function () {
                // Skip when there's literally nothing to save.
                if (!subject && !bodyHtml.replace(/<[^>]*>/g, '').trim() && to.length === 0 && cc.length === 0 && bcc.length === 0) return;
                var payload = {
                    from: from,
                    to:   extractEmails(to),
                    cc:   extractEmails(cc),
                    bcc:  extractEmails(bcc),
                    subject: subject,
                    body_plain: htmlToPlain(bodyHtml),
                    body_html:  bodyHtml,
                    thread_id:  initial.threadId || undefined,
                    track_open: track,
                    attachments: atts.map(function (a) { return { filename: a.filename, content_type: a.content_type, content_b64: a.content_b64 }; }),
                };
                // 1) Write through to IDB ALWAYS, even when online — so
                //    if the network blips mid-keystroke nothing is lost.
                var idbRow = {
                    clientId:    clientIdRef.current,
                    serverId:    draftId || null,
                    payload:     payload,
                    pendingSync: !navigator.onLine,
                    savedAt:     Date.now(),
                };
                em_inbox_idb_put_draft(idbRow).catch(function () { /* idb unavailable — fine */ });

                // 2) If online, push to server too.
                if (! navigator.onLine) {
                    setLastSaved(Date.now()); setSaveErr(null);
                    return;
                }
                var url = draftId > 0 ? 'drafts/' + draftId : 'drafts';
                apiFetch({ url: cfg.restRoot + url, method: 'POST', data: payload })
                    .then(function (res) {
                        if (res && res.id) {
                            if (draftId === 0) setDraftId(res.id);
                            // Mark cache row as synced.
                            idbRow.serverId = res.id;
                            idbRow.pendingSync = false;
                            idbRow.lastSyncedAt = Date.now();
                            em_inbox_idb_put_draft(idbRow);
                            setLastSaved(Date.now());
                            setSaveErr(null);
                        }
                    })
                    .catch(function (e) {
                        // Server failed despite navigator.onLine — leave
                        // cache row pendingSync so the next online-event
                        // tick retries.
                        idbRow.pendingSync = true;
                        em_inbox_idb_put_draft(idbRow);
                        setSaveErr((e && e.message) || 'autosave failed');
                    });
            }, 1500);
            return function () { if (saveTimerRef.current) clearTimeout(saveTimerRef.current); };
        }, [from, to, cc, bcc, subject, bodyHtml, track, atts.length, online]);
        var ccState = useState(initial.cc || []);                  var cc = ccState[0], setCc = ccState[1];
        var bccState = useState(initial.bcc || []);                var bcc = bccState[0], setBcc = bccState[1];
        var showCcBccState = useState(!!(initial.cc && initial.cc.length) || !!(initial.bcc && initial.bcc.length));
        var showCcBcc = showCcBccState[0], setShowCcBcc = showCcBccState[1];
        var subjState = useState(initial.subject || '');           var subject = subjState[0], setSubject = subjState[1];
        var bodyHtmlState = useState(initial.bodyHtml || '');      var bodyHtml = bodyHtmlState[0], setBodyHtml = bodyHtmlState[1];
        var attState = useState(initial.atts || []);               var atts = attState[0], setAtts = attState[1];
        var trackState = useState(false);                          var track = trackState[0], setTrack = trackState[1];
        var sendingState = useState(false);                        var sending = sendingState[0], setSending = sendingState[1];
        var errState = useState(null);                             var err = errState[0], setErr = errState[1];
        var dragState = useState(false);                           var dragging = dragState[0], setDragging = dragState[1];
        // Slice 2kk: draft auto-save state. draftId is the server-side
        // row's PK once we've persisted at least once. lastSavedAt is
        // shown as "Saved Xs ago" in the composer chrome.
        var draftIdState = useState(initial.draftId || 0);         var draftId = draftIdState[0], setDraftId = draftIdState[1];
        var lastSavedState = useState(null);                       var lastSaved = lastSavedState[0], setLastSaved = lastSavedState[1];
        var saveErrState = useState(null);                         var saveErr = saveErrState[0], setSaveErr = saveErrState[1];
        var saveTimerRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        // Slice 2pp: offline cache. Each Composer instance keeps a
        // stable clientId (per-mount uuid) so the IDB row is upsert-able
        // even before the server assigns an id.
        var clientIdRef  = wp.element.useRef ? wp.element.useRef(em_inbox_idb_uuid()) : { current: em_inbox_idb_uuid() };
        var onlineState  = useState(navigator.onLine);             var online = onlineState[0], setOnline = onlineState[1];
        useEffect(function () {
            function on()  { setOnline(true);  em_inbox_flush_pending_drafts(); }
            function off() { setOnline(false); }
            window.addEventListener('online',  on);
            window.addEventListener('offline', off);
            return function () { window.removeEventListener('online', on); window.removeEventListener('offline', off); };
        }, []);
        // Slice 2bb: scheduled send menu state. When sendAtIso is set,
        // the Send button shows the scheduled time + submits send_at to
        // the server. The dropdown is just a popover next to the Send btn.
        var schedMenuState = useState(false);                      var schedMenu = schedMenuState[0], setSchedMenu = schedMenuState[1];
        var sendAtState = useState(null);                          var sendAt = sendAtState[0], setSendAt = sendAtState[1];
        var customSchedState = useState('');                       var customSched = customSchedState[0], setCustomSched = customSchedState[1];

        function onFiles(files) {
            if (!files || !files.length) return;
            var list = atts.slice();
            var pending = files.length;
            Array.prototype.forEach.call(files, function (f) {
                if (f.size > 20 * 1024 * 1024) {
                    setErr('File too large: ' + f.name + ' (' + humanBytes(f.size) + ')');
                    pending--; return;
                }
                var reader = new FileReader();
                reader.onload = function () {
                    var b64 = String(reader.result).split(',', 2)[1] || '';
                    list.push({ filename: f.name, content_type: f.type || 'application/octet-stream', content_b64: b64, size: f.size });
                    pending--;
                    if (pending === 0) setAtts(list);
                };
                reader.readAsDataURL(f);
            });
        }
        function onDragOver(e) { e.preventDefault(); setDragging(true); }
        function onDragLeave(e) { e.preventDefault(); setDragging(false); }
        function onDrop(e) {
            e.preventDefault();
            setDragging(false);
            if (e.dataTransfer && e.dataTransfer.files) onFiles(e.dataTransfer.files);
        }
        function removeAtt(idx) {
            var next = atts.slice(); next.splice(idx, 1); setAtts(next);
        }

        function extractEmails(tokens) {
            return (Array.isArray(tokens) ? tokens : String(tokens || '').split(/[,;]/)).map(function (t) {
                var m = String(t).match(/<([^>]+)>/);
                return (m ? m[1] : String(t)).trim();
            }).filter(Boolean);
        }
        function submit() {
            setSending(true); setErr(null);
            // Append signature to the body unless the user opted out.
            // Signature goes BEFORE any forward/reply quoted content,
            // which conventionally sits at the END of bodyHtml — so we
            // splice it in just before the first <blockquote> if any,
            // otherwise just append.
            var finalHtml = bodyHtml;
            if (includeSig && sig) {
                var sep = '<br><br>--<br>';
                var quoteIdx = finalHtml.toLowerCase().indexOf('<blockquote');
                if (quoteIdx >= 0) {
                    finalHtml = finalHtml.slice(0, quoteIdx) + sep + sig + finalHtml.slice(quoteIdx);
                } else {
                    finalHtml = finalHtml + sep + sig;
                }
            }
            var bodyPlain = htmlToPlain(finalHtml);
            var payload = {
                to:          extractEmails(to),
                cc:          extractEmails(cc),
                bcc:         extractEmails(bcc),
                subject:     subject,
                body_plain:  bodyPlain,
                body_html:   finalHtml,
                thread_id:   initial.threadId || undefined,
                track_open:  track,
                attachments: atts.map(function (a) { return { filename: a.filename, content_type: a.content_type, content_b64: a.content_b64 }; }),
            };
            // Slice 2hh: when the user picks a non-self From, pass
            // from_override. The server enforces the permission check
            // (read_send grant required for non-admins) via
            // em_inbox_current_user_can_send_as.
            var selected = (availableFroms || []).find(function (f) { return f.address === from; });
            if (selected && ! selected.self) {
                payload.from_override = selected.address;
            }
            if (sendAt) {
                payload.send_at = sendAt;
                payload.undo_seconds = 0;
            } else {
                payload.undo_seconds = 10;
            }
            restPost('send', payload).then(function (res) {
                setSending(false);
                // Slice 2kk + 2pp: send succeeded → discard the draft
                // (server + offline cache) so it doesn't linger in the
                // Drafts list as a duplicate.
                if (draftId > 0) {
                    apiFetch({ url: cfg.restRoot + 'drafts/' + draftId, method: 'DELETE' }).catch(function () { /* non-fatal */ });
                }
                em_inbox_idb_delete_draft(clientIdRef.current).catch(function () {});
                props.onSent && props.onSent(res);
            }).catch(function (e) {
                setSending(false);
                setErr((e && e.message) || 'Send failed');
            });
        }

        var title = initial.mode === 'reply'   ? 'Reply'
                  : initial.mode === 'forward' ? 'Forward'
                  : 'New message';
        return html`
          <${Modal} title=${title} onRequestClose=${props.onClose} className="em-inbox-composer-modal">
            <div
              class="em-inbox-composer-dropzone ${dragging ? 'is-dragging' : ''}"
              onDragOver=${onDragOver}
              onDragLeave=${onDragLeave}
              onDrop=${onDrop}>
            <p class="em-inbox-composer-from">
              From: ${availableFroms.length > 1
                ? html`<select class="em-inbox-from-select" value=${from} onChange=${function (e) { setFrom(e.target.value); }}>
                    ${availableFroms.map(function (f) { return html`<option key=${f.address} value=${f.address}>${f.label}</option>`; })}
                  </select>`
                : html`<strong>${from || initial.from || '(no inbox)'}</strong>`}
              ${availableFroms.length > 1 && from && ! (availableFroms.find(function (f) { return f.address === from; }) || {}).self
                ? html`<span class="em-inbox-from-warning"> · sending AS ${from} (audited)</span>`
                : null}
              ${!showCcBcc && html`<button type="button" class="em-inbox-link-btn" onClick=${function () { setShowCcBcc(true); }}>Cc / Bcc</button>`}
            </p>
            <${ContactTokenField}
              label="To"
              help="Type a name or email; pick from suggestions."
              value=${to}
              onChange=${setTo} />
            ${showCcBcc && html`
              <${ContactTokenField} label="Cc"  value=${cc}  onChange=${setCc} />
              <${ContactTokenField} label="Bcc" value=${bcc} onChange=${setBcc} />
            `}
            <${TextControl}
              label="Subject"
              value=${subject}
              onChange=${setSubject}
              __nextHasNoMarginBottom=${true} />
            <label class="em-inbox-rte-label">Message</label>
            <${RichTextEditor} value=${bodyHtml} onChange=${setBodyHtml} />
            <div class="em-inbox-composer-attachments">
              <label class="em-inbox-att-add">
                <input type="file" multiple onChange=${function (e) { onFiles(e.target.files); e.target.value = ''; }} />
                <span>+ Add attachment</span>
              </label>
              ${atts.length > 0 && html`
                <ul class="em-inbox-att-list">
                  ${atts.map(function (a, i) {
                      return html`
                        <li key=${i}>
                          <span class="em-inbox-att-name">${a.filename}</span>
                          <span class="em-inbox-att-size">${humanBytes(a.size)}</span>
                          <button type="button" class="em-inbox-att-remove" aria-label=${'Remove attachment ' + (a.filename || 'unnamed')} onClick=${function () { removeAtt(i); }}><span aria-hidden="true">×</span></button>
                        </li>
                      `;
                  })}
                </ul>
              `}
            </div>
            <label class="em-inbox-track-toggle">
              <input type="checkbox" checked=${track} onChange=${function (e) { setTrack(e.target.checked); }} />
              <span>Track when opened <small>(injects a tiny tracking pixel; recipients with image-blocking won't trigger it)</small></span>
            </label>
            <label class="em-inbox-track-toggle">
              <input type="checkbox" checked=${includeSig} onChange=${function (e) { setIncludeSig(e.target.checked); }} />
              <span>Include signature
                <button type="button" class="em-inbox-link-btn" onClick=${function () { setShowSigEdit(true); }}>${sig ? 'Edit' : 'Set up'}</button>
              </span>
            </label>
            ${showSigEdit && html`<${SignatureEditor}
              onClose=${function () { setShowSigEdit(false); }}
              onSaved=${function () { restGet('signature').then(function (d) { setSig((d && d.html) || ''); }); }} />`}
            ${err && html`<${Notice} status="error" isDismissible=${false}>${err}<//>`}
            ${! online && html`<${Notice} status="warning" isDismissible=${false}>You're offline. Your draft is saved locally and will sync when you're back online.<//>`}
            <div class="em-inbox-composer-actions">
              <span class="em-inbox-draft-status" aria-live="polite" aria-atomic="true">
                ${saveErr ? html`<span class="em-inbox-draft-err">⚠ ${saveErr}</span>`
                 : lastSaved ? html`<span title=${new Date(lastSaved).toLocaleString()}>${online ? '✓ Draft saved' : '✓ Draft cached locally'}</span>`
                 : null}
              </span>
              <${Button} variant="tertiary" onClick=${props.onClose} disabled=${sending}>Cancel<//>
              <div class="em-inbox-send-group">
                <${Button} variant="primary"  onClick=${submit}        disabled=${sending || ((to.length + cc.length + bcc.length) === 0) || !bodyHtml.replace(/<[^>]*>/g,'').trim()}>
                  ${sending ? html`<${Spinner} />` : (sendAt ? 'Schedule send' : 'Send')}
                <//>
                <${Button} variant="primary" className="em-inbox-send-caret" onClick=${function () { setSchedMenu(!schedMenu); }} disabled=${sending} aria-label="Schedule send">▾<//>
                ${schedMenu && html`
                  <div class="em-inbox-sched-menu">
                    ${(function () {
                        // Slice 2mm: presets are computed in the WP-configured
                        // user timezone (cfg.userTimezone), not the browser's.
                        var presets = [];
                        var now = new Date();
                        // Day-of-week in user-tz for the "Monday morning" calc.
                        var dowStr = new Intl.DateTimeFormat('en-US', { timeZone: cfg.userTimezone || 'UTC', weekday: 'short' }).format(now);
                        var dowMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
                        var dow = dowMap[dowStr] || 0;
                        var tomorrowSrc = new Date(now.getTime() + 86400000);
                        var mondayDays = ((1 - dow) + 7) % 7; if (mondayDays === 0) mondayDays = 7;
                        var mondaySrc  = new Date(now.getTime() + mondayDays * 86400000);
                        var tomorrow9   = em_inbox_tz_aware_ts(tomorrowSrc, 9, 0);
                        var monday9     = em_inbox_tz_aware_ts(mondaySrc,   9, 0);
                        // Later-today: 4h from now, rounded to top of hour, in user-tz.
                        var laterSrc = new Date(now.getTime() + 4 * 3600 * 1000);
                        var laterHour = Number(new Intl.DateTimeFormat('en-US', { timeZone: cfg.userTimezone || 'UTC', hour: '2-digit', hour12: false }).format(laterSrc));
                        var laterToday = em_inbox_tz_aware_ts(laterSrc, laterHour % 24, 0);
                        // Include Later-today only if it's still the same calendar day in user-tz and before 22:00.
                        var todayDay = new Intl.DateTimeFormat('en-US', { timeZone: cfg.userTimezone || 'UTC', day: 'numeric' }).format(now);
                        var laterDay = new Intl.DateTimeFormat('en-US', { timeZone: cfg.userTimezone || 'UTC', day: 'numeric' }).format(laterToday);
                        if (todayDay === laterDay && laterHour < 22) {
                            presets.push(['Later today', laterToday]);
                        }
                        presets.push(['Tomorrow morning', tomorrow9]);
                        presets.push(['Monday morning', monday9]);
                        return presets.map(function (p, i) {
                            return html`<button key=${i} type="button" onClick=${function () { setSendAt(p[1].toISOString()); setSchedMenu(false); }}>${p[0]} <span class="em-sched-when">${em_inbox_fmt_tz(p[1], {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit', timeZoneName:'short'})}</span></button>`;
                        });
                    })()}
                    <div class="em-inbox-sched-custom">
                      <label>Pick a date/time:</label>
                      <input type="datetime-local" value=${customSched} onChange=${function (e) { setCustomSched(e.target.value); }} />
                      <button type="button" class="components-button is-secondary" onClick=${function () {
                          if (!customSched) { setErr('Please pick a date/time'); return; }
                          var d = new Date(customSched);
                          if (isNaN(d.getTime()) || d.getTime() <= Date.now()) { setErr('Scheduled time must be in the future'); return; }
                          setSendAt(d.toISOString()); setSchedMenu(false); setErr(null);
                      }}>Set custom</button>
                    </div>
                    ${sendAt && html`
                      <button type="button" class="em-sched-clear" onClick=${function () { setSendAt(null); setSchedMenu(false); }}>↺ Send immediately instead</button>
                    `}
                  </div>
                `}
              </div>
            </div>
            ${sendAt && html`<p class="em-inbox-sched-hint">Will be queued to send at <strong>${em_inbox_fmt_tz(new Date(sendAt), { dateStyle: 'medium', timeStyle: 'short', timeZoneName: 'short' })}</strong>.</p>`}
            ${dragging && html`<div class="em-inbox-drop-overlay">Drop files to attach</div>`}
            </div>
          <//>
        `;
    }

    function SearchResults(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        useEffect(function () {
            setState({ loading: true, items: [], err: null });
            var q = 'search?q=' + encodeURIComponent(props.query);
            if (props.inbox) q += '&inbox=' + encodeURIComponent(props.inbox);
            // Debounce: only fire after 350ms of inactivity.
            var handle = setTimeout(function () {
                restGet(q)
                    .then(function (data) { setState({ loading: false, items: data.items || [], err: null }); })
                    .catch(function (e) { setState({ loading: false, items: [], err: e.message || 'Search failed' }); });
            }, 350);
            return function () { clearTimeout(handle); };
        }, [props.query, props.inbox]);
        if (state.loading) return html`<aside class="em-inbox-feed"><${Spinner} /></aside>`;
        if (state.err)     return html`<aside class="em-inbox-feed"><${Notice} status="error" isDismissible=${false}>${state.err}<//></aside>`;
        if (!state.items.length) return html`<aside class="em-inbox-feed"><p class="em-inbox-empty">No matches for "${props.query}".</p></aside>`;
        return html`
          <aside class="em-inbox-feed">
            <header class="em-inbox-feed-header">${state.items.length} match${state.items.length === 1 ? '' : 'es'} for "${props.query}"</header>
            <ul class="em-inbox-thread-list">
              ${state.items.map(function (m) {
                  var open = props.openThreadId === Number(m.thread_id);
                  return html`
                    <li
                      key=${m.message_id}
                      class="em-inbox-thread-row${open ? ' is-open' : ''}"
                      onClick=${function () { props.onOpenThread(Number(m.thread_id)); }}
                    >
                      <div class="em-inbox-thread-sender">${m.sender || '(no sender)'}</div>
                      <div class="em-inbox-thread-subject">${m.subject || '(no subject)'}</div>
                      <div class="em-inbox-thread-meta">${formatDate(m.received_at)} · ${m.recipient}</div>
                      ${m.snippet && html`<div class="em-inbox-search-snippet" dangerouslySetInnerHTML=${{ __html: m.snippet }}></div>`}
                    </li>
                  `;
              })}
            </ul>
          </aside>
        `;
    }

    function ScheduledList(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        var tickState = useState(0); var tick = tickState[0], setTick = tickState[1];

        function reload() {
            setState({ loading: true, items: state.items, err: null });
            restGet('scheduled')
                .then(function (d) { setState({ loading: false, items: d.items || [], err: null }); })
                .catch(function (e) { setState({ loading: false, items: [], err: e.message || 'Failed to load scheduled' }); });
        }
        useEffect(reload, [props.refreshKey, tick]);

        function cancel(rawId) {
            if (! window.confirm('Cancel this scheduled message? The draft will be discarded.')) return;
            apiFetch({ url: cfg.restRoot + 'messages/' + rawId + '/cancel', method: 'DELETE' })
                .then(function () { setTick(tick + 1); props.onCancelled && props.onCancelled(); });
        }

        if (state.loading) return html`<div class="em-inbox-empty"><${Spinner} /></div>`;
        if (state.err)     return html`<${Notice} status="error" isDismissible=${false}>${state.err}<//>`;
        if (! state.items.length) return html`<p class="em-inbox-empty">No scheduled messages. Compose a message and choose a send time from the Send menu.</p>`;
        return html`
          <ul class="em-inbox-scheduled-list">
            ${state.items.map(function (m) {
                var d = m.send_at ? new Date(m.send_at + 'Z') : null;
                return html`
                  <li key=${m.raw_id} class="em-inbox-scheduled-row">
                    <div class="em-inbox-scheduled-meta">
                      <div class="em-inbox-scheduled-when">${d ? em_inbox_fmt_tz(d, {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit', timeZoneName:'short'}) : '(no time)'}</div>
                      <div class="em-inbox-scheduled-to">To: ${m.to_display || '(no recipient)'}</div>
                      <div class="em-inbox-scheduled-subject">${m.subject || '(no subject)'}</div>
                    </div>
                    <button type="button" class="components-button is-secondary em-inbox-scheduled-cancel" onClick=${function () { cancel(m.raw_id); }}>Cancel</button>
                  </li>
                `;
            })}
          </ul>
        `;
    }

    // ── LeftRail (slice 2uu) — col-1 of the 3-col body. Holds the
    //    search input + (filter pills OR a CustomerCard when a thread
    //    is open). ─────────────────────────────────────────────────────
    function LeftRail(props) {
        var counts = props.counts || {};
        function btn(key, label) {
            return html`<button
                type="button"
                class="em-inbox-filter ${props.filter === key ? 'is-active' : ''}"
                role="tab"
                aria-selected=${props.filter === key ? 'true' : 'false'}
                onClick=${function () { props.onFilterChange && props.onFilterChange(key); }}>${label}</button>`;
        }
        return html`
          <aside class="em-inbox-leftrail" aria-label="Inbox sidebar">
            <div class="em-inbox-leftrail-search">
              <${TextControl}
                label="Search"
                placeholder="Subject, body, sender…   (/)"
                value=${props.searchQ}
                onChange=${function (v) { props.onSearchQChange && props.onSearchQChange(v); }}
                ref=${function (n) {
                    if (n && props.searchInputRef) {
                        props.searchInputRef.current = n.querySelector ? n.querySelector('input') : n;
                    }
                }}
                __nextHasNoMarginBottom=${true}
              />
            </div>
            ${props.openThreadId
              ? html`<${CustomerCard} email=${props.otherPartyEmail} />`
              : html`
                <div class="em-inbox-leftrail-filters">
                  <div class="em-inbox-filters em-inbox-filters--column" role="tablist" aria-label="Inbox filters">
                    ${btn('all',       'All' + (counts.total != null ? ' · ' + counts.total : ''))}
                    ${btn('unread',    'Unread' + (counts.unread != null ? ' · ' + counts.unread : ''))}
                    ${btn('starred',   '★ Starred' + (counts.starred != null ? ' · ' + counts.starred : ''))}
                    ${btn('snoozed',   '⏰ Snoozed' + (counts.snoozed != null ? ' · ' + counts.snoozed : ''))}
                    ${btn('scheduled', '⏱ Scheduled')}
                    ${btn('drafts',    '📝 Drafts')}
                    ${btn('archived',  'Archived' + (counts.archived != null ? ' · ' + counts.archived : ''))}
                    ${btn('trashed',   'Trash' + (counts.trashed != null ? ' · ' + counts.trashed : ''))}
                  </div>
                  ${(props.labels && props.labels.length) || props.onManageLabels
                    ? html`
                      <div class="em-inbox-label-bar em-inbox-label-bar--column">
                        <button type="button"
                          class="em-inbox-label-pill ${props.labelFilterId === 0 ? '' : 'is-muted'}"
                          onClick=${function () { props.onLabelFilter && props.onLabelFilter(0); }}>All labels</button>
                        ${(props.labels || []).map(function (l) {
                          var active = Number(props.labelFilterId) === Number(l.id);
                          return html`<button type="button"
                            key=${l.id}
                            class="em-inbox-label-pill ${active ? 'is-active' : ''}"
                            style=${{ borderColor: l.color, color: active ? '#fff' : l.color, backgroundColor: active ? l.color : 'transparent' }}
                            onClick=${function () { props.onLabelFilter && props.onLabelFilter(active ? 0 : Number(l.id)); }}>${l.name}</button>`;
                        })}
                        <button type="button" class="em-inbox-label-manage" onClick=${props.onManageLabels}>Manage…</button>
                      </div>
                    `
                    : null}
                </div>
              `}
          </aside>
        `;
    }

    // ── CustomerCard (slice 2uu) — fetches /customer-card?email=…
    //    and renders sections. Each section degrades gracefully when
    //    the backing plugin (Woo / contracts / mycred / chat-forms)
    //    isn't installed.
    function CustomerCard(props) {
        var st = useState({ loading: true, data: null, err: null });
        var state = st[0], setState = st[1];
        useEffect(function () {
            if (! props.email) { setState({ loading: false, data: null, err: null }); return; }
            setState({ loading: true, data: state.data, err: null });
            restGet('customer-card?email=' + encodeURIComponent(props.email))
                .then(function (d) { setState({ loading: false, data: d, err: null }); })
                .catch(function (e) { setState({ loading: false, data: null, err: e.message || 'Failed to load customer card' }); });
        }, [props.email]);

        // 2xx: tri-state on props.email
        //   null       → ThreadView still loading the thread
        //   ''         → resolved; no other party identifiable (orphan thread)
        //   '<email>'  → resolved; show the card
        if (props.email === null || props.email === undefined) {
            return html`<div class="em-inbox-card em-inbox-card--empty"><p>Loading conversation…</p></div>`;
        }
        if (props.email === '') {
            return html`<div class="em-inbox-card em-inbox-card--empty"><p>No participant could be identified for this thread.<br/><small>This usually means the thread has no messages yet (orphaned thread).</small></p></div>`;
        }
        if (state.loading)  return html`<div class="em-inbox-card em-inbox-card--loading"><${Spinner} /></div>`;
        if (state.err)      return html`<div class="em-inbox-card"><${Notice} status="error" isDismissible=${false}>${state.err}<//></div>`;
        var d = state.data || {};
        var u = d.user || {};
        var f = d.forms || null;
        var c = d.contracts || null;
        var o = d.orders || null;
        var w = d.wallet || null;
        function num(v) { return v == null ? '—' : v; }
        function money(v, cur) { if (v == null) return '—'; return (cur || '$') + ' ' + Number(v).toFixed(2); }
        return html`
          <div class="em-inbox-card">
            <header class="em-inbox-card-header">
              ${u.avatar_url && html`<img src=${u.avatar_url} alt="" class="em-inbox-card-avatar" />`}
              <div class="em-inbox-card-id">
                <div class="em-inbox-card-name">${u.display_name || d.email}</div>
                <div class="em-inbox-card-email">${d.email}</div>
                ${u.exists ? html`<div class="em-inbox-card-meta">member since ${u.registered ? formatDate(u.registered) : '—'}</div>` :
                              html`<div class="em-inbox-card-meta em-inbox-card-meta-warn">⚠ no WP account</div>`}
              </div>
            </header>

            <section class="em-inbox-card-section">
              <h3>Forms filled</h3>
              ${f === null
                ? html`<p class="em-inbox-card-na">— forms plugin not active</p>`
                : html`<dl class="em-inbox-card-stats">
                    <dt>Submissions</dt><dd>${num(f.total)}</dd>
                    <dt>Last</dt><dd>${f.last_at ? formatDate(f.last_at) : '—'}</dd>
                  </dl>`}
            </section>

            <section class="em-inbox-card-section">
              <h3>Active proposals / contracts</h3>
              ${c === null
                ? html`<p class="em-inbox-card-na">— contracts plugin not active</p>`
                : html`<dl class="em-inbox-card-stats">
                    <dt>Active</dt><dd>${num(c.active)}</dd>
                    <dt>Offered</dt><dd>${num(c.offered_total)}</dd>
                    <dt>Awarded</dt><dd>${num(c.awarded_total)}</dd>
                    <dt>Last</dt><dd>${c.last_at ? formatDate(c.last_at) : '—'}</dd>
                  </dl>`}
            </section>

            <section class="em-inbox-card-section">
              <h3>Store orders</h3>
              ${o === null
                ? html`<p class="em-inbox-card-na">— WooCommerce not active</p>`
                : html`<dl class="em-inbox-card-stats">
                    <dt>Orders</dt><dd>${num(o.total)}</dd>
                    <dt>Total spent</dt><dd>${money(o.total_spent, o.currency)}</dd>
                    <dt>Last status</dt><dd>${o.last_status || '—'}</dd>
                    <dt>Last</dt><dd>${o.last_at ? formatDate(o.last_at) : '—'}</dd>
                  </dl>`}
            </section>

            <section class="em-inbox-card-section">
              <h3>Wallet</h3>
              ${w === null
                ? html`<p class="em-inbox-card-na">— MyCred not active or no WP user</p>`
                : html`<dl class="em-inbox-card-stats">
                    ${w.mycred != null && html`<${WaPair} k="Points" v=${Number(w.mycred).toFixed(2)} />`}
                    ${w.dgen != null && html`<${WaPair} k="DGEN" v=${Number(w.dgen).toFixed(2)} />`}
                    ${w.task_credit != null && html`<${WaPair} k="Task Credit" v=${Number(w.task_credit).toFixed(2)} />`}
                    ${(w.mycred == null && w.dgen == null && w.task_credit == null) && html`<dt>Wallet</dt><dd>—</dd>`}
                  </dl>`}
            </section>
          </div>
        `;
    }
    function WaPair(props) { return html`<dt>${props.k}</dt><dd>${props.v}</dd>`; }

    function DraftsList(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        var tickState = useState(0); var tick = tickState[0], setTick = tickState[1];

        function reload() {
            setState({ loading: true, items: state.items, err: null });
            restGet('drafts')
                .then(function (d) { setState({ loading: false, items: d.items || [], err: null }); })
                .catch(function (e) { setState({ loading: false, items: [], err: e.message || 'Failed to load drafts' }); });
        }
        useEffect(reload, [props.refreshKey, tick]);

        function discard(id) {
            if (! window.confirm('Discard this draft? This cannot be undone.')) return;
            apiFetch({ url: cfg.restRoot + 'drafts/' + id, method: 'DELETE' })
                .then(function () { setTick(tick + 1); props.onDeleted && props.onDeleted(); });
        }
        function open(id) {
            restGet('drafts/' + id)
                .then(function (d) { props.onOpen && props.onOpen(d); })
                .catch(function (e) { alert((e && e.message) || 'failed to load draft'); });
        }

        if (state.loading) return html`<div class="em-inbox-empty"><${Spinner} /></div>`;
        if (state.err)     return html`<${Notice} status="error" isDismissible=${false}>${state.err}<//>`;
        if (! state.items.length) return html`<p class="em-inbox-empty">No drafts. Anything you start composing is auto-saved here every couple of seconds.</p>`;
        return html`
          <ul class="em-inbox-scheduled-list">
            ${state.items.map(function (m) {
                var to_display = (m.to && m.to.length) ? m.to.join(', ') : '(no recipient)';
                return html`
                  <li key=${m.id} class="em-inbox-scheduled-row" onClick=${function () { open(m.id); }} style=${{ cursor: 'pointer' }}>
                    <div class="em-inbox-scheduled-meta">
                      <div class="em-inbox-scheduled-when">${formatDate(m.updated_at)}</div>
                      <div class="em-inbox-scheduled-to">To: ${to_display}</div>
                      <div class="em-inbox-scheduled-subject">${m.subject || '(no subject)'}</div>
                      ${m.snippet && html`<div class="em-inbox-search-snippet">${m.snippet}…</div>`}
                    </div>
                    <button type="button" class="components-button is-secondary em-inbox-scheduled-cancel" onClick=${function (e) { e.stopPropagation(); discard(m.id); }}>Discard</button>
                  </li>
                `;
            })}
          </ul>
        `;
    }

    function FeedView(props) {
        var st = useState({ loading: true, items: [], total: 0, page: 1, err: null, counts: null });
        var state = st[0], setState = st[1];
        // Slice 2uu: filter state can come from a parent (App lifted it
        // so the left rail can render the filter pills). Fall back to
        // internal state for backwards compat / standalone use.
        var internalFilterState = useState('all');
        var filter    = (props.filter !== undefined) ? props.filter : internalFilterState[0];
        var setFilter = function (v) {
            if (props.onFilterChange) props.onFilterChange(v);
            else internalFilterState[1](v);
        };
        // Slice 2jj: listen for the g+letter keyboard sequence.
        useEffect(function () {
            function onFilterEvent(e) {
                var t = e && e.detail;
                if (t === 'all' || t === 'unread' || t === 'starred' || t === 'snoozed' || t === 'scheduled' || t === 'archived' || t === 'trashed' || t === 'drafts') {
                    setFilter(t);
                }
            }
            window.addEventListener('em-inbox-filter', onFilterEvent);
            return function () { window.removeEventListener('em-inbox-filter', onFilterEvent); };
        }, []);
        // Slice 2uu: push counts up to App so LeftRail can render
        // them next to each filter pill.
        useEffect(function () {
            if (props.onCountsChange && state.counts) props.onCountsChange(state.counts);
        }, [state.counts && JSON.stringify(state.counts)]);
        var selState = useState({});      var selected = selState[0], setSelected = selState[1];
        var loadingMoreState = useState(false); var loadingMore = loadingMoreState[0], setLoadingMore = loadingMoreState[1];
        var inbox = props.inbox;
        var PER_PAGE = 50;

        // Slice 2x: keyboard nav j/k cycles focused index.
        useEffect(function () {
            function onKey(e) {
                if (isTypingTarget(e.target)) return;
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                if (e.key !== 'j' && e.key !== 'k') return;
                if (! state.items.length) return;
                var ids = state.items.map(function (t) { return Number(t.id); });
                var cur = props.focusedThreadId ? ids.indexOf(Number(props.focusedThreadId)) : -1;
                var next = e.key === 'j'
                    ? Math.min(cur + 1, ids.length - 1)
                    : Math.max(cur - 1, 0);
                if (next === cur) return;
                props.onFocusedChange && props.onFocusedChange(ids[next]);
                e.preventDefault();
            }
            window.addEventListener('keydown', onKey);
            return function () { window.removeEventListener('keydown', onKey); };
        }, [state.items, props.focusedThreadId, props.onFocusedChange]);

        function fetchPage(page, append) {
            var q = 'threads?inbox=' + encodeURIComponent(inbox) + '&per_page=' + PER_PAGE + '&page=' + page;
            if (props.labelFilterId) {
                q += '&label_id=' + encodeURIComponent(props.labelFilterId);
            } else {
                if (filter === 'unread')   q += '&unread=1';
                if (filter === 'archived') q += '&archived=1';
                if (filter === 'trashed')  q += '&trashed=1';
                if (filter === 'starred')  q += '&starred=1';
                if (filter === 'snoozed')  q += '&snoozed=1';
            }
            return restGet(q);
        }

        useEffect(function () {
            if (!inbox) return;
            setState({ loading: true, items: [], total: 0, page: 1, err: null, counts: null });
            setSelected({});
            fetchPage(1, false)
                .then(function (data) { setState({ loading: false, items: data.items || [], total: data.total, page: data.page, err: null, counts: data.counts || null }); })
                .catch(function (e) { setState({ loading: false, items: [], total: 0, page: 1, err: e.message || 'Failed to load threads', counts: null }); });
        }, [inbox, filter, props.labelFilterId, props.refreshKey]);

        function loadMore() {
            if (loadingMore) return;
            setLoadingMore(true);
            fetchPage(state.page + 1, true)
                .then(function (data) {
                    setState({ loading: false, items: state.items.concat(data.items || []), total: data.total, page: data.page, err: null, counts: data.counts || state.counts });
                    setLoadingMore(false);
                })
                .catch(function () { setLoadingMore(false); });
        }

        function toggleSelected(id) {
            var next = Object.assign({}, selected);
            if (next[id]) { delete next[id]; } else { next[id] = true; }
            setSelected(next);
        }
        var selIds = Object.keys(selected).map(Number);

        function bulk(action) {
            if (selIds.length === 0) return;
            restPost('threads/bulk', { action: action, thread_ids: selIds })
                .then(function () { setSelected({}); props.onBulkApplied && props.onBulkApplied(); });
        }

        if (state.loading) return html`<aside class="em-inbox-feed"><${Spinner} /></aside>`;
        if (state.err)     return html`<aside class="em-inbox-feed"><${Notice} status="error" isDismissible=${false}>${state.err}<//></aside>`;

        var counts = state.counts || {};
        var btn = function (key, label) {
            return html`<button
                type="button"
                class="em-inbox-filter ${filter === key ? 'is-active' : ''}"
                role="tab"
                aria-selected=${filter === key ? 'true' : 'false'}
                onClick=${function () { setFilter(key); setSelected({}); }}>${label}</button>`;
        };

        var hasMore = state.items.length < (Number(state.total) || 0);
        var allOnPageSelected = state.items.length > 0 && state.items.every(function (t) { return selected[Number(t.id)]; });

        return html`
          <aside class="em-inbox-feed" aria-label="Inbox thread list">
            <header class="em-inbox-feed-header">
              ${! props.hideHeader && html`
                <div class="em-inbox-filters" role="tablist" aria-label="Inbox filters">
                  ${btn('all',       'All' + (counts.total != null ? ' · ' + counts.total : ''))}
                  ${btn('unread',    'Unread' + (counts.unread != null ? ' · ' + counts.unread : ''))}
                  ${btn('starred',   '★ Starred' + (counts.starred != null ? ' · ' + counts.starred : ''))}
                  ${btn('snoozed',   '⏰ Snoozed' + (counts.snoozed != null ? ' · ' + counts.snoozed : ''))}
                  ${btn('scheduled', '⏱ Scheduled')}
                  ${btn('drafts',    '📝 Drafts')}
                  ${btn('archived',  'Archived' + (counts.archived != null ? ' · ' + counts.archived : ''))}
                  ${btn('trashed',   'Trash' + (counts.trashed != null ? ' · ' + counts.trashed : ''))}
                </div>
                ${(props.labels && props.labels.length) || props.onManageLabels
                  ? html`
                    <div class="em-inbox-label-bar">
                      <button type="button"
                        class="em-inbox-label-pill ${props.labelFilterId === 0 ? '' : 'is-muted'}"
                        onClick=${function () { props.onLabelFilter(0); }}>All labels</button>
                      ${(props.labels || []).map(function (l) {
                        var active = Number(props.labelFilterId) === Number(l.id);
                        return html`<button type="button"
                          key=${l.id}
                          class="em-inbox-label-pill ${active ? 'is-active' : ''}"
                          style=${{ borderColor: l.color, color: active ? '#fff' : l.color, backgroundColor: active ? l.color : 'transparent' }}
                          onClick=${function () { props.onLabelFilter(active ? 0 : Number(l.id)); }}>${l.name}</button>`;
                      })}
                      <button type="button" class="em-inbox-label-manage" onClick=${props.onManageLabels}>Manage…</button>
                    </div>
                  `
                  : null}
              `}
              ${selIds.length > 0 && html`
                <div class="em-inbox-bulk-bar">
                  <span>${selIds.length} selected</span>
                  ${filter === 'trashed'
                    ? html`
                      <button type="button" class="em-inbox-bulk-act"   onClick=${function () { bulk('restore'); }}>Restore</button>
                      <button type="button" class="em-inbox-bulk-act"   onClick=${function () {
                          if (! window.confirm('Permanently delete ' + selIds.length + ' thread(s)? This cannot be undone.')) return;
                          Promise.all(selIds.map(function (id) {
                              return apiFetch({ url: cfg.restRoot + 'threads/' + id + '/delete-forever', method: 'DELETE' });
                          })).then(function () { setSelected({}); props.onBulkApplied && props.onBulkApplied(); });
                      }}>Delete forever</button>`
                    : html`
                      <button type="button" class="em-inbox-bulk-act" onClick=${function () { bulk('read'); }}>Mark read</button>
                      <button type="button" class="em-inbox-bulk-act" onClick=${function () { bulk('unread'); }}>Mark unread</button>
                      <button type="button" class="em-inbox-bulk-act" onClick=${function () { bulk(filter === 'archived' ? 'unarchive' : 'archive'); }}>${filter === 'archived' ? 'Unarchive' : 'Archive'}</button>
                      <button type="button" class="em-inbox-bulk-act" onClick=${function () { bulk('trash'); }}>Trash</button>
                    `}
                  <button type="button" class="em-inbox-bulk-clear" onClick=${function () { setSelected({}); }}>Clear</button>
                </div>
              `}
            </header>
            ${filter === 'scheduled'
              ? html`<${ScheduledList} refreshKey=${props.refreshKey} onCancelled=${function () { props.onBulkApplied && props.onBulkApplied(); }} />`
              : filter === 'drafts'
              ? html`<${DraftsList} refreshKey=${props.refreshKey} onOpen=${function (d) { props.onOpenDraft && props.onOpenDraft(d); }} onDeleted=${function () { props.onBulkApplied && props.onBulkApplied(); }} />`
              : state.items.length === 0
              ? html`<p class="em-inbox-empty">No threads ${filter === 'all' ? 'yet' : 'in ' + filter}.</p>`
              : html`
                <ul class="em-inbox-thread-list">
                  ${state.items.length > 0 && html`
                    <li class="em-inbox-thread-row em-inbox-selectall-row">
                      <input type="checkbox" checked=${allOnPageSelected} onClick=${function (e) {
                          e.stopPropagation();
                          if (allOnPageSelected) {
                              setSelected({});
                          } else {
                              var next = {};
                              state.items.forEach(function (t) { next[Number(t.id)] = true; });
                              setSelected(next);
                          }
                      }} />
                      <span class="em-inbox-thread-subject">Select page (${state.items.length}/${state.total})</span>
                    </li>
                  `}
                  ${state.items.map(function (t) {
                      var open   = props.openThreadId === Number(t.id) || props.openThreadId === t.id;
                      var unread = Number(t.is_read) === 0;
                      var starred = Number(t.is_starred) === 1;
                      var tid    = Number(t.id);
                      var focused = Number(props.focusedThreadId) === tid;
                      return html`
                        <li
                          key=${t.id}
                          class="em-inbox-thread-row${open ? ' is-open' : ''}${unread ? ' is-unread' : ''}${selected[tid] ? ' is-selected' : ''}${focused ? ' is-focused' : ''}"
                        >
                          <input
                            type="checkbox"
                            checked=${!!selected[tid]}
                            onClick=${function (e) { e.stopPropagation(); toggleSelected(tid); }}
                          />
                          <button
                            type="button"
                            class="em-inbox-star ${starred ? 'is-starred' : ''}"
                            title=${starred ? 'Unstar' : 'Star'}
                            aria-label=${starred ? 'Remove star' : 'Add star'}
                            aria-pressed=${starred ? 'true' : 'false'}
                            onClick=${function (e) {
                                e.stopPropagation();
                                restPost('threads/' + tid + '/' + (starred ? 'unstar' : 'star'), {})
                                    .then(function () { props.onBulkApplied && props.onBulkApplied(); });
                            }}
                          ><span aria-hidden="true">${starred ? '★' : '☆'}</span></button>
                          <div
                            class="em-inbox-thread-body"
                            role="button"
                            tabindex="0"
                            aria-label=${'Open thread: ' + (t.subject_first || 'no subject')}
                            onClick=${function () { props.onOpenThread(tid); }}
                            onKeyDown=${function (e) {
                                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); props.onOpenThread(tid); }
                            }}>
                            <div class="em-inbox-thread-sender">${t.last_sender || '(no sender)'}</div>
                            <div class="em-inbox-thread-subject">
                              ${(t.labels || []).map(function (l) {
                                  return html`<span class="em-inbox-chip" style=${{ backgroundColor: l.color }}>${l.name}</span>`;
                              })}
                              ${t.subject_first || '(no subject)'}
                            </div>
                            <div class="em-inbox-thread-meta">${t.message_count} msg · ${formatDate(t.updated_at)}${props.inbox === '*' && t.inbox_address ? html`<span class="em-inbox-origin-chip">${t.inbox_address}</span>` : null}</div>
                          </div>
                        </li>
                      `;
                  })}
                </ul>
                ${hasMore && html`
                  <div class="em-inbox-load-more">
                    <button type="button" class="components-button is-tertiary" onClick=${loadMore} disabled=${loadingMore}>
                      ${loadingMore ? html`<${Spinner} />` : ('Load more (' + (state.total - state.items.length) + ' remaining)')}
                    </button>
                  </div>
                `}
              `}
          </aside>
        `;
    }

    function ThreadView(props) {
        var st = useState({ loading: true, thread: null, messages: [], err: null });
        var state = st[0], setState = st[1];

        useEffect(function () {
            setState({ loading: true, thread: null, messages: [], err: null });
            restGet('threads/' + props.threadId)
                .then(function (data) {
                    setState({ loading: false, thread: data.thread, messages: data.messages, err: null });
                    // GET /threads/{id} implicitly marks the thread read
                    // server-side for the owner — let App refresh the feed
                    // so the bold-unread row clears.
                    props.onLoaded && props.onLoaded();
                    // Slice 2uu: identify the OTHER party in the
                    // conversation (the customer) so App can drive a
                    // CustomerCard in the left rail. Picks the first
                    // message whose sender != inbox_address. For an
                    // entirely-outbound thread, falls back to the first
                    // To: header. Slice 2xx: ALWAYS fire the callback —
                    // pass '' (empty string) when we couldn't resolve a
                    // party, so the App can swap the card from a
                    // perpetual spinner to a "no participant" notice.
                    if (props.onOtherParty && data.thread) {
                        var owner = (data.thread.inbox_address || '').toLowerCase();
                        var other = null;
                        (data.messages || []).some(function (m) {
                            if (m.sender && m.sender.toLowerCase() !== owner) { other = m.sender; return true; }
                            return false;
                        });
                        if (! other) {
                            // outbound-only thread — read the first To: header
                            (data.messages || []).some(function (m) {
                                var hdrs = m.headers || [];
                                for (var i = 0; i < hdrs.length; i++) {
                                    if (hdrs[i].name && hdrs[i].name.toLowerCase() === 'to') {
                                        var v = String(hdrs[i].value || '').split(/[,;]/)[0];
                                        var mm = v.match(/<([^>]+)>/);
                                        other = (mm ? mm[1] : v).trim();
                                        return !!other;
                                    }
                                }
                                return false;
                            });
                        }
                        props.onOtherParty(other ? other.toLowerCase() : '');
                    }
                })
                .catch(function (e) { setState({ loading: false, thread: null, messages: [], err: e.message || 'Failed to load thread' }); });
            // NOTE: deliberately NOT depending on props.refreshKey here.
            // onLoaded bumps the App's tick to refresh the feed; if
            // refreshKey were in our deps, that bump would re-trigger
            // this effect, fire onLoaded again, and loop forever.
        }, [props.threadId]);

        if (state.loading) return html`<section class="em-inbox-thread"><${Spinner} /></section>`;
        if (state.err)     return html`<section class="em-inbox-thread"><${Notice} status="error" isDismissible=${false}>${state.err}<//></section>`;

        // Reply default-To is the last *external* sender on the thread —
        // skip messages we sent ourselves (sender === inbox owner).
        var lastInbound = null;
        for (var i = state.messages.length - 1; i >= 0; i--) {
            var m = state.messages[i];
            if (m.sender && m.sender.toLowerCase() !== (state.thread.inbox_address || '').toLowerCase()) {
                lastInbound = m; break;
            }
        }
        var lastMsg = lastInbound || state.messages[state.messages.length - 1];

        return html`
          <section class="em-inbox-thread">
            <header class="em-inbox-thread-header">
              <${Button} variant="tertiary" onClick=${props.onBack}>← Back<//>
              <h2>${state.thread.subject_first || '(no subject)'}</h2>
              <p class="em-inbox-thread-inbox">to ${state.thread.inbox_address} · ${state.messages.length} message${state.messages.length === 1 ? '' : 's'}</p>
              <div class="em-inbox-thread-actions">
                <button
                  type="button"
                  class="em-inbox-star em-inbox-star-header ${Number(state.thread.is_starred) === 1 ? 'is-starred' : ''}"
                  title=${Number(state.thread.is_starred) === 1 ? 'Unstar' : 'Star'}
                  onClick=${function () {
                      var st = Number(state.thread.is_starred) === 1;
                      restPost('threads/' + state.thread.id + '/' + (st ? 'unstar' : 'star'), {})
                          .then(function () {
                              setState(Object.assign({}, state, { thread: Object.assign({}, state.thread, { is_starred: st ? 0 : 1 }) }));
                              props.onArchived && props.onArchived();
                          });
                  }}
                >${Number(state.thread.is_starred) === 1 ? '★' : '☆'}</button>
                <${Button} variant="primary" data-em-key="reply" onClick=${function () { props.onReply && props.onReply(state.thread, lastMsg); }}>Reply<//>
                <${Button} variant="secondary" data-em-key="reply-all" onClick=${function () { props.onReplyAll && props.onReplyAll(state.thread, lastMsg); }}>Reply all<//>
                <${Button} variant="secondary" data-em-key="forward" onClick=${function () { props.onForward && props.onForward(state.thread, lastMsg); }}>Forward<//>
                <${Button} variant="secondary" onClick=${function () {
                    var archived = Number(state.thread.is_archived) === 1;
                    restPost('threads/' + state.thread.id + '/' + (archived ? 'unarchive' : 'archive'), {})
                        .then(function () { props.onArchived && props.onArchived(); });
                }}>${Number(state.thread.is_archived) === 1 ? 'Unarchive' : 'Archive'}<//>
                <${Button} variant="tertiary" onClick=${function () {
                    restPost('threads/' + state.thread.id + '/unread', {})
                        .then(function () { props.onMarkedUnread && props.onMarkedUnread(); });
                }}>Mark unread<//>
                <${Button} variant="tertiary" onClick=${function () {
                    restPost('threads/' + state.thread.id + '/trash', {})
                        .then(function () { props.onArchived && props.onArchived(); });
                }}>Trash<//>
                <${SnoozeButton}
                  threadId=${state.thread.id}
                  snoozedUntil=${state.thread.snoozed_until || null}
                  onChanged=${function () { props.onArchived && props.onArchived(); }} />
                <${ThreadLabelPicker}
                  threadId=${state.thread.id}
                  threadLabels=${state.thread.labels || []}
                  labels=${props.labels || []}
                  onChanged=${function (res) {
                      setState(Object.assign({}, state, { thread: Object.assign({}, state.thread, { labels: (res && res.labels) || [] }) }));
                      props.onArchived && props.onArchived();
                  }} />
              </div>
            </header>
            <div class="em-inbox-messages">
              ${state.messages.map(function (m, idx) {
                  var isMine = m.sender && state.thread.inbox_address && m.sender.toLowerCase() === state.thread.inbox_address.toLowerCase();
                  return html`<${MessageCard} key=${m.id} message=${m} initialOpen=${idx === state.messages.length - 1} isMine=${isMine} />`;
              })}
            </div>
          </section>
        `;
    }

    function MessageCard(props) {
        var msg = props.message;
        var openState = useState(props.initialOpen);
        var open = openState[0], setOpen = openState[1];
        // Per-card image-blocked override — "Show in this message"
        var imagesUnlockState = useState(false);
        var imagesUnlocked = imagesUnlockState[0], setImagesUnlocked = imagesUnlockState[1];
        var bodyRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };

        function unblockImagesNow() {
            // Swap data-blocked-src → src on all <img class="em-inbox-blocked-img">
            // inside this card. The HTML is already wp_kses-clean so this is safe.
            setImagesUnlocked(true);
            // useEffect below applies the swap after re-render.
        }
        useEffect(function () {
            if (!imagesUnlocked || !bodyRef.current) return;
            var imgs = bodyRef.current.querySelectorAll('img.em-inbox-blocked-img[data-blocked-src]');
            imgs.forEach(function (img) {
                var url = img.getAttribute('data-blocked-src');
                if (url) img.setAttribute('src', url);
                img.classList.remove('em-inbox-blocked-img');
            });
        }, [imagesUnlocked, msg.id]);

        // Slice 2gg: detect quoted prior-message portions and collapse
        // them behind a "Show trimmed content" toggle. Runs once when
        // the body is rendered. Idempotent — skips if a toggle is
        // already present (covers re-render via image unlock).
        useEffect(function () {
            if (!open || !bodyRef.current) return;
            if (bodyRef.current.querySelector('.em-inbox-quote-toggle')) return;
            em_inbox_collapse_quoted_block(bodyRef.current);
        }, [open, msg.id, imagesUnlocked]);

        function allowSenderImages() {
            restPost('senders/show-images', { sender: msg.sender, show: true })
                .then(function () { setImagesUnlocked(true); });
        }

        var status     = msg.delivery_status || (props.isMine ? 'sent' : null);
        var statusInfo = renderDeliveryStatus(status, msg);
        var blockedN   = Number(msg.images_blocked || 0);
        var showBlockedBanner = blockedN > 0 && !imagesUnlocked && !msg.images_show_for_sender;
        var openCount  = Number(msg.open_count || 0);
        var lastOpen   = msg.last_open_at;
        var auth       = msg.auth || null;
        var authBadge  = !props.isMine && auth ? renderAuthBadge(auth) : null;

        return html`
          <${Card} className="em-inbox-message ${open ? 'is-open' : 'is-collapsed'} ${props.isMine ? 'is-mine' : ''} ${status ? 'is-delivery-' + status : ''}">
            <${CardHeader} onClick=${function () { setOpen(!open); }}>
              <div class="em-inbox-message-from">${props.isMine ? 'Me' : msg.sender}</div>
              <div class="em-inbox-message-meta">
                ${statusInfo}
                ${authBadge}
                ${props.isMine && openCount > 0 && html`
                  <span class="em-inbox-open-badge" title=${'Last opened ' + (lastOpen || '—') + ' UTC'}>👁 ${openCount}</span>
                `}
                <div class="em-inbox-message-date">${formatDate(msg.received_at)}</div>
              </div>
            <//>
            ${open && html`
              <${CardBody}>
                ${showBlockedBanner && html`
                  <div class="em-inbox-img-banner">
                    <span>${blockedN} remote image${blockedN === 1 ? '' : 's'} blocked.</span>
                    <button type="button" class="em-inbox-img-btn" onClick=${unblockImagesNow}>Show in this message</button>
                    <button type="button" class="em-inbox-img-btn" onClick=${allowSenderImages}>Always show from ${msg.sender}</button>
                  </div>
                `}
                ${msg.body_html
                    ? html`<div class="em-inbox-message-body" ref=${function (n) { bodyRef.current = n; }} dangerouslySetInnerHTML=${{ __html: msg.body_html }} />`
                    : html`<pre class="em-inbox-message-plain" ref=${function (n) { bodyRef.current = n; }}>${msg.body_plain || '(no body)'}</pre>`}
                ${msg.attachments && msg.attachments.length
                    ? html`<${AttachmentList} messageId=${msg.id} attachments=${msg.attachments} />`
                    : null}
              <//>
            `}
          <//>
        `;
    }

    function AttachmentList(props) {
        if (!props.attachments.length) return null;
        var previewState = useState(null); var previewing = previewState[0], setPreviewing = previewState[1];
        function canPreview(ct) {
            ct = String(ct || '').toLowerCase();
            return ct.indexOf('image/') === 0
                || ct === 'application/pdf'
                || ct.indexOf('text/') === 0
                || ct === 'application/json';
        }
        return html`
          <ul class="em-inbox-attachments">
            ${props.attachments.map(function (a, idx) {
                var href = cfg.restRoot + 'message/' + props.messageId + '/attachment/' + idx + '?_wpnonce=' + cfg.nonce;
                var size = a.size ? humanBytes(a.size) : '';
                return html`
                  <li key=${idx}>
                    <a href=${href} target="_blank" rel="noopener" download=${a.filename || 'attachment-' + idx}>${a.filename || ('attachment-' + idx)}</a>
                    <span class="em-inbox-att-meta">${a.content_type || ''} ${size ? '· ' + size : ''}</span>
                    ${canPreview(a.content_type) && html`
                      <button type="button" class="em-inbox-att-preview" onClick=${function () { setPreviewing({ href: href, att: a }); }}>Preview</button>
                    `}
                  </li>
                `;
            })}
          </ul>
          ${previewing && html`<${AttachmentPreviewModal} preview=${previewing} onClose=${function () { setPreviewing(null); }} />`}
        `;
    }

    // ── Attachment preview modal (slice 2ll) ──────────────────────────
    // Renders image / pdf / text inline. The server already serves with
    // Content-Disposition: inline so <img>/<iframe>/fetch all work
    // without extra endpoints.
    function AttachmentPreviewModal(props) {
        var p = props.preview;
        var att = p.att;
        var ct = String(att.content_type || '').toLowerCase();
        var textState = useState({ loading: false, text: '', err: null });
        var ts = textState[0], setTextState = textState[1];

        useEffect(function () {
            if (ct.indexOf('text/') !== 0 && ct !== 'application/json') return;
            setTextState({ loading: true, text: '', err: null });
            // Cap to 256 KiB so a multi-megabyte log file doesn't OOM
            // the browser.
            var ctl = new AbortController();
            fetch(p.href, { credentials: 'include', signal: ctl.signal })
                .then(function (r) { return r.text(); })
                .then(function (text) {
                    var truncated = false;
                    if (text.length > 262144) { text = text.slice(0, 262144); truncated = true; }
                    setTextState({ loading: false, text: text + (truncated ? '\n\n— Truncated at 256 KiB —' : ''), err: null });
                })
                .catch(function (e) { setTextState({ loading: false, text: '', err: e.message || 'load failed' }); });
            return function () { ctl.abort(); };
        }, [p.href, ct]);

        var body;
        if (ct.indexOf('image/') === 0) {
            body = html`<img class="em-inbox-preview-img" src=${p.href} alt=${att.filename || ''} />`;
        } else if (ct === 'application/pdf') {
            body = html`<iframe class="em-inbox-preview-pdf" src=${p.href} title=${att.filename || 'PDF preview'}></iframe>`;
        } else if (ct.indexOf('text/') === 0 || ct === 'application/json') {
            if (ts.loading) body = html`<${Spinner} />`;
            else if (ts.err) body = html`<${Notice} status="error" isDismissible=${false}>${ts.err}<//>`;
            else            body = html`<pre class="em-inbox-preview-text">${ts.text}</pre>`;
        } else {
            body = html`<p class="em-inbox-empty">Preview not supported for this file type.</p>`;
        }
        return html`
          <${Modal} title=${att.filename || 'Attachment preview'} onRequestClose=${props.onClose} className="em-inbox-preview-modal">
            ${body}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose}>Close<//>
              <${Button} variant="primary" href=${p.href} target="_blank" rel="noopener" download=${att.filename || 'attachment'}>Download<//>
            </div>
          <//>
        `;
    }

    // ── Format helpers ──────────────────────────────────────────────────

    function formatDate(s) {
        if (!s) return '';
        var d = new Date(s.replace(' ', 'T') + 'Z');
        if (isNaN(d)) return s;
        var now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    // ── Rich-text editor (contenteditable + execCommand) ────────────────
    // Minimal in-house RTE. execCommand is officially deprecated but
    // every current browser still implements it for these basic
    // verbs. Avoids a 60KB-plus 3rd-party dep + build step for what's
    // a single-textarea-replacement.
    function RichTextEditor(props) {
        var ref = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        // Push value INTO the contenteditable only when it changes
        // externally (e.g. parent reset on send) — otherwise the cursor
        // resets on every keystroke.
        useEffect(function () {
            if (ref.current && ref.current.innerHTML !== props.value) {
                ref.current.innerHTML = props.value || '';
            }
        }, [props.value]);

        function exec(cmd, value) {
            return function (e) {
                e.preventDefault();
                if (ref.current) ref.current.focus();
                document.execCommand(cmd, false, value);
                if (ref.current) props.onChange(ref.current.innerHTML);
            };
        }
        function onInput() { if (ref.current) props.onChange(ref.current.innerHTML); }
        function onLink(e) {
            e.preventDefault();
            var url = window.prompt('Link URL:', 'https://');
            if (url) {
                if (ref.current) ref.current.focus();
                document.execCommand('createLink', false, url);
                if (ref.current) props.onChange(ref.current.innerHTML);
            }
        }

        var btn = function (label, cmd, value, title) {
            return html`<button type="button" class="em-rte-btn" title=${title || label} onMouseDown=${exec(cmd, value)}>${label}</button>`;
        };
        return html`
          <div class="em-rte">
            <div class="em-rte-toolbar">
              ${btn(html`<b>B</b>`,      'bold',          null, 'Bold')}
              ${btn(html`<i>I</i>`,      'italic',        null, 'Italic')}
              ${btn(html`<u>U</u>`,      'underline',     null, 'Underline')}
              <span class="em-rte-sep" />
              ${btn('• List',            'insertUnorderedList', null, 'Bulleted list')}
              ${btn('1. List',           'insertOrderedList',   null, 'Numbered list')}
              ${btn('"',                 'formatBlock',  'blockquote', 'Quote')}
              <span class="em-rte-sep" />
              <button type="button" class="em-rte-btn" title="Link" onMouseDown=${onLink}>🔗</button>
              ${btn('Clear',             'removeFormat',  null, 'Clear formatting')}
            </div>
            <div
              class="em-rte-editor"
              contentEditable=${true}
              ref=${function (n) { ref.current = n; }}
              onInput=${onInput}
              onBlur=${onInput}
            ></div>
          </div>
        `;
    }

    function htmlToPlain(html) {
        if (!html) return '';
        // Convert <br>, </p>, </div> to newlines before stripping tags.
        var s = String(html)
            .replace(/<\s*br\s*\/?>/gi, '\n')
            .replace(/<\/(p|div|li|h\d|blockquote)>/gi, '\n')
            .replace(/<li[^>]*>/gi, '\n• ')
            .replace(/<[^>]+>/g, '')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/\n{3,}/g, '\n\n');
        return s.trim();
    }

    // Slice 2gg: detect a quoted-prior-message region in a rendered
    // message body and collapse it behind a "Show trimmed content"
    // toggle. Handles Gmail/Outlook HTML idioms + a "On X, Y wrote:"
    // plain-text fallback + leading "> "/">>" lines.
    //
    // The function mutates `rootNode` in place. Idempotent.
    function em_inbox_collapse_quoted_block(rootNode) {
        if (!rootNode) return;
        var doc = rootNode.ownerDocument || document;

        // ---- HTML path: look for known quote containers
        var selectors = [
            '.gmail_quote',
            'blockquote[type="cite"]',
            '#appendonsend',
            '.OutlookMessageHeader',
            '.x_OutlookMessageHeader',
            'div[id^="reply-"]',
        ];
        var found = null;
        for (var i = 0; i < selectors.length; i++) {
            var el = rootNode.querySelector(selectors[i]);
            if (el) { found = el; break; }
        }

        // ---- Plain-text "On ... wrote:" fallback (HTML body without
        //      gmail_quote wrapper, or a <pre> plain-text card)
        if (!found) {
            var text = rootNode.textContent || '';
            // Match "On <date>, <name> wrote:" (date can span newlines).
            var m = text.match(/On\s+[^\n]{1,200}\s+wrote:/i);
            if (m) {
                var idx = text.indexOf(m[0]);
                // Walk the DOM, find the text node containing the match,
                // and slice from there.
                var split = em_inbox_split_text_at(rootNode, idx);
                if (split) found = split;
            }
        }

        // ---- ">" prefix lines fallback for plain bodies
        if (!found && rootNode.tagName === 'PRE') {
            var lines = (rootNode.textContent || '').split('\n');
            var firstQ = -1;
            for (var j = 0; j < lines.length; j++) {
                if (/^>\s/.test(lines[j])) { firstQ = j; break; }
            }
            if (firstQ >= 0) {
                var visible = lines.slice(0, firstQ).join('\n').replace(/\s+$/, '');
                var quoted  = lines.slice(firstQ).join('\n');
                rootNode.textContent = visible + '\n';
                var qNode = doc.createElement('div');
                qNode.className = 'em-inbox-quoted-block';
                var qPre = doc.createElement('pre');
                qPre.className = 'em-inbox-message-plain';
                qPre.textContent = quoted;
                qNode.appendChild(qPre);
                rootNode.parentNode.insertBefore(qNode, rootNode.nextSibling);
                found = qNode;
            }
        }

        if (!found) return;

        // Inject a toggle button just before the (now-collapsed) quote.
        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'em-inbox-quote-toggle';
        btn.textContent = '··· Show trimmed content';
        found.classList.add('em-inbox-quoted-block', 'is-collapsed');
        found.parentNode.insertBefore(btn, found);
        btn.addEventListener('click', function () {
            var collapsed = found.classList.toggle('is-collapsed');
            btn.textContent = collapsed
                ? '··· Show trimmed content'
                : '··· Hide trimmed content';
        });
    }

    // Walk text nodes inside `root`; return the element containing the
    // first character at `charOffset` (counted across textContent). Wraps
    // that-and-everything-after in a single sibling element marked
    // .em-inbox-quoted-block and returns it.
    function em_inbox_split_text_at(root, charOffset) {
        var doc = root.ownerDocument || document;
        var walker = doc.createTreeWalker(root, 4 /* SHOW_TEXT */, null);
        var consumed = 0, node;
        while ((node = walker.nextNode())) {
            var len = node.nodeValue.length;
            if (consumed + len < charOffset) { consumed += len; continue; }
            // Split this text node at (charOffset - consumed).
            var rel = charOffset - consumed;
            if (rel > 0) node.splitText(rel);
            // The second half is now node.nextSibling. Wrap from that
            // node + every following sibling-chain element up the tree
            // in a quoted block.
            var startNode = node.nextSibling || node;
            // Walk up to a common ancestor of the start node and root,
            // then collect siblings from startNode onward at that level.
            var ancestor = startNode;
            while (ancestor.parentNode && ancestor.parentNode !== root) ancestor = ancestor.parentNode;
            if (! ancestor.parentNode) return null;
            // Move ancestor + every nextSibling into a wrapper.
            var wrapper = doc.createElement('div');
            wrapper.className = 'em-inbox-quoted-block';
            var cursor = ancestor;
            while (cursor) {
                var nxt = cursor.nextSibling;
                wrapper.appendChild(cursor);
                cursor = nxt;
            }
            root.appendChild(wrapper);
            return wrapper;
        }
        return null;
    }

    // ── Slice 2pp: offline draft cache via IndexedDB ──────────────────
    // Why an IDB cache + not just localStorage:
    //  - drafts include attachments (potentially MB of base64) — way
    //    over localStorage's typical 5MB limit, but well inside IDB's
    //    multi-GB quota
    //  - structured-clone serialization preserves the payload shape
    //    without a JSON.parse(JSON.stringify(...)) round-trip
    //
    // Schema: store 'drafts' keyed by clientId (a uuid generated up
    // front so the row exists in cache before it has a server id).
    // Each row carries `serverId` (null until first sync), `payload`
    // (the raw POST body), `savedAt`, `pendingSync` (boolean — true
    // when offline edits made and not yet pushed).
    var EM_IDB_NAME = 'em-inbox';
    var EM_IDB_VERSION = 1;
    var em_idb_promise = null;
    function em_inbox_idb() {
        if (em_idb_promise) return em_idb_promise;
        em_idb_promise = new Promise(function (resolve, reject) {
            if (! window.indexedDB) { reject(new Error('IndexedDB unavailable')); return; }
            var req = indexedDB.open(EM_IDB_NAME, EM_IDB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (! db.objectStoreNames.contains('drafts')) {
                    var store = db.createObjectStore('drafts', { keyPath: 'clientId' });
                    store.createIndex('serverId',    'serverId',    { unique: false });
                    store.createIndex('pendingSync', 'pendingSync', { unique: false });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror   = function () { reject(req.error); };
        });
        return em_idb_promise;
    }
    function em_inbox_idb_uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'em-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }
    function em_inbox_idb_put_draft(row) {
        return em_inbox_idb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction('drafts', 'readwrite');
                tx.objectStore('drafts').put(row);
                tx.oncomplete = function () { resolve(row); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }
    function em_inbox_idb_delete_draft(clientId) {
        return em_inbox_idb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction('drafts', 'readwrite');
                tx.objectStore('drafts').delete(clientId);
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { reject(tx.error); };
            });
        });
    }
    function em_inbox_idb_list_pending() {
        return em_inbox_idb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction('drafts', 'readonly');
                var out = [];
                var req = tx.objectStore('drafts').openCursor();
                req.onsuccess = function () {
                    var cur = req.result;
                    if (cur) { if (cur.value && cur.value.pendingSync) out.push(cur.value); cur.continue(); }
                    else resolve(out);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    // Push every queued (pendingSync=true) draft to the server. Called
    // by the global online-event listener.
    function em_inbox_flush_pending_drafts() {
        if (!navigator.onLine) return;
        em_inbox_idb_list_pending().then(function (rows) {
            rows.forEach(function (row) {
                var url = row.serverId > 0 ? 'drafts/' + row.serverId : 'drafts';
                apiFetch({ url: cfg.restRoot + url, method: 'POST', data: row.payload })
                    .then(function (res) {
                        if (res && res.id) {
                            row.serverId = res.id;
                            row.pendingSync = false;
                            row.lastSyncedAt = Date.now();
                            em_inbox_idb_put_draft(row);
                        }
                    })
                    .catch(function () { /* leave pendingSync=true for next online tick */ });
            });
        }).catch(function () { /* idb unavailable — fine */ });
    }
    // Wire once globally.
    if (! window.__em_inbox_online_wired) {
        window.__em_inbox_online_wired = true;
        window.addEventListener('online',  em_inbox_flush_pending_drafts);
        // Try once at boot in case we're online but with pending rows
        // from a previous offline session.
        setTimeout(em_inbox_flush_pending_drafts, 1500);
    }

    // Slice 2mm: timezone-aware date construction. Returns a Date
    // whose UTC instant corresponds to <hour>:<minute> wall-clock time
    // in `tz` on the same calendar day as `targetDate` (also in `tz`).
    // Falls back to browser-local when Intl APIs are unavailable.
    function em_inbox_tz_aware_ts(targetDate, hour, minute) {
        var tz = cfg.userTimezone || 'UTC';
        try {
            var fmt = new Intl.DateTimeFormat('en-US', {
                timeZone: tz,
                year: 'numeric', month: '2-digit', day: '2-digit',
                timeZoneName: 'longOffset',
            });
            var parts = fmt.formatToParts(targetDate);
            function pick(t) { var p = parts.find(function (x) { return x.type === t; }); return p ? p.value : ''; }
            var y = pick('year'), m = pick('month'), d = pick('day');
            var offRaw = pick('timeZoneName') || 'GMT+00:00';
            var off = offRaw.replace(/^GMT/, '');
            if (off === '' || off === 'Z') off = '+00:00';
            var hh = String(hour).padStart(2, '0');
            var mm = String(minute).padStart(2, '0');
            return new Date(y + '-' + m + '-' + d + 'T' + hh + ':' + mm + ':00' + off);
        } catch (e) {
            var fallback = new Date(targetDate.getTime());
            fallback.setHours(hour, minute, 0, 0);
            return fallback;
        }
    }
    function em_inbox_fmt_tz(date, opts) {
        var tz = cfg.userTimezone || undefined;
        try { return date.toLocaleString([], Object.assign({}, opts || {}, { timeZone: tz })); }
        catch (e) { return date.toLocaleString([], opts || {}); }
    }

    function humanBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }
    function renderAuthBadge(auth) {
        if (!auth || !auth.summary) return null;
        var s = String(auth.summary).toLowerCase();
        var tip = ['SPF', 'DKIM', 'DMARC'].map(function (k) {
            return k + ': ' + (auth[k.toLowerCase()] || 'none');
        }).join('  ·  ');
        if (s === 'pass')        return html`<span class="em-inbox-auth-badge em-auth-pass" title=${tip}>✓ verified</span>`;
        if (s === 'partial')     return html`<span class="em-inbox-auth-badge em-auth-partial" title=${tip}>~ partial</span>`;
        if (s === 'fail')        return html`<span class="em-inbox-auth-badge em-auth-fail" title=${tip}>! spoof risk</span>`;
        return html`<span class="em-inbox-auth-badge em-auth-unknown" title=${tip}>? unverified</span>`;
    }

    function renderDeliveryStatus(status, msg) {
        if (!status || msg.kind === 'inbound') return null;
        var label, klass, hint;
        if (status === 'sent')     { label = '✓ Sent';     klass = 'sent';     hint = 'Delivered'; }
        else if (status === 'retrying') { label = '↻ Retrying ' + (msg.delivery_attempts || 0); klass = 'retrying'; hint = msg.delivery_last_error || 'Pending retry'; }
        else if (status === 'failed')   { label = '✗ Failed';  klass = 'failed';   hint = msg.delivery_last_error || 'Delivery failed'; }
        else                            { label = status;      klass = 'unknown';  hint = ''; }
        return html`<span class="em-inbox-msg-status em-inbox-msg-status-${klass}" title=${hint}>${label}</span>`;
    }

    // ── Mount ───────────────────────────────────────────────────────────
    // Don't gate on DOMContentLoaded — this script is enqueued in the
    // footer, so the mount node is already in the DOM by the time we run.
    // (DOMContentLoaded gating is fragile: if the event already fired
    // before we attached the listener, our callback never runs.)
    function mount() {
        var root = document.getElementById('em-inbox-root');
        if (!root) { console.error('em-inbox: #em-inbox-root not found'); return; }
        root.removeAttribute('data-loading');
        root.textContent = '';
        var tree = html`<${App} />`;
        // Prefer React 18 createRoot when available; fall back to
        // legacy wp.element.render (deprecated in WP 6.2+ but still works
        // for older builds).
        if (wp.element.createRoot) {
            try {
                wp.element.createRoot(root).render(tree);
                return;
            } catch (e) { console.warn('em-inbox: createRoot failed, falling back to render', e); }
        }
        if (typeof wp.element.render === 'function') {
            wp.element.render(tree, root);
        } else {
            root.textContent = 'Inbox UI failed to mount: no compatible React renderer available.';
            console.error('em-inbox: neither wp.element.createRoot nor wp.element.render available');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
