<?php
/**
 * Google for Jobs (owner 2026-11-14): every open posting's /apply/{slug} page describes itself as a job.
 *  - JobPosting structured data (merged into the site's one JSON-LD graph via blog-manager's bm_seo_graph filter).
 *    The /apply/ route is a rewrite on the home query, so the graph used to call it the home CollectionPage with a
 *    list of blog posts; it becomes the page's own WebPage + JobPosting + breadcrumb.
 *  - The page's description / og:url / og:title (gend-society seo-meta) and a canonical link.
 *  - A "jobs" sitemap (wp-sitemap-jobs-1.xml) listing open postings with lastmod.
 * Closed postings already print noindex and get none of this. Per-posting values: post meta `_em_posting_job` (array) over
 * the defaults below; see em_job_fields().
 */
if (!defined('ABSPATH')) exit;

/** The open posting this request renders (/apply/{slug} or ?em_posting=), or null. */
function em_job_current_posting()
{
    static $done = false, $posting = null;
    if ($done) return $posting;
    $slug = get_query_var('em_posting');
    if (!$slug && isset($_GET['em_posting'])) $slug = sanitize_title(wp_unslash($_GET['em_posting']));
    if (!$slug || !class_exists('EM_Postings')) return null;   // not cached: query vars may not be parsed yet
    $done = true;
    $p = get_page_by_path($slug, OBJECT, EM_Postings::CPT);
    if ($p && $p->post_status === 'publish' && em_job_is_open($p)) $posting = $p;
    return $posting;
}

function em_job_is_open($posting)
{
    return (get_post_meta($posting->ID, EM_Postings::META_STATUS, true) ?: 'open') === 'open';
}

/** Defaults for gend.me's three postings (hub ids); other postings derive everything from their own data. */
function em_job_defaults($posting_id)
{
    $gendme = wp_parse_url(network_home_url(), PHP_URL_HOST) === 'gend.me';
    $d = $gendme ? [
        154337 => [
            'title'   => 'Web Developer',
            'summary' => 'Remote contract. Businesses on gend.me order fixed-scope web work: AI drafts it, you finish it properly in their WordPress web app, and you are paid $15 CAD plus 500 AI Builder Tokens for every credit of approved work.',
            'board'   => 'linkedin-wd2611',
            'occupationalCategory' => '15-1254.00 Web Developers',
        ],
        153620 => [
            'title'   => 'Project Consultant',
            'summary' => 'Remote contract. Run web app projects for businesses on gend.me: plan the work, AI drafts it, network Web Developers build it, and you approve it. Earn 10% of every Project Task plus referral commissions.',
            'board'   => 'linkedin-pc2611',
            'occupationalCategory' => '13-1111.00 Management Analysts',
        ],
        153626 => [
            'title'   => 'Network Sales Partner',
            'summary' => 'Remote, commission only, approved instantly. Every business you bring to gend.me pays you every month it stays: 10% to 20% on your own referrals and up to 20% more on your team\'s, paid in Canadian dollars.',
            'board'   => 'linkedin-ns2611',
            'job'     => false,   // owner 2026-11-14: commission-only partner programme, not listed in Google Jobs (normal search only)
            'occupationalCategory' => '41-3091.00 Sales Representatives of Services',
        ],
    ] : [];
    return $d[(int) $posting_id] ?? [];
}

