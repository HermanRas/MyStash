const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/wall.html`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/wall_closed.png', fullPage: true });

  await page.click('#filters-toggle');
  await page.waitForSelector('#filter-panel.open');
  await page.screenshot({ path: '/work/screenshots/wall_open.png', fullPage: true });

  console.log('captured wall_closed.png and wall_open.png');
  await browser.close();
})();
