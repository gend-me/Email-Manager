<?php
/**
 * Video Player Widget (slice 3b).
 *
 * Site-wide click interceptor + glass video player. Any anchor whose
 * href is a direct video file (.mp4/.webm/.mov/.m4v/.ogg/.ogv) OR a
 * YouTube / Vimeo URL gets intercepted and routed into a floating
 * mini-player at the bottom-right (just above the chat widget). User
 * can click the expand button to blow it up to a full-screen modal
 * with custom stylized controls.
 *
 * Pure-client implementation — no REST endpoints, no server state.
 * This file just enqueues the assets + drops a mount point.
 *
 * Other modules can fire from anywhere:
 *   window.emVideoOpen('https://youtu.be/dQw4w9WgXcQ');
 *   window.emVideoOpen({ src: '/foo.mp4', title: 'Demo' });
 *
 * @package EmailManager
 * @since   1.7.0
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Frontend asset enqueue — site-wide
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'em_video_player_enqueue', 25);
function em_video_player_enqueue() {
    wp_enqueue_style(
        'em-video-player',
        EMAIL_MANAGER_URL . 'assets/video-player.css',
        array(),
        EMAIL_MANAGER_VERSION
    );
    wp_enqueue_script(
        'em-video-player',
        EMAIL_MANAGER_URL . 'assets/video-player.js',
        array('wp-element'),
        EMAIL_MANAGER_VERSION,
        true
    );
}

// Mount point lives in wp_footer of every page.
add_action('wp_footer', 'em_video_player_mount', 60);
function em_video_player_mount() {
    echo '<div id="em-video-player-root"></div>';
}
