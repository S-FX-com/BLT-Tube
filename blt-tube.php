<?php
/**
 * Plugin Name: BLT Tube
 * Plugin URI:  https://github.com/s-fx-com/blt-tube
 * Description: Import YouTube playlist videos into any WordPress Custom Post Type with full field mapping, thumbnails, transcripts, and scheduled sync. Includes a shortcode for embedding playlists.
 * Version:     1.2.0
 * Author:      S-FX.com Small Business Solutions
 * License:     GPL-2.0-or-later
 * Text Domain: blt-tube
 * Requires at least: 6.5
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BLTT_VERSION', '1.2.0' );
define( 'BLTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BLTT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Bootstrap the update checker when the vendored library is present (release builds).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';

    $bltt_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/s-fx-com/blt-tube/',
        __FILE__,
        'blt-tube'
    );
    // Fetch the zip attached to each GitHub release instead of the raw source archive.
    $bltt_update_checker->getVcsApi()->enableReleaseAssets();
    unset( $bltt_update_checker );
}

require_once BLTT_PLUGIN_DIR . 'includes/class-bltt-youtube-api.php';
require_once BLTT_PLUGIN_DIR . 'includes/class-bltt-admin.php';
require_once BLTT_PLUGIN_DIR . 'includes/class-bltt-sync-engine.php';
require_once BLTT_PLUGIN_DIR . 'includes/class-bltt-cron.php';
require_once BLTT_PLUGIN_DIR . 'includes/class-bltt-shortcode.php';

/**
 * Main plugin class.
 */
final class BLT_Tube {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'maybe_migrate_legacy_options' ), 5 );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    public function init() {
        BLTT_Admin::get_instance();
        BLTT_Cron::get_instance();
        BLTT_Shortcode::get_instance();
    }

    public function activate() {
        $this->maybe_migrate_legacy_options();

        if ( false === get_option( 'bltt_settings' ) ) {
            update_option( 'bltt_settings', array(
                'api_key'            => '',
                'playlist_id'        => '',
                'post_type'          => 'post',
                'field_mapping'      => array(),
                'sync_cadence'       => 'daily',
                'description_target' => 'post_content',
                'transcript_target'  => '',
                'assign_keywords'    => true,
            ) );
        }

        BLTT_Cron::schedule_sync();
    }

    public function deactivate() {
        BLTT_Cron::unschedule_sync();
    }

    /**
     * One-time migration from the legacy ZymTube option keys so existing
     * installations don't lose their settings on upgrade.
     */
    public function maybe_migrate_legacy_options() {
        if ( ! get_option( 'bltt_settings' ) ) {
            $legacy = get_option( 'ztube_settings' );
            if ( false !== $legacy ) {
                update_option( 'bltt_settings', $legacy );
            }
        }

        if ( ! get_option( 'bltt_sync_log' ) ) {
            $legacy_log = get_option( 'ztube_sync_log' );
            if ( false !== $legacy_log ) {
                update_option( 'bltt_sync_log', $legacy_log );
            }
        }
    }
}

BLT_Tube::get_instance();
