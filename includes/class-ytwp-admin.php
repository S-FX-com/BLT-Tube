<?php
/**
 * Admin pages, settings, and AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YTWP_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_ytwp_validate_api_key', array( $this, 'ajax_validate_api_key' ) );
        add_action( 'wp_ajax_ytwp_search_channels', array( $this, 'ajax_search_channels' ) );
        add_action( 'wp_ajax_ytwp_get_playlists', array( $this, 'ajax_get_playlists' ) );
        add_action( 'wp_ajax_ytwp_get_post_type_fields', array( $this, 'ajax_get_post_type_fields' ) );
        add_action( 'wp_ajax_ytwp_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_ytwp_manual_sync', array( $this, 'ajax_manual_sync' ) );
        add_action( 'wp_ajax_ytwp_sync_status', array( $this, 'ajax_sync_status' ) );
    }

    public function add_menu_pages() {
        add_menu_page(
            __( 'YouTube to WP', 'yt-to-wp' ),
            __( 'YouTube to WP', 'yt-to-wp' ),
            'manage_options',
            'ytwp-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-youtube',
            30
        );

        add_submenu_page(
            'ytwp-settings',
            __( 'Sync Log', 'yt-to-wp' ),
            __( 'Sync Log', 'yt-to-wp' ),
            'manage_options',
            'ytwp-sync-log',
            array( $this, 'render_sync_log_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'ytwp' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'ytwp-admin',
            YTWP_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            YTWP_VERSION
        );

        wp_enqueue_script(
            'ytwp-admin',
            YTWP_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            YTWP_VERSION,
            true
        );

        wp_localize_script( 'ytwp-admin', 'ytwpAdmin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ytwp_nonce' ),
        ) );
    }

    /**
     * Main settings page.
     */
    public function render_settings_page() {
        $settings = get_option( 'ytwp_settings', array() );

        $post_types     = get_post_types( array( 'public' => true ), 'objects' );
        $cadence_options = array(
            'every_5_min'  => __( 'Every 5 Minutes', 'yt-to-wp' ),
            'every_15_min' => __( 'Every 15 Minutes', 'yt-to-wp' ),
            'hourly'       => __( 'Hourly', 'yt-to-wp' ),
            'twicedaily'   => __( 'Twice Daily', 'yt-to-wp' ),
            'daily'        => __( 'Daily', 'yt-to-wp' ),
            'weekly'       => __( 'Weekly', 'yt-to-wp' ),
            'disabled'     => __( 'Disabled (Manual Only)', 'yt-to-wp' ),
        );

        // Available YouTube fields that can be mapped.
        $youtube_fields = array(
            'video_url'      => __( 'Video URL (watch link)', 'yt-to-wp' ),
            'embed_url'      => __( 'Embed URL', 'yt-to-wp' ),
            'title'          => __( 'Video Title', 'yt-to-wp' ),
            'description'    => __( 'Video Description', 'yt-to-wp' ),
            'published_at'   => __( 'Published Date', 'yt-to-wp' ),
            'channel_title'  => __( 'Channel Name', 'yt-to-wp' ),
            'thumbnail_url'  => __( 'Thumbnail URL', 'yt-to-wp' ),
            'duration'       => __( 'Duration', 'yt-to-wp' ),
            'view_count'     => __( 'View Count', 'yt-to-wp' ),
            'like_count'     => __( 'Like Count', 'yt-to-wp' ),
            'comment_count'  => __( 'Comment Count', 'yt-to-wp' ),
            'video_id'       => __( 'YouTube Video ID', 'yt-to-wp' ),
            'transcript'     => __( 'Transcript', 'yt-to-wp' ),
        );
        ?>
        <div class="wrap ytwp-wrap">
            <h1><?php esc_html_e( 'YouTube Playlist to WordPress', 'yt-to-wp' ); ?></h1>

            <div class="ytwp-notices" id="ytwp-notices"></div>

            <form id="ytwp-settings-form" method="post">
                <?php wp_nonce_field( 'ytwp_nonce', 'ytwp_nonce_field' ); ?>

                <!-- Section 1: API Key -->
                <div class="ytwp-card">
                    <h2><?php esc_html_e( '1. YouTube API Key', 'yt-to-wp' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Enter your YouTube Data API v3 key. You can create one in the Google Cloud Console.', 'yt-to-wp' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="ytwp_api_key"><?php esc_html_e( 'API Key', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <input type="text" id="ytwp_api_key" name="api_key"
                                       value="<?php echo esc_attr( isset( $settings['api_key'] ) ? $settings['api_key'] : '' ); ?>"
                                       class="regular-text" />
                                <button type="button" id="ytwp-validate-key" class="button button-secondary">
                                    <?php esc_html_e( 'Validate Key', 'yt-to-wp' ); ?>
                                </button>
                                <span id="ytwp-key-status"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 2: Channel & Playlist Selection -->
                <div class="ytwp-card">
                    <h2><?php esc_html_e( '2. Select YouTube Playlist', 'yt-to-wp' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="ytwp_channel_search"><?php esc_html_e( 'Search Channel', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <input type="text" id="ytwp_channel_search" class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Enter channel name...', 'yt-to-wp' ); ?>" />
                                <button type="button" id="ytwp-search-channels" class="button button-secondary">
                                    <?php esc_html_e( 'Search', 'yt-to-wp' ); ?>
                                </button>
                                <div id="ytwp-channel-results" class="ytwp-results-list"></div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Or Enter Playlist ID Directly', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <input type="text" id="ytwp_playlist_id" name="playlist_id"
                                       value="<?php echo esc_attr( isset( $settings['playlist_id'] ) ? $settings['playlist_id'] : '' ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'PLxxxxxxxxxxxxxxxx', 'yt-to-wp' ); ?>" />
                            </td>
                        </tr>
                        <tr id="ytwp-playlists-row" style="display:none;">
                            <th><label><?php esc_html_e( 'Available Playlists', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <select id="ytwp-playlists-select" class="regular-text">
                                    <option value=""><?php esc_html_e( '— Select a playlist —', 'yt-to-wp' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 3: Post Type & Field Mapping -->
                <div class="ytwp-card">
                    <h2><?php esc_html_e( '3. Post Type & Field Mapping', 'yt-to-wp' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="ytwp_post_type"><?php esc_html_e( 'Target Post Type', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <select id="ytwp_post_type" name="post_type" class="regular-text">
                                    <?php foreach ( $post_types as $pt ) : ?>
                                        <option value="<?php echo esc_attr( $pt->name ); ?>"
                                            <?php selected( isset( $settings['post_type'] ) ? $settings['post_type'] : 'post', $pt->name ); ?>>
                                            <?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="ytwp-load-fields" class="button button-secondary">
                                    <?php esc_html_e( 'Load Custom Fields', 'yt-to-wp' ); ?>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Video Description', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <select id="ytwp_description_target" name="description_target" class="regular-text">
                                    <option value="post_content" <?php selected( isset( $settings['description_target'] ) ? $settings['description_target'] : 'post_content', 'post_content' ); ?>>
                                        <?php esc_html_e( 'Post Content (body)', 'yt-to-wp' ); ?>
                                    </option>
                                    <option value="custom_field" <?php selected( isset( $settings['description_target'] ) ? $settings['description_target'] : '', 'custom_field' ); ?>>
                                        <?php esc_html_e( 'Map to a custom field (select below)', 'yt-to-wp' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Transcript', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <select id="ytwp_transcript_target" name="transcript_target" class="regular-text">
                                    <option value="" <?php selected( isset( $settings['transcript_target'] ) ? $settings['transcript_target'] : '', '' ); ?>>
                                        <?php esc_html_e( '— Do not import —', 'yt-to-wp' ); ?>
                                    </option>
                                    <option value="post_content" <?php selected( isset( $settings['transcript_target'] ) ? $settings['transcript_target'] : '', 'post_content' ); ?>>
                                        <?php esc_html_e( 'Append to Post Content (body)', 'yt-to-wp' ); ?>
                                    </option>
                                    <option value="custom_field" <?php selected( isset( $settings['transcript_target'] ) ? $settings['transcript_target'] : '', 'custom_field' ); ?>>
                                        <?php esc_html_e( 'Map to a custom field (select below)', 'yt-to-wp' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Set Featured Image', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ytwp_set_thumbnail" name="set_thumbnail" value="1"
                                        <?php checked( isset( $settings['set_thumbnail'] ) ? $settings['set_thumbnail'] : true ); ?> />
                                    <?php esc_html_e( 'Download YouTube thumbnail and set as featured image', 'yt-to-wp' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Assign Keywords', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ytwp_assign_keywords" name="assign_keywords" value="1"
                                        <?php checked( isset( $settings['assign_keywords'] ) ? $settings['assign_keywords'] : true ); ?> />
                                    <?php esc_html_e( 'Import YouTube tags as post tags / keywords', 'yt-to-wp' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e( 'Custom Field Mapping', 'yt-to-wp' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Map YouTube video data to custom fields on your chosen post type. Click "Load Custom Fields" above to populate available fields.', 'yt-to-wp' ); ?>
                    </p>
                    <div id="ytwp-field-mapping-container">
                        <table class="widefat ytwp-mapping-table" id="ytwp-mapping-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'YouTube Data', 'yt-to-wp' ); ?></th>
                                    <th><?php esc_html_e( 'WordPress Custom Field', 'yt-to-wp' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'yt-to-wp' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="ytwp-mapping-rows">
                                <?php
                                $field_mapping = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();
                                if ( ! empty( $field_mapping ) ) :
                                    foreach ( $field_mapping as $yt_field => $wp_field ) :
                                        ?>
                                        <tr class="ytwp-mapping-row">
                                            <td>
                                                <select name="yt_fields[]" class="regular-text ytwp-yt-field-select">
                                                    <option value=""><?php esc_html_e( '— Select —', 'yt-to-wp' ); ?></option>
                                                    <?php foreach ( $youtube_fields as $key => $label ) : ?>
                                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $yt_field, $key ); ?>>
                                                            <?php echo esc_html( $label ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="wp_fields[]" value="<?php echo esc_attr( $wp_field ); ?>"
                                                       class="regular-text ytwp-wp-field-input"
                                                       placeholder="<?php esc_attr_e( 'meta_key or select from loaded fields', 'yt-to-wp' ); ?>" />
                                                <select class="regular-text ytwp-wp-field-select" style="display:none;">
                                                    <option value=""><?php esc_html_e( '— Or choose detected field —', 'yt-to-wp' ); ?></option>
                                                </select>
                                            </td>
                                            <td><button type="button" class="button ytwp-remove-row">&times;</button></td>
                                        </tr>
                                        <?php
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                        <p>
                            <button type="button" id="ytwp-add-mapping" class="button button-secondary">
                                <?php esc_html_e( '+ Add Field Mapping', 'yt-to-wp' ); ?>
                            </button>
                        </p>
                    </div>

                    <!-- Hidden template row for JS cloning -->
                    <script type="text/html" id="tmpl-ytwp-mapping-row">
                        <tr class="ytwp-mapping-row">
                            <td>
                                <select name="yt_fields[]" class="regular-text ytwp-yt-field-select">
                                    <option value=""><?php esc_html_e( '— Select —', 'yt-to-wp' ); ?></option>
                                    <?php foreach ( $youtube_fields as $key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="wp_fields[]" value=""
                                       class="regular-text ytwp-wp-field-input"
                                       placeholder="<?php esc_attr_e( 'meta_key or select from loaded fields', 'yt-to-wp' ); ?>" />
                                <select class="regular-text ytwp-wp-field-select" style="display:none;">
                                    <option value=""><?php esc_html_e( '— Or choose detected field —', 'yt-to-wp' ); ?></option>
                                </select>
                            </td>
                            <td><button type="button" class="button ytwp-remove-row">&times;</button></td>
                        </tr>
                    </script>
                </div>

                <!-- Section 4: Sync Settings -->
                <div class="ytwp-card">
                    <h2><?php esc_html_e( '4. Sync Settings', 'yt-to-wp' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="ytwp_sync_cadence"><?php esc_html_e( 'Auto-Sync Cadence', 'yt-to-wp' ); ?></label></th>
                            <td>
                                <select id="ytwp_sync_cadence" name="sync_cadence" class="regular-text">
                                    <?php foreach ( $cadence_options as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"
                                            <?php selected( isset( $settings['sync_cadence'] ) ? $settings['sync_cadence'] : 'daily', $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'How often the plugin should check for new videos in the playlist.', 'yt-to-wp' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Actions -->
                <div class="ytwp-card ytwp-actions-card">
                    <p class="submit">
                        <button type="submit" id="ytwp-save-settings" class="button button-primary button-hero">
                            <?php esc_html_e( 'Save Settings', 'yt-to-wp' ); ?>
                        </button>
                        <button type="button" id="ytwp-manual-sync" class="button button-secondary button-hero">
                            <?php esc_html_e( 'Sync All Videos Now', 'yt-to-wp' ); ?>
                        </button>
                    </p>
                    <div id="ytwp-sync-progress" style="display:none;">
                        <div class="ytwp-progress-bar">
                            <div class="ytwp-progress-bar-inner" id="ytwp-progress-inner" style="width:0%"></div>
                        </div>
                        <p id="ytwp-sync-status-text"></p>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Sync log page.
     */
    public function render_sync_log_page() {
        $log = get_option( 'ytwp_sync_log', array() );
        ?>
        <div class="wrap ytwp-wrap">
            <h1><?php esc_html_e( 'Sync Log', 'yt-to-wp' ); ?></h1>
            <?php if ( empty( $log ) ) : ?>
                <p><?php esc_html_e( 'No sync operations have been performed yet.', 'yt-to-wp' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'yt-to-wp' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'yt-to-wp' ); ?></th>
                            <th><?php esc_html_e( 'Videos Found', 'yt-to-wp' ); ?></th>
                            <th><?php esc_html_e( 'Imported', 'yt-to-wp' ); ?></th>
                            <th><?php esc_html_e( 'Skipped', 'yt-to-wp' ); ?></th>
                            <th><?php esc_html_e( 'Errors', 'yt-to-wp' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_reverse( $log ) as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['date'] ); ?></td>
                                <td><?php echo esc_html( $entry['type'] ); ?></td>
                                <td><?php echo esc_html( $entry['found'] ); ?></td>
                                <td><?php echo esc_html( $entry['imported'] ); ?></td>
                                <td><?php echo esc_html( $entry['skipped'] ); ?></td>
                                <td><?php echo esc_html( $entry['errors'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------
     * AJAX Handlers
     * ------------------------------------------------------------- */

    public function ajax_validate_api_key() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'Please enter an API key.' );
        }

        $api = new YTWP_YouTube_API( $api_key );
        if ( $api->validate_key() ) {
            wp_send_json_success( 'API key is valid.' );
        } else {
            wp_send_json_error( 'API key validation failed. Please check the key and ensure the YouTube Data API v3 is enabled.' );
        }
    }

    public function ajax_search_channels() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
        if ( empty( $query ) ) {
            wp_send_json_error( 'Enter a search term.' );
        }

        $settings = get_option( 'ytwp_settings', array() );
        $api      = new YTWP_YouTube_API( $settings['api_key'] ?? '' );
        $channels = $api->search_channels( $query );

        if ( is_wp_error( $channels ) ) {
            wp_send_json_error( $channels->get_error_message() );
        }

        wp_send_json_success( $channels );
    }

    public function ajax_get_playlists() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $channel_id = sanitize_text_field( wp_unslash( $_POST['channel_id'] ?? '' ) );
        if ( empty( $channel_id ) ) {
            wp_send_json_error( 'No channel selected.' );
        }

        $settings  = get_option( 'ytwp_settings', array() );
        $api       = new YTWP_YouTube_API( $settings['api_key'] ?? '' );
        $playlists = $api->get_channel_playlists( $channel_id );

        if ( is_wp_error( $playlists ) ) {
            wp_send_json_error( $playlists->get_error_message() );
        }

        wp_send_json_success( $playlists );
    }

    public function ajax_get_post_type_fields() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? 'post' ) );

        $fields = $this->discover_custom_fields( $post_type );

        wp_send_json_success( $fields );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $yt_fields = isset( $_POST['yt_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['yt_fields'] ) ) : array();
        $wp_fields = isset( $_POST['wp_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['wp_fields'] ) ) : array();

        $field_mapping = array();
        foreach ( $yt_fields as $i => $yt ) {
            $wp = isset( $wp_fields[ $i ] ) ? $wp_fields[ $i ] : '';
            if ( ! empty( $yt ) && ! empty( $wp ) ) {
                $field_mapping[ $yt ] = $wp;
            }
        }

        $old_settings = get_option( 'ytwp_settings', array() );

        $settings = array(
            'api_key'            => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
            'playlist_id'        => sanitize_text_field( wp_unslash( $_POST['playlist_id'] ?? '' ) ),
            'post_type'          => sanitize_key( wp_unslash( $_POST['post_type'] ?? 'post' ) ),
            'field_mapping'      => $field_mapping,
            'sync_cadence'       => sanitize_key( wp_unslash( $_POST['sync_cadence'] ?? 'daily' ) ),
            'description_target' => sanitize_key( wp_unslash( $_POST['description_target'] ?? 'post_content' ) ),
            'transcript_target'  => sanitize_key( wp_unslash( $_POST['transcript_target'] ?? '' ) ),
            'set_thumbnail'      => ! empty( $_POST['set_thumbnail'] ),
            'assign_keywords'    => ! empty( $_POST['assign_keywords'] ),
        );

        update_option( 'ytwp_settings', $settings );

        // Reschedule cron if cadence changed.
        $old_cadence = isset( $old_settings['sync_cadence'] ) ? $old_settings['sync_cadence'] : '';
        if ( $settings['sync_cadence'] !== $old_cadence ) {
            YTWP_Cron::unschedule_sync();
            if ( 'disabled' !== $settings['sync_cadence'] ) {
                YTWP_Cron::schedule_sync();
            }
        }

        wp_send_json_success( 'Settings saved.' );
    }

    public function ajax_manual_sync() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $engine = new YTWP_Sync_Engine();
        $result = $engine->sync_all();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_sync_status() {
        check_ajax_referer( 'ytwp_nonce', 'nonce' );

        $status = get_transient( 'ytwp_sync_progress' );
        wp_send_json_success( $status ? $status : array( 'status' => 'idle' ) );
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /**
     * Discover custom fields (meta keys) used by a post type.
     *
     * Pulls from existing postmeta and registered meta keys,
     * as well as ACF field groups if available.
     */
    private function discover_custom_fields( $post_type ) {
        global $wpdb;

        $fields = array();

        // 1. Query existing meta keys from the database.
        $meta_keys = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s
             AND pm.meta_key NOT LIKE %s
             ORDER BY pm.meta_key ASC
             LIMIT 200",
            $post_type,
            $wpdb->esc_like( '_' ) . '%'
        ) );

        if ( $meta_keys ) {
            foreach ( $meta_keys as $key ) {
                $fields[ $key ] = $key;
            }
        }

        // 2. Registered meta keys.
        $registered = get_registered_meta_keys( 'post', $post_type );
        foreach ( $registered as $key => $args ) {
            if ( ! isset( $fields[ $key ] ) ) {
                $fields[ $key ] = $key;
            }
        }

        // 3. ACF fields if available.
        if ( function_exists( 'acf_get_field_groups' ) ) {
            $groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
            foreach ( $groups as $group ) {
                $acf_fields = acf_get_fields( $group['key'] );
                if ( $acf_fields ) {
                    foreach ( $acf_fields as $field ) {
                        $fields[ $field['name'] ] = $field['label'] . ' (' . $field['name'] . ')';
                    }
                }
            }
        }

        return $fields;
    }
}
