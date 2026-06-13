<?php
/**
 * Member Inbox: BuddyPress "Email" subnav + Email/Chat tab strip
 * inside /members/<user>/messages/ (slice 2ww).
 *
 * Adds an "Email" subnav under the BP Messages component for users
 * who can access an inbox (owner, grantee, admin, super-admin). When
 * the user is on /messages/email/ we render the React inbox SPA;
 * when they're on the standard /messages/inbox/ (Chat) we keep BP's
 * normal templates. A tab strip injected at the top of EITHER screen
 * lets them switch back and forth.
 *
 * Permission function:
 *   em_inbox_user_has_inbox_access(int $user_id): bool
 *
 * @package EmailManager
 * @since   1.5.0
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Permission helper
 * ------------------------------------------------------------------------- */

/**
 * Does the user have any kind of access to an inbox?
 *   - admin (manage_options)            → YES
 *   - super-admin (multisite)            → YES
 *   - has em_inbox_address user_meta     → YES (they own one)
 *   - has any wp_gdc_inbox_grants row    → YES (delegated to them)
 *   - filter em_inbox_has_access lets    → YES (extension hook)
 *     a 3rd-party plugin force-enable
 */
function em_inbox_user_has_inbox_access($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;

    if (user_can($user_id, 'manage_options')) return true;
    if (is_multisite() && function_exists('is_super_admin') && is_super_admin($user_id)) return true;

    if (get_user_meta($user_id, 'em_inbox_address', true)) return true;

    global $wpdb;
    $tbl = $wpdb->prefix . 'gdc_inbox_grants';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl)) === $tbl) {
        $has = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tbl WHERE grantee_user_id = %d
              AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
            $user_id
        ));
        if ($has > 0) return true;
    }

    return (bool) apply_filters('em_inbox_has_access', false, $user_id);
}

/* -------------------------------------------------------------------------
 * Register the Email subnav under BP Messages
 * ------------------------------------------------------------------------- */

add_action('bp_setup_nav', 'em_inbox_add_bp_messages_email_subnav', 110);

function em_inbox_add_bp_messages_email_subnav() {
    if (! function_exists('bp_core_new_subnav_item') || ! function_exists('bp_get_messages_slug')) return;
    if (! function_exists('bp_loggedin_user_id') || ! function_exists('bp_displayed_user_domain')) return;

    $displayed_uid = bp_displayed_user_id();
    if ($displayed_uid <= 0) return;

    // Only render on the displayed user's OWN profile, and only when
    // THAT user can see inboxes.
    $own_profile = function_exists('bp_is_my_profile') ? bp_is_my_profile() : ($displayed_uid === get_current_user_id());
    if (! $own_profile) return;
    if (! em_inbox_user_has_inbox_access($displayed_uid)) return;

    $messages_slug = bp_get_messages_slug();
    $parent_url    = trailingslashit(bp_displayed_user_domain() . $messages_slug);

    bp_core_new_subnav_item(array(
        'name'             => __('Email', 'email-manager'),
        'slug'             => 'email',
        'parent_url'       => $parent_url,
        'parent_slug'      => $messages_slug,
        'screen_function'  => 'em_inbox_bp_messages_email_screen',
        'position'         => 5,  // before Inbox (10)
        'user_has_access'  => true,
        'show_in_admin_bar'=> false,
    ));
    // Slice 2zz.7: Email is registered as a subnav so /messages/email/
    // routes correctly, but it should NOT appear in the BP subnav
    // strip — it lives exclusively in our top-level Email/Chat tab
    // strip. CSS in inbox-bp-tabs.css hides the rendered <li>; we
    // also tag it with a data-em-subnav attribute via the nav-item
    // filter below for a reliable selector against Youzify markup.
}

function em_inbox_bp_messages_email_screen() {
    add_action('bp_template_title',   'em_inbox_bp_messages_email_title');
    add_action('bp_template_content', 'em_inbox_bp_messages_email_content');
    bp_core_load_template(array('members/single/plugins'));
}

function em_inbox_bp_messages_email_title() {
    echo esc_html__('Email', 'email-manager');
}

function em_inbox_bp_messages_email_content() {
    // Render the React SPA mount only. The Email/Chat tab strip is
    // injected at the top of the page via wp_footer + JS so it appears
    // above the BP subnav on every messages screen (chat side too).
    echo '<div class="em-inbox-wrap em-inbox-wrap--frontend"><div id="em-inbox-root" data-loading="1">'
       . esc_html__('Loading inbox…', 'email-manager')
       . '</div></div>';
}

