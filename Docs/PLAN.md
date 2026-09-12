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
- [x] 2.2 Define per-video `{ID}.json.enc` metadata schema — documented in `App/src/Datastore.php` docblock; files themselves are created during Phase 3 ingestion, not yet on disk
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

## Phase 4 — Video & Creator Management

- [x] 4.0 Media serving endpoint: decrypt-on-the-fly streaming for preview thumbnails/clips (wall hover-preview) and full video playback (video watch page) — `App/public/media.php`, extracts to tmpfs and wipes via `register_shutdown_function`, supports HTTP Range for video seeking. Wired into `wall.php` (real `<img>` thumbnail over the gradient fallback, `<video>` hover-preview per §4.4's debounced mouseenter/mouseleave) and `video.php` (click-to-load `<video controls>`, never fetched eagerly). Verified via curl (200/206, correct content-type, correct decrypted bytes) and in-browser network responses. Actual on-screen playback could not be screenshotted in the Playwright container's bundled Chromium — it ships without H.264/HEVC decoders (`canPlayType` returns empty for both), a test-environment limitation unrelated to the server-side implementation; real browsers (Chrome/Firefox/Safari) ship licensed codec support.
- [x] 4.1 Video edit (title, description, category tags, timestamps) from `video.html` — renamed to `App/public/video.php` (view + `?edit=1` mode), `video_save.php`, `video_tag_add.php`/`video_tag_delete.php`
- [x] 4.2 Video delete (remove `Video{ID}/` directory, update index) — `App/public/video_delete.php`
- [x] 4.3 Video conversion feature: convert to MP4/H.265, remove `not converted` tag on success — `App/public/video_convert.php`; fails gracefully (no-op + banner) for index entries with no real encrypted file behind them (e.g. seeded demo data)
- [x] 4.4 Creator CRUD (create/edit/delete) from the nav dropdown "manage creators" screen — renamed to `App/public/creator.php`, `creator_save.php`/`creator_delete.php`; renaming a creator repoints their videos, deleting reassigns videos to `default`
- [x] 4.5 Category management: global list of name + color, used by both video tagging and pill bar — added to `creator.php`, `category_save.php`/`category_delete.php`; deleting a category strips it from every video's category list

All verified end-to-end via curl (edit/rename/delete/convert/tag add-delete/category add-delete) and screenshots of the rendered pages; datastore reset to the clean seed afterward.

## Phase 5 — Search, Filter, Sort, Playlists

- [ ] 5.1 Video search by title, creator, category tags
- [ ] 5.2 Creator filter by age, gender, other details
- [ ] 5.3 Video sort by name, length, date
- [ ] 5.4 Playlist creation and playback
- [ ] 5.5 View count tracking (increment on watch, no likes/comments)

## Phase 6 — Password Change & Re-encryption

- [ ] 6.1 Password change form validation (current password check via trial extraction, new password entered twice)
- [ ] 6.2 Re-encryption pass: for each video, decrypt with old password, re-encrypt with new password, keeping `{ID}.mp4.enc.old` until the whole run succeeds
- [ ] 6.3 Update `{user}.json.enc` last, only after all video files are successfully re-encrypted
- [ ] 6.4 Cleanup of `.old` files on success; rollback plan if a step fails mid-run

## Phase 7 — Multi-user Support & Hardening

- [ ] 7.1 Confirm full isolation between `App/Data/{user}/` datastores
- [ ] 7.2 Review PHP `exec`/`proc_open` calls for command-injection safety (arguments arrays, not string interpolation)
- [ ] 7.3 Rate-limit / lockout considerations on login attempts
- [ ] 7.4 Final review against SPECIFICATIONS.md app flow (§2) for completeness
