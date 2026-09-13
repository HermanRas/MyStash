// Every screenshot in the README, taken against the stash dev/seed_sample_data.sh
// builds. One script rather than twenty-five: the login happens once, the order
// is the order a reader meets the features in, and a re-run after a UI change
// produces a directly comparable set.
//
// Two things this cannot photograph, and they are worth stating rather than
// silently skipping:
//
//  - **the hover preview** (04_wall_hover.png);
//  - **a video actually playing** (11_watch_playing.png).
//
// Both are H.264 or HEVC, and the Chromium that ships with Playwright is built
// without either — `canPlayType` returns "" for both codecs, so the video
// element errors here while playing perfectly in a real browser. Those two
// files are captured by hand in Chrome and live in Docs/Assets/README only;
// nothing below writes to those names, so re-running this leaves them alone.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'http://app:8080';
const OUT = '/work/screenshots/readme';
const USER = process.env.PROBE_USER || 'TestUser';
const PASSWORD = process.env.PROBE_PASS || 'DS89HONPtufGDncNUoGfshCg';

// Fixed ids, because the seed script builds the same stash every time and
// these three shots need particular videos: one that plays in this browser,
// one still tagged Not Converted, and one big enough that converting it is
// still running when the screenshot is taken.
const UNCONVERTED = process.env.UNCONVERTED_ID || '3'; // 480p H.264
const CONVERTIBLE = process.env.CONVERTIBLE_ID || '12'; // 4K VP9

fs.mkdirSync(OUT, { recursive: true });

const shot = async (page, name, opts = {}) => {
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(250);
  await page.screenshot({ path: `${OUT}/${name}.png`, ...opts });
  console.log(`  ${name}.png`);
};

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

  // --- the two screens before there is a session --------------------------
  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await shot(page, '01_login');

  await page.goto(`${BASE}/register.php`, { waitUntil: 'networkidle' });
  await shot(page, '02_register');

  await page.goto(`${BASE}/login.html`);
  await page.fill('#username', USER);
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  // --- the wall -----------------------------------------------------------
  await page.waitForTimeout(1500);            // every thumbnail is a decrypt
  await shot(page, '03_wall');
  await shot(page, '03_wall_full', { fullPage: true });

  // --- filtering, searching, sorting --------------------------------------
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  for (const s of await page.locator('.filter-section').all()) {
    if (!(await s.evaluate((el) => el.open))) await s.locator('summary').click();
  }
  await page.waitForTimeout(300);
  await shot(page, '05_filters');

  await page.goto(`${BASE}/wall.php?categories[]=CATS`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  await shot(page, '06_filtered');

  await page.goto(`${BASE}/wall.php?q=cat`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  await shot(page, '07_search');

  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  await page.click('#sort-toggle').catch(async () => {
    await page.locator('.sort-menu button').first().click();
  });
  await page.waitForTimeout(300);
  await shot(page, '08_sort');

  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  await page.click('#upload-toggle');
  await page.waitForTimeout(300);
  await shot(page, '09_upload');

  // --- the watch page -----------------------------------------------------
  await page.goto(`${BASE}/video.php?id=${CONVERTIBLE}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await shot(page, '10_watch');

  await page.goto(`${BASE}/video.php?id=${CONVERTIBLE}&edit=1`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await shot(page, '12_watch_edit', { fullPage: true });

  await page.goto(`${BASE}/video.php?id=${CONVERTIBLE}`, { waitUntil: 'networkidle' });
  await page.locator('.playlist-trigger').click();
  await page.waitForTimeout(300);
  await shot(page, '13_playlist_dropdown');

  // --- creators -----------------------------------------------------------
  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  await shot(page, '14_creators');

  const firstCreator = await page.$eval('.creator-card:not(:first-child)',
    (a) => new URL(a.href, location.origin).searchParams.get('edit'));
  await page.goto(`${BASE}/creator.php?edit=${encodeURIComponent(firstCreator)}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await shot(page, '15_creator_edit');

  // --- categories ---------------------------------------------------------
  await page.goto(`${BASE}/category.php`, { waitUntil: 'networkidle' });
  await shot(page, '16_categories');

  // --- playlists ----------------------------------------------------------
  await page.goto(`${BASE}/playlist.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
  await shot(page, '17_playlists');

  await page.goto(`${BASE}/playlist.php?id=1`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await shot(page, '18_playlist_edit');

  // Mid-drag, not before or after: the row is picked up, moved to the top of
  // the list and held there while the shot is taken, so the screenshot shows
  // the thing the feature actually is.
  const rows = page.locator('.playlist-row');
  const from = await rows.last().locator('.playlist-grip').boundingBox();
  const to = await rows.first().boundingBox();
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  await page.mouse.move(to.x + to.width / 2, to.y + 10, { steps: 20 });
  await page.waitForTimeout(400);
  await shot(page, '19_playlist_drag');
  await page.mouse.up();

  await page.goto(`${BASE}/playlist.php?id=1`, { waitUntil: 'networkidle' });
  await page.click('#add-videos-open');
  await page.waitForTimeout(400);
  await shot(page, '20_playlist_modal');

  // --- the user menu and the profile screen -------------------------------
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  await page.locator('.user-menu').hover();
  await page.locator('.user-menu').evaluate((el) => el.focus());
  await page.waitForTimeout(300);
  await shot(page, '21_user_menu');

  await page.goto(`${BASE}/user.php`, { waitUntil: 'networkidle' });
  await shot(page, '22_profile', { fullPage: true });

  // --- conversion ---------------------------------------------------------
  // Last, because it changes the stash: the video it converts stops being
  // tagged Not Converted, which every earlier screenshot of the wall shows.
  await page.goto(`${BASE}/video.php?id=${UNCONVERTED}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await shot(page, '23_not_converted', { fullPage: true });

  await page.goto(`${BASE}/video.php?id=${CONVERTIBLE}`, { waitUntil: 'networkidle' });
  const convert = page.locator('button:has-text("Convert to MP4/H.265")');
  if (await convert.count() > 0) {
    await convert.click();
    await page.waitForSelector('#job-card', { state: 'visible' });
    await page.waitForFunction(() => {
      const t = document.querySelector('#job-message');
      return t && /Converting/i.test(t.textContent);
    }, null, { timeout: 60000 }).catch(() => console.log('  (no progress text)'));
    await page.waitForTimeout(1500);
    await shot(page, '24_converting');
  } else {
    console.log('  (video ' + CONVERTIBLE + ' is already converted — 24_converting kept)');
  }

  await browser.close();
  console.log('done');
})();
