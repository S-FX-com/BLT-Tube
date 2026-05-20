<?php
/**
 * Shortcode for embedding YouTube playlists.
 *
 * Uses YouTube's public IFrame Player embed which does NOT require an API key.
 * The API key configured in plugin settings is only used for the import/sync features.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLTT_Shortcode {

    const TAG = 'blt_tube_playlist';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( self::TAG, array( $this, 'render' ) );
    }

    /**
     * Render the [blt_tube_playlist] shortcode.
     */
    public function render( $atts ) {
        $atts = shortcode_atts( array(
            'id'         => '',
            'width'      => '560',
            'height'     => '315',
            'responsive' => 'yes',
            'autoplay'   => 'no',
            'loop'       => 'no',
            'mute'       => 'no',
            'controls'   => 'yes',
            'privacy'    => 'yes',
            'start'      => '',
        ), $atts, self::TAG );

        $playlist_id = trim( (string) $atts['id'] );

        if ( '' === $playlist_id ) {
            $settings    = get_option( 'bltt_settings', array() );
            $playlist_id = isset( $settings['playlist_id'] ) ? trim( $settings['playlist_id'] ) : '';
        }

        if ( '' === $playlist_id || ! $this->is_valid_playlist_id( $playlist_id ) ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p><em>' . esc_html__( 'BLT Tube: no valid playlist ID provided. Set one in BLT Tube settings or pass id="PL..." to the shortcode.', 'blt-tube' ) . '</em></p>';
            }
            return '';
        }

        $is_truthy = function ( $v ) {
            return in_array( strtolower( (string) $v ), array( '1', 'yes', 'true', 'on' ), true );
        };

        $host = $is_truthy( $atts['privacy'] ) ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';

        $params = array(
            'list'           => $playlist_id,
            'listType'       => 'playlist',
            'rel'            => '0',
            'modestbranding' => '1',
            'autoplay'       => $is_truthy( $atts['autoplay'] ) ? '1' : '0',
            'mute'           => $is_truthy( $atts['mute'] ) ? '1' : '0',
            'controls'       => $is_truthy( $atts['controls'] ) ? '1' : '0',
            'loop'           => $is_truthy( $atts['loop'] ) ? '1' : '0',
        );

        if ( '' !== $atts['start'] && ctype_digit( (string) $atts['start'] ) ) {
            $params['index'] = max( 0, intval( $atts['start'] ) - 1 );
        }

        $src = $host . '/embed/videoseries?' . http_build_query( $params );

        $title = sprintf(
            /* translators: %s: playlist ID */
            __( 'YouTube playlist %s', 'blt-tube' ),
            $playlist_id
        );

        $iframe_attrs = sprintf(
            'src="%s" title="%s" frameborder="0" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen',
            esc_url( $src ),
            esc_attr( $title )
        );

        if ( $is_truthy( $atts['responsive'] ) ) {
            return sprintf(
                '<div class="bltt-playlist-embed" style="position:relative;width:100%%;max-width:100%%;aspect-ratio:16/9;"><iframe %s style="position:absolute;inset:0;width:100%%;height:100%%;border:0;"></iframe></div>',
                $iframe_attrs
            );
        }

        $width  = $this->sanitize_dimension( $atts['width'], '560' );
        $height = $this->sanitize_dimension( $atts['height'], '315' );

        return sprintf(
            '<div class="bltt-playlist-embed"><iframe width="%s" height="%s" %s></iframe></div>',
            esc_attr( $width ),
            esc_attr( $height ),
            $iframe_attrs
        );
    }

    /**
     * YouTube playlist IDs always start with one of these prefixes and contain
     * only URL-safe characters.
     */
    private function is_valid_playlist_id( $id ) {
        return (bool) preg_match( '/^(PL|UU|LL|FL|RD|OL)[A-Za-z0-9_-]{10,}$/', $id );
    }

    private function sanitize_dimension( $value, $default ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return $default;
        }
        if ( ctype_digit( $value ) ) {
            return $value;
        }
        if ( preg_match( '/^\d+(\.\d+)?(px|%|em|rem|vw|vh)$/', $value ) ) {
            return $value;
        }
        return $default;
    }
}
