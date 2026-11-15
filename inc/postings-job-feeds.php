<?php
/**
 * Job exposure (owner 2026-11-14), on top of postings-job-schema.php:
 *  - [em_careers]: the /careers/ page (open roles + partner programmes, linked from the footer).
 *  - /jobs.xml: an Indeed-style XML feed for job aggregators (Talent.com, Jooble, Adzuna, Careerjet). Job postings only.
 *  - Google Indexing API: tells Google when a job page opens, changes or closes. Auth = the hub pod's Workload Identity
 *    service account (metadata server token, indexing scope); no key is stored. Off until site option
 *    em_job_indexing_enabled = 1 (that service account must first be an Owner of gend.me in Search Console).
 */
if (!defined('ABSPATH')) exit;

/** Published, open postings, newest first. $jobs_only = only those marked up as jobs (job !== false). */
function em_job_open_postings($jobs_only = false)
{
    if (!class_exists('EM_Postings')) return [];
    $out = [];
    foreach (get_posts(['post_type' => EM_Postings::CPT, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'date', 'order' => 'DESC']) as $p) {
        if (!em_job_is_open($p)) continue;
        if ($jobs_only && empty(em_job_fields($p)['job'])) continue;
        $out[] = $p;
    }
    return $out;
}

/* ── Careers page ─────────────────────────────────────────────────────────────── */

add_shortcode('em_careers', function () {
    $jobs = $partners = '';
    foreach (em_job_open_postings() as $p) {
        $f   = em_job_fields($p);
        $l   = EM_Postings::get_landing($p->ID);
        $a   = !empty($l['accent']) ? $l['accent'] : '#6366f1';
        $b   = !empty($l['accent2']) ? $l['accent2'] : '#8b5cf6';
        $job = !empty($f['job']);
        $card = '<a class="emc-card" href="' . esc_url(trailingslashit(EM_Postings::landing_url($p))) . '" style="--a:' . esc_attr($a) . ';--b:' . esc_attr($b) . ';">'
            . '<span class="emc-card__k">' . esc_html(($job ? __('Remote · Contract', 'email-manager') : __('Remote · Commission only', 'email-manager'))
                . (($o = function_exists('em_posting_org') ? em_posting_org($p->ID) : null) ? ' · ' . $o['name'] : '')) . '</span>'
            . '<h3 class="emc-card__t">' . esc_html($f['title']) . '</h3>'
            . '<p class="emc-card__d">' . esc_html($f['summary']) . '</p>'
            . '<span class="emc-card__chips"><span>' . esc_html__('Free to apply', 'email-manager') . '</span><span>' . esc_html__('Takes 2 minutes', 'email-manager') . '</span>'
            . (!empty($f['noDegree']) ? '<span>' . esc_html__('No degree needed', 'email-manager') . '</span>' : '') . '</span>'
            . '<span class="emc-card__go">' . esc_html($job ? __('View role and apply', 'email-manager') : __('View programme and join', 'email-manager')) . ' &rarr;</span></a>';
        if ($job) $jobs .= $card; else $partners .= $card;
    }
    if ($jobs === '' && $partners === '') {
        return '<div class="emc"><p class="emc-empty">' . esc_html__('There are no open roles right now. Check back soon.', 'email-manager') . '</p></div>';
    }
    return '<div class="emc">'
        . '<header class="emc-hero"><span class="emc-kick">' . esc_html__('Careers at gend.me', 'email-manager') . '</span>'
        . '<h1>' . esc_html__('Work with businesses building on gend.me', 'email-manager') . '</h1>'
        . '<p>' . esc_html__('Remote, flexible work on real web app projects. AI drafts the work; people make it right. Applying takes two minutes and creates your free gend.me account.', 'email-manager') . '</p></header>'
        . ($jobs !== '' ? '<section class="emc-sec"><h2 class="emc-sec__h">' . esc_html__('Open roles', 'email-manager') . '</h2><div class="emc-grid">' . $jobs . '</div></section>' : '')
        . ($partners !== '' ? '<section class="emc-sec"><h2 class="emc-sec__h">' . esc_html__('Partner programmes', 'email-manager') . '</h2><div class="emc-grid">' . $partners . '</div></section>' : '')
        . '</div>';
});

