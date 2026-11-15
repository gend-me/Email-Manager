<?php
/**
 * Profile > Overview > Applications (owner request 2026-11-11): the last Overview sub-tab on a member's OWN profile
 * (gend-society inc/member-profile-pages.php prints the tab and calls em_member_apps_content()). The old
 * /members/<me>/applications/ address redirects to <profile>/#applications, so links in pages and emails keep working. Lists every application
 * they sent through a Talk Flows posting with its process and current step. When the step they are on is a user-action
 * step set to "Member submits it" (Project Consultant / Web Developer: "Set up your profile"), it shows a profile
 * checklist and a "Submit profile for review" button, which completes the step through EM_Postings
 * (wp_ajax_em_member_submit_step) and moves the application into Review in the back office.
 *
 * Visitors never see the tab (show_for_displayed_user false, and gend-society replaces a visitor's menu anyway), and the
 * screen redirects anyone but the member to the profile.
 *
 * @package email-manager
 */
if (!defined('ABSPATH')) exit;

const EM_MEMBER_APPS_SLUG = 'applications';

add_action('bp_setup_nav', 'em_member_apps_nav', 120);
function em_member_apps_nav()
{
    if (!function_exists('bp_core_new_nav_item') || !class_exists('EM_Postings') || !is_user_logged_in()) return;
    // show_for_displayed_user false: only the member themselves sees it; the screen also redirects anyone else.
    bp_core_new_nav_item([
        'name'                    => __('Applications', 'email-manager'),
        'slug'                    => EM_MEMBER_APPS_SLUG,
        'screen_function'         => 'em_member_apps_screen',
        'position'                => 200,
        'item_css_id'             => 'applications',
        'show_for_displayed_user' => false,
    ]);
}

function em_member_apps_screen()
{
    // The list lives under Overview now: open it there (visitors just land on the profile).
    bp_core_redirect(trailingslashit(bp_displayed_user_domain()) . (bp_is_my_profile() ? '#applications' : ''));
}

// Not a top menu item any more (gend-society profile menu).
add_filter('gend_society_profile_nav_items', function ($items) {
    return array_values(array_filter((array) $items, function ($it) { return !isset($it->slug) || $it->slug !== EM_MEMBER_APPS_SLUG; }));
}, 99);

// gend-society profile menu icon (its filter returns the whole <svg>).
add_filter('gend_society_profile_nav_icon', function ($svg, $slug) {
    if ($slug !== EM_MEMBER_APPS_SLUG) return $svg;
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M9 3.5V5h6V3.5"/><path d="m8.5 12 2 2 4-4"/><path d="M8.5 17.5h7"/></svg>';
}, 10, 2);

/** What a reviewer looks at, from what the member has on their profile now. */
function em_member_apps_profile_checks($uid)
{
    $uid  = (int) $uid;
    $base = em_member_apps_profile_url($uid);
    $cover = EM_Postings::cover_page_ok($uid, false);
    $resume = get_user_meta($uid, '_gend_society_resume', true);
    $has_resume = is_array($resume) && (!empty($resume['experience']) || !empty($resume['education']));
    $items = function_exists('gend_society_portfolio_items') ? gend_society_portfolio_items($uid, true) : [];
    $n = is_array($items) ? count($items) : 0;
    return [
        ['key' => 'cover', 'title' => __('Overview page', 'email-manager'), 'done' => $cover,
         'text' => __('Built with the drag-and-drop page builder. Customers see it when they choose who to work with.', 'email-manager'), 'url' => $base . '#cover', 'go' => __('Build it', 'email-manager')],
        ['key' => 'resume', 'title' => __('Resume', 'email-manager'), 'done' => $has_resume,
         'text' => __('Your experience, the projects behind it and your credentials.', 'email-manager'), 'url' => $base . '#resume', 'go' => __('Add it', 'email-manager')],
        ['key' => 'portfolio', 'title' => __('Portfolio', 'email-manager'), 'done' => $n >= 3,
         'text' => $n ? sprintf(_n('%d item so far. Three or more show your range best.', '%d items so far. Three or more show your range best.', $n, 'email-manager'), $n) : __('Photos, videos, files, links and posts of work you are proud of. Three or more is best.', 'email-manager'),
         'url' => $base . '#portfolio', 'go' => __('Add work', 'email-manager')],
    ];
}

function em_member_apps_status($app)
{
    if (!empty($app['rejected'])) return ['closed', __('Not selected', 'email-manager')];
    if ($app['approved']) return ['approved', __('Approved', 'email-manager')];
    if (!empty($app['email_hold'])) return ['you', __('Confirm your email', 'email-manager')];
    if ($app['can_submit']) return ['you', __('Waiting on you', 'email-manager')];
    if ($app['waiting_on'] === 'approval') return ['review', __('In review', 'email-manager')];
    if ($app['waiting_on'] === 'action') return ['you', __('Waiting on you', 'email-manager')];
    return ['review', __('Received', 'email-manager')];
}

