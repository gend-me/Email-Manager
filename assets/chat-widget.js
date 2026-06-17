/**
 * Chat Widget (slice 3a).
 *
 * Floating chat surface available on every page once logged in.
 *
 * Desktop (≥ 720px):
 *   ┌─ Launcher (bottom-right) ── opens the thread switcher panel
 *   └─ Chat boxes (stacked from the right) for each open conversation
 *      with header / message scroll / compose input
 *
 * Mobile (< 720px):
 *   ┌─ Launcher (bottom-right) — same affordance
 *   └─ Tapping opens a full-screen drawer (switcher + active chat)
 *
 * Uses wp.element (preact-compat) + htm so this matches the inbox
 * SPA's build-free pattern.
 */
(function () {
    if (!window.wp || !wp.element) return;
    if (!window.EM_CHAT_CONFIG) return;

    var cfg = window.EM_CHAT_CONFIG;
    var html = window.htm
        ? htm.bind(wp.element.createElement)
        : null;
    if (! html) {
        // Inline-minified htm v3.1.1 — same vendored copy the inbox SPA uses.
        // (Avoids depending on the inbox script load order.)
        var n=function(t,s,r,e){var u;s[0]=0;for(var h=1;h<s.length;h++){var p=s[h++],a=s[h]?(s[0]|=p?1:2,r[s[h++]]):s[++h];3===p?e[0]=a:4===p?e[1]=Object.assign(e[1]||{},a):5===p?(e[1]=e[1]||{})[s[++h]]=a:6===p?e[1][s[++h]]+=a+"":p?(u=t.apply(a,n(t,a,r,["",null])),e.push(u),a[0]?s[0]|=2:(s[h-2]=0,s[h]=u)):e.push(a)}return e},t=new Map;function e(s){var r=t.get(this);return r||(r=new Map,t.set(this,r)),(r=n(this,r.get(s)||(r.set(s,r=function(n){for(var t,s,r=1,e="",u="",h=[0],p=function(n){1===r&&(n||(e=e.replace(/^\s*\n\s*|\s*\n\s*$/g,"")))?h.push(0,n,e):3===r&&(n||e)?(h.push(3,n,e),r=2):2===r&&"..."===e&&n?h.push(4,n,0):2===r&&e&&!n?h.push(5,0,!0,e):r>=5&&((e||!n&&5===r)&&(h.push(r,0,e,s),r=6),n&&(h.push(r,n,0,s),r=6)),e=""},a=0;a<n.length;a++){a&&(1===r&&p(),p(a));for(var l=0;l<n[a].length;l++)t=n[a][l],1===r?"<"===t?(p(),h=[h],r=3):e+=t:4===r?"--"===e&&">"===t?(r=1,e=""):e=t+e[0]:u?t===u?u="":e+=t:'"'===t||"'"===t?u=t:">"===t?(p(),r=1):r&&("="===t?(r=5,s=e,e=""):"/"===t&&(r<5||">"===n[a][l+1])?(p(),3===r&&(h=h[0]),r=h,(h=h[0]).push(2,0,r),r=0):" "===t||"\t"===t||"\n"===t||"\r"===t?(p(),r=2):e+=t),3===r&&"!--"===e&&(r=4,h=h[0])}return p(),h}(s)),r),arguments,[])).length>1?r:r[0]}
            window.htm=e;
            html = e.bind(wp.element.createElement);
    }
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef || function (init) { return { current: init }; };
    var apiFetch = wp.apiFetch || function (opts) {
        return fetch(opts.url, {
            method: opts.method || 'GET',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: opts.data ? JSON.stringify(opts.data) : undefined,
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    };
    // Slice 3e.1: ensure our X-WP-Nonce rides every wp.apiFetch call.
    // Without this, wp.apiFetch ships requests with no nonce → cookie
    // auth fails → wp-oauth-server's "block unauthenticated REST"
    // filter denies with `rest_not_authorized: Authorization is required.`
    if (wp.apiFetch && wp.apiFetch.createNonceMiddleware && cfg.nonce) {
        try { wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(cfg.nonce)); } catch (e) {}
    }

    function restGet(path) {
        return apiFetch({ url: cfg.restRoot + path, method: 'GET' });
    }
    function restPost(path, data) {
        return apiFetch({ url: cfg.restRoot + path, method: 'POST', data: data || {} });
    }

    // Slice 3e.5: thread-GET prefetch. The inbox row handler calls
    // emChatPrefetchThread(id) on mousedown/touchstart so the network
    // round-trip races the click — by the time ChatBox.load() runs,
    // the response is either already here or close to it. We dedupe
    // by id and expire entries after 10s so a stale prefetch can't
    // out-vote a fresh refresh.
    var __emPrefetch = {};
    window.emChatPrefetchThread = function (id) {
        id = parseInt(id, 10);
        if (! id || __emPrefetch[id]) return;
        var p = restGet('threads/' + id);
        __emPrefetch[id] = { promise: p, at: Date.now() };
        p.then(function (d) {
            // Park the resolved value so a later consumer doesn't
            // re-hit the network. takeCachedThread() pulls + expires it.
            __emPrefetch[id] = { value: d, at: Date.now() };
            setTimeout(function () {
                var e = __emPrefetch[id];
                if (e && (Date.now() - e.at) >= 10000) delete __emPrefetch[id];
            }, 10000);
        }, function () { delete __emPrefetch[id]; });
    };
    function takeCachedThread(id) {
        var entry = __emPrefetch[id];
        if (! entry) return null;
        delete __emPrefetch[id];
        if (entry.value) return Promise.resolve(entry.value);
        return entry.promise || null;
    }

    // Some server errors (PHP fatals) come back as an HTML body. Strip
    // tags so the chat panel never renders raw markup as the error
    // message — show a clean human string instead.
    function cleanError(e) {
        var m = (e && e.message) ? String(e.message) : 'Something went wrong';
        if (/<[a-z!\/][\s\S]*?>/i.test(m)) {
            return 'Chat is temporarily unavailable. Please try again in a moment.';
        }
        return m;
    }

    function formatTime(s) {
        if (!s) return '';
        var d = new Date(s);
        if (isNaN(d)) return s;
        var now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    // ── Live unread dock (slice 3e) ──────────────────────────────────
    // A horizontal stack of avatars rendered LEFT of the launcher.
    // Each chip represents a sender with unread messages; clicking it
    // opens that specific thread. Entrance is staggered so the dock
    // appears to "build itself" as senders come in. Each chip also
    // carries an unread-count badge and a pulse halo while it lives.
    function UnreadDock(props) {
        var items = props.items || [];
        if (! items.length) return null;
        return html`
          <div class="em-chat-dock" role="list" aria-label="Unread conversations">
            ${items.map(function (it, i) {
                var n = it.unread > 99 ? '99+' : String(it.unread);
                var initials = String(it.display_name || '?')
                    .split(/\s+/).map(function (s) { return s.charAt(0); }).join('').slice(0, 2).toUpperCase();
                return html`
                  <button
                    type="button"
                    role="listitem"
                    key=${it.thread_id}
                    class="em-chat-dock-chip"
                    style=${{ '--em-i': i }}
                    title=${it.display_name + (it.excerpt ? ' — ' + it.excerpt : '')}
                    aria-label=${it.display_name + ' sent ' + n + ' new message' + (it.unread === 1 ? '' : 's')}
                    onClick=${function () { props.onOpen(it); }}>
                    <span class="em-chat-dock-halo" aria-hidden="true"></span>
                    <span class="em-chat-dock-ring" aria-hidden="true"></span>
                    <span class="em-chat-dock-avatar">
                      ${it.avatar_url
                        ? html`<img src=${it.avatar_url} alt="" loading="lazy" />`
                        : html`<span class="em-chat-dock-initials">${initials}</span>`}
                    </span>
                    <span class="em-chat-dock-badge">${n}</span>
                  </button>
                `;
            })}
          </div>
        `;
    }

    // ── Launcher button + global widget ──────────────────────────────
    function Widget() {
        var openState     = useState(false);
        var open          = openState[0], setOpen = openState[1];
        var unreadState   = useState(0);
        var unread        = unreadState[0], setUnread = unreadState[1];
        var dockState     = useState([]);   // per-sender unread items
        var dock          = dockState[0], setDock = dockState[1];
        var dockSeenRef   = useRef({});     // thread_id -> animation seq number
        var dockSeqRef    = useRef(0);
        var openBoxesState= useState([]); // array of thread summaries
        var openBoxes     = openBoxesState[0], setOpenBoxes = openBoxesState[1];
        var isMobileState = useState(window.innerWidth < 720);
        var isMobile      = isMobileState[0], setIsMobile = isMobileState[1];

        // Resize listener — flip layout between desktop chat-boxes
        // and mobile drawer.
        useEffect(function () {
            function onResize() { setIsMobile(window.innerWidth < 720); }
            window.addEventListener('resize', onResize);
            return function () { window.removeEventListener('resize', onResize); };
        }, []);

        // Poll unread count every 15s while visible. The endpoint now
        // returns a per-sender items[] used by the live notification dock.
        useEffect(function () {
            function tick() {
                if (document.visibilityState !== 'visible') return;
                restGet('unread-count').then(function (d) {
                    setUnread(Number(d.unread || 0));
                    var items = Array.isArray(d.items) ? d.items : [];
                    // Stamp each item with a stable sequence number on
                    // first appearance so its entrance animation only
                    // fires once (and never re-fires across polls).
                    var seen = dockSeenRef.current;
                    items.forEach(function (it) {
                        var k = String(it.thread_id);
                        if (! (k in seen)) { seen[k] = dockSeqRef.current++; }
                        it._seq = seen[k];
                    });
                    // Reap stale sequence numbers for threads that
                    // dropped off the unread list (so a future re-arrival
                    // gets a fresh entrance).
                    var liveKeys = {};
                    items.forEach(function (it) { liveKeys[String(it.thread_id)] = 1; });
                    Object.keys(seen).forEach(function (k) { if (! liveKeys[k]) delete seen[k]; });
                    // Sort: newest seq last (so it animates in at the
                    // outer end of the stack), preserving display order.
                    items.sort(function (a, b) { return a._seq - b._seq; });
                    setDock(items);
                }).catch(function () {});
            }
            tick();
            var h = setInterval(tick, 15000);
            function onVis() { if (document.visibilityState === 'visible') tick(); }
            document.addEventListener('visibilitychange', onVis);
            return function () { clearInterval(h); document.removeEventListener('visibilitychange', onVis); };
        }, []);

        // Listen for site-wide "open chat with user" events fired by
        // other modules (e.g. profile "Message" buttons).
        useEffect(function () {
            function onOpen(e) {
                var detail = e.detail || {};
                if (detail.thread) {
                    pushBox(detail.thread);
                } else if (detail.user_id) {
                    // Start a fresh thread in a virtual box (no id yet).
                    pushBox({ id: 0, others: [{ user_id: detail.user_id, display_name: detail.display_name || '', avatar_url: detail.avatar_url || '' }] });
                }
            }
            window.addEventListener('em-chat:open', onOpen);
            return function () { window.removeEventListener('em-chat:open', onOpen); };
        }, [openBoxes]);

        function pushBox(thread) {
            // Avoid duplicate boxes for the same thread id.
            if (thread.id) {
                if (openBoxes.some(function (b) { return b.id === thread.id; })) {
                    return;
                }
            }
            // Cap concurrent boxes (desktop only).
            var max = isMobile ? 1 : 3;
            var next = openBoxes.slice(0, max - 1).concat([thread]);
            setOpenBoxes(next);
            // On mobile we collapse the switcher panel.
            if (isMobile) setOpen(false);
        }
        function closeBox(idx) {
            var next = openBoxes.slice();
            next.splice(idx, 1);
            setOpenBoxes(next);
        }
        function openSwitcher(thread) {
            pushBox(thread);
        }

        function openThreadById(item) {
            pushBox({
                id: item.thread_id,
                others: [{
                    user_id: item.user_id,
                    display_name: item.display_name,
                    avatar_url: item.avatar_url,
                }],
            });
            // Optimistically drop this sender's dock chip — the next
            // poll will reconcile if there are still unread messages.
            setDock(dock.filter(function (d) { return d.thread_id !== item.thread_id; }));
            delete dockSeenRef.current[String(item.thread_id)];
        }

        return html`
          <div class=${'em-chat-widget ' + (isMobile ? 'is-mobile' : 'is-desktop')}>
            <${UnreadDock} items=${dock} onOpen=${openThreadById} />
            <button
              type="button"
              class=${'em-chat-launcher ' + (open ? 'is-active' : '') + (unread > 0 ? ' has-unread' : '')}
              aria-label=${'Open chat' + (unread ? ' (' + unread + ' unread)' : '')}
              onClick=${function () { setOpen(!open); }}>
              <span class="em-chat-launcher-icon" aria-hidden="true">💬</span>
              ${unread > 0 && html`<span class="em-chat-launcher-badge">${unread > 99 ? '99+' : unread}</span>`}
            </button>
            ${open && html`<${SwitcherPanel}
              isMobile=${isMobile}
              onClose=${function () { setOpen(false); }}
              onOpenThread=${openSwitcher} />`}
            <div class="em-chat-boxes">
              ${openBoxes.map(function (th, i) {
                return html`<${ChatBox}
                  key=${th.id || ('new-' + i)}
                  thread=${th}
                  onClose=${function () { closeBox(i); }}
                  onUpdate=${function (updated) {
                      var next = openBoxes.slice();
                      next[i] = updated;
                      setOpenBoxes(next);
                  }} />`;
              })}
            </div>
          </div>
        `;
    }

    function SwitcherPanel(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        var qState = useState(''); var q = qState[0], setQ = qState[1];
        var searchState = useState({ loading: false, items: [] });
        var search = searchState[0], setSearch = searchState[1];

        function reload() {
            setState({ loading: true, items: state.items, err: null });
            restGet('threads').then(function (d) {
                setState({ loading: false, items: d.items || [], err: null });
            }).catch(function (e) {
                setState({ loading: false, items: [], err: cleanError(e) });
            });
        }
        useEffect(reload, []);

        useEffect(function () {
            if (!q.trim()) { setSearch({ loading: false, items: [] }); return; }
            var clean = q.trim();
            var handle = setTimeout(function () {
                setSearch({ loading: true, items: search.items });
                restGet('users/search?q=' + encodeURIComponent(clean))
                    .then(function (d) { setSearch({ loading: false, items: d.items || [] }); })
                    .catch(function () { setSearch({ loading: false, items: [] }); });
            }, 250);
            return function () { clearTimeout(handle); };
        }, [q]);

        function startWith(user) {
            // Open a virtual box keyed to the user; the box will create
            // a real thread when the user sends the first message.
            props.onOpenThread({
                id: 0,
                others: [{
                    user_id: user.user_id,
                    display_name: user.display_name,
                    avatar_url: user.avatar_url,
                }],
            });
            setQ('');
        }

        return html`
          <div class=${'em-chat-panel ' + (props.isMobile ? 'is-mobile' : 'is-desktop')}>
            <header class="em-chat-panel-header">
              <span class="em-chat-panel-title">Chat</span>
              <button type="button" class="em-chat-panel-close" onClick=${props.onClose} aria-label="Close chat">×</button>
            </header>
            <div class="em-chat-panel-search">
              <input
                type="search"
                placeholder="Search members…"
                value=${q}
                onChange=${function (e) { setQ(e.target.value); }} />
            </div>
            ${q && html`
              <div class="em-chat-panel-search-results" role="listbox">
                ${search.loading && html`<div class="em-chat-panel-empty">Searching…</div>`}
                ${!search.loading && search.items.length === 0 && html`<div class="em-chat-panel-empty">No members match "${q}".</div>`}
                ${search.items.map(function (u, i) {
                  return html`
                    <button
                      type="button"
                      key=${u.user_id}
                      class="em-chat-row em-chat-row--user"
                      style=${{ '--em-i': i }}
                      onClick=${function () { startWith(u); }}>
                      <img class="em-chat-row-avatar" src=${u.avatar_url} alt="" />
                      <span class="em-chat-row-id">
                        <span class="em-chat-row-name">${u.display_name}</span>
                        <span class="em-chat-row-sub">@${u.username || ''}</span>
                      </span>
                      <span class="em-chat-row-action" aria-hidden="true">→</span>
                    </button>
                  `;
                })}
              </div>
            `}
            ${!q && html`
              <ul class="em-chat-panel-threads" role="list">
                ${state.loading && html`<li class="em-chat-panel-empty">Loading chats…</li>`}
                ${state.err && html`<li class="em-chat-panel-empty em-chat-err">${state.err}</li>`}
                ${!state.loading && !state.err && state.items.length === 0 && html`<li class="em-chat-panel-empty">No conversations yet. Search above to start one.</li>`}
                ${state.items.map(function (t, i) {
                  var other = (t.others && t.others[0]) || {};
                  return html`
                    <li key=${t.id} style=${{ '--em-i': i }}>
                      <button type="button" class=${'em-chat-row ' + (t.unread > 0 ? 'is-unread' : '')} onClick=${function () { props.onOpenThread(t); }}>
                        <img class="em-chat-row-avatar" src=${other.avatar_url} alt="" />
                        <span class="em-chat-row-id">
                          <span class="em-chat-row-name">${other.display_name || '(unknown)'}</span>
                          <span class="em-chat-row-sub">${t.last_message_excerpt || ''}</span>
                        </span>
                        <span class="em-chat-row-meta">
                          <span class="em-chat-row-time">${formatTime(t.last_at)}</span>
                          ${t.unread > 0 && html`<span class="em-chat-row-unread">${t.unread}</span>`}
                        </span>
                      </button>
                    </li>
                  `;
                })}
              </ul>
            `}
          </div>
        `;
    }

    function ChatBox(props) {
        var st = useState({ loading: true, thread: props.thread, messages: [], err: null });
        var state = st[0], setState = st[1];
        var draftState = useState(''); var draft = draftState[0], setDraft = draftState[1];
        var collapsedState = useState(false); var collapsed = collapsedState[0], setCollapsed = collapsedState[1];
        var sendingState = useState(false); var sending = sendingState[0], setSending = sendingState[1];
        var scrollRef = useRef(null);

        // Slice 3e.3: `silent` skips the loading-flash AND preserves the
        // already-resolved thread (with its recipient + avatar) instead
        // of reverting to props.thread (which was only the click-payload
        // — often {id, others: []}). Without this, every 15s tick
        // wiped the recipient back to "(no recipient)" + broken image
        // until the fetch resolved.
        function load(silent) {
            if (!props.thread.id) {
                // Virtual thread for a new conversation — nothing to fetch yet.
                setState(function (prev) {
                    return { loading: false, thread: prev.thread || props.thread, messages: [], err: null };
                });
                return;
            }
            if (!silent) {
                setState(function (prev) {
                    return { loading: true, thread: prev.thread || props.thread, messages: prev.messages, err: null };
                });
            }
            // Slice 3e.5: consume the row-mousedown prefetch if it's
            // still live — saves the round-trip in the common case.
            var p = takeCachedThread(props.thread.id) || restGet('threads/' + props.thread.id);
            p.then(function (d) {
                setState(function (prev) {
                    return {
                        loading: false,
                        // Prefer fresh server data, but fall back to whatever
                        // recipient info we already had so a sparse response
                        // never blanks the header.
                        thread: d.thread || prev.thread || props.thread,
                        messages: d.messages || [],
                        err: null,
                    };
                });
                restPost('threads/' + props.thread.id + '/read', {}).catch(function () {});
            }).catch(function (e) {
                setState(function (prev) {
                    return {
                        loading: false,
                        thread: prev.thread || props.thread,
                        messages: prev.messages,
                        err: cleanError(e),
                    };
                });
            });
        }
        useEffect(function () { load(false); }, [props.thread.id]);

        // Auto-scroll to bottom when messages list changes.
        useEffect(function () {
            if (scrollRef.current) {
                scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
            }
        }, [state.messages.length]);

        // Refresh every 15s while open so incoming replies appear. Silent
        // mode so the box doesn't flash the spinner + "(no recipient)"
        // header every tick.
        useEffect(function () {
            if (!props.thread.id) return;
            var h = setInterval(function () {
                if (document.visibilityState !== 'visible') return;
                if (collapsed) return;
                load(true);
            }, 15000);
            return function () { clearInterval(h); };
        }, [props.thread.id, collapsed]);

        function send() {
            var content = draft.trim();
            if (!content) return;
            setSending(true);
            if (state.thread.id) {
                restPost('threads/' + state.thread.id + '/send', { content: content })
                    .then(function () {
                        setDraft('');
                        setSending(false);
                        load();
                    })
                    .catch(function () { setSending(false); });
            } else {
                // New thread — needs to_user_id from the other participant.
                var other = state.thread.others && state.thread.others[0];
                if (!other) { setSending(false); return; }
                restPost('threads/new', { to_user_id: other.user_id, content: content, subject: 'Chat' })
                    .then(function (d) {
                        setDraft('');
                        setSending(false);
                        if (d.thread) {
                            var updated = d.thread;
                            setState({ loading: false, thread: updated, messages: state.messages, err: null });
                            props.onUpdate && props.onUpdate(updated);
                        }
                    })
                    .catch(function () { setSending(false); });
            }
        }

        var other = (state.thread.others && state.thread.others[0]) || { display_name: '(no recipient)', avatar_url: '' };
        return html`
          <div class=${'em-chat-box ' + (collapsed ? 'is-collapsed' : '')}>
            <header class="em-chat-box-header" onClick=${function () { setCollapsed(!collapsed); }}>
              <img class="em-chat-box-avatar" src=${other.avatar_url || ''} alt="" />
              <span class="em-chat-box-name">${other.display_name}</span>
              <button type="button" class="em-chat-box-close" onClick=${function (e) { e.stopPropagation(); props.onClose && props.onClose(); }} aria-label="Close chat box">×</button>
            </header>
            ${!collapsed && html`
              <div class="em-chat-box-body" ref=${function (n) { scrollRef.current = n; }}>
                ${state.loading && html`<div class="em-chat-panel-empty">Loading…</div>`}
                ${state.err && html`<div class="em-chat-panel-empty em-chat-err">${state.err}</div>`}
                ${!state.loading && state.messages.length === 0 && state.thread.id && html`
                  <div class="em-chat-panel-empty">No messages yet. Say hello.</div>
                `}
                ${state.messages.map(function (m, i) {
                  return html`
                    <div key=${m.id} class=${'em-chat-bubble ' + (m.is_self ? 'is-self' : 'is-other')} style=${{ '--em-i': i }}>
                      ${!m.is_self && html`<img class="em-chat-bubble-avatar" src=${m.sender_avatar} alt="" />`}
                      <span class="em-chat-bubble-body">
                        <span class="em-chat-bubble-text" dangerouslySetInnerHTML=${{ __html: m.message }}></span>
                        <span class="em-chat-bubble-time">${formatTime(m.date_sent)}</span>
                      </span>
                    </div>
                  `;
                })}
              </div>
              <form class="em-chat-box-compose" onSubmit=${function (e) { e.preventDefault(); send(); }}>
                <input
                  type="text"
                  placeholder=${'Message ' + other.display_name + '…'}
                  value=${draft}
                  disabled=${sending}
                  onChange=${function (e) { setDraft(e.target.value); }} />
                <button type="submit" disabled=${sending || !draft.trim()} aria-label="Send">
                  ${sending ? '…' : '➤'}
                </button>
              </form>
            `}
          </div>
        `;
    }

    function mount() {
        var root = document.getElementById('em-chat-widget-root');
        if (!root) return;
        root.removeAttribute('data-loading');
        root.textContent = '';
        var tree = html`<${Widget} />`;
        if (wp.element.createRoot) {
            try { wp.element.createRoot(root).render(tree); return; }
            catch (e) { /* fall through */ }
        }
        if (typeof wp.element.render === 'function') wp.element.render(tree, root);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
    // Expose a fire-from-anywhere helper that other scripts can call.
    window.emChatOpenThread = function (thread) {
        window.dispatchEvent(new CustomEvent('em-chat:open', { detail: { thread: thread } }));
    };
    window.emChatOpenUser = function (user) {
        window.dispatchEvent(new CustomEvent('em-chat:open', { detail: user }));
    };
})();
