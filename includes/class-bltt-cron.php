<?php
/**
 * Cron scheduling for automated sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLTT_Cron {

    const HOOK = 'bltt_scheduled_sync';
    const LEGACY_HOOK = 'ztube_scheduled_sync';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::HOOK, array( $this, 'run_sync' ) );
        add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );

        // Clean up any stale legacy schedule from prior ZymTube installs.
        if ( wp_next_scheduled( self::LEGACY_HOOK ) ) {
            wp_unschedule_hook( self::LEGACY_HOOK );
        }
    }

    public function add_custom_schedules( $schedules ) {
        $schedules['every_5_min'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'blt-tube' ),
        );
        $schedules['every_15_min'] = array(
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'blt-tube' ),
        );
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display'  => __( 'Once Weekly', 'blt-tube' ),
        );
        return $schedules;
    }

    public function run_sync() {
        $engine = new BLTT_Sync_Engine();
        $engine->sync_updates( 'cron' );
    }

    public static function schedule_sync() {
        $settings = get_option( 'bltt_settings', array() );
        $cadence  = isset( $settings['sync_cadence'] ) ? $settings['sync_cadence'] : 'daily';

        if ( 'disabled' === $cadence ) {
            return;
        }

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), $cadence, self::HOOK );
        }
    }

    public static function unschedule_sync() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
        wp_unschedule_hook( self::HOOK );
    }
}
