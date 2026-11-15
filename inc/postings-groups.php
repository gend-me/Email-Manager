<?php
/**
 * Postings belong to groups (owner 2026-11-14: Organization > People > Careers).
 *
 * - Every posting belongs to a group: post meta _em_posting_group, else site option em_postings_default_group (on
 *   gend.me: the gend.me group, so its own roles stay where they were). Site admins pick the group in the posting
 *   editor (Talk Flows). Group postings are hub postings, so they are on the gend.me careers page, /jobs.xml and
 *   Google for Jobs automatically; their job listings name the group as the hiring organization.
 * - Careers tab (projects plugin People > Careers, via psoo_people_careers_panel / _count): the group's open roles for
 *   everyone who can open the page; group admins also get the full Applicants manager (the Talk Flows one, em-ats.js),
 *   limited to their group's postings.
 * - Group scope for the applicant endpoints (em_ats_group_scope): a request carrying em_group + a group nonce from a
 *   group manager (filter em_ats_can_manage_group: site admins, group admins, the Project Consultant) may list, view,
 *   note, rate, reject/restore and move applicants of THAT group's postings only. Approving or moving is refused when
 *   a posting's steps would grant a role with admin-level capabilities (only site admins can run those). Confirming an
 *   applicant's email for them stays site-admin only.
 *
 * Loaded from postings-ats.php.
 *
 * @package email-manager
 */
if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------------ which group a posting belongs to */

function em_posting_group_id($posting_id)
{
    if (!class_exists('EM_Postings') || get_post_type((int) $posting_id) !== EM_Postings::CPT) return 0;
    $g = (int) get_post_meta((int) $posting_id, '_em_posting_group', true);
    return $g ?: (int) get_site_option('em_postings_default_group', 0);
}

/** The group's postings ($open_only: published and accepting applications). */
function em_group_postings($gid, $open_only = false)
{
    $out = [];
    if (!class_exists('EM_Postings') || !$gid) return $out;
    foreach (EM_Postings::get_postings() as $p) {
        if (em_posting_group_id($p->ID) !== (int) $gid) continue;
        if ($open_only && ($p->post_status !== 'publish' || (get_post_meta($p->ID, EM_Postings::META_STATUS, true) ?: 'open') !== 'open')) continue;
        $out[] = $p;
    }
    return $out;
}

/** Groups a site admin can assign a posting to (id => name). */
function em_posting_group_choices()
{
    if (!function_exists('groups_get_groups')) return [];
    $r = groups_get_groups(['per_page' => 500, 'orderby' => 'name', 'order' => 'ASC', 'show_hidden' => true, 'update_meta_cache' => false]);
    $out = [];
    foreach ((array) ($r['groups'] ?? []) as $g) $out[(int) $g->id] = html_entity_decode((string) $g->name, ENT_QUOTES, 'UTF-8');
    return $out;
}

/** Hiring organization for job listings: the group, for postings of a group other than the site's own. */
function em_posting_org($posting_id)
{
    $gid = em_posting_group_id($posting_id);
    $own = (int) get_site_option('em_postings_default_group', 0);
    if (!$gid || $gid === $own || !function_exists('groups_get_group')) return null;
    $g = groups_get_group($gid);
    if (empty($g->id)) return null;
    $logo = function_exists('bp_core_fetch_avatar') ? (string) bp_core_fetch_avatar(['item_id' => $gid, 'object' => 'group', 'type' => 'full', 'html' => false]) : '';
    return [
        'name' => html_entity_decode((string) $g->name, ENT_QUOTES, 'UTF-8'),
        'url'  => function_exists('bp_get_group_url') ? bp_get_group_url($g) : bp_get_group_permalink($g),
        'logo' => $logo,
    ];
}

/* ------------------------------------------------------------------ who manages a group's applicants */

function em_group_can_manage_careers($uid, $gid)
{
    $uid = (int) $uid; $gid = (int) $gid;
    if (!$uid || !$gid) return false;
    $ok = user_can($uid, 'manage_options')
        || (function_exists('psoo_ga_is_admin') && psoo_ga_is_admin($uid, $gid))
        || (function_exists('groups_is_user_admin') && groups_is_user_admin($uid, $gid));
    return (bool) apply_filters('em_ats_can_manage_group', $ok, $uid, $gid);
}

