/**
 * gend-launch-open-widget.js
 *
 * After a [gend_launch] link fires a sequence launch (Chat_Forms_Launch::
 * handle_launch_click redirects here with ?gend_launched=1), reveal the
 * site's AI chat widget as the visible confirmation instead of a bare
 * "Launched!" page.
 *
 * The widget is always the <aipa-widget> custom element — owned directly by
 * the LEO plugin on the hub, or mirrored cross-origin by gend-society's
 * GS_AI_Widget on subsites (LEO itself is never installed there; see
 * gend-society/inc/ai-widget.php). Either way the element + its JS load
 * asynchronously, so this polls briefly rather than assuming it's already
 * upgraded on load. If the visitor isn't eligible for the widget (not
 * logged in / not gend.me-connected), this quietly gives up — the sequence
 * itself already fired server-side regardless.
 *
 * The "float above everything + re-greet" sequence mirrors the proven
 * pattern in gend-society/assets/gs-template-modal.js (openLeoWidget()) —
 * kept feature-detected here since the widget's own JS lives outside this
 * plugin and can change independently.
 */
(function () {
    'use strict';

    var params = new URLSearchParams(window.location.search);
    if (!params.has('gend_launched')) return;

    // Clean the query flag so a refresh doesn't re-trigger the reveal.
    params.delete('gend_launched');
    var qs = params.toString();
    var cleanUrl = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
    if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, cleanUrl);
    }

    var attempts = 0;
    var maxAttempts = 20; // ~5s at 250ms — long enough for the async widget script to land
    var timer = setInterval(function () {
        attempts++;
        var leo = document.querySelector('aipa-widget');
        if (leo) {
            clearInterval(timer);
            try {
                leo.style.display = '';
                leo.style.zIndex = '2147483647';
                if (typeof leo.resetLeoState === 'function') leo.resetLeoState();
                if (leo.leoState && typeof leo.showWelcomeOptions === 'function') {
                    leo.leoState.hasGreeted = false;
                    leo.showWelcomeOptions();
                }
            } catch (e) {
                // Widget internals can change independently of this plugin —
                // degrade silently; the launch itself already fired.
                if (window.console && console.warn) {
                    console.warn('[GenD Launch] could not auto-open the AI widget:', e);
                }
            }
        } else if (attempts >= maxAttempts) {
            clearInterval(timer);
        }
    }, 250);
})();
