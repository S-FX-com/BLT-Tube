# BLT Tube

A WordPress plugin that imports videos from a YouTube playlist into any Custom Post Type — with full field mapping, thumbnail import, transcript capture, keyword tagging, scheduled sync, and shortcodes for embedding a playlist player or a video gallery anywhere on your site.

## Features

- **YouTube API Integration** — Connect with a YouTube Data API v3 key, search for channels, and browse their playlists.
- **Playlist Embed Shortcode** — Drop `[blt_tube_playlist]` into any post, page, widget, or block to embed a playlist player. No API key required for embedding.
- **Video Gallery Shortcode** — `[blt_tube_gallery]` shows a playlist (or a whole channel) as thumbnails and titles in a grid, featured, carousel, or list layout. Nothing from YouTube loads until a visitor clicks a video.
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
| Show a video gallery via `[blt_tube_gallery]`                            | **Recommended** — without one it shows only the latest 15 videos and no channel avatar |
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

## Video Gallery Shortcode

`[blt_tube_gallery]` renders a playlist as a visual gallery — thumbnails and titles — instead of a single player. Videos are read live from YouTube and cached; no posts are created, so it works independently of the import features.

```
[blt_tube_gallery id="PLxxxxxxxxxxxxxxxx"]
```

With no `id`, it uses the playlist saved in BLT Tube settings.

### Layouts

**Grid** (default) — responsive columns.

```
[blt_tube_gallery id="PLxxxxxxxxxxxxxxxx" columns="3" columns_tablet="2" columns_mobile="1" max="12"]
```

**Featured** — a large player on top showing the first video, with the rest as thumbnails underneath (a grid, or a horizontal strip with `thumbs="strip"`). Clicking a thumbnail plays it in the main player.

```
[blt_tube_gallery layout="featured" thumbs="strip" show_description="yes"]
```

**Carousel** — a single horizontal row with previous/next buttons. Swipes on touch screens; arrow keys move between videos.

```
[blt_tube_gallery layout="carousel" columns="4" play="lightbox"]
```

**List** — thumbnail on the left; title, date and an optional description excerpt on the right.

```
[blt_tube_gallery layout="list" show_description="yes" load_more="yes" per_page="5"]
```

**A whole channel** — pass the channel's uploads playlist, which is its channel ID with the leading `UC` replaced by `UU`:

```
[blt_tube_gallery id="UUxxxxxxxxxxxxxxxxxxxxxx" show_header="yes" max="24" load_more="yes"]
```

### Attributes

| Attribute          | Default            | Description |
|--------------------|--------------------|-------------|
| `id`               | *(saved playlist)* | YouTube playlist ID. `UU…` uploads playlists show a whole channel. |
| `layout`           | `grid`             | `grid`, `featured`, `carousel`, or `list`. |
| `play`             | `inline`           | `inline` replaces the thumbnail with the player; `lightbox` opens it in a modal; `youtube` opens YouTube in a new tab. The featured layout always plays in its main player. |
| `columns`          | `3`                | Videos per row (1–6) when the gallery is wider than 840px. Featured layout default: `4`. |
| `columns_tablet`   | `2`                | Videos per row when the gallery is 481–840px wide. Featured layout default: `3`. |
| `columns_mobile`   | `1`                | Videos per row when the gallery is 480px wide or less. Featured layout default: `2`. |
| `max`              | `12`               | How many videos to show. Up to 50 (filter: `bltt_gallery_max_limit`); up to 15 without an API key. |
| `order`            | `playlist`         | `playlist` keeps the playlist's own order; `newest` sorts by publish date, newest first. |
| `show_header`      | `no`               | Show the channel avatar and name, linked to the channel. |
| `show_title`       | `yes`              | Show each video's title. |
| `show_date`        | `yes`              | Show each video's publish date. |
| `show_description` | `no`               | Show a short description excerpt (list layout and the featured player). |
| `load_more`        | `no`               | Show `per_page` videos first and reveal the rest with a "Load more" button. Works from the videos already in the page — no extra requests. Grid, list and featured grid. |
| `per_page`         | `6`                | Videos per batch when `load_more` is on. |
| `thumbs`           | `grid`             | Featured layout only: `grid` or `strip`. |
| `privacy`          | `yes`              | Play videos from `youtube-nocookie.com` (privacy-enhanced mode). |

The column breakpoints follow the **gallery's own width**, not the screen's, so the same shortcode works in a full-width section and in a narrow sidebar. The list layout ignores the `columns` attributes.

### Where the videos come from

- **With an API key** (BLT Tube settings): the YouTube Data API. About 2 quota units per refresh for a typical gallery.
- **Without an API key**: the playlist's public RSS feed. It only lists the **latest 15 videos** and has no channel avatar. Administrators see a note about this on the page; visitors do not.

Results are cached for **one hour** per playlist, `max` and `order`, so page views never call YouTube directly. Change the lifetime with the `bltt_gallery_cache_ttl` filter:

```php
add_filter( 'bltt_gallery_cache_ttl', function ( $seconds, $playlist_id ) {
    return 15 * MINUTE_IN_SECONDS;
}, 10, 2 );
```

To refresh immediately, use **BLT Tube → Clear gallery cache**. Saving a different API key clears it automatically.

If YouTube cannot be reached when the cache expires, the gallery keeps showing the previous videos and tries again a few minutes later. If there is nothing cached, visitors see nothing and administrators see the error.

### Performance and privacy

Only thumbnails are rendered on page load — no YouTube iframe or script — and the player is created when a visitor clicks. Thumbnails are lazy-loaded from YouTube's image host (`i.ytimg.com`). The stylesheet and script (vanilla JS, no jQuery) load only on pages that contain the shortcode.

### Styling

All styles are scoped under `.bltt-gallery`; text colour and fonts are inherited from your theme. Set these CSS custom properties on `.bltt-gallery`, a wrapper, or `:root`:

| Property                         | Default   | Controls |
|----------------------------------|-----------|----------|
| `--bltt-gallery-gap`             | `20px`    | Space between videos |
| `--bltt-gallery-radius`          | `10px`    | Corner radius of thumbnails and buttons |
| `--bltt-gallery-accent`          | `#cc181e` | Play button hover, focus ring, "Load more" button |
| `--bltt-gallery-accent-contrast` | `#fff`    | Text and icon colour on the accent |

```css
.bltt-gallery {
    --bltt-gallery-accent: #0a66c2;
    --bltt-gallery-radius: 4px;
}
```

A quick reference for both shortcodes is also available in the WP admin under **BLT Tube → Shortcode Help**.

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
│   ├── class-bltt-shortcode.php       # [blt_tube_playlist] shortcode
│   └── class-bltt-gallery.php         # [blt_tube_gallery] shortcode
├── assets/
│   ├── css/
│   │   ├── admin.css                  # Admin page styles
│   │   └── gallery.css                # [blt_tube_gallery] front-end styles
│   └── js/
│       ├── admin.js                   # Admin page interactivity
│       └── gallery.js                 # [blt_tube_gallery] front-end behaviour
└── README.md
```

## Upgrading from ZymTube

BLT Tube is the rebranded successor to ZymTube. On first load it automatically migrates the legacy `ztube_settings` and `ztube_sync_log` options into their `bltt_*` equivalents. Previously imported videos remain deduplicated (the old `_ztube_video_id` post-meta key is still honoured alongside the new `_bltt_video_id`).

## License

GPL-2.0-or-later
