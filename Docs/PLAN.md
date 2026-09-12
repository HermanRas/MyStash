# MyStash — Build Plan

See [../README.md](../README.md) for the project overview and [SPECIFICATIONS.md](SPECIFICATIONS.md) for tech stack, app flow, datastore layout, and UI/UX spec. This document breaks the build into phases, each made up of small, independently-checkable objectives.

## Phase 0 — Environment & Dependencies

Get the Docker/PHP environment able to do the two things the whole app depends on: encode video to MP4/H.265 and create password-protected 7zip archives.

- [x] 0.1 Base Dockerfile: PHP + web server (php-fpm/nginx or php built-in server for dev) — `App/Dockerfile` (`php:8.3-cli`), `docker-compose.yml`
- [x] 0.2 Install `ffmpeg` in the container; verify `ffmpeg -version` and `ffprobe -version` from PHP via `exec` — verified via `App/src/VideoEncoder.php`
- [x] 0.3 Install `p7zip-full` (provides `7z` CLI) in the container; verify `7z` from PHP via `exec` — verified via `App/src/Crypto7z.php`
- [x] 0.4 PHP wrapper function: encode an input video to MP4/H.265 via `ffmpeg` (`-c:v libx265`), return success/failure + output path — `App/src/VideoEncoder.php`
- [x] 0.5 PHP wrapper function: create an AES-256-encrypted `.7z` archive from a file/directory with a password, using `proc_open` and compression level `-mx=0` (store only — 7zip is for encryption here, not compression; recompressing already-compressed video wastes CPU and slows load) (password passed via stdin or a temp arg array — never interpolated into a shell string) — `App/src/Crypto7z.php`
- [x] 0.6 PHP wrapper function: extract a password-protected `.7z` archive, return success/failure (used for login check) — `App/src/Crypto7z.php`
- [x] 0.7 Smoke test script: round-trip `App/Data/TestUser/videos/1.mp4` (existing sample fixture) through encrypt → extract → compare, and through ffmpeg conversion — `App/tests/smoke_test.php`, all 11 checks passing

## Phase 1 — Static HTML Samples (no backend logic yet)

Build one static HTML sample per core screen, styled to the dark theme in SPECIFICATIONS.md §4, with placeholder/dummy data. Goal: validate layout and hover-preview behavior before wiring any PHP. Place these under `App/` (e.g. `App/public/` or equivalent front-controller-served path) rather than the repo root. Use `Docs/Assets/icon.png` and `Docs/Assets/Banner.jpg` for branding; treat `Docs/Assets/UI_Idea.jpg` as loose component-shape inspiration only — the actual palette stays the dark/amber theme in SPECIFICATIONS.md §4.1, not the red accent shown there.

- [x] 1.1 `login.html` — username + password form, matching dark theme — `App/public/login.html`
- [x] 1.2 `wall.html` — header, search bar, category pill bar, responsive video grid, dummy tiles with hover-to-preview (sprite scrub or clip injection, pick one per §4.4) — `App/public/wall.html` (CSS-driven hover progress bar placeholder pending real preview assets)
- [x] 1.3 `video.html` — video watch page: player, title, description, category tags (with jump-to-timestamp), edit button — `App/public/video.html`
- [x] 1.4 `creator.html` — creator directory tile grid + a single creator detail/bio view — `App/public/creator.html`
- [x] 1.5 `user.html` — user profile page: password change form (current + new + confirm), nav dropdown with "manage creators" link — `App/public/user.html`

Verified by serving `App/public/` via `docker compose up` and screenshotting all 5 pages with a disposable `mcr.microsoft.com/playwright` container (`docker-compose.dev.yml`, `dev/playwright/screenshot.js`) — never install browser/test tooling on the host, always run it in a container joined to the app's compose network.

## Phase 2 — Datastore & Login

- [x] 2.1 Define `{user}.json.enc` schema (video index: ID, title, category tags incl. timestamped ones, creator ref, length, view count, format, conversion status) — `App/src/Datastore.php` docblock; seeded via `App/bin/seed_testuser.php`
- [x] 2.2 Define per-video `{ID}.json.enc` metadata schema — documented in `App/src/Datastore.php` docblock; written by Phase 3 ingestion and by the seed script, and since 4.6 it is the source of truth for category assignments
- [x] 2.3 Login endpoint: check `./{user}` path exists, attempt `{user}.json.enc` extraction with submitted password (Phase 0.6), return session on success — `App/public/login.php` + `App/src/Datastore::loadIndex()`
- [x] 2.4 Session handling: password held only in memory for the session (never persisted), used to decrypt/re-encrypt on demand — `App/src/Session.php`, backed by tmpfs (`/dev/shm`) rather than the default on-disk session store, so the password never touches persistent disk
- [x] 2.5 Wire `wall.html` to real decrypted index data for the logged-in user — renamed to `App/public/wall.php`, renders `Session::index()` server-side; verified end-to-end (wrong password rejected, correct password decrypts + renders, unauthenticated access redirected, logout clears session) with curl and a disposable Playwright container

