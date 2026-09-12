const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', 'wrongpassword');
  await page.click('button[type="submit"]');
  await page.waitForSelector('#login-error:not([hidden])');
  await page.screenshot({ path: '/work/screenshots/login_error.png', fullPage: true });
  console.log('captured login_error.png');

  await page.fill('#username', 'TestUser');
  await page.fill('#password', 'testpass123');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');
  await page.screenshot({ path: '/work/screenshots/wall_live.png', fullPage: true });
  console.log('captured wall_live.png (real decrypted data)');

  await browser.close();
})();
