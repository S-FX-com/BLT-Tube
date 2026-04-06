/**
 * ZymTube — Admin JavaScript
 */
(function ($) {
    'use strict';

    var $notices = $('#ztube-notices');

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    function showNotice(message, type) {
        type = type || 'success';
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        $notices.html(
            '<div class="notice ' + cls + ' is-dismissible"><p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>'
        );
        $notices.find('.notice-dismiss').on('click', function () {
            $(this).closest('.notice').fadeOut(200, function () { $(this).remove(); });
        });
    }

    function setLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true);
            $btn.data('original-text', $btn.text());
            $btn.append('<span class="ztube-spinner"></span>');
        } else {
            $btn.prop('disabled', false);
            $btn.find('.ztube-spinner').remove();
        }
    }

    /* ---------------------------------------------------------------
     * 1. Validate API Key
     * ------------------------------------------------------------- */

    $('#ztube-validate-key').on('click', function () {
        var $btn = $(this);
        var apiKey = $('#ztube_api_key').val().trim();
        var $status = $('#ztube-key-status');

        if (!apiKey) {
            $status.text('Please enter an API key.').removeClass('valid').addClass('invalid');
            return;
        }

        setLoading($btn, true);
        $status.text('Checking...').removeClass('valid invalid');

        $.post(ztubeAdmin.ajax_url, {
            action: 'ztube_validate_api_key',
            nonce: ztubeAdmin.nonce,
            api_key: apiKey
        }, function (res) {
            setLoading($btn, false);
            if (res.success) {
                $status.text('Valid').removeClass('invalid').addClass('valid');
            } else {
                $status.text(res.data || 'Invalid').removeClass('valid').addClass('invalid');
            }
        }).fail(function () {
            setLoading($btn, false);
            $status.text('Request failed.').removeClass('valid').addClass('invalid');
        });
    });

    /* ---------------------------------------------------------------
     * 2. Search Channels
     * ------------------------------------------------------------- */

    $('#ztube-search-channels').on('click', function () {
        var $btn = $(this);
        var query = $('#ztube_channel_search').val().trim();
        var $results = $('#ztube-channel-results');

        if (!query) return;

        setLoading($btn, true);
        $results.html('<em>Searching...</em>');

        $.post(ztubeAdmin.ajax_url, {
            action: 'ztube_search_channels',
            nonce: ztubeAdmin.nonce,
            query: query
        }, function (res) {
            setLoading($btn, false);
            $results.empty();

            if (!res.success) {
                $results.html('<em>' + (res.data || 'Error') + '</em>');
                return;
            }

            if (!res.data.length) {
                $results.html('<em>No channels found.</em>');
                return;
            }

            $.each(res.data, function (i, ch) {
                var $item = $(
                    '<div class="ztube-channel-item" data-channel-id="' + ch.id + '">' +
                    '<img src="' + ch.thumb + '" alt="">' +
                    '<span class="channel-title">' + ch.title + '</span>' +
                    '</div>'
                );
                $results.append($item);
            });
        }).fail(function () {
            setLoading($btn, false);
            $results.html('<em>Request failed.</em>');
        });
    });

    // Click a channel → load its playlists.
    $(document).on('click', '.ztube-channel-item', function () {
        var channelId = $(this).data('channel-id');
        var $row = $('#ztube-playlists-row');
        var $select = $('#ztube-playlists-select');

        $('.ztube-channel-item').css('border-color', '#ddd').css('background', '');
        $(this).css('border-color', '#2271b1').css('background', '#f0f6fc');

        $select.html('<option value="">Loading playlists...</option>');
        $row.show();

        $.post(ztubeAdmin.ajax_url, {
            action: 'ztube_get_playlists',
            nonce: ztubeAdmin.nonce,
            channel_id: channelId
        }, function (res) {
            $select.empty().append('<option value="">— Select a playlist —</option>');
            if (res.success && res.data.length) {
                $.each(res.data, function (i, pl) {
                    $select.append('<option value="' + pl.id + '">' + pl.title + '</option>');
                });
            } else {
                $select.append('<option value="">No playlists found</option>');
            }
        });
    });

    // When a playlist is selected, populate the playlist ID field.
    $(document).on('change', '#ztube-playlists-select', function () {
        var val = $(this).val();
        if (val) {
            $('#ztube_playlist_id').val(val);
        }
    });

    /* ---------------------------------------------------------------
     * 3. Load Custom Fields for Post Type
     * ------------------------------------------------------------- */

    $('#ztube-load-fields').on('click', function () {
        var $btn = $(this);
        var postType = $('#ztube_post_type').val();

        setLoading($btn, true);

        $.post(ztubeAdmin.ajax_url, {
            action: 'ztube_get_post_type_fields',
            nonce: ztubeAdmin.nonce,
            post_type: postType
        }, function (res) {
            setLoading($btn, false);
            if (!res.success) {
                showNotice(res.data || 'Could not load fields.', 'error');
                return;
            }

            var fields = res.data;
            var fieldKeys = Object.keys(fields);

            // Populate all existing WP field selects.
            $('.ztube-wp-field-select').each(function () {
                var $sel = $(this);
                var currentVal = $sel.siblings('.ztube-wp-field-input').val();

                $sel.empty().append('<option value="">— Choose detected field —</option>');
                $.each(fields, function (key, label) {
                    var selected = (key === currentVal) ? ' selected' : '';
                    $sel.append('<option value="' + key + '"' + selected + '>' + label + '</option>');
                });

                if (fieldKeys.length) {
                    $sel.show();
                }
            });

            // Store fields globally for new rows.
            window._ztubeDetectedFields = fields;

            showNotice('Loaded ' + fieldKeys.length + ' custom field(s) for "' + postType + '".');
        }).fail(function () {
            setLoading($btn, false);
            showNotice('Failed to load fields.', 'error');
        });
    });

    // When a detected-field select changes, update the text input.
    $(document).on('change', '.ztube-wp-field-select', function () {
        var val = $(this).val();
        if (val) {
            $(this).siblings('.ztube-wp-field-input').val(val);
        }
    });

    /* ---------------------------------------------------------------
     * 4. Field Mapping Rows
     * ------------------------------------------------------------- */

    $('#ztube-add-mapping').on('click', function () {
        var template = $('#tmpl-ztube-mapping-row').html();
        var $row = $(template);

        // If we have detected fields, populate the select.
        if (window._ztubeDetectedFields && Object.keys(window._ztubeDetectedFields).length) {
            var $sel = $row.find('.ztube-wp-field-select');
            $sel.empty().append('<option value="">— Choose detected field —</option>');
            $.each(window._ztubeDetectedFields, function (key, label) {
                $sel.append('<option value="' + key + '">' + label + '</option>');
            });
            $sel.show();
        }

        $('#ztube-mapping-rows').append($row);
    });

    $(document).on('click', '.ztube-remove-row', function () {
        $(this).closest('tr').remove();
    });

    /* ---------------------------------------------------------------
     * 5. Save Settings
     * ------------------------------------------------------------- */

    $('#ztube-settings-form').on('submit', function (e) {
        e.preventDefault();

        var $btn = $('#ztube-save-settings');
        setLoading($btn, true);

        // Gather mapping rows.
        var ytFields = [];
        var wpFields = [];
        $('.ztube-mapping-row').each(function () {
            ytFields.push($(this).find('.ztube-yt-field-select').val());
            wpFields.push($(this).find('.ztube-wp-field-input').val());
        });

        var data = {
            action: 'ztube_save_settings',
            nonce: ztubeAdmin.nonce,
            api_key: $('#ztube_api_key').val(),
            playlist_id: $('#ztube_playlist_id').val(),
            post_type: $('#ztube_post_type').val(),
            sync_cadence: $('#ztube_sync_cadence').val(),
            description_target: $('#ztube_description_target').val(),
            transcript_target: $('#ztube_transcript_target').val(),
            set_thumbnail: $('#ztube_set_thumbnail').is(':checked') ? 1 : 0,
            assign_keywords: $('#ztube_assign_keywords').is(':checked') ? 1 : 0,
            'yt_fields[]': ytFields,
            'wp_fields[]': wpFields
        };

        $.post(ztubeAdmin.ajax_url, data, function (res) {
            setLoading($btn, false);
            if (res.success) {
                showNotice('Settings saved successfully.');
            } else {
                showNotice(res.data || 'Failed to save settings.', 'error');
            }
        }).fail(function () {
            setLoading($btn, false);
            showNotice('Request failed.', 'error');
        });
    });

    /* ---------------------------------------------------------------
     * 6. Manual Sync
     * ------------------------------------------------------------- */

    $('#ztube-manual-sync').on('click', function () {
        var $btn = $(this);
        var $progress = $('#ztube-sync-progress');
        var $bar = $('#ztube-progress-inner');
        var $text = $('#ztube-sync-status-text');

        if (!confirm('This will import all videos from the configured playlist. Continue?')) {
            return;
        }

        setLoading($btn, true);
        $progress.show();
        $bar.css('width', '0%');
        $text.text('Starting sync...');

        // Start polling progress.
        var pollInterval = setInterval(function () {
            $.post(ztubeAdmin.ajax_url, {
                action: 'ztube_sync_status',
                nonce: ztubeAdmin.nonce
            }, function (res) {
                if (res.success && res.data && res.data.status === 'running') {
                    $bar.css('width', res.data.percent + '%');
                    $text.text(res.data.message);
                }
            });
        }, 2000);

        $.post(ztubeAdmin.ajax_url, {
            action: 'ztube_manual_sync',
            nonce: ztubeAdmin.nonce
        }, function (res) {
            clearInterval(pollInterval);
            setLoading($btn, false);
            $bar.css('width', '100%');

            if (res.success) {
                var d = res.data;
                $text.text('Sync complete.');
                showNotice(
                    'Sync complete: ' + d.found + ' found, ' +
                    d.imported + ' imported, ' + d.skipped + ' skipped, ' +
                    d.errors + ' errors.'
                );
            } else {
                $text.text('Sync failed.');
                showNotice(res.data || 'Sync failed.', 'error');
            }

            setTimeout(function () { $progress.fadeOut(); }, 5000);
        }).fail(function () {
            clearInterval(pollInterval);
            setLoading($btn, false);
            $text.text('Sync request failed.');
            showNotice('Sync request failed.', 'error');
        });
    });

})(jQuery);
