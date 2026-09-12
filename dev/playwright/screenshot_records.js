const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

// A real PNG fixture to upload as a creator profile picture.
const AVATAR = '/work/avatar_fixture.png';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });

  const fail = (msg) => { console.log('FAIL: ' + msg); process.exitCode = 1; };
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`) || (ok || fail(label));

  // --- login with the 24-character password ------------------------------
  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');
  check('logs in with the new 24-char password', page.url().includes('wall.php'));

  // --- top nav -----------------------------------------------------------
  const pills = await page.locator('.category-bar .pill').allInnerTexts();
  console.log('nav pills:', JSON.stringify(pills));
  check('nav is exactly All Videos / Creators / Categories',
    JSON.stringify(pills.map(p => p.trim())) === '["All Videos","Creators","Categories"]');

  await page.screenshot({ path: '/work/screenshots/records_wall.png' });

  // --- sort --------------------------------------------------------------
  const titles = async () => (await page.locator('.tile-title').allInnerTexts()).map(t => t.trim());

  // Changing the menu submits the form, so wait the navigation out before reading.
  const sortBy = async (value) => {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.selectOption('#sort', value),
    ]);
    await page.waitForSelector('.video-grid');
  };

  await sortBy('title_asc');
  const asc = await titles();
  await sortBy('title_desc');
  const desc = await titles();
  check('title A→Z and Z→A are exact reverses',
    JSON.stringify(asc) === JSON.stringify([...desc].reverse()) && asc.length > 1);
  console.log('  A→Z first/last:', asc[0], '|', asc[asc.length - 1]);

  await sortBy('length_desc');
  const badges = (await page.locator('.badge.duration').allInnerTexts()).map(t => t.trim());
  console.log('  length long→short:', badges.join(' '));

  await sortBy('views_desc');
  const views = (await page.locator('.tile-stats').allInnerTexts())
    .map(t => Number(t.match(/(\d+) views/)[1]));
  check('views max→min is descending', views.every((v, i) => i === 0 || views[i - 1] >= v));
  console.log('  views:', views.join(' '));

  await sortBy('uploaded_desc');
  await page.screenshot({ path: '/work/screenshots/records_sorted.png' });

  // --- category click on the Categories screen opens a filtered wall ------
  await page.goto(`${BASE}/category.php`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/records_categories.png', fullPage: true });
  await page.locator('.card a.tag', { hasText: 'Highlights' }).first().click();
  await page.waitForSelector('.video-grid');
  check('clicking a category opens the filtered wall', page.url().includes('category%5B0%5D=Highlights'));

  const filteredCount = await page.locator('.video-card').count();
  const checked = await page.locator('input[type="checkbox"][name="category[]"][value="Highlights"]').isChecked();
  check('the filter panel reflects the applied category', checked);
  console.log('  filtered wall shows', filteredCount, 'video(s);', await page.locator('.wall-count').innerText());
  await page.screenshot({ path: '/work/screenshots/records_filtered.png' });

  // Reset Filters returns the full wall.
  await page.click('a.btn.secondary:has-text("Reset Filters")');
  await page.waitForSelector('.video-grid');
  check('Reset Filters restores every video', (await page.locator('.video-card').count()) > filteredCount);

  // --- creator profile picture ------------------------------------------
  await page.goto(`${BASE}/creator.php?edit=${encodeURIComponent('Alex R.')}`, { waitUntil: 'networkidle' });
  await page.setInputFiles('#c-profile', AVATAR);
  await page.click('button[type="submit"]:has-text("Save Changes")');
  await page.waitForSelector('.creator-grid');

  const avatarImg = page.locator('.creator-card', { hasText: 'Alex R.' }).locator('img').first();
  check('creator card renders the uploaded profile picture', await avatarImg.count() > 0);

  if (await avatarImg.count() > 0) {
    const src = await avatarImg.getAttribute('src');
    const response = await page.request.get(`${BASE}/${src}`);
    check('the avatar decrypts and serves as a PNG',
      response.status() === 200 && response.headers()['content-type'] === 'image/png');
    console.log('  avatar:', src, response.status(), response.headers()['content-type'],
      (await response.body()).length, 'bytes');
  }

  await page.screenshot({ path: '/work/screenshots/records_creators.png', fullPage: true });

  // --- register page password rule --------------------------------------
  await page.goto(`${BASE}/register.php`, { waitUntil: 'networkidle' });
  const minlength = await page.locator('#password').getAttribute('minlength');
  check('registration asks for at least 24 characters', minlength === '24');

  const short = await page.request.post(`${BASE}/register.php`, {
    form: { username: 'shortpwuser', password: 'tooshort123', confirm_password: 'tooshort123' },
  });
  check('a short password is rejected server-side', (await short.text()).includes('at least 24 characters'));

  await page.screenshot({ path: '/work/screenshots/records_register.png' });

  await browser.close();
})();