/** Everything the JobPosting needs for one posting. */
function em_job_fields($posting)
{
    $f = wp_parse_args((array) get_post_meta($posting->ID, '_em_posting_job', true), em_job_defaults($posting->ID));
    $f = wp_parse_args($f, [
        'title'          => $posting->post_title,
        'job'            => true,    // false = no JobPosting (page still gets its own description, canonical and sitemap entry)
        'summary'        => '',
        'board'          => '',
        'employmentType' => 'CONTRACTOR',
        'countries'      => (array) get_site_option('em_job_countries', ['Canada', 'United States', 'United Kingdom', 'Australia']),   // country names applicants may live in
        'occupationalCategory' => '',
        'baseSalary'     => null,   // ['currency' => 'CAD', 'value' => 15, 'unit' => 'HOUR'] when the pay has a time unit
        'noDegree'       => true,    // no degree or diploma asked for (Google's "No degree" filter); experience is reviewed, so not claimed
        'workHours'      => 'Flexible, remote and asynchronous, no minimum hours',
    ]);
    // The description: the posting's own job-board copy (same facts as the page), as simple HTML.
    $boards = (array) get_post_meta($posting->ID, EM_Postings::META_BOARDS, true);
    $body = '';
    foreach ($boards as $b) {
        if (!is_array($b) || ($b['kind'] ?? '') !== 'job' || trim((string) ($b['body'] ?? '')) === '') continue;
        if ($f['board'] === '' || ($b['id'] ?? '') === $f['board']) { $body = (string) $b['body']; break; }
    }
    $f['description'] = $body !== '' ? em_job_text_to_html($body) : wpautop(esc_html($f['summary']));
    if ($f['summary'] === '') $f['summary'] = wp_trim_words(wp_strip_all_tags($f['description']), 40, '…');
    return $f;
}

/** Plain job-board text -> <p> paragraphs and <ul> lists (lines starting with •, -, *). */
function em_job_text_to_html($text)
{
    $out = '';
    $list = false;
    foreach (preg_split('/\R/u', trim((string) $text)) as $line) {
        $line = trim($line);
        if ($line === '') { if ($list) { $out .= '</ul>'; $list = false; } continue; }
        if (preg_match('/^(?:•|-|\*)\s*(.+)$/u', $line, $m)) {
            if (!$list) { $out .= '<ul>'; $list = true; }
            $out .= '<li>' . esc_html($m[1]) . '</li>';
            continue;
        }
        if ($list) { $out .= '</ul>'; $list = false; }
        $out .= '<p>' . esc_html($line) . '</p>';
    }
    if ($list) $out .= '</ul>';
    return $out;
}

/** The JobPosting node (Google for Jobs). */
function em_job_node($posting, $url)
{
    $f = em_job_fields($posting);
    $org = home_url('/#organization');
    $node = [
        '@type'          => 'JobPosting',
        '@id'            => $url . '#job',
        'title'          => $f['title'],
        'description'    => $f['description'],
        'datePosted'     => get_post_time('c', true, $posting),
        'employmentType' => $f['employmentType'],
        'hiringOrganization' => [
            '@type'  => 'Organization',
            '@id'    => $org,
            'name'   => 'gend.me',
            'sameAs' => home_url('/'),
            'logo'   => wp_parse_url(network_home_url(), PHP_URL_HOST) === 'gend.me' ? home_url('/wp-content/uploads/2026/04/gend-Logo.png') : get_site_icon_url(512),
        ],
        'jobLocationType' => 'TELECOMMUTE',
        'applicantLocationRequirements' => array_values(array_map(function ($c) {
            return ['@type' => 'Country', 'name' => (string) $c];
        }, array_filter((array) $f['countries']))),
        'directApply'    => true,
        'url'            => $url,
        'identifier'     => ['@type' => 'PropertyValue', 'name' => 'gend.me', 'value' => 'posting-' . (int) $posting->ID],
        'mainEntityOfPage' => ['@id' => $url . '#webpage'],
    ];
    if ($f['occupationalCategory'] !== '') $node['occupationalCategory'] = $f['occupationalCategory'];
    if (!empty($f['noDegree'])) $node['educationRequirements'] = 'no requirements';
    if ($f['workHours'] !== '') $node['workHours'] = $f['workHours'];
    if (is_array($f['baseSalary']) && isset($f['baseSalary']['value'], $f['baseSalary']['unit'])) {
        $node['baseSalary'] = [
            '@type' => 'MonetaryAmount', 'currency' => (string) ($f['baseSalary']['currency'] ?? 'CAD'),
            'value' => ['@type' => 'QuantitativeValue', 'value' => (float) $f['baseSalary']['value'], 'unitText' => (string) $f['baseSalary']['unit']],
        ];
    }
    return (array) apply_filters('em_job_posting_node', $node, $posting);
}

