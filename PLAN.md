# MyStash.

## Idea
We are planning a video and Creator clarification wall with a short bio on Creator.
Creator has age, gender, and other details. The wall will have a search bar, filter, and categories.
Video will have a title, description, length, category tags and other details.
Catagories will have a name and color and be configured global. 
Video Tag Catagory will have an optional timestamp for jumping to a specific part of the video.
The wall will have a hover to preview feature for videos with a small field below showing: title, length, Creator and categories.

The site will support video and Creator upload, edit, and delete features. The site will support video and Creator search, filter, and sort features. The site will support video and Creator view count, but no like comment features as its a personal wall. The site will support a playlist features.

The site will be hosting personal videos before uploading to public a Key feature is Security and encryption of videos and Creator data. The site will support a local video wall with a UI/UX and layout specification.

Site login is a username and password {key} of sorts without it nothing stored can be decrypted. neither the username nor password is stored, on submit the app checks path ./{user} for confirmation of a valid username and attempts to extract {user}.json.enc (encrypted 7zip file encrypted with the password) if successfull the user is logged in. The encryption is a standard 7zip using its well known algorithm with password,the password key is not stored anywhere. once user is logged in the app will read all the video index list form {user}.json.enc and display the video wall. The app will start loading the video preview image and video metadata from the datastore. The app will not load the video file until the user clicks on the video to play it. on hover the app will load the video preview and play the video preview. The app will not store any data on the server, all data is stored locally in the datastore. The app will support multiple users with their own datastore and video wall.

the video preview is a photo taken every 15sec combined into a short clip with no sound, the default preview images is the 15sec image by default but user can upload or pic a custom time on video to capture as preview.

datastore: 
./{user}/videos/{user}.json.enc
./{user}/videos/Video{ID}/{ID}.mp4.enc
./{user}/videos/Video{ID}/{ID}.mp4.preview.enc
./{user}/videos/Video{ID}/{ID}.jpg.preview.enc
./{user}/videos/Video{ID}/{ID}.json.enc

tech stack:
- php
- docker container

during upload the video file is used to generate a preview image and a short video preview clip, both of which are encrypted and stored in the datastore. The video metadata is also encrypted and stored in the datastore. The app will use the video metadata to display the video wall and the video preview on hover. on upload the creator=default, and the format is what ever is uploaded format=x, any video not in mp4 with (H.265/MPEG-H HEVC) will also carry a catagory tag of not converted. the app will support a video conversion feature to convert any video to mp4 with (H.265/MPEG-H HEVC) and remove the not converted catagory tag. the app will support a video deletion feature to delete any video from the datastore. the app will support a video edit feature to edit any video metadata in the datastore. the app will support a creator edit feature to edit any creator metadata in the datastore. the video edit button will be available on the video watch page, the creator manage button will be available on the user drop down from nav bar. on the user profile page the user will be able to edit their password but not their username. the password change requires the user to enter their current password and the new password twice for confirmation. The app will re-encrypt the datastore with the new password and update the {user}.json.enc file last after reprocessing the videos. while processing the old files is kept as videos/Video{ID}/{ID}.mp4.enc.old incase something goes wrong. the app will support a video search feature to search for videos by title, creator and category tags. the app will support a creator filter feature to filter creators by age, gender, and other details. the app will support a video sort by name, length, and date.  
---

# Local Video Wall UI/UX & Layout Specification

## 1. Visual Theme & Color Palette

The interface utilizes a high-contrast dark theme optimized for media consumption, reducing eye strain and allowing video thumbnails to stand out.

* **Primary Background:** Deep Matte Dark Grey / Black (`#111111` to `#1b1b1b`)
* **Secondary Surface / Cards:** Dark Charcoal (`#222222` to `#2a2a2a`)
* **Primary Text & Icons:** High-contrast White (`#ffffff`)
* **Secondary Text & Metadata:** Muted Mid-Grey (`#888888`)
* **Accent & Interactive Color:** High-visibility Amber/Orange (`#ffa31a` or `#ff9900`) — used for call-to-action buttons, duration badges, verified ticks, and active tab states.
* **Borders & Dividers:** Subtle Dark Grey (`#333333`)

