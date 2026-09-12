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

**Password rules:** at least **24 characters**. Because the password is the encryption key, it is never stored, can never be reset, and cannot be rate-limited where it matters — anyone holding a copy of the `.7z` files can attack them offline as fast as their hardware allows. Length is therefore the only real defence. The minimum is enforced at registration and on password change; it is deliberately *not* enforced at login, so stashes created before the rule still open.

Because the password *is* the encryption key and is never stored, a forgotten password means the stash cannot be recovered — by the user or anyone else.

1. User submits a **username** and **password**. Neither is stored anywhere by the app.
2. App checks that path `./{user}` exists (confirms a valid username).
3. App attempts to extract `{user}.json.enc` (a 7zip-encrypted archive) using the submitted password.
4. Success = login. Failure (bad path or bad password) = rejected, no further detail given.
5. Once logged in, the app reads the decrypted video index from `{user}.json.enc` and renders the video wall.

**Session storage:** the password (and a cached copy of the decrypted index) live in a PHP session — but the session store itself is redirected to tmpfs (`/dev/shm`, RAM-backed) instead of PHP's default on-disk session path, so the password never touches persistent disk. It's gone on logout or container restart. See `App/src/Session.php`.

**Stale sessions:** if the index no longer decrypts with the password the session holds, the stash was re-keyed elsewhere and the session is ended immediately (back to the login page, with an explanation). It must not simply fall back to its cached index: every write re-encrypts with the session's password, so carrying on would rewrite the index and creator records under the *old* key and split the stash across two passwords.

**Index freshness:** every page and endpoint re-reads the index from disk (`Session::refreshIndex()`) rather than trusting the copy taken at login. Because each change rewrites the whole index, a session working from a login-time snapshot would silently revert changes made in another session — that is exactly how a deleted video reappeared on the wall once.

### 2.2 Video Wall Load

1. Preview images and video metadata load first, from the decrypted datastore index.
2. The video file itself is never loaded until the user clicks to play it.
3. On hover, the preview clip (or sprite sheet / animated image — see §4.4) loads and plays.

### 2.3 Upload

1. User uploads a video file. The creator list defaults to `[default]`; `format` is whatever was uploaded.
2. If the uploaded format is not MP4 (H.265/HEVC), the video is tagged `not converted`.
3. On ingestion, the app generates:
   - **A preview image** — by default the frame at 15s. The user may instead pick a different timestamp, or upload their own image. This can be changed after upload, from the video's edit screen.
   - **A preview clip** — a *timelapse*, not an excerpt: one frame sampled every 15s across the whole video, joined into a short clip with no sound. So the preview skims the entire video rather than showing one continuous moment.
   - Encrypted metadata
4. All generated artifacts are encrypted and written to the datastore (§3).

**Derived tags — quality and "not converted".** Neither is editable. They describe the file, so letting a user type them in would only let them lie about it; both are recalculated from the stored technical facts (pixel height, container, codec) on ingest, on **every video save**, and on conversion. Conversion measures the converted file while it is still decrypted in tmpfs, which is the one moment the real height is cheap to read.

Quality follows the standard tiers, keyed on vertical pixel count (`p` = progressive). A video takes the highest tier its height reaches. Standard definition gets no per-height badge — 360p and 480p are both just `SD` — and an unknown height gets no badge at all:

| Height | Badge | Class |
| --- | --- | --- |
| 4320 | `8K` | UHD — 8K UHD, 7680 × 4320 |
| 2160 | `4K` | UHD — 4K UHD, 3840 × 2160 |
| 1440 | `2K` | UHD — 2K / QHD, 2560 × 1440 |
| 1080 | `Full HD` | HD — the standard for streaming and Blu-ray, 1920 × 1080 |
| 720 | `HD` | HD — "Ready HD", the minimum for high definition, 1280 × 720 |
| below 720 | `SD` | SD — 360p and 480p, legacy formats for tube TVs and low-bandwidth streaming |

