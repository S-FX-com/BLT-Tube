<?php
/**
 * Cron scheduling for automated sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZTUBE_Cron {

    const HOOK = 'ztube_scheduled_sync';

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
    }

    /**
     * Add custom cron schedules.
     */
    public function add_custom_schedules( $schedules ) {
        $schedules['every_5_min'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'zymtube' ),
        );
        $schedules['every_15_min'] = array(
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'zymtube' ),
        );
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display'  => __( 'Once Weekly', 'zymtube' ),
        );
        return $schedules;
    }

    /**
     * Run the scheduled sync.
     */
    public function run_sync() {
        $engine = new ZTUBE_Sync_Engine();
        $engine->sync_all( 'cron' );
    }

    /**
     * Schedule the sync event based on saved cadence.
     */
    public static function schedule_sync() {
        $settings = get_option( 'ztube_settings', array() );
        $cadence  = isset( $settings['sync_cadence'] ) ? $settings['sync_cadence'] : 'daily';

        if ( 'disabled' === $cadence ) {
            return;
        }

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), $cadence, self::HOOK );
        }
    }

    /**
     * Clear all scheduled sync events.
     */
    public static function unschedule_sync() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
        // Clear any remaining events.
        wp_unschedule_hook( self::HOOK );
    }
}