/* -------------------------------------------------------------------------
 * Email/Chat tab strip — rendered at wp_footer and relocated client-
 * side to the TOP of the messages container, ABOVE the BP subnav. On
 * the Email screen the BP subnav is also CSS-hidden so the user only
 * sees the top-level Email tab; when they switch to Chat the BP
 * subnav (Inbox/Starred/Sent/Compose/Notices) reappears as Chat's
 * sub-tabs.
 * ------------------------------------------------------------------------- */

add_action('wp_footer', 'em_inbox_bp_messages_tab_strip_footer', 5);

function em_inbox_bp_messages_tab_strip_footer() {
    if (! function_exists('bp_is_messages_component') || ! bp_is_messages_component()) return;
    if (! function_exists('bp_displayed_user_id')) return;
    if (! em_inbox_user_has_inbox_access(bp_displayed_user_id())) return;
    if (! function_exists('bp_displayed_user_domain') || ! function_exists('bp_get_messages_slug')) return;

    $messages_url = trailingslashit(bp_displayed_user_domain() . bp_get_messages_slug());
    $email_url    = $messages_url . 'email/';
    $chat_url     = $messages_url;
    $active       = (function_exists('bp_current_action') && bp_current_action() === 'email') ? 'email' : 'chat';

    $email_active = $active === 'email' ? ' is-active' : '';
    $chat_active  = $active === 'chat'  ? ' is-active' : '';
    $body_class   = $active === 'email' ? 'em-inbox-bp-mode-email' : 'em-inbox-bp-mode-chat';
    ?>
    <template id="em-inbox-messages-tabs-template">
      <nav class="em-inbox-messages-tabs" aria-label="<?php esc_attr_e('Messages section', 'email-manager'); ?>">
        <a class="em-inbox-messages-tab<?php echo $email_active; ?>" href="<?php echo esc_url($email_url); ?>" aria-current="<?php echo $email_active ? 'page' : 'false'; ?>">
          <span class="em-inbox-messages-tab-icon" aria-hidden="true">📧</span> <?php esc_html_e('Email', 'email-manager'); ?>
        </a>
        <a class="em-inbox-messages-tab<?php echo $chat_active; ?>" href="<?php echo esc_url($chat_url); ?>" aria-current="<?php echo $chat_active ? 'page' : 'false'; ?>">
          <span class="em-inbox-messages-tab-icon" aria-hidden="true">💬</span> <?php esc_html_e('Chat', 'email-manager'); ?>
        </a>
      </nav>
    </template>
    <script>
    (function () {
        var BODY_CLASS = '<?php echo esc_js($body_class); ?>';
        // Find the BP messages subnav and insert our tab strip JUST BEFORE
        // it so Email/Chat sits on TOP. Falls back to a messages-content
        // container if no subnav found. MutationObserver covers Youzify
        // themes that render the subnav after this script.
        var SUBNAV_SELECTORS = [
            '.item-list-tabs#subnav',
            '#subnav.item-list-tabs',
            '.bp-subnavs',
            'div#subnav',
            '.youzify-bp-message-nav',
            '.youzify-bp-message-options',
            '.youzify-bp-content-nav',
            '.youzify-bp-content .item-list-tabs',
            '.youzify-bp-nav.item-list-tabs',
            'ul#subnav',
            '.subnav.item-list-tabs'
        ];
        var FALLBACK_SELECTORS = [
            '.youzify-bp-messages',
            '.youzify-bp-content',
            '.bp-messages',
            '#buddypress',
            '.youzify-main-column'
        ];
        function findSubnav() {
            for (var i = 0; i < SUBNAV_SELECTORS.length; i++) {
                var el = document.querySelector(SUBNAV_SELECTORS[i]);
                if (el) return el;
            }
            return null;
        }
        function inject() {
            document.body.classList.add(BODY_CLASS);
            var tpl = document.getElementById('em-inbox-messages-tabs-template');
            if (! tpl || tpl.dataset.emInjected) return true;
            var subnav = findSubnav();
            var frag, anchor, where;
            if (subnav && subnav.parentNode) {
                anchor = subnav;
                where = 'before';
            } else {
                for (var j = 0; j < FALLBACK_SELECTORS.length; j++) {
                    anchor = document.querySelector(FALLBACK_SELECTORS[j]);
                    if (anchor) break;
                }
                where = 'prepend';
            }
            if (! anchor) return false;
            frag = tpl.content.cloneNode(true);
            if (where === 'before') {
                anchor.parentNode.insertBefore(frag, anchor);
            } else {
                anchor.insertBefore(frag, anchor.firstChild);
            }
            tpl.dataset.emInjected = '1';
            return true;
        }
        // Slice 2zz.7.5: hide stray page-title headings on the Email
        // screen — Youzify renders them via different selectors than
        // our CSS catches in some themes. Walk siblings of our tab
        // strip + the messages container, nuke any h1/h2 whose text
        // is exactly "Email" / "Messages" (or empty).
        function hideEmailHeading() {
            if (! document.body.classList.contains('em-inbox-bp-mode-email')) return;
            var strip = document.querySelector('.em-inbox-messages-tabs');
            var roots = [strip ? strip.parentNode : null, findContainer(), document.querySelector('.em-inbox-wrap--frontend')];
            var seen = new Set();
            roots.forEach(function (root) {
                if (! root || seen.has(root)) return;
                seen.add(root);
                root.querySelectorAll('h1, h2').forEach(function (h) {
                    var txt = (h.textContent || '').trim().toLowerCase();
                    if (txt === 'email' || txt === 'messages' || txt === '') {
                        h.style.display = 'none';
                    }
                });
            });
        }

        // Slice 2zz.7.2: remove the "Email" entry from the BP subnav
        // strip by href-matching (most reliable across Youzify variants).
        // The route stays registered server-side so /messages/email/
        // still works; we just nuke the <li> from the strip.
        function removeEmailItem() {
            var subnav = findSubnav();
            if (! subnav) return false;
            // Match any anchor pointing at our email subnav, then remove
            // the closest list-item container.
            var anchors = subnav.querySelectorAll('a[href$="/messages/email/"], a[href$="/messages/email"], a[href*="/messages/email/"]');
            var removed = 0;
            anchors.forEach(function (a) {
                var li = a.closest('li');
                if (li && li.parentNode) {
                    li.parentNode.removeChild(li);
                    removed++;
                }
            });
            // Style every remaining anchor so the Chat sub-tabs match
            // the design language. Adds .em-chat-subnav-item to each
            // so the CSS has a single hook.
            subnav.querySelectorAll('li > a').forEach(function (a) {
                a.classList.add('em-chat-subnav-item');
            });
            return removed > 0;
        }
        function run() {
            inject();
            removeEmailItem();
            hideEmailHeading();
            // Youzify can render the subnav after DOMContentLoaded —
            // watch the body for ~3s and re-apply as nodes arrive.
            var obs = new MutationObserver(function () {
                inject();
                removeEmailItem();
                hideEmailHeading();
            });
            obs.observe(document.body, { childList: true, subtree: true });
            setTimeout(function () { obs.disconnect(); }, 3000);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }

        // Slice 2zz.7.3 — AJAX tab switching. Intercept clicks on the
        // Email/Chat strip + the BP subnav, fetch the target URL, swap
        // the content container, re-run our injectors, re-mount the SPA
        // if needed. Falls through to a normal navigation on any error
        // (so users never get stranded if the fetch fails).
        function findContainer(doc) {
            doc = doc || document;
            var sels = ['.youzify-bp-messages', '.youzify-bp-content', '.bp-messages', '#buddypress', '.youzify-main-column'];
            for (var i = 0; i < sels.length; i++) {
                var el = doc.querySelector(sels[i]);
                if (el) return el;
            }
            return null;
        }
        function swapBodyClass(targetTab) {
            document.body.classList.remove('em-inbox-bp-mode-email', 'em-inbox-bp-mode-chat');
            document.body.classList.add('em-inbox-bp-mode-' + targetTab);
        }
        function ajaxNavigate(url, targetTab, push) {
            var container = findContainer(document);
            if (! container) { window.location.href = url; return; }
            container.classList.add('em-inbox-ajax-loading');
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'em-inbox-ajax' } })
                .then(function (r) { if (! r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(function (html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var newContainer = findContainer(doc);
                    if (! newContainer) throw new Error('no container in response');
                    container.innerHTML = newContainer.innerHTML;
                    // Push URL into the address bar (only when this was
                    // a fresh click — popstate replays already match).
                    if (push !== false) {
                        try { history.pushState({ emTab: targetTab }, '', url); } catch (e) {}
                    }
                    swapBodyClass(targetTab);
                    // Re-render our top strip — it was nuked when we
                    // overwrote container.innerHTML.
                    var tpl = document.getElementById('em-inbox-messages-tabs-template');
                    if (tpl) tpl.dataset.emInjected = '';
                    inject();
                    removeEmailItem();
                    hideEmailHeading();
                    // Re-mount the SPA if the email screen is now active.
                    if (targetTab === 'email' && typeof window.emInboxMount === 'function') {
                        // Give React's createRoot a tick — also lets any
                        // BP/Youzify initializers settle first.
                        setTimeout(window.emInboxMount, 0);
                    }
                    // Make sure the strip's active state reflects the
                    // new tab regardless of which Active was rendered
                    // server-side.
                    document.querySelectorAll('.em-inbox-messages-tab').forEach(function (a) {
                        var isEmail = /\/messages\/email\/?$/.test(a.getAttribute('href') || '');
                        a.classList.toggle('is-active', (targetTab === 'email') === isEmail);
                        a.setAttribute('aria-current', a.classList.contains('is-active') ? 'page' : 'false');
                    });
                    window.scrollTo(0, 0);
                })
                .catch(function () {
                    // Bail to a full reload — never strand the user.
                    window.location.href = url;
                })
                .finally(function () {
                    container.classList.remove('em-inbox-ajax-loading');
                });
        }
        function classifyLink(a) {
            var href = a.getAttribute('href') || '';
            if (! href || href.charAt(0) === '#') return null;
            if (a.hasAttribute('data-em-no-ajax')) return null;
            // Skip download / mailto / tel / javascript:
            if (/^(mailto:|tel:|javascript:|data:)/i.test(href)) return null;
            // Same-origin check via URL parser.
            var url;
            try { url = new URL(href, location.href); } catch (e) { return null; }
            if (url.origin !== location.origin) return null;
            // Must be under the SAME user's /messages/ path. Any link
            // outside that section (going to wallet, app projects, etc.)
            // navigates normally.
            if (url.pathname.indexOf('/messages/') === -1
                && ! /\/messages\/?$/.test(url.pathname)) {
                return null;
            }
            // The Email tab.
            if (/\/messages\/email\/?$/.test(url.pathname)) return 'email';
            // Everything else under /messages/ — Chat root, sub-tabs
            // (Inbox / Starred / Sent / Compose / Notices / Search),
            // thread-view (/view/{id}/), pagination, etc. — falls under
            // Chat.
            return 'chat';
        }
        document.addEventListener('click', function (e) {
            // Plain left-click only.
            if (e.defaultPrevented || e.button !== 0) return;
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            var a = e.target && e.target.closest ? e.target.closest('a') : null;
            if (! a || a.target === '_blank') return;
            var kind = classifyLink(a);
            if (! kind) return;
            var targetTab = (kind === 'email') ? 'email' : 'chat';
            e.preventDefault();
            ajaxNavigate(a.href, targetTab, true);
        });
        window.addEventListener('popstate', function (e) {
            var path = location.pathname;
            var targetTab = /\/messages\/email\/?$/.test(path) ? 'email' : 'chat';
            ajaxNavigate(location.href, targetTab, false);
        });
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Frontend asset enqueue — load the inbox SPA when on /messages/email/
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'em_inbox_bp_messages_email_enqueue', 20);
function em_inbox_bp_messages_email_enqueue() {
    if (! function_exists('bp_is_messages_component') || ! function_exists('bp_current_action')) return;
    if (! bp_is_messages_component()) return;
    if (! em_inbox_user_has_inbox_access(bp_displayed_user_id())) return;

    // Slice 2zz.7.3: enqueue tab-strip CSS + SPA on EVERY messages
    // screen (was Email-only) so AJAX switching between Email and
    // Chat works without a full page reload — the SPA's
    // window.emInboxMount() global lets the tab-strip JS re-mount
    // the React tree after swapping content into the page.
    wp_enqueue_style(
        'em-inbox-bp-tabs',
        EMAIL_MANAGER_URL . 'assets/inbox-bp-tabs.css',
        array(),
        EMAIL_MANAGER_VERSION
    );
    wp_enqueue_style(
        'em-inbox-app',
        EMAIL_MANAGER_URL . 'assets/inbox-app.css',
        array('wp-components'),
        EMAIL_MANAGER_VERSION
    );
    wp_enqueue_script(
        'em-inbox-app',
        EMAIL_MANAGER_URL . 'assets/inbox-app.js',
        array('wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch'),
        EMAIL_MANAGER_VERSION,
        true
    );
    $u = wp_get_current_user();
    $tz_str = function_exists('wp_timezone_string') ? wp_timezone_string() : (get_option('timezone_string') ?: 'UTC');
    wp_localize_script('em-inbox-app', 'EM_INBOX_CONFIG', array(
        'restRoot'         => esc_url_raw(rest_url('em/v1/inbox/')),
        'nonce'            => wp_create_nonce('wp_rest'),
        'isAdmin'          => current_user_can('manage_options'),
        'currentUserEmail' => $u ? $u->user_email : '',
        'userTimezone'     => $tz_str ?: 'UTC',
        'frontend'         => true,
    ));
}
