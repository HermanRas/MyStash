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

  // Dropdown must survive the pointer crossing the gap below the trigger.
  const trigger = page.locator('.user-menu-trigger');
  await trigger.hover();
  const box = await trigger.boundingBox();
  // Move down through the gap in small steps, as a real pointer would.
  for (let dy = 2; dy <= 30; dy += 4) {
    await page.mouse.move(box.x + box.width / 2, box.y + box.height + dy);
  }
  const stillOpen = await page.locator('.user-menu-dropdown a', { hasText: 'Manage Creators' }).isVisible();
  console.log('dropdown still open after crossing the gap:', stillOpen);
  await page.screenshot({ path: '/work/screenshots/dropdown_gap.png' });

  // Category editing UI on the real video.
  await page.goto(`${BASE}/video.php?id=8&edit=1`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/video_categories_edit.png', fullPage: true });
  console.log('captured video_categories_edit.png');

  // Filter panel populated from the global category list.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  await page.click('#filters-toggle');
  await page.waitForSelector('#filter-panel.open');
  await page.screenshot({ path: '/work/screenshots/wall_category_filter.png', fullPage: true });
  console.log('captured wall_category_filter.png');

  await browser.close();
})();
