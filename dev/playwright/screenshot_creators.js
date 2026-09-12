const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  const id = await page.locator('.video-card').first().getAttribute('href');
  const videoId = new URL(id, BASE).searchParams.get('id');

  // Credit two creators on the video.
  await page.goto(`${BASE}/video.php?id=${videoId}&edit=1`, { waitUntil: 'networkidle' });
  const boxes = page.locator('input[name="creators[]"]');
  check(`the edit screen offers every creator as a checkbox (${await boxes.count()})`, await boxes.count() >= 2);

  const before = await page.locator('input[name="creators[]"]:checked').count();
  await boxes.nth(1).check();
  await boxes.nth(2).check();
  await page.click('button[type="submit"]:has-text("Save Changes")');
  await page.waitForSelector('.watch-title');

  const cards = page.locator('.creator-strip');
  check(`the watch page lists every credited creator (${await cards.count()}, was ${before})`,
    (await cards.count()) === 3);
  console.log('  heading:', await page.locator('.section-title').first().innerText());
  await page.screenshot({ path: '/work/screenshots/creators_video.png', fullPage: true });

  // The wall tile should list them all.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  const tile = await page.locator('.tile-creator').first().innerText();
  check(`the wall tile names all of them ("${tile.trim()}")`, tile.includes(',')),
  await page.screenshot({ path: '/work/screenshots/creators_wall.png' });

  // Each creator's video count should include the shared video.
  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  const counts = await page.locator('.creator-meta').allInnerTexts();
  console.log('  creator counts:', counts.map((c) => c.trim().replace(/\s+/g, ' ')).join(' | '));
  check('every credited creator counts the video',
    counts.filter((c) => /^1 video/.test(c.trim())).length === 3);
  await page.screenshot({ path: '/work/screenshots/creators_grid.png' });

  // Put it back to a single creator.
  await page.goto(`${BASE}/video.php?id=${videoId}&edit=1`, { waitUntil: 'networkidle' });
  await page.locator('input[name="creators[]"]').nth(1).uncheck();
  await page.locator('input[name="creators[]"]').nth(2).uncheck();
  await page.click('button[type="submit"]:has-text("Save Changes")');
  await page.waitForSelector('.watch-title');
  check('back to one creator', (await page.locator('.creator-strip').count()) === 1);

  await browser.close();
})();