## Phase 3 — Upload & Ingestion Pipeline

- [x] 3.1 Upload endpoint: accept video file, assign `{ID}`, create `Video{ID}/` directory — `App/public/upload.php`, `App/src/VideoIngest.php`, `Datastore::nextVideoId()`/`videoDir()`
- [x] 3.2 Format check: tag `not converted` if not MP4/H.265 (use ffprobe) — `VideoIngest::ingest()`, adds `Not Converted` to categories
- [x] 3.3 Preview image generation: extract frame at 15s default (or user-chosen timestamp) via ffmpeg — wired to the upload form's optional "preview at" field
- [x] 3.4 Preview clip generation: short silent low-bitrate clip via ffmpeg
- [x] 3.5 Encrypt generated artifacts (mp4, preview clip, preview image, metadata) into the datastore layout (Phase 0.5)
- [x] 3.6 Update `{user}.json.enc` index with the new video entry — `Datastore::saveIndex()` called from `upload.php`, session index updated too
- [x] 3.7 Wire `wall.html` uploads flow end-to-end with a real file — `+ Upload` panel on `wall.php`; verified via curl multipart upload: files land in `Video{ID}/`, decrypt back to byte-identical content, metadata correct (codec/not_converted/preview), new tile appears on the wall

### Phase 3 follow-ups (from idea review)

- [x] 3.8 Rebuild the preview clip as a **timelapse**, per SPECIFICATIONS.md §2.3: sample one frame every 15s across the whole video and join them into a short silent clip — `VideoEncoder::buildPreviewClip()`. Done in two ffmpeg passes (sample stills, then join at 4fps); a single pass using `setpts`/`-r` silently dropped most sampled frames. Verified: a 62s source now yields a 4-frame, 1s, 480px-wide clip spanning the whole video instead of a continuous excerpt.
- [ ] 3.9 Let the user set the preview image by **uploading their own** as well as picking a timestamp, and allow changing it after upload from the video edit screen. Only "pick a timestamp at upload time" exists today.

## Phase 4 — Video & Creator Management

