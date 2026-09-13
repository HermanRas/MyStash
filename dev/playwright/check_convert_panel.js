// 4.32 — while a conversion runs, the progress panel must be the first thing
// on the watch page. It used to render below the player and the action row,
// which on a short window put it off the bottom of the screen entirely.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const USER = process.env.PROBE_USER;
const PASS = process.env.PROBE_PASS;

(async () => {
  const browser = await chromium.launch();
  // Deliberately short: the whole complaint was that the panel was below the
  // fold, so a tall viewport would hide the bug rather than catch it.
  const page = await browser.newPage({ viewport: { width: 1400, height: 800 } });
  let fails = 0;
  const check = (label, ok) => {
    if (!ok) fails++;
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  };

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', USER);
  await page.fill('#password', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

  await page.goto(`${BASE}/video.php?id=1`, { waitUntil: 'networkidle' });

  const card = page.locator('#job-card');
  check('the conversion panel is on the page', await card.count() === 1);
  if (await card.count() === 0) {
    await browser.close();
    process.exit(1);
  }

  const job = await card.boundingBox();
  const title = await page.locator('.watch-title').boundingBox();
  const player = await page.locator('#player').boundingBox();

  console.log(`  panel at y=${Math.round(job.y)}, title y=${Math.round(title.y)}, player y=${Math.round(player.y)}`);

  check('the panel is above the title', job.y < title.y);
  check('the panel is above the player', job.y < player.y);
  check('the whole panel is visible without scrolling',
    job.y + job.height <= page.viewportSize().height);

  await page.screenshot({ path: '/work/screenshots/convert_panel.png' });
  await browser.close();
  console.log(fails === 0 ? 'check_convert_panel: all passed' : `check_convert_panel: ${fails} failed`);
  process.exit(fails);
})();
