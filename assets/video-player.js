/**
 * Video Player Widget (slice 3b).
 *
 * Intercepts clicks on any video-shaped URL anywhere on the site and
 * routes them into a glass mini-player at the bottom-right that can
 * expand to a full-screen modal.
 *
 * Supported sources:
 *   - Direct files: .mp4, .webm, .mov, .m4v, .ogg, .ogv
 *   - YouTube: youtube.com/watch?v=, youtu.be/, /embed/
 *   - Vimeo: vimeo.com/{id}, player.vimeo.com/video/{id}
 *
 * Direct files render in a styled <video> element with custom controls.
 * YouTube/Vimeo embeds use their iframe APIs (with autoplay query
 * params) — the host iframe is themed by us; native controls inside.
 *
 * Programmatic open:
 *   window.emVideoOpen('https://youtu.be/dQw4w9WgXcQ');
 *   window.emVideoOpen({ src: '/foo.mp4', title: 'Demo', poster: '/thumb.jpg' });
 */
(function () {
    if (!window.wp || !wp.element) return;
    var useState  = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef    = wp.element.useRef || function (init) { return { current: init }; };

    // ── htm vendored copy (shared with chat widget) ──────────────────
    var html = window.htm
        ? htm.bind(wp.element.createElement)
        : null;
    if (! html) {
        var n=function(t,s,r,e){var u;s[0]=0;for(var h=1;h<s.length;h++){var p=s[h++],a=s[h]?(s[0]|=p?1:2,r[s[h++]]):s[++h];3===p?e[0]=a:4===p?e[1]=Object.assign(e[1]||{},a):5===p?(e[1]=e[1]||{})[s[++h]]=a:6===p?e[1][s[++h]]+=a+"":p?(u=t.apply(a,n(t,a,r,["",null])),e.push(u),a[0]?s[0]|=2:(s[h-2]=0,s[h]=u)):e.push(a)}return e},t=new Map;function e(s){var r=t.get(this);return r||(r=new Map,t.set(this,r)),(r=n(this,r.get(s)||(r.set(s,r=function(n){for(var t,s,r=1,e="",u="",h=[0],p=function(n){1===r&&(n||(e=e.replace(/^\s*\n\s*|\s*\n\s*$/g,"")))?h.push(0,n,e):3===r&&(n||e)?(h.push(3,n,e),r=2):2===r&&"..."===e&&n?h.push(4,n,0):2===r&&e&&!n?h.push(5,0,!0,e):r>=5&&((e||!n&&5===r)&&(h.push(r,0,e,s),r=6),n&&(h.push(r,n,0,s),r=6)),e=""},a=0;a<n.length;a++){a&&(1===r&&p(),p(a));for(var l=0;l<n[a].length;l++)t=n[a][l],1===r?"<"===t?(p(),h=[h],r=3):e+=t:4===r?"--"===e&&">"===t?(r=1,e=""):e=t+e[0]:u?t===u?u="":e+=t:'"'===t||"'"===t?u=t:">"===t?(p(),r=1):r&&("="===t?(r=5,s=e,e=""):"/"===t&&(r<5||">"===n[a][l+1])?(p(),3===r&&(h=h[0]),r=h,(h=h[0]).push(2,0,r),r=0):" "===t||"\t"===t||"\n"===t||"\r"===t?(p(),r=2):e+=t),3===r&&"!--"===e&&(r=4,h=h[0])}return p(),h}(s)),r),arguments,[])).length>1?r:r[0]}
        window.htm=e;
        html = e.bind(wp.element.createElement);
    }

    /* ── URL detection ──────────────────────────────────────────────── */
    function detectVideo(rawHref) {
        if (! rawHref) return null;
        var u;
        try { u = new URL(rawHref, location.href); }
        catch (e) { return null; }
        var host = u.hostname.toLowerCase();
        var path = u.pathname;

        // Direct video file.
        if (/\.(mp4|webm|mov|m4v|ogg|ogv|m3u8)(\?|#|$)/i.test(path)) {
            return {
                kind:  'direct',
                src:   u.href,
                title: decodeURIComponent(path.split('/').pop()),
            };
        }

        // YouTube — youtube.com/watch?v=, youtu.be/, /embed/, /shorts/
        if (host === 'youtu.be' || /(^|\.)youtube\.com$/.test(host) || /(^|\.)youtube-nocookie\.com$/.test(host)) {
            var ytId = '';
            if (host === 'youtu.be') {
                ytId = path.replace(/^\//, '').split('/')[0];
            } else if (u.searchParams.get('v')) {
                ytId = u.searchParams.get('v');
            } else {
                var em = path.match(/\/(?:embed|shorts|live|v)\/([^\/?]+)/);
                if (em) ytId = em[1];
            }
            if (ytId) {
                var t = u.searchParams.get('t') || u.searchParams.get('start');
                return {
                    kind:    'youtube',
                    src:     'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(ytId)
                             + '?autoplay=1&rel=0&modestbranding=1&playsinline=1'
                             + (t ? '&start=' + parseInt(t, 10) : ''),
                    title:   'YouTube · ' + ytId,
                    embedded: true,
                };
            }
        }

        // Vimeo — vimeo.com/{id}, player.vimeo.com/video/{id}
        if (/(^|\.)vimeo\.com$/.test(host)) {
            var vmId = '';
            var vp = path.match(/\/(?:video\/)?(\d+)/);
            if (vp) vmId = vp[1];
            if (vmId) {
                return {
                    kind:    'vimeo',
                    src:     'https://player.vimeo.com/video/' + vmId + '?autoplay=1&dnt=1',
                    title:   'Vimeo · ' + vmId,
                    embedded: true,
                };
            }
        }
        return null;
    }

    /* ── React widget ──────────────────────────────────────────────── */
    function VideoWidget() {
        var videoState   = useState(null);     // current { kind, src, title, ... }
        var fullState    = useState(false);    // mini vs full
        var visibleState = useState(false);    // open / closed
        var video    = videoState[0],    setVideo    = videoState[1];
        var full     = fullState[0],     setFull     = fullState[1];
        var visible  = visibleState[0],  setVisible  = visibleState[1];

        // Listen for site-wide open events.
        useEffect(function () {
            function onOpen(e) {
                var detail = e.detail || {};
                var info;
                if (typeof detail === 'string') info = detectVideo(detail);
                else if (detail.src && ! detail.kind) info = detectVideo(detail.src) || { kind: 'direct', src: detail.src, title: detail.title || '' };
                else info = detail;
                if (! info) return;
                if (detail && detail.title) info.title = detail.title;
                if (detail && detail.poster) info.poster = detail.poster;
                setVideo(info);
                setFull(!!detail.full);
                setVisible(true);
            }
            window.addEventListener('em-video:open', onOpen);
            return function () { window.removeEventListener('em-video:open', onOpen); };
        }, []);

        // Esc closes full-screen mode (back to mini) on full, or
        // closes outright when in mini.
        useEffect(function () {
            function onKey(e) {
                if (e.key !== 'Escape') return;
                if (! visible) return;
                if (full) setFull(false);
                else      setVisible(false);
            }
            document.addEventListener('keydown', onKey);
            return function () { document.removeEventListener('keydown', onKey); };
        }, [visible, full]);

        if (! visible || ! video) return null;
        return html`
          <div class=${'em-video-widget ' + (full ? 'is-full' : 'is-mini')}>
            ${full && html`<div class="em-video-backdrop" onClick=${function () { setFull(false); }}></div>`}
            <div class="em-video-frame">
              <div class="em-video-stage">
                ${video.kind === 'direct'
                  ? html`<video
                          src=${video.src}
                          poster=${video.poster || ''}
                          controls
                          autoplay
                          playsinline
                          preload="metadata"></video>`
                  : html`<iframe
                          src=${video.src}
                          title=${video.title || 'Video'}
                          frameborder="0"
                          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                          allowfullscreen></iframe>`}
              </div>
              <div class="em-video-bar">
                <span class="em-video-title">${video.title || 'Video'}</span>
                <span class="em-video-actions">
                  <button type="button" class="em-video-btn" aria-label=${full ? 'Collapse' : 'Expand'}
                    onClick=${function () { setFull(!full); }}>
                    <span aria-hidden="true">${full ? '⤓' : '⤢'}</span>
                  </button>
                  <button type="button" class="em-video-btn em-video-btn--close" aria-label="Close video"
                    onClick=${function () { setVisible(false); }}>
                    <span aria-hidden="true">×</span>
                  </button>
                </span>
              </div>
            </div>
          </div>
        `;
    }

    function mount() {
        var root = document.getElementById('em-video-player-root');
        if (! root) return;
        var tree = html`<${VideoWidget} />`;
        if (wp.element.createRoot) {
            try { wp.element.createRoot(root).render(tree); return; }
            catch (e) {}
        }
        if (typeof wp.element.render === 'function') wp.element.render(tree, root);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }

    /* ── Click interceptor ──────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented) return;
        if (e.button !== 0) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (! a) return;
        if (a.target === '_blank') return;
        if (a.hasAttribute('data-em-no-video')) return;
        var info = detectVideo(a.getAttribute('href') || '');
        if (! info) return;
        e.preventDefault();
        window.dispatchEvent(new CustomEvent('em-video:open', { detail: info }));
    }, true);

    /* ── Programmatic open helper ───────────────────────────────────── */
    window.emVideoOpen = function (urlOrObj) {
        window.dispatchEvent(new CustomEvent('em-video:open', { detail: urlOrObj }));
    };
})();