add_action('wp_enqueue_scripts', function () {
    $post = get_queried_object();
    if (!($post instanceof WP_Post) || strpos((string) $post->post_content, '[em_careers') === false) return;
    wp_register_style('em-careers', false, [], '1');
    wp_enqueue_style('em-careers');
    wp_add_inline_style('em-careers', implode('', [
        '.emc{max-width:1180px;margin:0 auto;padding:clamp(72px,9vw,112px) 16px clamp(28px,5vw,64px);font-family:Inter,system-ui,sans-serif;color:#e2e8f0;box-sizing:border-box}',
        '.emc *{box-sizing:border-box}',
        '.emc-hero{text-align:center;max-width:780px;margin:0 auto clamp(32px,5vw,56px)}',
        '.emc-kick{display:inline-block;margin-bottom:14px;padding:6px 14px;border-radius:999px;border:1px solid rgba(95,227,208,.4);color:#5fe3d0;font-size:.74rem;font-weight:800;letter-spacing:2px;text-transform:uppercase}',
        '.emc-hero h1{margin:0 0 14px;color:#fff;font-size:clamp(1.9rem,4vw,3rem);line-height:1.12;letter-spacing:-.02em}',
        '.emc-hero p{margin:0;color:#94a3b8;font-size:1.08rem;line-height:1.65}',
        '.emc-sec{margin-top:clamp(28px,4vw,44px)}',
        '.emc-sec__h{margin:0 0 16px!important;color:#cbd5e1!important;font-size:.8rem!important;font-weight:800;letter-spacing:2px;text-transform:uppercase}',
        '.emc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:18px}',
        '.emc-card{position:relative;display:flex;flex-direction:column;gap:12px;padding:26px 26px 24px;border-radius:22px;text-decoration:none!important;color:#e2e8f0!important;overflow:hidden;',
        'border:1px solid rgba(255,255,255,.09);background:radial-gradient(420px 180px at 0% 0%,color-mix(in srgb,var(--a) 22%,transparent),transparent 70%),#0b0f1c;transition:transform .25s ease,border-color .25s ease,box-shadow .25s ease}',
        '.emc-card:hover,.emc-card:focus-visible{transform:translateY(-4px);border-color:color-mix(in srgb,var(--a) 60%,transparent);box-shadow:0 22px 50px -26px var(--a)}',
        '.emc-card:focus-visible{outline:2px solid var(--a);outline-offset:3px}',
        '.emc-card__k{color:var(--a);font-size:.74rem;font-weight:800;letter-spacing:1.6px;text-transform:uppercase}',
        '.emc-card__t{margin:0!important;color:#fff!important;font-size:1.5rem!important;line-height:1.2}',
        '.emc-card__d{margin:0!important;color:#94a3b8!important;line-height:1.6;flex:1}',
        '.emc-card__chips{display:flex;flex-wrap:wrap;gap:6px}',
        '.emc-card__chips span{padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#cbd5e1;font-size:.76rem;font-weight:600}',
        '.emc-card__go{margin-top:4px;color:#fff;font-weight:800}',
        '.emc-empty{text-align:center;color:#94a3b8}',
        '@media (prefers-reduced-motion:reduce){.emc-card{transition:none}.emc-card:hover{transform:none}}',
    ]));
});

/* ── /jobs.xml ───────────────────────────────────────────────────────────────── */

add_action('parse_request', function () {
    $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/jobs.xml' || !is_main_site() || !class_exists('EM_Postings')) return;
    $cd = function ($v) { return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string) $v) . ']]>'; };
    $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<source>\n<publisher>gend.me</publisher>\n"
        . '<publisherurl>' . esc_url(home_url('/')) . "</publisherurl>\n<lastBuildDate>" . gmdate('D, d M Y H:i:s') . " GMT</lastBuildDate>\n";
    foreach (em_job_open_postings(true) as $p) {
        $f = em_job_fields($p);
        $x .= "<job>\n"
            . '<title>' . $cd($f['title']) . "</title>\n"
            . '<date>' . $cd(get_post_time('D, d M Y H:i:s', true, $p) . ' GMT') . "</date>\n"
            . '<referencenumber>' . $cd('posting-' . $p->ID) . "</referencenumber>\n"
            . '<url>' . $cd(trailingslashit(EM_Postings::landing_url($p))) . "</url>\n"
            . '<company>' . $cd(($o = function_exists('em_posting_org') ? em_posting_org($p->ID) : null) ? $o['name'] : 'gend.me') . "</company>\n"
            . '<city>' . $cd('') . "</city>\n<state>" . $cd('ON') . "</state>\n<country>" . $cd('CA') . "</country>\n<postalcode>" . $cd('') . "</postalcode>\n"
            . '<remotetype>' . $cd('Fully remote') . "</remotetype>\n"
            . '<jobtype>' . $cd('contract') . "</jobtype>\n"
            . '<description>' . $cd($f['description']) . "</description>\n"
            . (!empty($f['noDegree']) ? '<education>' . $cd('No requirements') . "</education>\n" : '')
            . "</job>\n";
    }
    $x .= "</source>\n";
    status_header(200);
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: public, max-age=3600');
    echo $x; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped / CDATA-wrapped above
    exit;
}, 0);

