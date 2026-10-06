<?php
/**
 * Member Inbox: WP user ↔ inbox address linking (slice 2e).
 *
 * On user registration we materialize an inbox address from the user's
 * login + the container's `em_inbox_default_domain` option and pin it to
 * user_meta (`em_inbox_address`). When a webhook arrives for that
 * address we look up the user_id and stamp it on the raw row, the
 * threading worker copies it forward to the thread row, and permission
 * checks fall back from "current_user owns thread" before the
 * "current_user is admin" or "email matches" branches.
 *
 * BACKFILL: existing installs ship without owner_user_id stamped on
 * historical threads — run `wp em-inbox backfill` once after deploy.
 *
 * @package EmailManager
 * @since   1.1.0
 */

defined('ABSPATH') || exit;

define('EM_INBOX_THREAD_DB_VERSION_2E', '1.1.0');

/* -------------------------------------------------------------------------
 * Schema migration: add owner_user_id to threads
 * ------------------------------------------------------------------------- */

function em_inbox_user_maybe_migrate_threads() {
    if (get_option('em_inbox_thread_db_version') === EM_INBOX_THREAD_DB_VERSION_2E) return;
    global $wpdb;
    $table = $wpdb->prefix . 'gdc_inbox_threads';
    $has_col = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM $table LIKE %s", 'owner_user_id'
    ));
    if (! $has_col) {
        $wpdb->query("ALTER TABLE $table
            ADD COLUMN owner_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ADD KEY idx_owner (owner_user_id)");
    }
    update_option('em_inbox_thread_db_version', EM_INBOX_THREAD_DB_VERSION_2E);
}
add_action('admin_init',    'em_inbox_user_maybe_migrate_threads');
add_action('rest_api_init', 'em_inbox_user_maybe_migrate_threads');  // REST never fires admin_init

/* -------------------------------------------------------------------------
 * Address resolution
 * ------------------------------------------------------------------------- */

/**
 * Return the configured default inbox domain for this container (e.g.
 * "mail-test.gend.me"). Empty string means user provisioning is dormant.
 */
function em_inbox_default_domain() {
    return strtolower(trim((string) get_option('em_inbox_default_domain', '')));
}

/**
 * Synthesize a user's inbox address. Pure function — no DB write. Returns
 * empty string when no default domain is set.
 */
function em_inbox_address_for_user(WP_User $u) {
    $domain = em_inbox_default_domain();
    if ($domain === '') return '';
    // user_login is unique in WP, ASCII-safe enough for the LHS of an
    // email address (WP enforces sanitize_user). Lowercased so the
    // recipient-domain comparison stays predictable.
    return strtolower($u->user_login) . '@' . $domain;
}

/**
 * Look up a user by inbox address (em_inbox_address user_meta). Returns
 * WP_User or null. Cached in-process for the duration of the request to
 * avoid hammering the DB during a multi-recipient threading pass.
 */
function em_inbox_user_by_address($address) {
    static $cache = array();
    $address = strtolower(trim((string) $address));
    if ($address === '') return null;
    if (array_key_exists($address, $cache)) return $cache[$address];

    $users = get_users(array(
        'meta_key'   => 'em_inbox_address',
        'meta_value' => $address,
        'number'     => 1,
        'fields'     => 'all',
    ));
    $cache[$address] = $users ? $users[0] : null;
    return $cache[$address];
}

/* -------------------------------------------------------------------------
 * Auto-provisioning hook
 * ------------------------------------------------------------------------- */

add_action('user_register', 'em_inbox_provision_on_register', 20, 1);
function em_inbox_provision_on_register($user_id) {
    $u = get_user_by('id', $user_id);
    if (! $u) return;
    if (get_user_meta($user_id, 'em_inbox_address', true)) return;  // already set
    $address = em_inbox_address_for_user($u);
    if ($address === '') return;  // default domain not configured
    update_user_meta($user_id, 'em_inbox_address', $address);
}

