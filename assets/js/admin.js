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
        $notices[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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

    // Parse ISO 8601 duration (PT4M13S) → "4:13"
    function parseDuration(iso) {
        if (!iso) return '';
        var m = iso.match(/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/);
        if (!m) return iso;
        var h = parseInt(m[1] || 0),
            min = parseInt(m[2] || 0),
            s   = parseInt(m[3] || 0);
        var parts = [];
        if (h) { parts.push(h); }
        parts.push(h ? String(min).padStart(2, '0') : min);
        parts.push(String(s).padStart(2, '0'));
        return parts.join(':');
    }

    function startProgressPolling() {
        return setInterval(function () {
            $.post(blttAdmin.ajax_url, {
                action: 'bltt_sync_status',
                nonce:  blttAdmin.nonce
            }, function (res) {
                if (res.success && res.data && res.data.status === 'running') {
                    $('#bltt-progress-inner').css('width', res.data.percent + '%');
                    $('#bltt-sync-status-text').text(res.data.message);
                }
            });
        }, 2000);
    }

    /* ---------------------------------------------------------------
     * 1. Validate API Key
     * ------------------------------------------------------------- */

    $('#bltt-validate-key').on('click', function () {
        var $btn    = $(this);
        var apiKey  = $('#bltt_api_key').val().trim();
        var $status = $('#bltt-key-status');

        if (!apiKey) {
            $status.text('Please enter an API key.').removeClass('valid').addClass('invalid');
            return;
        }

        setLoading($btn, true);
        $status.text('Checking…').removeClass('valid invalid');

        $.post(blttAdmin.ajax_url, {
            action:  'bltt_validate_api_key',
            nonce:   blttAdmin.nonce,
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
        var $btn     = $(this);
        var query    = $('#bltt_channel_search').val().trim();
        var $results = $('#bltt-channel-results');

        if (!query) return;

        setLoading($btn, true);
        $results.html('<em>Searching…</em>');

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_search_channels',
            nonce:  blttAdmin.nonce,
            query:  query
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

    $(document).on('click', '.bltt-channel-item', function () {
        var channelId = $(this).data('channel-id');
        var $row      = $('#bltt-playlists-row');
        var $select   = $('#bltt-playlists-select');

        $('.bltt-channel-item').css('border-color', '#ddd').css('background', '');
        $(this).css('border-color', '#2271b1').css('background', '#f0f6fc');

        $select.html('<option value="">Loading playlists…</option>');
        $row.show();

        $.post(blttAdmin.ajax_url, {
            action:     'bltt_get_playlists',
            nonce:      blttAdmin.nonce,
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
        var $btn      = $(this);
        var postType  = $('#bltt_post_type').val();

        setLoading($btn, true);

        $.post(blttAdmin.ajax_url, {
            action:    'bltt_get_post_type_fields',
            nonce:     blttAdmin.nonce,
            post_type: postType
        }, function (res) {
            setLoading($btn, false);
            if (!res.success) {
                showNotice(res.data || 'Could not load fields.', 'error');
                return;
            }

            var fields    = res.data;
            var fieldKeys = Object.keys(fields);

            $('.bltt-wp-field-select').each(function () {
                var $sel      = $(this);
                var currentVal = $sel.siblings('.bltt-wp-field-input').val();

                $sel.empty().append('<option value="">— Choose detected field —</option>');
                $.each(fields, function (key, label) {
                    var sel = (key === currentVal) ? ' selected' : '';
                    $sel.append('<option value="' + key + '"' + sel + '>' + label + '</option>');
                });

                if (fieldKeys.length) { $sel.show(); }
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
        var $row     = $(template);

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
     * 5. Save Settings
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
            action:             'bltt_save_settings',
            nonce:              blttAdmin.nonce,
            api_key:            $('#bltt_api_key').val(),
            playlist_id:        $('#bltt_playlist_id').val(),
            post_type:          $('#bltt_post_type').val(),
            sync_cadence:       $('#bltt_sync_cadence').val(),
            description_target: $('#bltt_description_target').val(),
            transcript_target:  $('#bltt_transcript_target').val(),
            set_thumbnail:      $('#bltt_set_thumbnail').is(':checked') ? 1 : 0,
            assign_keywords:    $('#bltt_assign_keywords').is(':checked') ? 1 : 0,
            'yt_fields[]':      ytFields,
            'wp_fields[]':      wpFields
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
     * 6. Load Playlist Preview
     * ------------------------------------------------------------- */

    function renderPreviewTable(data) {
        var $wrap    = $('#bltt-preview-wrap');
        var $rows    = $('#bltt-preview-rows');
        var $summary = $('#bltt-preview-summary');

        $rows.empty();

        $summary.html(
            'Found <strong>' + data.total + '</strong> video(s) — ' +
            '<strong>' + data.unsynced + '</strong> not yet imported.'
        );

        if (!data.videos.length) {
            $wrap.show();
            return;
        }

        $.each(data.videos, function (i, v) {
            var thumb = v.thumbnail
                ? '<img src="' + v.thumbnail + '" style="width:70px;height:auto;border-radius:3px;" loading="lazy">'
                : '';
            var status = v.synced
                ? '<span style="color:#2e7d32;font-weight:600;">&#10003; Imported</span>'
                : '<span style="color:#888;">Not imported</span>';
            var cb = !v.synced
                ? '<input type="checkbox" class="bltt-video-checkbox" value="' + v.video_id + '" checked>'
                : '';
            var published = v.published_at ? v.published_at.substring(0, 10) : '';
            var title     = $('<span>').text(v.title).html();

            $rows.append(
                '<tr>' +
                '<td style="text-align:center;">' + cb + '</td>' +
                '<td>' + thumb + '</td>' +
                '<td><strong>' + title + '</strong></td>' +
                '<td>' + parseDuration(v.duration) + '</td>' +
                '<td>' + published + '</td>' +
                '<td>' + status + '</td>' +
                '</tr>'
            );
        });

        syncImportButton();
        $wrap.show();
    }

    function syncImportButton() {
        var count   = $('.bltt-video-checkbox:checked').length;
        var $btn    = $('#bltt-import-selected');
        var $selAll = $('#bltt-select-all');
        var total   = $('.bltt-video-checkbox').length;

        $btn.prop('disabled', count === 0)
            .text(count > 0 ? 'Import Selected (' + count + ')' : 'Import Selected');

        if (total > 0) {
            $selAll.prop('indeterminate', count > 0 && count < total)
                   .prop('checked', count === total);
        }
    }

    $('#bltt-load-playlist').on('click', function () {
        var $btn = $(this);
        $('#bltt-preview-wrap').hide();
        $('#bltt-preview-rows').empty();

        setLoading($btn, true);

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_preview_playlist',
            nonce:  blttAdmin.nonce
        }, function (res) {
            setLoading($btn, false);
            if (!res.success) {
                showNotice(res.data || 'Failed to load playlist.', 'error');
                return;
            }
            renderPreviewTable(res.data);
        }).fail(function () {
            setLoading($btn, false);
            showNotice('Request failed.', 'error');
        });
    });

    $('#bltt-select-all').on('change', function () {
        $('.bltt-video-checkbox').prop('checked', $(this).is(':checked'));
        syncImportButton();
    });

    $(document).on('change', '.bltt-video-checkbox', syncImportButton);

    /* ---------------------------------------------------------------
     * 7. Import Selected Videos
     * ------------------------------------------------------------- */

    $('#bltt-import-selected').on('click', function () {
        var videoIds = [];
        $('.bltt-video-checkbox:checked').each(function () {
            videoIds.push($(this).val());
        });

        if (!videoIds.length) {
            showNotice('No videos selected.', 'error');
            return;
        }

        if (!confirm('Import ' + videoIds.length + ' video(s) as posts? This may take a while.')) {
            return;
        }

        var $btn      = $(this);
        var $progress = $('#bltt-sync-progress');
        var $bar      = $('#bltt-progress-inner');
        var $text     = $('#bltt-sync-status-text');

        setLoading($btn, true);
        $progress.show();
        $bar.css('width', '0%');
        $text.text('Starting import…');

        var poll = startProgressPolling();

        $.post(blttAdmin.ajax_url, {
            action:        'bltt_import_selected',
            nonce:         blttAdmin.nonce,
            'video_ids[]': videoIds
        }, function (res) {
            clearInterval(poll);
            setLoading($btn, false);
            $bar.css('width', '100%');

            if (res.success) {
                var d = res.data;
                $text.text('Import complete.');
                showNotice(
                    'Import complete — ' + d.imported + ' imported, ' +
                    d.skipped + ' skipped, ' + d.errors + ' errors.'
                );
                // Refresh the preview table to reflect updated sync status.
                $('#bltt-load-playlist').trigger('click');
            } else {
                $text.text('Import failed.');
                showNotice(res.data || 'Import failed.', 'error');
            }

            setTimeout(function () { $progress.fadeOut(); }, 5000);
        }).fail(function () {
            clearInterval(poll);
            setLoading($btn, false);
            $text.text('Import request failed.');
            showNotice('Import request failed.', 'error');
        });
    });

    /* ---------------------------------------------------------------
     * 8. Sync Existing Posts
     * ------------------------------------------------------------- */

    $('#bltt-sync-updates').on('click', function () {
        if (!confirm('Update all previously imported posts with the latest data from YouTube?')) {
            return;
        }

        var $btn      = $(this);
        var $progress = $('#bltt-sync-progress');
        var $bar      = $('#bltt-progress-inner');
        var $text     = $('#bltt-sync-status-text');

        setLoading($btn, true);
        $progress.show();
        $bar.css('width', '0%');
        $text.text('Starting sync…');

        var poll = startProgressPolling();

        $.post(blttAdmin.ajax_url, {
            action: 'bltt_sync_updates',
            nonce:  blttAdmin.nonce
        }, function (res) {
            clearInterval(poll);
            setLoading($btn, false);
            $bar.css('width', '100%');

            if (res.success) {
                var d = res.data;
                $text.text('Sync complete.');
                showNotice(
                    'Sync complete — ' + d.found + ' post(s) checked, ' +
                    d.updated + ' updated, ' + d.errors + ' error(s).' +
                    (d.message ? ' ' + d.message : '')
                );
            } else {
                $text.text('Sync failed.');
                showNotice(res.data || 'Sync failed.', 'error');
            }

            setTimeout(function () { $progress.fadeOut(); }, 5000);
        }).fail(function () {
            clearInterval(poll);
            setLoading($btn, false);
            $text.text('Sync request failed.');
            showNotice('Sync request failed.', 'error');
        });
    });

})(jQuery);
