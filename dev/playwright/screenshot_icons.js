const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);

  // Any icon that 404s or fails to decode shows as a broken image.
  const broken = [];
  page.on('response', (r) => { if (r.status() >= 400) broken.push(`${r.status()} ${r.url()}`); });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/icons_login.png' });

  await page.goto(`${BASE}/login.html?error=stale`, { waitUntil: 'networkidle' });
  check('the stale-session notice renders on login', await page.locator('#login-stale').isVisible());

  await page.goto(`${BASE}/register.php`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/icons_register.png' });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');
  check('logs in after the re-key', page.url().includes('wall.php'));
  console.log('  wall shows', await page.locator('.video-card').count(), 'video(s)');

  await page.locator('.user-menu-trigger').hover();
  await page.screenshot({ path: '/work/screenshots/icons_wall.png' });

  await page.goto(`${BASE}/creator.php?edit=${encodeURIComponent('Jamie K.')}`, { waitUntil: 'networkidle' });
  check('the Verified checkbox is gone', (await page.locator('input[name="verified"]').count()) === 0);

  const buttons = page.locator('.form-actions .btn');
  check('Save and Delete share one row', (await buttons.count()) === 2);
  if (await buttons.count() === 2) {
    const [a, b] = [await buttons.nth(0).boundingBox(), await buttons.nth(1).boundingBox()];
    check('the two buttons are side by side', Math.abs(a.y - b.y) < 4 && b.x > a.x);
  }
  await page.screenshot({ path: '/work/screenshots/icons_creator.png', fullPage: true });

  // Saving through the new out-of-form button must still submit the form.
  const originalBio = await page.locator('#c-bio').inputValue();
  await page.fill('#c-bio', 'Edited via the shared button row');
  await page.click('.form-actions .btn:has-text("Save Changes")');
  await page.waitForSelector('.creator-grid');
  await page.goto(`${BASE}/creator.php?edit=${encodeURIComponent('Jamie K.')}`, { waitUntil: 'networkidle' });
  check('the out-of-form Save button still saves',
    (await page.locator('#c-bio').inputValue()) === 'Edited via the shared button row');

  // Put the bio back so the test leaves no trace in the stash.
  await page.fill('#c-bio', originalBio);
  await page.click('.form-actions .btn:has-text("Save Changes")');
  await page.waitForSelector('.creator-grid');

  await page.goto(`${BASE}/category.php`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/icons_categories.png' });

  check(`no failed requests (${broken.length})`, broken.length === 0);
  broken.forEach((b) => console.log('   ' + b));

  await browser.close();
})();
