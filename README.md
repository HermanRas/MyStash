# MyStash

A private, self-hosted video and Creator wall for personal media libraries — before anything gets uploaded to a public platform.

## What it is

MyStash lets you keep a personal collection of videos, organized around **Creators** (short bios: age, gender, other details) and global **Categories** (name + color). Every video carries a title, description, length, and category tags, and category tags can optionally carry a timestamp so a tag jumps straight to that point in the video.

The wall itself is a searchable, filterable, sortable grid with a hover-to-preview feature: hovering a thumbnail shows a short silent preview clip plus title, length, Creator, and categories.

## Core features

- Video and Creator upload, edit, and delete
- Search, filter, and sort for both videos and Creators
- View counts (no likes/comments — this is a personal wall, not a social one)
- Playlists
- Automatic preview generation (thumbnail + short silent preview clip) at upload time
- Automatic "not converted" tagging for any upload that isn't MP4/H.265, plus a conversion feature to fix it
- Password-based re-encryption of the whole datastore on password change, with an `.old` safety copy of processed files kept until the whole run succeeds

## Why it's built this way

Nothing sensitive is ever stored in plaintext, and nothing about the login itself (username or password) is stored anywhere. The password **is** the decryption key: it unlocks a per-user 7zip-encrypted archive, and if that archive won't decrypt, the login fails. See [Docs/SPECIFICATIONS.md](Docs/SPECIFICATIONS.md) for how the encryption, datastore layout, and login flow work, and [Docs/PLAN.md](Docs/PLAN.md) for the phased build plan.

## Documentation map

| Document | Contents |
| --- | --- |
| [README.md](README.md) | This file — project overview |
| [Docs/SPECIFICATIONS.md](Docs/SPECIFICATIONS.md) | Tech stack, app flow, datastore layout, encryption model, UI/UX spec, third-party dependencies |
| [Docs/PLAN.md](Docs/PLAN.md) | Phased build plan, broken into small objectives |

## Repository Layout

```
App/                    application code (PHP) and the runtime datastore
App/Data/               per-user encrypted datastore root — gitignored, local only
App/Data/{user}/videos/ that user's video files and index
Docs/                   design references and branding assets (not app code)
```

`App/Data/` is excluded from git (see `.gitignore`) since it holds — or will hold — per-user encrypted media. A `TestUser` fixture with a sample raw `.mp4` lives under `App/Data/TestUser/videos/` for local dev/testing of the ingestion pipeline (see [Docs/PLAN.md](Docs/PLAN.md) Phase 0–1).

## Branding

Working name: **MyStash — Video Wall**. Logo/icon and banner live in `Docs/Assets/` (`icon.png`, `Banner.jpg`) — black-and-gold mark. `Docs/Assets/UI_Idea.jpg` is a loose style reference only (component shapes/spacing), not the color direction — the actual UI palette is the dark/amber theme defined in [Docs/SPECIFICATIONS.md](Docs/SPECIFICATIONS.md) §4.