function em_member_apps_card($app, $checks)
{
    list($cls, $label) = em_member_apps_status($app);
    $cur = $app['steps'][$app['index']] ?? null;
    ob_start();
    ?>
    <article class="em-ma-card is-<?php echo esc_attr($cls); ?>" data-em-ma-app="<?php echo (int) $app['id']; ?>">
        <header class="em-ma-card__head">
            <div>
                <span class="em-ma-chip is-<?php echo esc_attr($cls); ?>"><i></i><?php echo esc_html($label); ?></span>
                <h3><?php echo esc_html($app['title']); ?></h3>
                <p class="em-ma-meta"><?php echo esc_html(sprintf(__('Applied %s', 'email-manager'), $app['submitted'])); ?> &middot; <a href="<?php echo esc_url($app['url']); ?>"><?php esc_html_e('View the posting', 'email-manager'); ?></a></p>
            </div>
        </header>
        <ol class="em-ma-steps" style="--n:<?php echo count($app['steps']); ?>">
            <?php foreach ($app['steps'] as $i => $s) :
                $st = ($app['approved'] && $s['final']) || $i < $app['index'] || ($app['state'] === 'completed') ? 'done' : ($i === $app['index'] ? 'here' : 'next'); ?>
            <li class="is-<?php echo esc_attr($st); ?>"><span class="em-ma-dot"><?php echo $st === 'done' ? '&#10003;' : (int) ($i + 1); ?></span><b><?php echo esc_html($s['title']); ?></b></li>
            <?php endforeach; ?>
        </ol>
        <?php if ($cur && !$app['approved'] && $cur['desc'] !== '') : ?>
            <p class="em-ma-now"><b><?php echo esc_html($cur['title']); ?>:</b> <?php echo esc_html($cur['desc']); ?></p>
        <?php endif; ?>
        <?php if (!empty($app['rejected'])) : ?>
            <p class="em-ma-note"><?php esc_html_e('We have decided not to move forward with this application. Your account, referral link and dashboards stay active, and you are welcome to apply again later.', 'email-manager'); ?></p>
        <?php elseif (!empty($app['email_hold'])) : ?>
            <div class="em-ma-submit">
                <p><?php echo esc_html(sprintf(__('One step first: confirm your email address. We sent a link to %s. Your application moves on the moment you click it.', 'email-manager'), wp_get_current_user()->user_email)); ?></p>
                <button type="button" class="em-ma-btn" data-em-ma-resend><?php esc_html_e('Resend the confirmation email', 'email-manager'); ?></button>
                <p class="em-ma-msg" data-em-ma-msg aria-live="polite"></p>
            </div>
        <?php elseif ($app['can_submit']) :
            $done = count(array_filter($checks, function ($c) { return $c['done']; })); ?>
            <div class="em-ma-checks">
                <?php foreach ($checks as $i => $c) : ?>
                <a class="em-ma-check<?php echo $c['done'] ? ' is-done' : ''; ?>" href="<?php echo esc_url($c['url']); ?>">
                    <span class="em-ma-check__n"><?php echo $c['done'] ? '&#10003;' : (int) ($i + 1); ?></span>
                    <span class="em-ma-check__t"><b><?php echo esc_html($c['title']); ?></b><small><?php echo esc_html($c['text']); ?></small></span>
                    <span class="em-ma-check__go"><?php echo $c['done'] ? esc_html__('Edit', 'email-manager') : esc_html($c['go']); ?> &rarr;</span>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="em-ma-submit">
                <p><?php echo $done === count($checks)
                    ? esc_html__('Your profile is ready. Submit it and your application moves to Review: we look at your overview page, resume and portfolio and email you our decision.', 'email-manager')
                    : esc_html(sprintf(__('%1$d of %2$d done. You can submit now, but a complete profile is what gets approved.', 'email-manager'), $done, count($checks))); ?></p>
                <button type="button" class="em-ma-btn" data-em-ma-submit="<?php echo (int) $app['id']; ?>"><?php echo esc_html($app['action_label'] !== '' ? $app['action_label'] : __('Submit profile for review', 'email-manager')); ?></button>
                <p class="em-ma-msg" data-em-ma-msg aria-live="polite"></p>
            </div>
        <?php elseif ($cls === 'review') : ?>
            <p class="em-ma-note"><?php esc_html_e('Nothing for you to do right now. We will email you as soon as there is news.', 'email-manager'); ?></p>
            <?php if (em_member_apps_role($app) === 'developer') echo em_member_apps_desktop_cta(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php elseif ($app['approved']) : ?>
            <p class="em-ma-note is-ok"><?php esc_html_e('You are approved. Your dashboards and contracts are switched on.', 'email-manager'); ?></p>
        <?php elseif ($app['waiting_on'] === 'action' && $app['action_url'] !== '') : ?>
            <p class="em-ma-note"><a class="em-ma-btn is-ghost" href="<?php echo esc_url($app['action_url']); ?>"><?php echo esc_html($app['action_label'] !== '' ? $app['action_label'] : __('Do this step', 'email-manager')); ?></a></p>
        <?php endif; ?>
    </article>
    <?php
    return ob_get_clean();
}

function em_member_apps_content()
{
    $uid  = get_current_user_id();
    $apps = EM_Postings::member_applications($uid);
    $need = array_filter($apps, function ($a) { return $a['can_submit']; });
    $checks = $need ? em_member_apps_profile_checks($uid) : [];
    echo '<style>' . em_member_apps_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
    <section class="em-ma" data-em-ma data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('em_member_apps')); ?>" data-done-url="<?php echo esc_attr(em_member_apps_done_url($uid)); ?>">
        <header class="em-ma-hero">
            <span class="em-ma-eyebrow"><?php esc_html_e('Your applications', 'email-manager'); ?></span>
            <h2><?php esc_html_e('Where each application stands', 'email-manager'); ?></h2>
            <p><?php esc_html_e('Every role you applied for, the step it is on, and anything waiting on you.', 'email-manager'); ?></p>
        </header>
        <?php if (!$apps) : ?>
            <div class="em-ma-empty">
                <p><?php esc_html_e('You have not applied for anything yet.', 'email-manager'); ?></p>
                <div class="em-ma-open">
                    <?php foreach (EM_Postings::get_postings() as $p) :
                        if ((get_post_meta($p->ID, EM_Postings::META_STATUS, true) ?: 'open') !== 'open' || $p->post_status !== 'publish') continue; ?>
                        <a class="em-ma-btn is-ghost" href="<?php echo esc_url(EM_Postings::landing_url($p)); ?>"><?php echo esc_html($p->post_title); ?> &rarr;</a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else : ?>
            <div class="em-ma-list">
                <?php foreach ($apps as $a) echo em_member_apps_card($a, $checks); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

// The profile menu swaps pages without a reload (gdc-nav-pjax), so the button handler is delegated from the document
// and printed on every page of the member's own profile.
add_action('wp_footer', function () {
    if (!function_exists('bp_is_user') || !bp_is_user() || !bp_is_my_profile()) return;
    ?>
    <script id="em-member-apps-js">(function () {
        if (window.emMemberApps) return; window.emMemberApps = 1;
        document.addEventListener('click', function (e) {
            var rs = e.target.closest ? e.target.closest('[data-em-ma-resend]') : null;
            if (rs) {
                var rr = rs.closest('[data-em-ma]'), rm = rs.parentNode.querySelector('[data-em-ma-msg]');
                if (!rr || rs.disabled) return;
                rs.disabled = true; if (rm) { rm.className = 'em-ma-msg'; rm.textContent = 'Sending…'; }
                var rf = new FormData(); rf.append('action', 'em_member_resend_confirmation'); rf.append('nonce', rr.getAttribute('data-nonce'));
                fetch(rr.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: rf }).then(function (r) { return r.json(); }).then(function (j) {
                    rs.disabled = false;
                    if (rm) { rm.className = 'em-ma-msg' + (j && j.success ? '' : ' is-error'); rm.textContent = (j && j.data && j.data.message) || 'Could not send it. Please try again.'; }
                }).catch(function () { rs.disabled = false; if (rm) { rm.className = 'em-ma-msg is-error'; rm.textContent = 'Network error. Please try again.'; } });
                return;
            }
            var b = e.target.closest ? e.target.closest('[data-em-ma-submit]') : null;
            if (!b) return;
            var root = b.closest('[data-em-ma]'), card = b.closest('[data-em-ma-app]');
            var msg = card ? card.querySelector('[data-em-ma-msg]') : null;
            if (!root || b.disabled) return;
            b.disabled = true; if (msg) { msg.className = 'em-ma-msg'; msg.textContent = 'Submitting…'; }
            var fd = new FormData();
            fd.append('action', 'em_member_submit_step');
            fd.append('nonce', root.getAttribute('data-nonce'));
            fd.append('submission_id', b.getAttribute('data-em-ma-submit'));
            fetch(root.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.success) {
                        if (msg) msg.textContent = 'Submitted. Your application is now in review.';
                        var done = root.getAttribute('data-done-url');
                        setTimeout(function () {
                            if (done) { location.href = done.replace('{id}', encodeURIComponent(b.getAttribute('data-em-ma-submit'))); return; }
                            location.reload();
                        }, 900);
                        return;
                    }
                    b.disabled = false;
                    if (msg) { msg.className = 'em-ma-msg is-error'; msg.textContent = (j && j.data && j.data.message) || 'Could not submit it. Please try again.'; }
                })
                .catch(function () { b.disabled = false; if (msg) { msg.className = 'em-ma-msg is-error'; msg.textContent = 'Network error. Please try again.'; } });
        });
    }());</script>
    <?php
}, 50);

