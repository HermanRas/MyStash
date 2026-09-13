const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  check('the panel has a "Filters" title',
    (await page.locator('.filter-title').innerText()).trim() === 'Filters');

  const groups = await page.locator('.filter-section summary').allInnerTexts();
  console.log('  groups:', groups.map((g) => g.trim()).join(' -> '));
  // Rendered uppercase by CSS, so compare case-insensitively.
  check('groups are Categories -> Videos -> Creators',
    JSON.stringify(groups.map((g) => g.trim().toLowerCase())) === '["categories","videos","creators"]');

  const open = await page.$$eval('.filter-section', (els) => els.map((e) => e.open));
  check('only Categories is open by default', JSON.stringify(open) === '[true,false,false]');
  await page.screenshot({ path: '/work/screenshots/filters_default.png' });

  // Collapse Categories, expand Creators.
  await page.locator('.filter-section summary').first().click();
  await page.locator('.filter-section summary').nth(2).click();
  const afterToggle = await page.$$eval('.filter-section', (els) => els.map((e) => e.open));
  check('sections collapse and expand', JSON.stringify(afterToggle) === '[false,false,true]');

  const creatorBoxes = page.locator('input[name="creator[]"]');
  check(`creators have checkboxes (${await creatorBoxes.count()})`, await creatorBoxes.count() >= 3);
  await page.screenshot({ path: '/work/screenshots/filters_creators.png' });

  // A creator filter should actually filter, and survive a sort change.
  const all = await page.locator('.video-card').count();
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    creatorBoxes.nth(1).check(),
  ]);
  const name = await page.locator('input[name="creator[]"]:checked').getAttribute('value');
  console.log(`  filtering on "${name}": ${all} -> ${await page.locator('.video-card').count()} tiles`);
  check('the creator filter is in the URL', page.url().includes('creator%5B%5D='));
  check('the Creators group re-opens because a filter is active',
    (await page.$$eval('.filter-section', (els) => els.map((e) => e.open)))[2] === true);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.selectOption('#sort', 'title_asc'),
  ]);
  check('sorting keeps the creator filter', page.url().includes('creator%5B%5D='));
  check('the checkbox stays ticked', await page.locator(`input[type="checkbox"][name="creator[]"][value="${name}"]`).isChecked());

  // Views should increment on play, not on page load.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  const href = await page.locator('.video-card').first().getAttribute('href');
  const videoId = new URL(href, BASE).searchParams.get('id');

  await page.goto(`${BASE}/video.php?id=${videoId}`, { waitUntil: 'networkidle' });
  const before = Number(await page.locator('#view-count').innerText());
  await page.reload({ waitUntil: 'networkidle' });
  check(`opening the page does not count a view (${before})`,
    Number(await page.locator('#view-count').innerText()) === before);

  // Chromium here has no H.264 decoder, so `playing` never fires — post the
  // same request the player would to prove the endpoint and the count work.
  const response = await page.request.post(`${BASE}/video_view.php`, { form: { id: videoId } });
  const after = (await response.json()).views;
  check(`playing records a view (${before} -> ${after})`, after === before + 1);

  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  check('the wall tile shows the new count',
    new RegExp(`\\b${after} views?\\b`).test(await page.locator('.tile-stats').first().innerText()));

  const bad = await page.request.post(`${BASE}/video_view.php`, { form: { id: '../x' } });
  check(`a non-numeric id is rejected (${bad.status()})`, bad.status() === 400);

  await browser.close();
})();
