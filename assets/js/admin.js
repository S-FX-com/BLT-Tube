/**
 * BLT Tube — Admin JavaScript
 */
(function ($) {
    'use strict';

    var $notices = $('#bltt-notices');

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
            $btn.append('<span class="bltt-spinner"></span>');
        } else {
            $btn.prop('disabled', false);
            $btn.find('.bltt-spinner').remove();
        }
    }

    /* ---------------------------------------------------------------
     * 1. Validate API Key
     * ------------------------------------------------------------- */

    $('#bltt-validate-key').on('click', function () {
        var $btn = $(this);
        var apiKey = $('#bltt_api_key').val().trim();
        var $status = $('#bltt-key-status');

        if (!apiKey) {
            $status.text('Please enter an API key.').removeClass('valid').addClass('invalid');
            return;
        }

        setLoading($btn, true);
        $status.text('Checking...').removeClass('valid invalid');

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_validate_api_key',
            nonce: blttAdmin.nonce,
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

    $('#bltt-search-channels').on('click', function () {
        var $btn = $(this);
        var query = $('#bltt_channel_search').val().trim();
        var $results = $('#bltt-channel-results');

        if (!query) return;

        setLoading($btn, true);
        $results.html('<em>Searching...</em>');

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_search_channels',
            nonce: blttAdmin.nonce,
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
                    '<div class="bltt-channel-item" data-channel-id="' + ch.id + '">' +
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
    $(document).on('click', '.bltt-channel-item', function () {
        var channelId = $(this).data('channel-id');
        var $row = $('#bltt-playlists-row');
        var $select = $('#bltt-playlists-select');

        $('.bltt-channel-item').css('border-color', '#ddd').css('background', '');
        $(this).css('border-color', '#2271b1').css('background', '#f0f6fc');

        $select.html('<option value="">Loading playlists...</option>');
        $row.show();

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_get_playlists',
            nonce: blttAdmin.nonce,
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
    $(document).on('change', '#bltt-playlists-select', function () {
        var val = $(this).val();
        if (val) {
            $('#bltt_playlist_id').val(val);
        }
    });

    /* ---------------------------------------------------------------
     * 3. Load Custom Fields for Post Type
     * ------------------------------------------------------------- */

    $('#bltt-load-fields').on('click', function () {
        var $btn = $(this);
        var postType = $('#bltt_post_type').val();

        setLoading($btn, true);

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_get_post_type_fields',
            nonce: blttAdmin.nonce,
            post_type: postType
        }, function (res) {
            setLoading($btn, false);
            if (!res.success) {
                showNotice(res.data || 'Could not load fields.', 'error');
                return;
            }

            var fields = res.data;
            var fieldKeys = Object.keys(fields);

            $('.bltt-wp-field-select').each(function () {
                var $sel = $(this);
                var currentVal = $sel.siblings('.bltt-wp-field-input').val();

                $sel.empty().append('<option value="">— Choose detected field —</option>');
                $.each(fields, function (key, label) {
                    var selected = (key === currentVal) ? ' selected' : '';
                    $sel.append('<option value="' + key + '"' + selected + '>' + label + '</option>');
                });

                if (fieldKeys.length) {
                    $sel.show();
                }
            });

            window._blttDetectedFields = fields;

            showNotice('Loaded ' + fieldKeys.length + ' custom field(s) for "' + postType + '".');
        }).fail(function () {
            setLoading($btn, false);
            showNotice('Failed to load fields.', 'error');
        });
    });

    $(document).on('change', '.bltt-wp-field-select', function () {
        var val = $(this).val();
        if (val) {
            $(this).siblings('.bltt-wp-field-input').val(val);
        }
    });

    /* ---------------------------------------------------------------
     * 4. Field Mapping Rows
     * ------------------------------------------------------------- */

    $('#bltt-add-mapping').on('click', function () {
        var template = $('#tmpl-bltt-mapping-row').html();
        var $row = $(template);

        if (window._blttDetectedFields && Object.keys(window._blttDetectedFields).length) {
            var $sel = $row.find('.bltt-wp-field-select');
            $sel.empty().append('<option value="">— Choose detected field —</option>');
            $.each(window._blttDetectedFields, function (key, label) {
                $sel.append('<option value="' + key + '">' + label + '</option>');
            });
            $sel.show();
        }

        $('#bltt-mapping-rows').append($row);
    });

    $(document).on('click', '.bltt-remove-row', function () {
        $(this).closest('tr').remove();
    });

    /* ---------------------------------------------------------------
     * 5. Sync Time Visibility
     * ------------------------------------------------------------- */

    function updateSyncTimeVisibility() {
        var cadence   = $('#bltt_sync_cadence').val();
        var timeCads  = ['hourly', 'twicedaily', 'daily', 'weekly'];
        var showRow   = timeCads.indexOf(cadence) >= 0;

        $('#bltt-sync-time-row').toggle(showRow);
        if (!showRow) return;

        var isWeekly = (cadence === 'weekly');
        var isHourly = (cadence === 'hourly');

        $('#bltt-sync-on-prefix').toggle(isWeekly);
        $('#bltt_sync_weekday').toggle(isWeekly);
        $('#bltt_sync_hour').toggle(!isHourly);
        $('#bltt-sync-colon').toggle(!isHourly);

        if (isHourly) {
            $('#bltt-sync-at-word').text('At minute :');
        } else if (isWeekly) {
            $('#bltt-sync-at-word').text(' at ');
        } else {
            $('#bltt-sync-at-word').text('At ');
        }

        var descs = {
            hourly:    'The sync will run at this minute past every hour.',
            twicedaily:'The sync will run at this time, then again 12 hours later.',
            daily:     'The time of day the sync should run.',
            weekly:    'The day and time the sync should run.'
        };
        $('#bltt-sync-time-desc').text(descs[cadence] || '');
    }

    $('#bltt_sync_cadence').on('change', updateSyncTimeVisibility);
    updateSyncTimeVisibility();

    /* ---------------------------------------------------------------
     * 6. Save Settings
     * ------------------------------------------------------------- */

    $('#bltt-settings-form').on('submit', function (e) {
        e.preventDefault();

        var $btn = $('#bltt-save-settings');
        setLoading($btn, true);

        var ytFields = [];
        var wpFields = [];
        $('.bltt-mapping-row').each(function () {
            ytFields.push($(this).find('.bltt-yt-field-select').val());
            wpFields.push($(this).find('.bltt-wp-field-input').val());
        });

        var data = {
            action: 'bltt_save_settings',
            nonce: blttAdmin.nonce,
            api_key: $('#bltt_api_key').val(),
            playlist_id: $('#bltt_playlist_id').val(),
            post_type: $('#bltt_post_type').val(),
            sync_cadence: $('#bltt_sync_cadence').val(),
            sync_hour: $('#bltt_sync_hour').val(),
            sync_minute: $('#bltt_sync_minute').val(),
            sync_weekday: $('#bltt_sync_weekday').val(),
            description_target: $('#bltt_description_target').val(),
            transcript_target: $('#bltt_transcript_target').val(),
            set_thumbnail: $('#bltt_set_thumbnail').is(':checked') ? 1 : 0,
            assign_keywords: $('#bltt_assign_keywords').is(':checked') ? 1 : 0,
            'yt_fields[]': ytFields,
            'wp_fields[]': wpFields
        };

        $.post(blttAdmin.ajax_url, data, function (res) {
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
     * 7. Manual Sync
     * ------------------------------------------------------------- */

    $('#bltt-manual-sync').on('click', function () {
        var $btn = $(this);
        var $progress = $('#bltt-sync-progress');
        var $bar = $('#bltt-progress-inner');
        var $text = $('#bltt-sync-status-text');

        if (!confirm('This will import all videos from the configured playlist. Continue?')) {
            return;
        }

        setLoading($btn, true);
        $progress.show();
        $bar.css('width', '0%');
        $text.text('Starting sync...');

        var pollInterval = setInterval(function () {
            $.post(blttAdmin.ajax_url, {
                action: 'bltt_sync_status',
                nonce: blttAdmin.nonce
            }, function (res) {
                if (res.success && res.data && res.data.status === 'running') {
                    $bar.css('width', res.data.percent + '%');
                    $text.text(res.data.message);
                }
            });
        }, 2000);

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_manual_sync',
            nonce: blttAdmin.nonce
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