/* ── Google Indexing API ─────────────────────────────────────────────────────── */

function em_job_indexing_enabled()
{
    return (bool) get_site_option('em_job_indexing_enabled', 0) && wp_parse_url(network_home_url(), PHP_URL_HOST) === 'gend.me';
}

function em_job_indexing_token()
{
    $t = get_site_transient('em_job_indexing_token');
    if (is_string($t) && $t !== '') return $t;
    $r = wp_remote_get('http://169.254.169.254/computeMetadata/v1/instance/service-accounts/default/token?scopes=https://www.googleapis.com/auth/indexing',
        ['timeout' => 5, 'headers' => ['Metadata-Flavor' => 'Google']]);
    $j = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($j) || empty($j['access_token'])) return '';
    $ttl = isset($j['expires_in']) ? (int) $j['expires_in'] - 120 : 1800;
    if ($ttl > 60) set_site_transient('em_job_indexing_token', $j['access_token'], min($ttl, 50 * MINUTE_IN_SECONDS));
    return (string) $j['access_token'];
}

/** Send one notification now ($type URL_UPDATED | URL_DELETED). Logs [time, url, type, http code, Google error status]. */
function em_job_indexing_send($url, $type = 'URL_UPDATED')
{
    $type  = $type === 'URL_DELETED' ? 'URL_DELETED' : 'URL_UPDATED';
    $token = em_job_indexing_token();
    $res   = [0, 'no token'];
    if ($token !== '') {
        $r = wp_remote_post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['url' => (string) $url, 'type' => $type]),
        ]);
        if (is_wp_error($r)) {
            $res = [0, $r->get_error_code()];
        } else {
            $b   = json_decode(wp_remote_retrieve_body($r), true);
            $res = [(int) wp_remote_retrieve_response_code($r), isset($b['error']['status']) ? (string) $b['error']['status'] : ''];
        }
    }
    $log = (array) get_site_option('em_job_indexing_log', []);
    array_unshift($log, ['t' => time(), 'url' => (string) $url, 'type' => $type, 'code' => $res[0], 'err' => $res[1]]);
    update_site_option('em_job_indexing_log', array_slice($log, 0, 50));
    return $res;
}
add_action('em_job_indexing_ping', 'em_job_indexing_send', 10, 2);

/** Queue a notification for one posting's page: 30 s later, at most once per URL and type per 10 minutes. */
function em_job_indexing_queue($posting_id)
{
    if (!em_job_indexing_enabled() || !class_exists('EM_Postings')) return;
    $p = get_post((int) $posting_id);
    if (!$p || $p->post_type !== EM_Postings::CPT || $p->post_status === 'auto-draft') return;
    if (empty(em_job_fields($p)['job'])) return;   // the Indexing API is for job pages only
    $url  = trailingslashit(EM_Postings::landing_url($p));
    $type = ($p->post_status === 'publish' && em_job_is_open($p)) ? 'URL_UPDATED' : 'URL_DELETED';
    $key  = 'em_jix_' . md5($url . $type);
    if (get_site_transient($key)) return;
    set_site_transient($key, 1, 10 * MINUTE_IN_SECONDS);
    wp_schedule_single_event(time() + 30, 'em_job_indexing_ping', [$url, $type]);
}

add_action('transitioned_post_status', function ($new, $old, $post) {
    if ($post instanceof WP_Post && $post->post_type === 'em_posting') em_job_indexing_queue($post->ID);
}, 30, 3);
foreach (['added_post_meta', 'updated_post_meta'] as $em_jix_hook) {
    add_action($em_jix_hook, function ($mid, $object_id, $key) {
        if (in_array($key, ['_em_posting_status', '_em_posting_boards', '_em_posting_job'], true)) em_job_indexing_queue((int) $object_id);
    }, 30, 3);
}
// The posting's own landing page (edited in the block editor) changed, so the job page changed.
add_action('post_updated', function ($id, $after) {
    if (!($after instanceof WP_Post) || $after->post_type !== 'page') return;
    $posting = (int) get_post_meta($id, '_em_posting_owner', true);
    if ($posting > 0) em_job_indexing_queue($posting);
}, 30, 2);
