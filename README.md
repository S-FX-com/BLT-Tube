# YouTube Playlist to WordPress

A WordPress plugin that imports videos from a YouTube playlist into any Custom Post Type, with full field mapping, thumbnail import, transcript capture, keyword tagging, and scheduled sync.

## Features

- **YouTube API Integration** — Connect with a YouTube Data API v3 key, search for channels, and browse their playlists.
- **Custom Post Type Support** — Select any registered post type (built-in or custom) as the import target.
- **Field Mapping** — Map any YouTube video data (URL, title, description, duration, view count, etc.) to WordPress custom fields. Supports ACF and all registered meta keys.
- **Video Playback URL** — Stores the direct YouTube watch URL and embed URL as mapped custom fields.
- **Featured Image** — Downloads the highest-resolution YouTube thumbnail and sets it as the post's featured image.
- **Description as Post Content** — The YouTube video description is imported as post content (or mapped to a custom field).
- **Transcript Import** — Fetches auto-generated or manual captions and maps them to a custom field or appends them to the post body.
- **Keyword Tagging** — YouTube video tags are assigned as WordPress post tags (or the first non-hierarchical taxonomy on the CPT).
- **Manual Sync** — One-click "Sync All Videos Now" button to import all existing playlist videos, with a live progress bar.
- **Scheduled Sync** — Configurable cadence (every 5 min, 15 min, hourly, twice daily, daily, weekly) to automatically check for and import new videos.
- **Duplicate Detection** — Videos already imported are skipped based on their YouTube video ID stored in post meta.
- **Sync Log** — View a history of all sync operations with counts of found, imported, skipped, and errored videos.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- A YouTube Data API v3 key ([create one here](https://console.cloud.google.com/apis/credentials))

## Installation

1. Download or clone this repository into `wp-content/plugins/youtube-to-wp/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Navigate to **YouTube to WP** in the admin sidebar.

## Setup

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
youtube-to-wp/
├── youtube-to-wp.php              # Main plugin entry point
├── includes/
│   ├── class-ytwp-youtube-api.php # YouTube Data API v3 wrapper
│   ├── class-ytwp-admin.php       # Admin pages, settings, AJAX handlers
│   ├── class-ytwp-sync-engine.php # Video import / sync logic
│   └── class-ytwp-cron.php        # WP-Cron scheduling
├── assets/
│   ├── css/
│   │   └── admin.css              # Admin page styles
│   └── js/
│       └── admin.js               # Admin page interactivity
└── README.md
```

## License

GPL-2.0-or-later