/* ---------------- Next-step bar (owner request 2026-11-12) ----------------
 * Applicants follow the checklist links off to build their overview page, resume and portfolio and never come back to
 * press Submit. While an application waits on the member, every page of their own profile shows a bar pinned to the
 * bottom with their checklist progress and the Submit button itself (same delegated handler as the Applications tab). */

/** The member's first application waiting on them to submit, or null. */
function em_member_apps_waiting($uid)
{
    if (!class_exists('EM_Postings') || (int) $uid < 1) return null;
    foreach (EM_Postings::member_applications((int) $uid) as $a) {
        if (!empty($a['can_submit']) && empty($a['email_hold']) && empty($a['rejected'])) return $a;
    }
    return null;
}

function em_member_apps_profile_url($uid)
{
    $u = function_exists('bp_members_get_user_url') ? bp_members_get_user_url((int) $uid) : '';
    if (!$u && function_exists('bp_core_get_user_domain')) $u = bp_core_get_user_domain((int) $uid);
    return trailingslashit($u ?: home_url('/'));
}

/** The Applications section of the member's profile (the bar's script scrolls to it). */
function em_member_apps_url($uid)
{
    return em_member_apps_profile_url($uid) . '#applications';
}

/** Where a member lands after submitting their profile: their Commissions screen, showing the application's timeline once. */
function em_member_apps_done_url($uid)
{
    return add_query_arg('em_submitted', '{id}', em_member_apps_profile_url($uid) . 'invest/');
}

add_action('wp_footer', function () {
    if (!function_exists('bp_is_user') || !bp_is_user() || !bp_is_my_profile()) return;
    em_member_apps_bar(get_current_user_id());
}, 60);

function em_member_apps_bar($uid)
{
    $uid = (int) $uid;
    $app = em_member_apps_waiting($uid);
    if (!$app) return;
    $checks = em_member_apps_profile_checks($uid);
    $done   = count(array_filter($checks, function ($c) { return $c['done']; }));
    $total  = count($checks);
    $ready  = $done === $total;
    $label  = $app['action_label'] !== '' ? $app['action_label'] : __('Submit profile for review', 'email-manager');
    $next   = null;
    foreach ($checks as $c) { if (!$c['done']) { $next = $c; break; } }
    ?>
    <div class="em-ma-bar<?php echo $ready ? ' is-ready' : ''; ?>" data-em-ma data-em-ma-bar data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('em_member_apps')); ?>" data-done-url="<?php echo esc_attr(em_member_apps_done_url($uid)); ?>" role="region" aria-label="<?php esc_attr_e('Your application', 'email-manager'); ?>">
        <div class="em-ma-bar__in" data-em-ma-app="<?php echo (int) $app['id']; ?>">
            <span class="em-ma-bar__ring" style="--p:<?php echo (int) round($done / max(1, $total) * 100); ?>"><b><?php echo (int) $done; ?>/<?php echo (int) $total; ?></b></span>
            <div class="em-ma-bar__t">
                <b><?php echo esc_html(sprintf(__('%s application: one step left', 'email-manager'), $app['title'])); ?></b>
                <small><?php
                    if ($ready) {
                        esc_html_e('Your profile is ready. Press Submit to send it for review. We can\'t start until you do.', 'email-manager');
                    } else {
                        echo esc_html(sprintf(__('%1$d of %2$d profile parts done. When you\'re happy with it, press Submit. We can\'t review it until you do.', 'email-manager'), $done, $total));
                        if ($next) echo ' <a href="' . esc_url($next['url']) . '">' . esc_html(sprintf(__('Next: %s', 'email-manager'), $next['title'])) . ' &rarr;</a>';
                    } ?></small>
            </div>
            <div class="em-ma-bar__acts">
                <a class="em-ma-bar__link" href="<?php echo esc_url(em_member_apps_url($uid)); ?>"><?php esc_html_e('See checklist', 'email-manager'); ?></a>
                <button type="button" class="em-ma-btn" data-em-ma-submit="<?php echo (int) $app['id']; ?>"><?php echo esc_html($label); ?></button>
            </div>
            <button type="button" class="em-ma-bar__x" data-em-ma-bar-x aria-label="<?php esc_attr_e('Minimise', 'email-manager'); ?>">&minus;</button>
            <p class="em-ma-msg" data-em-ma-msg aria-live="polite"></p>
        </div>
        <button type="button" class="em-ma-bar__pill" data-em-ma-bar-x><i></i><?php echo esc_html($label); ?></button>
    </div>
    <style><?php echo em_member_apps_bar_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
    <script>(function () {
        var bar = document.querySelector('[data-em-ma-bar]');
        if (!bar) return;
        var K = 'em-ma-bar-min';
        function sync() { document.documentElement.classList.toggle('em-ma-bar-on', !bar.classList.contains('is-min')); }
        // Scroll to "Where each application stands" once the Applications section is showing (the profile opens it from #applications).
        function toSection(tries) {
            var sec = document.querySelector('section.em-ma');
            if (sec && sec.offsetParent !== null) {
                var y = sec.getBoundingClientRect().top + window.pageYOffset - 90;
                window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
                return;
            }
            if (tries > 0) setTimeout(function () { toSection(tries - 1); }, 200);
        }
        var link = bar.querySelector('.em-ma-bar__link');
        if (link) link.addEventListener('click', function (e) {
            if (!document.querySelector('section.em-ma')) return;   // not on the Overview page: load it (the hash scrolls on arrival)
            e.preventDefault();
            var tab = document.querySelector('a[href$="#applications"]:not(.em-ma-bar__link), [data-tab="applications"], [data-subtab="applications"]');
            if (tab && tab !== link) tab.click();
            if (location.hash !== '#applications') history.replaceState(null, '', '#applications');
            window.dispatchEvent(new HashChangeEvent('hashchange'));
            toSection(25);
        });
        if (location.hash === '#applications') toSection(40);
        try { if (sessionStorage.getItem(K) === '1') bar.classList.add('is-min'); } catch (e) {}
        bar.addEventListener('click', function (e) {
            if (!e.target.closest('[data-em-ma-bar-x]')) return;
            var min = !bar.classList.contains('is-min');
            bar.classList.toggle('is-min', min);
            try { sessionStorage.setItem(K, min ? '1' : '0'); } catch (err) {}
            sync();
        });
        window.addEventListener('hashchange', sync);
        sync();
    }());</script>
    <?php
}

