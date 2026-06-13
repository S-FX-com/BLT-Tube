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
     * Fetch every video in the configured playlist and return its details plus
     * whether it has already been imported, without writing anything to the DB.
     *
     * @return array|WP_Error
     */
    public function preview_playlist() {
        $playlist_id = $this->settings['playlist_id'] ?? '';
        if ( empty( $playlist_id ) ) {
            return new WP_Error( 'no_playlist', 'No playlist configured. Please save settings first.' );
        }

        $video_ids = $this->api->get_playlist_video_ids( $playlist_id );
        if ( is_wp_error( $video_ids ) ) {
            return $video_ids;
        }

        if ( empty( $video_ids ) ) {
            return array( 'videos' => array(), 'total' => 0, 'unsynced' => 0 );
        }

        $videos = $this->api->get_videos( $video_ids );
        if ( is_wp_error( $videos ) ) {
            return $videos;
        }

        $results = array();
        foreach ( $videos as $video ) {
            $results[] = array(
                'video_id'     => $video['video_id'],
                'title'        => $video['title'],
                'thumbnail'    => $video['thumbnail_url'],
                'duration'     => $video['duration'],
                'published_at' => $video['published_at'],
                'channel'      => $video['channel_title'],
                'synced'       => $this->video_exists( $video['video_id'] ),
            );
        }

        $unsynced = count( array_filter( $results, function ( $v ) {
            return ! $v['synced'];
        } ) );

        return array(
            'videos'   => $results,
            'total'    => count( $results ),
            'unsynced' => $unsynced,
        );
    }

    /**
     * Import a specific set of video IDs chosen by the user.
     *
     * @param string[] $video_ids
     * @return array|WP_Error
     */
    public function import_selected( array $video_ids ) {
        if ( empty( $video_ids ) ) {
            return new WP_Error( 'no_videos', 'No video IDs provided.' );
        }

        $this->update_progress( 'Fetching video details…', 0 );

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
                sprintf( 'Importing %d / %d: %s', $index + 1, $total, $video['title'] ),
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

        $this->update_progress( 'Import complete.', 100 );
        $this->log_sync( 'selected', $total, $imported, $skipped, $errors );

        return array(
            'found'    => $total,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        );
    }

    /**
     * Update all previously-imported posts with the latest data from YouTube.
     * Only touches posts that already have a _bltt_video_id meta value.
     *
     * @param string $type  'cron' or 'manual'
     * @return array|WP_Error
     */
    public function sync_updates( $type = 'manual' ) {
        global $wpdb;

        $post_type = $this->settings['post_type'] ?? 'post';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS video_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_bltt_video_id'
             AND p.post_type = %s
             AND p.post_status != 'trash'",
            $post_type
        ) );

        if ( empty( $rows ) ) {
            $this->log_sync( $type, 0, 0, 0, 0 );
            return array(
                'found'   => 0,
                'updated' => 0,
                'errors'  => 0,
                'message' => 'No previously imported posts found.',
            );
        }

        $this->update_progress( 'Fetching current video details from YouTube…', 0 );

        $video_id_to_post = array();
        foreach ( $rows as $row ) {
            $video_id_to_post[ $row->video_id ] = (int) $row->post_id;
        }

        $videos = $this->api->get_videos( array_keys( $video_id_to_post ) );
        if ( is_wp_error( $videos ) ) {
            return $videos;
        }

        $total   = count( $videos );
        $updated = 0;
        $errors  = 0;

        foreach ( $videos as $index => $video ) {
            $pct = intval( ( ( $index + 1 ) / $total ) * 90 ) + 10;
            $this->update_progress(
                sprintf( 'Updating %d / %d: %s', $index + 1, $total, $video['title'] ),
                $pct
            );

            $post_id = $video_id_to_post[ $video['video_id'] ] ?? null;
            if ( ! $post_id ) {
                continue;
            }

            $result = $this->update_post_from_video( $post_id, $video );
            if ( is_wp_error( $result ) ) {
                $errors++;
            } else {
                $updated++;
            }
        }

        $this->update_progress( 'Sync complete.', 100 );
        $this->log_sync( $type, $total, $updated, 0, $errors );

        return array(
            'found'   => $total,
            'updated' => $updated,
            'errors'  => $errors,
        );
    }

    /**
     * Create a new post for a single video.
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

        update_post_meta( $post_id, '_bltt_video_id', $video['video_id'] );
        update_post_meta( $post_id, '_bltt_video_url', $video['video_url'] );
        update_post_meta( $post_id, '_ztube_video_id', $video['video_id'] );

        $this->apply_field_mapping( $post_id, $video, $transcript, true );

        if ( $set_thumbnail && ! isset( $field_mapping['thumbnail_url'] ) && ! empty( $video['thumbnail_url'] ) ) {
            $attach_id = $this->api->sideload_thumbnail( $video['thumbnail_url'], $post_id, $video['title'] );
            if ( ! is_wp_error( $attach_id ) ) {
                set_post_thumbnail( $post_id, $attach_id );
            }
        }

        if ( 'custom_field' === $trans_target && ! empty( $transcript ) ) {
            if ( ! isset( $field_mapping['transcript'] ) ) {
                update_post_meta( $post_id, 'bltt_transcript', $transcript );
            }
        }

        if ( $assign_keywords && ! empty( $video['tags'] ) ) {
            $this->assign_tags( $post_id, $video['tags'], $post_type );
        }

        return $post_id;
    }

    /**
     * Update an existing post with the latest YouTube data.
     * Does not overwrite a featured image that has already been set.
     */
    private function update_post_from_video( $post_id, $video ) {
        $desc_target     = $this->settings['description_target'] ?? 'post_content';
        $trans_target    = $this->settings['transcript_target'] ?? '';
        $set_thumbnail   = $this->settings['set_thumbnail'] ?? true;
        $assign_keywords = $this->settings['assign_keywords'] ?? true;
        $field_mapping   = $this->settings['field_mapping'] ?? array();
        $post_type       = $this->settings['post_type'] ?? 'post';

        $post_content = '';
        if ( 'post_content' === $desc_target ) {
            $post_content = wp_kses_post( $this->format_description( $video['description'] ) );
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

        wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field( $video['title'] ),
            'post_content' => $post_content,
        ) );

        update_post_meta( $post_id, '_bltt_video_url', $video['video_url'] );

        $this->apply_field_mapping( $post_id, $video, $transcript, false );

        // Only set a featured image if the post doesn't already have one.
        if ( $set_thumbnail && ! get_post_thumbnail_id( $post_id )
            && ! isset( $field_mapping['thumbnail_url'] )
            && ! empty( $video['thumbnail_url'] ) ) {
            $attach_id = $this->api->sideload_thumbnail( $video['thumbnail_url'], $post_id, $video['title'] );
            if ( ! is_wp_error( $attach_id ) ) {
                set_post_thumbnail( $post_id, $attach_id );
            }
        }

        if ( 'custom_field' === $trans_target && ! empty( $transcript ) ) {
            if ( ! isset( $field_mapping['transcript'] ) ) {
                update_post_meta( $post_id, 'bltt_transcript', $transcript );
            }
        }

        if ( $assign_keywords && ! empty( $video['tags'] ) ) {
            $this->assign_tags( $post_id, $video['tags'], $post_type );
        }

        return $post_id;
    }

    /**
     * Apply the user-configured field mapping to a post.
     *
     * @param int    $post_id
     * @param array  $video
     * @param string $transcript
     * @param bool   $allow_thumbnail_sideload  True on first import; false when updating (avoid duplicate uploads).
     */
    private function apply_field_mapping( $post_id, $video, $transcript, $allow_thumbnail_sideload ) {
        $field_mapping      = $this->settings['field_mapping'] ?? array();
        $native_post_fields = array( 'post_title', 'post_content', 'post_excerpt' );
        $post_field_updates = array();

        foreach ( $field_mapping as $yt_field => $wp_target ) {
            $value = '';
            if ( 'transcript' === $yt_field ) {
                $value = $transcript;
            } elseif ( isset( $video[ $yt_field ] ) ) {
                $value = $video[ $yt_field ];
                if ( is_array( $value ) ) {
                    $value = implode( ', ', $value );
                }
            }

            if ( '' === $value ) {
                continue;
            }

            if ( '_featured_image' === $wp_target ) {
                if ( $allow_thumbnail_sideload || ! get_post_thumbnail_id( $post_id ) ) {
                    $attach_id = $this->api->sideload_thumbnail( $value, $post_id, $video['title'] );
                    if ( ! is_wp_error( $attach_id ) ) {
                        set_post_thumbnail( $post_id, $attach_id );
                    }
                }
            } elseif ( in_array( $wp_target, $native_post_fields, true ) ) {
                $post_field_updates[ $wp_target ] = $value;
            } else {
                update_post_meta( $post_id, $wp_target, $value );
            }
        }

        if ( ! empty( $post_field_updates ) ) {
            $post_field_updates['ID'] = $post_id;
            wp_update_post( $post_field_updates );
        }
    }

    /**
     * Assign tags/keywords to a post.
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
