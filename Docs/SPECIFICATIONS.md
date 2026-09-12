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

### 2.1 Registration & Login

**Creating a stash:** a user is nothing more than a datastore directory plus an index archive encrypted with their password — there is no account record and no stored password. Registration (`register.php`) takes a username, a password and a confirmation, then creates `App/Data/{user}/videos/{user}.json.enc` holding an empty video list, a `default` creator and a `Not Converted` category (both of which ingestion relies on). Usernames are **letters and digits only**, at most 32 characters, and must be unused. That rule is also what makes a username safe to use directly as a path segment — no separators or dots, so it cannot escape the data directory. The same validation guards login.

Because the password *is* the encryption key and is never stored, a forgotten password means the stash cannot be recovered — by the user or anyone else.

1. User submits a **username** and **password**. Neither is stored anywhere by the app.
2. App checks that path `./{user}` exists (confirms a valid username).
3. App attempts to extract `{user}.json.enc` (a 7zip-encrypted archive) using the submitted password.
4. Success = login. Failure (bad path or bad password) = rejected, no further detail given.
5. Once logged in, the app reads the decrypted video index from `{user}.json.enc` and renders the video wall.

**Session storage:** the password (and a cached copy of the decrypted index) live in a PHP session — but the session store itself is redirected to tmpfs (`/dev/shm`, RAM-backed) instead of PHP's default on-disk session path, so the password never touches persistent disk. It's gone on logout or container restart. See `App/src/Session.php`.

**Index freshness:** every page and endpoint re-reads the index from disk (`Session::refreshIndex()`) rather than trusting the copy taken at login. Because each change rewrites the whole index, a session working from a login-time snapshot would silently revert changes made in another session — that is exactly how a deleted video reappeared on the wall once.

### 2.2 Video Wall Load

1. Preview images and video metadata load first, from the decrypted datastore index.
2. The video file itself is never loaded until the user clicks to play it.
3. On hover, the preview clip (or sprite sheet / animated image — see §4.4) loads and plays.

### 2.3 Upload

1. User uploads a video file. `creator` defaults to `default`; `format` is whatever was uploaded.
2. If the uploaded format is not MP4 (H.265/HEVC), the video is tagged `not converted`.
3. On ingestion, the app generates:
   - **A preview image** — by default the frame at 15s. The user may instead pick a different timestamp, or upload their own image. This can be changed after upload, from the video's edit screen.
   - **A preview clip** — a *timelapse*, not an excerpt: one frame sampled every 15s across the whole video, joined into a short clip with no sound. So the preview skims the entire video rather than showing one continuous moment.
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

### 2.7 Categories

Categories work like creators: they are **global definitions** (name + colour) managed once per user, and videos *reference* them.

- **Definitions** live in the index (`{user}.json`) as `{"<name>": "<hex colour>"}` and are managed on the Creators screen.
- **Assignments** live in the video's own metadata (`Video{ID}/{ID}.json`) as `{"name": "<category>", "timestamp_seconds": N}`.
- Each assignment carries a timestamp (`hh:mm:ss`, default `00:00:00`) so a category can point at a specific moment; clicking it on the watch page seeks the player there.
- The **same category may be assigned multiple times** at different timestamps. Only the exact same category at the exact same timestamp is rejected as a duplicate.
- On the video edit screen you pick a category from the global list — categories can't be invented ad hoc per video.
- Removing a global category only **retires the definition**: it can no longer be assigned to videos and it disappears from the wall's filter panel, but videos already tagged with it **keep their tags** (they render in a neutral grey, since there's no colour to look up).
- The wall's filter list is populated from the global definitions.
- Categories are managed on their own screen (`category.php`), reached from the user dropdown in the nav bar — the same place as "Manage Creators".

The index also keeps a **de-duplicated copy of each video's category names** on its entry. That copy is derived, not authoritative: it exists so the wall grid and its filters can render without decrypting every video's metadata archive on each page load.

### 2.8 Search / Filter / Sort

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

**Current state:** `App/Data/TestUser/videos/1.mp4` remains as a raw (unencrypted) sample fixture used to exercise the ffmpeg/7z pipeline directly (Phase 0 smoke test) and as upload input for manual testing — it is not itself part of the datastore layout. `App/bin/seed_testuser.php` seeds `TestUser.json.enc` plus a `{ID}.json.enc` per demo entry (password `testpass123`); it **merges**, so re-running it refreshes the demo rows without touching real uploads. Demo entries carry metadata only — no media files — so playback/conversion for them reports "no encrypted video file". Uploads through `App/public/upload.php` create complete `Video{ID}/...enc` entries following the layout above.

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
| (All)  [Most Recent]  [Not Converted]  [Creators]  [Category A] ...    |
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

**Header & Navigation:** Sticky, dark. Brand mark left, search bar (with auto-complete) center, user actions right. A horizontally scrolling category pill bar sits directly beneath (Most Recent, Not Converted, Creators, then the global category names). There are deliberately no ranking pills such as "Trending" or "Top Rated" — this is a personal wall with no ratings (see §2.8).

**Media Grid:** CSS Grid, `repeat(auto-fill, minmax(280px, 1fr))`. Gap 12–16px. Container padding 16–24px.

**Creator Directory:** 6–8 column grid, circular avatars (`border-radius: 50%`), hover border transition to `#ffa31a`. Metadata: display name, video count, view count, age, verified badge.

### 4.3 Video Tile Specification

| Component | Position | Styling |
| --- | --- | --- |
| Thumbnail Box | Top | 16:9 (`aspect-ratio: 16/9`), 4px rounded corners, overflow hidden |
| Duration Badge | Bottom-right overlay | `rgba(0,0,0,0.8)` bg, white text, 11px, e.g. `14:20` |
| Quality Badge | Bottom-left overlay | Small amber rounded tag, e.g. `4K`, `HD` |
| Title | Below thumbnail | 14px, line-height 1.3, bold white, 2-line clamp + ellipsis |
| Creator/Channel | Under title | 12px, `#888888`, inline verified badge if applicable |
| Stats Line | Bottom row | 12px, `#888888`. Shows length, view count and categories, e.g. `14:20 • 128 views • Personal, Highlights`. No rating or score — there are no likes/ratings anywhere in the app |

### 4.4 Hover-to-Preview Technical Specification

**Trigger logic:**
1. `mouseenter` on thumbnail container.
2. Debounce 150–200ms before triggering, to avoid firing during rapid scroll.
3. `mouseleave` cancels pending timers, stops playback, restores static thumbnail instantly.

**In use: option 2 (short video clip injection)** — the encrypted `{ID}.mp4.preview.enc` timelapse from §2.3, served through `media.php` and played in a `<video muted loop playsinline>` on hover. The other two options are recorded here as alternatives only.

**Preview implementation options:**

1. **Sprite sheet frame scrubbing (recommended for performance):** one composite `spritesheet.jpg` with 10–15 frames generated at ingestion; on hover, `setInterval` every 200–300ms steps `background-position`.
2. **Short video clip injection:** low-bitrate 3–5s `preview.mp4`; on hover, append/unhide a `<video muted loop playsinline>` and `.play()`.
3. **Animated image swap:** matching animated `.webp`/`.gif`; on hover, swap the `<img>` `src`.

**Progress indicator:** 2px accent-colored (`#ffa31a`) bar animates across the thumbnail's bottom edge during preview playback.
