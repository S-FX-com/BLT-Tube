<?php
/**
 * Plugin Name: ZymTube
 * Plugin URI:  https://github.com/s-fx-com/zymtube
 * Description: Import YouTube playlist videos into any WordPress Custom Post Type with full field mapping, thumbnails, transcripts, and scheduled sync.
 * Version:     1.0.0
 * Author:      Obie
 * License:     GPL-2.0-or-later
 * Text Domain: zymtube
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ZTUBE_VERSION', '1.0.0' );
define( 'ZTUBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZTUBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZTUBE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ZTUBE_PLUGIN_DIR . 'includes/class-ztube-youtube-api.php';
require_once ZTUBE_PLUGIN_DIR . 'includes/class-ztube-admin.php';
require_once ZTUBE_PLUGIN_DIR . 'includes/class-ztube-sync-engine.php';
require_once ZTUBE_PLUGIN_DIR . 'includes/class-ztube-cron.php';

/**
 * Main plugin class.
 */
final class ZymTube {

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
        ZTUBE_Admin::get_instance();
        ZTUBE_Cron::get_instance();
    }

    public function activate() {
        // Create default options.
        if ( false === get_option( 'ztube_settings' ) ) {
            update_option( 'ztube_settings', array(
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
        ZTUBE_Cron::schedule_sync();
    }

    public function deactivate() {
        ZTUBE_Cron::unschedule_sync();
    }
}

ZymTube::get_instance();
