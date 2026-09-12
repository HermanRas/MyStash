const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/register.php`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/register.png' });
  console.log('captured register.png');

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', 'testpass123');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  const filterOpen = await page.locator('#filter-panel').isVisible();
  console.log('filter panel visible on load:', filterOpen);
  await page.screenshot({ path: '/work/screenshots/wall_filters_default.png' });

  // The Filters button should now collapse it.
  await page.click('#filters-toggle');
  console.log('filter panel visible after clicking Filters:', await page.locator('#filter-panel').isVisible());

  // Categories must be reachable from the user menu.
  await page.locator('.user-menu-trigger').hover();
  const menuItems = await page.locator('.user-menu-dropdown a').allInnerTexts();
  console.log('user menu:', menuItems.join(' | '));
  await page.screenshot({ path: '/work/screenshots/user_menu.png' });

  await page.goto(`${BASE}/category.php`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/categories_page.png', fullPage: true });
  console.log('captured categories_page.png');

  await browser.close();
})();
