<?php
/**
 * Plugin Name: YouTube Playlist to WordPress
 * Plugin URI:  https://github.com/S-FX-com/Obie_YouTube-to-WP
 * Description: Import YouTube playlist videos into any WordPress Custom Post Type with full field mapping, thumbnails, transcripts, and scheduled sync.
 * Version:     1.0.0
 * Author:      Obie
 * License:     GPL-2.0-or-later
 * Text Domain: yt-to-wp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'YTWP_VERSION', '1.0.0' );
define( 'YTWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YTWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YTWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once YTWP_PLUGIN_DIR . 'includes/class-ytwp-youtube-api.php';
require_once YTWP_PLUGIN_DIR . 'includes/class-ytwp-admin.php';
require_once YTWP_PLUGIN_DIR . 'includes/class-ytwp-sync-engine.php';
require_once YTWP_PLUGIN_DIR . 'includes/class-ytwp-cron.php';

/**
 * Main plugin class.
 */
final class YouTube_To_WP {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    public function init() {
        YTWP_Admin::get_instance();
        YTWP_Cron::get_instance();
    }

    public function activate() {
        // Create default options.
        if ( false === get_option( 'ytwp_settings' ) ) {
            update_option( 'ytwp_settings', array(
                'api_key'        => '',
                'playlist_id'    => '',
                'post_type'      => 'post',
                'field_mapping'  => array(),
                'sync_cadence'   => 'daily',
                'description_target' => 'post_content',
                'transcript_target'  => '',
                'assign_keywords'    => true,
            ) );
        }

        // Schedule cron if a cadence is set.
        YTWP_Cron::schedule_sync();
    }

    public function deactivate() {
        YTWP_Cron::unschedule_sync();
    }
}

YouTube_To_WP::get_instance();