/* ── JSON-LD: replace the home CollectionPage on /apply/ pages ───────────────── */
add_filter('bm_seo_graph', function ($graph) {
    $posting = em_job_current_posting();
    if (!$posting) return $graph;
    $url = EM_Postings::landing_url($posting);
    $url = trailingslashit($url);
    $f = em_job_fields($posting);
    unset($graph['list'], $graph['faq'], $graph['software']);
    $graph['page'] = [
        '@type' => 'WebPage', '@id' => $url . '#webpage', 'url' => $url,
        'name' => $posting->post_title . ' — ' . get_bloginfo('name'),
        'description' => $f['summary'],
        'isPartOf' => ['@id' => home_url('/#website')],
        'inLanguage' => get_bloginfo('language'),
        'datePublished' => get_post_time('c', true, $posting), 'dateModified' => get_post_modified_time('c', true, $posting),
        'breadcrumb' => ['@id' => $url . '#breadcrumb'],
    ];
    $graph['breadcrumb'] = [
        '@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'gend.me', 'item' => home_url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $f['title']],
        ],
    ];
    if (!empty($f['job'])) {
        $graph['page']['about'] = ['@id' => $url . '#job'];
        $graph['job'] = em_job_node($posting, $url);
    }
    return $graph;
}, 20);

/* ── description, og:* and canonical ─────────────────────────────────────────── */
add_filter('gend_society_seo_current', function ($seo) {
    $posting = em_job_current_posting();
    if (!$posting) return $seo;
    $f = em_job_fields($posting);
    $seo['title'] = $f['title'] . ' (' . __('Remote', 'email-manager') . ') — ' . get_bloginfo('name');
    $seo['description'] = function_exists('gend_society_seo_trim') ? gend_society_seo_trim($f['summary']) : wp_html_excerpt($f['summary'], 160, '…');
    $seo['url'] = trailingslashit(EM_Postings::landing_url($posting));
    $seo['type'] = 'website';
    return $seo;
});
add_action('wp_head', function () {
    $posting = em_job_current_posting();
    if ($posting) echo '<link rel="canonical" href="' . esc_url(trailingslashit(EM_Postings::landing_url($posting))) . '">' . "\n";
}, 2);

/* ── sitemap: wp-sitemap-jobs-1.xml ──────────────────────────────────────────── */
add_action('init', function () {
    if (!function_exists('wp_register_sitemap_provider') || !class_exists('WP_Sitemaps_Provider') || !class_exists('EM_Postings')) return;
    if (!class_exists('EM_Jobs_Sitemap_Provider')) {
        class EM_Jobs_Sitemap_Provider extends WP_Sitemaps_Provider
        {
            public function __construct() { $this->name = 'jobs'; $this->object_type = 'jobs'; }
            private function open_postings()
            {
                return array_values(array_filter(get_posts([
                    'post_type' => EM_Postings::CPT, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'ID', 'order' => 'ASC',
                ]), 'em_job_is_open'));
            }
            public function get_url_list($page_num, $object_subtype = '')
            {
                if ((int) $page_num > 1) return [];
                $out = [];
                foreach ($this->open_postings() as $p) {
                    $out[] = ['loc' => trailingslashit(EM_Postings::landing_url($p)), 'lastmod' => get_post_modified_time('c', true, $p)];
                }
                return $out;
            }
            public function get_max_num_pages($object_subtype = '') { return $this->open_postings() ? 1 : 0; }
        }
    }
    wp_register_sitemap_provider('jobs', new EM_Jobs_Sitemap_Provider());
}, 20);

// Careers page, aggregator feed and Google Indexing API notifications (2026-11-14).
require_once __DIR__ . '/postings-job-feeds.php';
