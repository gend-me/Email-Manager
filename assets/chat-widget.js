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
    // PM "chatflow" task-type — launched-chatflow status list (LaunchedChatflows
    // below). Lives under em/v1 (bare), not this widget's own em/v1/chat/ root.
    var emRoot   = wpJsonRoot + 'em/v1';
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

        // Poll unread count every 30s while visible (was 15s — halved the
        // background request rate; gend.me's response times run 5-10s+
        // under load, so a 15s cadence routinely overlapped a still-inflight
        // previous poll, piling up connections against the site's limited
        // per-origin budget). The in-flight guard below stops a slow
        // response from ever having two of these outstanding at once.
        // The endpoint returns a per-sender items[] used by the live
        // notification dock.
        useEffect(function () {
            var inFlight = false;
            function tick() {
                if (document.visibilityState !== 'visible') return;
                if (inFlight) return;
                inFlight = true;
                restGet('unread-count').then(function (d) {
                    inFlight = false;
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
                }).catch(function () { inFlight = false; });
            }
            tick();
            var h = setInterval(tick, 30000);
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

        // Site-wide "open the sequence studio" (e.g. the Davinci Architect
        // page's Add/Edit sequence buttons). The action detail rides
        // window.__emSeqPending (set by emSeqStudioOpen); SwitcherPanel and
        // AgentSequencesPane route to Agents → Sequences and open the right
        // popup once mounted. headless deep-links GHOST the panel (mounted
        // but invisible) so only the body-portaled sequence popup shows; the
        // pane fires em-seq:ghost-done when its popups close and the panel
        // fully closes again.
        var seqGhostState = useState(false); var seqGhost = seqGhostState[0], setSeqGhost = seqGhostState[1];
        useEffect(function () {
            function onSeqNav() {
                var pnd = window.__emSeqPending || {};
                setSeqGhost(!!pnd.headless);
                setOpen(true);
            }
            function onGhostDone() { setSeqGhost(false); setOpen(false); }
            window.addEventListener('em-seq:nav', onSeqNav);
            window.addEventListener('em-seq:ghost-done', onGhostDone);
            return function () {
                window.removeEventListener('em-seq:nav', onSeqNav);
                window.removeEventListener('em-seq:ghost-done', onGhostDone);
            };
        }, []);
        // A normal open (launcher click) must never inherit a stale ghost.
        useEffect(function () { if (!open && seqGhost) { setSeqGhost(false); } }, [open]);

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
              seqGhost=${seqGhost}
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
        if (tab === 'email')    return false; // Email tab hosts the inbox suite, not chat threads
        if (tab === 'agents')   return !!t.is_agent;
        if (tab === 'projects') return !!t.project_id;
        return !t.is_agent; // 'members' (default)
    }

    // Agents → Chat: the group's agent DIRECTORY, rendered below the unread
    // thread list. Distinct from the thread list above (which only shows
    // agents you've ALREADY messaged) — this is the full roster, so a member
    // can find an agent they've never chatted with, filter by department,
    // fix its role/department, and start (or resume) a conversation.
    // Reads gs/v1/agent-roster (user_id + avatar + role + department per
    // agent); Edit saves through the SAME psoo/v1/agents/update endpoint the
    // desktop app uses. Follows the ONE group selector at the top of the
    // widget — no picker of its own.
    // v8.6.2: Agents → Organization — the Agent Directory rebuilt as the
    // desktop app's Organization tab: department sections (pill + agent
    // count + parent select + Edit/×/"+ Add agent") with agent cards
    // (name + prompt count) inside each. Reads AND writes the SAME
    // psoo/v1/org-chart store the desktop syncs (departments, agents,
    // bound sequences), so the two surfaces are in sync with the gend.me
    // group by construction. gs/v1/agent-roster is fetched alongside only
    // to hydrate each agent's ROLE/title for the edit modal (not part of
    // the org payload).
    function AgentRosterPane(props) {
        var gid = (props && Number(props.ctxGid)) || 0;
        var orgState = useState({ loading: false, departments: [], agents: [], err: '' });
        var org = orgState[0], setOrg = orgState[1];
        var roleMapState = useState({}); var roleMap = roleMapState[0], setRoleMap = roleMapState[1];
        var qState = useState('');    var q = qState[0], setQ = qState[1];
        var deptState = useState(''); var dept = deptState[0], setDept = deptState[1];
        var editState = useState(null); var editing = editState[0], setEditing = editState[1];
        var formState = useState({ name: '', role: '', department: '' });
        var form = formState[0], setForm = formState[1];
        var savingState = useState(false); var saving = savingState[0], setSaving = savingState[1];
        var saveErrState = useState(''); var saveErr = saveErrState[0], setSaveErr = saveErrState[1];
        var orgNoteState = useState(null); var orgNote = orgNoteState[0], setOrgNote = orgNoteState[1];

        function loadOrg() {
            if (!gid) { setOrg({ loading: false, departments: [], agents: [], err: '' }); return; }
            setOrg(function (cur) { return { loading: true, departments: cur.departments, agents: cur.agents, err: '' }; });
            apiFetch({ url: psooRoot + '/org-chart?group_id=' + gid, method: 'GET' })
                .then(function (d) {
                    setOrg({
                        loading: false,
                        departments: (d && d.departments) || [],
                        agents: (d && d.agents) || [],
                        err: '',
                    });
                })
                .catch(function (e) { setOrg({ loading: false, departments: [], agents: [], err: cleanError(e) }); });
        }
        useEffect(loadOrg, [gid]);
        useEffect(function () {
            if (!gid) { setRoleMap({}); return; }
            apiFetch({ url: gsRoot + '/agent-roster?group_id=' + gid, method: 'GET' })
                .then(function (d) {
                    var m = {};
                    ((d && d.agents) || []).forEach(function (a) { if (a && a.user_id != null) { m[String(a.user_id)] = a.role || ''; } });
                    setRoleMap(m);
                })
                .catch(function () { setRoleMap({}); });
        }, [gid]);

        function orgFail(e) { setOrgNote({ ok: false, text: cleanError(e) || 'Not allowed — group admins manage the organization.' }); }

        // ── Department CRUD — the same org-chart routes the desktop calls ──
        var deptModalState = useState(null); // null | {mode:'create'} | {mode:'edit', dep}
        var deptModal = deptModalState[0], setDeptModal = deptModalState[1];
        function addDepartment() {
            if (!gid) { setOrgNote({ ok: false, text: 'Pick a business group from the selector at the top of the chat first.' }); return; }
            setDeptModal({ mode: 'create' });
        }
        function submitDepartment(nm) {
            var body = { group_id: gid, name: nm };
            if (deptModal && deptModal.mode === 'edit') {
                body.id = deptModal.dep.id;
                body.parent = deptModal.dep.parent || '';
            }
            return apiFetch({ url: psooRoot + '/org-chart/department', method: 'POST', data: body })
                .then(function () { setDeptModal(null); loadOrg(); });
        }
        function deleteDepartment(dep) {
            if (!window.confirm('Delete department "' + dep.name + '"? Its agents move to its parent level.')) { return; }
            apiFetch({ url: psooRoot + '/org-chart/department/delete', method: 'POST', data: { group_id: gid, id: dep.id } })
                .then(loadOrg)
                .catch(orgFail);
        }
        function reparentDepartment(dep, parentId) {
            apiFetch({ url: psooRoot + '/org-chart/department', method: 'POST', data: { group_id: gid, id: dep.id, name: dep.name, parent: parentId || '' } })
                .then(loadOrg)
                .catch(orgFail);
        }

        // ── Agent edit modal (unchanged surface: psoo/v1/agents/update) ────
        function openEdit(a) {
            setEditing(a);
            setForm({
                name: a.name || '',
                role: a.role || roleMap[String(a.id)] || '',
                department: a.department || '',
                reports_to: a.reports_to || '',
                description: a.description || '',
                system_prompt: a.system_prompt || '',
            });
            setSaveErr('');
        }
        function saveEdit() {
            if (!editing || saving) return;
            setSaving(true);
            setSaveErr('');
            apiFetch({
                url: psooRoot + '/agents/update',
                method: 'POST',
                data: {
                    group_id: gid, slug: editing.slug,
                    name: form.name, role: form.role, department: form.department,
                    reports_to: form.reports_to, description: form.description,
                    system_prompt: form.system_prompt,
                }
            }).then(function () {
                setSaving(false);
                setEditing(null);
                var m = Object.assign({}, roleMap); m[String(editing.id)] = form.role; setRoleMap(m);
                loadOrg();
            }).catch(function (e) {
                setSaving(false);
                setSaveErr(cleanError(e) || 'Could not save — you may not have permission to edit this agent.');
            });
        }
        function deleteAgent() {
            if (!editing || saving) return;
            if (!window.confirm('Delete agent "' + editing.name + '"? It is deactivated on the group (its history stays).')) { return; }
            setSaving(true);
            apiFetch({ url: psooRoot + '/agents/deactivate', method: 'POST', data: { group_id: gid, slug: editing.slug } })
                .then(function () { setSaving(false); setEditing(null); loadOrg(); })
                .catch(function (e) { setSaving(false); setSaveErr(cleanError(e) || 'Could not delete this agent.'); });
        }
        function startChat(a) {
            props.onOpenThread({
                id: 0,
                others: [{ user_id: a.id, display_name: a.name, avatar_url: a.avatar }],
            });
        }

        // ── Grouping: departments ordered parent-first (level, then name),
        // plus an UNASSIGNED bucket for agents without a department. ────────
        var needle = q.trim().toLowerCase();
        function agentMatches(a) {
            if (!needle) return true;
            var hay = ((a.name || '') + ' ' + (roleMap[String(a.id)] || '')).toLowerCase();
            return hay.indexOf(needle) !== -1;
        }
        var deps = org.departments.slice().sort(function (a, b) {
            return (a.level - b.level) || String(a.name).localeCompare(String(b.name));
        });
        var deptNames = deps.map(function (d) { return d.name; });
        function agentsFor(name) {
            return org.agents.filter(function (a) { return (a.department || '') === name && agentMatches(a); });
        }
        var unassigned = org.agents.filter(function (a) {
            return (!a.department || deptNames.indexOf(a.department) === -1) && agentMatches(a);
        });
        function deptById(id) { return deps.filter(function (d) { return String(d.id) === String(id); })[0] || null; }
        function parentName(dep) { var p = deptById(dep.parent); return p ? p.name : ''; }

        function renderAgentCard(a) {
            // No prompt counts here — agents carry SETTINGS + their place in
            // the org structure (for task reviews), not prompts of their own.
            var role = a.role || roleMap[String(a.id)] || '';
            return html`
              <div class="em-chat-org-agent" key=${a.id} role="listitem">
                <button type="button" class="em-chat-org-agent-main" title="Edit agent"
                  onClick=${function () { openEdit(a); }}>
                  ${a.avatar && html`<img class="em-chat-org-agent-ava" src=${a.avatar} alt="" />`}
                  <span class="em-chat-org-agent-name">${a.name}</span>
                  ${role && html`<span class="em-chat-org-agent-prompts">${role}</span>`}
                </button>
                <button type="button" class="em-chat-agent-roster-btn em-chat-agent-roster-btn--chat"
                  onClick=${function () { startChat(a); }}>Chat</button>
              </div>`;
        }

        function renderDeptSection(dep) {
            if (dept && dep.name !== dept) { return null; }
            var list = agentsFor(dep.name);
            if (needle && list.length === 0) { return null; }
            return html`
              <div class="em-chat-org-dept" key=${dep.id}>
                <div class="em-chat-org-dept-head">
                  ${dep.parent && html`<span class="em-chat-org-dept-branch" aria-hidden="true">↳</span>`}
                  <span class="em-chat-org-dept-pill">${dep.name}</span>
                  <span class="em-chat-org-dept-count">${list.length} agent${list.length === 1 ? '' : 's'}</span>
                  <select class="em-chat-org-dept-parent" value=${dep.parent || ''} aria-label="Parent department"
                    onChange=${function (e) { reparentDepartment(dep, e.target.value); }}>
                    <option value="">— top-level</option>
                    ${deps.filter(function (d) { return String(d.id) !== String(dep.id); }).map(function (d) {
                      return html`<option key=${d.id} value=${d.id}>${d.name}</option>`;
                    })}
                  </select>
                  <span class="em-chat-org-dept-actions">
                    <button type="button" class="em-chat-agent-roster-btn" onClick=${function () { setDeptModal({ mode: 'edit', dep: dep }); }}>Edit</button>
                    <button type="button" class="em-chat-agent-roster-btn em-chat-org-dept-del" aria-label="Delete department"
                      onClick=${function () { deleteDepartment(dep); }}>×</button>
                    <button type="button" class="em-chat-agent-roster-btn" onClick=${function () { props.onAddAgent && props.onAddAgent(); }}>＋ Add agent</button>
                  </span>
                </div>
                <div class="em-chat-org-dept-body" role="list">
                  ${list.length === 0 && html`<div class="em-chat-org-empty">No agents in this department yet.</div>`}
                  ${list.map(renderAgentCard)}
                </div>
              </div>`;
        }

        // Edit-agent modal — desktop-Organization-edit parity: direct
        // reports, name + role side-by-side, department, reports-to,
        // description, Personality (the canonical hub system prompt the
        // desktop caches for offline runs), and Delete agent. The GROUP is
        // the source of truth: every field round-trips psoo/v1/agents/update
        // hub meta, which the desktop links into and pulls on sync.
        var editModal = editing && (function () {
            var reports = org.agents.filter(function (x) { return x.reports_to && editing.slug && x.reports_to === editing.slug; });
            function reportCountOf(slug) {
                return org.agents.filter(function (x) { return x.reports_to === slug; }).length;
            }
            return html`
          <div class="em-chat-agent-modal" role="dialog" aria-modal="true" aria-label=${'Edit ' + (editing.name || 'agent')}>
            <div class="em-chat-agent-backdrop" onClick=${function () { setEditing(null); }}></div>
            <div class="em-chat-agent-dialog em-chat-org-edit-dialog">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">Edit ${editing.name}</span>
                <button type="button" class="em-chat-agent-x" onClick=${function () { setEditing(null); }} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body em-chat-org-edit-body">
                ${reports.length > 0 && html`
                  <div class="em-chat-org-edit-reports">
                    <div class="em-chat-seq-sub-head">Responsible for: ${reports.length} direct report${reports.length === 1 ? '' : 's'}</div>
                    <div class="em-chat-org-edit-reportgrid">
                      ${reports.map(function (r) {
                        var rc = reportCountOf(r.slug);
                        return html`
                          <button type="button" class="em-chat-org-edit-report" key=${r.id}
                            title="Open this agent" onClick=${function () { openEdit(r); }}>
                            <span class="em-chat-org-edit-report-name">${r.name}</span>
                            ${(r.role || roleMap[String(r.id)]) && html`<span class="em-chat-org-edit-report-role">${r.role || roleMap[String(r.id)]}</span>`}
                            ${rc > 0 && html`<span class="em-chat-org-edit-report-meta">${rc} report${rc === 1 ? '' : 's'}</span>`}
                          </button>`;
                      })}
                    </div>
                  </div>
                `}
                <div class="em-chat-org-edit-row">
                  <label class="em-chat-agent-field">
                    <span>Name</span>
                    <input type="text" value=${form.name} onInput=${function (e) { setForm(Object.assign({}, form, { name: e.target.value })); }} />
                  </label>
                  <label class="em-chat-agent-field">
                    <span>Role / title</span>
                    <input type="text" value=${form.role} placeholder="e.g. VP" onInput=${function (e) { setForm(Object.assign({}, form, { role: e.target.value })); }} />
                  </label>
                </div>
                <div class="em-chat-org-edit-row">
                  <label class="em-chat-agent-field">
                    <span>Department</span>
                    <input type="text" list="em-org-edit-depts" value=${form.department}
                      placeholder="Pick from existing or type a new one"
                      onInput=${function (e) { setForm(Object.assign({}, form, { department: e.target.value })); }} />
                    <datalist id="em-org-edit-depts">
                      ${deptNames.map(function (d) { return html`<option key=${d} value=${d} />`; })}
                    </datalist>
                  </label>
                  <label class="em-chat-agent-field">
                    <span>Reports to</span>
                    <select value=${form.reports_to} onChange=${function (e) { setForm(Object.assign({}, form, { reports_to: e.target.value })); }}>
                      <option value="">— no one (top of the org)</option>
                      ${org.agents.filter(function (x) { return x.slug && x.slug !== editing.slug; }).map(function (x) {
                        return html`<option key=${x.slug} value=${x.slug}>${x.name}</option>`;
                      })}
                    </select>
                  </label>
                </div>
                <label class="em-chat-agent-field">
                  <span>Description</span>
                  <textarea rows="2" value=${form.description} placeholder="What this agent is responsible for"
                    onInput=${function (e) { setForm(Object.assign({}, form, { description: e.target.value })); }}></textarea>
                </label>
                <label class="em-chat-agent-field">
                  <span>Personality (system prompt)</span>
                  <textarea rows="5" value=${form.system_prompt}
                    placeholder="System prompt / persona"
                    onInput=${function (e) { setForm(Object.assign({}, form, { system_prompt: e.target.value })); }}></textarea>
                  <small class="em-chat-agent-help">Canonical on the group — the desktop app links into it and applies it when this agent runs there while online.</small>
                </label>
              </div>
              <footer class="em-chat-agent-foot em-chat-org-edit-foot">
                <button type="button" class="em-chat-org-edit-delete" disabled=${saving} onClick=${deleteAgent}>Delete agent</button>
                ${saveErr && html`<span class="em-chat-agent-status is-err">${saveErr}</span>`}
                <button type="button" class="em-chat-agent-cancel" onClick=${function () { setEditing(null); }}>Cancel</button>
                <button type="button" class="em-chat-agent-submit" disabled=${saving} onClick=${saveEdit}>
                  ${saving ? '…' : 'Save'}
                </button>
              </footer>
            </div>
          </div>`;
        })();

        var roster = html`
          <div class="em-chat-agent-roster">
            <div class="em-chat-agent-roster-head">
              <span class="em-chat-agent-roster-head-title">Organization</span>
            </div>
            ${orgNote && html`<div class=${'em-chat-seq-note ' + (orgNote.ok ? 'is-ok' : 'is-err')}>${orgNote.text}</div>`}
            ${!gid && html`<div class="em-chat-panel-empty">Pick a business group from the selector at the top of the chat to see its organization.</div>`}
            ${!!gid && html`
              <div class="em-chat-agent-roster-filters em-chat-deptsel em-chat-deptsel--row">
                <select class="em-chat-proj-select" value=${dept} aria-label="Jump to department"
                  onChange=${function (e) { setDept(e.target.value); }}>
                  <option value="">All departments</option>
                  ${deptNames.map(function (d) { return html`<option key=${d} value=${d}>${d}</option>`; })}
                </select>
                <button type="button" class="em-chat-agent-roster-addbtn em-chat-agent-roster-addbtn--ghost" onClick=${addDepartment}>＋ Add new Department</button>
              </div>
              <div class="em-chat-panel-search">
                <input type="search" placeholder="Search agents…" value=${q} onChange=${function (e) { setQ(e.target.value); }} />
              </div>
            `}
            <div class="em-chat-org-scroll">
              ${!!gid && org.loading && org.agents.length === 0 && html`<div class="em-chat-panel-empty">Loading the organization…</div>`}
              ${!!gid && org.err && html`<div class="em-chat-panel-empty em-chat-err">${org.err}</div>`}
              ${!!gid && !org.loading && !org.err && deps.length === 0 && org.agents.length === 0 && html`
                <div class="em-chat-panel-empty">No departments or agents yet — add a department or an agent to start the org chart.</div>
              `}
              ${deps.map(renderDeptSection)}
              ${unassigned.length > 0 && (!dept) && html`
                <div class="em-chat-org-dept">
                  <div class="em-chat-org-dept-head">
                    <span class="em-chat-org-dept-pill em-chat-org-dept-pill--muted">Unassigned</span>
                    <span class="em-chat-org-dept-count">${unassigned.length} agent${unassigned.length === 1 ? '' : 's'}</span>
                  </div>
                  <div class="em-chat-org-dept-body" role="list">
                    ${unassigned.map(renderAgentCard)}
                  </div>
                </div>
              `}
            </div>
          </div>
        `;
        var portal = editing ? (createPortal ? createPortal(editModal, document.body) : editModal) : null;
        var deptModalNode = deptModal ? html`
          <${EmPromptModal}
            title=${deptModal.mode === 'edit' ? 'Rename department' : 'New department'}
            label="Department name"
            placeholder="e.g. Marketing"
            initial=${deptModal.mode === 'edit' ? deptModal.dep.name : ''}
            submitLabel=${deptModal.mode === 'edit' ? 'Save' : 'Create department'}
            onSubmit=${submitDepartment}
            onCancel=${function () { setDeptModal(null); }} />
        ` : null;
        return [roster, portal, deptModalNode];
    }

    // v8.3.1: Email tab — the FULL member inbox suite mounted NATIVELY
    // into the panel (no iframe, no duplicate page chrome/Leo widget).
    // inbox-app.js + its config are enqueued alongside this widget for
    // inbox-access users; its window.emInboxMount(el) mounts the React
    // SPA into any element. The dropdown of connected inboxes drives the
    // SPA's initial selection via window.EM_INBOX_PRESELECT + a fresh
    // remount (a new child element each time, so React roots never clash).
    function EmailInboxPane() {
        // The suite ships its own inbox selector, so the pane is just the
        // mount host — no duplicate dropdown, no full-page link.
        var hostRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };

        useEffect(function () {
            var host = hostRef.current;
            if (!host) return;
            if (!window.emInboxMount || !window.EM_INBOX_CONFIG) {
                host.textContent = 'The email inbox is unavailable on this page.';
                return;
            }
            host.innerHTML = '';
            var el = document.createElement('div');
            // em-inbox-wrap classes so the SPA's scoped styles apply here.
            el.className = 'em-inbox-wrap em-inbox-wrap--frontend em-inbox-embed';
            host.appendChild(el);
            window.emInboxMount(el);
            return function () { try { host.innerHTML = ''; } catch (e) {} };
        }, []);

        return html`
          <div class="em-chat-email-pane">
            <div class="em-chat-email-host" ref=${hostRef}></div>
          </div>
        `;
    }

    // v8.4.2: open the projects plugin's workspace popup IN PLACE — no
    // visible page hop. The PM SPA (pm-admin.js) needs three things that
    // normally come from the group Business Plan page: the server-rendered
    // drawer/task-modal markup, the PSOO_PM boot config, and its own
    // JS/CSS. Fetch that page in the background ONCE, graft those pieces
    // into the current document, and from then on PM.openProjectDrawer(id)
    // pops the workspace right here. Falls back to the deep-link
    // navigation if any piece is missing (no jQuery, fetch failure,
    // markup drift).
    var emPmLoad = null;
    function emLoadPmWorkspace(groupId) {
        if (window.PM && window.PM.openProjectDrawer && document.getElementById('psoo-pm-project-drawer')) {
            return Promise.resolve(true);
        }
        if (emPmLoad) return emPmLoad;
        // pm-admin.js is a jQuery SPA — if the host page has no jQuery
        // (block-theme fronts), load core jQuery instead of bailing to a
        // full navigation.
        var jqReady = window.jQuery
            ? Promise.resolve(true)
            : new Promise(function (resolve) {
                var el = document.createElement('script');
                el.src = (wpJsonRoot.replace(/wp-json\/?$/, '') || '/') + 'wp-includes/js/jquery/jquery.min.js';
                el.onload = function () { resolve(!!window.jQuery); };
                el.onerror = function () { resolve(false); };
                document.head.appendChild(el);
            });
        emPmLoad = jqReady.then(function (haveJq) {
            if (!haveJq) { emPmLoad = null; return false; }
            return emLoadPmFragment(groupId);
        });
        return emPmLoad;
    }
    function emLoadPmFragment(groupId) {
        // Dedicated lightweight fragment endpoint (projects plugin) — the
        // shortcode output + PSOO_PM boot + SPA script tags, WITHOUT a whole
        // group page render around it.
        var ajaxUrl = wpJsonRoot.replace(/wp-json\/?$/, '') + 'wp-admin/admin-ajax.php';
        return fetch(ajaxUrl + '?action=psoo_pm_workspace_fragment&group_id=' + encodeURIComponent(groupId), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (txt) {
                var doc = new DOMParser().parseFromString(txt, 'text/html');
                var drawer = doc.getElementById('psoo-pm-project-drawer');
                var taskModal = doc.getElementById('psoo-pm-task-modal');
                if (!drawer) { emPmLoad = null; return false; }
                // Inject EVERY stylesheet the fragment carries (deduped per
                // href) — the old "skip everything once pm-admin.css exists"
                // gate left the workspace popup half-unstyled.
                var haveHrefs = {};
                document.querySelectorAll('link[rel="stylesheet"]').forEach(function (x) {
                    haveHrefs[(x.href || '').split('?')[0]] = 1;
                });
                doc.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) {
                    var href = l.getAttribute('href') || '';
                    if (!href) return;
                    var abs;
                    try { abs = new URL(href, window.location.href).href.split('?')[0]; } catch (e) { abs = href.split('?')[0]; }
                    if (haveHrefs[abs]) return;
                    haveHrefs[abs] = 1;
                    var link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = href;
                    document.head.appendChild(link);
                });
                document.body.appendChild(drawer);
                if (taskModal) document.body.appendChild(taskModal);
                // Boot config — wp_localize emits `var PSOO_PM = {…};`;
                // rescope to window so pm-admin.js can see it.
                var boot = '';
                doc.querySelectorAll('script:not([src])').forEach(function (s) {
                    if (!boot && /PSOO_PM\s*=/.test(s.textContent || '')) boot = s.textContent;
                });
                if (!boot) { emPmLoad = null; return false; }
                try {
                    (new Function(boot.replace(/var\s+PSOO_PM\b/, 'window.PSOO_PM')))();
                } catch (e) { emPmLoad = null; return false; }
                if (!window.PSOO_PM) { emPmLoad = null; return false; }
                // External scripts in fragment order (sortablejs → pm-admin);
                // never re-load jQuery over the host page's copy.
                var srcs = [];
                doc.querySelectorAll('script[src]').forEach(function (s) {
                    var src = s.getAttribute('src') || '';
                    if (src && !/jquery/i.test(src)) srcs.push(src);
                });
                if (!srcs.length) { emPmLoad = null; return false; }
                return srcs.reduce(function (chain, src) {
                    return chain.then(function (ok) {
                        if (!ok) return false;
                        return new Promise(function (resolve) {
                            var el = document.createElement('script');
                            el.src = src;
                            el.onload = function () { resolve(true); };
                            el.onerror = function () { resolve(false); };
                            document.body.appendChild(el);
                        });
                    });
                }, Promise.resolve(true)).then(function (ok) {
                    if (!ok) { emPmLoad = null; return false; }
                    // pm-admin binds on document.ready — already past, so its
                    // init runs synchronously on load; give it a tick.
                    return new Promise(function (resolve) {
                        setTimeout(function () { resolve(!!(window.PM && window.PM.openProjectDrawer)); }, 90);
                    });
                });
            })
            .catch(function () { emPmLoad = null; return false; });
    }

    // Global group context — a selector bar at the TOP of the widget
    // (above the Projects/Agents/Members/Email tabs) with the group icon.
    // The picked group scopes the group-content panes (Sequences, Projects)
    // and is remembered for the session.
    function GroupContextBar(props) {
        var gState = useState(null); var groups = gState[0], setGroups = gState[1];
        var openState = useState(false); var open = openState[0], setOpen = openState[1];
        var qState = useState('');    var q = qState[0], setQ = qState[1];
        var searchState = useState({ loading: false, items: null }); // items:null = no search active, show `groups`
        var search = searchState[0], setSearch = searchState[1];
        var inputRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        var CACHE_KEY = 'emCtxGroups.' + cfg.currentUserId;

        function mapGroups(list) {
            return (Array.isArray(list) ? list : []).map(function (g) {
                return {
                    id: Number(g.id),
                    name: g.name || ('Group #' + g.id),
                    avatar: (g.avatar_urls && (g.avatar_urls.thumb || g.avatar_urls.full)) || ''
                };
            });
        }

        useEffect(function () {
            try {
                var c = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
                if (c && Array.isArray(c.groups) && (Date.now() - c.t) < 600000) { setGroups(c.groups); return; }
            } catch (e) { /* no cache */ }
            // Staggered on purpose: on a cold widget-open, several panes
            // (threads, project-cards, this groups list) all mount in the
            // same tick and would otherwise fire simultaneously, competing
            // for gend.me's limited per-origin connection slots — a real
            // cause of requests hanging behind each other. A small delay
            // here lets the thread list's request (the primary visible
            // content) get a head start.
            var cancelled = false;
            var h = setTimeout(function () {
                if (cancelled) return;
                apiFetch({ url: wpJsonRoot + 'buddypress/v1/groups?user_id=' + cfg.currentUserId + '&per_page=50', method: 'GET' })
                    .then(function (list) {
                        list = mapGroups(list);
                        setGroups(list);
                        try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ t: Date.now(), groups: list })); } catch (e) {}
                    })
                    .catch(function () { setGroups([]); });
            }, 200);
            return function () { cancelled = true; clearTimeout(h); };
        }, []);

        // AJAX search — debounced, server-side (buddypress/v1/groups?search=),
        // scoped to the SAME user_id so results never include groups the
        // member doesn't belong to. Clearing the box reverts to the full
        // (cached) list rather than an empty "no results" state.
        useEffect(function () {
            var clean = q.trim();
            if (!clean) { setSearch({ loading: false, items: null }); return; }
            setSearch({ loading: true, items: search.items });
            var handle = setTimeout(function () {
                apiFetch({
                    url: wpJsonRoot + 'buddypress/v1/groups?user_id=' + cfg.currentUserId
                        + '&per_page=50&search=' + encodeURIComponent(clean),
                    method: 'GET'
                })
                    .then(function (list) { setSearch({ loading: false, items: mapGroups(list) }); })
                    .catch(function () { setSearch({ loading: false, items: [] }); });
            }, 250);
            return function () { clearTimeout(handle); };
        }, [q]);

        var wrapRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        useEffect(function () {
            if (open && inputRef.current) { setTimeout(function () { inputRef.current.focus(); }, 30); }
            if (!open) { setQ(''); setSearch({ loading: false, items: null }); return; }
            function onDocClick(e) {
                if (wrapRef.current && !wrapRef.current.contains(e.target)) { setOpen(false); }
            }
            document.addEventListener('mousedown', onDocClick, true);
            document.addEventListener('touchstart', onDocClick, true);
            return function () {
                document.removeEventListener('mousedown', onDocClick, true);
                document.removeEventListener('touchstart', onDocClick, true);
            };
        }, [open]);

        var current = (groups || []).filter(function (g) { return g.id === props.gid; })[0] || null;
        var shown = search.items !== null ? search.items : (groups || []);

        function pick(id) {
            setOpen(false);
            try { sessionStorage.setItem('emGroupCtx.' + cfg.currentUserId, String(id)); } catch (e) {}
            props.onPick(id);
        }
        return html`
          <div class="em-chat-groupbar" ref=${wrapRef}>
            <button type="button" class="em-chat-groupbar-btn" aria-haspopup="listbox" aria-expanded=${open ? 'true' : 'false'}
              onClick=${function () { setOpen(!open); }}>
              ${current && current.avatar
                ? html`<img class="em-chat-groupbar-ava" src=${current.avatar} alt="" />`
                : html`<span class="em-chat-groupbar-ava em-chat-groupbar-ava--all" aria-hidden="true">◎</span>`}
              <span class="em-chat-groupbar-name">${current ? current.name : 'All groups'}</span>
              <span class="em-chat-groupbar-caret" aria-hidden="true">▾</span>
            </button>
            ${open && html`
              <div class="em-chat-groupbar-list" role="listbox">
                <div class="em-chat-groupbar-search">
                  <input
                    ref=${inputRef}
                    type="search"
                    placeholder="Search groups…"
                    value=${q}
                    onClick=${function (e) { e.stopPropagation(); }}
                    onChange=${function (e) { setQ(e.target.value); }} />
                </div>
                ${!q && html`
                  <button type="button" class=${'em-chat-groupbar-item' + (!props.gid ? ' is-current' : '')} onClick=${function () { pick(0); }}>
                    <span class="em-chat-groupbar-ava em-chat-groupbar-ava--all" aria-hidden="true">◎</span>
                    <span>All groups</span>
                  </button>
                `}
                ${groups === null && search.items === null && html`<div class="em-chat-panel-empty">Loading groups…</div>`}
                ${search.loading && html`<div class="em-chat-panel-empty">Searching…</div>`}
                ${!search.loading && q && shown.length === 0 && html`<div class="em-chat-panel-empty">No groups match "${q}".</div>`}
                ${shown.map(function (g) {
                  return html`
                    <button type="button" key=${g.id} class=${'em-chat-groupbar-item' + (props.gid === g.id ? ' is-current' : '')}
                      onClick=${function () { pick(g.id); }}>
                      ${g.avatar
                        ? html`<img class="em-chat-groupbar-ava" src=${g.avatar} alt="" />`
                        : html`<span class="em-chat-groupbar-ava em-chat-groupbar-ava--all" aria-hidden="true">#</span>`}
                      <span>${g.name}</span>
                    </button>
                  `;
                })}
              </div>
            `}
          </div>
        `;
    }

    // v8.5.1: Agents → Sequences sub-tab (the DEFAULT agents view) — the
    // desktop app's Brain → Agents → SEQUENCES tab cards, mirrored to this
    // group. The desktop pushes compact snapshots of its local
    // sequence-project store (gend-projects.json: one workspace per
    // sequence, with launch triggers + task progress) to
    // psoo-pm/v1/groups/{id}/sequence-workspaces (`_psoo_sequence_workspaces`
    // groupmeta). Two PRIOR versions of this pane showed the wrong store:
    // the group's PM project cards (duplicated the Projects tab) and then
    // `_psoo_sequences` (the ORG-CHART roster — one entry per AGENT, so it
    // read as "all the AI agents", not the runnable sequences). A sequence
    // built with a triggered start creates/links a hub Project Workspace in
    // this group when it fires; hubProjectId records that link, so those
    // cards offer "Open project workspace →" via the same emLoadPmWorkspace
    // + openProjectDrawer flow the Projects tab uses. Group selection is
    // NOT duplicated here — this pane follows the ONE group selector at the
    // top of the chat widget (GroupContextBar / props.ctxGid).
    function AgentSequencesPane(props) {
        var pmRoot = wpJsonRoot + 'psoo-pm/v1';
        var seqState = useState({ loading: false, rows: [], err: '' });
        var seqs = seqState[0], setSeqs = seqState[1];
        var openingState = useState(0); var opening = openingState[0], setOpening = openingState[1];
        var gid = (props && Number(props.ctxGid)) || 0;

        // Sequence DEFINITIONS (`_psoo_sequences` — the store the desktop
        // two-way syncs) + the member's connected devices: together they power
        // the step-studio editor each card opens. Clicking a card edits the
        // definition matched by the workspace's sequenceId; Save POSTs the
        // merged definition back through the LWW-by-updatedAt upsert route,
        // and the desktop reconciles it on its next pull — so edits made here
        // flow back to the desktop app and vice-versa.
        var defsState = useState({ loading: false, byId: {}, list: [] });
        var defs = defsState[0], setDefs = defsState[1];
        var devState = useState([]); var devices = devState[0], setDevices = devState[1];
        var editState = useState(null); var editing = editState[0], setEditing = editState[1];
        // The workspace whose editor is open — tracked by id and re-derived
        // from rows each render so optimistic op patches show immediately.
        var editWsState = useState(null); var editingWsId = editWsState[0], setEditingWsId = editWsState[1];
        var noteState = useState(null); var note = noteState[0], setNote = noteState[1];

        useEffect(function () {
            if (!gid) { setSeqs({ loading: false, rows: [], err: '' }); setDefs({ loading: false, byId: {}, list: [] }); return; }
            setSeqs({ loading: true, rows: [], err: '' });
            apiFetch({ url: pmRoot + '/groups/' + gid + '/sequence-workspaces', method: 'GET' })
                .then(function (d) {
                    setSeqs({ loading: false, rows: (d && d.workspaces) || [], err: '' });
                })
                .catch(function (e) { setSeqs({ loading: false, rows: [], err: cleanError(e) }); });
            setDefs({ loading: true, byId: {}, list: [] });
            apiFetch({ url: psooRoot + '/business-plan/sequences?group_id=' + gid, method: 'GET' })
                .then(function (d) {
                    var list = (d && d.sequences) || [];
                    var byId = {};
                    list.forEach(function (s) { if (s && s.id != null) byId[String(s.id)] = s; });
                    setDefs({ loading: false, byId: byId, list: list });
                })
                .catch(function () { setDefs({ loading: false, byId: {}, list: [] }); });
        }, [gid]);

        useEffect(function () {
            // Group-scoped: every connected server/desktop/mobile of the
            // group's ADMINS (hub group: the super admins' signed-in
            // devices) — not just the caller's own registry.
            apiFetch({ url: psooRoot + '/devices' + (gid ? '?group_id=' + encodeURIComponent(gid) : ''), method: 'GET' })
                .then(function (d) {
                    var items = (d && Array.isArray(d.devices)) ? d.devices : (Array.isArray(d) ? d : []);
                    setDevices(items);
                })
                .catch(function () { setDevices([]); });
        }, [gid]);

        function deviceById(id) {
            for (var i = 0; i < devices.length; i++) {
                var d = devices[i];
                if (d && String(d.device_id != null ? d.device_id : d.id) === String(id)) { return d; }
            }
            return null;
        }

        // Resolve a workspace card to its sequence definition: sequenceId
        // first, then an exact name match (older workspaces predate the id
        // link on the hub side).
        function defForWorkspace(w) {
            var hit = w.sequenceId != null ? defs.byId[String(w.sequenceId)] : null;
            if (hit) return hit;
            var want = String(w.name || '').trim().toLowerCase();
            if (!want) return null;
            return defs.list.filter(function (s) {
                return String(s.name || '').trim().toLowerCase() === want;
            })[0] || null;
        }

        // "＋ New Sequence" — creates the sequence DEFINITION on the hub right
        // away (and opens the step-studio editor on it), then queues a
        // workspace.create op so the desktop app materializes the bound
        // workspace card (its next snapshot push makes it appear here).
        var seqModalState = useState(false); var seqModal = seqModalState[0], setSeqModal = seqModalState[1];
        // Deep-linked action from other surfaces (Davinci Architect →
        // Sequences): 'new' opens the ＋New Sequence prompt immediately;
        // 'edit' waits for the rows, then opens that workspace's editor.
        useEffect(function () {
            var pnd = window.__emSeqPending;
            if (!pnd || (pnd.ts && Date.now() - pnd.ts > 30000)) { return; }
            if (pnd.action === 'new') {
                window.__emSeqPending = null;
                window.__emSeqGhostActive = !!pnd.headless;
                createSequence();
                return;
            }
            if (pnd.action === 'edit' && pnd.workspaceId != null) {
                if (seqs.loading) { return; }
                var row = (seqs.rows || []).filter(function (w) { return String(w.id) === String(pnd.workspaceId); })[0];
                if (row) { window.__emSeqPending = null; window.__emSeqGhostActive = !!pnd.headless; openEditor(row); }
                else if ((seqs.rows || []).length) { window.__emSeqPending = null; }
            }
        }, [seqs]);
        // Ghost session lifecycle: once a popup has been open and BOTH the
        // editor and the ＋New Sequence prompt are closed again, tell the
        // root to drop the invisible panel.
        var ghostSeenRef = useRef(false);
        useEffect(function () {
            if (!window.__emSeqGhostActive) { ghostSeenRef.current = false; return; }
            if (editing || seqModal) { ghostSeenRef.current = true; return; }
            if (ghostSeenRef.current) {
                ghostSeenRef.current = false;
                window.__emSeqGhostActive = false;
                window.dispatchEvent(new CustomEvent('em-seq:ghost-done'));
            }
        }, [editing, seqModal]);

        function createSequence() {
            if (!gid) { setNote({ ok: false, text: 'Pick a business group from the selector at the top of the chat first.' }); return; }
            setNote(null);
            setSeqModal(true);
        }
        function submitNewSequence(name) {
            var defId = 'web-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
            var def = {
                id: defId,
                name: name,
                description: '',
                prompts: [{ id: 'p1', text: '' }],
                updatedAt: new Date().toISOString(),
            };
            return apiFetch({
                url: psooRoot + '/business-plan/sequences/' + encodeURIComponent(defId),
                method: 'POST',
                data: Object.assign({ group_id: gid }, def),
            })
                .then(function (d) {
                    if (!d || !d.ok) { throw new Error((d && d.error) || 'create failed'); }
                    var saved = d.sequence || def;
                    var byId = Object.assign({}, defs.byId); byId[String(saved.id)] = saved;
                    setDefs({ loading: false, byId: byId, list: defs.list.concat([saved]) });
                    // Queue the desktop-side workspace creation, bound to this def.
                    apiFetch({
                        url: pmRoot + '/groups/' + gid + '/sequence-workspaces/ops',
                        method: 'POST',
                        data: { workspaceId: defId, op: 'workspace.create', payload: { name: name, bindSequenceId: defId } },
                    }).catch(function () {});
                    // Optimistic card so the list reflects it immediately; the
                    // desktop's first push replaces it with the real workspace.
                    setSeqs(function (cur) {
                        return Object.assign({}, cur, { rows: cur.rows.concat([{
                            id: defId, name: name, description: '', status: 'active',
                            sequenceId: defId, sequenceName: name, sequenceRole: '',
                            triggers: [], taskLists: [], tasks: [], promptSteps: [],
                            tasksTotal: 0, tasksDone: 0, hubProjectId: 0, lastRunAt: '',
                        }]) });
                    });
                    setEditing(Object.assign({}, saved, {
                        prompts: (saved.prompts || []).map(function (p) { return Object.assign({}, p); }),
                    }));
                    setEditingWsId(defId);
                    setSeqModal(false);
                    setNote({ ok: true, text: 'Sequence created — the desktop app picks it up and creates its workspace within a minute.' });
                });
        }

        function openEditor(w) {
            setNote(null);
            var def = defForWorkspace(w);
            if (!def) {
                // STANDALONE workspace ("+ New Sequence" on the desktop creates
                // a bare project with NO sequence definition — its steps are
                // just local prompt/task rows). Create the definition right
                // here: a stable id keyed to the workspace, seeded from the
                // snapshot's promptSteps (real step texts when the desktop has
                // pushed them; one empty step otherwise), and carrying
                // projectId = the LOCAL desktop project id — the desktop's
                // sequence pull binds project.sequenceId from that, making the
                // pair fully two-way from then on.
                var steps = (w.promptSteps || []).filter(function (s) { return s && (s.text || s.title); });
                def = {
                    id: 'ws-' + w.id,
                    name: w.name || 'Untitled sequence',
                    description: w.description || '',
                    projectId: String(w.id),
                    prompts: steps.length
                        ? steps.map(function (s, i) { return { id: s.id || ('p' + (i + 1)), text: s.text || s.title || '' }; })
                        : [{ id: 'p1', text: '' }],
                };
            }
            setEditing(Object.assign({}, def, {
                prompts: (def.prompts || []).map(function (p) { return Object.assign({}, p); }),
            }));
            setEditingWsId(w.id);
        }

        // Queue a web→desktop workspace edit op (trigger toggle, task edits…)
        // and patch the local rows optimistically so the editor reflects the
        // change instantly; the desktop applies the op to its local store on
        // its next ops poll and pushes back the authoritative snapshot.
        function sendWsOp(wsId, op, payload, patchRow) {
            setSeqs(function (cur) {
                return Object.assign({}, cur, {
                    rows: cur.rows.map(function (w) { return w.id === wsId && patchRow ? patchRow(w) : w; }),
                });
            });
            apiFetch({
                url: pmRoot + '/groups/' + gid + '/sequence-workspaces/ops',
                method: 'POST',
                data: { workspaceId: wsId, op: op, payload: payload || {} },
            }).catch(function (e) { setNote({ ok: false, text: 'Edit not queued: ' + cleanError(e) }); });
        }

        // ── Manual launch from a workspace card: connected chatflow first,
        // else ask for a kick-off prompt (EmPromptModal below) and queue a
        // sequence.launch op for the desktop.
        var launchAskState = useState(null); var launchAskWs = launchAskState[0], setLaunchAskWs = launchAskState[1];
        function launchWorkspace(w) {
            var ts = w.triggers || [];
            var isCf = function (t) { return t.chatflowId && (t.type === 'chatflow' || !t.type); };
            var trg = ts.filter(function (t) { return t.enabled && isCf(t); })[0]
                || ts.filter(isCf)[0];
            if (trg) {
                apiFetch({ url: emRoot + '/chatflows/launch' + (gid ? ('?group_id=' + encodeURIComponent(gid)) : ''), method: 'POST', data: { chatflow_id: trg.chatflowId, task_id: 'seq-' + String(w.id || '') } })
                    .then(function () { setNote({ ok: true, text: '💬 "' + (trg.chatflowTitle || trg.name || 'Chatflow') + '" launched for "' + (w.name || 'sequence') + '" — open the chat to run it.' }); })
                    .catch(function (e) { setNote({ ok: false, text: 'Chatflow launch failed: ' + cleanError(e) }); });
            } else {
                setLaunchAskWs(w);
            }
        }

        // Save from the step-studio editor: merge the edited fields over the
        // FULL existing definition (the upsert route re-normalizes the whole
        // body — a partial payload would wipe role/department/agentSlug) and
        // POST with a fresh updatedAt so both the hub store and the desktop's
        // next LWW pull take this version.
        function saveEditedSequence(draft) {
            // Base = the stored definition when one exists, else the editing
            // seed (which carries projectId for a freshly-created standalone
            // definition — the editor's save() only returns id/name/
            // description/prompts, so the seed's extra fields must survive).
            var base = defs.byId[String(draft.id)] || editing || {};
            var merged = Object.assign({}, base, {
                id: draft.id,
                name: draft.name,
                description: draft.description,
                prompts: draft.prompts,
                updatedAt: new Date().toISOString(),
            });
            apiFetch({
                url: psooRoot + '/business-plan/sequences/' + encodeURIComponent(draft.id),
                method: 'POST',
                data: Object.assign({ group_id: gid }, merged),
            })
                .then(function (d) {
                    if (!d || !d.ok) { throw new Error((d && d.error) || 'save failed'); }
                    var saved = d.sequence || merged;
                    var byId = Object.assign({}, defs.byId); byId[String(saved.id)] = saved;
                    var replaced = false;
                    var list = defs.list.map(function (s) {
                        if (String(s.id) === String(saved.id)) { replaced = true; return saved; }
                        return s;
                    });
                    if (!replaced) { list = list.concat([saved]); }
                    setDefs({ loading: false, byId: byId, list: list });
                    setEditing(null);
                    setEditingWsId(null);
                    setNote({ ok: true, text: 'Sequence saved — synced to the group; the desktop app picks it up on its next sync.' });
                })
                .catch(function (e) { setNote({ ok: false, text: cleanError(e) }); });
        }

        function openHubProject(w) {
            if (opening || !w.hubProjectId) return;
            apiFetch({ url: pmRoot + '/me/active-project', method: 'POST', data: { project_id: w.hubProjectId } }).catch(function () {});
            setOpening(w.hubProjectId);
            emLoadPmWorkspace(gid).then(function (ok) {
                setOpening(0);
                if (ok && window.PM && window.PM.openProjectDrawer) {
                    // Drawer paints above the panel (see ProjectCardsPane note).
                    window.PM.openProjectDrawer(w.hubProjectId);
                }
            });
        }

        return html`
          <div class="em-chat-seq-pane">
            ${note && html`<div class=${'em-chat-seq-note ' + (note.ok ? 'is-ok' : 'is-err')}>${note.text}</div>`}
            ${!!gid && html`
              <div class="em-chat-seq-addbar">
                <button type="button" class="em-chat-proj-newbtn" onClick=${createSequence}>＋ New Sequence</button>
              </div>
            `}
            ${seqModal && html`
              <${EmPromptModal}
                title="New sequence"
                label="Sequence name"
                placeholder="e.g. Weekly content pipeline"
                submitLabel="Create sequence"
                hint="Opens the sequence editor right away; the desktop app creates the bound workspace card on its next sync."
                onSubmit=${submitNewSequence}
                onCancel=${function () { setSeqModal(false); }} />
            `}
            <div class="em-chat-proj-cards" role="list">
              ${!gid && html`<div class="em-chat-panel-empty">Pick a business group from the selector at the top of the chat to see its sequences.</div>`}
              ${seqs.loading && html`<div class="em-chat-panel-empty">Loading sequences…</div>`}
              ${seqs.err && html`<div class="em-chat-panel-empty em-chat-err">${seqs.err}</div>`}
              ${!!gid && !seqs.loading && !seqs.err && seqs.rows.length === 0 && html`
                <div class="em-chat-panel-empty">
                  No sequences synced from the desktop app yet. Open the desktop
                  app's Brain → Agents → Sequences tab with this group's
                  membership active — your sequence workspaces sync here
                  automatically.
                </div>
              `}
              ${seqs.rows.map(function (w) {
                var total = Number(w.tasksTotal) || 0;
                var done = Number(w.tasksDone) || 0;
                var archived = w.status === 'archived';
                var triggers = w.triggers || [];
                var enabledTriggers = triggers.filter(function (t) { return t.enabled; }).length;
                return html`
                  <div key=${w.id} class="em-chat-proj-card em-chat-seq-card" role="listitem">
                    <button type="button" class="em-chat-seq-card-main"
                      title="Open the sequence editor"
                      onClick=${function () { openEditor(w); }}>
                      <span class="em-chat-proj-card-title">⚡ ${w.name || 'Untitled sequence'}</span>
                      ${(w.sequenceRole || w.description) && html`<span class="em-chat-seq-card-desc">${w.sequenceRole || w.description}</span>`}
                      <span class="em-chat-proj-card-meta">
                        <span class=${'em-chat-proj-card-status' + (archived ? ' is-archived' : ' is-active')}>${archived ? 'ARCHIVED' : 'ACTIVE'}</span>
                        <span class="em-chat-proj-card-progress">✓ ${done}/${total}</span>
                        ${triggers.length > 0 && html`
                          <span class="em-chat-proj-card-cat em-chat-seq-card-trigger">⚡ ${enabledTriggers}/${triggers.length} trigger${triggers.length === 1 ? '' : 's'}</span>
                        `}
                        ${w.lastRunAt && html`<span class="em-chat-proj-card-cat" title="Last triggered run">last run ${String(w.lastRunAt).slice(0, 10)}</span>`}
                      </span>
                      <span class="em-chat-proj-card-cta">✎ Edit sequence</span>
                    </button>
                    <button type="button" class="em-chat-proj-card-cta em-chat-seq-card-launch"
                      title=${(triggers.filter(function (t) { return t.chatflowId; }).length > 0)
                        ? 'Launches the connected chatflow'
                        : 'Asks for a kick-off prompt and runs the sequence on the desktop'}
                      onClick=${function () { launchWorkspace(w); }}>🚀 Launch</button>
                    ${w.hubProjectId
                      ? html`
                        <button type="button" class="em-chat-proj-card-cta em-chat-seq-card-open"
                          onClick=${function () { openHubProject(w); }}>
                          ${opening === w.hubProjectId ? 'Opening workspace…' : 'Open project workspace →'}
                        </button>`
                      : html`<span class="em-chat-seq-card-note">${triggers.length > 0
                          ? 'Triggered runs create a project workspace in this group to track and approve each step.'
                          : 'No launch trigger yet — open \u270e Edit sequence to connect a chatflow, or hit \ud83d\ude80 Launch to kick it off from a prompt.'}</span>`}
                  </div>
                `;
              })}
            </div>
            ${launchAskWs && html`<${EmPromptModal}
              title=${'Kick off — ' + (launchAskWs.name || 'sequence')}
              label="Kick-off prompt"
              placeholder="What should this run start from?"
              onCancel=${function () { setLaunchAskWs(null); }}
              onSubmit=${function (v) {
                sendWsOp(launchAskWs.id, 'sequence.launch', { prompt: v }, null);
                setLaunchAskWs(null);
                setNote({ ok: true, text: '🚀 Kick-off queued — the desktop app runs the sequence on its next sync (within ~a minute while online).' });
              }}
            />`}
            ${editing && (function () {
              // The step-studio editor, portaled to <body> so it overlays the
              // whole page instead of clipping inside the chat panel. This is
              // a FIRST-level portal (the pane itself is not portaled), so the
              // nested-portal preact bug the AgentCreate flow works around
              // doesn't apply here.
              var editingWs = seqs.rows.filter(function (r) { return r.id === editingWsId; })[0] || null;
              var closeEditor = function () { setEditing(null); setEditingWsId(null); };
              var inner = html`<${SequenceEditor}
                seq=${editing}
                allDevices=${devices}
                deviceById=${deviceById}
                homeGroup=${gid}
                workspace=${editingWs}
                onWorkspaceOp=${function (op, payload, patchRow) { if (editingWs) sendWsOp(editingWs.id, op, payload, patchRow); }}
                onSave=${saveEditedSequence}
                onCancel=${closeEditor} />`;
              var wrapped = Component
                ? html`<${SeqErrorBoundary} onClose=${closeEditor}>${inner}</${SeqErrorBoundary}>`
                : inner;
              return createPortal ? createPortal(wrapped, document.body) : wrapped;
            })()}
          </div>
        `;
    }

    // v8.4.1: Projects tab — every project the member can reach, as CARDS
    // aggregated across their business groups. Clicking a card records it
    // as the active project and opens the projects plugin's own workspace
    // popup right here (see emLoadPmWorkspace above).
    function ProjectCardsPane(props) {
        var pmRoot = wpJsonRoot + 'psoo-pm/v1';
        var st = useState({ loading: true, cards: [], err: '' });
        var state = st[0], setState = st[1];
        var openingState = useState(0); var opening = openingState[0], setOpening = openingState[1];
        // Toolbar filters matching the group page's Projects panel: search
        // box + status filter + category pills.
        var qState = useState(''); var q = qState[0], setQ = qState[1];
        var stFilterState = useState('all'); var stFilter = stFilterState[0], setStFilter = stFilterState[1];
        var catState = useState(''); var cat = catState[0], setCat = catState[1];
        // The plugin's FIXED category set (slug → label) from the cards
        // payload — pills render from it even when no project is categorized
        // yet, matching the group page. Plus the "＋ New Project" popup state.
        var catMapState = useState({}); var catMap = catMapState[0], setCatMap = catMapState[1];
        var showNewState = useState(false); var showNew = showNewState[0], setShowNew = showNewState[1];

        // Session cache (5 min): the cards need groups + per-group project
        // fetches, so reopening the widget was slow. Cached cards paint
        // instantly; a background refresh keeps them current. v2 key: the
        // card payload grew desktop-parity fields (description/progress/
        // members/category) — old cached shapes must not paint.
        var CARDS_CACHE_KEY = 'emProjCards2.' + cfg.currentUserId;
        useEffect(function () {
            try {
                var cached = JSON.parse(sessionStorage.getItem(CARDS_CACHE_KEY) || 'null');
                if (cached && Array.isArray(cached.cards) && (Date.now() - cached.t) < 300000) {
                    setState({ loading: false, cards: cached.cards, err: '' });
                }
            } catch (e) { /* no cache */ }
            // Fast path: ONE aggregate call (psoo-pm/v1/me/project-cards).
            // Falls back to the legacy 1+N per-group fan-out if the live
            // endpoint is missing.
            // 'projects' is the default tab, so on a cold widget-open this
            // fires in the SAME tick as the thread list + groups list —
            // staggered so it doesn't compete with that first burst for
            // gend.me's limited per-origin connection slots (cached cards
            // above already painted instantly, so this delay is invisible
            // when a cache hit exists).
            var cancelled = false;
            var h = setTimeout(function () {
                if (cancelled) return;
                apiFetch({ url: pmRoot + '/me/project-cards', method: 'GET' })
                    .then(function (d) {
                        if (!d || !Array.isArray(d.cards)) throw new Error('bad payload');
                        setState({ loading: false, cards: d.cards, err: '' });
                        if (d.categories && typeof d.categories === 'object') { setCatMap(d.categories); }
                        try { sessionStorage.setItem(CARDS_CACHE_KEY, JSON.stringify({ t: Date.now(), cards: d.cards })); } catch (e) {}
                    })
                    .catch(function () { legacyLoad(); });
            }, 400);
            function legacyLoad() {
            apiFetch({ url: wpJsonRoot + 'buddypress/v1/groups?user_id=' + cfg.currentUserId + '&per_page=50', method: 'GET' })
                .then(function (groups) {
                    groups = Array.isArray(groups) ? groups : [];
                    if (!groups.length) { setState({ loading: false, cards: [], err: '' }); return; }
                    var remaining = groups.length;
                    var cards = [];
                    groups.forEach(function (g) {
                        var done = function () {
                            remaining--;
                            if (remaining === 0) {
                                cards.sort(function (a, b) { return a.groupName.localeCompare(b.groupName) || a.title.localeCompare(b.title); });
                                setState({ loading: false, cards: cards, err: '' });
                                try { sessionStorage.setItem(CARDS_CACHE_KEY, JSON.stringify({ t: Date.now(), cards: cards })); } catch (e) {}
                            }
                        };
                        apiFetch({ url: pmRoot + '/groups/' + g.id + '/projects', method: 'GET' })
                            .then(function (d) {
                                ((d && d.projects) || []).forEach(function (p) {
                                    var base = String(g.link || '').replace(/\/+$/, '') + '/business-plan/';
                                    cards.push({
                                        id: Number(p.id),
                                        title: p.title || ('Project #' + p.id),
                                        groupId: Number(g.id),
                                        groupName: g.name || '',
                                        avatar: (g.avatar_urls && (g.avatar_urls.thumb || g.avatar_urls.full)) || '',
                                        link: base + '?pm_project=' + p.id,
                                    });
                                });
                            })
                            .catch(function () { /* group without PM — skip */ })
                            .then(done);
                    });
                })
                .catch(function (e) { setState({ loading: false, cards: [], err: cleanError(e) }); });
            }
            return function () { cancelled = true; clearTimeout(h); };
        }, []);

        function open(card) {
            if (opening) return;
            // Remember the pick (same active-project store the desktop uses).
            apiFetch({ url: pmRoot + '/me/active-project', method: 'POST', data: { project_id: card.id } }).catch(function () {});
            setOpening(card.id);
            emLoadPmWorkspace(card.groupId).then(function (ok) {
                setOpening(0);
                if (ok && window.PM && window.PM.openProjectDrawer) {
                    // Leave the chat panel open — chat-widget.css pins the
                    // drawer ABOVE the widget (body .psoo-pm-drawer z-index
                    // override), so it always paints on top of the panel.
                    window.PM.openProjectDrawer(card.id);
                } else {
                    // Fallback: deep-linked navigation still auto-opens the
                    // drawer on the group page.
                    window.location.href = card.link;
                }
            });
        }

        // Warm the (cached) workspace fragment while the pointer is still
        // hovering — by click time the drawer is usually ready, instead of
        // a multi-second "Opening workspace…" stall.
        function prefetch(card) {
            emLoadPmWorkspace(card.groupId);
        }
        // …and pre-warm as soon as the tab shows cards at all: the fragment
        // is one cached load per page, so doing it in the background while
        // the member is still LOOKING at the cards makes the eventual click
        // near-instant. Delayed so it never competes with the card fetch.
        var preCtx = (props && Number(props.ctxGid)) || 0;
        useEffect(function () {
            var g = preCtx || (state.cards[0] && state.cards[0].groupId) || 0;
            if (!g) { return; }
            var h = setTimeout(function () { emLoadPmWorkspace(g); }, 1500);
            return function () { clearTimeout(h); };
        }, [preCtx, state.cards.length]);

        // Widget-level group context (top selector bar) scopes the cards —
        // no separate picker needed inside this pane. On top of that, the
        // toolbar filters mirror the group page's Projects panel: search,
        // status (active/archived), and category pills (built from whatever
        // categories the visible projects actually carry).
        var ctxGid = (props && Number(props.ctxGid)) || 0;
        var scoped = ctxGid ? state.cards.filter(function (c) { return c.groupId === ctxGid; }) : state.cards;
        var cats = Object.keys(catMap).map(function (k) { return catMap[k]; });
        scoped.forEach(function (c) {
            if (c.category && cats.indexOf(c.category) === -1) { cats.push(c.category); }
        });
        var qClean = q.trim().toLowerCase();
        var visibleCards = scoped.filter(function (c) {
            if (stFilter === 'active' && Number(c.status) === 1) return false;
            if (stFilter === 'archived' && Number(c.status) !== 1) return false;
            if (cat && c.category !== cat) return false;
            if (qClean && String(c.title || '').toLowerCase().indexOf(qClean) === -1
                && String(c.description || '').toLowerCase().indexOf(qClean) === -1) return false;
            return true;
        });

        return html`
          <div class="em-chat-projects-pane">
            <div class="em-chat-proj-toolbar">
              <button type="button" class="em-chat-proj-newbtn" onClick=${function () { setShowNew(true); }}>＋ New Project</button>
              <input type="search" class="em-chat-proj-search" placeholder="🔍 Search projects…"
                value=${q} onInput=${function (e) { setQ(e.target.value); }} />
              <select class="em-chat-proj-select em-chat-proj-statussel" value=${stFilter} aria-label="Status filter"
                onChange=${function (e) { setStFilter(e.target.value); }}>
                <option value="all">All Statuses</option>
                <option value="active">Active</option>
                <option value="archived">Archived</option>
              </select>
            </div>
            ${cats.length > 0 && html`
              <div class="em-chat-proj-catpills" role="tablist" aria-label="Project category">
                ${[['', 'All']].concat(cats.map(function (c) { return [c, c]; })).map(function (p) {
                  return html`
                    <button type="button" key=${p[0] || 'all'} role="tab" aria-selected=${cat === p[0] ? 'true' : 'false'}
                      class=${'em-chat-proj-catpill' + (cat === p[0] ? ' is-on' : '')}
                      onClick=${function () { setCat(p[0]); }}>${p[1]}</button>
                  `;
                })}
              </div>
            `}
            <div class="em-chat-proj-cards" role="list">
              ${state.loading && html`<div class="em-chat-panel-empty">Loading your projects…</div>`}
              ${state.err && html`<div class="em-chat-panel-empty em-chat-err">${state.err}</div>`}
              ${!state.loading && !state.err && visibleCards.length === 0 && html`
                <div class="em-chat-panel-empty">${ctxGid ? 'No projects match here yet.' : 'No projects yet — create one from a business group’s workspace.'}</div>
              `}
              ${visibleCards.map(function (c) {
                var total = Number(c.tasksTotal) || 0;
                var done = Number(c.tasksDone) || 0;
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                var archived = Number(c.status) === 1;
                return html`
                  <button type="button" key=${c.id} class="em-chat-proj-card em-chat-proj-card--rich" role="listitem"
                    style=${c.color ? { '--em-proj-color': c.color } : null}
                    onMouseEnter=${function () { prefetch(c); }}
                    onClick=${function () { open(c); }}>
                    ${!ctxGid && html`
                      <span class="em-chat-proj-card-head">
                        ${c.avatar && html`<img class="em-chat-proj-card-avatar" src=${c.avatar} alt="" />`}
                        <span class="em-chat-proj-card-group">${c.groupName}</span>
                      </span>
                    `}
                    <span class="em-chat-proj-card-title">${c.title}</span>
                    ${c.description && html`<span class="em-chat-proj-card-desc">${c.description}</span>`}
                    <span class="em-chat-proj-card-progressbar" aria-hidden="true">
                      <span class="em-chat-proj-card-progressfill" style=${{ width: pct + '%' }}></span>
                    </span>
                    <span class="em-chat-proj-card-meta">
                      <span class=${'em-chat-proj-card-status' + (archived ? ' is-archived' : ' is-active')}>${archived ? 'ARCHIVED' : 'ACTIVE'}</span>
                      <span class="em-chat-proj-card-progress">✔ ${done}/${total}</span>
                      <span class="em-chat-proj-card-progress">👥 ${Number(c.members) || 0}</span>
                      ${c.category && html`<span class="em-chat-proj-card-cat">${c.category}</span>`}
                    </span>
                    <span class="em-chat-proj-card-cta">${opening === c.id ? 'Opening workspace…' : 'Open workspace →'}</span>
                  </button>
                `;
              })}
            </div>
            ${showNew && html`
              <${ProjNewModal}
                ctxGid=${ctxGid}
                categories=${catMap}
                onClose=${function () { setShowNew(false); }}
                onCreated=${function (card) {
                  setShowNew(false);
                  var next = [card].concat(state.cards);
                  setState({ loading: false, cards: next, err: '' });
                  try { sessionStorage.setItem(CARDS_CACHE_KEY, JSON.stringify({ t: Date.now(), cards: next })); } catch (e) {}
                }} />
            `}
          </div>
        `;
    }


    // v8.6.4: Messages → Members — profile-messages parity
    // (/members/{me}/messages/): Inbox / Starred / Sent / Compose /
    // Notices / Search pill tabs, "Search members to start a new
    // conversation…", a FROM / SUBJECT / ☆ / ACTIONS table with unread
    // pills, star toggles, read-unread + delete actions, and bulk
    // actions. Backed by the same BP messages store through em/v1/chat
    // (threads?box=, star, unread, delete, notices).
    function MembersMessagesPane(props) {
        var boxState = useState('inbox'); var box = boxState[0], setBox = boxState[1];
        var thState = useState({ loading: true, items: [], err: '' });
        var th = thState[0], setTh = thState[1];
        var ntState = useState(null); var notices = ntState[0], setNotices = ntState[1];
        var selState = useState({}); var sel = selState[0], setSel = selState[1];
        var bulkState = useState(''); var bulk = bulkState[0], setBulk = bulkState[1];
        var filterState = useState(''); var filter = filterState[0], setFilter = filterState[1];
        var showFilterState = useState(false); var showFilter = showFilterState[0], setShowFilter = showFilterState[1];
        var mqState = useState(''); var mq = mqState[0], setMq = mqState[1];
        var mResState = useState({ loading: false, items: [] });
        var mRes = mResState[0], setMRes = mResState[1];
        var searchRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };

        function loadThreads(b) {
            setTh(function (cur) { return { loading: true, items: cur.items, err: '' }; });
            restGet('threads?box=' + (b === 'sent' ? 'sentbox' : b))
                .then(function (d) { setTh({ loading: false, items: d.items || [], err: '' }); setSel({}); })
                .catch(function (e) { setTh({ loading: false, items: [], err: cleanError(e) }); });
        }
        useEffect(function () {
            if (box === 'notices') {
                if (notices === null) {
                    apiFetch({ url: emRoot + '/chat/notices', method: 'GET' })
                        .then(function (d) { setNotices((d && d.items) || []); })
                        .catch(function () { setNotices([]); });
                }
                return;
            }
            loadThreads(box);
        }, [box]);

        // Member type-ahead ("Search members to start a new conversation…").
        useEffect(function () {
            var clean = mq.trim();
            if (!clean) { setMRes({ loading: false, items: [] }); return; }
            var h = setTimeout(function () {
                setMRes({ loading: true, items: mRes.items });
                restGet('users/search?q=' + encodeURIComponent(clean))
                    .then(function (d) { setMRes({ loading: false, items: d.items || [] }); })
                    .catch(function () { setMRes({ loading: false, items: [] }); });
            }, 250);
            return function () { clearTimeout(h); };
        }, [mq]);
        function startWith(u) {
            props.onOpenThread({
                id: 0,
                others: [{ user_id: u.user_id, display_name: u.display_name, avatar_url: u.avatar_url }],
            });
            setMq('');
        }

        function patchThread(id, patch) {
            setTh(function (cur) {
                return { loading: cur.loading, err: cur.err, items: cur.items.map(function (t) {
                    return t.id === id ? Object.assign({}, t, patch) : t;
                }) };
            });
        }
        function dropThread(id) {
            setTh(function (cur) {
                return { loading: cur.loading, err: cur.err, items: cur.items.filter(function (t) { return t.id !== id; }) };
            });
        }
        function actStar(t) {
            var next = !t.starred;
            patchThread(t.id, { starred: next });
            if (box === 'starred' && !next) { dropThread(t.id); }
            apiFetch({ url: emRoot + '/chat/threads/' + t.id + '/star', method: 'POST', data: { starred: next ? '1' : '0' } }).catch(function () {});
        }
        function actToggleRead(t) {
            if (t.unread > 0) {
                patchThread(t.id, { unread: 0 });
                restPost('threads/' + t.id + '/read').catch(function () {});
            } else {
                patchThread(t.id, { unread: 1 });
                apiFetch({ url: emRoot + '/chat/threads/' + t.id + '/unread', method: 'POST' }).catch(function () {});
            }
        }
        function actDelete(t) {
            if (!window.confirm('Delete this conversation? (It stays for the other participants.)')) { return; }
            dropThread(t.id);
            apiFetch({ url: emRoot + '/chat/threads/' + t.id + '/delete', method: 'POST' }).catch(function () {});
        }
        function applyBulk() {
            var ids = Object.keys(sel).filter(function (k) { return sel[k]; }).map(Number);
            if (!ids.length || !bulk) { return; }
            if (bulk === 'delete' && !window.confirm('Delete ' + ids.length + ' conversation' + (ids.length === 1 ? '' : 's') + '?')) { return; }
            ids.forEach(function (id) {
                var t = th.items.filter(function (x) { return x.id === id; })[0];
                if (!t) return;
                if (bulk === 'read')   { patchThread(id, { unread: 0 }); restPost('threads/' + id + '/read').catch(function () {}); }
                if (bulk === 'unread') { patchThread(id, { unread: 1 }); apiFetch({ url: emRoot + '/chat/threads/' + id + '/unread', method: 'POST' }).catch(function () {}); }
                if (bulk === 'star')   { patchThread(id, { starred: true });  apiFetch({ url: emRoot + '/chat/threads/' + id + '/star', method: 'POST', data: { starred: '1' } }).catch(function () {}); }
                if (bulk === 'unstar') { patchThread(id, { starred: false }); apiFetch({ url: emRoot + '/chat/threads/' + id + '/star', method: 'POST', data: { starred: '0' } }).catch(function () {}); }
                if (bulk === 'delete') { dropThread(id); apiFetch({ url: emRoot + '/chat/threads/' + id + '/delete', method: 'POST' }).catch(function () {}); }
            });
            setSel({});
            setBulk('');
        }

        var needle = filter.trim().toLowerCase();
        var shown = th.items.filter(function (t) {
            if (!needle) return true;
            var other = (t.others && t.others[0]) || {};
            var hay = ((other.display_name || '') + ' ' + (t.subject || '') + ' ' + (t.last_message_excerpt || '')).toLowerCase();
            return hay.indexOf(needle) !== -1;
        });
        var allSelected = shown.length > 0 && shown.every(function (t) { return sel[t.id]; });
        var anySelected = shown.some(function (t) { return sel[t.id]; });
        function toggleAll() {
            var next = {};
            if (!allSelected) { shown.forEach(function (t) { next[t.id] = true; }); }
            setSel(next);
        }
        function fmtDate(iso) {
            if (!iso) return '';
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }).toUpperCase()
                + ' AT ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        }

        var TABS = [
            ['inbox',   '✉️', 'Inbox'],
            ['starred', '⭐', 'Starred'],
            ['sent',    '📤', 'Sent'],
            ['compose', '📝', 'Compose'],
            ['notices', '🔔', 'Notices'],
            ['search',  '🔍', 'Search'],
        ];
        function onTab(k) {
            if (k === 'compose') {
                if (searchRef.current) { searchRef.current.focus(); }
                return;
            }
            if (k === 'search') { setShowFilter(!showFilter); return; }
            setBox(k);
        }

        function renderRow(t, i) {
            var other = (t.others && t.others[0]) || {};
            return html`
              <div class=${'em-chat-dm-row' + (t.unread > 0 ? ' is-unread' : '')} key=${t.id} style=${{ '--em-i': i }}>
                <input type="checkbox" class="em-chat-dm-check" checked=${!!sel[t.id]}
                  onChange=${function () { var n = Object.assign({}, sel); n[t.id] = !n[t.id]; setSel(n); }} />
                <button type="button" class="em-chat-dm-from" onClick=${function () { props.onOpenThread(t); }}>
                  <img class="em-chat-row-avatar" src=${other.avatar_url} alt="" />
                  <span class="em-chat-dm-from-meta">
                    <span class="em-chat-dm-from-name">
                      ${other.display_name || '(unknown)'}
                      ${t.unread > 0 && html`<span class="em-chat-dm-unread">(${t.unread})</span>`}
                    </span>
                    <span class="em-chat-dm-date">${fmtDate(t.last_at)}</span>
                  </span>
                </button>
                <button type="button" class="em-chat-dm-subject" onClick=${function () { props.onOpenThread(t); }}>
                  <span class="em-chat-dm-subject-title">${t.subject || '(no subject)'}</span>
                  <span class="em-chat-dm-subject-excerpt">${t.last_message_excerpt || ''}</span>
                </button>
                <button type="button" class=${'em-chat-dm-star' + (t.starred ? ' is-on' : '')}
                  aria-label=${t.starred ? 'Unstar' : 'Star'}
                  onClick=${function () { actStar(t); }}>${t.starred ? '★' : '☆'}</button>
                <span class="em-chat-dm-actions">
                  <button type="button" class="em-chat-dm-act" title=${t.unread > 0 ? 'Mark read' : 'Mark unread'}
                    onClick=${function () { actToggleRead(t); }}>${t.unread > 0 ? '👁' : '🙈'}</button>
                  <button type="button" class="em-chat-dm-act em-chat-dm-act--del" title="Delete"
                    onClick=${function () { actDelete(t); }}>🗑</button>
                </span>
              </div>`;
        }

        return html`
          <div class="em-chat-dm-pane">
            <div class="em-chat-dm-tabs" role="tablist" aria-label="Message box">
              ${TABS.map(function (v) {
                var active = (v[0] === box) || (v[0] === 'search' && showFilter);
                return html`
                  <button type="button" key=${v[0]} role="tab" aria-selected=${active ? 'true' : 'false'}
                    class=${'em-chat-dm-tab' + (active ? ' is-active' : '')}
                    onClick=${function () { onTab(v[0]); }}>
                    <span class="em-chat-dm-tab-ico" aria-hidden="true">${v[1]}</span>
                    <span>${v[2]}</span>
                  </button>`;
              })}
              <button type="button" class="em-chat-proj-newbtn em-chat-dm-invite" onClick=${function () { props.onInvite && props.onInvite(); }}>＋ Invite new</button>
            </div>

            <div class="em-chat-dm-compose">
              <input ref=${searchRef} type="search" placeholder="Search members to start a new conversation…"
                value=${mq} onInput=${function (e) { setMq(e.target.value); }} />
              ${mq && html`
                <div class="em-chat-dm-compose-results" role="listbox">
                  ${mRes.loading && html`<div class="em-chat-panel-empty">Searching…</div>`}
                  ${!mRes.loading && mRes.items.length === 0 && html`<div class="em-chat-panel-empty">No members match "${mq}".</div>`}
                  ${mRes.items.map(function (u) {
                    return html`
                      <button type="button" key=${u.user_id} class="em-chat-row em-chat-row--user" onClick=${function () { startWith(u); }}>
                        <img class="em-chat-row-avatar" src=${u.avatar_url} alt="" />
                        <span class="em-chat-row-id">
                          <span class="em-chat-row-name">${u.display_name}</span>
                          <span class="em-chat-row-sub">@${u.username || ''}</span>
                        </span>
                        <span class="em-chat-row-action" aria-hidden="true">→</span>
                      </button>`;
                  })}
                </div>
              `}
            </div>

            ${showFilter && box !== 'notices' && html`
              <div class="em-chat-dm-filter">
                <input type="search" placeholder="Filter conversations…" value=${filter}
                  onInput=${function (e) { setFilter(e.target.value); }} />
              </div>
            `}

            ${box === 'notices' ? html`
              <div class="em-chat-dm-scroll">
                ${notices === null && html`<div class="em-chat-panel-empty">Loading notices…</div>`}
                ${notices !== null && notices.length === 0 && html`<div class="em-chat-panel-empty">No site notices.</div>`}
                ${(notices || []).map(function (n) {
                  return html`
                    <div class="em-chat-dm-notice" key=${n.id}>
                      <span class="em-chat-dm-subject-title">${n.subject}${n.is_active ? ' · ACTIVE' : ''}</span>
                      <span class="em-chat-dm-subject-excerpt">${n.message}</span>
                      <span class="em-chat-dm-date">${String(n.date || '').slice(0, 10)}</span>
                    </div>`;
                })}
              </div>
            ` : html`
              <div class="em-chat-dm-head" aria-hidden="true">
                <input type="checkbox" class="em-chat-dm-check" checked=${allSelected} onChange=${toggleAll} />
                <span class="em-chat-dm-head-from">From</span>
                <span class="em-chat-dm-head-subject">Subject</span>
                <span class="em-chat-dm-head-star">★</span>
                <span class="em-chat-dm-head-actions">Actions</span>
              </div>
              <div class="em-chat-dm-scroll">
                ${th.loading && html`<div class="em-chat-panel-empty">Loading conversations…</div>`}
                ${th.err && html`<div class="em-chat-panel-empty em-chat-err">${th.err}</div>`}
                ${!th.loading && !th.err && shown.length === 0 && html`
                  <div class="em-chat-panel-empty">${box === 'starred' ? 'No starred conversations.' : (box === 'sent' ? 'Nothing sent yet.' : 'No conversations yet. Search above to start one.')}</div>
                `}
                ${shown.map(renderRow)}
              </div>
              <div class="em-chat-dm-bulk">
                <select value=${bulk} onChange=${function (e) { setBulk(e.target.value); }} aria-label="Bulk actions">
                  <option value="">Bulk Actions</option>
                  <option value="read">Mark read</option>
                  <option value="unread">Mark unread</option>
                  <option value="star">Star</option>
                  <option value="unstar">Unstar</option>
                  <option value="delete">Delete</option>
                </select>
                <button type="button" class="em-chat-proj-newbtn" disabled=${!anySelected || !bulk} onClick=${applyBulk}>Apply</button>
              </div>
            `}
          </div>`;
    }

    // v8.5: Projects tab — view DROPDOWN above the project cards, mirroring
    // the sections of the group's own /projects/ page (e.g.
    // /groups/<slug>/projects/): Projects workspace (the cards), Proposals,
    // My Tasks, and the group projects Calendar. Each view reads the SAME
    // stores those page panels render from, via the psoo-pm/v1 REST wrappers
    // (the page panels themselves are admin-ajax + psoo_pm_nonce, which this
    // widget can't call). Group scope comes from the ONE selector at the top
    // of the widget (GroupContextBar / ctxGid).
    // "＋ New Project" popup — mirrors the group page's launcher wizard:
    // step 1 is the same three contract-type cards; Self Build creates the
    // project right here (psoo-pm/v1 POST /groups/{id}/projects — the same
    // route the desktop "Push to Hub" uses); the two CONTRACT types need the
    // full wizard (templates, task plans, checkout), so they deep-link to
    // the group's Projects page where it lives.
    var EM_PROJ_TYPES = [
        ['self_build', '🛠', 'Self Build', 'You architect and run the build yourself — start a blank project right here.'],
        ['task_execution_contracts', '📁', 'Task Execution Contract', 'Hire specialists to execute predefined tasks. Launches the full wizard (templates, task plans, checkout) on the group page.'],
        ['investment_contracts', '➕', 'Investment Contract', 'Raise capital against verifiable project deliverables. Launches the full wizard on the group page.'],
    ];
    function ProjNewModal(props) {
        var stepState = useState('type'); var step = stepState[0], setStep = stepState[1];
        var typeState = useState(''); var type = typeState[0], setType = typeState[1];
        var gState = useState(null); var groups = gState[0], setGroups = gState[1];
        var gidState = useState(Number(props.ctxGid) || 0); var gid = gidState[0], setGid = gidState[1];
        var tState = useState(''); var title = tState[0], setTitle = tState[1];
        var dState = useState(''); var desc = dState[0], setDesc = dState[1];
        var cState = useState(''); var category = cState[0], setCategory = cState[1];
        var busyState = useState(false); var busy = busyState[0], setBusy = busyState[1];
        var errState = useState(''); var err = errState[0], setErr = errState[1];

        useEffect(function () {
            apiFetch({ url: wpJsonRoot + 'buddypress/v1/groups?user_id=' + cfg.currentUserId + '&per_page=100', method: 'GET' })
                .then(function (list) {
                    setGroups((Array.isArray(list) ? list : []).map(function (g) {
                        return { id: Number(g.id), name: g.name || ('Group #' + g.id), link: g.link || '' };
                    }));
                })
                .catch(function () { setGroups([]); });
        }, []);

        function groupById(id) {
            return (groups || []).filter(function (g) { return g.id === Number(id); })[0] || null;
        }
        function pickType(t) {
            setType(t);
            setStep(t === 'self_build' ? 'form' : 'link');
        }
        function create() {
            if (!gid) { setErr('Pick a business group first.'); return; }
            if (!title.trim()) { setErr('Project title is required.'); return; }
            setBusy(true); setErr('');
            apiFetch({
                url: wpJsonRoot + 'psoo-pm/v1/groups/' + gid + '/projects',
                method: 'POST',
                data: { title: title.trim(), description: desc, category: category },
            })
                .then(function (d) {
                    if (!d || !d.ok || !d.project) { throw new Error((d && d.error) || 'create failed'); }
                    var g = groupById(gid);
                    props.onCreated({
                        id: Number(d.project.id),
                        title: title.trim(),
                        groupId: gid,
                        groupName: g ? g.name : '',
                        avatar: '',
                        link: (g && g.link ? String(g.link).replace(/\/+$/, '') : '') + '/business-plan/?pm_project=' + Number(d.project.id),
                        description: desc,
                        status: 0,
                        tasksDone: 0, tasksTotal: 0, members: 1,
                        category: (props.categories && props.categories[category]) || '',
                        color: '',
                    });
                })
                .catch(function (e) { setBusy(false); setErr(cleanError(e)); });
        }
        function openFullWizard() {
            var g = groupById(gid);
            if (!g || !g.link) { setErr('Pick a business group first.'); return; }
            window.location.href = String(g.link).replace(/\/+$/, '') + '/projects/?tab=projects';
        }

        var groupSelect = html`
          <label class="em-chat-agent-field">
            <span>Business group</span>
            <select value=${gid || ''} onChange=${function (e) { setGid(Number(e.target.value) || 0); }}>
              <option value="">${groups === null ? 'Loading groups…' : '— pick a group'}</option>
              ${(groups || []).map(function (g) {
                return html`<option key=${g.id} value=${g.id}>${g.name}</option>`;
              })}
            </select>
          </label>`;

        var modal = html`
          <div class="em-chat-agent-modal em-chat-projnew-modal" role="dialog" aria-modal="true" aria-label="New project">
            <div class="em-chat-agent-backdrop" onClick=${props.onClose}></div>
            <div class="em-chat-agent-dialog em-chat-projnew-dialog">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">${step === 'type' ? 'Start a new project' : (step === 'form' ? 'Self Build project' : 'Contract project')}</span>
                <button type="button" class="em-chat-agent-x" onClick=${props.onClose} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body">
                ${step === 'type' && html`
                  <div class="em-chat-projnew-prompt">What kind of project contract are you launching?</div>
                  <div class="em-chat-projnew-grid">
                    ${EM_PROJ_TYPES.map(function (t) {
                      return html`
                        <button type="button" key=${t[0]} class="em-chat-projnew-card" onClick=${function () { pickType(t[0]); }}>
                          <span class="em-chat-projnew-ico" aria-hidden="true">${t[1]}</span>
                          <span class="em-chat-projnew-name">${t[2]}</span>
                          <span class="em-chat-projnew-desc">${t[3]}</span>
                        </button>`;
                    })}
                  </div>
                `}
                ${step === 'form' && html`
                  <button type="button" class="em-chat-projnew-back" onClick=${function () { setStep('type'); setErr(''); }}>← Back</button>
                  ${groupSelect}
                  <label class="em-chat-agent-field">
                    <span>Project title *</span>
                    <input type="text" value=${title} placeholder="Enter project name…"
                      onInput=${function (e) { setTitle(e.target.value); }} />
                  </label>
                  <label class="em-chat-agent-field">
                    <span>Description</span>
                    <textarea rows="3" value=${desc} placeholder="What is this project about?"
                      onInput=${function (e) { setDesc(e.target.value); }}></textarea>
                  </label>
                  <label class="em-chat-agent-field">
                    <span>Category</span>
                    <select value=${category} onChange=${function (e) { setCategory(e.target.value); }}>
                      <option value="">— Uncategorized —</option>
                      ${Object.keys(props.categories || {}).map(function (slug) {
                        return html`<option key=${slug} value=${slug}>${props.categories[slug]}</option>`;
                      })}
                    </select>
                  </label>
                `}
                ${step === 'link' && html`
                  <button type="button" class="em-chat-projnew-back" onClick=${function () { setStep('type'); setErr(''); }}>← Back</button>
                  ${groupSelect}
                  <div class="em-chat-seq-ws-note-inline">
                    ${(EM_PROJ_TYPES.filter(function (t) { return t[0] === type; })[0] || [])[2] || 'Contract'} projects
                    go through the full launch wizard — proposal templates, task-plan design and checkout —
                    which lives on the group's Projects page. You'll land there with the wizard one click away.
                  </div>
                `}
                ${err && html`<div class="em-chat-seq-note is-err" style=${{ margin: '10px 0 0' }}>${err}</div>`}
              </div>
              ${step !== 'type' && html`
                <footer class="em-chat-agent-foot">
                  <button type="button" class="em-chat-agent-cancel" onClick=${props.onClose}>Cancel</button>
                  ${step === 'form'
                    ? html`<button type="button" class="em-chat-agent-submit" disabled=${busy} onClick=${create}>${busy ? 'Creating…' : 'Create Project'}</button>`
                    : html`<button type="button" class="em-chat-agent-submit" onClick=${openFullWizard}>Open the full wizard →</button>`}
                </footer>
              `}
            </div>
          </div>`;
        return createPortal ? createPortal(modal, document.body) : modal;
    }

    // "＋ Invite new" popup (Messages → Members) — grafts the SAME Invite
    // panel the profile's Connections → Invite sub-tab renders
    // (/members/{me}/friends/ → Invite), fetched bare via the
    // gs_invite_panel_fragment admin-ajax endpoint. The fragment is
    // self-contained: scoped .gs-invite-* styles + an inline IIFE that
    // bootstraps by the wrap's unique id — innerHTML leaves scripts inert,
    // so each one is re-created to execute.
    function InviteNewModal(props) {
        var hostRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        var st = useState({ loading: true, err: '' });
        var state = st[0], setState = st[1];
        useEffect(function () {
            var ajaxUrl = wpJsonRoot.replace(/wp-json\/?$/, '') + 'wp-admin/admin-ajax.php';
            var cancelled = false;
            fetch(ajaxUrl + '?action=gs_invite_panel_fragment', { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(function (txt) {
                    if (cancelled || !hostRef.current) return;
                    hostRef.current.innerHTML = txt;
                    hostRef.current.querySelectorAll('script').forEach(function (s) {
                        var el = document.createElement('script');
                        if (s.src) { el.src = s.src; } else { el.textContent = s.textContent; }
                        s.parentNode.replaceChild(el, s);
                    });
                    setState({ loading: false, err: '' });
                })
                .catch(function (e) { if (!cancelled) setState({ loading: false, err: String((e && e.message) || e) }); });
            return function () { cancelled = true; };
        }, []);
        var modal = html`
          <div class="em-chat-agent-modal em-chat-invite-modal" role="dialog" aria-modal="true" aria-label="Invite new members">
            <div class="em-chat-agent-backdrop" onClick=${props.onClose}></div>
            <div class="em-chat-agent-dialog em-chat-invite-dialog">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">Invite new members</span>
                <button type="button" class="em-chat-agent-x" onClick=${props.onClose} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body em-chat-invite-body">
                ${state.loading && html`<div class="em-chat-panel-empty">Loading the invite panel…</div>`}
                ${state.err && html`<div class="em-chat-panel-empty em-chat-err">Could not load the invite panel: ${state.err}</div>`}
                <div ref=${hostRef}></div>
              </div>
            </div>
          </div>`;
        return createPortal ? createPortal(modal, document.body) : modal;
    }

    // Reusable themed one-field prompt modal (replaces native window.prompt,
    // which paints OS chrome that clashes with the widget). Reuses the
    // agent-modal classes, portaled to <body>.
    function EmPromptModal(props) {
        var vState = useState(props.initial || ''); var v = vState[0], setV = vState[1];
        var busyState = useState(false); var busy = busyState[0], setBusy = busyState[1];
        var errState = useState(''); var err = errState[0], setErr = errState[1];
        function submit() {
            var clean = v.trim();
            if (!clean) { setErr((props.label || 'Name') + ' is required.'); return; }
            setBusy(true); setErr('');
            Promise.resolve(props.onSubmit(clean))
                .catch(function (e) { setBusy(false); setErr(cleanError(e) || 'Could not save.'); });
        }
        var modal = html`
          <div class="em-chat-agent-modal" role="dialog" aria-modal="true" aria-label=${props.title || 'New'}>
            <div class="em-chat-agent-backdrop" onClick=${props.onCancel}></div>
            <div class="em-chat-agent-dialog em-chat-prompt-dialog">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">${props.title || 'New'}</span>
                <button type="button" class="em-chat-agent-x" onClick=${props.onCancel} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body">
                <label class="em-chat-agent-field">
                  <span>${props.label || 'Name'}</span>
                  <input type="text" value=${v} placeholder=${props.placeholder || ''} autoFocus
                    onInput=${function (e) { setV(e.target.value); }}
                    onKeyDown=${function (e) { if (e.key === 'Enter') { submit(); } if (e.key === 'Escape') { props.onCancel(); } }} />
                </label>
                ${props.hint && html`<small class="em-chat-agent-help">${props.hint}</small>`}
                ${err && html`<div class="em-chat-seq-note is-err" style=${{ margin: '10px 0 0' }}>${err}</div>`}
              </div>
              <footer class="em-chat-agent-foot">
                <button type="button" class="em-chat-agent-cancel" onClick=${props.onCancel}>Cancel</button>
                <button type="button" class="em-chat-agent-submit" disabled=${busy} onClick=${submit}>
                  ${busy ? '…' : (props.submitLabel || 'Create')}
                </button>
              </footer>
            </div>
          </div>`;
        return createPortal ? createPortal(modal, document.body) : modal;
    }

    // Reusable themed section dropdown (glass style, matches the group
    // picker) — options are [value, icon, label] triples. Used by the
    // Agents tab; the Projects tab renders the same markup inline.
    function EmViewSelect(props) {
        var openState = useState(false); var open = openState[0], setOpen = openState[1];
        var wrapRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        useEffect(function () {
            if (!open) return;
            function onDocClick(e) {
                if (wrapRef.current && !wrapRef.current.contains(e.target)) { setOpen(false); }
            }
            document.addEventListener('mousedown', onDocClick, true);
            document.addEventListener('touchstart', onDocClick, true);
            return function () {
                document.removeEventListener('mousedown', onDocClick, true);
                document.removeEventListener('touchstart', onDocClick, true);
            };
        }, [open]);
        var opts = props.options || [];
        var current = opts.filter(function (v) { return v[0] === props.value; })[0] || opts[0] || ['', '', ''];
        return html`
          <div class="em-chat-proj-bar em-chat-viewsel-bar" ref=${wrapRef}>
            <button type="button" class="em-chat-viewsel-btn" aria-haspopup="listbox" aria-expanded=${open ? 'true' : 'false'}
              aria-label=${props.ariaLabel || 'Section'}
              onClick=${function () { setOpen(!open); }}>
              <span class="em-chat-viewsel-ico" aria-hidden="true">${current[1]}</span>
              <span class="em-chat-viewsel-name">${current[2]}</span>
              <span class="em-chat-viewsel-caret" aria-hidden="true">▾</span>
            </button>
            ${open && html`
              <div class="em-chat-viewsel-list" role="listbox">
                ${opts.map(function (v) {
                  return html`
                    <button type="button" key=${v[0]} role="option" aria-selected=${props.value === v[0] ? 'true' : 'false'}
                      class=${'em-chat-viewsel-item' + (props.value === v[0] ? ' is-current' : '')}
                      onClick=${function () { props.onChange && props.onChange(v[0]); setOpen(false); }}>
                      <span class="em-chat-viewsel-ico" aria-hidden="true">${v[1]}</span>
                      <span class="em-chat-viewsel-name">${v[2]}</span>
                    </button>`;
                })}
              </div>
            `}
          </div>`;
    }

    var EM_PROJ_VIEWS = [
        ['projects',  '📁', 'Projects workspace'],
        ['proposals', '📄', 'Proposals'],
        ['my-tasks',  '✅', 'My Tasks'],
        ['calendar',  '📅', 'Calendar'],
    ];
    function ProjectsPane(props) {
        var viewState = useState('projects'); var view = viewState[0], setView = viewState[1];
        var ctxGid = (props && Number(props.ctxGid)) || 0;
        // Custom themed dropdown — a native <select> paints its option list
        // with OS chrome (grey/system colors) that clashes with the widget's
        // glass theme; this mirrors the GroupContextBar picker instead.
        var openState = useState(false); var open = openState[0], setOpen = openState[1];
        var wrapRef = wp.element.useRef ? wp.element.useRef(null) : { current: null };
        useEffect(function () {
            if (!open) return;
            function onDocClick(e) {
                if (wrapRef.current && !wrapRef.current.contains(e.target)) { setOpen(false); }
            }
            document.addEventListener('mousedown', onDocClick, true);
            document.addEventListener('touchstart', onDocClick, true);
            return function () {
                document.removeEventListener('mousedown', onDocClick, true);
                document.removeEventListener('touchstart', onDocClick, true);
            };
        }, [open]);
        var current = EM_PROJ_VIEWS.filter(function (v) { return v[0] === view; })[0] || EM_PROJ_VIEWS[0];
        return html`
          <div class="em-chat-projects-pane">
            <div class="em-chat-proj-bar em-chat-viewsel-bar" ref=${wrapRef}>
              <button type="button" class="em-chat-viewsel-btn" aria-haspopup="listbox" aria-expanded=${open ? 'true' : 'false'}
                onClick=${function () { setOpen(!open); }}>
                <span class="em-chat-viewsel-ico" aria-hidden="true">${current[1]}</span>
                <span class="em-chat-viewsel-name">${current[2]}</span>
                <span class="em-chat-viewsel-caret" aria-hidden="true">▾</span>
              </button>
              ${open && html`
                <div class="em-chat-viewsel-list" role="listbox">
                  ${EM_PROJ_VIEWS.map(function (v) {
                    return html`
                      <button type="button" key=${v[0]} role="option" aria-selected=${view === v[0] ? 'true' : 'false'}
                        class=${'em-chat-viewsel-item' + (view === v[0] ? ' is-current' : '')}
                        onClick=${function () { setView(v[0]); setOpen(false); }}>
                        <span class="em-chat-viewsel-ico" aria-hidden="true">${v[1]}</span>
                        <span class="em-chat-viewsel-name">${v[2]}</span>
                      </button>`;
                  })}
                </div>
              `}
            </div>
            ${view === 'projects' && html`<${ProjectCardsPane} onClosePanel=${props.onClosePanel} ctxGid=${ctxGid} />`}
            ${view === 'proposals' && html`<${ProjectProposalsPane} ctxGid=${ctxGid} />`}
            ${view === 'my-tasks' && html`<${ProjectMyTasksPane} onClosePanel=${props.onClosePanel} ctxGid=${ctxGid} />`}
            ${view === 'calendar' && html`<${ProjectCalendarPane} ctxGid=${ctxGid} />`}
          </div>
        `;
    }

    // Projects → Proposals view: the group's proposal board (st_proposal
    // posts linked via _stp_group_id/_psoo_group_id), read through
    // psoo-pm/v1/groups/{id}/proposals. Server enforces the same audience
    // as the page's Proposals tab (site admin / group admin+mod / assigned
    // consultant) and answers can_view:false for everyone else.
    function ProjectProposalsPane(props) {
        var gid = Number(props.ctxGid) || 0;
        var st = useState({ loading: false, canView: true, rows: [], boardUrl: '', err: '' });
        var state = st[0], setState = st[1];

        useEffect(function () {
            if (!gid) { setState({ loading: false, canView: true, rows: [], boardUrl: '', err: '' }); return; }
            setState({ loading: true, canView: true, rows: [], boardUrl: '', err: '' });
            apiFetch({ url: wpJsonRoot + 'psoo-pm/v1/groups/' + gid + '/proposals', method: 'GET' })
                .then(function (d) {
                    setState({
                        loading: false,
                        canView: !(d && d.can_view === false),
                        rows: (d && d.proposals) || [],
                        boardUrl: (d && d.board_url) || '',
                        err: '',
                    });
                })
                .catch(function (e) { setState({ loading: false, canView: true, rows: [], boardUrl: '', err: cleanError(e) }); });
        }, [gid]);

        function openBoard() {
            if (state.boardUrl) window.location.href = state.boardUrl;
        }

        return html`
          <div class="em-chat-proj-body em-chat-proposals-pane">
            ${!gid && html`<div class="em-chat-panel-empty">Pick a business group from the selector at the top of the chat to see its proposals.</div>`}
            ${state.loading && html`<div class="em-chat-panel-empty">Loading proposals…</div>`}
            ${state.err && html`<div class="em-chat-panel-empty em-chat-err">${state.err}</div>`}
            ${!!gid && !state.loading && !state.err && !state.canView && html`
              <div class="em-chat-panel-empty">Proposals are visible to this group's admins and its project consultant.</div>
            `}
            ${!!gid && !state.loading && !state.err && state.canView && state.rows.length === 0 && html`
              <div class="em-chat-panel-empty">No proposals in this group yet.</div>
            `}
            ${state.rows.length > 0 && html`
              <ul class="em-chat-proj-list" role="list">
                ${state.rows.map(function (p) {
                  return html`
                    <li key=${p.id} class="em-chat-proj-row em-chat-prop-row" onClick=${openBoard}>
                      <span class="em-chat-proj-row-title">${p.title}${p.is_active ? html`<span class="em-chat-row-tag is-project">ACTIVE</span>` : null}</span>
                      ${p.date && html`<span class="em-chat-proj-row-due">${p.date}</span>`}
                      <span class=${'em-chat-prop-status is-' + (p.status || 'offer')}>${p.status_label || p.status}</span>
                    </li>
                  `;
                })}
              </ul>
            `}
            ${!!gid && state.canView && state.boardUrl && html`
              <button type="button" class="em-chat-proj-openfull" onClick=${openBoard}>Open full Proposals board →</button>
            `}
          </div>
        `;
    }

    // Projects → My Tasks view: tasks assigned to the member across their
    // projects (psoo-pm/v1/me/tasks — the same query behind the group
    // page's "Tasks assigned to me" panel), narrowed to the widget's group
    // context when one is picked. Rows open the task's project workspace.
    function ProjectMyTasksPane(props) {
        var pmRoot = wpJsonRoot + 'psoo-pm/v1';
        var gid = Number(props.ctxGid) || 0;
        var statusState = useState('0'); var status = statusState[0], setStatus = statusState[1];
        var st = useState({ loading: true, rows: [], err: '' });
        var state = st[0], setState = st[1];
        var openingState = useState(0); var opening = openingState[0], setOpening = openingState[1];

        useEffect(function () {
            setState({ loading: true, rows: [], err: '' });
            var q = '?status=' + status + (gid ? '&group_id=' + gid : '');
            apiFetch({ url: pmRoot + '/me/tasks' + q, method: 'GET' })
                .then(function (d) { setState({ loading: false, rows: (d && d.tasks) || [], err: '' }); })
                .catch(function (e) { setState({ loading: false, rows: [], err: cleanError(e) }); });
        }, [gid, status]);

        function open(t) {
            if (opening || !t.group_id || !t.project_id) return;
            apiFetch({ url: pmRoot + '/me/active-project', method: 'POST', data: { project_id: t.project_id } }).catch(function () {});
            setOpening(t.id);
            emLoadPmWorkspace(t.group_id).then(function (ok) {
                setOpening(0);
                if (ok && window.PM && window.PM.openProjectDrawer) {
                    // Drawer paints above the panel (see ProjectCardsPane note).
                    window.PM.openProjectDrawer(t.project_id);
                }
            });
        }

        return html`
          <div class="em-chat-proj-body em-chat-mytasks-pane">
            <div class="em-chat-mytasks-head">
              <span class="em-chat-mytasks-title">Tasks assigned to me</span>
              <select class="em-chat-proj-select em-chat-mytasks-status" value=${status} aria-label="Task status"
                onChange=${function (e) { setStatus(e.target.value); }}>
                <option value="0">Open</option>
                <option value="1">Completed</option>
              </select>
            </div>
            ${state.loading && html`<div class="em-chat-panel-empty">Loading your tasks…</div>`}
            ${state.err && html`<div class="em-chat-panel-empty em-chat-err">${state.err}</div>`}
            ${!state.loading && !state.err && state.rows.length === 0 && html`
              <div class="em-chat-panel-empty">${status === '0' ? 'No open tasks assigned to you' : 'No completed tasks'}${gid ? ' in this group.' : '.'}</div>
            `}
            ${state.rows.length > 0 && html`
              <ul class="em-chat-proj-list" role="list">
                ${state.rows.map(function (t) {
                  return html`
                    <li key=${t.id} class="em-chat-proj-row em-chat-mytask-row" onClick=${function () { open(t); }}>
                      <span class="em-chat-proj-row-title">
                        ${t.title}
                        <span class="em-chat-mytask-sub">${t.project_title}${(!gid && t.group_name) ? ' · ' + t.group_name : ''}</span>
                      </span>
                      ${t.due_date && html`<span class="em-chat-proj-row-due">${String(t.due_date).slice(0, 10)}</span>`}
                      <span class=${'em-chat-proj-status' + (Number(t.status) === 1 ? ' is-s2' : '')}>${Number(t.status) === 1 ? 'Done' : (opening === t.id ? 'Opening…' : 'Open')}</span>
                    </li>
                  `;
                })}
              </ul>
            `}
          </div>
        `;
    }

    // Projects → Calendar view: the group's project due dates, task due
    // dates and milestone due dates on a compact month grid
    // (psoo-pm/v1/groups/{id}/calendar — same sources the page's Calendar
    // panel plots). All the group's dated events arrive in ONE fetch;
    // month navigation just refilters client-side.
    function ProjectCalendarPane(props) {
        var gid = Number(props.ctxGid) || 0;
        var st = useState({ loading: false, events: [], err: '' });
        var state = st[0], setState = st[1];
        var now = new Date();
        var ymState = useState({ y: now.getFullYear(), m: now.getMonth() });
        var ym = ymState[0], setYm = ymState[1];

        useEffect(function () {
            if (!gid) { setState({ loading: false, events: [], err: '' }); return; }
            setState({ loading: true, events: [], err: '' });
            apiFetch({ url: wpJsonRoot + 'psoo-pm/v1/groups/' + gid + '/calendar', method: 'GET' })
                .then(function (d) { setState({ loading: false, events: (d && d.events) || [], err: '' }); })
                .catch(function (e) { setState({ loading: false, events: [], err: cleanError(e) }); });
        }, [gid]);

        function nav(delta) {
            var m = ym.m + delta, y = ym.y;
            if (m < 0) { m = 11; y--; }
            if (m > 11) { m = 0; y++; }
            setYm({ y: y, m: m });
        }

        var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        var TYPE_ICON = { project: '📁', task: '✅', milestone: '🏁' };
        var prefix = ym.y + '-' + String(ym.m + 1).padStart(2, '0') + '-';
        var monthEvents = state.events.filter(function (e) { return String(e.date || '').indexOf(prefix) === 0; });
        var byDay = {};
        monthEvents.forEach(function (e) {
            var d = parseInt(String(e.date).slice(8, 10), 10);
            (byDay[d] = byDay[d] || []).push(e);
        });
        var firstDow = new Date(ym.y, ym.m, 1).getDay();
        var daysInMonth = new Date(ym.y, ym.m + 1, 0).getDate();
        var todayKey = now.getFullYear() === ym.y && now.getMonth() === ym.m ? now.getDate() : 0;
        var cells = [];
        for (var i = 0; i < firstDow; i++) cells.push(0);
        for (var d = 1; d <= daysInMonth; d++) cells.push(d);

        return html`
          <div class="em-chat-proj-body em-chat-cal-pane">
            ${!gid && html`<div class="em-chat-panel-empty">Pick a business group from the selector at the top of the chat to see its project calendar.</div>`}
            ${state.err && html`<div class="em-chat-panel-empty em-chat-err">${state.err}</div>`}
            ${!!gid && !state.err && html`
              <div class="em-chat-cal-head">
                <button type="button" class="em-chat-cal-nav" aria-label="Previous month" onClick=${function () { nav(-1); }}>←</button>
                <span class="em-chat-cal-label">${MONTHS[ym.m]} ${ym.y}</span>
                <button type="button" class="em-chat-cal-nav" aria-label="Next month" onClick=${function () { nav(1); }}>→</button>
              </div>
              <div class="em-chat-cal-grid" role="grid">
                ${['S', 'M', 'T', 'W', 'T', 'F', 'S'].map(function (w, i) {
                  return html`<span key=${'w' + i} class="em-chat-cal-dow">${w}</span>`;
                })}
                ${cells.map(function (d, i) {
                  if (!d) return html`<span key=${'e' + i} class="em-chat-cal-cell is-empty"></span>`;
                  var evs = byDay[d] || [];
                  return html`
                    <span key=${'d' + d} class=${'em-chat-cal-cell' + (d === todayKey ? ' is-today' : '') + (evs.length ? ' has-events' : '')}>
                      ${d}
                      ${evs.length > 0 && html`<span class="em-chat-cal-dots">${evs.slice(0, 3).map(function (e, j) {
                        return html`<i key=${j} class=${'em-chat-cal-dot is-' + (e.type || 'task')}></i>`;
                      })}</span>`}
                    </span>
                  `;
                })}
              </div>
              ${state.loading && html`<div class="em-chat-panel-empty">Loading calendar…</div>`}
              ${!state.loading && monthEvents.length === 0 && html`
                <div class="em-chat-panel-empty">Nothing due in ${MONTHS[ym.m]}.</div>
              `}
              ${monthEvents.length > 0 && html`
                <ul class="em-chat-proj-list" role="list">
                  ${monthEvents.map(function (e, i) {
                    return html`
                      <li key=${i} class="em-chat-proj-row">
                        <span class="em-chat-proj-row-title">
                          ${TYPE_ICON[e.type] || '•'} ${e.title}
                          ${e.type !== 'project' && e.project_title && html`<span class="em-chat-mytask-sub">${e.project_title}</span>`}
                        </span>
                        <span class="em-chat-proj-row-due">${String(e.date).slice(5)}</span>
                        ${e.done && html`<span class="em-chat-proj-status is-s2">Done</span>`}
                      </li>
                    `;
                  })}
                </ul>
              `}
            `}
          </div>
        `;
    }

    // v8.4: Projects tab — a compact projects WORKSPACE dashboard on the
    // same psoo-pm/v1 store the desktop ProjectsPanel drives. Two selection
    // menus (business group → project) and per-project views: Overview /
    // Tasks / Milestones / Activity. The picked project persists via
    // /me/active-project, so the desktop app and this widget stay on the
    // same project. Cookie-session auth — psoo-pm accepts the logged-in
    // user directly.
    var PM_TASK_STATUS = { 0: 'To do', 1: 'In progress', 2: 'Done' };
    function ProjectsWorkspacePane() {
        var pmRoot = wpJsonRoot + 'psoo-pm/v1';
        var groupsState = useState(null); var groups = groupsState[0], setGroups = groupsState[1];
        var gidState    = useState(0);    var gid = gidState[0], setGid = gidState[1];
        var projsState  = useState(null); var projs = projsState[0], setProjs = projsState[1];
        var pidState    = useState(0);    var pid = pidState[0], setPid = pidState[1];
        var viewState   = useState('overview'); var view = viewState[0], setView = viewState[1];
        var dataState   = useState({});   var data = dataState[0], setData = dataState[1];
        var errState    = useState('');   var err = errState[0], setErr = errState[1];

        function rows(x, key) {
            if (Array.isArray(x)) return x;
            if (x && Array.isArray(x[key])) return x[key];
            if (x && Array.isArray(x.items)) return x.items;
            return [];
        }

        // Boot: the user's groups + their persisted active project.
        useEffect(function () {
            apiFetch({ url: wpJsonRoot + 'buddypress/v1/groups?user_id=' + cfg.currentUserId + '&per_page=100', method: 'GET' })
                .then(function (list) { setGroups(Array.isArray(list) ? list : []); })
                .catch(function () { setGroups([]); });
            apiFetch({ url: pmRoot + '/me/active-project', method: 'GET' })
                .then(function (d) {
                    if (d && d.project && d.project.id) {
                        if (d.project.group_id) setGid(Number(d.project.group_id));
                        setPid(Number(d.project.id));
                    }
                })
                .catch(function () { /* fresh start */ });
        }, []);

        // Group picked → its projects menu.
        useEffect(function () {
            if (!gid) { setProjs(null); return; }
            setProjs(null);
            apiFetch({ url: pmRoot + '/groups/' + gid + '/projects', method: 'GET' })
                .then(function (d) { setProjs(rows(d, 'projects')); })
                .catch(function (e) { setProjs([]); setErr(cleanError(e)); });
        }, [gid]);

        // Project picked → dashboard data + persist as the active project.
        useEffect(function () {
            if (!pid) { setData({}); return; }
            setErr('');
            setData({});
            apiFetch({ url: pmRoot + '/projects/' + pid, method: 'GET' })
                .then(function (p) { setData(function (cur) { return Object.assign({}, cur, { project: p }); }); })
                .catch(function (e) { setErr(cleanError(e)); });
            apiFetch({ url: pmRoot + '/projects/' + pid + '/tasks', method: 'GET' })
                .then(function (d) { setData(function (cur) { return Object.assign({}, cur, { tasks: rows(d, 'tasks') }); }); })
                .catch(function () {});
            apiFetch({ url: pmRoot + '/projects/' + pid + '/milestones', method: 'GET' })
                .then(function (d) { setData(function (cur) { return Object.assign({}, cur, { milestones: rows(d, 'milestones') }); }); })
                .catch(function () {});
            apiFetch({ url: pmRoot + '/me/active-project', method: 'POST', data: { project_id: pid } }).catch(function () {});
        }, [pid]);

        // Activity is fetched lazily the first time its view opens.
        useEffect(function () {
            if (view !== 'activity' || !pid || data.activity) return;
            apiFetch({ url: pmRoot + '/projects/' + pid + '/activity', method: 'GET' })
                .then(function (d) { setData(function (cur) { return Object.assign({}, cur, { activity: rows(d, 'activity') }); }); })
                .catch(function () { setData(function (cur) { return Object.assign({}, cur, { activity: [] }); }); });
        }, [view, pid, data.activity]);

        function cycleTask(t) {
            var next = ((Number(t.status) || 0) + 1) % 3;
            apiFetch({ url: pmRoot + '/tasks/' + t.id, method: 'POST', data: { status: next } })
                .then(function () {
                    setData(function (cur) {
                        var ts = (cur.tasks || []).map(function (x) {
                            return x.id === t.id ? Object.assign({}, x, { status: next }) : x;
                        });
                        return Object.assign({}, cur, { tasks: ts });
                    });
                })
                .catch(function (e) { setErr(cleanError(e)); });
        }

        var tasks = data.tasks || [];
        var doneCount = tasks.filter(function (t) { return Number(t.status) === 2; }).length;

        return html`
          <div class="em-chat-projects-pane">
            <div class="em-chat-proj-bar">
              <select class="em-chat-proj-select" value=${gid || ''} aria-label="Business group"
                onChange=${function (e) { setGid(Number(e.target.value) || 0); setPid(0); }}>
                <option value="">${groups === null ? 'Loading groups…' : 'Pick a business group…'}</option>
                ${(groups || []).map(function (g) {
                  return html`<option key=${g.id} value=${g.id}>${g.name}</option>`;
                })}
              </select>
              <select class="em-chat-proj-select" value=${pid || ''} aria-label="Project" disabled=${!gid}
                onChange=${function (e) { setPid(Number(e.target.value) || 0); setView('overview'); }}>
                <option value="">${!gid ? 'Project…' : (projs === null ? 'Loading projects…' : (projs.length ? 'Pick a project…' : 'No projects in this group'))}</option>
                ${(projs || []).map(function (p) {
                  return html`<option key=${p.id} value=${p.id}>${p.title}</option>`;
                })}
              </select>
            </div>

            ${err && html`<div class="em-chat-panel-empty em-chat-err">${err}</div>`}

            ${!pid && !err && html`
              <div class="em-chat-panel-empty">
                Pick a business group and project above — the workspace dashboard
                (overview, tasks, milestones, activity) opens right here.
              </div>
            `}

            ${pid ? html`
              <div class="em-chat-agent-subfilter em-chat-proj-views" role="tablist" aria-label="Project view">
                ${[['overview', 'Overview'], ['tasks', 'Tasks'], ['milestones', 'Milestones'], ['activity', 'Activity']].map(function (v) {
                  return html`
                    <button type="button" role="tab" key=${v[0]} aria-selected=${view === v[0] ? 'true' : 'false'}
                      class=${'em-chat-subtab ' + (view === v[0] ? 'is-active' : '')}
                      onClick=${function () { setView(v[0]); }}>${v[1]}</button>
                  `;
                })}
              </div>

              <div class="em-chat-proj-body">
                ${view === 'overview' && html`
                  <div class="em-chat-proj-overview">
                    <div class="em-chat-proj-title">${data.project ? data.project.title : 'Loading…'}</div>
                    ${data.project && data.project.description && html`<div class="em-chat-proj-desc">${data.project.description}</div>`}
                    <div class="em-chat-proj-stats">
                      <div class="em-chat-proj-stat"><span>${tasks.length}</span> tasks</div>
                      <div class="em-chat-proj-stat"><span>${doneCount}</span> done</div>
                      <div class="em-chat-proj-stat"><span>${(data.milestones || []).length}</span> milestones</div>
                    </div>
                  </div>
                `}

                ${view === 'tasks' && html`
                  <ul class="em-chat-proj-list" role="list">
                    ${!data.tasks && html`<li class="em-chat-panel-empty">Loading tasks…</li>`}
                    ${data.tasks && tasks.length === 0 && html`<li class="em-chat-panel-empty">No tasks yet.</li>`}
                    ${tasks.map(function (t) {
                      return html`
                        <li key=${t.id} class="em-chat-proj-row">
                          <span class="em-chat-proj-row-title">${t.title}</span>
                          ${t.due_date && html`<span class="em-chat-proj-row-due">${String(t.due_date).slice(0, 10)}</span>`}
                          <button type="button" class=${'em-chat-proj-status is-s' + (Number(t.status) || 0)}
                            title="Tap to advance status"
                            onClick=${function () { cycleTask(t); }}>${PM_TASK_STATUS[Number(t.status) || 0] || t.status}</button>
                        </li>
                      `;
                    })}
                  </ul>
                `}

                ${view === 'milestones' && html`
                  <ul class="em-chat-proj-list" role="list">
                    ${!data.milestones && html`<li class="em-chat-panel-empty">Loading milestones…</li>`}
                    ${data.milestones && data.milestones.length === 0 && html`<li class="em-chat-panel-empty">No milestones yet.</li>`}
                    ${(data.milestones || []).map(function (m, i) {
                      return html`
                        <li key=${m.id || i} class="em-chat-proj-row">
                          <span class="em-chat-proj-row-title">${m.title || m.name || ('Milestone ' + (i + 1))}</span>
                          ${(m.due_date || m.due) && html`<span class="em-chat-proj-row-due">${String(m.due_date || m.due).slice(0, 10)}</span>`}
                        </li>
                      `;
                    })}
                  </ul>
                `}

                ${view === 'activity' && html`
                  <ul class="em-chat-proj-list" role="list">
                    ${!data.activity && html`<li class="em-chat-panel-empty">Loading activity…</li>`}
                    ${data.activity && data.activity.length === 0 && html`<li class="em-chat-panel-empty">No recent activity.</li>`}
                    ${(data.activity || []).map(function (a, i) {
                      return html`
                        <li key=${a.id || i} class="em-chat-proj-row em-chat-proj-row--activity">
                          <span class="em-chat-proj-row-title">${a.text || a.message || a.summary || a.action || 'Activity'}</span>
                          ${(a.created_at || a.date || a.ts) && html`<span class="em-chat-proj-row-due">${String(a.created_at || a.date || a.ts).slice(0, 10)}</span>`}
                        </li>
                      `;
                    })}
                  </ul>
                `}
              </div>
            ` : null}
          </div>
        `;
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

        // ── Fetch the group's connected devices (group admins' + hub super
        // admins' servers/desktops/mobiles). Before a home group is chosen it
        // lists the caller's own devices; refetches once the group is known.
        useEffect(function () {
            setAllDevices({ loaded: false, loading: true, items: [], err: null });
            apiFetch({ url: psooRoot + '/devices' + (homeGroup ? '?group_id=' + encodeURIComponent(homeGroup) : ''), method: 'GET' })
                .then(function (d) {
                    var items = (d && Array.isArray(d.devices)) ? d.devices : (Array.isArray(d) ? d : []);
                    setAllDevices({ loaded: true, loading: false, items: items, err: null });
                })
                .catch(function (e) { setAllDevices({ loaded: true, loading: false, items: [], err: cleanError(e) }); });
        }, [homeGroup]);

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

    // Draft text field that COMMITS on blur — used by the workspace panels
    // where every change becomes a web→desktop op (per-keystroke ops would
    // flood the queue). Re-seeds from props when the upstream value changes.
    function WsDraftText(props) {
        var vState = useState(props.value || '');
        var v = vState[0], setV = vState[1];
        useEffect(function () { setV(props.value || ''); }, [props.value]);
        return html`
          <textarea class="em-chat-seq-ws-draft" rows=${props.rows || 3} value=${v}
            placeholder=${props.placeholder || ''}
            onInput=${function (e) { setV(e.target.value); }}
            onBlur=${function () { if (v !== (props.value || '')) { props.onCommit && props.onCommit(v); } }}></textarea>`;
    }

    // Small segmented control (desktop PeopleAgentToggle analogue).
    function WsSegmented(props) {
        return html`
          <div class="em-chat-seq-ws-seg" role="tablist">
            ${(props.options || []).map(function (o) {
              return html`
                <button type="button" key=${o.value} role="tab"
                  aria-selected=${props.value === o.value ? 'true' : 'false'}
                  class=${'em-chat-seq-ws-seg-btn' + (props.value === o.value ? ' is-on' : '')}
                  onClick=${function () { props.onChange && props.onChange(o.value); }}>
                  ${o.label}
                </button>`;
            })}
          </div>`;
    }

    // ── Sub-task POPUP editor (desktop SubtaskEditor parity) ─────────────
    // Every field the desktop popup edits, saved as ONE subtask.update op.
    // Desktop-only surfaces are shown read-only with a note: the Update
    // target REGISTRY picker (needs desktop IPC), AI "Build prompt" runs,
    // and per-sub-task agent runs.
    var WS_EXEC_OPTS = [
        { value: 'people', label: '👤 People' },
        { value: 'agent',  label: '🤖 Agent' },
        { value: 'update', label: '🔄 Update' },
    ];
    var WS_KIND_OPTS = [
        { value: 'sequence', label: '📋 Sequence' },
        { value: 'chatflow', label: '💬 Launch chatflow' },
        { value: 'prompt',   label: '✨ Build Prompt' },
    ];
    var WS_ENV_OPTS = [
        { value: 'live',   label: '🌐 Live site' },
        { value: 'local',  label: '💻 Local mirror' },
        { value: 'gendme', label: '🏠 gend.me hub' },
    ];
    function WsSubtaskEditor(props) {
        var s = props.subtask || {};
        var f = useState({
            title: s.title || '',
            task_type: s.task_type === 'agent' || s.task_type === 'update' ? s.task_type : 'people',
            taskKind: s.taskKind === 'chatflow' || s.taskKind === 'prompt' ? s.taskKind : 'sequence',
            agentSequenceId: s.agentSequenceId || '',
            prompt: s.prompt || '',
            aiIntegration: s.aiIntegration || '',
            assignee: s.assignee || '',
            memberId: s.memberId || '',
            approvalMode: s.approvalMode === 'member' ? 'member' : 'auto',
            approverId: s.approverId || '',
            approverName: s.approverName || '',
            updateEnvironment: s.updateEnvironment || 'live',
            updateGroupId: s.updateGroupId || '',
            chatflowId: s.chatflowId || '',
            chatflowTitle: s.chatflowTitle || '',
            promptSourceSubtaskId: s.promptSourceSubtaskId || '',
            builtPrompt: s.builtPrompt || '',
        });
        var d = f[0], setD = f[1];
        function set(patch) { setD(Object.assign({}, d, patch)); }

        var members = props.members || [];
        var chatflows = props.chatflows || [];
        var groupSeqs = props.groupSeqs || [];
        var siblings = props.siblingChatflows || [];

        function save() {
            var t = d.task_type;
            // Mirrors the desktop SubtaskEditor save payload: irrelevant
            // plain-string fields blank on type switch; enum fields keep
            // their last value ('' is ignored by the desktop whitelist).
            props.onSave({
                title: d.title.trim() || s.title || '(untitled)',
                task_type: t,
                taskKind: d.taskKind,
                agentSequenceId: t === 'agent' ? d.agentSequenceId : '',
                prompt: d.prompt,
                aiIntegration: t === 'agent' ? d.aiIntegration : '',
                assignee: t === 'people' ? ((members.filter(function (m) { return String(m.id) === String(d.memberId); })[0] || {}).name || d.assignee) : '',
                memberId: t === 'people' ? d.memberId : '',
                approvalMode: t === 'agent' ? d.approvalMode : '',
                approverId: (t === 'agent' && d.approvalMode === 'member') ? d.approverId : '',
                approverName: (t === 'agent' && d.approvalMode === 'member')
                    ? ((members.filter(function (m) { return String(m.id) === String(d.approverId); })[0] || {}).name || d.approverName)
                    : '',
                updateEnvironment: t === 'update' ? d.updateEnvironment : '',
                updateGroupId: t === 'update' ? d.updateGroupId : '',
                chatflowId: d.taskKind === 'chatflow' ? d.chatflowId : '',
                chatflowTitle: d.taskKind === 'chatflow' ? d.chatflowTitle : '',
                promptSourceSubtaskId: d.taskKind === 'prompt' ? d.promptSourceSubtaskId : '',
                builtPrompt: d.taskKind === 'prompt' ? d.builtPrompt : '',
            });
        }

        return html`
          <div class="em-chat-seq-sub-modal" role="dialog" aria-modal="true" aria-label="Sub-task">
            <div class="em-chat-agent-backdrop" onClick=${props.onCancel}></div>
            <div class="em-chat-agent-dialog em-chat-seq-sub-dialog">
              <header class="em-chat-agent-head">
                <span class="em-chat-agent-title">Sub-task</span>
                <button type="button" class="em-chat-agent-x" onClick=${props.onCancel} aria-label="Close">×</button>
              </header>
              <div class="em-chat-agent-body">
                <label class="em-chat-agent-field">
                  <span>Title</span>
                  <input type="text" value=${d.title} onInput=${function (e) { set({ title: e.target.value }); }} />
                </label>

                <div class="em-chat-agent-field">
                  <span>Execute</span>
                  <${WsSegmented} options=${WS_EXEC_OPTS} value=${d.task_type} onChange=${function (v) { set({ task_type: v }); }} />
                  <small class="em-chat-agent-help">
                    ${d.task_type === 'people' ? 'A person owns this task.'
                      : d.task_type === 'agent' ? 'An agent auto-runs this task (runs execute on the desktop app or via triggers).'
                      : "Writes the previous sub-task's output to a site or group setting."}
                  </small>
                </div>

                <div class="em-chat-agent-field">
                  <span>Task type</span>
                  <${WsSegmented} options=${WS_KIND_OPTS} value=${d.taskKind} onChange=${function (v) { set({ taskKind: v }); }} />
                </div>

                ${d.taskKind === 'chatflow' && html`
                  <label class="em-chat-agent-field">
                    <span>Chatflow</span>
                    <select value=${String(d.chatflowId || '')}
                      onChange=${function (e) {
                        var id = e.target.value;
                        var cf = chatflows.filter(function (c) { return String(c.id) === String(id); })[0];
                        set({ chatflowId: id, chatflowTitle: cf ? (cf.title || '') : '' });
                      }}>
                      <option value="">— pick a chatflow</option>
                      ${chatflows.map(function (c) {
                        return html`<option key=${String(c.id)} value=${String(c.id)}>${c.title || ('Chatflow ' + c.id)}</option>`;
                      })}
                    </select>
                    ${s.launchStatus && html`<small class="em-chat-agent-help">Launch status: ${s.launchStatus}</small>`}
                  </label>
                `}

                ${d.taskKind === 'prompt' && html`
                  <label class="em-chat-agent-field">
                    <span>Source chatflow sub-task (optional)</span>
                    <select value=${String(d.promptSourceSubtaskId || '')}
                      onChange=${function (e) { set({ promptSourceSubtaskId: e.target.value }); }}>
                      <option value="">— none</option>
                      ${siblings.map(function (sb) {
                        return html`<option key=${sb.id} value=${sb.id}>${sb.title}</option>`;
                      })}
                    </select>
                  </label>
                  <label class="em-chat-agent-field">
                    <span>Built prompt</span>
                    <textarea rows="4" value=${d.builtPrompt}
                      placeholder="The composed prompt (the ✨ AI build runs in the desktop app; you can write or edit it here)"
                      onInput=${function (e) { set({ builtPrompt: e.target.value }); }}></textarea>
                  </label>
                `}

                ${d.task_type === 'agent' && html`
                  <label class="em-chat-agent-field">
                    <span>Agent (prompt sequence)</span>
                    <select value=${String(d.agentSequenceId || '')}
                      onChange=${function (e) { set({ agentSequenceId: e.target.value }); }}>
                      <option value="">— pick an agent</option>
                      ${groupSeqs.map(function (gs) {
                        return html`<option key=${String(gs.id)} value=${String(gs.id)}>${(gs.name || ('Sequence ' + gs.id)) + (gs.role ? ' — ' + gs.role : '')}</option>`;
                      })}
                    </select>
                  </label>
                  <label class="em-chat-agent-field">
                    <span>Prompt (optional — falls back to the task description)</span>
                    <textarea rows="3" value=${d.prompt}
                      onInput=${function (e) { set({ prompt: e.target.value }); }}></textarea>
                  </label>
                  <label class="em-chat-agent-field">
                    <span>AI integration / model</span>
                    <input type="text" list="em-ws-models" value=${d.aiIntegration}
                      placeholder="e.g. gemini-2.5-flash (or a desktop-local model)"
                      onInput=${function (e) { set({ aiIntegration: e.target.value }); }} />
                    <datalist id="em-ws-models">
                      ${(props.gendmeModels || []).map(function (m) { return html`<option key=${m} value=${m}></option>`; })}
                    </datalist>
                  </label>
                  <div class="em-chat-agent-field">
                    <span>Approval</span>
                    <${WsSegmented}
                      options=${[{ value: 'auto', label: '✓ Auto-approve' }, { value: 'member', label: '🙋 Member reviews & approves' }]}
                      value=${d.approvalMode} onChange=${function (v) { set({ approvalMode: v }); }} />
                  </div>
                  ${d.approvalMode === 'member' && html`
                    <label class="em-chat-agent-field">
                      <span>Approver</span>
                      ${members.length > 0 ? html`
                        <select value=${String(d.approverId || '')}
                          onChange=${function (e) { set({ approverId: e.target.value }); }}>
                          <option value="">— pick a member</option>
                          ${members.map(function (m) {
                            return html`<option key=${String(m.id)} value=${String(m.id)}>${m.name}</option>`;
                          })}
                        </select>`
                      : html`
                        <input type="text" value=${d.approverName} placeholder="Approver name"
                          onInput=${function (e) { set({ approverName: e.target.value }); }} />`}
                    </label>
                  `}
                `}

                ${d.task_type === 'people' && html`
                  <label class="em-chat-agent-field">
                    <span>${s.kind === 'approval' ? 'Assigned member (final approval)' : 'Assigned member'}</span>
                    ${members.length > 0 ? html`
                      <select value=${String(d.memberId || '')}
                        onChange=${function (e) { set({ memberId: e.target.value }); }}>
                        <option value="">— pick a member</option>
                        ${members.map(function (m) {
                          return html`<option key=${String(m.id)} value=${String(m.id)}>${m.name}</option>`;
                        })}
                      </select>`
                    : html`
                      <input type="text" value=${d.assignee} placeholder="Assignee name"
                        onInput=${function (e) { set({ assignee: e.target.value }); }} />`}
                  </label>
                `}

                ${d.task_type === 'update' && html`
                  <div class="em-chat-agent-field">
                    <span>Environment</span>
                    <${WsSegmented} options=${WS_ENV_OPTS} value=${d.updateEnvironment}
                      onChange=${function (v) { set({ updateEnvironment: v }); }} />
                  </div>
                  <div class="em-chat-agent-field">
                    <span>Target setting</span>
                    <div class="em-chat-seq-ws-note-inline">
                      ${s.updateTargetLabel
                        ? html`<strong>${s.updateTargetLabel}</strong>`
                        : 'No target picked yet.'}
                      ${' '}The exact page/tab/setting is picked in the desktop app (it browses the live settings registry).
                    </div>
                  </div>
                `}

                ${s.output && html`
                  <div class="em-chat-agent-field">
                    <span>Output</span>
                    <pre class="em-chat-seq-ws-output">${s.output}</pre>
                  </div>
                `}
              </div>
              <footer class="em-chat-agent-foot">
                <button type="button" class="em-chat-agent-cancel" onClick=${props.onCancel}>Cancel</button>
                <button type="button" class="em-chat-agent-submit" onClick=${save}>Save sub-task</button>
              </footer>
            </div>
          </div>`;
    }

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
        // controls always have arrays/strings to bind to. Steps synced from the
        // DESKTOP app carry runTarget/targetRef instead of deviceRef (the
        // `_psoo_sequences` normalizer strips deviceRef) — map those back so
        // opening a desktop-built sequence shows its real run target.
        function normStep(p, i) {
            p = p || {};
            var devRef = p.deviceRef || '';
            if (!devRef && (p.runTarget === 'device' || p.runTarget === 'device_or_gendme') && p.targetRef) {
                devRef = String(p.targetRef);
            }
            return {
                id: p.id || ('p' + (i + 1)),
                text: p.text || '',
                deviceRef: devRef,
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
        // Workspace-mode accordion: which TASK row is expanded to show its
        // step editor + sub-tasks (desktop-popup parity). Hook lives here
        // unconditionally (hooks can't be conditional on props.workspace).
        var expState = useState(null); var expandedTask = expState[0], setExpandedTask = expState[1];
        // Workspace-mode extras: the hub chatflow list (em/v1/chatflows —
        // the same REST bridge the desktop proxies through) for chatflow-
        // kind tasks/sub-tasks, and which sub-task's popup editor is open.
        var cfListState = useState(null); var cfList = cfListState[0], setCfList = cfListState[1];
        // ── Manual "Launch now" (workspace mode) ─────────────────────────
        // A bound chatflow trigger launches that chatflow for the member;
        // with no chatflow bound we ask for a kick-off prompt and queue a
        // sequence.launch op the desktop runs on its next sync.
        var wsLaunchNoteState = useState(''); var wsLaunchNote = wsLaunchNoteState[0], setWsLaunchNote = wsLaunchNoteState[1];
        var wsLaunchBusyState = useState(false); var wsLaunchBusy = wsLaunchBusyState[0], setWsLaunchBusy = wsLaunchBusyState[1];
        var wsLaunchAskState = useState(false); var wsLaunchAsk = wsLaunchAskState[0], setWsLaunchAsk = wsLaunchAskState[1];
        var newTrigCfState = useState(''); var newTrigCf = newTrigCfState[0], setNewTrigCf = newTrigCfState[1];
        var newTrigKindState = useState('chatflow'); var newTrigKind = newTrigKindState[0], setNewTrigKind = newTrigKindState[1];
        var cfTargetsState = useState({ chatflows: [], forms: [], lists: [] });
        var cfTargets = cfTargetsState[0], setCfTargets = cfTargetsState[1];
        // Monthly scheduled runs — hub-side registry, executed by the hub
        // cron as sequence.launch ops on the 1st of every month.
        var schedState = useState([]); var schedules = schedState[0], setSchedules = schedState[1];
        useEffect(function () {
            if (!props.workspace || !homeGroup) { return; }
            apiFetch({ url: wpJsonRoot + 'psoo-pm/v1/groups/' + encodeURIComponent(homeGroup) + '/sequence-workspaces/schedule', method: 'GET' })
                .then(function (d) { setSchedules((d && d.schedules) || []); })
                .catch(function () {});
        }, [!!props.workspace, homeGroup]);
        function saveSchedule() {
            if (!homeGroup || !props.workspace) { return; }
            apiFetch({ url: wpJsonRoot + 'psoo-pm/v1/groups/' + encodeURIComponent(homeGroup) + '/sequence-workspaces/schedule', method: 'POST',
                data: { op: 'save', workspaceId: String(props.workspace.id || ''), name: props.workspace.name || '' } })
                .then(function (d) { setSchedules((d && d.schedules) || []); })
                .catch(function () { window.alert('Could not save the monthly schedule.'); });
        }
        function deleteSchedule(s) {
            apiFetch({ url: wpJsonRoot + 'psoo-pm/v1/groups/' + encodeURIComponent(homeGroup) + '/sequence-workspaces/schedule', method: 'POST',
                data: { op: 'delete', schedule_id: s.id } })
                .then(function (d) { setSchedules((d && d.schedules) || []); })
                .catch(function () {});
        }
        function wsChatflowTrigger() {
            var ts = (props.workspace && props.workspace.triggers) || [];
            var isCf = function (t) { return t.chatflowId && (t.type === 'chatflow' || !t.type); };
            return ts.filter(function (t) { return t.enabled && isCf(t); })[0]
                || ts.filter(isCf)[0] || null;
        }
        function wsLaunchNow() {
            if (!props.workspace || wsLaunchBusy) { return; }
            var trg = wsChatflowTrigger();
            if (trg) {
                setWsLaunchBusy(true); setWsLaunchNote('');
                apiFetch({ url: emRoot + '/chatflows/launch' + (homeGroup ? ('?group_id=' + encodeURIComponent(homeGroup)) : ''), method: 'POST', data: { chatflow_id: trg.chatflowId, task_id: 'seq-' + String(props.workspace.id || '') } })
                    .then(function () {
                        setWsLaunchBusy(false);
                        setWsLaunchNote('💬 "' + (trg.chatflowTitle || trg.name || 'Chatflow') + '" launched — open the chat to run it; the sequence fires when it completes.');
                    })
                    .catch(function (e) { setWsLaunchBusy(false); setWsLaunchNote('Could not launch the chatflow: ' + cleanError(e)); });
            } else {
                setWsLaunchAsk(true);
            }
        }
        useEffect(function () {
            if (!props.workspace || cfList !== null) { return; }
            // Everything a start trigger can bind to on the LINKED APP:
            // chatflows, standalone forms, email lists (em/v1/launch-targets).
            apiFetch({ url: emRoot + '/launch-targets' + (homeGroup ? ('?group_id=' + encodeURIComponent(homeGroup)) : ''), method: 'GET' })
                .then(function (dd) {
                    setCfTargets({
                        chatflows: (dd && dd.chatflows) || [],
                        forms: (dd && dd.forms) || [],
                        lists: (dd && dd.lists) || [],
                    });
                    setCfList((dd && dd.chatflows) || []);
                })
                .catch(function () {
                    setCfTargets({ chatflows: [], forms: [], lists: [] });
                    setCfList([]);
                });
        }, [!!props.workspace]);
        var subEditState = useState(null); var subEdit = subEditState[0], setSubEdit = subEditState[1];

        // ALL the member's connected devices. Each step's "Prompt Runs At"
        // <select> lists every type — server, desktop, mobile — plus the
        // gend.me Compute Network (value "", the blockchain-metered hub) first.
        var allDevices = props.allDevices || [];
        var deviceById = props.deviceById || function () { return null; };
        var GENDME_MODELS = props.gendmeModels || ['gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'];

        // ── Group prompt-sequences (for context-file attach + launch_sequence
        // output). Fetched once the home group is known. ────────────────────
        var grpSeqState = useState([]); var groupSeqs = grpSeqState[0], setGroupSeqs = grpSeqState[1];
        // Brain "files" (foundations: branding slots, plan chapters, growth,
        // extras) — each individually attachable as step context.
        var brainFilesState = useState([]); var brainFiles = brainFilesState[0], setBrainFiles = brainFilesState[1];
        useEffect(function () {
            if (!homeGroup) { setBrainFiles([]); return; }
            apiFetch({ url: wpJsonRoot + 'psoo/v1/business-plan/brain-files?group_id=' + encodeURIComponent(homeGroup), method: 'GET' })
                .then(function (d) { setBrainFiles((d && d.files) || []); })
                .catch(function () { setBrainFiles([]); });
        }, [homeGroup]);
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
            if (step.deviceRef) { return 'Runs on your device — no metered compute cost'; }
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
            var integ = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations.slice() : [];
            // Desktop devices always offer the Claude terminal (the app's
            // local `claude` CLI on the member's personal subscription) —
            // even when the record predates the integration registering.
            if (dev && dev.type === 'desktop' && !integ.some(function (it) { return it && String(it.id) === 'claude-terminal'; })) {
                integ.push({ id: 'claude-terminal', displayName: 'Claude Terminal (personal account)', available: true, models: [] });
            }
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
                var integ = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations.slice() : [];
            // Desktop devices always offer the Claude terminal (the app's
            // local `claude` CLI on the member's personal subscription) —
            // even when the record predates the integration registering.
            if (dev && dev.type === 'desktop' && !integ.some(function (it) { return it && String(it.id) === 'claude-terminal'; })) {
                integ.push({ id: 'claude-terminal', displayName: 'Claude Terminal (personal account)', available: true, models: [] });
            }
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
        function addBrainFileContext(idx, cat) {
            if (!cat) { return; }
            var f = brainFiles.filter(function (x) { return String(x.category) === String(cat); })[0];
            var cur = ctxList(steps[idx]);
            if (cur.some(function (c) { return c && c.type === 'brain_file' && String(c.ref) === String(cat); })) { return; }
            addContext(idx, { type: 'brain_file', ref: String(cat), label: '🧠 ' + ((f && f.title) || cat) });
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

        var workspaceMode = !!props.workspace;

        // One step's full editor card. Used by BOTH layouts: the flat
        // steps list (agent-create flow, no workspace) and — in workspace
        // mode — embedded under the TASK row it belongs to, matching the
        // desktop popup where each task carries its step's details.
        function renderStepCard(s, idx) {
                    var dev = s.deviceRef ? deviceById(s.deviceRef) : null;
                    var integ = (dev && Array.isArray(dev.ai_integrations)) ? dev.ai_integrations.slice() : [];
            // Desktop devices always offer the Claude terminal (the app's
            // local `claude` CLI on the member's personal subscription) —
            // even when the record predates the integration registering.
            if (dev && dev.type === 'desktop' && !integ.some(function (it) { return it && String(it.id) === 'claude-terminal'; })) {
                integ.push({ id: 'claude-terminal', displayName: 'Claude Terminal (personal account)', available: true, models: [] });
            }
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
                            <select class="em-chat-seq-ctx-select" value=""
                              onChange=${function (e) { addBrainFileContext(idx, e.target.value); e.target.value = ''; }}>
                              <option value="">＋ Brain file…</option>
                              ${['branding', 'growth', 'plan', 'other'].map(function (grp) {
                                var items = brainFiles.filter(function (f) { return f.group === grp; });
                                if (!items.length) { return null; }
                                return html`<optgroup key=${grp} label=${grp.charAt(0).toUpperCase() + grp.slice(1)}>
                                  ${items.map(function (f) {
                                    return html`<option key=${f.category} value=${String(f.category)}>${(f.filled ? '' : '(empty) ') + f.title}</option>`;
                                  })}
                                </optgroup>`;
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
                        <!-- Every connected device type is selectable — server,
                             desktop, mobile — plus the gend.me Compute Network
                             (the blockchain-metered hub compute, settled in Leo
                             Tokens through the gas-station network). A device
                             that's currently offline stays selectable: the run
                             dispatch falls back to the network when the device
                             can't be reached (device_or_gendme semantics). -->
                        <div class="em-chat-agent-step-targets">
                          <label class="em-chat-agent-field">
                            <span>Prompt Runs At</span>
                            <select value=${s.deviceRef} onChange=${function (e) { onStepTarget(idx, e.target.value); }}>
                              <option value="">⛓️ gend.me Compute Network (blockchain)</option>
                              ${allDevices.map(function (dev) {
                                var did = dev && (dev.device_id != null ? dev.device_id : dev.id);
                                var type = (dev && dev.type) || '';
                                var icon = type === 'desktop' ? '🖥️' : (type === 'mobile' ? '📱' : (type === 'server' ? '🗄️' : '🔌'));
                                var typeLbl = type ? (type.charAt(0).toUpperCase() + type.slice(1)) : 'Device';
                                var off = dev && (dev.online === false || dev.available === false);
                                var lbl = (dev && dev.label) || String(did);
                                var who = (dev && dev.owner_name) ? ' · ' + dev.owner_name : '';
                                return html`<option key=${String(did)} value=${String(did)}>${icon + ' ' + lbl + ' — ' + typeLbl + who + (off ? ' (offline)' : '')}</option>`;
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
                  ${workspaceMode ? null : steps.map(function (s, idx) { return renderStepCard(s, idx); })}
                </div>
                ${!workspaceMode && html`<button type="button" class="em-chat-agent-addstep" onClick=${addStep}>＋ Add step</button>`}

                ${props.workspace && (function () {
                  // ── Workspace layout (desktop-popup parity) ──────────────
                  // TASK LIST is the primary surface (accordion): each task
                  // expands to its linked STEP editor + full task fields
                  // (description, kind, status, chatflow launch, build-prompt,
                  // revisions/approve) + its SUB-TASK list; sub-tasks open the
                  // full popup editor (WsSubtaskEditor). All task/sub-task/
                  // trigger edits queue ops the desktop applies to its local
                  // store; step edits save with the sequence definition.
                  var ws = props.workspace;
                  var sendOp = props.onWorkspaceOp || function () {};
                  var wsTriggers = ws.triggers || [];
                  var wsLists = (ws.taskLists && ws.taskLists.length) ? ws.taskLists : [{ id: 'default', name: 'Tasks' }];
                  var wsTasks = ws.tasks || [];
                  var KIND_LABEL = { sequence: '⚡ Sequence', chatflow: '💬 Launch chatflow', prompt: '✨ Build Prompt' };
                  var membersList = Object.keys(memCache).map(function (k) { return memCache[k]; });
                  var chatflows = cfList || [];

                  function stepIdxForTask(t) {
                    var ref = String(t.linkedPromptId || t.id || '');
                    for (var i = 0; i < steps.length; i++) {
                      if (String(steps[i].id) === ref) { return i; }
                    }
                    return -1;
                  }
                  var claimed = {};
                  wsTasks.forEach(function (t) { var i = stepIdxForTask(t); if (i >= 0) claimed[i] = true; });
                  var extraSteps = steps.map(function (s, i) { return claimed[i] ? null : i; })
                    .filter(function (i) { return i !== null; });

                  function patchTask(w, taskId, patch) {
                    return Object.assign({}, w, { tasks: (w.tasks || []).map(function (x) {
                      return x.id === taskId ? Object.assign({}, x, typeof patch === 'function' ? patch(x) : patch) : x;
                    }) });
                  }
                  function opTaskUpdate(t, fields) {
                    sendOp('task.update', Object.assign({ taskId: t.id }, fields), function (w) {
                      return patchTask(w, t.id, fields);
                    });
                  }
                  function toggleTrigger(t) {
                    sendOp('trigger.toggle', { triggerId: t.id, enabled: t.enabled ? '0' : '1' }, function (w) {
                      return Object.assign({}, w, { triggers: (w.triggers || []).map(function (x) {
                        return x.id === t.id ? Object.assign({}, x, { enabled: !t.enabled }) : x;
                      }) });
                    });
                  }
                  function toggleTask(t) {
                    var next = t.status === 'done' ? 'todo' : 'done';
                    sendOp('task.status', { taskId: t.id, status: next }, function (w) { return patchTask(w, t.id, { status: next }); });
                  }
                  function renameTask(t) {
                    var title = (window.prompt('Task title', t.title) || '').trim();
                    if (!title || title === t.title) { return; }
                    opTaskUpdate(t, { title: title });
                  }
                  function deleteTask(t) {
                    if (!window.confirm('Delete task "' + t.title + '"?')) { return; }
                    sendOp('task.delete', { taskId: t.id }, function (w) {
                      return Object.assign({}, w, { tasks: (w.tasks || []).filter(function (x) { return x.id !== t.id; }) });
                    });
                  }
                  function addTask(listId) {
                    var title = (window.prompt('New task title') || '').trim();
                    if (!title) { return; }
                    var tmp = { id: 'tmp_' + Date.now(), listId: listId, listKey: 'tasks', title: title, description: '', status: 'todo', taskKind: 'sequence', revisions: [], subtasks: [], subtasksTotal: 0, subtasksDone: 0 };
                    sendOp('task.add', { listId: listId, title: title }, function (w) {
                      return Object.assign({}, w, { tasks: (w.tasks || []).concat([tmp]) });
                    });
                  }
                  // ↑↓ within a list; persists the GLOBAL flat order the
                  // desktop reorderTasks expects (all lists, in list order).
                  function moveTask(t, dir) {
                    var byList = {};
                    wsLists.forEach(function (l) { byList[l.id] = wsTasks.filter(function (x) { return (x.listId || 'default') === l.id; }); });
                    var arr = byList[t.listId || 'default'] || [];
                    var i = arr.findIndex(function (x) { return x.id === t.id; });
                    var j = i + dir;
                    if (i < 0 || j < 0 || j >= arr.length) { return; }
                    var tmpA = arr[i]; arr[i] = arr[j]; arr[j] = tmpA;
                    var flat = [];
                    wsLists.forEach(function (l) { (byList[l.id] || []).forEach(function (x) { flat.push(x); }); });
                    sendOp('task.reorder', { orderedIds: flat.map(function (x) { return x.id; }).join(',') }, function (w) {
                      return Object.assign({}, w, { tasks: flat });
                    });
                  }
                  function toggleSubtask(t, st) {
                    var next = st.status === 'done' ? 'todo' : 'done';
                    sendOp('subtask.toggle', { taskId: t.id, subtaskId: st.id, status: next }, function (w) {
                      return patchTask(w, t.id, function (x) {
                        return { subtasks: (x.subtasks || []).map(function (sx) {
                          return sx.id === st.id ? Object.assign({}, sx, { status: next }) : sx;
                        }) };
                      });
                    });
                  }
                  function deleteSubtaskOf(t, st) {
                    if (!window.confirm('Delete sub-task "' + st.title + '"?')) { return; }
                    sendOp('subtask.delete', { taskId: t.id, subtaskId: st.id }, function (w) {
                      return patchTask(w, t.id, function (x) {
                        return { subtasks: (x.subtasks || []).filter(function (sx) { return sx.id !== st.id; }) };
                      });
                    });
                  }
                  function moveSubtask(t, st, dir) {
                    var arr = (t.subtasks || []).slice();
                    var i = arr.findIndex(function (x) { return x.id === st.id; });
                    var j = i + dir;
                    if (i < 0 || j < 0 || j >= arr.length) { return; }
                    var tmpA = arr[i]; arr[i] = arr[j]; arr[j] = tmpA;
                    sendOp('subtask.reorder', { taskId: t.id, orderedIds: arr.map(function (x) { return x.id; }).join(',') }, function (w) {
                      return patchTask(w, t.id, { subtasks: arr });
                    });
                  }
                  function saveSubtaskPatch(t, st, patch) {
                    sendOp('subtask.update', Object.assign({ taskId: t.id, subtaskId: st.id }, patch), function (w) {
                      return patchTask(w, t.id, function (x) {
                        return { subtasks: (x.subtasks || []).map(function (sx) {
                          return sx.id === st.id ? Object.assign({}, sx, patch) : sx;
                        }) };
                      });
                    });
                    setSubEdit(null);
                  }
                  function launchChatflow(t, subtaskOrNull) {
                    var cfId = subtaskOrNull ? subtaskOrNull.chatflowId : t.chatflowId;
                    if (!cfId) { return; }
                    var refId = subtaskOrNull ? subtaskOrNull.id : t.id;
                    apiFetch({ url: emRoot + '/chatflows/launch' + (homeGroup ? ('?group_id=' + encodeURIComponent(homeGroup)) : ''), method: 'POST', data: { chatflow_id: cfId, task_id: refId } })
                      .then(function (dd) {
                        var instId = (dd && (dd.id || (dd.data && dd.data.id))) || '';
                        var fields = { launchedChatflowInstanceId: String(instId), launchStatus: 'pending', launchedAt: new Date().toISOString() };
                        if (subtaskOrNull) {
                          saveSubtaskPatch(t, subtaskOrNull, fields);
                        } else {
                          opTaskUpdate(t, fields);
                        }
                      })
                      .catch(function () { window.alert('Could not launch the chatflow.'); });
                  }
                  function subtaskMeta(st) {
                    if (st.task_type === 'agent') {
                      var seq = groupSeqs.filter(function (gs) { return String(gs.id) === String(st.agentSequenceId); })[0];
                      return '🤖 ' + ((seq && (seq.name || seq.role)) || 'Agent')
                        + (st.aiIntegration ? ' · ' + st.aiIntegration : '')
                        + (st.kind === 'review' ? ' · review' : '')
                        + (st.approvalMode === 'member' ? ' · 🙋 ' + (st.approverName || 'member approves') : '');
                    }
                    if (st.task_type === 'update') {
                      return '🔄 ' + (st.updateEnvironment || 'live') + ' · ' + (st.updateTargetLabel || 'no target picked yet');
                    }
                    return '🧑 ' + (st.assignee || (st.kind === 'approval' ? 'Final approval — unassigned' : 'Person'));
                  }

                  // Inline sub-task composer state kept in the DOM via prompt()
                  // is too clumsy for type choice — small closure over a draft
                  // is enough here since add is one-shot.
                  function addSubtaskTo(t, taskType) {
                    var title = (window.prompt('New ' + (taskType === 'agent' ? 'agent' : 'people') + ' sub-task title') || '').trim();
                    if (!title) { return; }
                    var tmp = { id: 'tmp_' + Date.now(), title: title, status: 'todo', task_type: taskType, kind: 'task', subtasks: [] };
                    sendOp('subtask.add', { taskId: t.id, title: title, task_type: taskType }, function (w) {
                      return patchTask(w, t.id, function (x) { return { subtasks: (x.subtasks || []).concat([tmp]) }; });
                    });
                  }

                  function renderTaskDetail(t) {
                    var sIdx = stepIdxForTask(t);
                    var subs = t.subtasks || [];
                    var revs = t.revisions || [];
                    var activeRev = revs.filter(function (r) { return r.id === t.activeRevisionId; })[0] || revs[revs.length - 1] || null;
                    var siblingsCf = subs.filter(function (sx) { return sx.taskKind === 'chatflow'; });
                    return html`
                      <div class="em-chat-seq-ws-task-detail">
                        ${sIdx >= 0
                          ? renderStepCard(steps[sIdx], sIdx)
                          : html`<div class="em-chat-seq-ws-empty">No sequence step linked to this task — it's a plain workspace to-do.</div>`}

                        <div class="em-chat-agent-field">
                          <span>Description</span>
                          <${WsDraftText} value=${t.description} rows=${3}
                            placeholder="What this task is about — also the default prompt for agent sub-tasks"
                            onCommit=${function (v) { opTaskUpdate(t, { description: v }); }} />
                        </div>

                        <div class="em-chat-seq-ws-fieldrow">
                          <div class="em-chat-agent-field">
                            <span>Task type</span>
                            <${WsSegmented} options=${WS_KIND_OPTS} value=${t.taskKind || 'sequence'}
                              onChange=${function (v) { opTaskUpdate(t, { taskKind: v }); }} />
                          </div>
                          <label class="em-chat-agent-field em-chat-seq-ws-statusfield">
                            <span>Status</span>
                            <select value=${t.status || 'todo'}
                              onChange=${function (e) { sendOp('task.status', { taskId: t.id, status: e.target.value }, function (w) { return patchTask(w, t.id, { status: e.target.value }); }); }}>
                              <option value="todo">To do</option>
                              <option value="in-progress">In progress</option>
                              <option value="done">Done</option>
                            </select>
                          </label>
                        </div>

                        ${t.taskKind === 'chatflow' && html`
                          <div class="em-chat-agent-field">
                            <span>Chatflow</span>
                            <div class="em-chat-seq-ws-cfrow">
                              <select value=${String(t.chatflowId || '')}
                                onChange=${function (e) {
                                  var cf = chatflows.filter(function (c) { return String(c.id) === String(e.target.value); })[0];
                                  opTaskUpdate(t, { chatflowId: e.target.value, chatflowTitle: cf ? (cf.title || '') : '' });
                                }}>
                                <option value="">— pick a chatflow</option>
                                ${chatflows.map(function (c) {
                                  return html`<option key=${String(c.id)} value=${String(c.id)}>${c.title || ('Chatflow ' + c.id)}</option>`;
                                })}
                              </select>
                              <button type="button" class="em-chat-seq-ctx-btn"
                                disabled=${!t.chatflowId || t.launchStatus === 'pending'}
                                onClick=${function () { launchChatflow(t, null); }}>
                                ${t.launchStatus === 'pending' ? '⏳ Launched' : '🚀 Launch'}
                              </button>
                            </div>
                            ${t.launchStatus && html`<small class="em-chat-agent-help">${t.launchStatus === 'complete' ? '✓ Completed' : '⏳ Waiting for completion'}${t.launchedAt ? ' · ' + String(t.launchedAt).slice(0, 16).replace('T', ' ') : ''}</small>`}
                          </div>
                        `}

                        ${t.taskKind === 'prompt' && html`
                          <div class="em-chat-agent-field">
                            <span>Source chatflow sub-task</span>
                            <select value=${String(t.promptSourceSubtaskId || '')}
                              onChange=${function (e) { opTaskUpdate(t, { promptSourceSubtaskId: e.target.value }); }}>
                              <option value="">— none</option>
                              ${siblingsCf.map(function (sb) {
                                return html`<option key=${sb.id} value=${sb.id}>${sb.title}</option>`;
                              })}
                            </select>
                          </div>
                          <div class="em-chat-agent-field">
                            <span>Built prompt</span>
                            <${WsDraftText} value=${t.builtPrompt} rows=${4}
                              placeholder="The composed prompt (the ✨ AI build runs in the desktop app; edit here freely)"
                              onCommit=${function (v) { opTaskUpdate(t, { builtPrompt: v }); }} />
                          </div>
                        `}

                        ${revs.length > 0 && html`
                          <div class="em-chat-agent-field">
                            <span>Revisions — review & approve</span>
                            <div class="em-chat-seq-ws-revchips">
                              ${revs.map(function (r, ri) {
                                var isActive = activeRev && r.id === activeRev.id;
                                return html`
                                  <button type="button" key=${r.id}
                                    class=${'em-chat-seq-ws-revchip' + (isActive ? ' is-on' : '')}
                                    title=${(r.title || '') + (r.createdAt ? ' · ' + r.createdAt : '')}
                                    onClick=${function () {
                                      sendOp('task.set-active-revision', { taskId: t.id, revisionId: r.id }, function (w) {
                                        return patchTask(w, t.id, { activeRevisionId: r.id });
                                      });
                                    }}>
                                    ${(ri + 1) + '. ' + (r.title || 'Revision')}${r.source === 'claude' ? ' ✨' : ''}${r.attachmentsCount > 0 ? ' 📎' + r.attachmentsCount : ''}
                                  </button>`;
                              })}
                            </div>
                            ${activeRev && html`<pre class="em-chat-seq-ws-output em-chat-seq-ws-rev">${activeRev.content || '(empty revision)'}</pre>`}
                            <div class="em-chat-seq-ws-approverow">
                              <button type="button" class="em-chat-seq-ws-approve"
                                disabled=${t.status === 'done'}
                                onClick=${function () { sendOp('task.status', { taskId: t.id, status: 'done' }, function (w) { return patchTask(w, t.id, { status: 'done' }); }); }}>
                                ${t.status === 'done' ? '✓ Approved' : '✓ Approve'}
                              </button>
                              ${t.onApproveType && t.onApproveType !== 'none' && html`
                                <span class="em-chat-seq-ws-note-inline">On approve: ${t.onApproveType} (configured in the desktop app)</span>
                              `}
                            </div>
                          </div>
                        `}

                        ${!!t.mirrorHubProjectId && html`
                          <div class="em-chat-seq-ws-note-inline">Output mirrors to gend.me project #${t.mirrorHubProjectId}.</div>
                        `}

                        <div class="em-chat-seq-sub-head" style=${{ marginTop: '10px' }}>Sub-tasks</div>
                        ${subs.length === 0 && html`<div class="em-chat-seq-ws-empty">No sub-tasks yet.</div>`}
                        ${subs.map(function (st, si) {
                          return html`
                            <div class="em-chat-seq-ws-task em-chat-seq-ws-subtask" key=${st.id}>
                              <input type="checkbox" checked=${st.status === 'done'} onChange=${function () { toggleSubtask(t, st); }} />
                              <button type="button" class="em-chat-seq-ws-task-main"
                                title="Open the sub-task editor"
                                onClick=${function () { setSubEdit({ task: t, subtask: st }); }}>
                                <span class=${'em-chat-seq-ws-task-title' + (st.status === 'done' ? ' is-done' : '')}>${st.title}</span>
                                <span class="em-chat-seq-ws-task-sub">${subtaskMeta(st)}</span>
                                ${st.taskKind === 'chatflow' && st.launchStatus && html`<span class="em-chat-proj-card-cat">${st.launchStatus === 'complete' ? '✓' : '⏳'}</span>`}
                              </button>
                              ${st.taskKind === 'chatflow' && st.chatflowId && st.launchStatus !== 'pending' && html`
                                <button type="button" class="em-chat-seq-ws-task-x" title="Launch chatflow"
                                  onClick=${function () { launchChatflow(t, st); }}>🚀</button>
                              `}
                              <button type="button" class="em-chat-seq-ws-task-x" aria-label="Move up" disabled=${si === 0}
                                onClick=${function () { moveSubtask(t, st, -1); }}>↑</button>
                              <button type="button" class="em-chat-seq-ws-task-x" aria-label="Move down" disabled=${si === subs.length - 1}
                                onClick=${function () { moveSubtask(t, st, 1); }}>↓</button>
                              <button type="button" class="em-chat-seq-ws-task-x" aria-label="Delete sub-task"
                                onClick=${function () { deleteSubtaskOf(t, st); }}>×</button>
                            </div>`;
                        })}
                        <div class="em-chat-seq-ws-subadd">
                          <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { addSubtaskTo(t, 'people'); }}>＋ People sub-task</button>
                          <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { addSubtaskTo(t, 'agent'); }}>＋ Agent sub-task</button>
                        </div>
                        <div class="em-chat-seq-ws-note-inline">Agent runs, the ✨ AI prompt build, file attachments and schedules execute in the desktop app (or fire via launch triggers) — everything configured here syncs to it.</div>
                      </div>`;
                  }

                  function renderTaskRow(t, ti, listTasks) {
                    var open = expandedTask === t.id;
                    var sIdx = stepIdxForTask(t);
                    var subs = t.subtasks || [];
                    return html`
                      <div class=${'em-chat-seq-ws-taskwrap' + (open ? ' is-open' : '')} key=${t.id}>
                        <div class="em-chat-seq-ws-task">
                          <input type="checkbox" checked=${t.status === 'done'} onChange=${function () { toggleTask(t); }} />
                          <button type="button" class="em-chat-seq-ws-task-main"
                            aria-expanded=${open ? 'true' : 'false'}
                            onClick=${function () { setExpandedTask(open ? null : t.id); }}>
                            <span class=${'em-chat-seq-ws-task-title' + (t.status === 'done' ? ' is-done' : '')}>
                              ${(ti + 1) + '. ' + t.title}
                            </span>
                            ${t.taskKind && t.taskKind !== 'sequence' && html`<span class="em-chat-proj-card-cat">${KIND_LABEL[t.taskKind] || t.taskKind}</span>`}
                            ${t.taskKind === 'chatflow' && t.launchStatus && html`<span class="em-chat-proj-card-cat">${t.launchStatus === 'complete' ? '✓ complete' : '⏳ pending'}</span>`}
                            ${subs.length > 0 && html`<span class="em-chat-seq-ws-task-sub">☑ ${subs.filter(function (sx) { return sx.status === 'done'; }).length}/${subs.length}</span>`}
                            ${(t.revisions || []).length > 0 && html`<span class="em-chat-seq-ws-task-sub">${t.revisions.length} rev${t.revisions.length === 1 ? '' : 's'}</span>`}
                            ${sIdx >= 0 && html`<span class="em-chat-seq-ws-task-steplink">Step ${sIdx + 1}</span>`}
                            <span class="em-chat-seq-ws-task-caret" aria-hidden="true">${open ? '▾' : '▸'}</span>
                          </button>
                          <button type="button" class="em-chat-seq-ws-task-x" aria-label="Move up" disabled=${ti === 0}
                            onClick=${function () { moveTask(t, -1); }}>↑</button>
                          <button type="button" class="em-chat-seq-ws-task-x" aria-label="Move down" disabled=${ti === listTasks.length - 1}
                            onClick=${function () { moveTask(t, 1); }}>↓</button>
                          <button type="button" class="em-chat-seq-ws-task-x" aria-label="Rename task" title="Rename"
                            onClick=${function () { renameTask(t); }}>✎</button>
                          <button type="button" class="em-chat-seq-ws-task-x" aria-label="Delete task" title="Delete"
                            onClick=${function () { deleteTask(t); }}>×</button>
                        </div>
                        ${open && renderTaskDetail(t)}
                      </div>`;
                  }

                  return html`
                    <div class="em-chat-seq-ws">
                      <div class="em-chat-seq-ws-banner">Workspace — two-way synced with the desktop app (changes apply there within a minute)</div>

                      <div class="em-chat-seq-sub-head em-chat-seq-sub-head--row">
                        <span>Launch triggers</span>
                        <button type="button" class="em-chat-seq-launch-btn" disabled=${wsLaunchBusy}
                          title=${wsChatflowTrigger() ? 'Launches the connected chatflow' : 'Asks for a kick-off prompt and runs the sequence'}
                          onClick=${wsLaunchNow}>
                          ${wsLaunchBusy ? '⏳ Launching…' : '🚀 Launch now'}
                        </button>
                      </div>
                      ${wsLaunchNote && html`<div class="em-chat-seq-launch-note">${wsLaunchNote}</div>`}
                      ${wsTriggers.length === 0 && html`
                        <div class="em-chat-seq-ws-empty">No launch triggers yet — connect a chatflow below, or hit 🚀 Launch now to kick the sequence off from a prompt.</div>
                      `}
                      ${wsTriggers.map(function (t) {
                        return html`
                          <div class="em-chat-seq-ws-trigger" key=${t.id || t.name}>
                            <span class="em-chat-seq-ws-trigger-id">
                              <span class="em-chat-seq-ws-trigger-name">${t.name || 'Trigger'}</span>
                              <span class="em-chat-seq-ws-trigger-meta">
                                ${t.type === 'chatflow' ? ('💬 Chatflow' + (t.chatflowTitle ? ': ' + t.chatflowTitle : ''))
                                  : (t.type === 'list' ? ('📧 List joined' + (t.chatflowTitle ? ': ' + t.chatflowTitle : ''))
                                  : (t.type === 'form' ? ('📝 Form filled' + (t.chatflowTitle ? ': ' + t.chatflowTitle : ''))
                                  : '🔗 Manual code'))}
                                ${t.firedCount > 0 ? (' · fired ' + t.firedCount + '×') : ''}
                              </span>
                              ${t.code && html`<code class="em-chat-seq-ws-trigger-code" title="Launch code">${t.code}</code>`}
                            </span>
                            ${t.type === 'chatflow' && html`
                              <select class="em-chat-seq-ws-trigger-cf" value=${String(t.chatflowId || '')}
                                title="Connected chatflow — completing it fires this sequence"
                                onChange=${function (e) {
                                  var id = e.target.value;
                                  var cf = chatflows.filter(function (c) { return String(c.id) === String(id); })[0];
                                  var title = cf ? (cf.title || '') : '';
                                  sendOp('trigger.update', { triggerId: t.id, chatflowId: id, chatflowTitle: title }, function (w) {
                                    return Object.assign({}, w, { triggers: (w.triggers || []).map(function (x) {
                                      return x.id === t.id ? Object.assign({}, x, { chatflowId: id, chatflowTitle: title }) : x;
                                    }) });
                                  });
                                }}>
                                <option value="">— unbind chatflow</option>
                                ${chatflows.map(function (c) {
                                  return html`<option key=${c.id} value=${String(c.id)}>${c.title || ('Chatflow #' + c.id)}</option>`;
                                })}
                              </select>`}
                            <button type="button" class=${'em-chat-seq-ws-toggle' + (t.enabled ? ' is-on' : '')}
                              onClick=${function () { toggleTrigger(t); }}>
                              ${t.enabled ? 'Enabled' : 'Disabled'}
                            </button>
                          </div>`;
                      })}
                      ${(function () {
                        // ── Start-trigger composer: chatflow completed /
                        // form filled / email list joined / monthly schedule.
                        // Non-schedule kinds mint the launch code HERE and
                        // bind it on the linked app immediately (em/v1/
                        // launch-bind) so the trigger fires even before the
                        // desktop applies the trigger record.
                        function addTrigger() {
                          if (newTrigKind === 'schedule') { saveSchedule(); return; }
                          var pool = newTrigKind === 'list' ? cfTargets.lists : (newTrigKind === 'form' ? cfTargets.forms : cfTargets.chatflows);
                          var t = pool.filter(function (c) { return String(c.id) === String(newTrigCf); })[0];
                          if (!t) { return; }
                          var code = 'LW-' + Math.random().toString(36).slice(2, 10).toUpperCase() + Date.now().toString(36).toUpperCase();
                          var label = t.title || ('#' + t.id);
                          var KINDN = { chatflow: 'completion', form: 'submission', list: 'join' };
                          var tmp = { id: 'tmp_' + Date.now(), name: label + ' ' + KINDN[newTrigKind], type: newTrigKind, enabled: true, code: code,
                            chatflowId: newTrigKind === 'chatflow' ? String(t.id) : '', chatflowTitle: label, firedCount: 0 };
                          apiFetch({ url: emRoot + '/launch-bind' + (homeGroup ? ('?group_id=' + encodeURIComponent(homeGroup)) : ''), method: 'POST',
                            data: { kind: newTrigKind, target_id: t.id, code: code } }).catch(function () {});
                          sendOp('trigger.add', { kind: newTrigKind, code: code, targetId: String(t.id),
                            chatflowId: newTrigKind === 'chatflow' ? String(t.id) : '', chatflowTitle: label, name: tmp.name }, function (w) {
                            return Object.assign({}, w, { triggers: (w.triggers || []).concat([tmp]) });
                          });
                          setNewTrigCf('');
                        }
                        var pool = newTrigKind === 'list' ? cfTargets.lists : (newTrigKind === 'form' ? cfTargets.forms : cfTargets.chatflows);
                        var wsScheds = schedules.filter(function (s) { return String(s.workspaceId) === String(ws.id); });
                        return html`
                          <div class="em-chat-seq-ws-trigger em-chat-seq-ws-trigger--new">
                            <select class="em-chat-seq-ws-trigger-cf" style=${{ flex: '0 0 auto', maxWidth: '200px' }} value=${newTrigKind}
                              onChange=${function (e) { setNewTrigKind(e.target.value); setNewTrigCf(''); }}>
                              <option value="chatflow">💬 Chatflow completed</option>
                              <option value="form">📝 Form filled</option>
                              <option value="list">📧 Joined email list</option>
                              <option value="schedule">📅 Monthly — 1st</option>
                            </select>
                            ${newTrigKind !== 'schedule' && html`
                              <select class="em-chat-seq-ws-trigger-cf" value=${newTrigCf}
                                onChange=${function (e) { setNewTrigCf(e.target.value); }}>
                                <option value="">${newTrigKind === 'list' ? '— pick an email list…' : (newTrigKind === 'form' ? '— pick a form…' : '— pick a chatflow…')}</option>
                                ${pool.map(function (c) {
                                  return html`<option key=${c.id} value=${String(c.id)}>${c.title || ('#' + c.id)}</option>`;
                                })}
                              </select>`}
                            <button type="button" class="em-chat-seq-ctx-btn" disabled=${newTrigKind !== 'schedule' && !newTrigCf}
                              onClick=${addTrigger}>＋ Add trigger</button>
                          </div>
                          ${wsScheds.length > 0 && html`<div class="em-chat-seq-sub-head" style=${{ marginTop: '10px' }}>Scheduled runs</div>`}
                          ${wsScheds.map(function (s) {
                            return html`
                              <div class="em-chat-seq-ws-trigger" key=${s.id}>
                                <span class="em-chat-seq-ws-trigger-id">
                                  <span class="em-chat-seq-ws-trigger-name">📅 Monthly run</span>
                                  <span class="em-chat-seq-ws-trigger-meta">1st of every month${s.lastRunYm ? ' · last ran ' + s.lastRunYm.slice(0, 4) + '-' + s.lastRunYm.slice(4) : ' · first run next 1st'}</span>
                                </span>
                                <button type="button" class="em-chat-seq-ws-toggle" onClick=${function () { deleteSchedule(s); }}>Remove</button>
                              </div>`;
                          })}`;
                      })()}
                      ${wsLaunchAsk && html`<${EmPromptModal}
                        title="Kick off sequence"
                        label="Kick-off prompt"
                        placeholder="What should this run start from?"
                        onCancel=${function () { setWsLaunchAsk(false); }}
                        onSubmit=${function (v) {
                          sendOp('sequence.launch', { prompt: v }, function (w) { return w; });
                          setWsLaunchAsk(false);
                          setWsLaunchNote('🚀 Kick-off queued — the desktop app runs the sequence on its next sync (within ~a minute while online).');
                        }}
                      />`}

                      <div class="em-chat-seq-sub-head" style=${{ marginTop: '14px' }}>Task lists</div>
                      ${wsLists.map(function (l) {
                        var listTasks = wsTasks.filter(function (t) { return (t.listId || 'default') === l.id; });
                        var doneN = listTasks.filter(function (t) { return t.status === 'done'; }).length;
                        return html`
                          <div class="em-chat-seq-ws-list" key=${l.id}>
                            <div class="em-chat-seq-ws-list-head">
                              <span class="em-chat-seq-ws-list-name">${l.name}</span>
                              <span class="em-chat-seq-ws-list-count">${doneN}/${listTasks.length}</span>
                              <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { addTask(l.id); }}>＋ Add task</button>
                            </div>
                            ${listTasks.length === 0 && html`<div class="em-chat-seq-ws-empty">No tasks in this list.</div>`}
                            ${listTasks.map(function (t, ti) { return renderTaskRow(t, ti, listTasks); })}
                          </div>`;
                      })}

                      ${extraSteps.length > 0 && html`
                        <div class="em-chat-seq-sub-head" style=${{ marginTop: '14px' }}>Additional steps (no task yet)</div>
                        <div class="em-chat-agent-steps">
                          ${extraSteps.map(function (i) { return renderStepCard(steps[i], i); })}
                        </div>
                      `}
                      <button type="button" class="em-chat-agent-addstep" onClick=${addStep}>＋ Add step</button>

                      ${subEdit && html`
                        <${WsSubtaskEditor}
                          subtask=${(function () {
                            // Re-derive the FRESH sub-task from the live rows so
                            // the popup shows optimistic patches too.
                            var t = wsTasks.filter(function (x) { return x.id === subEdit.task.id; })[0] || subEdit.task;
                            return (t.subtasks || []).filter(function (sx) { return sx.id === subEdit.subtask.id; })[0] || subEdit.subtask;
                          })()}
                          members=${membersList}
                          chatflows=${chatflows}
                          groupSeqs=${groupSeqs}
                          gendmeModels=${GENDME_MODELS}
                          siblingChatflows=${(function () {
                            var t = wsTasks.filter(function (x) { return x.id === subEdit.task.id; })[0] || subEdit.task;
                            return (t.subtasks || []).filter(function (sx) { return sx.taskKind === 'chatflow' && sx.id !== subEdit.subtask.id; });
                          })()}
                          onSave=${function (patch) { saveSubtaskPatch(subEdit.task, subEdit.subtask, patch); }}
                          onCancel=${function () { setSubEdit(null); }} />
                      `}
                    </div>`;
                })()}
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

    // PM "chatflow" task-type (intake / prompt-building / review chatflows,
    // all launched the same way) — the Agents tab's "Chatflows" sub-view.
    // Polls the current user's launch registry and renders it filtered by
    // complete/incomplete, mirroring the "open chat" thread list's shape so
    // it sits naturally alongside it under the same Agents tab.
    function ChatflowsList(props) {
        // The linked web app's Talk Flows catalog (em/v1/chatflows?group_id —
        // the same list as its wp-admin Chatflows → Flows tab), merged with
        // the member's launched-instance statuses so each flow shows its
        // latest run state and can be launched right here.
        var gid = (props && Number(props.ctxGid)) || 0;
        var st = useState({ loading: true, flows: [], launched: [], err: null });
        var state = st[0], setState = st[1];

        function reload() {
            var q = gid ? ('?group_id=' + encodeURIComponent(gid)) : '';
            Promise.all([
                apiFetch({ url: emRoot + '/chatflows' + q, method: 'GET' }).catch(function () { return []; }),
                apiFetch({ url: emRoot + '/chatflows/launched', method: 'GET' }).catch(function () { return { launched: [] }; })
            ]).then(function (rs) {
                var flows = Array.isArray(rs[0]) ? rs[0] : ((rs[0] && rs[0].items) || []);
                var launched = (rs[1] && rs[1].launched) || [];
                setState({ loading: false, flows: flows, launched: launched, err: null });
            }).catch(function () { setState({ loading: false, flows: [], launched: [], err: true }); });
        }
        useEffect(function () {
            setState({ loading: true, flows: [], launched: [], err: null });
            reload();
            var h = setInterval(reload, 20000);
            return function () { clearInterval(h); };
        }, [gid]);

        function latestFor(flowId) {
            var hits = (state.launched || []).filter(function (it) { return String(it.chatflow_id) === String(flowId); });
            return hits.length ? hits[hits.length - 1] : null;
        }
        function launchFlow(f) {
            apiFetch({ url: emRoot + '/chatflows/launch' + (gid ? ('?group_id=' + encodeURIComponent(gid)) : ''), method: 'POST', data: { chatflow_id: f.id, task_id: 'widget' } })
                .then(reload)
                .catch(function () { window.alert('Could not launch the chatflow — it may not have a Launch Trigger linked yet.'); });
        }

        var filter = props.filter || 'all';
        var rows = (state.flows || []).filter(function (f) {
            var inst = latestFor(f.id);
            if (filter === 'complete') return !!inst && inst.status === 'complete';
            if (filter === 'incomplete') return !!inst && inst.status !== 'complete';
            return true;
        });

        return html`
          <ul class="em-chat-panel-threads em-chat-chatflow-list" role="list">
            ${state.loading && html`<li class="em-chat-panel-empty">Loading chatflows…</li>`}
            ${state.err && html`<li class="em-chat-panel-empty em-chat-err">Could not load chatflows.</li>`}
            ${!state.loading && !state.err && rows.length === 0 && html`
              <li class="em-chat-panel-empty">${filter === 'all'
                ? 'No chatflows on the linked web app yet — build one under its wp-admin → Talk Flows → Chatflows.'
                : (filter === 'complete' ? 'No completed chatflow runs yet.' : 'No pending chatflow runs.')}</li>
            `}
            ${rows.map(function (f, i) {
              var inst = latestFor(f.id);
              return html`
                <li key=${f.id} style=${{ '--em-i': i }}>
                  <div class="em-chat-row em-chat-row--chatflow">
                    <span class=${'em-chat-launched-status ' + (inst ? (inst.status === 'complete' ? 'is-complete' : 'is-pending') : 'is-idle')}>
                      ${inst ? (inst.status === 'complete' ? '✓' : '⏳') : '💬'}
                    </span>
                    <span class="em-chat-row-id">
                      <span class="em-chat-row-name">${f.title || 'Chatflow'}</span>
                      <span class="em-chat-row-sub">${(Number(f.question_count) || 0) + ' question' + (Number(f.question_count) === 1 ? '' : 's')
                        + (f.has_ai_prompt ? ' · AI prompt' : '')
                        + (inst ? (' · ' + (inst.status === 'complete' ? 'last run complete' : 'run pending')) : '')}</span>
                    </span>
                    <span class="em-chat-row-meta">
                      <button type="button" class="em-chat-seq-ctx-btn" onClick=${function () { launchFlow(f); }}>▶ Launch</button>
                    </span>
                  </div>
                </li>
              `;
            })}
          </ul>
        `;
    }

    function SwitcherPanel(props) {
        var st = useState({ loading: true, items: [], err: null });
        var state = st[0], setState = st[1];
        var qState = useState(''); var q = qState[0], setQ = qState[1];
        var searchState = useState({ loading: false, items: [] });
        var search = searchState[0], setSearch = searchState[1];
        var tabState = useState('projects'); var tab = tabState[0], setTab = tabState[1];
        var agentAddState = useState(false); var agentAdd = agentAddState[0], setAgentAdd = agentAddState[1];
        var inviteOpenState = useState(false); var inviteOpen = inviteOpenState[0], setInviteOpen = inviteOpenState[1];
        // Agents tab sub-view: real DM "chat" vs launched "chatflows"
        // (intake / prompt-building / review — all tracked the same way),
        // the latter further filterable by complete/incomplete.
        // Default = 'chat' (rendered as "Organization" — the agent directory).
        var agentViewState = useState('chat'); var agentView = agentViewState[0], setAgentView = agentViewState[1];
        // Pending sequence-studio deep-link (window.__emSeqPending): route to
        // Agents → Sequences on mount, or when the nav event fires while the
        // panel is already open. Stale pendings (>30s) are ignored.
        useEffect(function () {
            function route() {
                var pnd = window.__emSeqPending;
                if (!pnd || (pnd.ts && Date.now() - pnd.ts > 30000)) { return; }
                // The caller knows which group it's on — force the widget's
                // group context to it, else the Sequences pane loads whatever
                // group the session last used (or none) and the editor deep-
                // link can never find its workspace.
                if (pnd.groupId) {
                    setCtxGid(Number(pnd.groupId) || 0);
                    try { sessionStorage.setItem('emGroupCtx.' + cfg.currentUserId, String(Number(pnd.groupId) || 0)); } catch (err) {}
                }
                setTab('agents');
                setAgentView('sequences');
            }
            route();
            window.addEventListener('em-seq:nav', route);
            return function () { window.removeEventListener('em-seq:nav', route); };
        }, []);
        var chatflowFilterState = useState('incomplete'); var chatflowFilter = chatflowFilterState[0], setChatflowFilter = chatflowFilterState[1];
        // Messages tab sub-view: Email (inbox suite) / Members (real DM
        // threads — the old top-level tab). Defaults to Email when the
        // member has inbox access, else Members.
        var msgViewState = useState(cfg.hasInbox ? 'email' : 'members'); var msgView = msgViewState[0], setMsgView = msgViewState[1];

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

        // Global group context (top selector bar) — remembered per session.
        var ctxState = useState((function () {
            try { return Number(sessionStorage.getItem('emGroupCtx.' + cfg.currentUserId)) || 0; } catch (e) { return 0; }
        })());
        var ctxGid = ctxState[0], setCtxGid = ctxState[1];

        // The Members pane owns its own compose-search + invite bar now.
        var msgSearchActive = false;

        return html`
          <div class=${'em-chat-panel ' + (props.isMobile ? 'is-mobile' : 'is-desktop')
              /* One consistent panel size across EVERY tab — Messages →
                 Members used to fall back to the original 360px panel,
                 so switching sub-views visibly resized the widget. */
              + ' em-chat-panel--email'
              + (props.seqGhost ? ' em-chat-panel--ghost' : '')}>
            <header class="em-chat-panel-header em-chat-panel-header--groupbar">
              <span class="em-chat-panel-header-spacer" aria-hidden="true"></span>
              <${GroupContextBar} gid=${ctxGid} onPick=${setCtxGid} />
              <button type="button" class="em-chat-panel-close" onClick=${props.onClose} aria-label="Close chat">×</button>
            </header>
            <nav class="em-chat-tabs" role="tablist" aria-label="Conversation type">
              ${[['projects', 'Projects'], ['agents', 'Agents'], ['messages', 'Messages']].map(function (p) {
                // Real unread counts only exist for member DM threads today —
                // the Messages tab's dot mirrors those (its Members sub-tab).
                var countTab = p[0] === 'messages' ? 'members' : p[0];
                var c = state.items.filter(function (t) { return emThreadMatchesTab(t, countTab); })
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
            ${tab === 'messages' && html`
              <div class="em-chat-msg-switch" role="tablist" aria-label="Email or member messages">
                ${[]
                    .concat(cfg.hasInbox ? [['email', '✉️', 'Email', 'Your connected inboxes']] : [])
                    .concat([['members', '💬', 'Members', 'Direct messages']])
                    .map(function (v) {
                  return html`
                    <button type="button" role="tab" key=${v[0]} aria-selected=${msgView === v[0] ? 'true' : 'false'}
                      class=${'em-chat-msg-switch-btn' + (msgView === v[0] ? ' is-active' : '')}
                      onClick=${function () { setMsgView(v[0]); }}>
                      <span class="em-chat-msg-switch-ico" aria-hidden="true">${v[1]}</span>
                      <span class="em-chat-msg-switch-txt">
                        <span class="em-chat-msg-switch-name">${v[2]}</span>
                        <span class="em-chat-msg-switch-sub">${v[3]}</span>
                      </span>
                    </button>
                  `;
                })}
              </div>
            `}
            ${msgSearchActive && html`
              <div class="em-chat-invite-bar">
                <button type="button" class="em-chat-proj-newbtn" onClick=${function () { setInviteOpen(true); }}>＋ Invite new</button>
              </div>
              <div class="em-chat-panel-search">
                <input
                  type="search"
                  placeholder="Search members…"
                  value=${q}
                  onChange=${function (e) { setQ(e.target.value); }} />
              </div>
            `}
            ${msgSearchActive && q && html`
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
            ${(!msgSearchActive || !q) && html`
              ${tab === 'agents' && html`
                <${EmViewSelect}
                  ariaLabel="Agents section"
                  options=${[['chat', '🏢', 'Organization'], ['sequences', '⚡', 'Sequences'], ['chatflows', '🌊', 'Chatflows']]}
                  value=${agentView}
                  onChange=${setAgentView} />
                ${agentView === 'chatflows' && html`
                  <div class="em-chat-agent-subfilter em-chat-agent-subfilter--pill" role="tablist" aria-label="Chatflow status">
                    ${[['incomplete', 'Incomplete'], ['complete', 'Complete'], ['all', 'All']].map(function (f) {
                      return html`
                        <button type="button" role="tab" key=${f[0]} aria-selected=${chatflowFilter === f[0] ? 'true' : 'false'}
                          class=${'em-chat-subtab em-chat-subtab--pill ' + (chatflowFilter === f[0] ? 'is-active' : '')}
                          onClick=${function () { setChatflowFilter(f[0]); }}>${f[1]}</button>
                      `;
                    })}
                  </div>
                `}
              `}
              ${tab === 'messages' && msgView === 'email' && html`<${EmailInboxPane} />`}
              ${tab === 'projects' && html`<${ProjectsPane} onClosePanel=${props.onClose} ctxGid=${ctxGid} />`}
              ${tab === 'agents' && agentView === 'sequences' && html`<${AgentSequencesPane} onClosePanel=${props.onClose} ctxGid=${ctxGid} />`}
              ${(function () {
                // Shared threads list (member DMs / agent conversations).
                if (tab === 'messages' && msgView === 'members') {
                  return html`<${MembersMessagesPane} onOpenThread=${props.onOpenThread} onInvite=${function () { setInviteOpen(true); }} />`;
                }
                if (!(tab === 'agents' && agentView === 'chat')) { return null; }
                var threadsList = html`
                <ul class=${'em-chat-panel-threads' + (tab === 'agents' ? ' em-chat-panel-threads--capped' : '')} role="list">
                  ${state.loading && html`<li class="em-chat-panel-empty">Loading chats…</li>`}
                  ${state.err && html`<li class="em-chat-panel-empty em-chat-err">${state.err}</li>`}
                  ${(function () {
                    var listTab = tab === 'messages' ? 'members' : tab;
                    var shown = state.items.filter(function (t) { return emThreadMatchesTab(t, listTab); });
                    if (!state.loading && !state.err && shown.length === 0) {
                      var msg = listTab === 'agents'
                        ? 'No agent conversations yet.'
                        : 'No conversations yet. Search above to start one.';
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
                `;
                // Agents → Organization: ONE scroll for the whole tab —
                // conversations + the org sections travel together instead
                // of stacking two independent scrollbars.
                if (tab === 'agents') {
                  return html`
                    <div class="em-chat-agents-scroll">
                      ${threadsList}
                      <${AgentRosterPane} onOpenThread=${props.onOpenThread} ctxGid=${ctxGid} onAddAgent=${function () { setAgentAdd(true); }} />
                    </div>`;
                }
                return threadsList;
              })()}
              ${tab === 'agents' && agentView === 'chatflows' && html`<${ChatflowsList} filter=${chatflowFilter} ctxGid=${ctxGid} />`}
            `}
            ${inviteOpen && html`<${InviteNewModal} onClose=${function () { setInviteOpen(false); }} />`}
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
        // Smoothness: a typing indicator while the other side (an agent / a chatflow's next question) is replying,
        // the user's own message shown instantly (optimistic), and entrance animation only for the first load --
        // new messages never wait behind a per-index delay. initialIdsRef = ids present at first load.
        var typingState = useState(false); var typing = typingState[0], setTyping = typingState[1];
        var initialIdsRef = useRef(null);
        var replyPollRef = useRef(null);
        function otherCount(list) { return (list || []).filter(function (m) { return !m.is_self; }).length; }

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
                if (initialIdsRef.current === null) {
                    initialIdsRef.current = {};
                    (d.messages || []).forEach(function (m) { initialIdsRef.current[m.id] = true; });
                }
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

        // Keep the newest message (or the typing dots) in view: instant on first load, smooth afterwards.
        // The thread keeps growing after that first paint -- avatars, emoji images and fonts arrive late, by a few
        // hundred pixels on a long thread -- which left the newest message cut off until the next refresh jolted
        // the box down. So the box stays pinned to the bottom for as long as the READER has not scrolled away:
        // `pinnedRef` only changes on the reader's own input (wheel / touch / scrollbar / keys), never because the
        // content grew, and every late arrival re-pins.
        var firstScrollRef = useRef(true);
        var pinnedRef = useRef(true);
        var pinTimersRef = useRef([]);
        // The box has `scroll-behavior: smooth` in CSS (for new messages), so a plain scrollTop assignment would
        // ANIMATE -- on opening a thread that showed it sliding down from the top, and every re-pin restarted the
        // slide. Pins that must not be seen are done instantly.
        function jumpToEnd(b) {
            if (typeof b.scrollTo === 'function') { try { b.scrollTo({ top: b.scrollHeight, behavior: 'instant' }); return; } catch (e) {} }
            b.scrollTop = b.scrollHeight;
        }
        function repin() {
            var b = scrollRef.current;
            if (b && pinnedRef.current && b.scrollHeight - b.scrollTop - b.clientHeight > 1) jumpToEnd(b);
        }
        function bindBody(n) {
            scrollRef.current = n;
            if (!n || n.__emPinBound) return;
            n.__emPinBound = true;
            var userMoved = function () {
                setTimeout(function () { pinnedRef.current = n.scrollHeight - n.scrollTop - n.clientHeight < 48; }, 90);
            };
            ['wheel', 'touchmove', 'pointerup', 'keyup'].forEach(function (ev) { n.addEventListener(ev, userMoved, { passive: true }); });
            n.addEventListener('load', repin, true); // images inside (load does not bubble, so capture it)
            if (document.fonts && document.fonts.ready && document.fonts.ready.then) document.fonts.ready.then(repin);
        }
        function settle(delays) {
            pinTimersRef.current.forEach(clearTimeout);
            pinTimersRef.current = delays.map(function (ms) { return setTimeout(repin, ms); });
        }
        useEffect(function () {
            var n = scrollRef.current;
            if (!n) return;
            pinnedRef.current = true; // a new message (or the typing dots) always brings the box to the bottom
            if (firstScrollRef.current) {
                jumpToEnd(n);
                if (state.messages.length) { firstScrollRef.current = false; settle([120, 400, 1000, 2500]); }
                return;
            }
            if (typeof n.scrollTo === 'function') n.scrollTo({ top: n.scrollHeight, behavior: 'smooth' });
            else n.scrollTop = n.scrollHeight;
            settle([900, 1600]); // once the smooth scroll has arrived (a re-pin is a no-op when already there)
        }, [state.messages.length, typing]);
        useEffect(function () { return function () { clearTimeout(replyPollRef.current); pinTimersRef.current.forEach(clearTimeout); }; }, []);

        // After sending: show the typing dots and check quickly for the reply (a chatflow's next question is usually
        // already there; an agent's answer can take a few seconds), up to ~45s -- then the normal 15s refresh resumes.
        function awaitReply(othersBefore) {
            clearTimeout(replyPollRef.current);
            setTyping(true);
            var started = Date.now();
            function tick() {
                restGet('threads/' + props.thread.id).then(function (d) {
                    var msgs = d.messages || [];
                    var arrived = otherCount(msgs) > othersBefore;
                    var minShown = Date.now() - started > 700; // let the dots read as "typing", not a flicker
                    if (arrived && minShown) {
                        setState(function (prev) { return { loading: false, thread: d.thread || prev.thread, messages: msgs, err: null }; });
                        setTyping(false);
                        restPost('threads/' + props.thread.id + '/read', {}).catch(function () {});
                        return;
                    }
                    if (!arrived) {
                        // Keep our own (now saved) message in sync without dropping the optimistic one early.
                        setState(function (prev) { return msgs.length >= prev.messages.length ? { loading: false, thread: d.thread || prev.thread, messages: msgs, err: null } : prev; });
                    }
                    if (Date.now() - started > 45000) { setTyping(false); return; }
                    replyPollRef.current = setTimeout(tick, arrived ? 350 : 1200);
                }).catch(function () {
                    if (Date.now() - started > 45000) { setTyping(false); return; }
                    replyPollRef.current = setTimeout(tick, 2000);
                });
            }
            replyPollRef.current = setTimeout(tick, 600);
        }

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
                // Optimistic: the message shows immediately (escaped), replaced by the saved copy on the next fetch.
                var safe = content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                var others = otherCount(state.messages);
                setState(function (prev) {
                    return { loading: false, thread: prev.thread, err: null, messages: prev.messages.concat([{ id: 'tmp-' + Date.now(), is_self: true, message: safe, date_sent: new Date().toISOString(), sender_avatar: '', _pending: true }]) };
                });
                setDraft('');
                restPost('threads/' + state.thread.id + '/send', { content: content })
                    .then(function () {
                        setSending(false);
                        awaitReply(others);
                    })
                    .catch(function () {
                        setSending(false);
                        setTyping(false);
                        setState(function (prev) { return { loading: false, thread: prev.thread, err: 'Message not sent — try again.', messages: prev.messages.filter(function (m) { return !m._pending; }) }; });
                        setDraft(content);
                    });
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
              <div class="em-chat-box-body" ref=${bindBody}>
                ${state.loading && html`<div class="em-chat-panel-empty">Loading…</div>`}
                ${state.err && html`<div class="em-chat-panel-empty em-chat-err">${state.err}</div>`}
                ${!state.loading && state.messages.length === 0 && html`
                  <div class="em-chat-panel-empty">No messages yet. Say hello.</div>
                `}
                ${state.messages.map(function (m, i) {
                  // Stagger only the first load's last few bubbles; anything newer animates in right away.
                  var initial = initialIdsRef.current && initialIdsRef.current[m.id];
                  var delayIndex = initial ? Math.max(0, i - (state.messages.length - 8)) : 0;
                  return html`
                    <div key=${m.id} class=${'em-chat-bubble ' + (m.is_self ? 'is-self' : 'is-other') + (initial ? '' : ' is-new') + (m._pending ? ' is-pending' : '')} style=${{ '--em-i': delayIndex }}>
                      ${!m.is_self && html`<img class="em-chat-bubble-avatar" src=${m.sender_avatar} alt="" />`}
                      <span class="em-chat-bubble-body">
                        <span class="em-chat-bubble-text" dangerouslySetInnerHTML=${{ __html: m.message }}></span>
                        <span class="em-chat-bubble-time">${m._pending ? 'Sending…' : formatTime(m.date_sent)}</span>
                      </span>
                    </div>
                  `;
                })}
                ${typing && html`
                  <div class="em-chat-bubble is-other is-new em-chat-typing" aria-live="polite" aria-label=${other.display_name + ' is typing'}>
                    <img class="em-chat-bubble-avatar" src=${other.avatar_url || ''} alt="" />
                    <span class="em-chat-bubble-body"><span class="em-chat-typing-dots"><i></i><i></i><i></i></span></span>
                  </div>
                `}
              </div>
              <form class="em-chat-box-compose" onSubmit=${function (e) { e.preventDefault(); send(); }}>
                <input
                  type="text"
                  placeholder=${'Message ' + other.display_name + '…'}
                  value=${draft}
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
    // Open the widget's sequence studio from anywhere on the page:
    // emSeqStudioOpen({ action: 'new' }) or
    // emSeqStudioOpen({ action: 'edit', workspaceId: '<id>' }).
    window.emSeqStudioOpen = function (detail) {
        var d = Object.assign({ ts: Date.now() }, detail || {});
        window.__emSeqPending = d;
        window.dispatchEvent(new CustomEvent('em-seq:nav', { detail: d }));
    };
})();
