<?php
/**
 * [blt_tube_gallery] — a YouTube playlist rendered as a visual video gallery.
 *
 * Videos are fetched live (no posts are created) and cached in a transient, so
 * a page view never triggers a YouTube request on its own. With an API key the
 * data comes from the YouTube Data API; without one it falls back to the
 * playlist's public RSS feed, which only carries the latest 15 videos.
 *
 * The markup is a facade: thumbnails only. No YouTube iframe or script is
 * loaded until a visitor clicks a video.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLTT_Gallery {

    const TAG = 'blt_tube_gallery';

    /** Style and script handle. */
    const HANDLE = 'bltt-gallery';

    /** Option holding the cache generation; bumping it orphans every cached gallery. */
    const VERSION_OPTION = 'bltt_gallery_cache_version';

    /** How long an expired result is kept so it can be served if a refresh fails. */
    const STALE_WINDOW = WEEK_IN_SECONDS;

    /** After a failed fetch, wait this long before asking YouTube again. */
    const RETRY_AFTER = 300;

    /** The public RSS feed never returns more than this many entries. */
    const RSS_LIMIT = 15;

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( self::TAG, array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_early' ) );
    }

    /* ---------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------- */

    public function register_assets() {
        wp_register_style(
            self::HANDLE,
            BLTT_PLUGIN_URL . 'assets/css/gallery.css',
            array(),
            BLTT_VERSION
        );

        wp_register_script(
            self::HANDLE,
            BLTT_PLUGIN_URL . 'assets/js/gallery.js',
            array(),
            BLTT_VERSION,
            array(
                'in_footer' => true,
                'strategy'  => 'defer',
            )
        );
    }

    /**
     * Put the stylesheet in <head> when the shortcode is visible in the post
     * content up front. Page builders keep their content elsewhere, so render()
     * enqueues again as the catch-all — WordPress prints late styles in the
     * footer. Either way nothing loads on a page without a gallery.
     */
    public function maybe_enqueue_early() {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_post();

        if ( $post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
            $this->enqueue_assets();
        }
    }

    private function enqueue_assets() {
        if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
            $this->register_assets();
        }

        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( self::HANDLE );
    }

    /* ---------------------------------------------------------------
     * Shortcode
     * ------------------------------------------------------------- */

    public function render( $atts ) {
        $raw = is_array( $atts ) ? $atts : array();

        $atts = shortcode_atts( array(
            'id'               => '',
            'layout'           => 'grid',
            'play'             => 'inline',
            'columns'          => '3',
            'columns_tablet'   => '2',
            'columns_mobile'   => '1',
            'max'              => '12',
            'order'            => 'playlist',
            'show_header'      => 'no',
            'show_title'       => 'yes',
            'show_date'        => 'yes',
            'show_description' => 'no',
            'load_more'        => 'no',
            'per_page'         => '6',
            'thumbs'           => 'grid',
            'privacy'          => 'yes',
        ), $atts, self::TAG );

        $is_admin = current_user_can( 'manage_options' );

        $playlist_id = trim( (string) $atts['id'] );

        if ( '' === $playlist_id ) {
            $settings    = get_option( 'bltt_settings', array() );
            $playlist_id = isset( $settings['playlist_id'] ) ? trim( (string) $settings['playlist_id'] ) : '';
        }

        if ( '' === $playlist_id || ! BLTT_Shortcode::is_valid_playlist_id( $playlist_id ) ) {
            return $this->render_admin_only(
                $is_admin,
                esc_html__( 'BLT Tube: no valid playlist ID provided. Set one in BLT Tube settings or pass id="PL..." to the shortcode.', 'blt-tube' )
            );
        }

        $layout = self::pick( $atts['layout'], array( 'grid', 'featured', 'carousel', 'list' ), 'grid' );
        $play   = self::pick( $atts['play'], array( 'inline', 'lightbox', 'youtube' ), 'inline' );
        $order  = self::pick( $atts['order'], array( 'playlist', 'newest' ), 'playlist' );
        $thumbs = self::pick( $atts['thumbs'], array( 'grid', 'strip' ), 'grid' );

        // The featured layout always plays in its own main player.
        if ( 'featured' === $layout ) {
            $play = 'stage';
        }

        /**
         * Upper bound for the `max` attribute.
         *
         * @param int $limit Default 50.
         */
        $max_limit = max( 1, (int) apply_filters( 'bltt_gallery_max_limit', 50 ) );
        $max       = self::clamp( $atts['max'], 1, $max_limit, 12 );

        $data = $this->get_data( $playlist_id, $max, $order );

        if ( empty( $data['videos'] ) ) {
            if ( ! empty( $data['error'] ) ) {
                $message = sprintf(
                    /* translators: %s: error message from YouTube */
                    esc_html__( 'BLT Tube: the video gallery could not be loaded, so visitors see nothing here. YouTube said: %s', 'blt-tube' ),
                    esc_html( $data['error'] )
                );
            } else {
                $message = esc_html__( 'BLT Tube: this playlist has no public videos to show.', 'blt-tube' );
            }

            return $this->render_admin_only( $is_admin, $message );
        }

        $videos = array_slice( $data['videos'], 0, $max );
        $count  = count( $videos );

        /*
         * Under the featured player the thumbnails are navigation, not the
         * main event, so that layout defaults to a denser grid (4/3/2) unless
         * the columns are set explicitly.
         */
        if ( 'featured' === $layout ) {
            foreach ( array( 'columns' => '4', 'columns_tablet' => '3', 'columns_mobile' => '2' ) as $name => $dense ) {
                if ( ! isset( $raw[ $name ] ) ) {
                    $atts[ $name ] = $dense;
                }
            }
        }

        $ctx = array(
            'uid'          => wp_unique_id( 'bltt-gallery-' ),
            'layout'       => $layout,
            'play'         => $play,
            'playlist_id'  => $playlist_id,
            'show_title'   => self::is_truthy( $atts['show_title'] ),
            'show_date'    => self::is_truthy( $atts['show_date'] ),
            'show_desc'    => self::is_truthy( $atts['show_description'] ),
            'columns'      => self::clamp( $atts['columns'], 1, 6, 3 ),
            'columns_t'    => self::clamp( $atts['columns_tablet'], 1, 6, 2 ),
            'columns_m'    => self::clamp( $atts['columns_mobile'], 1, 6, 1 ),
            'strip'        => 'featured' === $layout && 'strip' === $thumbs,
            'per_page'     => 0,
            /**
             * Number of words kept in a description excerpt.
             *
             * @param int $words Default 30.
             */
            'desc_words'   => max( 1, (int) apply_filters( 'bltt_gallery_description_words', 30 ) ),
        );

        // A scroller has nothing to "load more" into, so it applies to the
        // layouts that stack: grid, list, and the featured layout's grid.
        $scrolls = 'carousel' === $layout || $ctx['strip'];

        if ( self::is_truthy( $atts['load_more'] ) && ! $scrolls ) {
            $per_page = self::clamp( $atts['per_page'], 1, $max_limit, 6 );

            if ( $per_page < $count ) {
                $ctx['per_page'] = $per_page;
            }
        }

        $this->enqueue_assets();

        $host = self::is_truthy( $atts['privacy'] ) ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';

        $html = sprintf(
            '<div id="%1$s" class="bltt-gallery bltt-gallery--%2$s" data-bltt-gallery data-layout="%2$s" data-play="%3$s" data-embed-host="%4$s" data-per-page="%5$d" data-label-close="%6$s" style="--bltt-cols:%7$d;--bltt-cols-tablet:%8$d;--bltt-cols-mobile:%9$d;">',
            esc_attr( $ctx['uid'] ),
            esc_attr( $layout ),
            esc_attr( $play ),
            esc_url( $host ),
            (int) $ctx['per_page'],
            esc_attr__( 'Close video', 'blt-tube' ),
            (int) $ctx['columns'],
            (int) $ctx['columns_t'],
            (int) $ctx['columns_m']
        );

        if ( $is_admin ) {
            $html .= $this->render_admin_notices( $data, $max );
        }

        if ( self::is_truthy( $atts['show_header'] ) ) {
            $html .= $this->render_header( $this->get_channel( $playlist_id, $data ) );
        }

        if ( 'featured' === $layout ) {
            $html .= $this->render_stage( $videos[0], $ctx );
        }

        $items_id = $ctx['uid'] . '-items';

        if ( $scrolls ) {
            $html .= $this->render_scroll_controls( $items_id );
        }

        $html .= sprintf(
            '<div id="%s" class="bltt-gallery__items%s" role="list">',
            esc_attr( $items_id ),
            $scrolls ? ' bltt-gallery__track' . ( $ctx['strip'] ? ' bltt-gallery__track--strip' : '' ) : ''
        );

        foreach ( $videos as $index => $video ) {
            $html .= $this->render_item( $video, $index, $ctx );
        }

        $html .= '</div>';

        if ( $ctx['per_page'] ) {
            $html .= sprintf(
                '<div class="bltt-gallery__more"><button type="button" class="bltt-gallery__more-btn" aria-controls="%s">%s</button></div>',
                esc_attr( $items_id ),
                esc_html__( 'Load more', 'blt-tube' )
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * A message only a site admin sees; visitors get an empty string.
     *
     * @param bool   $is_admin Whether the viewer can manage options.
     * @param string $message  Already-escaped message.
     */
    private function render_admin_only( $is_admin, $message ) {
        if ( ! $is_admin ) {
            return '';
        }

        $this->enqueue_assets();

        return '<div class="bltt-gallery bltt-gallery--empty"><p class="bltt-gallery__notice">' . $message . '</p></div>';
    }

    private function render_admin_notices( $data, $max ) {
        $notices = array();

        if ( isset( $data['source'] ) && 'rss' === $data['source'] ) {
            $notice = esc_html__( 'BLT Tube: no YouTube API key is set, so this gallery is reading the playlist\'s public RSS feed, which only lists the latest 15 videos and has no channel avatar.', 'blt-tube' );

            if ( $max > self::RSS_LIMIT ) {
                $notice .= ' ' . sprintf(
                    /* translators: %d: the max attribute's value */
                    esc_html__( 'You asked for %d.', 'blt-tube' ),
                    (int) $max
                );
            }

            $notice .= ' <a href="' . esc_url( admin_url( 'admin.php?page=bltt-settings' ) ) . '">' . esc_html__( 'Add an API key', 'blt-tube' ) . '</a>';

            $notices[] = $notice;
        }

        if ( ! empty( $data['error'] ) ) {
            $notices[] = sprintf(
                /* translators: %s: error message from YouTube */
                esc_html__( 'BLT Tube: the last refresh from YouTube failed, so this gallery is showing its previously cached videos. YouTube said: %s', 'blt-tube' ),
                esc_html( $data['error'] )
            );
        }

        if ( ! $notices ) {
            return '';
        }

        $html = '';

        foreach ( $notices as $notice ) {
            $html .= '<p class="bltt-gallery__notice">' . $notice . ' <em>' . esc_html__( '(Only administrators see this message.)', 'blt-tube' ) . '</em></p>';
        }

        return $html;
    }

    private function render_header( $channel ) {
        if ( empty( $channel['title'] ) ) {
            return '';
        }

        $inner = '';

        if ( ! empty( $channel['thumb'] ) ) {
            $inner .= sprintf(
                '<img class="bltt-gallery__avatar" src="%s" alt="" width="48" height="48" loading="lazy" decoding="async" />',
                esc_url( $channel['thumb'] )
            );
        }

        $inner .= '<span class="bltt-gallery__channel-name">' . esc_html( $channel['title'] ) . '</span>';

        if ( empty( $channel['url'] ) ) {
            return '<div class="bltt-gallery__header"><span class="bltt-gallery__channel">' . $inner . '</span></div>';
        }

        return sprintf(
            '<div class="bltt-gallery__header"><a class="bltt-gallery__channel" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="bltt-gallery__sr"> %s</span></a></div>',
            esc_url( $channel['url'] ),
            $inner,
            esc_html__( '(YouTube channel, opens in a new tab)', 'blt-tube' )
        );
    }

    /**
     * The featured layout's main player: a facade for the first video until it
     * is played or another video is picked from the thumbnails.
     */
    private function render_stage( $video, $ctx ) {
        $html  = '<div class="bltt-gallery__stage">';
        $html .= '<div class="bltt-gallery__media">' . $this->render_thumb( $video, $ctx, 'stage' ) . '</div>';

        if ( $ctx['show_title'] || $ctx['show_date'] || $ctx['show_desc'] ) {
            $html .= '<div class="bltt-gallery__body bltt-gallery__stage-body" aria-live="polite">';

            if ( $ctx['show_title'] ) {
                $html .= '<h3 class="bltt-gallery__title bltt-gallery__stage-title">' . esc_html( $video['title'] ) . '</h3>';
            }

            if ( $ctx['show_date'] ) {
                $html .= $this->render_date( $video, 'bltt-gallery__date bltt-gallery__stage-date' );
            }

            if ( $ctx['show_desc'] ) {
                $html .= '<p class="bltt-gallery__desc bltt-gallery__stage-desc">' . esc_html( $this->excerpt( $video, $ctx ) ) . '</p>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function render_scroll_controls( $items_id ) {
        $prev = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $next = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        return sprintf(
            '<div class="bltt-gallery__controls"><button type="button" class="bltt-gallery__nav bltt-gallery__nav--prev" data-dir="-1" aria-controls="%1$s" aria-label="%2$s" aria-disabled="true">%4$s</button><button type="button" class="bltt-gallery__nav bltt-gallery__nav--next" data-dir="1" aria-controls="%1$s" aria-label="%3$s">%5$s</button></div>',
            esc_attr( $items_id ),
            esc_attr__( 'Previous videos', 'blt-tube' ),
            esc_attr__( 'Next videos', 'blt-tube' ),
            $prev,
            $next
        );
    }

    private function render_item( $video, $index, $ctx ) {
        $hidden = $ctx['per_page'] && $index >= $ctx['per_page'];

        $html = '<div class="bltt-gallery__item" role="listitem"' . ( $hidden ? ' hidden' : '' ) . '>';

        $html .= '<div class="bltt-gallery__media">' . $this->render_thumb( $video, $ctx, 'item', 0 === $index ) . '</div>';

        $show_desc = $ctx['show_desc'] && 'list' === $ctx['layout'];

        if ( $ctx['show_title'] || $ctx['show_date'] || $show_desc ) {
            $html .= '<div class="bltt-gallery__body">';

            if ( $ctx['show_title'] ) {
                $html .= '<h3 class="bltt-gallery__title">' . esc_html( $video['title'] ) . '</h3>';
            }

            if ( $ctx['show_date'] ) {
                $html .= $this->render_date( $video, 'bltt-gallery__date' );
            }

            if ( $show_desc ) {
                $html .= '<p class="bltt-gallery__desc">' . esc_html( $this->excerpt( $video, $ctx ) ) . '</p>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * The clickable thumbnail. A real <button> for anything that plays on the
     * page; a real link when play="youtube", because that one navigates.
     *
     * @param array  $video    Cached video.
     * @param array  $ctx      Render context.
     * @param string $role     'item' or 'stage'.
     * @param bool   $selected Whether this is the featured layout's current video.
     */
    private function render_thumb( $video, $ctx, $role, $selected = false ) {
        $id    = $video['id'];
        $title = $video['title'];

        $hq     = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
        $maxres = 'https://i.ytimg.com/vi/' . $id . '/maxresdefault.jpg';

        /*
         * hqdefault always exists; maxresdefault only for HD uploads. The API
         * tells us which (maxres true/false); the RSS feed does not (null), so
         * there we offer it and let gallery.js fall back if YouTube answers
         * with its 4:3 placeholder.
         */
        $srcset = '';

        if ( ! isset( $video['maxres'] ) || $video['maxres'] ) {
            $srcset = sprintf(
                ' srcset="%s 480w, %s 1280w" sizes="%s" data-fallback="%s"',
                esc_url( $hq ),
                esc_url( $maxres ),
                esc_attr( $this->sizes( $ctx, $role ) ),
                esc_url( $hq )
            );
        }

        $img = sprintf(
            '<img class="bltt-gallery__img" src="%s"%s alt="%s" width="480" height="270" loading="lazy" decoding="async" />',
            esc_url( $hq ),
            $srcset,
            esc_attr( $title )
        );

        $icon = '<span class="bltt-gallery__play" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>';

        if ( 'youtube' === $ctx['play'] ) {
            $url = add_query_arg(
                array(
                    'v'    => $id,
                    'list' => $ctx['playlist_id'],
                ),
                'https://www.youtube.com/watch'
            );

            return sprintf(
                '<a class="bltt-gallery__thumb" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s">%s%s</a>',
                esc_url( $url ),
                esc_attr( sprintf(
                    /* translators: %s: video title */
                    __( 'Watch on YouTube: %s (opens in a new tab)', 'blt-tube' ),
                    $title
                ) ),
                $img,
                $icon
            );
        }

        $data = sprintf( ' data-video-id="%s" data-title="%s"', esc_attr( $id ), esc_attr( $title ) );

        if ( 'stage' === $ctx['play'] && 'item' === $role ) {
            // What the main player's caption needs when this video is picked.
            if ( $ctx['show_date'] ) {
                $data .= sprintf(
                    ' data-date="%s" data-datetime="%s"',
                    esc_attr( $this->format_date( $video ) ),
                    esc_attr( $this->machine_date( $video ) )
                );
            }

            if ( $ctx['show_desc'] ) {
                $data .= sprintf( ' data-desc="%s"', esc_attr( $this->excerpt( $video, $ctx ) ) );
            }

            if ( $selected ) {
                $data .= ' aria-current="true"';
            }
        }

        return sprintf(
            '<button type="button" class="bltt-gallery__thumb" aria-label="%s"%s>%s%s</button>',
            esc_attr( sprintf(
                /* translators: %s: video title */
                __( 'Play video: %s', 'blt-tube' ),
                $title
            ) ),
            $data,
            $img,
            $icon
        );
    }

    /**
     * A `sizes` hint so the browser only picks the 1280px thumbnail where the
     * slot is actually that large. Approximate by design: the real breakpoints
     * follow the gallery's own width, which `sizes` cannot express.
     */
    private function sizes( $ctx, $role ) {
        if ( 'stage' === $role ) {
            return '(min-width: 1200px) 1200px, 100vw';
        }

        if ( 'list' === $ctx['layout'] ) {
            return '(max-width: 480px) 100vw, 320px';
        }

        if ( $ctx['strip'] ) {
            return '220px';
        }

        return sprintf(
            '(max-width: 480px) %dvw, (max-width: 840px) %dvw, %dvw',
            (int) ceil( 100 / $ctx['columns_m'] ),
            (int) ceil( 100 / $ctx['columns_t'] ),
            (int) ceil( 100 / $ctx['columns'] )
        );
    }

    private function render_date( $video, $class ) {
        $label = $this->format_date( $video );

        if ( '' === $label ) {
            return '';
        }

        return sprintf(
            '<time class="%s" datetime="%s">%s</time>',
            esc_attr( $class ),
            esc_attr( $this->machine_date( $video ) ),
            esc_html( $label )
        );
    }

    private function format_date( $video ) {
        $timestamp = empty( $video['published'] ) ? false : strtotime( $video['published'] );

        return $timestamp ? (string) wp_date( get_option( 'date_format' ), $timestamp ) : '';
    }

    private function machine_date( $video ) {
        $timestamp = empty( $video['published'] ) ? false : strtotime( $video['published'] );

        return $timestamp ? gmdate( 'c', $timestamp ) : '';
    }

    private function excerpt( $video, $ctx ) {
        return wp_trim_words( isset( $video['description'] ) ? $video['description'] : '', $ctx['desc_words'], '…' );
    }

    /* ---------------------------------------------------------------
     * Data
     * ------------------------------------------------------------- */

    /**
     * Videos for a playlist, from cache whenever possible.
     *
     * Always returns an array: { videos, source, channel, fetched, expires,
     * error }. `error` is non-empty when the last refresh failed — alongside
     * stale `videos` if an earlier result was still on hand, alongside none
     * otherwise.
     *
     * Failures are cached too (briefly), so an outage or a bad key costs one
     * request every few minutes rather than one per page view.
     */
    private function get_data( $playlist_id, $max, $order ) {
        $key    = self::cache_key( 'v', array( $playlist_id, $max, $order ) );
        $cached = get_transient( $key );
        $now    = time();

        if ( is_array( $cached ) && isset( $cached['expires'] ) && $cached['expires'] > $now ) {
            return $cached;
        }

        $fresh = $this->fetch( $playlist_id, $max, $order );

        if ( ! is_wp_error( $fresh ) ) {
            /**
             * How long a gallery's videos are cached, in seconds.
             *
             * @param int    $ttl         Default one hour.
             * @param string $playlist_id Playlist being cached.
             */
            $ttl = max( MINUTE_IN_SECONDS, (int) apply_filters( 'bltt_gallery_cache_ttl', HOUR_IN_SECONDS, $playlist_id ) );

            $fresh['fetched'] = $now;
            $fresh['expires'] = $now + $ttl;
            $fresh['error']   = '';

            // Stored well past its freshness so it can stand in during an outage.
            set_transient( $key, $fresh, $ttl + self::STALE_WINDOW );

            return $fresh;
        }

        $has_stale = is_array( $cached ) && ! empty( $cached['videos'] );

        $data = $has_stale ? $cached : array(
            'videos'  => array(),
            'source'  => '',
            'channel' => array(),
            'fetched' => 0,
        );

        $data['error']   = $fresh->get_error_message();
        $data['expires'] = $now + self::RETRY_AFTER;

        set_transient( $key, $data, $has_stale ? self::STALE_WINDOW : self::RETRY_AFTER );

        return $data;
    }

    /**
     * @return array|WP_Error
     */
    private function fetch( $playlist_id, $max, $order ) {
        $api = new BLTT_YouTube_API();

        if ( $api->has_key() ) {
            return $this->fetch_from_api( $api, $playlist_id, $max, $order );
        }

        return $this->fetch_from_rss( $playlist_id, $max, $order );
    }

    private function fetch_from_api( $api, $playlist_id, $max, $order ) {
        /*
         * Private and deleted videos still occupy a playlist slot but are
         * dropped by the details request, so ask for a few spare IDs.
         *
         * "Newest" needs more than the first page of a hand-ordered playlist to
         * be right, so it scans further. A channel uploads playlist (UU...) is
         * already newest-first and needs no scan.
         */
        $limit = $max + 10;

        if ( 'newest' === $order && 0 !== strpos( $playlist_id, 'UU' ) ) {
            /**
             * How many playlist entries order="newest" inspects before sorting.
             *
             * @param int $limit Default 200 (4 API requests).
             */
            $limit = max( $limit, (int) apply_filters( 'bltt_gallery_newest_scan_limit', 200 ) );
        }

        $ids = $api->get_playlist_video_ids( $playlist_id, $limit );

        if ( is_wp_error( $ids ) ) {
            return $ids;
        }

        $videos = array();

        if ( $ids ) {
            $details = $api->get_videos( $ids );

            if ( is_wp_error( $details ) ) {
                return $details;
            }

            $by_id = array();

            foreach ( $details as $detail ) {
                $by_id[ $detail['video_id'] ] = $detail;
            }

            // Back into playlist order, without repeats.
            foreach ( array_unique( $ids ) as $id ) {
                if ( ! isset( $by_id[ $id ] ) || ! self::is_valid_video_id( $id ) ) {
                    continue;
                }

                $videos[] = array(
                    'id'          => $id,
                    'title'       => self::clean_text( $by_id[ $id ]['title'], 200 ),
                    'description' => self::clean_text( $by_id[ $id ]['description'], 600 ),
                    'published'   => (string) $by_id[ $id ]['published_at'],
                    'maxres'      => false !== strpos( (string) $by_id[ $id ]['thumbnail_url'], 'maxresdefault' ),
                );
            }
        }

        return array(
            'videos'  => self::order_and_limit( $videos, $order, $max ),
            'source'  => 'api',
            'channel' => array(),
        );
    }

    /**
     * Keyless fallback: the playlist's public Atom feed. Latest 15 entries only.
     */
    private function fetch_from_rss( $playlist_id, $max, $order ) {
        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'bltt_no_simplexml', __( 'No API key is set, and this server cannot read the public feed (the PHP SimpleXML extension is missing).', 'blt-tube' ) );
        }

        $response = wp_remote_get(
            add_query_arg( 'playlist_id', rawurlencode( $playlist_id ), 'https://www.youtube.com/feeds/videos.xml' ),
            array( 'timeout' => 15 )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            return new WP_Error( 'bltt_feed_error', sprintf(
                /* translators: %d: HTTP status code */
                __( 'The public playlist feed returned HTTP %d. The playlist may be private or the ID may be wrong.', 'blt-tube' ),
                $code
            ) );
        }

        $previous = libxml_use_internal_errors( true );
        $feed     = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $feed ) {
            return new WP_Error( 'bltt_feed_error', __( 'The public playlist feed could not be read.', 'blt-tube' ) );
        }

        $ns_yt    = 'http://www.youtube.com/xml/schemas/2015';
        $ns_media = 'http://search.yahoo.com/mrss/';
        $videos   = array();
        $seen     = array();

        foreach ( $feed->entry as $entry ) {
            $id = (string) $entry->children( $ns_yt )->videoId;

            if ( ! self::is_valid_video_id( $id ) || isset( $seen[ $id ] ) ) {
                continue;
            }

            $seen[ $id ] = true;

            $description = '';
            $group       = $entry->children( $ns_media )->group;

            if ( $group ) {
                $description = (string) $group->children( $ns_media )->description;
            }

            $videos[] = array(
                'id'          => $id,
                'title'       => self::clean_text( (string) $entry->title, 200 ),
                'description' => self::clean_text( $description, 600 ),
                'published'   => (string) $entry->published,
                'maxres'      => null,
            );
        }

        $channel     = array();
        $channel_url = isset( $feed->author->uri ) ? (string) $feed->author->uri : '';

        if ( isset( $feed->author->name ) ) {
            $channel = array(
                'title' => self::clean_text( (string) $feed->author->name, 200 ),
                'url'   => 0 === strpos( $channel_url, 'https://www.youtube.com/' ) ? $channel_url : '',
                'thumb' => '',
            );
        }

        return array(
            'videos'  => self::order_and_limit( $videos, $order, min( $max, self::RSS_LIMIT ) ),
            'source'  => 'rss',
            'channel' => $channel,
        );
    }

    /**
     * Channel name, link and avatar for the optional header.
     *
     * The RSS feed already carries the name and link. With an API key it takes
     * up to two extra requests, so it is fetched only when a gallery actually
     * shows a header, and cached on its own for a day.
     */
    private function get_channel( $playlist_id, $data ) {
        if ( ! empty( $data['channel']['title'] ) ) {
            return $data['channel'];
        }

        if ( isset( $data['source'] ) && 'api' !== $data['source'] ) {
            return array();
        }

        $key    = self::cache_key( 'c', array( $playlist_id ) );
        $cached = get_transient( $key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $api     = new BLTT_YouTube_API();
        $channel = array();

        if ( $api->has_key() ) {
            // A channel's uploads playlist is its channel ID with UC swapped for UU.
            if ( preg_match( '/^UU[A-Za-z0-9_-]{22}$/', $playlist_id ) ) {
                $channel_id = 'UC' . substr( $playlist_id, 2 );
            } else {
                $channel_id = $api->get_playlist_channel_id( $playlist_id );
            }

            if ( ! is_wp_error( $channel_id ) ) {
                $result = $api->get_channel( $channel_id );

                if ( ! is_wp_error( $result ) ) {
                    $channel = array(
                        'title' => self::clean_text( $result['title'], 200 ),
                        'url'   => $result['url'],
                        'thumb' => $result['thumb'],
                    );
                }
            }
        }

        // An empty result is cached briefly too, so a failing lookup is not
        // retried on every page view.
        set_transient( $key, $channel, $channel ? DAY_IN_SECONDS : self::RETRY_AFTER );

        return $channel;
    }

    /* ---------------------------------------------------------------
     * Cache
     * ------------------------------------------------------------- */

    /**
     * Transient name for a gallery payload. Includes a generation number so
     * flush_cache() works with a persistent object cache, where transients
     * cannot be found by name pattern.
     */
    private static function cache_key( $kind, $parts ) {
        array_unshift( $parts, (int) get_option( self::VERSION_OPTION, 1 ) );

        return 'bltt_gal_' . $kind . '_' . md5( implode( '|', $parts ) );
    }

    /**
     * Drop every cached gallery; each one refetches on its next view.
     */
    public static function flush_cache() {
        global $wpdb;

        update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 1 ) + 1, true );

        // The old generation is now unreachable. Without a persistent object
        // cache it lives in the options table, so remove it rather than leave
        // it to expire.
        if ( ! wp_using_ext_object_cache() ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_bltt_gal_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_bltt_gal_' ) . '%'
            ) );
        }
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private static function order_and_limit( $videos, $order, $max ) {
        if ( 'newest' === $order ) {
            usort( $videos, static function ( $a, $b ) {
                return strtotime( $b['published'] ) <=> strtotime( $a['published'] );
            } );
        }

        return array_slice( $videos, 0, $max );
    }

    private static function is_valid_video_id( $id ) {
        return is_string( $id ) && (bool) preg_match( '/^[A-Za-z0-9_-]{11}$/', $id );
    }

    /**
     * Single-spaced and capped — what is stored in the cache.
     *
     * YouTube titles and descriptions are plain text, not HTML, so nothing is
     * stripped here: strip_tags() would eat the rest of a title like
     * "I <3 this". Everything is escaped where it is printed instead.
     */
    private static function clean_text( $text, $max_chars ) {
        $collapsed = preg_replace( '/\s+/u', ' ', (string) $text );
        $text      = trim( null === $collapsed ? (string) $text : $collapsed );

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $text, 0, $max_chars );
        }

        return substr( $text, 0, $max_chars );
    }

    private static function pick( $value, $allowed, $default ) {
        $value = strtolower( trim( (string) $value ) );

        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    private static function clamp( $value, $min, $max, $default ) {
        $value = trim( (string) $value );

        if ( '' === $value || ! ctype_digit( $value ) ) {
            return $default;
        }

        return max( $min, min( $max, (int) $value ) );
    }

    private static function is_truthy( $value ) {
        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
    }
}