---

## 2. Page Structure & Components

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

### A. Header & Navigation Bar

* **Top Header:** Sticky position, dark background. Contains brand mark on the left, full-featured search bar centered with auto-complete, and user action buttons on the right.
* **Category Pill Bar:** Horizontal scrolling container directly beneath the header featuring quick-filter tags (e.g., *Trending*, *Most Recent*, *Category Names*, *Creators*).

### B. Media Grid Layout

* **Grid System:** CSS Grid / Responsive Fluid Layout (`repeat(auto-fill, minmax(280px, 1fr))`).
* **Gap Spacing:** Tight 12px to 16px grid gaps to maximize thumbnail density on the screen.
* **Padding:** 16px to 24px container margins.

### C. People / Creator Directory Section

* **Grid Variant:** 6 to 8 columns with smaller tile widths.
* **Tile Style:** Circular avatar profile pictures (`border-radius: 50%`) with hover border-color transitions (`#ffa31a`).
* **Metadata:** Creator display name, total view count or subscriber count, and verified badge indicator.

---

## 3. Video Tile Specification

Each video card unit follows a standardized vertical layout structure:

| Component | Position | Styling Details |
| --- | --- | --- |
| **Thumbnail Box** | Top | 16:9 aspect ratio container (`aspect-ratio: 16 / 9`) with 4px rounded corners. Overflow set to hidden. |
| **Duration Badge** | Bottom-Right (Overlay) | Absolute positioning. Semi-transparent black background (`rgba(0,0,0,0.8)`), white text (`11px` monospace/sans-serif), e.g., `14:20`. |
| **Quality Badge** | Bottom-Left (Overlay) | Absolute positioning. Small amber/yellow rounded tag indicating high resolution (e.g., `4K`, `HD`). |
| **Title** | Below Thumbnail | Font size `14px`, line-height `1.3`, bold white text. CSS line clamping enforced at 2 lines max with `text-overflow: ellipsis`. |
| **Creator / Channel** | Under Title | Font size `12px`, muted grey (`#888888`). Displays creator name alongside a small inline checkmark/badge if verified. |
| **Stats Line** | Bottom Row | Font size `12px`, muted grey. Displays aggregated statistics separated by a dot, e.g., `1.4M views • 96%`. |

---

## 4. Hover-to-Preview Technical Specification

The hover preview allows users to quickly scan video content without navigating away from the grid.

### Trigger Logic

1. **Event Listener:** `mouseenter` on the thumbnail container.
2. **Debounce Delay:** Include a ~150ms–200ms delay before triggering preview rendering to prevent accidental activations during rapid scrolling.
3. **Exit Listener:** `mouseleave` cancels pending timeouts, stops playback/animation, and instantly restores the static default thumbnail image.

### Preview Implementation Options

1. **Sprite Sheet Frame Scrubbing (Recommended for Performance)**
* **Mechanism:** Generate a single composite image (`spritesheet.jpg`) during video ingestion containing 10–15 frame snapshots arranged horizontally or in a grid.
* **Execution:** On hover, run a JavaScript `setInterval` every 200ms–300ms updating the CSS `background-position` property of the thumbnail container to step through frames sequentially.


2. **Short Video Clip Injection**
* **Mechanism:** Prepare a low-bitrate 3–5 second clip snippet (`preview.webm` or `preview.mp4`).
* **Execution:** On hover, dynamically append or unhide a `<video>` element with `muted`, `loop`, and `playsinline` attributes enabled, then invoke `.play()`.


3. **Animated Image Swap**
* **Mechanism:** Maintain a matching animated `.webp` or `.gif` file for each video.
* **Execution:** Replace the primary `src` of the static `<img>` tag with the animated URL on hover.



### Progress Indicator

* A 2px high accent-colored (`#ffa31a`) loading/progress bar animates linearly across the bottom edge of the thumbnail during the hover preview sequence to show timeline playback progress.