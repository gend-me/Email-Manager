/**
 * Direct Messages Panel (slice 3c).
 *
 * Unified UI for cross-platform direct messages — Instagram, X, FB
 * Messenger, LinkedIn, TikTok. Each provider plugs in via the PHP
 * provider registry (inc/inbox-dm.php). Unconnected providers show
 * a Connect card; connected ones contribute threads to a merged feed.
 */
(function () {
    if (!window.wp || !wp.element) return;
    if (!window.EM_DM_CONFIG) return;
    var cfg = window.EM_DM_CONFIG;

    var useState  = wp.element.useState;
    var useEffect = wp.element.useEffect;

    var html = window.htm ? htm.bind(wp.element.createElement) : null;
    if (! html) {
        var n=function(t,s,r,e){var u;s[0]=0;for(var h=1;h<s.length;h++){var p=s[h++],a=s[h]?(s[0]|=p?1:2,r[s[h++]]):s[++h];3===p?e[0]=a:4===p?e[1]=Object.assign(e[1]||{},a):5===p?(e[1]=e[1]||{})[s[++h]]=a:6===p?e[1][s[++h]]+=a+"":p?(u=t.apply(a,n(t,a,r,["",null])),e.push(u),a[0]?s[0]|=2:(s[h-2]=0,s[h]=u)):e.push(a)}return e},t=new Map;function e(s){var r=t.get(this);return r||(r=new Map,t.set(this,r)),(r=n(this,r.get(s)||(r.set(s,r=function(n){for(var t,s,r=1,e="",u="",h=[0],p=function(n){1===r&&(n||(e=e.replace(/^\s*\n\s*|\s*\n\s*$/g,"")))?h.push(0,n,e):3===r&&(n||e)?(h.push(3,n,e),r=2):2===r&&"..."===e&&n?h.push(4,n,0):2===r&&e&&!n?h.push(5,0,!0,e):r>=5&&((e||!n&&5===r)&&(h.push(r,0,e,s),r=6),n&&(h.push(r,n,0,s),r=6)),e=""},a=0;a<n.length;a++){a&&(1===r&&p(),p(a));for(var l=0;l<n[a].length;l++)t=n[a][l],1===r?"<"===t?(p(),h=[h],r=3):e+=t:4===r?"--"===e&&">"===t?(r=1,e=""):e=t+e[0]:u?t===u?u="":e+=t:'"'===t||"'"===t?u=t:">"===t?(p(),r=1):r&&("="===t?(r=5,s=e,e=""):"/"===t&&(r<5||">"===n[a][l+1])?(p(),3===r&&(h=h[0]),r=h,(h=h[0]).push(2,0,r),r=0):" "===t||"\t"===t||"\n"===t||"\r"===t?(p(),r=2):e+=t),3===r&&"!--"===e&&(r=4,h=h[0])}return p(),h}(s)),r),arguments,[])).length>1?r:r[0]}
        window.htm=e;
        html = e.bind(wp.element.createElement);
    }

    var apiFetch = wp.apiFetch || function (opts) {
        return fetch(opts.url, {
            method: opts.method || 'GET',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: opts.data ? JSON.stringify(opts.data) : undefined,
        }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    };
    function restGet(path)        { return apiFetch({ url: cfg.restRoot + path, method: 'GET' }); }
    function restPost(path, data) { return apiFetch({ url: cfg.restRoot + path, method: 'POST', data: data || {} }); }

    // Centered digital orbital loader (slice 3d). Same visual language as
    // inbox-app's Spinner; styles live in dm-panel.css under .em-dm scope.
    function Spinner(props) {
        var label = (props && props.label) || 'Loading';
        var chars = String(label).split('');
        return html`
          <div class="em-loader-stage" role="status" aria-live="polite" aria-busy="true">
            <div class="em-loader-stack">
              <div class="em-loader-grid" aria-hidden="true"></div>
              <div class="em-loader" aria-hidden="true">
                <div class="em-loader-core"></div>
                <span class="em-loader-tick"></span>
                <span class="em-loader-tick"></span>
                <span class="em-loader-tick"></span>
              </div>
              <div class="em-loader-label" aria-hidden="true">
                ${chars.map(function (c, i) { return html`<span key=${i}>${c === ' ' ? ' ' : c}</span>`; })}
              </div>
            </div>
          </div>
        `;
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

    /* ── Panel ─────────────────────────────────────────────────────── */
    function DMPanel() {
        var st = useState({ loading: true, providers: [], threads: [], err: null });
        var state = st[0], setState = st[1];
        var openState = useState(null); // currently open thread key
        var openKey = openState[0], setOpenKey = openState[1];

        function reload() {
            setState({ loading: true, providers: state.providers, threads: state.threads, err: null });
            restGet('threads').then(function (d) {
                setState({ loading: false, providers: d.providers || [], threads: d.threads || [], err: null });
            }).catch(function (e) {
                setState({ loading: false, providers: [], threads: [], err: e.message || 'Failed to load DMs' });
            });
        }
        useEffect(reload, []);

        var anyConnected = state.providers.some(function (p) { return p.connected; });
        var openThread = state.threads.find(function (t) { return t.key === openKey; });

        return html`
          <div class="em-dm">
            <header class="em-dm-header">
              <h2 class="em-dm-title">Direct Messages</h2>
              <p class="em-dm-subtitle">Reply to every connected platform from one panel.</p>
            </header>

            <section class="em-dm-providers">
              ${state.providers.map(function (p, i) {
                return html`
                  <div key=${p.slug} class=${'em-dm-provider ' + (p.connected ? 'is-connected' : 'is-disconnected')} style=${{ '--em-i': i, '--prov-color': p.color }}>
                    <span class="em-dm-provider-icon" style=${{ background: p.color }}>${p.icon}</span>
                    <span class="em-dm-provider-name">${p.name}</span>
                    ${p.connected
                      ? html`<span class="em-dm-provider-status">Connected</span>`
                      : html`<a class="em-dm-provider-connect" href=${p.connect_url || '#'}>Connect →</a>`}
                  </div>
                `;
              })}
            </section>

            ${openThread
              ? html`<${DMThread} thread=${openThread} onBack=${function () { setOpenKey(null); reload(); }} />`
              : html`
                <section class="em-dm-feed">
                  <h3 class="em-dm-feed-title">Conversations</h3>
                  ${state.loading && html`<${Spinner} label="Syncing DMs" />`}
                  ${state.err && html`<p class="em-dm-empty em-dm-err">${state.err}</p>`}
                  ${! state.loading && ! state.err && ! anyConnected && html`
                    <p class="em-dm-empty">
                      Connect at least one platform above to start seeing direct messages here.
                    </p>
                  `}
                  ${! state.loading && ! state.err && anyConnected && state.threads.length === 0 && html`
                    <p class="em-dm-empty">No conversations yet from your connected platforms.</p>
                  `}
                  <ul class="em-dm-thread-list" role="list">
                    ${state.threads.map(function (t, i) {
                      return html`
                        <li key=${t.key} style=${{ '--em-i': i }}>
                          <button type="button" class="em-dm-thread-row" onClick=${function () { setOpenKey(t.key); }}>
                            <span class="em-dm-thread-platform" style=${{ background: t.provider_color }} title=${t.provider_name}>${t.provider_icon}</span>
                            <img class="em-dm-thread-avatar" src=${t.other_avatar || ''} alt="" />
                            <span class="em-dm-thread-id">
                              <span class="em-dm-thread-name">${t.other_name || '(unknown)'}</span>
                              <span class="em-dm-thread-sub">${t.last_excerpt || ''}</span>
                            </span>
                            <span class="em-dm-thread-meta">
                              <span class="em-dm-thread-time">${formatTime(t.last_at)}</span>
                              ${t.unread > 0 && html`<span class="em-dm-thread-unread">${t.unread}</span>`}
                            </span>
                          </button>
                        </li>
                      `;
                    })}
                  </ul>
                </section>
              `}
          </div>
        `;
    }

    function DMThread(props) {
        var st = useState({ loading: true, thread: props.thread, messages: [], err: null });
        var state = st[0], setState = st[1];
        var draftState = useState(''); var draft = draftState[0], setDraft = draftState[1];
        var sendingState = useState(false); var sending = sendingState[0], setSending = sendingState[1];

        function load() {
            setState({ loading: true, thread: props.thread, messages: state.messages, err: null });
            restGet('threads/' + props.thread.key).then(function (d) {
                setState({ loading: false, thread: d.thread || props.thread, messages: d.messages || [], err: null });
            }).catch(function (e) {
                setState({ loading: false, thread: props.thread, messages: [], err: e.message || 'Failed to load' });
            });
        }
        useEffect(load, [props.thread.key]);

        function send() {
            var content = draft.trim();
            if (! content) return;
            setSending(true);
            restPost('threads/' + state.thread.key + '/send', { content: content })
                .then(function () { setDraft(''); setSending(false); load(); })
                .catch(function () { setSending(false); });
        }

        return html`
          <section class="em-dm-thread">
            <header class="em-dm-thread-header">
              <button type="button" class="em-dm-back" onClick=${props.onBack}>← Back</button>
              <span class="em-dm-thread-platform" style=${{ background: state.thread.provider_color }}>${state.thread.provider_icon}</span>
              <span class="em-dm-thread-id">
                <span class="em-dm-thread-name">${state.thread.other_name || '(unknown)'}</span>
                <span class="em-dm-thread-sub">${state.thread.provider_name}</span>
              </span>
            </header>
            <div class="em-dm-thread-body">
              ${state.loading && html`<${Spinner} label="Loading thread" />`}
              ${state.err && html`<p class="em-dm-empty em-dm-err">${state.err}</p>`}
              ${! state.loading && ! state.err && state.messages.length === 0 && html`<p class="em-dm-empty">No messages in this conversation yet.</p>`}
              ${state.messages.map(function (m, i) {
                return html`
                  <div key=${m.id} class=${'em-dm-bubble ' + (m.is_self ? 'is-self' : 'is-other')} style=${{ '--em-i': i }}>
                    <span class="em-dm-bubble-body">
                      <span class="em-dm-bubble-text">${m.content || ''}</span>
                      <span class="em-dm-bubble-time">${formatTime(m.sent_at || m.date_sent)}</span>
                    </span>
                  </div>
                `;
              })}
            </div>
            <form class="em-dm-compose" onSubmit=${function (e) { e.preventDefault(); send(); }}>
              <input
                type="text"
                placeholder=${'Reply on ' + state.thread.provider_name + '…'}
                value=${draft}
                disabled=${sending}
                onChange=${function (e) { setDraft(e.target.value); }} />
              <button type="submit" disabled=${sending || !draft.trim()}>${sending ? '…' : 'Send'}</button>
            </form>
          </section>
        `;
    }

    function mount() {
        var root = document.getElementById('em-dm-root');
        if (! root) return;
        root.removeAttribute('data-loading');
        root.textContent = '';
        var tree = html`<${DMPanel} />`;
        if (wp.element.createRoot) {
            try { wp.element.createRoot(root).render(tree); return; }
            catch (e) {}
        }
        if (typeof wp.element.render === 'function') wp.element.render(tree, root);
    }
    window.emDMMount = mount;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
})();