add_action('wp_footer', function () {
    if (!function_exists('bp_is_user') || !bp_is_user() || !bp_is_my_profile() || !isset($_GET['em_submitted'])) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!function_exists('bp_is_current_component') || !bp_is_current_component('invest')) return;
    $sid = absint($_GET['em_submitted']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $app = null;
    foreach (EM_Postings::member_applications(get_current_user_id()) as $a) {
        if ((int) $a['id'] === $sid) { $app = $a; break; }
    }
    if (!$app) return;
    ?>
    <div id="em-ma-justsent" class="em-ma em-ma--justsent" hidden>
        <style><?php echo em_member_apps_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        .em-ma--justsent{max-width:none;padding:0 0 22px}
        .em-ma--justsent .em-ma-sent{display:flex;align-items:center;gap:10px;margin:0 0 12px;color:#34d399;font-weight:800;font-size:.95rem}
        .em-ma--justsent .em-ma-sent i{width:9px;height:9px;border-radius:50%;background:#34d399;box-shadow:0 0 12px #34d399}</style>
        <p class="em-ma-sent"><i></i><?php esc_html_e('Profile submitted. Your application is now in review.', 'email-manager'); ?></p>
        <?php echo em_member_apps_card($app, []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
    <script>(function () {
        var card = document.getElementById('em-ma-justsent'), host = document.querySelector('.gend-invest-screen');
        if (!card) return;
        if (host) host.insertBefore(card, host.firstChild);
        card.hidden = false;
        // Shown once: a refresh or a shared link does not bring it back.
        try { var u = new URL(location.href); u.searchParams.delete('em_submitted'); history.replaceState(null, '', u.toString()); } catch (e) {}
    }());</script>
    <?php
}, 60);

function em_member_apps_bar_css()
{
    return '
.em-ma-bar{position:fixed;left:50%;bottom:18px;z-index:99990;width:min(980px,calc(100vw - 24px));transform:translateX(-50%);font-family:Inter,system-ui,sans-serif;color:#cbd5e1;animation:em-ma-bar-in .5s cubic-bezier(.2,.9,.3,1.1) both}
.em-ma-bar[hidden]{display:none!important}
.em-ma-bar *{box-sizing:border-box}
@keyframes em-ma-bar-in{from{opacity:0;transform:translate(-50%,24px)}}
.em-ma-bar__in{position:relative;display:flex;align-items:center;gap:16px;padding:14px 52px 14px 16px;border-radius:22px;border:1px solid rgba(251,191,36,.45);background:radial-gradient(120% 140% at 0% 0%,rgba(251,191,36,.16),transparent 55%),rgba(11,14,20,.94);box-shadow:0 24px 60px -20px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.03) inset;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
.em-ma-bar.is-ready .em-ma-bar__in{border-color:rgba(52,211,153,.55);background:radial-gradient(120% 140% at 0% 0%,rgba(52,211,153,.16),transparent 55%),rgba(11,14,20,.94)}
.em-ma-bar__ring{position:relative;flex:0 0 52px;width:52px;height:52px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#fbbf24 calc(var(--p)*1%),rgba(255,255,255,.1) 0)}
.em-ma-bar.is-ready .em-ma-bar__ring{background:conic-gradient(#34d399 calc(var(--p)*1%),rgba(255,255,255,.1) 0)}
.em-ma-bar__ring::before{content:"";position:absolute;inset:5px;border-radius:50%;background:#0b0e14}
.em-ma-bar__ring b{position:relative;color:#fff;font-size:.8rem;font-weight:900}
.em-ma-bar__t{flex:1;min-width:0}
.em-ma-bar__t b{display:block;color:#fff;font-size:.98rem;font-weight:800;line-height:1.3}
.em-ma-bar__t small{display:block;margin-top:2px;color:#94a3b8;font-size:.82rem;line-height:1.45}
.em-ma-bar__t small a{color:#fbbf24!important;font-weight:700;text-decoration:none!important;white-space:nowrap}
.em-ma-bar__acts{display:flex;align-items:center;gap:14px;flex:0 0 auto}
.em-ma-bar__link{color:#cbd5e1!important;font-size:.8rem;font-weight:700;text-decoration:underline!important;text-underline-offset:3px;white-space:nowrap}
.em-ma-bar .em-ma-btn{all:unset;box-sizing:border-box;cursor:pointer;display:inline-block;padding:13px 24px;border-radius:100px;font-size:.78rem;font-weight:900;letter-spacing:1.2px;text-transform:uppercase;white-space:nowrap;color:#05070a!important;text-decoration:none!important;background:linear-gradient(135deg,#fbbf24,#f472b6);box-shadow:0 14px 30px -14px rgba(251,191,36,.9);transition:transform .2s,filter .2s}
.em-ma-bar .em-ma-btn:hover{transform:translateY(-2px);filter:brightness(1.08)}
.em-ma-bar .em-ma-btn:disabled{opacity:.6;cursor:wait;transform:none}
.em-ma-bar .em-ma-msg{color:#34d399}
.em-ma-bar .em-ma-msg.is-error{color:#f87171}
@keyframes em-ma-pulse{50%{opacity:.35}}
.em-ma-bar.is-ready .em-ma-btn{background:linear-gradient(135deg,#34d399,#22d3ee);box-shadow:0 14px 30px -14px rgba(52,211,153,.9);animation:em-ma-glow 2.2s ease-in-out infinite}
@keyframes em-ma-glow{50%{box-shadow:0 0 0 6px rgba(52,211,153,.18),0 14px 30px -14px rgba(52,211,153,.9)}}
.em-ma-bar__x{all:unset;cursor:pointer;position:absolute;top:10px;right:12px;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;color:#94a3b8;font-size:18px;line-height:1;background:rgba(255,255,255,.06)}
.em-ma-bar__x:hover{color:#fff;background:rgba(255,255,255,.12)}
.em-ma-bar .em-ma-msg{position:absolute;left:84px;bottom:-2px;margin:0!important;font-size:.76rem}
.em-ma-bar .em-ma-msg:empty{display:none}
.em-ma-bar__pill{all:unset;cursor:pointer;display:none;align-items:center;gap:9px;margin-left:auto;padding:11px 18px;border-radius:100px;font-size:.76rem;font-weight:900;letter-spacing:1px;text-transform:uppercase;color:#05070a;background:linear-gradient(135deg,#fbbf24,#f472b6);box-shadow:0 14px 30px -12px rgba(0,0,0,.8)}
.em-ma-bar__pill i{width:8px;height:8px;border-radius:50%;background:#05070a;animation:em-ma-pulse 1.6s ease-in-out infinite}
.em-ma-bar.is-min{width:auto;left:auto;right:18px;transform:none;animation:none}
.em-ma-bar.is-min .em-ma-bar__in{display:none}
.em-ma-bar.is-min .em-ma-bar__pill{display:inline-flex}
html.em-ma-bar-on body{padding-bottom:110px}
@media(max-width:760px){.em-ma-bar{bottom:10px}.em-ma-bar__in{flex-wrap:wrap;gap:10px 12px;padding:12px 44px 12px 12px}.em-ma-bar__ring{flex-basis:44px;width:44px;height:44px}.em-ma-bar__t{flex:1 1 calc(100% - 60px)}.em-ma-bar__acts{flex:1 1 100%;justify-content:space-between}.em-ma-bar .em-ma-btn{display:inline-block;width:auto;padding:12px 18px;font-size:.72rem}.em-ma-bar .em-ma-msg{position:static;flex:1 1 100%}html.em-ma-bar-on body{padding-bottom:190px}}
';
}

/* ---------------- 4-hour reminder (owner request 2026-11-12) ----------------
 * Once per application: 4 hours after it reached a "Member submits it" step, if the member still hasn't submitted, email
 * them a reminder with their checklist and a button to the Applications tab. Runs on the postings' hourly check.
 * add_post_meta(..., unique) claims the application first, so two overlapping cron runs can't send it twice. */
const EM_MEMBER_APPS_REMINDER_META  = '_em_submit_reminder_sent';
const EM_MEMBER_APPS_REMINDER_AFTER = 14400; // 4 hours

add_action('em_postings_check_actions', 'em_member_apps_send_reminders', 20); // EM_Postings::CRON_HOOK (hourly)

function em_member_apps_send_reminders()
{
    if (!class_exists('EM_Postings') || !class_exists('EM_Applications') || !function_exists('bp_core_get_user_domain')) return 0;
    $ids = get_posts([
        'post_type'   => 'chat_submission',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => 200,
        'fields'      => 'ids',
        'date_query'  => [['after' => '14 days ago', 'column' => 'post_date_gmt']], // never chase old applications
        'meta_query'  => [
            ['key' => EM_Postings::SUBMISSION_TAG, 'compare' => 'EXISTS'],
            ['key' => EM_Postings::META_STEP_STATE, 'value' => 'pending'],
            ['key' => EM_MEMBER_APPS_REMINDER_META, 'compare' => 'NOT EXISTS'],
        ],
    ]);
    $sent = 0;
    foreach ($ids as $sid) {
        $sid   = (int) $sid;
        $since = max((int) get_post_meta($sid, EM_Postings::META_STEP_SINCE, true), (int) get_post_time('U', true, $sid));
        if (time() - $since < EM_MEMBER_APPS_REMINDER_AFTER) continue;
        $uid = (int) get_post_meta($sid, EM_Applications::APPLICANT_USER_META, true) ?: (int) get_post_field('post_author', $sid);
        $user = $uid > 0 ? get_userdata($uid) : null;
        if (!$user || !is_email($user->user_email)) continue;
        $app = null;
        foreach (EM_Postings::member_applications($uid) as $a) {
            if ((int) $a['id'] === $sid) { $app = $a; break; }
        }
        if (!$app || empty($app['can_submit']) || !empty($app['email_hold']) || !empty($app['rejected'])) continue;
        if (!add_post_meta($sid, EM_MEMBER_APPS_REMINDER_META, time(), true)) continue; // already claimed
        if (em_member_apps_send_reminder($user, $app)) $sent++;
        else update_post_meta($sid, EM_MEMBER_APPS_REMINDER_META, -time()); // negative = wp_mail refused; not retried
    }
    return $sent;
}

function em_member_apps_send_reminder($user, array $app)
{
    $uid    = (int) $user->ID;
    $checks = em_member_apps_profile_checks($uid);
    $done   = count(array_filter($checks, function ($c) { return $c['done']; }));
    $name   = trim((string) $user->first_name) !== '' ? (string) $user->first_name : (string) $user->display_name;
    $label  = $app['action_label'] !== '' ? $app['action_label'] : __('Submit profile for review', 'email-manager');
    $url    = em_member_apps_url($uid);
    $subject = sprintf(__('%1$s, your %2$s application is waiting on one click', 'email-manager'), $name, $app['title']);

    $mono = 'font-family:JetBrains Mono,SFMono-Regular,Consolas,Courier New,monospace;';
    $rows = '';
    foreach ($checks as $c) {
        $rows .= '<tr><td width="40" style="padding:0 0 14px;vertical-align:top;' . $mono . 'font-size:15px;line-height:1.5;color:' . ($c['done'] ? '#00ff88' : '#e06bff') . ';">' . ($c['done'] ? '&#10003;' : '&#9675;') . '</td>'
            . '<td style="padding:0 0 14px;vertical-align:top;"><p style="margin:0 0 2px;font-size:15px;line-height:1.5;font-weight:700;color:#ffffff;">' . esc_html($c['title']) . ' <span style="' . $mono . 'font-size:11px;font-weight:500;letter-spacing:0.12em;text-transform:uppercase;color:' . ($c['done'] ? '#00ff88' : '#aab4bf') . ';">' . ($c['done'] ? esc_html__('Done', 'email-manager') : esc_html__('Not yet', 'email-manager')) . '</span></p>'
            . '<p style="margin:0;font-size:14px;line-height:1.6;color:#aab4bf;">' . esc_html($c['text']) . '</p></td></tr>';
    }
    $lead = $done === count($checks)
        ? __('Your profile looks complete. All that is left is to press the button below, then the Submit button on your Applications tab.', 'email-manager')
        : sprintf(__('You have %1$d of %2$d profile parts done. You can finish the rest first or submit now, but a complete profile is what gets approved.', 'email-manager'), $done, count($checks));

    $body = '<table role="presentation" class="gm-card" data-gend-email="body" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0b0e14" style="width:100%;max-width:600px;margin:0 auto;border-collapse:separate;border-spacing:0;background-color:#0b0e14;border:1px solid #1f2632;border-radius:20px;">'
        . '<tr><td style="height:4px;line-height:4px;font-size:0;background-color:#fbbf24;background-image:linear-gradient(90deg,#fbbf24,#e06bff,#6ec1e4);border-radius:19px 19px 0 0;">&nbsp;</td></tr>'
        . '<tr><td class="gm-pad" style="padding:40px 40px 36px;font-family:Inter,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.65;font-weight:300;color:#dfe6ee;">'
        . '<p class="gm-eyebrow" style="margin:0 0 14px;' . $mono . 'font-size:11px;line-height:1.4;font-weight:500;letter-spacing:0.22em;text-transform:uppercase;color:#fbbf24;">' . esc_html(sprintf(__('Reminder · %s', 'email-manager'), $app['title'])) . '</p>'
        . '<h1 class="gm-h1" style="margin:0 0 18px;font-family:Playfair Display,Georgia,Times New Roman,serif;font-size:32px;line-height:1.12;font-weight:700;letter-spacing:-0.02em;color:#ffffff;">' . esc_html__('One step left:', 'email-manager') . ' <em style="font-style:italic;color:#e06bff;">' . esc_html__('submit your profile', 'email-manager') . '</em>.</h1>'
        . '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#dfe6ee;">' . esc_html(sprintf(__('Hi %1$s, your %2$s application is saved, but we can\'t start reviewing it until you press %3$s on the Applications tab of your profile.', 'email-manager'), $name, $app['title'], $label)) . '</p>'
        . '<p style="margin:0 0 20px;font-size:16px;line-height:1.65;color:#dfe6ee;">' . esc_html($lead) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 6px;">' . $rows . '</table>'
        . '<table role="presentation" class="gm-btn" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 4px;"><tr><td align="center" bgcolor="#b608c9" style="padding:15px 34px;background-color:#b608c9;border-radius:100px;"><a href="' . esc_url($url) . '" style="font-family:Inter,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.2;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#ffffff;text-decoration:none;">' . esc_html__('Open my application', 'email-manager') . '</a></td></tr></table>'
        . '<p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#8b949e;">' . esc_html(sprintf(__('This is a one-time reminder about your %s application on gend.me.', 'email-manager'), $app['title'])) . '</p>'
        . '</td></tr></table>';
    if (function_exists('gend_email_wrap') && function_exists('gend_email_brand_on') && gend_email_brand_on()) $body = gend_email_wrap($body);
    return (bool) wp_mail($user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
}

function em_member_apps_css()
{
    return (function_exists('em_member_apps_preview_css') ? em_member_apps_preview_css() : '') . '
.em-ma{max-width:1080px;margin:0 auto;padding:10px clamp(14px,3vw,36px) 48px;color:#cbd5e1;font-family:Inter,system-ui,sans-serif}
.em-ma *{box-sizing:border-box}
.em-ma-hero{text-align:center;margin:8px 0 28px}
.em-ma-eyebrow{display:inline-block;font-size:.68rem;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#67e8f9;padding:6px 14px;border-radius:100px;background:rgba(0,210,255,.08);border:1px solid rgba(0,210,255,.25)}
.em-ma-hero h2{margin:14px 0 6px!important;color:#fff!important;font-family:Inter,system-ui,sans-serif!important;font-size:clamp(1.5rem,3vw,2.1rem)!important;font-weight:900!important;line-height:1.15!important;letter-spacing:-.02em}
.em-ma-hero p{margin:0 auto!important;max-width:560px;color:#94a3b8!important}
.em-ma-list{display:grid;gap:18px}
.em-ma-card{position:relative;overflow:hidden;padding:24px clamp(16px,3vw,28px);border-radius:24px;border:1px solid rgba(255,255,255,.1);background:radial-gradient(120% 100% at 0% 0%,rgba(182,8,201,.16),transparent 55%),linear-gradient(160deg,rgba(255,255,255,.05),rgba(255,255,255,.015)),rgba(11,14,20,.75)}
.em-ma-card.is-you{border-color:rgba(251,191,36,.45);box-shadow:0 24px 60px -34px rgba(251,191,36,.6)}
.em-ma-card.is-approved{border-color:rgba(52,211,153,.4)}
.em-ma-card__head h3{margin:10px 0 4px!important;color:#fff!important;font-family:Inter,system-ui,sans-serif!important;font-size:1.35rem!important;font-weight:900!important;line-height:1.2!important}
.em-ma-meta{margin:0!important;color:#64748b;font-size:.8rem}
.em-ma-meta a{color:#94a3b8!important}
.em-ma-chip{display:inline-flex;align-items:center;gap:7px;font-size:.66rem;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;padding:5px 11px;border-radius:100px;color:#67e8f9;background:rgba(0,210,255,.1);border:1px solid rgba(0,210,255,.28)}
.em-ma-chip i{width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 10px currentColor}
.em-ma-chip.is-you{color:#fbbf24;background:rgba(251,191,36,.1);border-color:rgba(251,191,36,.35)}
.em-ma-chip.is-you i{animation:em-ma-pulse 1.6s ease-in-out infinite}
.em-ma-chip.is-closed{color:#fca5a5;background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.32)}
.em-ma-card.is-closed{opacity:.75}
.em-ma-chip.is-approved{color:#34d399;background:rgba(52,211,153,.1);border-color:rgba(52,211,153,.35)}
@keyframes em-ma-pulse{50%{opacity:.35}}
.em-ma-steps{list-style:none;margin:22px 0 0;padding:0;display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr));gap:0;counter-reset:s}
.em-ma-steps li{position:relative;display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;padding:0 4px}
.em-ma-steps li::before{content:"";position:absolute;top:17px;left:calc(-50% + 22px);right:calc(50% + 22px);height:2px;background:rgba(255,255,255,.1)}
.em-ma-steps li:first-child::before{display:none}
.em-ma-steps li.is-done::before,.em-ma-steps li.is-here::before{background:linear-gradient(90deg,#34d399,#22d3ee)}
.em-ma-dot{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;font-weight:900;font-size:.85rem;color:#64748b;background:rgba(11,14,20,.9);border:2px solid rgba(255,255,255,.12)}
.em-ma-steps li.is-done .em-ma-dot{color:#05070a;background:linear-gradient(135deg,#34d399,#22d3ee);border-color:transparent}
.em-ma-steps li.is-here .em-ma-dot{color:#fff;border-color:#fbbf24;background:rgba(251,191,36,.18);box-shadow:0 0 0 5px rgba(251,191,36,.12)}
.em-ma-card.is-review .em-ma-steps li.is-here .em-ma-dot{border-color:#22d3ee;background:rgba(34,211,238,.16);box-shadow:0 0 0 5px rgba(34,211,238,.1)}
.em-ma-steps b{color:#94a3b8;font-size:.76rem;font-weight:800;line-height:1.3}
.em-ma-steps li.is-here b,.em-ma-steps li.is-done b{color:#fff}
.em-ma-now{margin:22px 0 0!important;padding:12px 16px;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#cbd5e1;font-size:.9rem}
.em-ma-now b{color:#fff}
.em-ma-checks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}
.em-ma-check{display:flex;flex-direction:column;gap:10px;padding:16px;border-radius:18px;text-decoration:none!important;border:1px solid rgba(255,255,255,.1);background:rgba(11,14,20,.55);transition:transform .2s,border-color .2s}
.em-ma-check:hover{transform:translateY(-3px);border-color:rgba(251,191,36,.5)}
.em-ma-check.is-done{border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.06)}
.em-ma-check__n{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:12px;font-weight:900;color:#05070a;background:linear-gradient(135deg,#fbbf24,#f472b6)}
.em-ma-check.is-done .em-ma-check__n{background:linear-gradient(135deg,#34d399,#22d3ee)}
.em-ma-check__t b{display:block;color:#fff;font-size:1rem}
.em-ma-check__t small{display:block;margin-top:3px;color:#94a3b8;font-size:.8rem;line-height:1.45}
.em-ma-check__go{margin-top:auto;color:#fbbf24;font-size:.74rem;font-weight:800;letter-spacing:1px;text-transform:uppercase}
.em-ma-check.is-done .em-ma-check__go{color:#34d399}
.em-ma-submit{margin-top:18px;padding:18px 20px;border-radius:18px;text-align:center;border:1px dashed rgba(251,191,36,.4);background:rgba(251,191,36,.05)}
.em-ma-submit p{margin:0 auto 14px!important;max-width:620px;color:#e2e8f0;font-size:.92rem}
.em-ma-btn{all:unset;box-sizing:border-box;cursor:pointer;display:inline-block;padding:13px 28px;border-radius:100px;font-size:.8rem;font-weight:900;letter-spacing:1.2px;text-transform:uppercase;color:#05070a!important;text-decoration:none!important;background:linear-gradient(135deg,#fbbf24,#f472b6);box-shadow:0 14px 30px -14px rgba(251,191,36,.9);transition:transform .2s,filter .2s}
.em-ma-btn:hover{transform:translateY(-2px);filter:brightness(1.08)}
.em-ma-btn:disabled{opacity:.6;cursor:wait;transform:none}
.em-ma-btn.is-ghost{color:#fff!important;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);box-shadow:none}
.em-ma-msg{min-height:1em;margin:10px 0 0!important;color:#34d399;font-size:.84rem}
.em-ma-msg.is-error{color:#f87171}
.em-ma-note{margin:18px 0 0!important;color:#94a3b8;font-size:.88rem}
.em-ma-note.is-ok{color:#34d399}
.em-ma-empty{text-align:center;padding:30px 20px;border-radius:24px;border:1px solid rgba(255,255,255,.1);background:rgba(11,14,20,.6)}
.em-ma-empty p{color:#94a3b8;margin:0 0 16px!important}
.em-ma-open{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
@media(max-width:760px){.em-ma-checks{grid-template-columns:1fr}.em-ma-steps{grid-template-columns:1fr;gap:10px}.em-ma-steps li{flex-direction:row;text-align:left;padding:0}.em-ma-steps li::before{display:none}.em-ma-dot{width:30px;height:30px;flex:0 0 30px;font-size:.78rem}.em-ma-btn{display:block;width:100%;text-align:center}}
';
}

/* ---------------- Dashboard previews for applicants (owner request 2026-11-12) ----------------
 * While an application is open, the member's Projects board shows the dashboards the role unlocks as locked preview
 * tabs (projects shortcode-group-projects.php, filter psoo_pm_profile_preview_tabs): Project Consultant -> Sales +
 * Contracts, Web Developer -> Contracts. Each preview lists what the dashboard does and shows the application's timeline
 * card, so the member sees where they are and what approval switches on. Tabs the member can already open are skipped. */

/** 'consultant', 'developer' or '' for an application, from its posting title. */
function em_member_apps_role($app)
{
    $t = strtolower((string) ($app['title'] ?? ''));
    $role = strpos($t, 'consultant') !== false ? 'consultant' : (strpos($t, 'developer') !== false ? 'developer' : '');
    return (string) apply_filters('em_member_apps_role', $role, $app);
}

/** Download the Windows desktop app while the application is in review. */
function em_member_apps_desktop_cta()
{
    $url = (string) apply_filters('em_member_apps_desktop_url', 'https://gend.me/download-desktop-client/');
    return '<a class="em-ma-desk" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
        . '<span class="em-ma-desk__ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.5 10.5 4.4v7.1H3zM11.5 4.3 21 3v8.5h-9.5zM3 12.5h7.5v7.1L3 18.5zM11.5 12.5H21V21l-9.5-1.3z"/></svg></span>'
        . '<span class="em-ma-desk__t"><b>' . esc_html__('While you wait: download the Desktop App for Windows', 'email-manager') . '</b>'
        . '<small>' . esc_html__('GenD Web Builder runs and builds web apps on your own computer. Have it set up so you can start on contracts the day you are approved.', 'email-manager') . '</small></span>'
        . '<span class="em-ma-desk__go">' . esc_html__('Download for Windows', 'email-manager') . ' &rarr;</span></a>';
}

/** What each previewed dashboard does. */
function em_member_apps_preview_features($dash_id)
{
    $f = [
        'consult' => [
            ['dashicons-groups', __('Your client groups', 'email-manager'), __('Every web app group you lead as Project Consultant, with its projects, tasks and progress in one terminal.', 'email-manager')],
            ['dashicons-media-document', __('Proposals', 'email-manager'), __('Build proposals from the service catalogue with milestone pricing and send them to customers.', 'email-manager')],
            ['dashicons-chart-line', __('Commissions', 'email-manager'), __('Earn on the sales you close. Every payment and payout is tracked on your Commissions tab.', 'email-manager')],
            ['dashicons-yes-alt', __('Delivery', 'email-manager'), __('Assign web developers to contracts, approve their work and release payouts.', 'email-manager')],
        ],
        'developers' => [
            ['dashicons-portfolio', __('Contract offers', 'email-manager'), __('Pick up task contracts from Project Consultants, with the scope and budget up front.', 'email-manager')],
            ['dashicons-upload', __('Deliverables', 'email-manager'), __('Submit your work against each task and follow every approval in one place.', 'email-manager')],
            ['dashicons-money-alt', __('Payouts', 'email-manager'), __('Payment is held until the customer approves the work, then paid out to you.', 'email-manager')],
            ['dashicons-desktop', __('Desktop App', 'email-manager'), __('Build and run sites on your own computer with GenD Web Builder.', 'email-manager')],
        ],
    ];
    return (array) apply_filters('em_member_apps_preview_features', $f[$dash_id] ?? [], $dash_id);
}

add_filter('psoo_pm_profile_preview_tabs', function ($tabs, $uid, $dash_tabs) {
    if (!class_exists('EM_Postings') || !function_exists('bp_is_my_profile') || !bp_is_my_profile()) return $tabs;
    $uid = (int) $uid;
    $map = (array) apply_filters('em_member_apps_preview_dashboards', ['consultant' => ['consult', 'developers'], 'developer' => ['developers']]);
    $all = function_exists('psoo_dash_get_all') ? (array) psoo_dash_get_all() : [];
    $defaults = [
        'consult'    => [__('Sales', 'email-manager'), 'dashicons-businessperson'],
        'developers' => [__('Contracts', 'email-manager'), 'dashicons-editor-code'],
    ];
    $checks = null;
    foreach (EM_Postings::member_applications($uid) as $app) {
        if (!empty($app['approved']) || !empty($app['rejected'])) continue;
        foreach ((array) ($map[em_member_apps_role($app)] ?? []) as $dash_id) {
            if (isset($tabs[$dash_id]) || isset($dash_tabs[$dash_id])) continue;
            if ($checks === null) $checks = em_member_apps_profile_checks($uid);
            $title = !empty($all[$dash_id]['title']) ? (string) $all[$dash_id]['title'] : ($defaults[$dash_id][0] ?? ucfirst($dash_id));
            $icon  = !empty($all[$dash_id]['icon']) ? (string) $all[$dash_id]['icon'] : ($defaults[$dash_id][1] ?? 'dashicons-lock');
            $tabs[$dash_id] = ['label' => $title, 'icon' => $icon, 'html' => em_member_apps_preview_html($dash_id, $title, $app, $checks, $uid)];
        }
    }
    return $tabs;
}, 10, 3);

function em_member_apps_preview_html($dash_id, $title, $app, $checks, $uid)
{
    static $css = false;
    ob_start();
    if (!$css) {
        $css = true;
        echo '<style>' . em_member_apps_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    <?php
    // Owner 2026-11-12: a blurred screenshot of the real dashboard behind the locked preview.
    $bgs = (array) apply_filters('em_member_apps_preview_backgrounds', [
        'consult'    => 'https://gend.me/wp-content/uploads/2026/11/gend-sales-dashboard-blur.jpg',
        'developers' => 'https://gend.me/wp-content/uploads/2026/11/gend-contracts-dashboard-blur.jpg',
    ]);
    $bg = isset($bgs[$dash_id]) ? (string) $bgs[$dash_id] : '';
    ?>
    <section class="em-ma em-ma-pv<?php echo $bg !== '' ? ' has-bg' : ''; ?>" data-em-ma data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('em_member_apps')); ?>" data-done-url="<?php echo esc_attr(em_member_apps_done_url($uid)); ?>">
        <?php if ($bg !== '') : ?><div class="em-ma-pv__bg" aria-hidden="true" style="background-image:url('<?php echo esc_url($bg); ?>')"></div><?php endif; ?>
        <header class="em-ma-pv__hero">
            <span class="em-ma-pv__lock"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Preview', 'email-manager'); ?></span>
            <h2><?php echo esc_html(sprintf(__('Your %s dashboard', 'email-manager'), $title)); ?></h2>
            <p><?php echo esc_html(sprintf(__('This switches on when your %s application is approved. Here is what you will be able to do.', 'email-manager'), $app['title'])); ?></p>
        </header>
        <div class="em-ma-pv__grid">
            <?php foreach (em_member_apps_preview_features($dash_id) as $f) : ?>
            <div class="em-ma-pv__feat">
                <span class="em-ma-pv__ico"><span class="dashicons <?php echo esc_attr($f[0]); ?>" aria-hidden="true"></span></span>
                <b><?php echo esc_html($f[1]); ?></b>
                <small><?php echo esc_html($f[2]); ?></small>
            </div>
            <?php endforeach; ?>
        </div>
        <h3 class="em-ma-pv__label"><?php esc_html_e('Your application', 'email-manager'); ?></h3>
        <?php echo em_member_apps_card($app, $app['can_submit'] ? $checks : []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </section>
    <?php
    return ob_get_clean();
}

function em_member_apps_preview_css()
{
    return '
.em-ma-pv{max-width:1080px;padding:6px 0 30px}
.em-ma-pv.has-bg{position:relative;isolation:isolate;padding:18px clamp(12px,2vw,22px) 30px;border-radius:28px;overflow:hidden}
.em-ma-pv__bg{position:absolute;inset:0;z-index:-1;background-size:cover;background-position:top center;opacity:.55;transform:scale(1.04);-webkit-mask-image:linear-gradient(180deg,#000 0%,#000 55%,transparent 100%);mask-image:linear-gradient(180deg,#000 0%,#000 55%,transparent 100%)}
.em-ma-pv.has-bg::after{content:"";position:absolute;inset:0;z-index:-1;background:radial-gradient(900px 420px at 50% 0%,rgba(11,14,20,.15),rgba(11,14,20,.65) 70%)}
.em-ma-pv.has-bg .em-ma-pv__hero{background:radial-gradient(520px 260px at 0% 0%,rgba(182,8,201,.26),transparent 70%),radial-gradient(460px 240px at 100% 100%,rgba(0,210,255,.16),transparent 70%),rgba(11,14,20,.6);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.em-ma-pv.has-bg .em-ma-pv__feat{background:rgba(11,14,20,.62);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.em-ma-pv__hero{position:relative;padding:28px clamp(18px,3vw,34px);margin:0 0 18px;border-radius:24px;border:1px solid rgba(255,255,255,.1);background:radial-gradient(520px 260px at 0% 0%,rgba(182,8,201,.22),transparent 70%),radial-gradient(460px 240px at 100% 100%,rgba(0,210,255,.14),transparent 70%),rgba(11,14,20,.72);text-align:left}
.em-ma-pv__hero h2{margin:12px 0 6px!important;color:#fff!important;font-family:Inter,system-ui,sans-serif!important;font-size:clamp(1.35rem,2.6vw,1.9rem)!important;font-weight:900!important;line-height:1.15!important}
.em-ma-pv__hero p{margin:0!important;max-width:620px;color:#94a3b8!important;font-size:.95rem;line-height:1.55}
.em-ma-pv__lock{display:inline-flex;align-items:center;gap:6px;padding:5px 12px 5px 8px;border-radius:100px;font-size:.66rem;font-weight:800;letter-spacing:1.8px;text-transform:uppercase;color:#fbbf24;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.35)}
.em-ma-pv__lock .dashicons{width:16px;height:16px;font-size:16px}
.em-ma-pv__grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 26px}
.em-ma-pv__feat{display:flex;flex-direction:column;gap:6px;padding:18px 16px;border-radius:18px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.035);min-width:0}
.em-ma-pv__feat b{color:#f1f5f9;font-size:.95rem}
.em-ma-pv__feat small{color:#94a3b8;font-size:.8rem;line-height:1.5}
.em-ma-pv__ico{display:grid;place-items:center;width:40px;height:40px;margin-bottom:4px;border-radius:12px;color:#fff;background:linear-gradient(135deg,rgba(182,8,201,.45),rgba(56,189,248,.3));border:1px solid rgba(255,255,255,.12)}
.em-ma-pv__ico .dashicons{width:20px;height:20px;font-size:20px}
.em-ma-pv__label{margin:0 0 10px!important;color:#cbd5e1!important;font-size:.72rem!important;font-weight:800!important;letter-spacing:2px;text-transform:uppercase}
.em-ma-desk{display:flex;align-items:center;gap:14px;margin-top:14px;padding:16px 18px;border-radius:18px;text-decoration:none!important;border:1px solid rgba(56,189,248,.35);background:linear-gradient(135deg,rgba(56,189,248,.12),rgba(182,8,201,.1));color:#e2e8f0!important;transition:transform .2s ease,border-color .2s ease}
.em-ma-desk:hover{transform:translateY(-2px);border-color:rgba(56,189,248,.6)}
.em-ma-desk__ico{flex:0 0 auto;display:grid;place-items:center;width:44px;height:44px;border-radius:12px;background:#0078d4;color:#fff}
.em-ma-desk__ico svg{width:22px;height:22px}
.em-ma-desk__t{display:flex;flex-direction:column;gap:3px;min-width:0;flex:1}
.em-ma-desk__t b{color:#fff;font-size:.95rem}
.em-ma-desk__t small{color:#94a3b8;font-size:.8rem;line-height:1.45}
.em-ma-desk__go{flex:0 0 auto;padding:10px 16px;border-radius:100px;background:#0078d4;color:#fff;font-size:.75rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
@media (max-width:1024px){.em-ma-pv__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:600px){.em-ma-pv__grid{grid-template-columns:1fr}.em-ma-desk{flex-wrap:wrap}.em-ma-desk__go{width:100%;text-align:center}}
.psoo-pm-tab__lock{width:14px!important;height:14px!important;font-size:14px!important;margin-left:4px;opacity:.75;color:#fbbf24}
';
}

// One application per posting + welcome AI Builder Tokens for accounts created through an application (2026-11-13).
require_once __DIR__ . '/application-welcome-tokens.php';
// Google for Jobs: JobPosting structured data, description/canonical and a jobs sitemap for open postings (2026-11-14).
require_once __DIR__ . '/postings-job-schema.php';
