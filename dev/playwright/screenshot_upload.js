const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  await page.click('#upload-toggle');
  await page.waitForSelector('#upload-panel.open');
  await page.screenshot({ path: '/work/screenshots/upload_panel.png', fullPage: true });
  console.log('captured upload_panel.png');

  await browser.close();
})();