/* -------------------------------------------------------------------------
 * Permission gate (used by 2b.2 + 2c handlers)
 * ------------------------------------------------------------------------- */

/**
 * Returns true if the current user can read the given inbox address.
 *   - admin (manage_options) always passes
 *   - the user whose em_inbox_address matches passes
 *   - LEGACY: the user whose user_email matches passes (slice 2b.2/2c
 *     behavior — kept as a fallback so the admin user can still bypass
 *     via their gend.me email even before user provisioning is enabled)
 */
function em_inbox_current_user_can_read_address($address) {
    if (current_user_can('manage_options')) return true;
    $u = wp_get_current_user();
    if (! $u || $u->ID === 0) return false;
    $assigned = get_user_meta($u->ID, 'em_inbox_address', true);
    if ($assigned && strcasecmp($assigned, $address) === 0) return true;
    if ($u->user_email && strcasecmp($u->user_email, $address) === 0) return true;
    // Slice 2ee: grant-based delegation. Hook lets other modules extend
    // the read predicate without touching this function.
    return (bool) apply_filters('em_inbox_can_read_address', false, $address, (int) $u->ID);
}

/* -------------------------------------------------------------------------
 * Threading-side stamp: copy owner_user_id from raw → thread on insert
 * ------------------------------------------------------------------------- */

add_action('em_inbox_thread_created', 'em_inbox_stamp_owner_on_thread', 10, 2);
function em_inbox_stamp_owner_on_thread($thread_id, $raw_row) {
    if (! $thread_id) return;
    $u = em_inbox_user_by_address($raw_row['recipient'] ?? '');
    if (! $u) return;
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'gdc_inbox_threads',
        array('owner_user_id' => $u->ID),
        array('id' => $thread_id, 'owner_user_id' => null),  // never overwrite
        array('%d'), array('%d', '%d')
    );
}

/* -------------------------------------------------------------------------
 * Backfill — wp-cli only (operator runs once after deploy)
 * ------------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('em-inbox', 'EM_Inbox_CLI');
}

class EM_Inbox_CLI {

    /**
     * Backfill em_inbox_address for every user, and owner_user_id for
     * every thread whose recipient resolves to one of those users.
     *
     * ## EXAMPLES
     *   wp em-inbox backfill
     *   wp em-inbox backfill --dry-run
     */
    public function backfill($args, $assoc) {
        $dry = isset($assoc['dry-run']);
        $domain = em_inbox_default_domain();
        if ($domain === '') {
            \WP_CLI::error('em_inbox_default_domain not set. Run: wp option update em_inbox_default_domain mail-test.gend.me');
        }
        \WP_CLI::log("Default domain: $domain  " . ($dry ? '[DRY-RUN]' : ''));

        global $wpdb;
        $user_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->users}");
        $provisioned = 0;
        foreach ($user_ids as $uid) {
            $u = get_user_by('id', (int) $uid);
            if (! $u) continue;
            if (get_user_meta($u->ID, 'em_inbox_address', true)) continue;
            $addr = em_inbox_address_for_user($u);
            if ($addr === '') continue;
            if (! $dry) update_user_meta($u->ID, 'em_inbox_address', $addr);
            $provisioned++;
        }
        \WP_CLI::log("Users provisioned: $provisioned");

        $threads = $wpdb->get_results(
            "SELECT id, inbox_address FROM {$wpdb->prefix}gdc_inbox_threads
             WHERE owner_user_id IS NULL", ARRAY_A
        );
        $stamped = 0;
        foreach ($threads as $t) {
            $u = em_inbox_user_by_address($t['inbox_address']);
            if (! $u) continue;
            if (! $dry) {
                $wpdb->update(
                    $wpdb->prefix . 'gdc_inbox_threads',
                    array('owner_user_id' => $u->ID),
                    array('id' => (int) $t['id']),
                    array('%d'), array('%d')
                );
            }
            $stamped++;
        }
        \WP_CLI::success("Threads stamped: $stamped");
    }
}
