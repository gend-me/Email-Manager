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
        var searchQState = useState('');          var searchQ = searchQState[0], setSearchQ = searchQState[1];
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
            var label = r.inbox_address;
            if (r.unread_count !== null && r.unread_count !== undefined && Number(r.unread_count) > 0) {
                label += ' · ' + r.unread_count + ' unread';
            } else {
                label += ' (' + r.thread_count + ')';
            }
            return { value: r.inbox_address, label: label };
        });

        return html`
          <div class="em-inbox">
            <div class="em-inbox-toolbar">
              <${SelectControl}
                label="Inbox"
                value=${selected}
                options=${inboxOptions}
                onChange=${function (v) { setSelected(v); setOpenThreadId(null); setSearchQ(''); }}
              />
              <${TextControl}
                label="Search"
                placeholder="Subject, body, sender…"
                value=${searchQ}
                onChange=${function (v) { setSearchQ(v); setOpenThreadId(null); }}
                __nextHasNoMarginBottom=${true}
              />
              <${Button}
                variant="primary"
                onClick=${function () { setComposerProps({ from: selected, mode: 'new' }); }}
              >Compose<//>
            </div>
            <div class="em-inbox-body">
              ${searchQ.length >= 2
                ? html`<${SearchResults}
                    query=${searchQ}
                    inbox=${selected}
                    openThreadId=${openThreadId}
                    onOpenThread=${setOpenThreadId} />`
                : html`<${FeedView}
                    inbox=${selected}
                    openThreadId=${openThreadId}
                    onOpenThread=${setOpenThreadId}
                    onBulkApplied=${function () { bumpTick(tick + 1); }}
                    refreshKey=${tick} />`}
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
                    onArchived=${function () { setOpenThreadId(null); bumpTick(tick + 1); }}
                    onMarkedUnread=${function () { setOpenThreadId(null); bumpTick(tick + 1); }}
                    onLoaded=${function () { bumpTick(tick + 1); }}
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
        var bodyHtmlState = useState('');                          var bodyHtml = bodyHtmlState[0], setBodyHtml = bodyHtmlState[1];
        var attState = useState([]);                               var atts = attState[0], setAtts = attState[1];
        var sendingState = useState(false);                        var sending = sendingState[0], setSending = sendingState[1];
        var errState = useState(null);                             var err = errState[0], setErr = errState[1];

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
        function removeAtt(idx) {
            var next = atts.slice(); next.splice(idx, 1); setAtts(next);
        }

        function submit() {
            setSending(true); setErr(null);
            // Derive plain text from the contenteditable HTML so recipients
            // whose clients prefer text/plain (and our own inbox feed snippets)
            // get readable content.
            var bodyPlain = htmlToPlain(bodyHtml);
            restPost('send', {
                to:          to,
                subject:     subject,
                body_plain:  bodyPlain,
                body_html:   bodyHtml,
                thread_id:   initial.threadId || undefined,
                attachments: atts.map(function (a) { return { filename: a.filename, content_type: a.content_type, content_b64: a.content_b64 }; }),
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
                          <button type="button" class="em-inbox-att-remove" onClick=${function () { removeAtt(i); }}>×</button>
                        </li>
                      `;
                  })}
                </ul>
              `}
            </div>
            ${err && html`<${Notice} status="error" isDismissible=${false}>${err}<//>`}
            <div class="em-inbox-composer-actions">
              <${Button} variant="tertiary" onClick=${props.onClose} disabled=${sending}>Cancel<//>
              <${Button} variant="primary"  onClick=${submit}        disabled=${sending || !to || !bodyHtml.replace(/<[^>]*>/g,'').trim()}>
                ${sending ? html`<${Spinner} />` : 'Send'}
              <//>
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
                      ${m.snippet && html`<div class="em-inbox-search-snippet">${m.snippet}…</div>`}
                    </li>
                  `;
              })}
            </ul>
          </aside>
        `;
    }

    function FeedView(props) {
        var st = useState({ loading: true, items: [], total: 0, page: 1, err: null, counts: null });
        var state = st[0], setState = st[1];
        var filterState = useState('all'); var filter = filterState[0], setFilter = filterState[1];
        var selState = useState({});      var selected = selState[0], setSelected = selState[1];
        var loadingMoreState = useState(false); var loadingMore = loadingMoreState[0], setLoadingMore = loadingMoreState[1];
        var inbox = props.inbox;
        var PER_PAGE = 50;

        function fetchPage(page, append) {
            var q = 'threads?inbox=' + encodeURIComponent(inbox) + '&per_page=' + PER_PAGE + '&page=' + page;
            if (filter === 'unread')   q += '&unread=1';
            if (filter === 'archived') q += '&archived=1';
            if (filter === 'trashed')  q += '&trashed=1';
            if (filter === 'starred')  q += '&starred=1';
            return restGet(q);
        }

        useEffect(function () {
            if (!inbox) return;
            setState({ loading: true, items: [], total: 0, page: 1, err: null, counts: null });
            setSelected({});
            fetchPage(1, false)
                .then(function (data) { setState({ loading: false, items: data.items || [], total: data.total, page: data.page, err: null, counts: data.counts || null }); })
                .catch(function (e) { setState({ loading: false, items: [], total: 0, page: 1, err: e.message || 'Failed to load threads', counts: null }); });
        }, [inbox, filter, props.refreshKey]);

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
                onClick=${function () { setFilter(key); setSelected({}); }}>${label}</button>`;
        };

        var hasMore = state.items.length < (Number(state.total) || 0);
        var allOnPageSelected = state.items.length > 0 && state.items.every(function (t) { return selected[Number(t.id)]; });

        return html`
          <aside class="em-inbox-feed">
            <header class="em-inbox-feed-header">
              <div class="em-inbox-filters">
                ${btn('all',      'All' + (counts.total != null ? ' · ' + counts.total : ''))}
                ${btn('unread',   'Unread' + (counts.unread != null ? ' · ' + counts.unread : ''))}
                ${btn('starred',  '★ Starred' + (counts.starred != null ? ' · ' + counts.starred : ''))}
                ${btn('archived', 'Archived' + (counts.archived != null ? ' · ' + counts.archived : ''))}
                ${btn('trashed',  'Trash' + (counts.trashed != null ? ' · ' + counts.trashed : ''))}
              </div>
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
            ${state.items.length === 0
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
                      return html`
                        <li
                          key=${t.id}
                          class="em-inbox-thread-row${open ? ' is-open' : ''}${unread ? ' is-unread' : ''}${selected[tid] ? ' is-selected' : ''}"
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
                            onClick=${function (e) {
                                e.stopPropagation();
                                restPost('threads/' + tid + '/' + (starred ? 'unstar' : 'star'), {})
                                    .then(function () { props.onBulkApplied && props.onBulkApplied(); });
                            }}
                          >${starred ? '★' : '☆'}</button>
                          <div class="em-inbox-thread-body" onClick=${function () { props.onOpenThread(tid); }}>
                            <div class="em-inbox-thread-sender">${t.last_sender || '(no sender)'}</div>
                            <div class="em-inbox-thread-subject">${t.subject_first || '(no subject)'}</div>
                            <div class="em-inbox-thread-meta">${t.message_count} msg · ${formatDate(t.updated_at)}</div>
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
                <${Button} variant="primary" onClick=${function () { props.onReply && props.onReply(state.thread, lastMsg); }}>Reply<//>
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

        function allowSenderImages() {
            restPost('senders/show-images', { sender: msg.sender, show: true })
                .then(function () { setImagesUnlocked(true); });
        }

        var status     = msg.delivery_status || (props.isMine ? 'sent' : null);
        var statusInfo = renderDeliveryStatus(status, msg);
        var blockedN   = Number(msg.images_blocked || 0);
        var showBlockedBanner = blockedN > 0 && !imagesUnlocked && !msg.images_show_for_sender;

        return html`
          <${Card} className="em-inbox-message ${open ? 'is-open' : 'is-collapsed'} ${props.isMine ? 'is-mine' : ''} ${status ? 'is-delivery-' + status : ''}">
            <${CardHeader} onClick=${function () { setOpen(!open); }}>
              <div class="em-inbox-message-from">${props.isMine ? 'Me' : msg.sender}</div>
              <div class="em-inbox-message-meta">
                ${statusInfo}
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

    function humanBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
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
