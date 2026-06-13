<?php
/**
 * Admin pages, settings, and AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLTT_Admin {

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

        add_action( 'wp_ajax_bltt_validate_api_key', array( $this, 'ajax_validate_api_key' ) );
        add_action( 'wp_ajax_bltt_search_channels', array( $this, 'ajax_search_channels' ) );
        add_action( 'wp_ajax_bltt_get_playlists', array( $this, 'ajax_get_playlists' ) );
        add_action( 'wp_ajax_bltt_get_post_type_fields', array( $this, 'ajax_get_post_type_fields' ) );
        add_action( 'wp_ajax_bltt_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_bltt_preview_playlist', array( $this, 'ajax_preview_playlist' ) );
        add_action( 'wp_ajax_bltt_import_selected', array( $this, 'ajax_import_selected' ) );
        add_action( 'wp_ajax_bltt_sync_updates', array( $this, 'ajax_sync_updates' ) );
        add_action( 'wp_ajax_bltt_sync_status', array( $this, 'ajax_sync_status' ) );
    }

    public function add_menu_pages() {
        add_menu_page(
            __( 'BLT Tube', 'blt-tube' ),
            __( 'BLT Tube', 'blt-tube' ),
            'manage_options',
            'bltt-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-youtube',
            30
        );

        add_submenu_page(
            'bltt-settings',
            __( 'Sync Log', 'blt-tube' ),
            __( 'Sync Log', 'blt-tube' ),
            'manage_options',
            'bltt-sync-log',
            array( $this, 'render_sync_log_page' )
        );

        add_submenu_page(
            'bltt-settings',
            __( 'Shortcode Help', 'blt-tube' ),
            __( 'Shortcode Help', 'blt-tube' ),
            'manage_options',
            'bltt-shortcode-help',
            array( $this, 'render_shortcode_help_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'bltt' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'bltt-admin',
            BLTT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BLTT_VERSION
        );

        wp_enqueue_script(
            'bltt-admin',
            BLTT_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            BLTT_VERSION,
            true
        );

        wp_localize_script( 'bltt-admin', 'blttAdmin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bltt_nonce' ),
        ) );
    }

    /**
     * Main settings page.
     */
    public function render_settings_page() {
        $settings = get_option( 'bltt_settings', array() );

        $sync_hour    = isset( $settings['sync_hour'] )    ? (int) $settings['sync_hour']    : 3;
        $sync_minute  = isset( $settings['sync_minute'] )  ? (int) $settings['sync_minute']  : 0;
        $sync_weekday = isset( $settings['sync_weekday'] ) ? (int) $settings['sync_weekday'] : 1;

        $post_types     = get_post_types( array( 'public' => true ), 'objects' );
        $cadence_options = array(
            'every_5_min'  => __( 'Every 5 Minutes', 'blt-tube' ),
            'every_15_min' => __( 'Every 15 Minutes', 'blt-tube' ),
            'hourly'       => __( 'Hourly', 'blt-tube' ),
            'twicedaily'   => __( 'Twice Daily', 'blt-tube' ),
            'daily'        => __( 'Daily', 'blt-tube' ),
            'weekly'       => __( 'Weekly', 'blt-tube' ),
            'disabled'     => __( 'Disabled (Manual Only)', 'blt-tube' ),
        );

        $youtube_fields = array(
            'video_url'      => __( 'Video URL (watch link)', 'blt-tube' ),
            'embed_url'      => __( 'Embed URL', 'blt-tube' ),
            'title'          => __( 'Video Title', 'blt-tube' ),
            'description'    => __( 'Video Description', 'blt-tube' ),
            'published_at'   => __( 'Published Date', 'blt-tube' ),
            'channel_title'  => __( 'Channel Name', 'blt-tube' ),
            'thumbnail_url'  => __( 'Thumbnail URL', 'blt-tube' ),
            'duration'       => __( 'Duration', 'blt-tube' ),
            'view_count'     => __( 'View Count', 'blt-tube' ),
            'like_count'     => __( 'Like Count', 'blt-tube' ),
            'comment_count'  => __( 'Comment Count', 'blt-tube' ),
            'video_id'       => __( 'YouTube Video ID', 'blt-tube' ),
            'transcript'     => __( 'Transcript', 'blt-tube' ),
        );
        ?>
        <div class="wrap bltt-wrap">
            <h1><?php esc_html_e( 'BLT Tube', 'blt-tube' ); ?></h1>

            <div class="bltt-notices" id="bltt-notices"></div>

            <form id="bltt-settings-form" method="post">
                <?php wp_nonce_field( 'bltt_nonce', 'bltt_nonce_field' ); ?>

                <!-- Section 1: API Key -->
                <div class="bltt-card">
                    <h2><?php esc_html_e( '1. YouTube API Key', 'blt-tube' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'A YouTube Data API v3 key is required only to IMPORT video metadata (titles, descriptions, tags, durations, etc.). It is NOT needed for the [blt_tube_playlist] shortcode, which uses YouTube\'s public iframe embed.', 'blt-tube' ); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label for="bltt_api_key"><?php esc_html_e( 'API Key', 'blt-tube' ); ?></label></th>
                            <td>
                                <input type="text" id="bltt_api_key" name="api_key"
                                       value="<?php echo esc_attr( isset( $settings['api_key'] ) ? $settings['api_key'] : '' ); ?>"
                                       class="regular-text" />
                                <button type="button" id="bltt-validate-key" class="button button-secondary">
                                    <?php esc_html_e( 'Validate Key', 'blt-tube' ); ?>
                                </button>
                                <span id="bltt-key-status"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 2: Channel & Playlist Selection -->
                <div class="bltt-card">
                    <h2><?php esc_html_e( '2. Select YouTube Playlist', 'blt-tube' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="bltt_channel_search"><?php esc_html_e( 'Search Channel', 'blt-tube' ); ?></label></th>
                            <td>
                                <input type="text" id="bltt_channel_search" class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Enter channel name...', 'blt-tube' ); ?>" />
                                <button type="button" id="bltt-search-channels" class="button button-secondary">
                                    <?php esc_html_e( 'Search', 'blt-tube' ); ?>
                                </button>
                                <div id="bltt-channel-results" class="bltt-results-list"></div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Or Enter Playlist ID Directly', 'blt-tube' ); ?></label></th>
                            <td>
                                <input type="text" id="bltt_playlist_id" name="playlist_id"
                                       value="<?php echo esc_attr( isset( $settings['playlist_id'] ) ? $settings['playlist_id'] : '' ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'PLxxxxxxxxxxxxxxxx', 'blt-tube' ); ?>" />
                            </td>
                        </tr>
                        <tr id="bltt-playlists-row" style="display:none;">
                            <th><label><?php esc_html_e( 'Available Playlists', 'blt-tube' ); ?></label></th>
                            <td>
                                <select id="bltt-playlists-select" class="regular-text">
                                    <option value=""><?php esc_html_e( '— Select a playlist —', 'blt-tube' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Section 3: Post Type & Field Mapping -->
                <div class="bltt-card">
                    <h2><?php esc_html_e( '3. Post Type & Field Mapping', 'blt-tube' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="bltt_post_type"><?php esc_html_e( 'Target Post Type', 'blt-tube' ); ?></label></th>
                            <td>
                                <select id="bltt_post_type" name="post_type" class="regular-text">
                                    <?php foreach ( $post_types as $pt ) : ?>
                                        <option value="<?php echo esc_attr( $pt->name ); ?>"
                                            <?php selected( isset( $settings['post_type'] ) ? $settings['post_type'] : 'post', $pt->name ); ?>>
                                            <?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="bltt-load-fields" class="button button-secondary">
                                    <?php esc_html_e( 'Load Custom Fields', 'blt-tube' ); ?>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Video Description', 'blt-tube' ); ?></label></th>
                            <td>
                                <select id="bltt_description_target" name="description_target" class="regular-text">
                                    <option value="post_content" <?php selected( isset( $settings['description_target'] ) ? $settings['description_target'] : 'post_content', 'post_content' ); ?>>
                                        <?php esc_html_e( 'Post Content (body)', 'blt-tube' ); ?>
                                    </option>
                                    <option value="custom_field" <?php selected( isset( $settings['description_target'] ) ? $settings['description_target'] : '', 'custom_field' ); ?>>
                                        <?php esc_html_e( 'Map to a custom field (select below)', 'blt-tube' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Transcript', 'blt-tube' ); ?></label></th>
                            <td>
                                <select id="bltt_transcript_target" name="transcript_target" class="regular-text">
                                    <option value="" <?php selected( isset( $settings['transcript_target'] ) ? $settings['transcript_target'] : '', '' ); ?>>
                                        <?php esc_html_e( '— Do not import —', 'blt-tube' ); ?>
                                    </option>
                                    <option value="post_content" <?php selected( isset( $settings['transcript_target'] ) ? $settings['transcript_target'] : '', 'post_content' ); ?>>
                                        <?php esc_html_e( 'Append to Post Content (body)', 'blt-tube' ); ?>
                                    </option>
                                    <option value="custom_field" <?php selected( isset( $settings['transcript_target'] ) ? $settings['transcript_target'] : '', 'custom_field' ); ?>>
                                        <?php esc_html_e( 'Map to a custom field (select below)', 'blt-tube' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Set Featured Image', 'blt-tube' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="bltt_set_thumbnail" name="set_thumbnail" value="1"
                                        <?php checked( isset( $settings['set_thumbnail'] ) ? $settings['set_thumbnail'] : true ); ?> />
                                    <?php esc_html_e( 'Download YouTube thumbnail and set as featured image', 'blt-tube' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Assign Keywords', 'blt-tube' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="bltt_assign_keywords" name="assign_keywords" value="1"
                                        <?php checked( isset( $settings['assign_keywords'] ) ? $settings['assign_keywords'] : true ); ?> />
                                    <?php esc_html_e( 'Import YouTube tags as post tags / keywords', 'blt-tube' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e( 'Custom Field Mapping', 'blt-tube' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Map YouTube video data to custom fields on your chosen post type. Click "Load Custom Fields" above to populate available fields.', 'blt-tube' ); ?>
                    </p>
                    <div id="bltt-field-mapping-container">
                        <table class="widefat bltt-mapping-table" id="bltt-mapping-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'YouTube Data', 'blt-tube' ); ?></th>
                                    <th><?php esc_html_e( 'WordPress Custom Field', 'blt-tube' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'blt-tube' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="bltt-mapping-rows">
                                <?php
                                $field_mapping = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();
                                if ( ! empty( $field_mapping ) ) :
                                    foreach ( $field_mapping as $yt_field => $wp_field ) :
                                        ?>
                                        <tr class="bltt-mapping-row">
                                            <td>
                                                <select name="yt_fields[]" class="regular-text bltt-yt-field-select">
                                                    <option value=""><?php esc_html_e( '— Select —', 'blt-tube' ); ?></option>
                                                    <?php foreach ( $youtube_fields as $key => $label ) : ?>
                                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $yt_field, $key ); ?>>
                                                            <?php echo esc_html( $label ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="wp_fields[]" value="<?php echo esc_attr( $wp_field ); ?>"
                                                       class="regular-text bltt-wp-field-input"
                                                       placeholder="<?php esc_attr_e( 'meta_key or select from loaded fields', 'blt-tube' ); ?>" />
                                                <select class="regular-text bltt-wp-field-select" style="display:none;">
                                                    <option value=""><?php esc_html_e( '— Or choose detected field —', 'blt-tube' ); ?></option>
                                                </select>
                                            </td>
                                            <td><button type="button" class="button bltt-remove-row">&times;</button></td>
                                        </tr>
                                        <?php
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                        <p>
                            <button type="button" id="bltt-add-mapping" class="button button-secondary">
                                <?php esc_html_e( '+ Add Field Mapping', 'blt-tube' ); ?>
                            </button>
                        </p>
                    </div>

                    <!-- Hidden template row for JS cloning -->
                    <script type="text/html" id="tmpl-bltt-mapping-row">
                        <tr class="bltt-mapping-row">
                            <td>
                                <select name="yt_fields[]" class="regular-text bltt-yt-field-select">
                                    <option value=""><?php esc_html_e( '— Select —', 'blt-tube' ); ?></option>
                                    <?php foreach ( $youtube_fields as $key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="wp_fields[]" value=""
                                       class="regular-text bltt-wp-field-input"
                                       placeholder="<?php esc_attr_e( 'meta_key or select from loaded fields', 'blt-tube' ); ?>" />
                                <select class="regular-text bltt-wp-field-select" style="display:none;">
                                    <option value=""><?php esc_html_e( '— Or choose detected field —', 'blt-tube' ); ?></option>
                                </select>
                            </td>
                            <td><button type="button" class="button bltt-remove-row">&times;</button></td>
                        </tr>
                    </script>
                </div>

                <!-- Section 4: Sync Settings -->
                <div class="bltt-card">
                    <h2><?php esc_html_e( '4. Sync Settings', 'blt-tube' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="bltt_sync_cadence"><?php esc_html_e( 'Auto-Sync Cadence', 'blt-tube' ); ?></label></th>
                            <td>
                                <select id="bltt_sync_cadence" name="sync_cadence" class="regular-text">
                                    <?php foreach ( $cadence_options as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"
                                            <?php selected( isset( $settings['sync_cadence'] ) ? $settings['sync_cadence'] : 'daily', $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'How often the plugin should check for new videos in the playlist.', 'blt-tube' ); ?></p>
                            </td>
                        </tr>
                        <tr id="bltt-sync-time-row">
                            <th><label><?php esc_html_e( 'Sync Time', 'blt-tube' ); ?></label></th>
                            <td>
                                <div class="bltt-sync-time-inner">
                                    <span id="bltt-sync-on-prefix"><?php esc_html_e( 'On', 'blt-tube' ); ?> </span>
                                    <select id="bltt_sync_weekday" name="sync_weekday">
                                        <?php
                                        $weekday_labels = array(
                                            0 => __( 'Sunday', 'blt-tube' ),
                                            1 => __( 'Monday', 'blt-tube' ),
                                            2 => __( 'Tuesday', 'blt-tube' ),
                                            3 => __( 'Wednesday', 'blt-tube' ),
                                            4 => __( 'Thursday', 'blt-tube' ),
                                            5 => __( 'Friday', 'blt-tube' ),
                                            6 => __( 'Saturday', 'blt-tube' ),
                                        );
                                        foreach ( $weekday_labels as $wday_num => $wday_name ) : ?>
                                            <option value="<?php echo esc_attr( $wday_num ); ?>"
                                                <?php selected( $sync_weekday, $wday_num ); ?>>
                                                <?php echo esc_html( $wday_name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span id="bltt-sync-at-word"></span>
                                    <select id="bltt_sync_hour" name="sync_hour">
                                        <?php for ( $h = 0; $h < 24; $h++ ) :
                                            $period       = $h < 12 ? 'AM' : 'PM';
                                            $display_hour = $h % 12 === 0 ? 12 : $h % 12;
                                            $hour_label   = sprintf( '%d:00 %s', $display_hour, $period );
                                        ?>
                                            <option value="<?php echo esc_attr( $h ); ?>"
                                                <?php selected( $sync_hour, $h ); ?>>
                                                <?php echo esc_html( $hour_label ); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <span id="bltt-sync-colon">:</span>
                                    <select id="bltt_sync_minute" name="sync_minute">
                                        <?php foreach ( array( 0, 15, 30, 45 ) as $m ) : ?>
                                            <option value="<?php echo esc_attr( $m ); ?>"
                                                <?php selected( $sync_minute, $m ); ?>>
                                                <?php echo esc_html( sprintf( '%02d', $m ) ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <p class="description" id="bltt-sync-time-desc"></p>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: WordPress timezone string */
                                        esc_html__( 'Times use the site timezone: %s', 'blt-tube' ),
                                        '<strong>' . esc_html( wp_timezone_string() ) . '</strong>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Save Settings -->
                <div class="bltt-card bltt-actions-card">
                    <p class="submit">
                        <button type="submit" id="bltt-save-settings" class="button button-primary button-hero">
                            <?php esc_html_e( 'Save Settings', 'blt-tube' ); ?>
                        </button>
                    </p>
                </div>
            </form>

            <!-- Section 5: Import & Sync — outside the form so its buttons don't submit it -->
            <div class="bltt-card" id="bltt-import-card">
                <h2><?php esc_html_e( '5. Import &amp; Sync Videos', 'blt-tube' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Load your playlist to preview all videos and choose which ones to import as posts. Use "Sync Existing Posts" to refresh already-imported posts with the latest data from YouTube.', 'blt-tube' ); ?>
                </p>
                <p style="margin-top:12px;">
                    <button type="button" id="bltt-load-playlist" class="button button-primary">
                        <?php esc_html_e( 'Load Playlist Videos', 'blt-tube' ); ?>
                    </button>
                    &nbsp;
                    <button type="button" id="bltt-sync-updates" class="button button-secondary">
                        <?php esc_html_e( 'Sync Existing Posts', 'blt-tube' ); ?>
                    </button>
                </p>

                <!-- Shared progress bar -->
                <div id="bltt-sync-progress" style="display:none; margin-top:12px;">
                    <div class="bltt-progress-bar">
                        <div class="bltt-progress-bar-inner" id="bltt-progress-inner" style="width:0%"></div>
                    </div>
                    <p id="bltt-sync-status-text"></p>
                </div>

                <!-- Preview table (populated after Load Playlist) -->
                <div id="bltt-preview-wrap" style="display:none; margin-top:20px;">
                    <p id="bltt-preview-summary"></p>
                    <p>
                        <label style="margin-right:14px;">
                            <input type="checkbox" id="bltt-select-all" checked />
                            <?php esc_html_e( 'Select / Deselect All', 'blt-tube' ); ?>
                        </label>
                        <button type="button" id="bltt-import-selected" class="button button-primary" disabled>
                            <?php esc_html_e( 'Import Selected', 'blt-tube' ); ?>
                        </button>
                    </p>
                    <table class="widefat striped" id="bltt-preview-table">
                        <thead>
                            <tr>
                                <th style="width:32px;"></th>
                                <th style="width:84px;"><?php esc_html_e( 'Thumbnail', 'blt-tube' ); ?></th>
                                <th><?php esc_html_e( 'Title', 'blt-tube' ); ?></th>
                                <th style="width:72px;"><?php esc_html_e( 'Duration', 'blt-tube' ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( 'Published', 'blt-tube' ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( 'Status', 'blt-tube' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="bltt-preview-rows"></tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Sync log page.
     */
    public function render_sync_log_page() {
        $log = get_option( 'bltt_sync_log', array() );
        ?>
        <div class="wrap bltt-wrap">
            <h1><?php esc_html_e( 'Sync Log', 'blt-tube' ); ?></h1>
            <?php if ( empty( $log ) ) : ?>
                <p><?php esc_html_e( 'No sync operations have been performed yet.', 'blt-tube' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Videos Found', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Imported', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Skipped', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Errors', 'blt-tube' ); ?></th>
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

    /**
     * Shortcode help / reference page.
     */
    public function render_shortcode_help_page() {
        $settings    = get_option( 'bltt_settings', array() );
        $playlist_id = isset( $settings['playlist_id'] ) ? $settings['playlist_id'] : '';
        ?>
        <div class="wrap bltt-wrap">
            <h1><?php esc_html_e( 'BLT Tube Shortcode', 'blt-tube' ); ?></h1>
            <div class="bltt-card">
                <h2><?php esc_html_e( 'Embed a YouTube Playlist', 'blt-tube' ); ?></h2>
                <p><?php esc_html_e( 'Drop this shortcode into any post, page, widget, or block to embed a YouTube playlist player. No API key is required for embedding.', 'blt-tube' ); ?></p>

                <h3><?php esc_html_e( 'Basic usage (uses the playlist configured in settings)', 'blt-tube' ); ?></h3>
                <pre><code>[blt_tube_playlist]</code></pre>

                <h3><?php esc_html_e( 'Specify a different playlist by ID', 'blt-tube' ); ?></h3>
                <pre><code>[blt_tube_playlist id="PLxxxxxxxxxxxxxxxx"]</code></pre>

                <?php if ( $playlist_id ) : ?>
                    <p>
                        <strong><?php esc_html_e( 'Your saved playlist ID:', 'blt-tube' ); ?></strong>
                        <code><?php echo esc_html( $playlist_id ); ?></code>
                    </p>
                <?php endif; ?>

                <h3><?php esc_html_e( 'All supported attributes', 'blt-tube' ); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Attribute', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Default', 'blt-tube' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'blt-tube' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>id</code></td><td><em><?php esc_html_e( 'saved playlist', 'blt-tube' ); ?></em></td><td><?php esc_html_e( 'YouTube playlist ID (begins with PL, UU, LL, FL, RD, OL).', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>width</code></td><td><code>560</code></td><td><?php esc_html_e( 'Player width in pixels, or use 100% for responsive.', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>height</code></td><td><code>315</code></td><td><?php esc_html_e( 'Player height in pixels (ignored when responsive is on).', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>responsive</code></td><td><code>yes</code></td><td><?php esc_html_e( 'When yes, scales to container width with 16:9 ratio.', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>autoplay</code></td><td><code>no</code></td><td><?php esc_html_e( 'Auto-play on page load (requires muted on most browsers).', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>loop</code></td><td><code>no</code></td><td><?php esc_html_e( 'Loop the playlist.', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>mute</code></td><td><code>no</code></td><td><?php esc_html_e( 'Start muted.', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>controls</code></td><td><code>yes</code></td><td><?php esc_html_e( 'Show player controls.', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>privacy</code></td><td><code>yes</code></td><td><?php esc_html_e( 'Use youtube-nocookie.com for privacy-enhanced mode.', 'blt-tube' ); ?></td></tr>
                        <tr><td><code>start</code></td><td><em><?php esc_html_e( 'none', 'blt-tube' ); ?></em></td><td><?php esc_html_e( 'Start at the Nth video (1-based index).', 'blt-tube' ); ?></td></tr>
                    </tbody>
                </table>

                <h3><?php esc_html_e( 'Full example', 'blt-tube' ); ?></h3>
                <pre><code>[blt_tube_playlist id="PLxxxx" responsive="yes" autoplay="no" privacy="yes"]</code></pre>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------
     * AJAX Handlers
     * ------------------------------------------------------------- */

    public function ajax_validate_api_key() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        if ( empty( $api_key ) ) {
            wp_send_json_error( 'Please enter an API key.' );
        }

        $api    = new BLTT_YouTube_API( $api_key );
        $result = $api->validate_key();

        if ( true === $result ) {
            wp_send_json_success( 'API key is valid.' );
        } else {
            wp_send_json_error( $result->get_error_message() );
        }
    }

    public function ajax_search_channels() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
        if ( empty( $query ) ) {
            wp_send_json_error( 'Enter a search term.' );
        }

        $settings = get_option( 'bltt_settings', array() );
        $api      = new BLTT_YouTube_API( $settings['api_key'] ?? '' );
        $channels = $api->search_channels( $query );

        if ( is_wp_error( $channels ) ) {
            wp_send_json_error( $channels->get_error_message() );
        }

        wp_send_json_success( $channels );
    }

    public function ajax_get_playlists() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $channel_id = sanitize_text_field( wp_unslash( $_POST['channel_id'] ?? '' ) );
        if ( empty( $channel_id ) ) {
            wp_send_json_error( 'No channel selected.' );
        }

        $settings  = get_option( 'bltt_settings', array() );
        $api       = new BLTT_YouTube_API( $settings['api_key'] ?? '' );
        $playlists = $api->get_channel_playlists( $channel_id );

        if ( is_wp_error( $playlists ) ) {
            wp_send_json_error( $playlists->get_error_message() );
        }

        wp_send_json_success( $playlists );
    }

    public function ajax_get_post_type_fields() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? 'post' ) );

        $fields = $this->discover_custom_fields( $post_type );

        wp_send_json_success( $fields );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

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

        $old_settings = get_option( 'bltt_settings', array() );

        $settings = array(
            'api_key'            => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
            'playlist_id'        => sanitize_text_field( wp_unslash( $_POST['playlist_id'] ?? '' ) ),
            'post_type'          => sanitize_key( wp_unslash( $_POST['post_type'] ?? 'post' ) ),
            'field_mapping'      => $field_mapping,
            'sync_cadence'       => sanitize_key( wp_unslash( $_POST['sync_cadence'] ?? 'daily' ) ),
            'sync_hour'          => min( 23, absint( wp_unslash( $_POST['sync_hour'] ?? 3 ) ) ),
            'sync_minute'        => in_array( absint( wp_unslash( $_POST['sync_minute'] ?? 0 ) ), array( 0, 15, 30, 45 ), true )
                                        ? absint( wp_unslash( $_POST['sync_minute'] ) )
                                        : 0,
            'sync_weekday'       => min( 6, absint( wp_unslash( $_POST['sync_weekday'] ?? 1 ) ) ),
            'description_target' => sanitize_key( wp_unslash( $_POST['description_target'] ?? 'post_content' ) ),
            'transcript_target'  => sanitize_key( wp_unslash( $_POST['transcript_target'] ?? '' ) ),
            'set_thumbnail'      => ! empty( $_POST['set_thumbnail'] ),
            'assign_keywords'    => ! empty( $_POST['assign_keywords'] ),
        );

        update_option( 'bltt_settings', $settings );

        $schedule_changed = $settings['sync_cadence'] !== ( $old_settings['sync_cadence'] ?? '' )
            || $settings['sync_hour']    !== ( $old_settings['sync_hour']    ?? '' )
            || $settings['sync_minute']  !== ( $old_settings['sync_minute']  ?? '' )
            || $settings['sync_weekday'] !== ( $old_settings['sync_weekday'] ?? '' );

        if ( $schedule_changed ) {
            BLTT_Cron::unschedule_sync();
            if ( 'disabled' !== $settings['sync_cadence'] ) {
                BLTT_Cron::schedule_sync();
            }
        }

        wp_send_json_success( 'Settings saved.' );
    }

    public function ajax_preview_playlist() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $engine = new BLTT_Sync_Engine();
        $result = $engine->preview_playlist();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_import_selected() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $video_ids = isset( $_POST['video_ids'] )
            ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['video_ids'] ) )
            : array();

        if ( empty( $video_ids ) ) {
            wp_send_json_error( 'No videos selected.' );
        }

        $engine = new BLTT_Sync_Engine();
        $result = $engine->import_selected( $video_ids );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_sync_updates() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $engine = new BLTT_Sync_Engine();
        $result = $engine->sync_updates( 'manual' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public function ajax_sync_status() {
        check_ajax_referer( 'bltt_nonce', 'nonce' );

        $status = get_transient( 'bltt_sync_progress' );
        wp_send_json_success( $status ? $status : array( 'status' => 'idle' ) );
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function discover_custom_fields( $post_type ) {
        global $wpdb;

        // Native WordPress post fields always appear at the top of the list.
        $fields = array(
            'post_title'      => __( 'Post Title (post_title)', 'blt-tube' ),
            'post_excerpt'    => __( 'Post Excerpt (post_excerpt)', 'blt-tube' ),
            '_featured_image' => __( 'Featured Image — download & set thumbnail (_featured_image)', 'blt-tube' ),
        );

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

        $registered = get_registered_meta_keys( 'post', $post_type );
        foreach ( $registered as $key => $args ) {
            if ( ! isset( $fields[ $key ] ) ) {
                $fields[ $key ] = $key;
            }
        }

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
