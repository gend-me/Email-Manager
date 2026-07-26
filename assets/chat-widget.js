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
    // v8.2 Phase 45+: the "Add new Agent" flow reuses gend-society + projects
    // REST routes that live OUTSIDE this widget's em/v1/chat root. Derive the
    // wp-json root from restRoot so we can reach gs/v1 + psoo/v1.
    var wpJsonRoot = String(cfg.restRoot || '').replace(/em\/v1\/chat\/?$/, '');
    var gsRoot   = wpJsonRoot + 'gs/v1';
    var psooRoot = wpJsonRoot + 'psoo/v1';
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
    // createPortal lets the "Add new Agent" modal render at document.body so it
    // escapes the chat panel (which has overflow:hidden + a transformed/filtered
    // ancestor that would otherwise trap a position:fixed child inside the widget).
    var createPortal = wp.element.createPortal || null;

    // ── Error boundary ──────────────────────────────────────────────────────
    // A render exception anywhere inside the nested sequence editor would, with
    // no boundary, bubble to the widget root and BLANK the entire widget until
    // reload. This boundary contains it: it renders the error text inside the
    // modal (so it's visible + reportable) and keeps the rest of the widget
    // alive. Built on wp.element.Component (preact compat).
    var Component = wp.element.Component;
    function SeqErrorBoundary() { Component.apply(this, arguments); this.state = { err: null }; }
    if (Component) {
        SeqErrorBoundary.prototype = Object.create(Component.prototype);
        SeqErrorBoundary.prototype.constructor = SeqErrorBoundary;
        SeqErrorBoundary.prototype.componentDidCatch = function (err) {
            try { console.error('[gend] sequence editor crashed:', err); } catch (e) {}
            this.setState({ err: err });
        };
        SeqErrorBoundary.prototype.render = function () {
            var self = this;
            if (this.state && this.state.err) {
                var e = this.state.err;
                var msg = (e && (e.stack || e.message)) ? String(e.stack || e.message) : String(e);
                return html`
                  <div class="em-chat-agent-modal em-chat-seq-modal" role="dialog" aria-modal="true">
                    <div class="em-chat-agent-backdrop" onClick=${function () { self.props.onClose && self.props.onClose(); }}></div>
                    <div class="em-chat-agent-dialog em-chat-seq-dialog">
                      <header class="em-chat-agent-head">
                        <span class="em-chat-agent-title">Sequence builder error</span>
                        <button type="button" class="em-chat-agent-x" onClick=${function () { self.props.onClose && self.props.onClose(); }} aria-label="Close">×</button>
                      </header>
                      <div class="em-chat-agent-body" style=${{ whiteSpace: 'pre-wrap', fontSize: '12px', color: '#ffb8bd', padding: '16px', fontFamily: 'monospace' }}>${msg}</div>
                    </div>
                  </div>`;
            }
            return this.props.children;
        };
    }

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
                    class=${'em-chat-dock-chip' + (it.is_agent ? ' is-agent' : (it.project_id ? ' is-project' : ''))}
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

    // v8.2 Phase 45: classify a thread for the Members/Agents/Projects tabs.
    // Mirrors the hub Messages-page semantics (gs_chat_prune_threads_by_tab):
    //   members  → any thread whose other participant is NOT an agent
    //   agents   → threads with an agent participant
    //   projects → threads attached to a project (Phase-44 linkage)
    // Members + Projects intentionally overlap (a project-attached human
    // thread shows under both), exactly as the hub page behaves.
    function emThreadMatchesTab(t, tab) {
        if (tab === 'agents')   return !!t.is_agent;
        if (tab === 'projects') return !!t.project_id;
        return !t.is_agent; // 'members' (default)
    }

    // v8.2 Phase 45+ (redesign 2): "Add new Agent" dashboard modal. A single
    // 80vw scrollable surface (createPortal'd to <body>) with futuristic section
    // cards. The OLD top "Run target" multiselect POOL is REMOVED. The agent now
    // belongs to a SINGLE Home org (one Web App), chosen via an AJAX search that
    // resolves to {homeGroup, homeName, homeAvatar}. That home group is REQUIRED
    // and drives billing, department, reports-to + the sequence binding.
    //
    //   Sections (top → bottom):
    //     1. Home org (single Web App, AJAX search)  |  Billing account  ─ 2-col
    //     2. Name *                                                      ─ full
    //     3. Personality (cached, optional)                             ─ full
    //     4. Role / title                                               ─ full
    //     5. Description                                                ─ full
    //     6. Department (select + "+ New")  |  Reports to               ─ 2-col
    //     7. Prompt sequences (list + nested builder popup)             ─ full
    //
    // Submit POSTs psoo/v1/agents/create with run_target:'webapp' bound to the
    // home group, persists each prompt sequence via
    // psoo/v1/business-plan/sequences/{seqId}, then best-effort gs/v1/agent-welcome.
    function AgentCreate(props) {
        // ── Home org (SINGLE Web App) ──────────────────────────────────────
        // homeGroup = group_id (number|null); homeName/homeAvatar = display.
        var hgState = useState(null); var homeGroup = hgState[0], setHomeGroup = hgState[1];
        var hnState = useState(''); var homeName = hnState[0], setHomeName = hnState[1];
        var haState = useState(''); var homeAvatar = haState[0], setHomeAvatar = haState[1];
        // Web-App AJAX search (only used while no home org is chosen).
        var qState = useState(''); var q = qState[0], setQ = qState[1];
        var waState = useState({ loading: false, items: [], err: null });
        var webApps = waState[0], setWebApps = waState[1];
        // ALL the member's connected devices (psoo/v1/devices), fetched once on
        // mount — they are no longer a top-level pool, but each prompt step can
        // still target one of them.
        var devState = useState({ loaded: false, loading: false, items: [], err: null });
        var allDevices = devState[0], setAllDevices = devState[1];

        // ── Form fields ────────────────────────────────────────────────────
        var fName = useState(''); var name = fName[0], setName = fName[1];
        var fRole = useState(''); var role = fRole[0], setRole = fRole[1];
        var fDept = useState(''); var dept = fDept[0], setDept = fDept[1];
        var fPersona = useState(''); var persona = fPersona[0], setPersona = fPersona[1];
        var fBilling = useState(''); var billing = fBilling[0], setBilling = fBilling[1];
        var fDesc = useState(''); var desc = fDesc[0], setDesc = fDesc[1];
        var fReportsTo = useState(''); var reportsTo = fReportsTo[0], setReportsTo = fReportsTo[1];
        // Options fetched once the HOME group resolves.
        var rtoState = useState([]); var reportsToOptions = rtoState[0], setReportsToOptions = rtoState[1];
        var billOptState = useState([]); var billingOptions = billOptState[0], setBillingOptions = billOptState[1];
        var deptState = useState([]); var departments = deptState[0], setDepartments = deptState[1];

        // ── Prompt sequences (LIST) ────────────────────────────────────────
        // An agent can have MANY sequences. Each sequence:
        //   { id, name, prompts:[{ id, text, deviceRef ('' = gend.me hub), aiIntegration }] }
        // The step builder lives in a NESTED popup (SequenceEditor) — the list
        // here only shows summaries + Edit/Remove + an "Add" button.
        var seqState = useState([]);
        var sequences = seqState[0], setSequences = seqState[1];
        var seqSeqRef = useRef(0); // monotonic counter for fresh sequence ids
        // Nested editor: null = closed; otherwise the sequence object being edited.
        var editState = useState(null); var editing = editState[0], setEditing = editState[1];

        var statusState = useState(null); var status = statusState[0], setStatus = statusState[1];
        var busyState = useState(false); var busy = busyState[0], setBusy = busyState[1];

        // GEND.me hub model list (per-prompt AI integration when no device chosen).
        var GENDME_MODELS = ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'];

        // Look up a device record (full object incl. ai_integrations) by id.
        function deviceById(id) {
            for (var i = 0; i < allDevices.items.length; i++) {
                var d = allDevices.items[i];
                if (d && String(d.device_id != null ? d.device_id : d.id) === String(id)) { return d; }
            }
            return null;
        }

        // Pick a Web App as the home org (clears the search dropdown).
        function pickHome(g) {
            setHomeGroup(parseInt(g.group_id, 10) || null);
            setHomeName(g.name || ('Group ' + g.group_id));
            setHomeAvatar(g.avatar || '');
            setQ('');
            setWebApps({ loading: false, items: [], err: null });
        }
        // Clear the chosen home org so the search input returns.
        function changeHome() {
            setHomeGroup(null); setHomeName(''); setHomeAvatar('');
            // Dependent options reset via the homeGroup effect below.
        }

        // ── Fetch ALL the member's devices ONCE on mount. ──────────────────
        // Desktop devices are selectable per-step; mobile/server are disabled.
        useEffect(function () {
            setAllDevices({ loaded: false, loading: true, items: [], err: null });
            apiFetch({ url: psooRoot + '/devices', method: 'GET' })
                .then(function (d) {
                    var items = (d && Array.isArray(d.devices)) ? d.devices : (Array.isArray(d) ? d : []);
                    setAllDevices({ loaded: true, loading: false, items: items, err: null });
                })
                .catch(function (e) { setAllDevices({ loaded: true, loading: false, items: [], err: cleanError(e) }); });
        }, []);

        // ── Web-App AJAX search (debounced ~250ms). Empty term loads the
        // caller's own admin Web Apps. Skipped once a home org is chosen. ───
        useEffect(function () {
            if (homeGroup) { return; }
            var term = q.trim();
            var handle = setTimeout(function () {
                setWebApps({ loading: true, items: webApps.items, err: null });
                apiFetch({ url: gsRoot + '/agent-admin-groups?term=' + encodeURIComponent(term), method: 'GET' })
                    .then(function (d) {
                        var items = (d && d.groups) || [];
                        setWebApps({ loading: false, items: items, err: null });
                        // Convenience: if the caller administers exactly ONE Web App and
                        // hasn't typed a search, auto-select it as the home org so the
                        // home-gated fields (billing/department/reports-to) enable
                        // immediately instead of sitting disabled.
                        if (items.length === 1 && !homeGroup && !term) { pickHome(items[0]); }
                    })
                    .catch(function (e) { setWebApps({ loading: false, items: [], err: cleanError(e) }); });
            }, 250);
            return function () { clearTimeout(handle); };
        }, [q, homeGroup]);

        // ── When the HOME group changes, (re)load billing members, departments
        // + reports-to. Reset dependent selections so they can't dangle. ────
        useEffect(function () {
            setReportsTo(''); setBilling(''); setDept('');
            if (!homeGroup) {
                setReportsToOptions([]); setBillingOptions([]); setDepartments([]);
                return;
            }
            apiFetch({ url: gsRoot + '/agent-list?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) {
                    var agents = (d && d.agents) || [];
                    setReportsToOptions(agents.filter(function (a) { return a && a.slug; }));
                })
                .catch(function () { setReportsToOptions([]); });
            apiFetch({ url: gsRoot + '/group-members?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) {
                    var members = (d && d.members) || [];
                    setBillingOptions(members.filter(function (m) { return m && m.id != null; }));
                })
                .catch(function () { setBillingOptions([]); });
            apiFetch({ url: gsRoot + '/group-departments?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) {
                    var depts = (d && d.departments) || [];
                    setDepartments(depts.filter(function (x) { return x && x.name; }));
                })
                .catch(function () { setDepartments([]); });
        }, [homeGroup]);

        // ── Department: create-new control. Prompts for a name, POSTs it, then
        // adds it to the options + selects it. ─────────────────────────────
        function addDepartment() {
            if (!homeGroup) { return; }
            var nm = (typeof window !== 'undefined' && window.prompt)
                ? window.prompt('New department name') : '';
            nm = String(nm || '').trim();
            if (!nm) { return; }
            apiFetch({ url: gsRoot + '/group-departments', method: 'POST', data: { group_id: homeGroup, name: nm } })
                .then(function (d) {
                    var created = (d && d.department) ? d.department : { id: nm, name: nm };
                    var nextName = created.name || nm;
                    setDepartments(departments.concat([created]));
                    setDept(nextName);
                })
                .catch(function (e) { setStatus({ t: cleanError(e), k: 'err' }); });
        }

        // ── Sequence-list helpers ──────────────────────────────────────────
        function freshSeqId() {
            seqSeqRef.current = (seqSeqRef.current || 0) + 1;
            return 'seq_' + Date.now() + '_' + seqSeqRef.current;
        }
        // Open the nested editor for a brand-new sequence (one empty step).
        function openNewSequence() {
            setEditing({
                id: freshSeqId(),
                name: '',
                description: '',
                prompts: [{ id: 'p1', text: '', deviceRef: '', aiIntegration: '' }],
                _isNew: true,
                _n: sequences.length + 1, // default-name hint ("Sequence N")
            });
        }
        // Open the nested editor on an existing sequence (deep-ish copy so the
        // editor edits a draft; Cancel discards, Save writes back by id).
        function openEditSequence(seq) {
            var pos = 0;
            for (var i = 0; i < sequences.length; i++) { if (sequences[i].id === seq.id) { pos = i; break; } }
            setEditing({
                id: seq.id,
                name: seq.name || '',
                description: seq.description || '',
                prompts: (seq.prompts || []).map(function (p) { return Object.assign({}, p); }),
                _isNew: false,
                _n: pos + 1, // default-name hint ("Sequence N")
            });
        }
        function removeSequence(id) {
            setSequences(sequences.filter(function (s) { return s.id !== id; }));
        }
        // Upsert (by id) the saved sequence from the nested editor.
        function saveSequence(seq) {
            var found = false;
            var next = sequences.map(function (s) {
                if (s.id === seq.id) { found = true; return seq; }
                return s;
            });
            if (!found) { next = next.concat([seq]); }
            setSequences(next);
            setEditing(null);
        }
        // Count of non-empty steps, used for the list summary.
        function stepCount(seq) {
            return (seq.prompts || []).filter(function (p) { return p && String(p.text || '').trim() !== ''; }).length;
        }

        // ── Submit ─────────────────────────────────────────────────────────
        function slugify(s) {
            return String(s || '').toLowerCase().trim()
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        }
        function create() {
            if (!homeGroup) {
                setStatus({ t: 'Select a Home org (Web App).', k: 'warn' });
                return;
            }
            if (!name.trim()) { setStatus({ t: 'Give the agent a name first.', k: 'warn' }); return; }
            setBusy(true);
            setStatus({ t: 'Creating agent…', k: 'info' });

            // The agent's single home org IS its run target (a Web App).
            var homeRef = String(homeGroup);
            var runTargetsOut = [{ type: 'webapp', ref: homeRef, label: homeName }];

            apiFetch({ url: psooRoot + '/agents/create', method: 'POST', data: {
                group_id: homeGroup,
                name: name.trim(),
                system_prompt: persona,
                role: role,
                department: dept,
                description: desc,
                reports_to: reportsTo,
                billing_account: billing,
                run_target: 'webapp',
                target_ref: homeRef,
                run_targets: runTargetsOut,
            } })
                .then(function (data) {
                    var agentId = (data && data.hub_user_id) ? parseInt(data.hub_user_id, 10) : 0;
                    // Prefer the slug the create route returns; fall back to a
                    // name-derived slug for the sequence binding.
                    var createdSlug = (data && data.slug) ? String(data.slug) : slugify(name);

                    var finish = function () {
                        setBusy(false);
                        props.onCreated && props.onCreated();
                    };

                    // Best-effort welcome ping, then done.
                    var afterSeq = function () {
                        if (agentId > 0) {
                            apiFetch({ url: gsRoot + '/agent-welcome', method: 'POST', data: { agent_user_id: agentId } })
                                .then(finish, finish);
                        } else { finish(); }
                    };

                    // Persist EACH sequence that has ≥1 non-empty step. Each is its
                    // own upsert keyed by the sequence's own id. Errors are
                    // swallowed (best-effort) so one bad upsert never blocks create.
                    var toPersist = sequences.filter(function (seq) {
                        return (seq.prompts || []).some(function (p) { return p && String(p.text || '').trim() !== ''; });
                    });

                    if (toPersist.length === 0) { afterSeq(); return; }

                    var jobs = toPersist.map(function (seq, si) {
                        var seqId = seq.id || ('seq_' + Date.now() + '_' + si);
                        var nonEmpty = (seq.prompts || []).filter(function (p) { return p && String(p.text || '').trim() !== ''; });
                        var promptsOut = nonEmpty.map(function (p, i) {
                            return {
                                id: p.id || ('p' + (i + 1)),
                                text: String(p.text || ''),
                                runTarget: p.deviceRef ? 'device' : 'gendme',
                                targetRef: p.deviceRef || '',
                                aiIntegration: p.aiIntegration || '',
                                model: p.model || '',
                                contextFiles: Array.isArray(p.contextFiles) ? p.contextFiles : [],
                                outputs: Array.isArray(p.outputs) ? p.outputs : [],
                            };
                        });
                        return apiFetch({ url: psooRoot + '/business-plan/sequences/' + encodeURIComponent(seqId), method: 'POST', data: {
                            group_id: homeGroup,
                            id: seqId,
                            name: String(seq.name || ('Sequence ' + (si + 1))),
                            description: String(seq.description || ''),
                            agentSlug: createdSlug,
                            prompts: promptsOut,
                        } }).catch(function () { /* best-effort: ignore */ });
                    });

                    Promise.all(jobs).then(afterSeq, afterSeq);
                })
                .catch(function (e) { setBusy(false); setStatus({ t: cleanError(e), k: 'err' }); });
        }

        // ── Render ─────────────────────────────────────────────────────────
        var canCreate = !!homeGroup && !!name.trim();

        return html`
          <div class="em-chat-agent-modal" role="dialog" aria-modal="true" aria-label="Add new Agent">
            <div class="em-chat-agent-backdrop" onClick=${props.onClose}></div>
            <div class="em-chat-agent-dialog em-chat-agent-dialog--wide">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">Add new Agent</span>
                <button type="button" class="em-chat-agent-x" onClick=${props.onClose} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body em-chat-agent-body--grid">

                <!-- 1 ── HOME ORG + BILLING (two columns) ──────────────────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span">
                  <div class="em-chat-agent-2col">

                    <!-- LEFT: Home org (single Web App, AJAX search) -->
                    <div class="em-chat-agent-2col-cell">
                      <h4 class="em-chat-agent-section-head">Home org <span class="em-chat-agent-req">*</span> <span class="em-chat-agent-section-sub">the Web App this agent belongs to</span></h4>
                      ${homeGroup ? html`
                        <div class="em-chat-agent-home-chosen">
                          ${homeAvatar
                            ? html`<img class="em-chat-agent-home-av" src=${homeAvatar} alt="" />`
                            : html`<span class="em-chat-agent-opt-ic" aria-hidden="true">🌐</span>`}
                          <span class="em-chat-agent-home-name">${homeName || ('Group ' + homeGroup)}</span>
                          <button type="button" class="em-chat-agent-home-change" onClick=${changeHome}>change</button>
                        </div>`
                      : html`
                        <div class="em-chat-agent-home-search">
                          <input class="em-chat-agent-search" type="search" value=${q}
                            placeholder="Search your Web Apps…"
                            onInput=${function (e) { setQ(e.target.value); }} />
                          ${webApps.loading && html`<div class="em-chat-agent-pick-note">Searching…</div>`}
                          ${!webApps.loading && webApps.err && html`<div class="em-chat-agent-pick-note">${webApps.err}</div>`}
                          ${!webApps.loading && !webApps.err && webApps.items.length === 0 && html`
                            <div class="em-chat-agent-pick-note">No Web Apps match${q.trim() ? ' "' + q.trim() + '"' : ' — you are not a group admin of any connected Web App'}.</div>`}
                          <div class="em-chat-agent-optlist">
                            ${webApps.items.map(function (g) {
                              return html`
                                <button type="button" key=${String(g.group_id)}
                                  class="em-chat-agent-opt em-chat-agent-opt--app"
                                  onClick=${function () { pickHome(g); }}>
                                  ${g.avatar ? html`<img class="em-chat-agent-opt-av" src=${g.avatar} alt="" />` : html`<span class="em-chat-agent-opt-ic" aria-hidden="true">🌐</span>`}
                                  <span class="em-chat-agent-opt-lbl">${g.name || ('Group ' + g.group_id)}</span>
                                </button>`;
                            })}
                          </div>
                          <small class="em-chat-agent-help">Select a Home org (Web App).</small>
                        </div>`}
                    </div>

                    <!-- RIGHT: Billing account -->
                    <div class="em-chat-agent-2col-cell">
                      <h4 class="em-chat-agent-section-head">Billing account</h4>
                      <label class="em-chat-agent-field">
                        <select value=${billing} onChange=${function (e) { setBilling(e.target.value); }} disabled=${!homeGroup}>
                          <option value="">(me) — default</option>
                          ${billingOptions.map(function (m) {
                            return html`<option key=${m.id} value=${String(m.id)}>${m.name || String(m.id)}</option>`;
                          })}
                        </select>
                        <small class="em-chat-agent-help">Leo Credits + Gas Compute for gend.me runs are charged to this member.</small>
                      </label>
                    </div>

                  </div>
                </section>

                <!-- 2 ── NAME (full width) ─────────────────────────────────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span">
                  <h4 class="em-chat-agent-section-head">Name <span class="em-chat-agent-req">*</span></h4>
                  <label class="em-chat-agent-field">
                    <input type="text" value=${name} placeholder="e.g. Marketing CEO" onInput=${function (e) { setName(e.target.value); }} />
                  </label>
                </section>

                <!-- 3 ── PERSONALITY (full width) ──────────────────────────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span">
                  <h4 class="em-chat-agent-section-head">Personality <span class="em-chat-agent-section-sub">cached, optional</span></h4>
                  <label class="em-chat-agent-field">
                    <textarea rows="3" value=${persona} placeholder="System prompt / persona for offline desktop runs" onInput=${function (e) { setPersona(e.target.value); }}></textarea>
                    <small class="em-chat-agent-help">Read live from the hub agent; this cached copy lets desktop runs apply the right persona offline.</small>
                  </label>
                </section>

                <!-- 4 ── ROLE / TITLE (full width) ─────────────────────────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span">
                  <h4 class="em-chat-agent-section-head">Role / title</h4>
                  <label class="em-chat-agent-field">
                    <input type="text" value=${role} placeholder="e.g. Chief Executive Officer" onInput=${function (e) { setRole(e.target.value); }} />
                  </label>
                </section>

                <!-- 5 ── DESCRIPTION (full width) ──────────────────────────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span">
                  <h4 class="em-chat-agent-section-head">Description</h4>
                  <label class="em-chat-agent-field">
                    <textarea rows="2" value=${desc} placeholder="What this agent's job is" onInput=${function (e) { setDesc(e.target.value); }}></textarea>
                  </label>
                </section>

                <!-- 6 ── DEPARTMENT + REPORTS TO (two columns) ─────────────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span">
                  <div class="em-chat-agent-2col">

                    <!-- LEFT: Department (select + "+ New") -->
                    <div class="em-chat-agent-2col-cell">
                      <h4 class="em-chat-agent-section-head">Department</h4>
                      <label class="em-chat-agent-field">
                        <div class="em-chat-agent-dept-row">
                          <select value=${dept} onChange=${function (e) { setDept(e.target.value); }} disabled=${!homeGroup}>
                            <option value="">— none</option>
                            ${departments.map(function (d) {
                              return html`<option key=${String(d.id != null ? d.id : d.name)} value=${String(d.name)}>${d.name}</option>`;
                            })}
                          </select>
                          <button type="button" class="em-chat-agent-dept-add" disabled=${!homeGroup}
                            onClick=${addDepartment} aria-label="Add a new department">＋ New</button>
                        </div>
                      </label>
                    </div>

                    <!-- RIGHT: Reports to -->
                    <div class="em-chat-agent-2col-cell">
                      <h4 class="em-chat-agent-section-head">Reports to</h4>
                      <label class="em-chat-agent-field">
                        <select value=${reportsTo} onChange=${function (e) { setReportsTo(e.target.value); }} disabled=${!homeGroup}>
                          <option value="">— root (no parent)</option>
                          ${reportsToOptions.map(function (a) {
                            return html`<option key=${a.slug} value=${a.slug}>${a.name || a.slug}</option>`;
                          })}
                        </select>
                      </label>
                    </div>

                  </div>
                </section>

                <!-- 7 ── PROMPT SEQUENCES (list + nested builder popup) ─────── -->
                <section class="em-chat-agent-section em-chat-agent-section--span em-chat-agent-section--seq">
                  <h4 class="em-chat-agent-section-head">Prompt sequence <span class="em-chat-agent-section-sub">each runs step-by-step on the chosen target</span></h4>
                  <div class="em-chat-agent-seqlist">
                    ${sequences.length === 0 && html`
                      <div class="em-chat-agent-seq-empty">No sequences yet — add one to define what this agent does.</div>`}
                    ${sequences.map(function (seq, idx) {
                      var n = stepCount(seq);
                      return html`
                        <div class="em-chat-agent-seqrow" key=${seq.id || idx}>
                          <span class="em-chat-agent-seqrow-name">${seq.name || ('Sequence ' + (idx + 1))}</span>
                          <span class="em-chat-agent-seqrow-count">${n + (n === 1 ? ' step' : ' steps')}</span>
                          <div class="em-chat-agent-seqrow-ctl">
                            <button type="button" class="em-chat-agent-seqrow-edit"
                              onClick=${function () { openEditSequence(seq); }}>Edit</button>
                            <button type="button" class="em-chat-agent-seqrow-x" aria-label="Remove sequence"
                              onClick=${function () { removeSequence(seq.id); }}>×</button>
                          </div>
                        </div>`;
                    })}
                  </div>
                  <button type="button" class="em-chat-agent-addseq" onClick=${openNewSequence}>＋ Add new sequence</button>
                </section>

              </div>
              <footer class="em-chat-agent-foot">
                ${status && html`<span class=${'em-chat-agent-status is-' + status.k}>${status.t}</span>`}
                <button type="button" class="em-chat-agent-submit" disabled=${busy || !canCreate} onClick=${create}>
                  ${busy ? '…' : 'Create Agent'}
                </button>
              </footer>
            </div>
            ${editing && (function () {
              // Nested popup (second modal). Rendered inline (NOT a 2nd body-portal —
              // that blanks the widget) + wrapped in an error boundary so a render
              // throw shows the error instead of taking down the whole widget.
              var inner = html`<${SequenceEditor}
                seq=${editing}
                allDevices=${allDevices.items}
                deviceById=${deviceById}
                gendmeModels=${GENDME_MODELS}
                homeGroup=${homeGroup}
                onSave=${saveSequence}
                onCancel=${function () { setEditing(null); }} />`;
              return Component
                ? html`<${SeqErrorBoundary} onClose=${function () { setEditing(null); }}>${inner}</${SeqErrorBoundary}>`
                : inner;
            })()}
          </div>
        `;
    }

    // ── Nested prompt-sequence builder popup ────────────────────────────────
    // Second modal (portaled to <body>) titled "Prompt sequence". Holds the
    // step builder that used to live inline in AgentCreate. Edits a local draft
    // of one sequence; Save upserts via props.onSave(seq); Cancel discards.
    function SequenceEditor(props) {
        var src = props.seq || {};
        var homeGroup = props.homeGroup || null;

        var nState = useState(src.name || '');
        var sName = nState[0], setName = nState[1];
        // NEW: a human note describing what the whole sequence accomplishes.
        var dState = useState(src.description || '');
        var sDesc = dState[0], setDesc = dState[1];

        // Each step now carries the richer Phase-1 shape. Older drafts only had
        // {id,text,deviceRef,aiIntegration} — normalize them on open so the new
        // controls always have arrays/strings to bind to.
        function normStep(p, i) {
            p = p || {};
            return {
                id: p.id || ('p' + (i + 1)),
                text: p.text || '',
                deviceRef: p.deviceRef || '',
                aiIntegration: p.aiIntegration || '',
                model: p.model || '',
                contextFiles: Array.isArray(p.contextFiles) ? p.contextFiles.slice() : [],
                outputs: Array.isArray(p.outputs) ? p.outputs.map(function (o) { return Object.assign({}, o); }) : [],
            };
        }
        var pState = useState(
            (src.prompts && src.prompts.length)
                ? src.prompts.map(normStep)
                : [normStep(null, 0)]
        );
        var steps = pState[0], setSteps = pState[1];
        var stepSeqRef = useRef(steps.length);

        // ALL the member's connected devices. Each step's "Prompt Runs At"
        // <select> lists them: desktop selectable, mobile/server disabled, with
        // "Gend.me (hub)" (value "") first.
        var allDevices = props.allDevices || [];
        var deviceById = props.deviceById || function () { return null; };
        var GENDME_MODELS = props.gendmeModels || ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'];

        // ── Group prompt-sequences (for context-file attach + launch_sequence
        // output). Fetched once the home group is known. ────────────────────
        var grpSeqState = useState([]); var groupSeqs = grpSeqState[0], setGroupSeqs = grpSeqState[1];
        useEffect(function () {
            if (!homeGroup) { setGroupSeqs([]); return; }
            apiFetch({ url: gsRoot + '/group-sequences?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) {
                    var list = (d && Array.isArray(d.sequences)) ? d.sequences : [];
                    setGroupSeqs(list.filter(function (x) { return x && x.id != null; }));
                })
                .catch(function () { setGroupSeqs([]); });
        }, [homeGroup]);

        // ── Agents in the home group (for the "Launch another sequence" output's
        // Agent→Sequence cascade). Fetched once when the editor mounts. ───────
        var agentListState = useState([]); var agentList = agentListState[0], setAgentList = agentListState[1];
        useEffect(function () {
            if (!homeGroup) { setAgentList([]); return; }
            apiFetch({ url: gsRoot + '/agent-list?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) {
                    var list = (d && Array.isArray(d.agents)) ? d.agents : [];
                    setAgentList(list.filter(function (a) { return a && a.slug; }));
                })
                .catch(function () { setAgentList([]); });
        }, [homeGroup]);

        // ── Project workspaces in the home group (for the "Update Project"
        // output's workspace <select>). Fetched once on mount; "+ New" pushes. ─
        var projListState = useState([]); var projList = projListState[0], setProjList = projListState[1];
        useEffect(function () {
            if (!homeGroup) { setProjList([]); return; }
            apiFetch({ url: gsRoot + '/group-projects?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) {
                    var list = (d && Array.isArray(d.projects)) ? d.projects : [];
                    setProjList(list.filter(function (p) { return p && p.id != null; }));
                })
                .catch(function () { setProjList([]); });
        }, [homeGroup]);
        function createWorkspace() {
            var name = (window.prompt('New workspace name') || '').trim();
            if (!name) { return; }
            return apiFetch({ url: gsRoot + '/group-projects', method: 'POST', data: { group_id: homeGroup, title: name } })
                .then(function (d) {
                    var p = d && d.project;
                    if (p && p.id != null) {
                        setProjList(projList.concat([{ id: p.id, name: p.name || name }]));
                        return p;
                    }
                    return null;
                })
                .catch(function () { return null; });
        }

        // ── Member lookup cache (id → {id,name,avatar}) for the chat output's
        // recipient multi-select. Seeded by a term-less fetch on first open and
        // augmented by every search result so selected chips can show name+avatar
        // even after the search box is cleared. ──────────────────────────────
        var memCacheState = useState({}); var memCache = memCacheState[0], setMemCache = memCacheState[1];
        var memCacheRef = useRef({});
        function cacheMembers(members) {
            if (!members || !members.length) { return; }
            var next = Object.assign({}, memCacheRef.current);
            for (var i = 0; i < members.length; i++) {
                var m = members[i];
                if (m && m.id != null) { next[String(m.id)] = { id: m.id, name: m.name || ('Member ' + m.id), avatar: m.avatar || '' }; }
            }
            memCacheRef.current = next;
            setMemCache(next);
        }
        // Term-less seed fetch (once the group is known) so already-selected
        // recipient ids can resolve to a name/avatar on first render.
        useEffect(function () {
            if (!homeGroup) { return; }
            apiFetch({ url: gsRoot + '/group-members?group_id=' + encodeURIComponent(homeGroup) + '&term=', method: 'GET' })
                .then(function (d) { cacheMembers((d && Array.isArray(d.members)) ? d.members : []); })
                .catch(function () {});
        }, [homeGroup]);

        // Per-step chat recipient search box state + debounced results. Keyed by
        // step index so each chat output owns its own search field.
        var memSearchState = useState({}); var memSearch = memSearchState[0], setMemSearch = memSearchState[1];
        var memResultsState = useState({}); var memResults = memResultsState[0], setMemResults = memResultsState[1];
        var memDebounceRef = useRef({});
        function setMemTerm(idx, term) {
            var nextSearch = Object.assign({}, memSearch); nextSearch[idx] = term; setMemSearch(nextSearch);
            if (memDebounceRef.current[idx]) { clearTimeout(memDebounceRef.current[idx]); }
            var clean = String(term || '').trim();
            if (!clean) {
                var clr = Object.assign({}, memResults); clr[idx] = []; setMemResults(clr);
                return;
            }
            memDebounceRef.current[idx] = setTimeout(function () {
                apiFetch({ url: gsRoot + '/group-members?group_id=' + encodeURIComponent(homeGroup) + '&term=' + encodeURIComponent(clean), method: 'GET' })
                    .then(function (d) {
                        var members = (d && Array.isArray(d.members)) ? d.members : [];
                        cacheMembers(members);
                        setMemResults(function (prev) { var n = Object.assign({}, prev); n[idx] = members; return n; });
                    })
                    .catch(function () {
                        setMemResults(function (prev) { var n = Object.assign({}, prev); n[idx] = []; return n; });
                    });
            }, 300);
        }
        function clearMemSearch(idx) {
            var nextSearch = Object.assign({}, memSearch); nextSearch[idx] = ''; setMemSearch(nextSearch);
            var clr = Object.assign({}, memResults); clr[idx] = []; setMemResults(clr);
        }

        // Hidden file inputs (one per step, lazily reffed by index).
        var fileInputs = useRef({});

        // ── Client-side cost estimate ───────────────────────────────────────
        // USD per 1K *combined* tokens (rough blended rates). Local/device
        // models carry no metered cost.
        var RATE_PER_1K = {
            'gemini-2.5-flash': 0.003,   // ~0.0006 in + 0.0024 out blended
            'gemini-1.5-flash': 0.0005,
            'gemini-1.5-pro':   0.005,
        };
        function estimateCost(step) {
            // Hub Gemini models are the only metered ones; everything else
            // (device-local integrations) runs on the member's own hardware.
            if (step.deviceRef) { return 'Local — no metered cost'; }
            var model = step.model || '';
            var rate = RATE_PER_1K[model];
            if (rate == null) { return null; } // no model chosen yet
            var inTokens = Math.ceil(String(step.text || '').length / 4);
            var outTokens = 600; // assumed typical completion length
            var totalK = (inTokens + outTokens) / 1000;
            var usd = totalK * rate;
            // Show 3 sig-ish figures; tiny numbers shouldn't collapse to $0.00.
            var shown = usd < 0.001 ? usd.toFixed(4) : usd.toFixed(3);
            return 'Est. ~$' + shown + ' / run (estimate)';
        }

        // Model list for the chosen target/integration.
        function modelsFor(step) {
            if (!step.deviceRef) { return GENDME_MODELS; } // hub → Gemini models
            var dev = deviceById(step.deviceRef);
            var integ = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations : [];
            for (var k = 0; k < integ.length; k++) {
                if (integ[k] && String(integ[k].id) === String(step.aiIntegration)) {
                    return Array.isArray(integ[k].models) ? integ[k].models : [];
                }
            }
            return [];
        }

        // ── Step helpers ────────────────────────────────────────────────────
        function patchStep(idx, patch) {
            setSteps(steps.map(function (s, i) { return i === idx ? Object.assign({}, s, patch) : s; }));
        }
        function addStep() {
            stepSeqRef.current = (stepSeqRef.current || steps.length) + 1;
            setSteps(steps.concat([normStep({ id: 'p' + stepSeqRef.current }, 0)]));
        }
        function removeStep(idx) {
            if (steps.length <= 1) { setSteps([normStep(null, 0)]); return; }
            setSteps(steps.filter(function (s, i) { return i !== idx; }));
        }
        function moveStep(idx, dir) {
            var j = idx + dir;
            if (j < 0 || j >= steps.length) { return; }
            var next = steps.slice();
            var tmp = next[idx]; next[idx] = next[j]; next[j] = tmp;
            setSteps(next);
        }
        // "Prompt Runs At" change → reset the integration + model cascade. For a
        // device, default to its first available integration; for the hub,
        // default the integration to "gemini".
        function onStepTarget(idx, deviceRef) {
            var aiDefault = '';
            if (deviceRef) {
                var dev = deviceById(deviceRef);
                var integ = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations : [];
                for (var k = 0; k < integ.length; k++) {
                    if (integ[k] && integ[k].available !== false) { aiDefault = String(integ[k].id); break; }
                }
            } else {
                aiDefault = 'gemini'; // hub Vertex Gemini
            }
            // Reset model whenever the upstream target/integration changes.
            patchStep(idx, { deviceRef: deviceRef, aiIntegration: aiDefault, model: '' });
        }
        function onStepIntegration(idx, integrationId) {
            patchStep(idx, { aiIntegration: integrationId, model: '' });
        }

        // ── Context-file helpers ────────────────────────────────────────────
        function ctxList(step) { return Array.isArray(step.contextFiles) ? step.contextFiles : []; }
        function addContext(idx, item) {
            var cur = ctxList(steps[idx]);
            patchStep(idx, { contextFiles: cur.concat([item]) });
        }
        function removeContext(idx, ci) {
            var cur = ctxList(steps[idx]);
            patchStep(idx, { contextFiles: cur.filter(function (_, k) { return k !== ci; }) });
        }
        function hasBrain(step) {
            return ctxList(step).some(function (c) { return c && c.type === 'brain'; });
        }
        function toggleBrain(idx) {
            var step = steps[idx];
            if (hasBrain(step)) {
                patchStep(idx, { contextFiles: ctxList(step).filter(function (c) { return !(c && c.type === 'brain'); }) });
            } else {
                addContext(idx, { type: 'brain', ref: String(homeGroup || ''), label: 'Business brain' });
            }
        }
        function addSequenceContext(idx, seqId) {
            if (!seqId) { return; }
            var hit = null;
            for (var i = 0; i < groupSeqs.length; i++) {
                if (String(groupSeqs[i].id) === String(seqId)) { hit = groupSeqs[i]; break; }
            }
            addContext(idx, { type: 'sequence', ref: String(seqId), label: (hit && hit.name) ? hit.name : ('Sequence ' + seqId) });
        }
        function onUploadFile(idx, file) {
            if (!file || !homeGroup) { return; }
            var fd = new FormData();
            fd.append('file', file);
            // FormData → cannot go through apiFetch's JSON path; use fetch with
            // the same nonce header the IIFE apiFetch fallback uses.
            fetch(psooRoot + '/groups/' + encodeURIComponent(homeGroup) + '/media/upload', {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg.nonce },
                credentials: 'same-origin',
                body: fd,
            })
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (d) {
                    var attId = (d && (d.id != null ? d.id : (d.attachment_id != null ? d.attachment_id : (d.attachment && d.attachment.id))));
                    var url = (d && (d.source_url || (d.attachment && d.attachment.source_url))) || '';
                    addContext(idx, { type: 'upload', ref: String(attId != null ? attId : ''), label: file.name || 'Uploaded file', url: url });
                })
                .catch(function () { /* best-effort: ignore upload errors */ });
        }
        // Inline Google-Drive paste box (one transient value per step).
        var gdriveState = useState({}); var gdrive = gdriveState[0], setGdrive = gdriveState[1];
        function setGdriveFor(idx, val) {
            var next = Object.assign({}, gdrive); next[idx] = val; setGdrive(next);
        }
        function addGdrive(idx) {
            var link = String(gdrive[idx] || '').trim();
            if (!link) { return; }
            addContext(idx, { type: 'gdrive', url: link, label: 'Google Drive doc' });
            setGdriveFor(idx, '');
        }

        // ── Google-Drive picker ─────────────────────────────────────────────
        // status: { loaded, configured, connected }. Fetched once on mount.
        // When the backend isn't reachable we degrade to the paste-link box.
        var gdStatusState = useState({ loaded: false, configured: false, connected: false });
        var gdStatus = gdStatusState[0], setGdStatus = gdStatusState[1];
        useEffect(function () {
            if (typeof apiFetch !== 'function') {
                setGdStatus({ loaded: true, configured: false, connected: false });
                return;
            }
            apiFetch({ url: gsRoot + '/gdrive/status', method: 'GET' })
                .then(function (d) {
                    setGdStatus({
                        loaded: true,
                        configured: !!(d && d.configured),
                        connected: !!(d && d.connected),
                    });
                })
                .catch(function () { setGdStatus({ loaded: true, configured: false, connected: false }); });
        }, []);

        // Optional per-step "paste link" fallback toggle (shown via a small link
        // even when the picker is available).
        var gdPasteOpenState = useState({}); var gdPasteOpen = gdPasteOpenState[0], setGdPasteOpen = gdPasteOpenState[1];
        function toggleGdPaste(idx) {
            var next = Object.assign({}, gdPasteOpen); next[idx] = !next[idx]; setGdPasteOpen(next);
        }

        // Per-step Drive search state: term, results, in-flight flag.
        var gdSearchState = useState({}); var gdSearch = gdSearchState[0], setGdSearch = gdSearchState[1];
        var gdResultsState = useState({}); var gdResults = gdResultsState[0], setGdResults = gdResultsState[1];
        var gdBusyState = useState({}); var gdBusy = gdBusyState[0], setGdBusy = gdBusyState[1];
        var gdDebounce = useRef({});

        function setGdSearchFor(idx, val) {
            var next = Object.assign({}, gdSearch); next[idx] = val; setGdSearch(next);
        }
        function setGdResultsFor(idx, list) {
            var next = Object.assign({}, gdResults); next[idx] = list; setGdResults(next);
        }
        function setGdBusyFor(idx, on) {
            var next = Object.assign({}, gdBusy); next[idx] = !!on; setGdBusy(next);
        }

        function runGdriveSearch(idx, term) {
            if (typeof apiFetch !== 'function') { return; }
            setGdBusyFor(idx, true);
            apiFetch({ url: gsRoot + '/gdrive/files?q=' + encodeURIComponent(term || ''), method: 'GET' })
                .then(function (d) {
                    setGdBusyFor(idx, false);
                    // Backend may report a dropped connection — reflect it so the UI
                    // falls back to the Connect button.
                    if (d && d.connected === false) {
                        setGdStatus(function (prev) { return Object.assign({}, prev, { connected: false }); });
                        setGdResultsFor(idx, []);
                        return;
                    }
                    var files = (d && Array.isArray(d.files)) ? d.files : [];
                    setGdResultsFor(idx, files);
                })
                .catch(function () { setGdBusyFor(idx, false); setGdResultsFor(idx, []); });
        }

        function onGdSearchInput(idx, val) {
            setGdSearchFor(idx, val);
            if (gdDebounce.current[idx]) { clearTimeout(gdDebounce.current[idx]); }
            gdDebounce.current[idx] = setTimeout(function () { runGdriveSearch(idx, val); }, 300);
        }

        function pickGdriveFile(idx, file) {
            if (!file) { return; }
            addContext(idx, {
                type: 'gdrive',
                ref: String(file.id || ''),
                label: file.name || 'Google Drive file',
                url: '',
            });
            setGdSearchFor(idx, '');
            setGdResultsFor(idx, []);
        }

        function connectGdrive() {
            try {
                window.open(gsRoot + '/gdrive/connect', 'gsgdrive', 'width=520,height=640');
            } catch (e) { return; }
            function onMsg(ev) {
                var data = ev && ev.data;
                if (!data || data.gsGDrive !== 'connected') { return; }
                window.removeEventListener('message', onMsg);
                // Re-fetch status so the UI flips to the search picker.
                if (typeof apiFetch === 'function') {
                    apiFetch({ url: gsRoot + '/gdrive/status', method: 'GET' })
                        .then(function (d) {
                            setGdStatus({
                                loaded: true,
                                configured: !!(d && d.configured),
                                connected: !!(d && d.connected),
                            });
                        })
                        .catch(function () { setGdStatus(function (prev) { return Object.assign({}, prev, { connected: true }); }); });
                } else {
                    setGdStatus(function (prev) { return Object.assign({}, prev, { connected: true }); });
                }
            }
            window.addEventListener('message', onMsg);
        }

        // ── Response-action ("Do this with the response") helpers ───────────
        // Visible label → persisted type. Order = display order.
        var OUTPUT_TYPES = [
            { type: 'email',          label: 'Email' },
            { type: 'chat',           label: 'Send chat message' },
            { type: 'project',        label: 'Update Project' },
            { type: 'chain',          label: 'Use in next step' },
            { type: 'context_launch', label: 'Launch another sequence' },
        ];
        function outList(step) { return Array.isArray(step.outputs) ? step.outputs : []; }
        function outputFor(step, type) {
            var list = outList(step);
            for (var i = 0; i < list.length; i++) { if (list[i] && list[i].type === type) { return list[i]; } }
            return null;
        }
        function hasOutput(step, type) { return !!outputFor(step, type); }
        function defaultSettings(type) {
            if (type === 'email')          { return { to: '', subject: '', body: '' }; }
            if (type === 'chat')           { return { recipients: [] }; }
            if (type === 'project')        { return { project: '', task_title: '' }; }
            if (type === 'context_launch') { return { agent_slug: '', sequence_id: '' }; }
            return {}; // chain
        }
        function toggleOutput(idx, type) {
            var step = steps[idx];
            var list = outList(step);
            if (hasOutput(step, type)) {
                patchStep(idx, { outputs: list.filter(function (o) { return o.type !== type; }) });
                return;
            }
            // Add the output entry. Computed as a fresh steps array so a chained
            // auto-add (below) operates on the same up-to-date snapshot.
            var next = steps.map(function (s, i) {
                return i === idx ? Object.assign({}, s, { outputs: list.concat([{ type: type, settings: defaultSettings(type) }]) }) : s;
            });
            // "Use in next step" (chain): if this is the LAST step, auto-create a
            // follow-up step seeded with "{{previous}}" so the member immediately
            // continues writing the prompt that consumes this output.
            if (type === 'chain' && idx === steps.length - 1) {
                stepSeqRef.current = (stepSeqRef.current || steps.length) + 1;
                var fresh = normStep({ id: 'p' + stepSeqRef.current }, 0);
                fresh.text = '{{previous}}\n\n';
                next = next.concat([fresh]);
            }
            setSteps(next);
        }
        function patchOutputSettings(idx, type, patch) {
            var step = steps[idx];
            patchStep(idx, {
                outputs: outList(step).map(function (o) {
                    return o.type === type ? Object.assign({}, o, { settings: Object.assign({}, o.settings, patch) }) : o;
                }),
            });
        }

        function save() {
            // Default a blank name to "Sequence N" (parent re-derives N from list
            // position on persist; here we fall back to the draft's own index).
            var nm = String(sName || '').trim() || ('Sequence ' + (src._n || 1));
            props.onSave && props.onSave({
                id: src.id,
                name: nm,
                description: String(sDesc || ''),
                prompts: steps.map(function (s) {
                    return {
                        id: s.id,
                        text: s.text,
                        deviceRef: s.deviceRef || '',
                        runTarget: s.deviceRef ? 'device' : 'gendme',
                        targetRef: s.deviceRef || '',
                        aiIntegration: s.aiIntegration || '',
                        model: s.model || '',
                        contextFiles: ctxList(s).slice(),
                        outputs: outList(s).map(function (o) { return Object.assign({}, o); }),
                    };
                }),
            });
        }

        // Per-output settings sub-form renderer.
        function renderOutputSettings(idx, step, type) {
            var o = outputFor(step, type);
            var st = (o && o.settings) || {};
            if (type === 'email') {
                return html`
                  <div class="em-chat-seq-out-settings">
                    <label class="em-chat-agent-field">
                      <span>To</span>
                      <input type="text" value=${st.to || ''} placeholder="recipient@example.com"
                        onInput=${function (e) { patchOutputSettings(idx, type, { to: e.target.value }); }} />
                    </label>
                    <label class="em-chat-agent-field">
                      <span>Subject</span>
                      <input type="text" value=${st.subject || ''} placeholder="Email subject"
                        onInput=${function (e) { patchOutputSettings(idx, type, { subject: e.target.value }); }} />
                    </label>
                    <label class="em-chat-agent-field em-chat-seq-email-body">
                      <span>Body</span>
                      <textarea rows="4" value=${st.body || ''}
                        placeholder="Email body — use {{response}} to insert the agent's output (leave blank to send the output as the body)"
                        onInput=${function (e) { patchOutputSettings(idx, type, { body: e.target.value }); }}></textarea>
                    </label>
                  </div>`;
            }
            if (type === 'chat') {
                // Migrate legacy single-recipient drafts → recipients[].
                var recips = Array.isArray(st.recipients)
                    ? st.recipients
                    : (st.recipient ? [st.recipient] : []);
                var term = memSearch[idx] || '';
                var results = memResults[idx] || [];
                function addRecip(id) {
                    var key = String(id);
                    if (recips.some(function (r) { return String(r) === key; })) { clearMemSearch(idx); return; }
                    patchOutputSettings(idx, type, { recipients: recips.concat([id]) });
                    clearMemSearch(idx);
                }
                function removeRecip(id) {
                    var key = String(id);
                    patchOutputSettings(idx, type, { recipients: recips.filter(function (r) { return String(r) !== key; }) });
                }
                return html`
                  <div class="em-chat-seq-out-settings">
                    <div class="em-chat-agent-field">
                      <span>Recipients</span>
                      ${recips.length > 0 && html`
                        <div class="em-chat-seq-recip-chips">
                          ${recips.map(function (rid) {
                            var m = memCache[String(rid)] || { id: rid, name: ('Member ' + rid), avatar: '' };
                            return html`
                              <span class="em-chat-seq-recip-chip" key=${String(rid)}>
                                ${m.avatar ? html`<img class="em-chat-seq-recip-av" src=${m.avatar} alt="" />` : ''}
                                <span class="em-chat-seq-recip-name">${m.name}</span>
                                <button type="button" class="em-chat-seq-recip-x" aria-label="Remove"
                                  onClick=${function () { removeRecip(rid); }}>×</button>
                              </span>`;
                          })}
                        </div>`}
                      <input type="text" class="em-chat-seq-recip-search" value=${term}
                        placeholder="Search members…"
                        onInput=${function (e) { setMemTerm(idx, e.target.value); }} />
                      ${results.length > 0 && html`
                        <div class="em-chat-seq-recip-results">
                          ${results.map(function (m) {
                            return html`
                              <button type="button" class="em-chat-seq-recip-result" key=${String(m.id)}
                                onClick=${function () { addRecip(m.id); }}>
                                ${m.avatar ? html`<img class="em-chat-seq-recip-av" src=${m.avatar} alt="" />` : ''}
                                <span class="em-chat-seq-recip-name">${m.name || ('Member ' + m.id)}</span>
                              </button>`;
                          })}
                        </div>`}
                    </div>
                  </div>`;
            }
            if (type === 'project') {
                function onNewWorkspace() {
                    var p = createWorkspace();
                    if (p && p.then) {
                        p.then(function (np) {
                            if (np && np.id != null) { patchOutputSettings(idx, type, { project: String(np.id) }); }
                        });
                    }
                }
                return html`
                  <div class="em-chat-seq-out-settings">
                    <div class="em-chat-agent-field">
                      <span>Workspace</span>
                      <div class="em-chat-seq-proj-row">
                        <select class="em-chat-seq-proj-select" value=${String(st.project || '')}
                          onChange=${function (e) { patchOutputSettings(idx, type, { project: e.target.value }); }}>
                          <option value="">— select workspace</option>
                          ${projList.map(function (p) {
                            return html`<option key=${String(p.id)} value=${String(p.id)}>${p.name || ('Workspace ' + p.id)}</option>`;
                          })}
                        </select>
                        <button type="button" class="em-chat-seq-proj-new" onClick=${onNewWorkspace}>＋ New workspace</button>
                      </div>
                    </div>
                    <label class="em-chat-agent-field">
                      <span>What to create/update</span>
                      <input type="text" value=${st.task_title || ''} placeholder="Task title"
                        onInput=${function (e) { patchOutputSettings(idx, type, { task_title: e.target.value }); }} />
                      <small class="em-chat-agent-help">The agent's response is saved as the task description.</small>
                    </label>
                  </div>`;
            }
            if (type === 'chain') {
                return html`
                  <div class="em-chat-seq-out-settings">
                    <small class="em-chat-agent-help">Feeds this output into the next step as <code>{{previous}}</code>.</small>
                  </div>`;
            }
            if (type === 'context_launch') {
                var pickedAgent = st.agent_slug || '';
                var agentSeqs = groupSeqs.filter(function (gs) { return gs && String(gs.agentSlug) === String(pickedAgent); });
                return html`
                  <div class="em-chat-seq-out-settings">
                    <label class="em-chat-agent-field">
                      <span>Agent</span>
                      <select class="em-chat-seq-launch-agent" value=${pickedAgent}
                        onChange=${function (e) { patchOutputSettings(idx, type, { agent_slug: e.target.value, sequence_id: '' }); }}>
                        <option value="">— choose an agent</option>
                        ${agentList.map(function (a) {
                          return html`<option key=${String(a.slug)} value=${String(a.slug)}>${a.name || a.slug}</option>`;
                        })}
                      </select>
                    </label>
                    <label class="em-chat-agent-field">
                      <span>Sequence to launch</span>
                      <select class="em-chat-seq-launch-seq" value=${st.sequence_id || ''} disabled=${!pickedAgent}
                        onChange=${function (e) { patchOutputSettings(idx, type, { sequence_id: e.target.value }); }}>
                        <option value="">${pickedAgent ? '— choose a sequence' : '— pick an agent first'}</option>
                        ${agentSeqs.map(function (gs) {
                          return html`<option key=${String(gs.id)} value=${String(gs.id)}>${gs.name || ('Sequence ' + gs.id)}</option>`;
                        })}
                      </select>
                    </label>
                  </div>`;
            }
            return null;
        }

        var dialog = html`
          <div class="em-chat-agent-modal em-chat-seq-modal" role="dialog" aria-modal="true" aria-label="Prompt sequence">
            <div class="em-chat-agent-backdrop" onClick=${props.onCancel}></div>
            <div class="em-chat-agent-dialog em-chat-seq-dialog">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">Prompt sequence</span>
                <button type="button" class="em-chat-agent-x" onClick=${props.onCancel} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body em-chat-seq-body">
                <label class="em-chat-agent-field">
                  <span>Sequence name</span>
                  <input type="text" value=${sName} placeholder="e.g. Daily briefing"
                    onInput=${function (e) { setName(e.target.value); }} />
                </label>
                <label class="em-chat-agent-field">
                  <span>Sequence description</span>
                  <textarea rows="2" value=${sDesc} placeholder="What this sequence accomplishes (optional)"
                    onInput=${function (e) { setDesc(e.target.value); }}></textarea>
                </label>

                <div class="em-chat-agent-steps">
                  ${steps.map(function (s, idx) {
                    var dev = s.deviceRef ? deviceById(s.deviceRef) : null;
                    var integ = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations : [];
                    var stepModels = modelsFor(s);
                    var costLine = estimateCost(s);
                    var ctx = ctxList(s);
                    return html`
                      <div class="em-chat-agent-step" key=${s.id || idx}>
                        <div class="em-chat-agent-step-bar">
                          <span class="em-chat-agent-step-n">Step ${idx + 1}</span>
                          <div class="em-chat-agent-step-ctl">
                            <button type="button" class="em-chat-agent-step-btn" aria-label="Move up"
                              disabled=${idx === 0} onClick=${function () { moveStep(idx, -1); }}>↑</button>
                            <button type="button" class="em-chat-agent-step-btn" aria-label="Move down"
                              disabled=${idx === steps.length - 1} onClick=${function () { moveStep(idx, 1); }}>↓</button>
                            <button type="button" class="em-chat-agent-step-btn em-chat-agent-step-btn--del" aria-label="Remove step"
                              onClick=${function () { removeStep(idx); }}>×</button>
                          </div>
                        </div>
                        <textarea class="em-chat-agent-step-text" rows="2" value=${s.text}
                          placeholder="What should the agent do…"
                          onInput=${function (e) { patchStep(idx, { text: e.target.value }); }}></textarea>

                        <!-- ── Context Files ─────────────────────────────── -->
                        <div class="em-chat-seq-ctx">
                          <div class="em-chat-seq-sub-head">Context Files</div>
                          ${ctx.length > 0 && html`
                            <div class="em-chat-seq-chips">
                              ${ctx.map(function (c, ci) {
                                return html`
                                  <span class="em-chat-seq-chip" key=${ci}>
                                    <span class="em-chat-seq-chip-lbl">${c.label || c.type}</span>
                                    <button type="button" class="em-chat-seq-chip-x" aria-label="Remove"
                                      onClick=${function () { removeContext(idx, ci); }}>×</button>
                                  </span>`;
                              })}
                            </div>`}
                          <div class="em-chat-seq-ctx-ctl">
                            <button type="button"
                              class=${'em-chat-seq-ctx-btn' + (hasBrain(s) ? ' is-on' : '')}
                              onClick=${function () { toggleBrain(idx); }}>
                              ${hasBrain(s) ? '✓ Business brain' : '＋ Business brain'}
                            </button>
                            <select class="em-chat-seq-ctx-select" value=""
                              onChange=${function (e) { addSequenceContext(idx, e.target.value); e.target.value = ''; }}>
                              <option value="">＋ Other prompt sequence…</option>
                              ${groupSeqs.map(function (gs) {
                                return html`<option key=${String(gs.id)} value=${String(gs.id)}>${gs.name || ('Sequence ' + gs.id)}</option>`;
                              })}
                            </select>
                            <button type="button" class="em-chat-seq-ctx-btn"
                              onClick=${function () { var el = fileInputs.current[idx]; if (el) { el.click(); } }}>
                              ＋ Upload file
                            </button>
                            <input type="file" style=${{ display: 'none' }}
                              ref=${function (el) { fileInputs.current[idx] = el; }}
                              onChange=${function (e) { var f = e.target.files && e.target.files[0]; onUploadFile(idx, f); e.target.value = ''; }} />
                          </div>
                          <!-- ── Google Drive ────────────────────────────── -->
                          ${(function () {
                            // 1) Not configured (or backend unreachable) → paste-link only.
                            if (!gdStatus.configured) {
                              return html`
                                <div class="em-chat-seq-gdrive">
                                  <div class="em-chat-seq-gdrive-row">
                                    <span class="em-chat-seq-gdrive-lbl">Google Drive (paste link)</span>
                                    <input type="text" class="em-chat-seq-gdrive-in" value=${gdrive[idx] || ''}
                                      placeholder="Paste a Google Drive share link…"
                                      onInput=${function (e) { setGdriveFor(idx, e.target.value); }} />
                                    <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { addGdrive(idx); }}>＋ Add</button>
                                  </div>
                                  ${gdStatus.loaded && html`<div class="em-chat-seq-gdrive-note">Drive sign-in not set up</div>`}
                                </div>`;
                            }
                            // 2) Configured but not connected → Connect button (+ paste fallback link).
                            if (!gdStatus.connected) {
                              return html`
                                <div class="em-chat-seq-gdrive">
                                  <button type="button" class="em-chat-seq-gdrive-connect" onClick=${function () { connectGdrive(); }}>
                                    <span class="em-chat-seq-gdrive-g">▷</span> Connect Google Drive
                                  </button>
                                  <a class="em-chat-seq-gdrive-paste-link" href="#"
                                    onClick=${function (e) { e.preventDefault(); toggleGdPaste(idx); }}>or paste a link</a>
                                  ${gdPasteOpen[idx] && html`
                                    <div class="em-chat-seq-gdrive-row">
                                      <input type="text" class="em-chat-seq-gdrive-in" value=${gdrive[idx] || ''}
                                        placeholder="Paste a Google Drive share link…"
                                        onInput=${function (e) { setGdriveFor(idx, e.target.value); }} />
                                      <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { addGdrive(idx); }}>＋ Add</button>
                                    </div>`}
                                </div>`;
                            }
                            // 3) Connected → searchable picker (+ paste fallback link).
                            var results = Array.isArray(gdResults[idx]) ? gdResults[idx] : [];
                            return html`
                              <div class="em-chat-seq-gdrive">
                                <div class="em-chat-seq-gdrive-row">
                                  <input type="text" class="em-chat-seq-gdrive-search" value=${gdSearch[idx] || ''}
                                    placeholder="Search Google Drive…"
                                    onInput=${function (e) { onGdSearchInput(idx, e.target.value); }} />
                                  <a class="em-chat-seq-gdrive-paste-link" href="#"
                                    onClick=${function (e) { e.preventDefault(); toggleGdPaste(idx); }}>paste link</a>
                                </div>
                                ${gdBusy[idx] && html`<div class="em-chat-seq-gdrive-note">Searching…</div>`}
                                ${(!gdBusy[idx] && results.length === 0 && (gdSearch[idx] || '')) && html`<div class="em-chat-seq-gdrive-note">No files found</div>`}
                                ${results.length > 0 && html`
                                  <ul class="em-chat-seq-gdrive-results">
                                    ${results.map(function (f) {
                                      return html`
                                        <li class="em-chat-seq-gdrive-item" key=${String(f.id)}
                                          onClick=${function () { pickGdriveFile(idx, f); }}>
                                          ${f.iconLink && html`<img class="em-chat-seq-gdrive-icon" src=${f.iconLink} alt="" />`}
                                          <span class="em-chat-seq-gdrive-name">${f.name || f.id}</span>
                                        </li>`;
                                    })}
                                  </ul>`}
                                ${gdPasteOpen[idx] && html`
                                  <div class="em-chat-seq-gdrive-row">
                                    <input type="text" class="em-chat-seq-gdrive-in" value=${gdrive[idx] || ''}
                                      placeholder="Paste a Google Drive share link…"
                                      onInput=${function (e) { setGdriveFor(idx, e.target.value); }} />
                                    <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { addGdrive(idx); }}>＋ Add</button>
                                  </div>`}
                              </div>`;
                          })()}
                        </div>

                        <!-- ── Prompt Runs At cascade ────────────────────── -->
                        <div class="em-chat-agent-step-targets">
                          <label class="em-chat-agent-field">
                            <span>Prompt Runs At</span>
                            <select value=${s.deviceRef} onChange=${function (e) { onStepTarget(idx, e.target.value); }}>
                              <option value="">Gend.me (hub)</option>
                              ${allDevices.map(function (dev) {
                                var did = dev && (dev.device_id != null ? dev.device_id : dev.id);
                                var isDesktop = dev && dev.type === 'desktop';
                                var lbl = (dev && dev.label) || String(did);
                                return html`<option key=${String(did)} value=${String(did)} disabled=${!isDesktop}>${lbl + (isDesktop ? '' : ' (coming soon)')}</option>`;
                              })}
                            </select>
                          </label>
                          <label class="em-chat-agent-field">
                            <span>AI integration</span>
                            ${s.deviceRef ? html`
                              <select value=${s.aiIntegration} onChange=${function (e) { onStepIntegration(idx, e.target.value); }}>
                                ${integ.length === 0 && html`<option value="">No integrations on this device</option>`}
                                ${integ.map(function (it) {
                                  var avail = !it || it.available !== false;
                                  var lbl = (it && it.displayName) ? it.displayName : String(it && it.id);
                                  return html`<option key=${it.id} value=${String(it.id)} disabled=${!avail}>${lbl + (avail ? '' : ' (offline)')}</option>`;
                                })}
                              </select>`
                            : html`
                              <select value=${s.aiIntegration} onChange=${function (e) { onStepIntegration(idx, e.target.value); }}>
                                <option value="gemini">Davinci Architect AI</option>
                              </select>`}
                          </label>
                        </div>

                        <!-- Model cascade — only once an integration is chosen. -->
                        ${s.aiIntegration && html`
                          <div class="em-chat-seq-model">
                            <label class="em-chat-agent-field">
                              <span>Model</span>
                              <select value=${s.model} onChange=${function (e) { patchStep(idx, { model: e.target.value }); }}>
                                <option value="">— choose a model</option>
                                ${stepModels.map(function (m) { return html`<option key=${m} value=${m}>${m}</option>`; })}
                              </select>
                            </label>
                            ${costLine && html`<div class="em-chat-seq-cost">${costLine}</div>`}
                          </div>`}

                        <!-- ── Do this with the response ─────────────────── -->
                        <div class="em-chat-seq-out">
                          <div class="em-chat-seq-sub-head">Do this with the response</div>
                          <div class="em-chat-seq-out-row">
                            ${OUTPUT_TYPES.map(function (ot) {
                              return html`
                                <label class=${'em-chat-seq-out-chk' + (hasOutput(s, ot.type) ? ' is-on' : '')} key=${ot.type}>
                                  <input type="checkbox" checked=${hasOutput(s, ot.type)}
                                    onChange=${function () { toggleOutput(idx, ot.type); }} />
                                  <span>${ot.label}</span>
                                </label>`;
                            })}
                          </div>
                          ${OUTPUT_TYPES.filter(function (ot) { return hasOutput(s, ot.type); }).map(function (ot) {
                            return html`
                              <div class="em-chat-seq-out-block" key=${'set-' + ot.type}>
                                <div class="em-chat-seq-out-block-head">${ot.label}</div>
                                ${renderOutputSettings(idx, s, ot.type)}
                              </div>`;
                          })}
                        </div>
                      </div>`;
                  })}
                </div>
                <button type="button" class="em-chat-agent-addstep" onClick=${addStep}>＋ Add step</button>
              </div>
              <footer class="em-chat-agent-foot">
                <button type="button" class="em-chat-agent-cancel" onClick=${props.onCancel}>Cancel</button>
                <button type="button" class="em-chat-agent-submit" onClick=${save}>Save sequence</button>
              </footer>
            </div>
          </div>`;
        // Render INLINE (no createPortal). The agent modal is already portaled to
        // <body> and has NO transform/filter, and this dialog is a direct child of
        // that fixed, non-transformed .em-chat-agent-modal — so position:fixed +
        // z-index:100010 overlays correctly. A SECOND createPortal(→ document.body)
        // here (nested inside the agent modal's own body-portal) makes preact/compat
        // mis-reconcile and blank BOTH the popup and the widget. Inline avoids that.
        return dialog;
    }

    function SwitcherPanel(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        var qState = useState(''); var q = qState[0], setQ = qState[1];
        var searchState = useState({ loading: false, items: [] });
        var search = searchState[0], setSearch = searchState[1];
        var tabState = useState('members'); var tab = tabState[0], setTab = tabState[1];
        var agentAddState = useState(false); var agentAdd = agentAddState[0], setAgentAdd = agentAddState[1];

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
              <nav class="em-chat-tabs" role="tablist" aria-label="Conversation type">
                ${[['members', 'Members'], ['agents', 'Agents'], ['projects', 'Projects']].map(function (p) {
                  var c = state.items.filter(function (t) { return emThreadMatchesTab(t, p[0]); })
                          .reduce(function (a, t) { return a + (t.unread > 0 ? 1 : 0); }, 0);
                  return html`
                    <button
                      type="button" role="tab" key=${p[0]}
                      class=${'em-chat-tab ' + (tab === p[0] ? 'is-active' : '')}
                      aria-selected=${tab === p[0] ? 'true' : 'false'}
                      onClick=${function () { setTab(p[0]); }}>
                      ${p[1]}${c > 0 ? html`<span class="em-chat-tab-dot">${c}</span>` : null}
                    </button>
                  `;
                })}
              </nav>
              ${tab === 'agents' && html`
                <div class="em-chat-agent-addbar">
                  <button type="button" class="em-chat-agent-add" onClick=${function () { setAgentAdd(true); }}>
                    ＋ Add new Agent
                  </button>
                </div>
              `}
              <ul class="em-chat-panel-threads" role="list">
                ${state.loading && html`<li class="em-chat-panel-empty">Loading chats…</li>`}
                ${state.err && html`<li class="em-chat-panel-empty em-chat-err">${state.err}</li>`}
                ${(function () {
                  var shown = state.items.filter(function (t) { return emThreadMatchesTab(t, tab); });
                  if (!state.loading && !state.err && shown.length === 0) {
                    var msg = tab === 'agents'
                      ? 'No agent conversations yet.'
                      : (tab === 'projects' ? 'No project-attached conversations yet.' : 'No conversations yet. Search above to start one.');
                    return html`<li class="em-chat-panel-empty">${msg}</li>`;
                  }
                  return shown.map(function (t, i) {
                    var other = (t.others && t.others[0]) || {};
                    var typeClass = t.is_agent ? ' is-agent' : (t.project_id ? ' is-project' : '');
                    return html`
                      <li key=${t.id} style=${{ '--em-i': i }}>
                        <button type="button" class=${'em-chat-row' + (t.unread > 0 ? ' is-unread' : '') + typeClass} onClick=${function () { props.onOpenThread(t); }}>
                          <img class="em-chat-row-avatar" src=${other.avatar_url} alt="" />
                          <span class="em-chat-row-id">
                            <span class="em-chat-row-name">${other.display_name || '(unknown)'}${t.is_agent ? html`<span class="em-chat-row-tag">AGENT</span>` : (t.project_id ? html`<span class="em-chat-row-tag is-project">PROJECT</span>` : null)}</span>
                            <span class="em-chat-row-sub">${t.last_message_excerpt || ''}</span>
                          </span>
                          <span class="em-chat-row-meta">
                            <span class="em-chat-row-time">${formatTime(t.last_at)}</span>
                            ${t.unread > 0 && html`<span class="em-chat-row-unread">${t.unread}</span>`}
                          </span>
                        </button>
                      </li>
                    `;
                  });
                })()}
              </ul>
            `}
            ${agentAdd && (function () {
              var modal = html`<${AgentCreate}
                onClose=${function () { setAgentAdd(false); }}
                onCreated=${function () { setAgentAdd(false); setTab('agents'); reload(); }} />`;
              // Portal to <body> so the modal overlays the whole page rather than
              // being clipped inside the chat panel. Falls back to inline render.
              return createPortal ? createPortal(modal, document.body) : modal;
            })()}
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
                ${!state.loading && state.messages.length === 0 && html`
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
