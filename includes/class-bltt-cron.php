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
            $start = self::calculate_first_run( $cadence, $settings );
            wp_schedule_event( $start, $cadence, self::HOOK );
        }
    }

    public static function calculate_first_run( $cadence, $settings ) {
        $hour    = isset( $settings['sync_hour'] )    ? (int) $settings['sync_hour']    : 3;
        $minute  = isset( $settings['sync_minute'] )  ? (int) $settings['sync_minute']  : 0;
        $weekday = isset( $settings['sync_weekday'] ) ? (int) $settings['sync_weekday'] : 1;

        $tz  = wp_timezone();
        $now = new DateTime( 'now', $tz );

        switch ( $cadence ) {
            case 'every_5_min':
            case 'every_15_min':
                // Start on the next clean interval boundary.
                $interval = ( 'every_5_min' === $cadence ) ? 300 : 900;
                $unix_now = time();
                return $unix_now + ( $interval - ( $unix_now % $interval ) );

            case 'hourly': {
                $candidate = clone $now;
                $candidate->setTime( (int) $now->format( 'G' ), $minute, 0 );
                if ( $candidate <= $now ) {
                    $candidate->modify( '+1 hour' );
                }
                return $candidate->getTimestamp();
            }

            case 'twicedaily':
            case 'daily': {
                $candidate = clone $now;
                $candidate->setTime( $hour, $minute, 0 );
                if ( $candidate <= $now ) {
                    $candidate->modify( '+1 day' );
                }
                return $candidate->getTimestamp();
            }

            case 'weekly': {
                $current_dow = (int) $now->format( 'w' ); // 0 = Sunday
                $days_ahead  = ( $weekday - $current_dow + 7 ) % 7;
                $candidate   = clone $now;
                $candidate->modify( "+{$days_ahead} days" );
                $candidate->setTime( $hour, $minute, 0 );
                if ( $candidate <= $now ) {
                    $candidate->modify( '+7 days' );
                }
                return $candidate->getTimestamp();
            }

            default:
                return time() + 60;
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
