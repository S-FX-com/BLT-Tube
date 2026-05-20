<?php
/**
 * Sync engine — imports YouTube videos as WordPress posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLTT_Sync_Engine {

    private $settings;
    private $api;

    public function __construct() {
        $this->settings = get_option( 'bltt_settings', array() );
        $this->api      = new BLTT_YouTube_API();
    }

    /**
     * Sync all videos from the configured playlist.
     *
     * @param string $type 'manual' or 'cron'.
     * @return array|WP_Error  Sync result summary.
     */
    public function sync_all( $type = 'manual' ) {
        $playlist_id = $this->settings['playlist_id'] ?? '';
        if ( empty( $playlist_id ) ) {
            return new WP_Error( 'no_playlist', 'No playlist configured. Please save settings first.' );
        }

        $this->update_progress( 'Fetching playlist videos...', 0 );

        $video_ids = $this->api->get_playlist_video_ids( $playlist_id );
        if ( is_wp_error( $video_ids ) ) {
            return $video_ids;
        }

        if ( empty( $video_ids ) ) {
            $this->update_progress( 'No videos found in playlist.', 100 );
            return array(
                'found'    => 0,
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => 0,
            );
        }

        $this->update_progress( sprintf( 'Found %d videos. Fetching details...', count( $video_ids ) ), 5 );

        $videos = $this->api->get_videos( $video_ids );
        if ( is_wp_error( $videos ) ) {
            return $videos;
        }

        $total    = count( $videos );
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ( $videos as $index => $video ) {
            $pct = intval( ( ( $index + 1 ) / $total ) * 90 ) + 10;
            $this->update_progress(
                sprintf( 'Processing %d / %d: %s', $index + 1, $total, $video['title'] ),
                $pct
            );

            if ( $this->video_exists( $video['video_id'] ) ) {
                $skipped++;
                continue;
            }

            $result = $this->import_video( $video );
            if ( is_wp_error( $result ) ) {
                $errors++;
            } else {
                $imported++;
            }
        }

        $this->update_progress( 'Sync complete.', 100 );

        $this->log_sync( $type, $total, $imported, $skipped, $errors );

        return array(
            'found'    => $total,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * Import a single video as a post.
     */
    private function import_video( $video ) {
        $post_type       = $this->settings['post_type'] ?? 'post';
        $desc_target     = $this->settings['description_target'] ?? 'post_content';
        $trans_target    = $this->settings['transcript_target'] ?? '';
        $set_thumbnail   = $this->settings['set_thumbnail'] ?? true;
        $assign_keywords = $this->settings['assign_keywords'] ?? true;
        $field_mapping   = $this->settings['field_mapping'] ?? array();

        $post_content = '';

        if ( 'post_content' === $desc_target ) {
            $post_content .= wp_kses_post( $this->format_description( $video['description'] ) );
        }

        $transcript = '';
        if ( ! empty( $trans_target ) || isset( $field_mapping['transcript'] ) ) {
            $transcript_result = $this->api->get_transcript( $video['video_id'] );
            if ( ! is_wp_error( $transcript_result ) ) {
                $transcript = $transcript_result;
            }
        }

        if ( 'post_content' === $trans_target && ! empty( $transcript ) ) {
            if ( ! empty( $post_content ) ) {
                $post_content .= "\n\n<h2>Transcript</h2>\n\n";
            }
            $post_content .= wp_kses_post( wpautop( $transcript ) );
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $video['title'] ),
            'post_content' => $post_content,
            'post_status'  => 'publish',
            'post_type'    => $post_type,
            'post_date'    => date( 'Y-m-d H:i:s', strtotime( $video['published_at'] ) ),
        );

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Store the YouTube video ID as meta so we can detect duplicates.
        // Legacy `_ztube_video_id` key is also written so older installs continue to dedupe.
        update_post_meta( $post_id, '_bltt_video_id', $video['video_id'] );
        update_post_meta( $post_id, '_bltt_video_url', $video['video_url'] );
        update_post_meta( $post_id, '_ztube_video_id', $video['video_id'] );

        foreach ( $field_mapping as $yt_field => $wp_meta_key ) {
            $value = '';
            if ( 'transcript' === $yt_field ) {
                $value = $transcript;
            } elseif ( isset( $video[ $yt_field ] ) ) {
                $value = $video[ $yt_field ];
                if ( is_array( $value ) ) {
                    $value = implode( ', ', $value );
                }
            }
            if ( '' !== $value ) {
                update_post_meta( $post_id, $wp_meta_key, $value );
            }
        }

        if ( 'custom_field' === $trans_target && ! empty( $transcript ) ) {
            if ( ! isset( $field_mapping['transcript'] ) ) {
                update_post_meta( $post_id, 'bltt_transcript', $transcript );
            }
        }

        if ( $set_thumbnail && ! empty( $video['thumbnail_url'] ) ) {
            $attach_id = $this->api->sideload_thumbnail(
                $video['thumbnail_url'],
                $post_id,
                $video['title']
            );
            if ( ! is_wp_error( $attach_id ) ) {
                set_post_thumbnail( $post_id, $attach_id );
            }
        }

        if ( $assign_keywords && ! empty( $video['tags'] ) ) {
            $this->assign_tags( $post_id, $video['tags'], $post_type );
        }

        return $post_id;
    }

    /**
     * Assign tags/keywords to a post.
     *
     * For 'post' post type, uses the built-in 'post_tag' taxonomy.
     * For custom post types, looks for an associated taxonomy or falls back to post_tag.
     */
    private function assign_tags( $post_id, $tags, $post_type ) {
        $taxonomy   = 'post_tag';
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );

        foreach ( $taxonomies as $tax_name => $tax_obj ) {
            if ( ! $tax_obj->hierarchical ) {
                $taxonomy = $tax_name;
                break;
            }
        }

        if ( ! in_array( $taxonomy, get_object_taxonomies( $post_type ), true ) ) {
            register_taxonomy_for_object_type( 'post_tag', $post_type );
            $taxonomy = 'post_tag';
        }

        wp_set_object_terms( $post_id, $tags, $taxonomy, true );
    }

    /**
     * Check if a video has already been imported.
     */
    private function video_exists( $video_id ) {
        global $wpdb;
        $post_type = $this->settings['post_type'] ?? 'post';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ('_bltt_video_id', '_ztube_video_id')
             AND pm.meta_value = %s
             AND p.post_type = %s
             AND p.post_status != 'trash'
             LIMIT 1",
            $video_id,
            $post_type
        ) );

        return ! empty( $exists );
    }

    /**
     * Convert a YouTube description into formatted HTML.
     */
    private function format_description( $text ) {
        $text = preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $text
        );

        return wpautop( $text );
    }

    private function update_progress( $message, $percent ) {
        set_transient( 'bltt_sync_progress', array(
            'status'  => 'running',
            'message' => $message,
            'percent' => $percent,
        ), 600 );
    }

    private function log_sync( $type, $found, $imported, $skipped, $errors ) {
        $log   = get_option( 'bltt_sync_log', array() );
        $log[] = array(
            'date'     => current_time( 'Y-m-d H:i:s' ),
            'type'     => $type,
            'found'    => $found,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );

        if ( count( $log ) > 100 ) {
            $log = array_slice( $log, -100 );
        }

        update_option( 'bltt_sync_log', $log );

        delete_transient( 'bltt_sync_progress' );
    }
}
