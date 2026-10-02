// The Stats screen, desktop and phone width. Read-only against TestUser: it
// opens the page and the user menu, and never presses Inspect or Reduce —
// both write to the stash (dev/run_stats_check.sh drives those against a
// throwaway one).
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  let fails = 0;
  const check = (label, ok) => {
    if (!ok) fails++;
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  };

  for (const [name, viewport] of [['desktop', { width: 1400, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    const page = await browser.newPage({ viewport });
    await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
    await page.fill('#username', 'TestUser');
    await page.fill('#password', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForSelector('.video-grid');

    await page.goto(`${BASE}/stats.php`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: `/work/screenshots/stats_${name}.png`, fullPage: true });

    const sizes = await page.$$eval('.stats-table tbody tr td:nth-child(3)', (tds) => tds.map((td) => td.textContent.trim()));
    console.log(`  ${name}: ${sizes.length} rows, sizes ${sizes.join(', ')}`);
    check(`${name}: the page has a row per video`, sizes.length > 0);

    // The table scrolls inside its own box rather than widening the page.
    // Measured on the main column, not the document: the shared site header
    // is wider than a phone on every page, which is not this screen's doing.
    const overflow = await page.evaluate(() =>
      document.querySelector('main').getBoundingClientRect().right - window.innerWidth);
    check(`${name}: the Stats column fits the window (${Math.round(overflow)}px over)`, overflow <= 0);

    if (name === 'desktop') {
      await page.hover('.user-menu');
      await page.screenshot({ path: '/work/screenshots/stats_menu.png', clip: { x: 1000, y: 0, width: 400, height: 360 } });
      check('the user menu carries Stats', await page.isVisible('.user-menu-dropdown a[href="stats.php"]'));
    }
    await page.close();
  }

  await browser.close();
  process.exit(fails);
})();