**Views** are counted when playback actually starts (the player's `playing` event), not when the watch page loads — opening a video without watching it does not count, and a file the browser cannot decode is not counted as watched. The count is written to both the per-video metadata and the index entry, so the wall can show it without decrypting anything. There are no likes, ratings or comments anywhere in the app.

### 2.4 Conversion

- User-triggered: convert any video to MP4 (H.265/HEVC).
- On success, the `not converted` category tag is removed.

### 2.5 Edit / Delete

- **Video edit:** available from the video watch page; edits title, description, creators and category assignments. Quality and "not converted" are not editable — see §2.3.
- **Creator edit:** available from the user dropdown in the nav bar ("manage creators"); edits name, age, gender, bio and profile picture. Renaming a creator repoints every video that referenced the old name; the creator's ID and files stay put.
- **Delete:** removes a video (and its datastore files), or a Creator (and their record + profile picture, reassigning their videos to `default`).

### 2.6 Password Change

1. User provides current password + new password (entered twice for confirmation); the new password must meet the §2.1 minimum length.
2. App re-encrypts the entire datastore with the new password — **every** archive, not just the videos: the four files per video, each creator's record and profile picture, and the index.
3. Order of operations: reprocess everything else first, then update `{user}.json.enc` last. While the index still opens with the old password, an interrupted run is retryable.
4. While reprocessing, the previous encrypted file is kept alongside as `{name}.enc.old` until the run completes successfully, then removed. A failed re-encrypt restores that one archive from its `.old` and aborts before touching anything further.

The re-encryption pass exists as `App/bin/rekey_user.php {user} {old} {new}`; the in-app form is still to come.

### 2.7 Categories

Categories work like creators: they are **global definitions** (name + colour) managed once per user, and videos *reference* them.

- **Definitions** live in the index (`{user}.json`) as `{"<name>": "<hex colour>"}` and are managed on the Categories screen.
- **Assignments** live in the video's own metadata (`Video{ID}/{ID}.json`) as `{"name": "<category>", "timestamp_seconds": N}`.
- Each assignment carries a timestamp (`hh:mm:ss`, default `00:00:00`) so a category can point at a specific moment; clicking it on the watch page seeks the player there.
- The **same category may be assigned multiple times** at different timestamps. Only the exact same category at the exact same timestamp is rejected as a duplicate.
- On the video edit screen you pick a category from the global list — categories can't be invented ad hoc per video.
- Removing a global category only **retires the definition**: it can no longer be assigned to videos and it disappears from the wall's filter panel, but videos already tagged with it **keep their tags** (they render in a neutral grey, since there's no colour to look up).
- The wall's filter list is populated from the global definitions.
- Categories are managed on their own screen (`category.php`), reached from the user dropdown in the nav bar — the same place as "Manage Creators" — and from the `Categories` pill in the top nav.
- Clicking a category on that screen opens the wall filtered to it (`wall.php?category[]=<name>`), which is the same URL the filter panel produces.

The index also keeps a **de-duplicated copy of each video's category names** on its entry. That copy is derived, not authoritative: it exists so the wall grid and its filters can render without decrypting every video's metadata archive on each page load.

### 2.8 Search / Filter / Sort

- **Search:** videos by title, creator, category tags.
- **Filter:** videos by category, creator and length range; creators by age, gender, other details.
- **Sort:** videos by title, length, views or upload date.