- [x] 4.0 Media serving endpoint: decrypt-on-the-fly streaming for preview thumbnails/clips (wall hover-preview) and full video playback (video watch page) — `App/public/media.php`, extracts to tmpfs and wipes via `register_shutdown_function`, supports HTTP Range for video seeking. Wired into `wall.php` (real `<img>` thumbnail over the gradient fallback, `<video>` hover-preview per §4.4's debounced mouseenter/mouseleave) and `video.php` (click-to-load `<video controls>`, never fetched eagerly). Verified via curl (200/206, correct content-type, correct decrypted bytes) and in-browser network responses. Actual on-screen playback could not be screenshotted in the Playwright container's bundled Chromium — it ships without H.264/HEVC decoders (`canPlayType` returns empty for both), a test-environment limitation unrelated to the server-side implementation; real browsers (Chrome/Firefox/Safari) ship licensed codec support.
- [x] 4.1 Video edit (title, description, category tags, timestamps) from `video.html` — renamed to `App/public/video.php` (view + `?edit=1` mode), `video_save.php`, plus the timestamped-tag endpoints later replaced by `video_category_add.php`/`video_category_delete.php` in 4.6
- [x] 4.2 Video delete (remove `Video{ID}/` directory, update index) — `App/public/video_delete.php`
- [x] 4.3 Video conversion feature: convert to MP4/H.265, remove `not converted` tag on success — `App/public/video_convert.php`; fails gracefully (no-op + banner) for index entries with no real encrypted file behind them (e.g. seeded demo data)
- [x] 4.4 Creator CRUD (create/edit/delete) from the nav dropdown "manage creators" screen — renamed to `App/public/creator.php`, `creator_save.php`/`creator_delete.php`; renaming a creator repoints their videos, deleting reassigns videos to `default`
- [x] 4.5 Category management: global list of name + color, used by both video tagging and pill bar — added to `creator.php`, `category_save.php`/`category_delete.php`; deleting a category strips it from every video's category list

All verified end-to-end via curl (edit/rename/delete/convert/tag add-delete/category add-delete) and screenshots of the rendered pages; datastore reset to the clean seed afterward.

### Phase 4 follow-ups (from review)

- [x] 4.6 Categories reworked to the model in SPECIFICATIONS.md §2.7: global definitions (name + colour) in `{user}.json`, per-video assignments (category + `hh:mm:ss` timestamp) in `{ID}.json`, the same category assignable more than once, and the wall filter populated from the global list. Replaces the old split between plain "categories" and separate "timestamp tags" (`App/src/VideoCategories.php`, `video_category_add.php`/`video_category_delete.php`). `App/bin/migrate_categories.php` converts existing datastores.
- [x] 4.7 Category chips on the watch page seek the player to their timestamp.
- [x] 4.8 Fixed: the user dropdown closed when the pointer crossed the gap below the trigger — the gap is now a transparent top border on the menu, so it stays within the hover target.
- [x] 4.9 Fixed: a deleted video could reappear on the wall. Delete was correct; the cause was that every write saved the session's login-time copy of the whole index, so a second session could revert the first's changes. All pages/endpoints now re-read the index from disk first (`Session::refreshIndex()`), and the migration prunes index entries left with no media behind them.
- [ ] 4.10 Creator avatar image: upload/replace a picture per creator, encrypted in the datastore and served through `media.php`. The UI spec (§4.2, §4.3) calls for circular avatars and the idea lists "Creator upload", but creators currently have no image field — every tile renders an empty gradient circle.
- [ ] 4.11 Creator view count — the idea asks for "video **and** Creator view count". Creator tiles show a video count today; the view count (sum of their videos' views) is not tracked or shown.
- [ ] 4.12 Show **length** in the tile's text line under the thumbnail (idea: "title, length, Creator and categories"). Length currently appears only as the duration badge overlaying the thumbnail.
- [x] 4.13 Global categories moved to their own screen (`App/public/category.php`), reached from the user dropdown instead of being buried at the bottom of the Creators page. Shows how many videos use each category, allows recolouring, and removal now only retires the definition — videos keep tags they already have (SPECIFICATIONS.md §2.7).
- [x] 4.14 Fixed: the wall's and profile page's "Manage Creators" menu item still pointed at `creator.html`, which stopped existing when it was renamed to `creator.php` — so that menu item was dead.
- [x] 4.15 The wall's filter panel is now open by default; the Filters button collapses it.

## Phase 5 — Search, Filter, Sort, Playlists

- [ ] 5.1 Video search by title, creator, category tags — includes wiring the header search input, which appears on every page today but is connected to nothing
- [ ] 5.2 Creator filter by age, gender, other details
- [ ] 5.3 Video sort by name, length, date — **needs 5.6 first**: nothing in the index carries a date to sort on
- [ ] 5.4 Playlist creation and playback
- [ ] 5.5 View count tracking (increment on watch, no likes/comments)
- [ ] 5.6 Add an upload/added date to each index entry. `uploaded_at` exists only in the per-video metadata, which the wall never reads, so "sort by date" has no data behind it.
- [ ] 5.7 Make the wall's filter panel actually filter (length range, categories). The panel is UI-only right now; 5.2 covers creator filters only.
- [ ] 5.8 Creator search — the idea asks for "video **and** Creator search"; only video search is covered by 5.1.
- [ ] 5.9 Creator sort — the idea asks for "video **and** Creator ... sort"; only video sort is covered by 5.3.

## Phase 6 — Password Change & Re-encryption

- [ ] 6.1 Password change form validation (current password check via trial extraction, new password entered twice) — also convert `App/public/user.html` to PHP; it is still the static Phase 1 mockup and is the only page not yet wired
- [ ] 6.2 Re-encryption pass: for each video, decrypt with old password, re-encrypt with new password, keeping `{ID}.mp4.enc.old` until the whole run succeeds. Note this covers **all four** encrypted files per video (`.mp4.enc`, `.mp4.preview.enc`, `.jpg.preview.enc`, `.json.enc`), not just the video — the idea names only `.mp4.enc.old`, but every file is locked with the same password
- [ ] 6.3 Update `{user}.json.enc` last, only after all video files are successfully re-encrypted
- [ ] 6.4 Cleanup of `.old` files on success; rollback plan if a step fails mid-run

## Phase 7 — Multi-user Support & Hardening

- [x] 7.0 **User creation flow** — `App/public/register.php` + `App/src/User.php`, linked from the login page. Creates `{user}/videos/{user}.json.enc` with an empty video list, a `default` creator and a `Not Converted` category (both required by ingestion), then signs the user straight in. Usernames are letters/digits only, max 32 chars, and must be unused; the same validation now guards `login.php`, which also closes the path-traversal hole in 7.1. Verified: symbols, traversal attempts, duplicates, empty and mismatched passwords all rejected; a new stash logs in isolated and empty.
- [ ] 7.1 Confirm full isolation between `App/Data/{user}/` datastores — path traversal via `{user}` is handled (see 7.0); still to check: that one user can't probe another's existence, and that no cross-user paths leak anywhere else
- [ ] 7.2 Review PHP `exec`/`proc_open` calls for command-injection safety (arguments arrays, not string interpolation)
- [ ] 7.3 Rate-limit / lockout considerations on login attempts
- [ ] 7.4 Final review against SPECIFICATIONS.md app flow (§2) for completeness
