<?php
/**
 * YouTube Data API v3 wrapper.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLTT_YouTube_API {

    private $api_key;
    private $api_base = 'https://www.googleapis.com/youtube/v3';

    public function __construct( $api_key = '' ) {
        if ( empty( $api_key ) ) {
            $settings      = get_option( 'bltt_settings', array() );
            $this->api_key = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        } else {
            $this->api_key = $api_key;
        }
    }

    /**
     * Validate that the API key works.
     */
    public function validate_key() {
        // For API-key-only auth we just test a known channel.
        $response = $this->request( '/channels', array(
            'part'       => 'snippet',
            'forHandle'  => '@YouTube',
            'maxResults' => 1,
        ) );

        return ! is_wp_error( $response );
    }

    /**
     * Search for channels by name.
     */
    public function search_channels( $query ) {
        $response = $this->request( '/search', array(
            'part'       => 'snippet',
            'type'       => 'channel',
            'q'          => $query,
            'maxResults' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $channels = array();
        if ( ! empty( $response['items'] ) ) {
            foreach ( $response['items'] as $item ) {
                $channels[] = array(
                    'id'    => $item['snippet']['channelId'],
                    'title' => $item['snippet']['title'],
                    'thumb' => $item['snippet']['thumbnails']['default']['url'],
                );
            }
        }
        return $channels;
    }

    /**
     * Get playlists for a given channel.
     */
    public function get_channel_playlists( $channel_id ) {
        $playlists  = array();
        $page_token = '';

        do {
            $params = array(
                'part'       => 'snippet',
                'channelId'  => $channel_id,
                'maxResults' => 50,
            );
            if ( $page_token ) {
                $params['pageToken'] = $page_token;
            }

            $response = $this->request( '/playlists', $params );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! empty( $response['items'] ) ) {
                foreach ( $response['items'] as $item ) {
                    $playlists[] = array(
                        'id'    => $item['id'],
                        'title' => $item['snippet']['title'],
                        'count' => isset( $item['contentDetails']['itemCount'] ) ? $item['contentDetails']['itemCount'] : '?',
                    );
                }
            }

            $page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : '';
        } while ( $page_token );

        return $playlists;
    }

    /**
     * Get all video IDs from a playlist.
     */
    public function get_playlist_video_ids( $playlist_id ) {
        $video_ids  = array();
        $page_token = '';

        do {
            $params = array(
                'part'       => 'contentDetails',
                'playlistId' => $playlist_id,
                'maxResults' => 50,
            );
            if ( $page_token ) {
                $params['pageToken'] = $page_token;
            }

            $response = $this->request( '/playlistItems', $params );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! empty( $response['items'] ) ) {
                foreach ( $response['items'] as $item ) {
                    $video_ids[] = $item['contentDetails']['videoId'];
                }
            }

            $page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : '';
        } while ( $page_token );

        return $video_ids;
    }

    /**
     * Get full video details for up to 50 video IDs at a time.
     */
    public function get_videos( $video_ids ) {
        $all_videos = array();
        $chunks     = array_chunk( $video_ids, 50 );

        foreach ( $chunks as $chunk ) {
            $response = $this->request( '/videos', array(
                'part' => 'snippet,contentDetails,statistics',
                'id'   => implode( ',', $chunk ),
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! empty( $response['items'] ) ) {
                foreach ( $response['items'] as $item ) {
                    $all_videos[] = $this->normalize_video( $item );
                }
            }
        }

        return $all_videos;
    }

    /**
     * Get a single video's details.
     */
    public function get_video( $video_id ) {
        $videos = $this->get_videos( array( $video_id ) );
        if ( is_wp_error( $videos ) ) {
            return $videos;
        }
        return ! empty( $videos ) ? $videos[0] : new WP_Error( 'not_found', 'Video not found.' );
    }

    /**
     * Fetch the transcript / captions for a video.
     *
     * Uses the unofficial timedtext endpoint which works for
     * auto-generated and manually uploaded captions without OAuth.
     */
    public function get_transcript( $video_id, $lang = 'en' ) {
        $list_url = 'https://www.youtube.com/watch?v=' . urlencode( $video_id );
        $response = wp_remote_get( $list_url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( preg_match( '/"captionTracks":\s*(\[.*?\])/', $body, $matches ) ) {
            $tracks = json_decode( $matches[1], true );
            if ( ! empty( $tracks ) ) {
                $track_url = '';
                foreach ( $tracks as $track ) {
                    if ( isset( $track['languageCode'] ) && $track['languageCode'] === $lang ) {
                        $track_url = $track['baseUrl'];
                        break;
                    }
                }
                if ( empty( $track_url ) && ! empty( $tracks[0]['baseUrl'] ) ) {
                    $track_url = $tracks[0]['baseUrl'];
                }

                if ( $track_url ) {
                    $track_url .= '&fmt=json3';
                    $caption_response = wp_remote_get( $track_url, array( 'timeout' => 15 ) );

                    if ( is_wp_error( $caption_response ) ) {
                        return $caption_response;
                    }

                    $caption_body = wp_remote_retrieve_body( $caption_response );
                    $caption_data = json_decode( $caption_body, true );

                    if ( ! empty( $caption_data['events'] ) ) {
                        $lines = array();
                        foreach ( $caption_data['events'] as $event ) {
                            if ( isset( $event['segs'] ) ) {
                                $segment_text = '';
                                foreach ( $event['segs'] as $seg ) {
                                    $segment_text .= isset( $seg['utf8'] ) ? $seg['utf8'] : '';
                                }
                                $segment_text = trim( $segment_text );
                                if ( $segment_text !== '' ) {
                                    $lines[] = $segment_text;
                                }
                            }
                        }
                        return implode( ' ', $lines );
                    }
                }
            }
        }

        return new WP_Error( 'no_transcript', 'No transcript available for this video.' );
    }

    /**
     * Download an image URL and attach it to a post, returning the attachment ID.
     */
    public function sideload_thumbnail( $image_url, $post_id, $description = '' ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $file_array = array(
            'name'     => sanitize_file_name( basename( parse_url( $image_url, PHP_URL_PATH ) ) ) . '.jpg',
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id, $description );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
        }

        return $attachment_id;
    }

    /**
     * Normalize a video API response into a flat array.
     */
    private function normalize_video( $item ) {
        $snippet    = $item['snippet'];
        $thumbnails = $snippet['thumbnails'];

        $thumb_url = '';
        foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
            if ( ! empty( $thumbnails[ $size ]['url'] ) ) {
                $thumb_url = $thumbnails[ $size ]['url'];
                break;
            }
        }

        $tags = isset( $snippet['tags'] ) ? $snippet['tags'] : array();

        return array(
            'video_id'      => $item['id'],
            'title'         => $snippet['title'],
            'description'   => $snippet['description'],
            'published_at'  => $snippet['publishedAt'],
            'channel_title' => $snippet['channelTitle'],
            'thumbnail_url' => $thumb_url,
            'tags'          => $tags,
            'duration'      => isset( $item['contentDetails']['duration'] ) ? $item['contentDetails']['duration'] : '',
            'view_count'    => isset( $item['statistics']['viewCount'] ) ? $item['statistics']['viewCount'] : 0,
            'like_count'    => isset( $item['statistics']['likeCount'] ) ? $item['statistics']['likeCount'] : 0,
            'comment_count' => isset( $item['statistics']['commentCount'] ) ? $item['statistics']['commentCount'] : 0,
            'video_url'     => 'https://www.youtube.com/watch?v=' . $item['id'],
            'embed_url'     => 'https://www.youtube.com/embed/' . $item['id'],
        );
    }

    /**
     * Make an API request.
     */
    private function request( $endpoint, $params = array() ) {
        $params['key'] = $this->api_key;

        $url = $this->api_base . $endpoint . '?' . http_build_query( $params );

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'YouTube API error.';
            return new WP_Error( 'youtube_api_error', $message, array( 'status' => $code ) );
        }

        return $body;
    }
}
