const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 950 } });
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  // Icons should all be one size now.
  // Menu icons live in a display:none dropdown until hovered, so measure only
  // what is actually on screen.
  await page.locator('.user-menu-trigger').hover();
  const sizes = await page.$$eval('.pill-icon, .menu-icon, .btn-icon',
    (els) => [...new Set(els.map((e) => Math.round(e.getBoundingClientRect().width))
      .filter((w) => w > 0))]);
  check(`every UI icon is one size (${sizes.join(', ')}px)`, sizes.length === 1);

  check('the Apply button is gone', (await page.locator('#filter-form button[type="submit"]:visible').count()) === 0);
  check('the Reset link is gone', (await page.locator('.filter-panel a:has-text("Reset")').count()) === 0);

  // A wrapped item would be taller than its siblings.
  check('no user-menu item wraps to a second line', await page.$$eval('.user-menu-dropdown a',
    (els) => new Set(els.map((e) => Math.round(e.getBoundingClientRect().height))).size === 1));
  await page.screenshot({ path: '/work/screenshots/ui_menu.png' });
  await page.mouse.move(700, 600);
  await page.screenshot({ path: '/work/screenshots/ui_wall.png' });

  // Ticking a category should apply straight away.
  const before = await page.locator('.video-card').count();
  const box = page.locator('.filter-panel input[type="checkbox"]').first();
  if (await box.count() > 0) {
    const name = await box.getAttribute('value');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), box.check()]);
    check(`ticking "${name}" filters without an Apply button`,
      page.url().includes(`category%5B%5D=${encodeURIComponent(name)}`));
    console.log(`  ${before} -> ${await page.locator('.video-card').count()} tiles`);
  }

  // Video page order: title, player, meta, categories, creator.
  await page.goto(`${BASE}/video.php?id=1`, { waitUntil: 'networkidle' });
  const order = await page.$$eval('.watch-title, .player, .watch-meta, .creator-strip',
    (els) => els.map((e) => [...e.classList].pop()));
  console.log('  watch page order:', order.join(' -> '));
  check('title is above the player', order.indexOf('watch-title') < order.indexOf('player'));
  check('creator is last', order[order.length - 1] === 'creator-strip');
  await page.screenshot({ path: '/work/screenshots/ui_video.png', fullPage: true });

  await page.goto(`${BASE}/video.php?id=1&edit=1`, { waitUntil: 'networkidle' });
  const bg = await page.locator('#v-creator').evaluate((e) => getComputedStyle(e).backgroundColor);
  check(`selects are themed dark (${bg})`, bg === 'rgb(42, 42, 42)');
  await page.screenshot({ path: '/work/screenshots/ui_video_edit.png', fullPage: true });

  await browser.close();
})();