**Sort options** (the wall's sort menu, right of the result count):

| Sort | Order |
| --- | --- |
| Uploaded | new → old *(default)*, old → new |
| Title | A → Z, Z → A |
| Length | short → long, long → short |
| Views | min → max, max → min |

Filtering and sorting are applied **server-side**, against the already-decrypted index, and carried in the query string (`?category[]=…&creator[]=…&len_min=…&len_max=…&sort=…`). That means a filtered wall renders exactly the tiles it should rather than hiding rows in the browser, the two compose with each other, and any filter can be linked to — which is how clicking a category on the Categories screen opens a filtered wall. `All Videos` in the top nav links to the bare wall URL, so it doubles as the reset. See `App/src/VideoQuery.php`.

The top of the length slider is an **open end**, not a ceiling: at maximum it reads "any" and stops filtering on length.

## 3. Datastore Layout

All data is stored locally per-user; the app stores nothing server-side outside each user's own datastore directory. Multiple users each get their own isolated datastore and video wall.

The datastore root in this repo is `App/Data/` (gitignored — see [../README.md](../README.md) Repository Layout), so paths below are relative to that:

```
App/Data/{user}/videos/{user}.json.enc                         # encrypted video index (7z, password-locked)
App/Data/{user}/videos/Video{ID}/{ID}.mp4.enc                  # encrypted video file
App/Data/{user}/videos/Video{ID}/{ID}.mp4.preview.enc          # encrypted short preview clip
App/Data/{user}/videos/Video{ID}/{ID}.jpg.preview.enc          # encrypted preview thumbnail
App/Data/{user}/videos/Video{ID}/{ID}.json.enc                 # encrypted per-video metadata
App/Data/{user}/creators/Creator{ID}/{ID}.json.enc             # encrypted creator record
App/Data/{user}/creators/Creator{ID}/{ID}.profile.png.enc      # encrypted creator profile picture
App/Data/{user}/**/*.enc.old                                   # transient safety copy during re-encryption only
```

**A video may credit more than one creator.** Both the index entry and the per-video metadata carry `"creators": ["<name>", ...]`, and the edit screen offers the global creator list as checkboxes. Every video has at least one: emptying the list falls back to `default`, the creator ingestion assigns and the only one that cannot be deleted. Deleting a creator drops them from every video they were on rather than reassigning the whole video, so a video credited to two people keeps the other. Entries written before this carry a single `creator` string; reads go through `VideoCreators::of()`, and `bin/migrate_records.php` rewrites them.

**Creators** are stored the same way videos are. The creator record (`{ID}.json`: id, name, age, gender, bio, created/updated timestamps) is authoritative; the index keeps a denormalized summary of each so the wall, the creator grid and the filter panel can render without decrypting every creator archive on each page load — the same arrangement as the category names on video entries. Creators are still keyed by **display name** in the index, because that is what a video's `creator` field references; the ID only addresses the files, and survives a rename. Uploaded profile pictures are normalised to PNG before encryption, so the stored filename always describes the actual bytes.

**Current state:** `App/Data/TestUser/videos/1.mp4` remains as a raw (unencrypted) sample fixture used to exercise the ffmpeg/7z pipeline directly (Phase 0 smoke test) and as upload input for manual testing — it is not itself part of the datastore layout. `App/bin/seed_testuser.php` seeds `TestUser.json.enc`, a `{ID}.json.enc` per demo entry and a `{ID}.json.enc` per demo creator (password `DS89HONPtufGDncNUoGfshCg` — 24 characters, per §2.1); it **merges**, so re-running it refreshes the demo rows without touching real uploads. Demo entries carry metadata only — no media files — so playback/conversion for them reports "no encrypted video file". Uploads through `App/public/upload.php` create complete `Video{ID}/...enc` entries following the layout above.

## 4. UI/UX & Layout Specification

### 4.0 Branding & Icons

All artwork is generated by the project owner and delivered into `Docs/Assets/` (prompts kept in `Docs/Assets/gemini_asset prompt.md`). Nothing in the UI should invent its own iconography while the sheet covers it.

- `site_icons.png` — a sheet of flat `#ffa31a` icons on black, sliced at build time into transparent PNGs under `App/public/assets/img/icons/` (upload, download, creator, A-Z / Z-A / 0-9 / 9-0 sorts, tag, user, login, logout, videos, register, chevrons).
- `icon.png` — the logo lockup, cropped into `mark.png` (the 32px header mark) and `logo.png` (the login/register cards).

Because the icons are a single flat colour, any icon placed on an amber ground (an active nav pill, a primary button, the user avatar) is knocked back to dark ink with `filter: brightness(0)` rather than shipping a second set of files.

Every inline UI icon is one size, set once as `--icon-size` — nav pills, menu items and buttons all use it, so no screen ends up with a mix. Selects are themed globally (`select { … }`) rather than per screen; left alone they render as white native controls against the dark theme.

### 4.1 Visual Theme & Color Palette

High-contrast dark theme, optimized for media consumption.

- **Primary Background:** Deep Matte Dark Grey / Black (`#111111`–`#1b1b1b`)
- **Secondary Surface / Cards:** Dark Charcoal (`#222222`–`#2a2a2a`)
- **Primary Text & Icons:** White (`#ffffff`)
- **Secondary Text & Metadata:** Muted Mid-Grey (`#888888`)
- **Accent / Interactive:** Amber/Orange (`#ffa31a` / `#ff9900`) — CTAs, duration badges, active tabs
- **Borders & Dividers:** Dark Grey (`#333333`)

### 4.2 Page Structure & Components

```
+-----------------------------------------------------------------------+
|  LOGO  |  [ Search Bar... ]       |  [+ Upload] [Filters] [User Profile]|
+-----------------------------------------------------------------------+
| (All Videos)  [Creators]  [Categories]                                 |
+-----------------------------------------------------------------------+
| FILTERS  |  7 of 7 videos                              Sort [ ______ ] |
+-----------------------------------------------------------------------+
|                                                                       |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|  | [Thumbnail]  |  | [Thumbnail]  |  | [Thumbnail]  |  | [Thumbnail]  |  |
|  |  (Preview)   |  |  (Preview)   |  |  (Preview)   |  |  (Preview)   |  |
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|  | Title Text   |  | Title Text   |  | Title Text   |  | Title Text   |  |
|  | Creator Name |  | Creator Name |  | Creator Name |  | Creator Name |  |
|  | Length•Views•Cats| Length•Views•Cats| Length•Views•Cats| Length•Views•Cats|
|  +--------------+  +--------------+  +--------------+  +--------------+  |
|                                                                       |
+-----------------------------------------------------------------------+
```

**Header & Navigation:** Sticky, dark. Brand mark left, search bar (with auto-complete) center, user actions right. A pill bar sits directly beneath with exactly three destinations, identical on every page: **All Videos**, **Creators**, **Categories** (`App/views/header.php`).

The nav deliberately carries **no category names** — those live in the wall's left filter panel, and having both was two ways to do the same thing. It carries no "Most Recent" either: that is a sort order, and it belongs in the sort menu. There are likewise no ranking pills such as "Trending" or "Top Rated" — this is a personal wall with no ratings (see §2.8). `All Videos` points at the bare wall URL, so it also resets whatever filters are applied.

**Wall toolbar:** above the grid — the result count on the left (`2 of 7 videos (filtered)`), the sort menu on the right.

**Filter panel:** left side, **open by default**, collapsed by the Filters button. Titled **Filters**, with each group a native `<details>` accordion in the order **Categories → Videos → Creators**. Categories is open on load; a group holding an active filter re-opens too, so a filter is never hidden behind a collapsed heading. Category checkboxes, a length range, creator checkboxes, and creator age/gender. There is **no Apply button** — the form submits on change (range inputs fire `change` on release, so dragging doesn't reload mid-drag) — and **no Reset button**, because `All Videos` in the top nav is the bare wall URL and already does exactly that.

**Media Grid:** CSS Grid, `repeat(auto-fill, minmax(280px, 1fr))`. Gap 12–16px. Container padding 16–24px.

**Creator Directory:** 6–8 column grid, circular avatars (`border-radius: 50%`), hover border transition to `#ffa31a`. Metadata: display name, video count, view count, age. There is deliberately no "verified" badge: this is a private stash, with nobody to verify a creator against.

### 4.2.1 Watch Page Order

Top to bottom, single column (no side rail): **title**, player, meta line (views • length • quality • conversion state), category chips, **creator card**, actions (Edit / Convert), description. The title leads because it names what you are looking at before you look at it; the creator follows the categories because it is reference material, not the point of the page.

### 4.3 Video Tile Specification

| Component | Position | Styling |
| --- | --- | --- |
| Thumbnail Box | Top | 16:9 (`aspect-ratio: 16/9`), 4px rounded corners, overflow hidden |
| Duration Badge | Bottom-right overlay | `rgba(0,0,0,0.8)` bg, white text, 11px, e.g. `14:20` |
| Quality Badge | Bottom-left overlay | Small amber rounded tag, e.g. `4K`, `Full HD`, `480p` — derived from pixel height, see §2.3 |
| Title | Below thumbnail | 14px, line-height 1.3, bold white, 2-line clamp + ellipsis |
| Creator/Channel | Under title | 12px, `#888888`. All credited creators, comma-separated |
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