/** Can a group manager approve / move applicants of this posting? Not when a step grants an admin-level role. */
function em_posting_group_safe($posting_id)
{
    $roles = [];
    foreach ((array) EM_Postings::get_process($posting_id) as $s) {
        if (is_array($s) && empty($s['final']) && !empty($s['role'])) $roles[] = (string) $s['role'];
    }
    $c = EM_Postings::get_contract($posting_id);
    if (!empty($c['role'])) $roles[] = (string) $c['role'];
    $risky = ['manage_options', 'manage_network', 'promote_users', 'create_users', 'edit_users', 'delete_users', 'install_plugins', 'activate_plugins',
        'edit_plugins', 'edit_themes', 'switch_themes', 'edit_theme_options', 'unfiltered_html', 'manage_woocommerce', 'export', 'import'];
    foreach (array_unique($roles) as $slug) {
        $r = get_role($slug);
        if (!$r) continue;
        foreach ($risky as $cap) if (!empty($r->capabilities[$cap])) return false;
    }
    return true;
}

/**
 * The group this applicant request is scoped to (0 = none / not allowed). Only for the applicant actions; postings
 * actions also need the posting to belong to the group and be safe for group approval.
 */
function em_ats_group_scope()
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = 0;
    $gid = absint($_POST['em_group'] ?? 0);
    if (!$gid || !wp_verify_nonce((string) ($_POST['gnonce'] ?? ''), 'em_ats_group_' . $gid)) return 0;
    if (!em_group_can_manage_careers(get_current_user_id(), $gid)) return 0;
    $action = sanitize_key($_REQUEST['action'] ?? '');
    $posting_ops = ['em_advance_posting_applicant', 'em_bulk_advance_posting_applicants', 'em_complete_posting_step'];
    if (!in_array($action, array_merge(['em_ats_list', 'em_ats_detail', 'em_ats_action'], $posting_ops), true)) return 0;
    if (in_array($action, $posting_ops, true)) {
        $pid = absint($_POST['posting_id'] ?? 0);
        if (!$pid || em_posting_group_id($pid) !== $gid) return 0;
        if (!current_user_can('manage_options') && !em_posting_group_safe($pid)) return 0;
    }
    return $memo = $gid;
}

function em_ats_submission_in_group($sid, $gid)
{
    $pid = (int) get_post_meta((int) $sid, EM_Postings::SUBMISSION_TAG, true);
    return $pid && em_posting_group_id($pid) === (int) $gid;
}

/* ------------------------------------------------------------------ Careers tab */

/** Open applications (not rejected, not yet approved) across the group's postings. */
function em_group_open_application_count($gid)
{
    $n = 0;
    foreach (em_group_postings($gid) as $p) {
        $last = count((array) EM_Postings::get_process($p->ID)) - 1;
        foreach (EM_Postings::get_applicants($p->ID) as $a) {
            if (EM_Postings::is_rejected((int) $a['id'])) continue;
            if ($last >= 0 && (int) $a['step_index'] >= $last) continue;
            $n++;
        }
    }
    return $n;
}

/** Tab count: open applications for the group's managers, open roles for everyone else. */
add_filter('psoo_people_careers_count', function ($n, $gid) {
    $gid = (int) $gid;
    $mgr = em_group_can_manage_careers(get_current_user_id(), $gid);
    $key = 'em_careers_n_' . $gid . '_' . ($mgr ? 'm' : 'v');
    $v = get_transient($key);
    if ($v === false) {
        $v = $mgr ? em_group_open_application_count($gid) : count(em_group_postings($gid, true));
        set_transient($key, $v, MINUTE_IN_SECONDS);
    }
    return (int) $v;
}, 10, 2);

add_filter('psoo_people_careers_panel', function ($html, $gid) {
    return em_careers_group_panel((int) $gid);
}, 10, 2);

function em_careers_page_url()
{
    $id = (int) get_option('em_careers_page_id');
    if (!$id) { $p = get_page_by_path('careers'); $id = $p ? (int) $p->ID : 0; }
    return $id && get_post_status($id) === 'publish' ? get_permalink($id) : '';
}

