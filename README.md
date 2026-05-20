# BLT Tube

A WordPress plugin that imports videos from a YouTube playlist into any Custom Post Type — with full field mapping, thumbnail import, transcript capture, keyword tagging, scheduled sync, and a shortcode for embedding playlists anywhere on your site.

## Features

- **YouTube API Integration** — Connect with a YouTube Data API v3 key, search for channels, and browse their playlists.
- **Playlist Embed Shortcode** — Drop `[blt_tube_playlist]` into any post, page, widget, or block to embed a playlist player. No API key required for embedding.
- **Custom Post Type Support** — Select any registered post type (built-in or custom) as the import target.
- **Field Mapping** — Map any YouTube video data (URL, title, description, duration, view count, etc.) to WordPress custom fields. Supports ACF and all registered meta keys.
- **Featured Image** — Downloads the highest-resolution YouTube thumbnail and sets it as the post's featured image.
- **Description as Post Content** — The YouTube video description is imported as post content (or mapped to a custom field).
- **Transcript Import** — Fetches auto-generated or manual captions and maps them to a custom field or appends them to the post body.
- **Keyword Tagging** — YouTube video tags are assigned as WordPress post tags (or the first non-hierarchical taxonomy on the CPT).
- **Manual Sync** — One-click "Sync All Videos Now" button to import all existing playlist videos, with a live progress bar.
- **Scheduled Sync** — Configurable cadence (every 5 min, 15 min, hourly, twice daily, daily, weekly) to automatically check for and import new videos.
- **Duplicate Detection** — Videos already imported are skipped based on their YouTube video ID stored in post meta.
- **Sync Log** — View a history of all sync operations with counts of found, imported, skipped, and errored videos.

## Requirements

- WordPress 6.5+ (tested through WordPress 7.0)
- PHP 7.4+
- A YouTube Data API v3 key — **only required for importing** ([create one here](https://console.cloud.google.com/apis/credentials)). Embedding via the shortcode works without one.

## Do I need a YouTube API key?

| What you want to do                                                      | API key required? |
|--------------------------------------------------------------------------|:-----------------:|
| Embed a YouTube playlist on a page via `[blt_tube_playlist]`             | **No**            |
| Search for channels in the admin UI                                       | Yes               |
| Browse a channel's playlists in the admin UI                              | Yes               |
| Import videos as WordPress posts (titles, descriptions, thumbnails, tags) | Yes               |
| Pull captions/transcripts                                                 | No (uses the public timedtext endpoint) |

The shortcode embed uses YouTube's public IFrame Player (`youtube.com/embed/videoseries?list=...`), which is the same mechanism YouTube provides on every "Share → Embed" dialog. It's free, public, and does not count against any API quota.

You only need a YouTube Data API v3 key if you want BLT Tube to **pull playlist metadata into your WordPress database** (creating posts, importing titles, descriptions, etc.). The free Google Cloud tier (10,000 units/day) is more than enough for typical sync workloads.

## Installation

1. Download or clone this repository into `wp-content/plugins/blt-tube/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Navigate to **BLT Tube** in the admin sidebar.

## Setup (Importing Videos)

1. **Enter your API Key** — Paste your YouTube Data API v3 key and click **Validate Key**.
2. **Select a Playlist** — Search for a channel and pick a playlist, or paste a playlist ID directly.
3. **Choose a Post Type** — Select the target Custom Post Type from the dropdown, then click **Load Custom Fields** to discover available meta keys.
4. **Configure Content Options**:
   - Choose where the video description goes (post content or custom field).
   - Choose where the transcript goes (post content, custom field, or skip).
   - Toggle featured image import and keyword tagging.
5. **Map Fields** — Add rows to map YouTube data fields to WordPress custom fields.
6. **Set Sync Cadence** — Choose how often the plugin checks for new videos.
7. **Save Settings** and click **Sync All Videos Now** to import existing videos.

## Embedding Playlists with the Shortcode

The fastest way to display a playlist anywhere on your site — no import required.

### Basic usage

Uses the playlist ID configured in BLT Tube settings:

```
[blt_tube_playlist]
```

### Specify a playlist by ID

```
[blt_tube_playlist id="PLxxxxxxxxxxxxxxxx"]
```

### All supported attributes

| Attribute    | Default          | Description |
|--------------|------------------|-------------|
| `id`         | *(saved playlist)* | YouTube playlist ID (begins with `PL`, `UU`, `LL`, `FL`, `RD`, or `OL`). |
| `width`      | `560`            | Player width in pixels (or a CSS unit like `100%`). |
| `height`     | `315`            | Player height in pixels (ignored when responsive is on). |
| `responsive` | `yes`            | When `yes`, scales to container width with a 16:9 aspect ratio. |
| `autoplay`   | `no`             | Auto-play on page load. Most browsers require `mute="yes"` as well. |
| `loop`       | `no`             | Loop the playlist. |
| `mute`       | `no`             | Start muted. |
| `controls`   | `yes`            | Show player controls. |
| `privacy`    | `yes`            | Use `youtube-nocookie.com` for privacy-enhanced mode. |
| `start`      | *(none)*         | Start at the Nth video (1-based index). |

### Full example

```
[blt_tube_playlist id="PLxxxx" responsive="yes" autoplay="no" privacy="yes"]
```

A quick reference is also available in the WP admin under **BLT Tube → Shortcode Help**.

## Available YouTube Fields for Mapping

| Field | Description |
|-------|-------------|
| `video_url` | YouTube watch URL (e.g., `https://www.youtube.com/watch?v=...`) |
| `embed_url` | Embeddable URL (e.g., `https://www.youtube.com/embed/...`) |
| `title` | Video title |
| `description` | Full video description |
| `published_at` | Original publish date |
| `channel_title` | Channel name |
| `thumbnail_url` | URL of the highest-resolution thumbnail |
| `duration` | ISO 8601 duration string |
| `view_count` | Number of views |
| `like_count` | Number of likes |
| `comment_count` | Number of comments |
| `video_id` | YouTube video ID |
| `transcript` | Full transcript text |

## File Structure

```
blt-tube/
├── blt-tube.php                       # Main plugin entry point
├── includes/
│   ├── class-bltt-youtube-api.php     # YouTube Data API v3 wrapper
│   ├── class-bltt-admin.php           # Admin pages, settings, AJAX handlers
│   ├── class-bltt-sync-engine.php     # Video import / sync logic
│   ├── class-bltt-cron.php            # WP-Cron scheduling
│   └── class-bltt-shortcode.php       # [blt_tube_playlist] shortcode
├── assets/
│   ├── css/
│   │   └── admin.css                  # Admin page styles
│   └── js/
│       └── admin.js                   # Admin page interactivity
└── README.md
```

## Upgrading from ZymTube

BLT Tube is the rebranded successor to ZymTube. On first load it automatically migrates the legacy `ztube_settings` and `ztube_sync_log` options into their `bltt_*` equivalents. Previously imported videos remain deduplicated (the old `_ztube_video_id` post-meta key is still honoured alongside the new `_bltt_video_id`).

## License

GPL-2.0-or-later
