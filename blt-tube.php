<?php
/**
 * Plugin Name: BLT Tube
 * Plugin URI:  https://github.com/s-fx-com/blt-tube
 * Description: Import YouTube playlist videos into any WordPress Custom Post Type with full field mapping, thumbnails, transcripts, and scheduled sync. Includes a shortcode for embedding playlists.
 * Version:     1.4.0
 * Author:      S-FX.com
 * Author URI:  https://www.s-fx.com
 * License:     GPL-2.0-or-later
 * Text Domain: blt-tube
 * Requires at least: 6.5
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BLTT_VERSION', '1.4.0' );
define( 'BLTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BLTT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Shared BLT family layer. Registered during load, not from a hook, so the
 * registry is complete before plugins_loaded — and required before the update
 * checker below, which calls BLT_Family_Updates::apply().
 */
require_once BLTT_PLUGIN_DIR . 'includes/blt-family/bootstrap.php';

blt_family_register(
    __FILE__,
    array(
        'name'    => 'BLT Tube',
        'slug'    => 'blt-tube',
        'version' => BLTT_VERSION,
        'menu'    => 'bltt-settings',
        'groups'  => array( 'github', 'google' ),
    )
);

// Bootstrap the update checker when the vendored library is present (release builds).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';

    // The 24 is the family policy's check period and is required: a checker
    // built with 0 registers no scheduler hooks at all and cannot be revived
    // afterwards. BLT_Family_Updates::apply() then holds automatic checks to
    // one a day, anchored to midnight site time, while manual checks stay
    // immediate.
    $bltt_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/s-fx-com/blt-tube/',
        __FILE__,
        'blt-tube',
        24
    );
    // Fetch the zip attached to each GitHub release instead of the raw source archive.
    $bltt_update_checker->getVcsApi()->enableReleaseAssets();

    /*
     * Authenticate the GitHub API calls when a shared token is available.
     * Unauthenticated requests are capped at 60 per hour per IP, shared with
     * every other anonymous caller on the host, which is what makes a release
     * check silently return nothing on a busy server.
     *
     * The read is deferred to plugins_loaded because BLT_Family only exists
     * once the family library has won its version election (plugins_loaded,
     * priority 0) — it is not loaded yet at this point in the plugin's own
     * load. Update checks all run later than this, so nothing is missed. The
     * token may legitimately be absent, so both the class and the value are
     * guarded and no authentication is set when it is.
     */
    add_action(
        'plugins_loaded',
        static function () use ( $bltt_update_checker ) {
            // Only where an update check can actually happen. The token is
            // consumed exclusively by plugin-update-checker, which runs from
            // admin_init, the manual-check request, WP-Cron or WP-CLI — never
            // from a front-end page view. Without this gate every public
            // request would build the family group definitions and read
            // blt_family_opt_in, an option stored with autoload = no, for a
            // value nothing on that request can use.
            if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
                return;
            }

            if ( ! class_exists( 'BLT_Family' ) || ! method_exists( $bltt_update_checker, 'setAuthentication' ) ) {
                return;
            }

            $bltt_github_token = BLT_Family::get( 'blt-tube', 'github', 'token' );

            if ( '' !== $bltt_github_token ) {
                $bltt_update_checker->setAuthentication( $bltt_github_token );
            }
        },
        1
    );

    BLT_Family_Updates::apply(
        $bltt_update_checker,
        array(
            'basename'  => BLTT_PLUGIN_BASENAME,
            'icons_url' => BLTT_PLUGIN_URL . 'assets/img/',
        )
    );

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
                'sync_hour'          => 3,
                'sync_minute'        => 0,
                'sync_weekday'       => 1,
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