function em_careers_group_panel($gid)
{
    $gid = (int) $gid;
    $uid = get_current_user_id();
    $mgr = em_group_can_manage_careers($uid, $gid);
    $site_admin = current_user_can('manage_options');
    $posts = em_group_postings($gid, !$mgr);
    $careers = em_careers_page_url();
    $ver = (defined('EMAIL_MANAGER_VERSION') ? EMAIL_MANAGER_VERSION : '1') . '-' . (int) @filemtime(EMAIL_MANAGER_PATH . 'assets/em-careers-group.css');
    wp_enqueue_style('em-careers-group', EMAIL_MANAGER_URL . 'assets/em-careers-group.css', [], $ver);
    $labels = ['open' => __('Open', 'email-manager'), 'closed' => __('Closed', 'email-manager'), 'draft' => __('Draft', 'email-manager')];
    ob_start();
    ?>
    <div class="emcg gs-vbg-keep">
        <div class="emcg__head">
            <div>
                <h3><?php esc_html_e('Open roles', 'email-manager'); ?></h3>
                <p><?php esc_html_e('Every role posted for this group is listed on the gend.me careers page and shared with job boards and Google for Jobs automatically.', 'email-manager'); ?></p>
            </div>
            <div class="emcg__tools">
                <?php if ($careers) : ?><a class="emcg__btn" href="<?php echo esc_url($careers); ?>" target="_blank" rel="noopener"><?php esc_html_e('Careers page', 'email-manager'); ?> &nearr;</a><?php endif; ?>
                <?php if ($site_admin) : ?><a class="emcg__btn is-main" href="<?php echo esc_url(admin_url('admin.php?page=talk-flows#tab=applications')); ?>"><?php esc_html_e('+ New posting', 'email-manager'); ?></a><?php endif; ?>
            </div>
        </div>
        <?php if (!$posts) : ?>
            <p class="emcg__empty"><?php echo esc_html($mgr
                ? ($site_admin ? __('No roles are posted for this group yet. Create a posting in Talk Flows and choose this group under Group.', 'email-manager')
                    : __('No roles are posted for this group yet. Roles are published by the gend.me team: ask your Project Consultant to post one.', 'email-manager'))
                : __('There are no open roles in this group right now.', 'email-manager')); ?></p>
        <?php else : ?>
            <div class="emcg__roles">
                <?php foreach ($posts as $p) :
                    $st = get_post_meta($p->ID, EM_Postings::META_STATUS, true) ?: 'open';
                    if ($p->post_status === 'draft') $st = 'draft';
                    $f = function_exists('em_job_fields') ? em_job_fields($p) : ['title' => $p->post_title, 'summary' => ''];
                    $n = $mgr ? count(EM_Postings::get_applicants($p->ID)) : 0;
                    $l = EM_Postings::get_landing($p->ID);
                ?>
                <article class="emcg__role" style="--a:<?php echo esc_attr(!empty($l['accent']) ? $l['accent'] : '#6366f1'); ?>">
                    <div class="emcg__rtop"><span class="emcg__st is-<?php echo esc_attr($st); ?>"><?php echo esc_html($labels[$st] ?? $st); ?></span><?php if ($mgr) : ?><span class="emcg__n"><?php echo esc_html(sprintf(_n('%d applicant', '%d applicants', $n, 'email-manager'), $n)); ?></span><?php endif; ?></div>
                    <h4><?php echo esc_html($f['title'] ?: $p->post_title); ?></h4>
                    <?php if (!empty($f['summary'])) : ?><p><?php echo esc_html(wp_html_excerpt((string) $f['summary'], 180, '…')); ?></p><?php endif; ?>
                    <a class="emcg__go" href="<?php echo esc_url(trailingslashit(EM_Postings::landing_url($p))); ?>" target="_blank" rel="noopener"><?php esc_html_e('View role', 'email-manager'); ?> &rarr;</a>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($mgr && $posts && class_exists('EM_Postings_ATS')) echo EM_Postings_ATS::render_group($gid); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ------------------------------------------------------------------ posting editor: Group (site admins) */

add_filter('em_posting_payload', function ($payload, $posting) {
    $payload['group_id'] = $posting ? (int) get_post_meta($posting->ID, '_em_posting_group', true) : 0;
    return $payload;
}, 10, 2);

add_action('em_posting_saved', function ($posting_id) {
    if (!current_user_can('manage_options') || !isset($_POST['group_id'])) return;
    $gid = absint($_POST['group_id']);
    if ($gid && function_exists('groups_get_group') && !empty(groups_get_group($gid)->id)) update_post_meta($posting_id, '_em_posting_group', $gid);
    else delete_post_meta($posting_id, '_em_posting_group');
});

function em_posting_group_field()
{
    $choices = em_posting_group_choices();
    if (!$choices) return;
    $default = (int) get_site_option('em_postings_default_group', 0);
    ?>
    <label class="em-pf-field em-pf-head__status">
        <span class="em-pf-label"><?php esc_html_e('Group', 'email-manager'); ?></span>
        <select name="group_id">
            <option value="0"><?php echo esc_html($default && isset($choices[$default]) ? sprintf(__('— Default (%s) —', 'email-manager'), $choices[$default]) : __('— No group —', 'email-manager')); ?></option>
            <?php foreach ($choices as $id => $name) : ?><option value="<?php echo (int) $id; ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?>
        </select>
    </label>
    <?php
}

/** Google for Jobs: a group's role names the group as the hiring organization. */
add_filter('em_job_posting_node', function ($node, $posting) {
    $o = em_posting_org($posting->ID);
    if (!$o) return $node;
    $node['hiringOrganization'] = array_filter(['@type' => 'Organization', 'name' => $o['name'], 'sameAs' => $o['url'], 'logo' => $o['logo']]);
    return $node;
}, 10, 2);
