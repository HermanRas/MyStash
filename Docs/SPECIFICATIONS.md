# MyStash — Specifications

## 1. Tech Stack

- **Backend:** PHP
- **Runtime:** Docker container
- **Encryption:** 7zip (standard, well-known algorithm), password-protected archives
- **Video processing:** PHP-driven conversion to MP4 (H.265/MPEG-H HEVC), frame extraction for previews
- **Frontend:** HTML/CSS/JS, dark-theme UI (see §4)

### Third-party dependencies

| Dependency | Purpose | Notes |
| --- | --- | --- |
| `p7zip` (7-Zip CLI, `7z`) | Create/extract AES-256 encrypted `.7z` archives for the datastore | Used purely for encryption, not compression — archives are created with compression level `-mx=0` (store only). Video/preview files are already compressed (MP4/H.265); recompressing them would waste CPU and slow down decrypt-and-load latency for no size benefit. Must be installed in the Docker image; invoked from PHP via `proc_open`/`exec` with the password passed safely (avoid shell interpolation of user input) |
| `ffmpeg` | Transcode uploaded video to MP4 (H.265/HEVC); extract frame snapshots every 15s for preview generation; build short muted preview clips | Core dependency for upload processing and format conversion |
| PHP `exec`/`proc_open` | Shell out to `ffmpeg` and `7z` | No PHP extension for either exists that's production-safe — CLI invocation is the standard approach |
| (Optional) `ffprobe` | Read video metadata (duration, codec, resolution) on upload | Ships with ffmpeg |

## 2. App Flow

### 2.1 Login

1. User submits a **username** and **password**. Neither is stored anywhere by the app.
2. App checks that path `./{user}` exists (confirms a valid username).
3. App attempts to extract `{user}.json.enc` (a 7zip-encrypted archive) using the submitted password.
4. Success = login. Failure (bad path or bad password) = rejected, no further detail given.
5. Once logged in, the app reads the decrypted video index from `{user}.json.enc` and renders the video wall.

