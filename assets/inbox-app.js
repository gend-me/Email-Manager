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

    // ── REST helpers ────────────────────────────────────────────────────
    function restGet(path) {
        // path is relative to the inbox root (e.g. 'threads?inbox=foo' or 'threads/3')
        return apiFetch({ url: cfg.restRoot + path, method: 'GET' });
    }
    function restPost(path, data) {
        return apiFetch({ url: cfg.restRoot + path, method: 'POST', data: data });
    }

    // ── Components ──────────────────────────────────────────────────────

    function App() {
        var inboxState = useState([]);            var inboxes = inboxState[0], setInboxes = inboxState[1];
        var selectedState = useState('');         var selected = selectedState[0], setSelected = selectedState[1];
        var threadState = useState(null);         var openThreadId = threadState[0], setOpenThreadId = threadState[1];
        var loadingState = useState(true);        var loading = loadingState[0], setLoading = loadingState[1];
        var errState = useState(null);            var err = errState[0], setErr = errState[1];
        var composerState = useState(null);       var composerProps = composerState[0], setComposerProps = composerState[1];
        // Refresh counter — bumped after a successful send so the feed
        // re-fetches and the new sent message shows up immediately.
        var refreshTick = useState(0);            var tick = refreshTick[0], bumpTick = refreshTick[1];

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
            return { value: r.inbox_address, label: r.inbox_address + ' (' + r.thread_count + ')' };
        });

        return html`
          <div class="em-inbox">
            <div class="em-inbox-toolbar">
              <${SelectControl}
                label="Inbox"
                value=${selected}
                options=${inboxOptions}
                onChange=${function (v) { setSelected(v); setOpenThreadId(null); }}
              />
              <${Button}
                variant="primary"
                onClick=${function () { setComposerProps({ from: selected, mode: 'new' }); }}
              >Compose<//>
            </div>
            <div class="em-inbox-body">
              <${FeedView}
                inbox=${selected}
                openThreadId=${openThreadId}
                onOpenThread=${setOpenThreadId}
                refreshKey=${tick} />
              ${openThreadId
                ? html`<${ThreadView}
                    threadId=${openThreadId}
                    onBack=${function () { setOpenThreadId(null); }}
                    onReply=${function (thread, lastMsg) {
                        setComposerProps({
                            from: thread.inbox_address,
                            mode: 'reply',
                            threadId: thread.id,
                            to: lastMsg && lastMsg.sender ? [lastMsg.sender] : [],
                            subject: thread.subject_first ? ('Re: ' + thread.subject_first.replace(/^\s*Re:\s*/i, '')) : '',
                        });
                    }}
                    refreshKey=${tick} />`
                : html`<div class="em-inbox-pane-placeholder">Select a thread to read.</div>`}
            </div>
            ${composerProps && html`
              <${Composer}
                initial=${composerProps}
                onClose=${function () { setComposerProps(null); }}
                onSent=${function () {
                    setComposerProps(null);
                    bumpTick(tick + 1);
                }} />
            `}
          </div>
        `;
    }

    function Composer(props) {
        var initial = props.initial || {};
        var toState = useState((initial.to || []).join(', '));     var to = toState[0], setTo = toState[1];
        var subjState = useState(initial.subject || '');           var subject = subjState[0], setSubject = subjState[1];
        var bodyState = useState('');                              var body = bodyState[0], setBody = bodyState[1];
        var sendingState = useState(false);                        var sending = sendingState[0], setSending = sendingState[1];
        var errState = useState(null);                             var err = errState[0], setErr = errState[1];

        function submit() {
            setSending(true); setErr(null);
            restPost('send', {
                to:         to,
                subject:    subject,
                body_plain: body,
                thread_id:  initial.threadId || undefined,
            }).then(function () {
                setSending(false);
                props.onSent && props.onSent();
            }).catch(function (e) {
                setSending(false);
                setErr((e && e.message) || 'Send failed');
            });
        }

        var title = initial.mode === 'reply' ? 'Reply' : 'New message';
        return html`
          <${Modal} title=${title} onRequestClose=${props.onClose} className="em-inbox-composer-modal">
            <p class="em-inbox-composer-from">From: <strong>${initial.from || '(no inbox)'}</strong></p>
            <${TextControl}
              label="To"
              help="Comma-separate multiple recipients."
              value=${to}
              onChange=${setTo}
              __nextHasNoMarginBottom=${true} />
            <${TextControl}
              label="Subject"
              value=${subject}
              onChange=${setSubject}
              __nextHasNoMarginBottom=${true} />
            <${TextareaControl}
              label="Message"
              value=${body}
              rows=${10}
              onChange=${setBody}
              __nextHasNoMarginBottom=${true} />
            ${err && html`<${Notice} status="error" isDismissible=${false}>${err}<//>`}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose} disabled=${sending}>Cancel<//>
              <${Button} variant="primary"  onClick=${submit}        disabled=${sending || !to || !body}>
                ${sending ? html`<${Spinner} />` : 'Send'}
              <//>
            </div>
          <//>
        `;
    }

    function FeedView(props) {
        var st = useState({ loading: true, items: [], total: 0, page: 1, err: null });
        var state = st[0], setState = st[1];
        var inbox = props.inbox;

        useEffect(function () {
            if (!inbox) return;
            setState({ loading: true, items: [], total: 0, page: 1, err: null });
            restGet('threads?inbox=' + encodeURIComponent(inbox) + '&per_page=50')
                .then(function (data) { setState({ loading: false, items: data.items || [], total: data.total, page: data.page, err: null }); })
                .catch(function (e) { setState({ loading: false, items: [], total: 0, page: 1, err: e.message || 'Failed to load threads' }); });
        }, [inbox, props.refreshKey]);

        if (state.loading) return html`<aside class="em-inbox-feed"><${Spinner} /></aside>`;
        if (state.err)     return html`<aside class="em-inbox-feed"><${Notice} status="error" isDismissible=${false}>${state.err}<//></aside>`;
        if (!state.items.length) return html`<aside class="em-inbox-feed"><p class="em-inbox-empty">No threads yet.</p></aside>`;

        return html`
          <aside class="em-inbox-feed">
            <header class="em-inbox-feed-header">${state.total} thread${state.total === 1 ? '' : 's'}</header>
            <ul class="em-inbox-thread-list">
              ${state.items.map(function (t) {
                  var open = props.openThreadId === Number(t.id) || props.openThreadId === t.id;
                  return html`
                    <li
                      key=${t.id}
                      class="em-inbox-thread-row${open ? ' is-open' : ''}"
                      onClick=${function () { props.onOpenThread(Number(t.id)); }}
                    >
                      <div class="em-inbox-thread-sender">${t.last_sender || '(no sender)'}</div>
                      <div class="em-inbox-thread-subject">${t.subject_first || '(no subject)'}</div>
                      <div class="em-inbox-thread-meta">${t.message_count} msg · ${formatDate(t.updated_at)}</div>
                    </li>
                  `;
              })}
            </ul>
          </aside>
        `;
    }

    function ThreadView(props) {
        var st = useState({ loading: true, thread: null, messages: [], err: null });
        var state = st[0], setState = st[1];

        useEffect(function () {
            setState({ loading: true, thread: null, messages: [], err: null });
            restGet('threads/' + props.threadId)
                .then(function (data) { setState({ loading: false, thread: data.thread, messages: data.messages, err: null }); })
                .catch(function (e) { setState({ loading: false, thread: null, messages: [], err: e.message || 'Failed to load thread' }); });
        }, [props.threadId, props.refreshKey]);

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
              <${Button} variant="primary" onClick=${function () { props.onReply && props.onReply(state.thread, lastMsg); }}>Reply<//>
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

        return html`
          <${Card} className="em-inbox-message ${open ? 'is-open' : 'is-collapsed'} ${props.isMine ? 'is-mine' : ''}">
            <${CardHeader} onClick=${function () { setOpen(!open); }}>
              <div class="em-inbox-message-from">${props.isMine ? 'Me' : msg.sender}</div>
              <div class="em-inbox-message-date">${formatDate(msg.received_at)}</div>
            <//>
            ${open && html`
              <${CardBody}>
                ${msg.body_html
                    ? html`<div class="em-inbox-message-body" dangerouslySetInnerHTML=${{ __html: msg.body_html }} />`
                    : html`<pre class="em-inbox-message-plain">${msg.body_plain || '(no body)'}</pre>`}
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
        return html`
          <ul class="em-inbox-attachments">
            ${props.attachments.map(function (a, idx) {
                var href = cfg.restRoot + 'message/' + props.messageId + '/attachment/' + idx + '?_wpnonce=' + cfg.nonce;
                var size = a.size ? humanBytes(a.size) : '';
                return html`
                  <li key=${idx}>
                    <a href=${href} target="_blank" rel="noopener">${a.filename || ('attachment-' + idx)}</a>
                    <span class="em-inbox-att-meta">${a.content_type || ''} ${size ? '· ' + size : ''}</span>
                  </li>
                `;
            })}
          </ul>
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
    function humanBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
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