**Session storage:** the password (and the decrypted index, kept for the session so it isn't re-decrypted on every request) live in a PHP session — but the session store itself is redirected to tmpfs (`/dev/shm`, RAM-backed) instead of PHP's default on-disk session path, so the password never touches persistent disk. It's gone on logout or container restart. See `App/src/Session.php`.

### 2.2 Video Wall Load

1. Preview images and video metadata load first, from the decrypted datastore index.
2. The video file itself is never loaded until the user clicks to play it.
3. On hover, the preview clip (or sprite sheet / animated image — see §4.4) loads and plays.

### 2.3 Upload

1. User uploads a video file. `creator` defaults to `default`; `format` is whatever was uploaded.
2. If the uploaded format is not MP4 (H.265/HEVC), the video is tagged `not converted`.
3. On ingestion, the app generates:
   - A preview image (default: frame at 15s, or a user-chosen timestamp)
   - A short silent preview clip
   - Encrypted metadata
4. All generated artifacts are encrypted and written to the datastore (§3).

### 2.4 Conversion

- User-triggered: convert any video to MP4 (H.265/HEVC).
- On success, the `not converted` category tag is removed.

### 2.5 Edit / Delete

- **Video edit:** available from the video watch page; edits title, description, category tags, timestamped tags, etc.
- **Creator edit:** available from the user dropdown in the nav bar ("manage creators").
- **Delete:** removes a video (and its datastore files) or a Creator.

### 2.6 Password Change

1. User provides current password + new password (entered twice for confirmation).
2. App re-encrypts the entire datastore with the new password.
3. Order of operations: reprocess all video files first, then update `{user}.json.enc` last.
4. While reprocessing, the previous encrypted file is kept as `Video{ID}/{ID}.mp4.enc.old` until the run completes successfully, then removed.

### 2.7 Search / Filter / Sort

- **Search:** videos by title, creator, category tags.
- **Filter:** creators by age, gender, other details.
- **Sort:** videos by name, length, date.

## 3. Datastore Layout

All data is stored locally per-user; the app stores nothing server-side outside each user's own datastore directory. Multiple users each get their own isolated datastore and video wall.

The datastore root in this repo is `App/Data/` (gitignored — see [../README.md](../README.md) Repository Layout), so paths below are relative to that:

```
App/Data/{user}/videos/{user}.json.enc                       # encrypted video index (7z, password-locked)
App/Data/{user}/videos/Video{ID}/{ID}.mp4.enc                 # encrypted video file
App/Data/{user}/videos/Video{ID}/{ID}.mp4.preview.enc         # encrypted short preview clip
App/Data/{user}/videos/Video{ID}/{ID}.jpg.preview.enc         # encrypted preview thumbnail
App/Data/{user}/videos/Video{ID}/{ID}.json.enc                # encrypted per-video metadata
App/Data/{user}/videos/Video{ID}/{ID}.mp4.enc.old             # transient safety copy during re-encryption only
```

**Current state:** `App/Data/TestUser/videos/1.mp4` exists as a raw (unencrypted, un-ingested) sample fixture — a 2.3MB standard MP4 used to exercise the ffmpeg/7z pipeline in Phase 0 and the ingestion pipeline in Phase 3. It is not yet in the `Video{ID}/...enc` layout above; that conversion happens once the upload/ingestion code in Phase 3 runs against it.

## 4. UI/UX & Layout Specification

### 4.1 Visual Theme & Color Palette

High-contrast dark theme, optimized for media consumption.

- **Primary Background:** Deep Matte Dark Grey / Black (`#111111`–`#1b1b1b`)
- **Secondary Surface / Cards:** Dark Charcoal (`#222222`–`#2a2a2a`)
- **Primary Text & Icons:** White (`#ffffff`)
- **Secondary Text & Metadata:** Muted Mid-Grey (`#888888`)
- **Accent / Interactive:** Amber/Orange (`#ffa31a` / `#ff9900`) — CTAs, duration badges, verified ticks, active tabs
- **Borders & Dividers:** Dark Grey (`#333333`)

### 4.2 Page Structure & Components

```
+-----------------------------------------------------------------------+
|  LOGO  |  [ Search Bar... ]  (Categories)  |  [Filters] [User Profile]|
+-----------------------------------------------------------------------+
| (All)  [Category A]  [Category B]  [People/Creators]  [Top Rated] ...  |
+-----------------------------------------------------------------------+
|                                                                       |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|  | [Thumbnail]  |  | [Thumbnail]  |  | [Thumbnail]  |  | [Thumbnail]  |  |
|  |  (Preview)   |  |  (Preview)   |  |  (Preview)   |  |  (Preview)   |  |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|  | Title Text   |  | Title Text   |  | Title Text   |  | Title Text   |  |
|  | Creator Name |  | Creator Name |  | Creator Name |  | Creator Name |  |
|  | Views • Rating  | Views • Rating  | Views • Rating  | Views • Rating  |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|                                                                       |
+-----------------------------------------------------------------------+
```

**Header & Navigation:** Sticky, dark. Brand mark left, search bar (with auto-complete) center, user actions right. A horizontally scrolling category pill bar sits directly beneath (Trending, Most Recent, Category Names, Creators, ...).

**Media Grid:** CSS Grid, `repeat(auto-fill, minmax(280px, 1fr))`. Gap 12–16px. Container padding 16–24px.

**Creator Directory:** 6–8 column grid, circular avatars (`border-radius: 50%`), hover border transition to `#ffa31a`. Metadata: display name, view/subscriber count, verified badge.

### 4.3 Video Tile Specification

| Component | Position | Styling |
| --- | --- | --- |
| Thumbnail Box | Top | 16:9 (`aspect-ratio: 16/9`), 4px rounded corners, overflow hidden |
| Duration Badge | Bottom-right overlay | `rgba(0,0,0,0.8)` bg, white text, 11px, e.g. `14:20` |
| Quality Badge | Bottom-left overlay | Small amber rounded tag, e.g. `4K`, `HD` |
| Title | Below thumbnail | 14px, line-height 1.3, bold white, 2-line clamp + ellipsis |
| Creator/Channel | Under title | 12px, `#888888`, inline verified badge if applicable |
| Stats Line | Bottom row | 12px, `#888888`, e.g. `1.4M views • 96%` |

### 4.4 Hover-to-Preview Technical Specification

**Trigger logic:**
1. `mouseenter` on thumbnail container.
2. Debounce 150–200ms before triggering, to avoid firing during rapid scroll.
3. `mouseleave` cancels pending timers, stops playback, restores static thumbnail instantly.

**Preview implementation options (pick one per deployment):**

1. **Sprite sheet frame scrubbing (recommended for performance):** one composite `spritesheet.jpg` with 10–15 frames generated at ingestion; on hover, `setInterval` every 200–300ms steps `background-position`.
2. **Short video clip injection:** low-bitrate 3–5s `preview.mp4`; on hover, append/unhide a `<video muted loop playsinline>` and `.play()`.
3. **Animated image swap:** matching animated `.webp`/`.gif`; on hover, swap the `<img>` `src`.

**Progress indicator:** 2px accent-colored (`#ffa31a`) bar animates across the thumbnail's bottom edge during preview playback.
