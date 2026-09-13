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

  // Which video to open is discovered from the wall, never hardcoded.
  // TestUser is a working stash: its videos get deleted and re-uploaded, so
  // ids do not stay put — the demo entries 1-6 were replaced by 7-9 and every
  // check that assumed `id=1` started timing out on a page that redirects.
  const VIDEO_ID = await page.$eval(
    'a[href*="video.php?id="]',
    (a) => new URL(a.href, location.origin).searchParams.get('id'),
  );
  console.log(`  using video id ${VIDEO_ID} from the wall`);
  check('logs in with the new 24-char password', page.url().includes('wall.php'));

  // --- top nav -----------------------------------------------------------
  const pills = await page.locator('.category-bar .pill').allInnerTexts();
  console.log('nav pills:', JSON.stringify(pills));
  check('nav is exactly All Videos / Creators / Categories',
    JSON.stringify(pills.map(p => p.trim())) === '["All Videos","Creators","Categories"]');

  await page.screenshot({ path: '/work/screenshots/records_wall.png' });

  // --- sort --------------------------------------------------------------
  const titles = async () => (await page.locator('.tile-title').allInnerTexts()).map(t => t.trim());

  // The sort is a hover menu of links now, not a <select> (4.31): hover the
  // trigger to open it, then click the entry, which is an ordinary navigation.
  const sortBy = async (value) => {
    await page.hover('.sort-menu');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click(`.sort-menu-dropdown a[href*="sort=${value}"]`),
    ]);
    await page.waitForSelector('.video-grid');
  };

  await sortBy('title_asc');
  const asc = await titles();
  await sortBy('title_desc');
  const desc = await titles();
  // Ordering can only be observed with more than one video in the stash.
  if (asc.length > 1) {
    check('title A→Z and Z→A are exact reverses',
      JSON.stringify(asc) === JSON.stringify([...desc].reverse()));
  } else {
    console.log(`SKIP: sort ordering (stash holds ${asc.length} video)`);
  }
  console.log('  A→Z first/last:', asc[0], '|', asc[asc.length - 1]);

  await sortBy('length_desc');
  const badges = (await page.locator('.badge.duration').allInnerTexts()).map(t => t.trim());
  console.log('  length long→short:', badges.join(' '));

  await sortBy('views_desc');
  const views = (await page.locator('.tile-stats').allInnerTexts())
    .map(t => Number(t.match(/(\d+) views?/)[1]));
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

  // The Reset Filters button was removed in 4.22 — the "All Videos" pill is
  // the bare wall URL, which is the same thing, so that is what resets now.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('.category-bar .pill:has-text("All Videos")'),
  ]);
  await page.waitForSelector('.video-grid');
  check('All Videos restores every video', (await page.locator('.video-card').count()) > filteredCount);

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

  // --- the header carries the nav, and the search sits after it (4.31) ----
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });

  const order = await page.$$eval('.site-header > *', (els) => els.map((el) => el.className));
  console.log('  header children:', JSON.stringify(order));
  check('the nav is inside the header, before the search',
    order.indexOf('header-left') < order.indexOf('search-bar')
      && order.indexOf('search-bar') < order.indexOf('header-actions'));
  check('there is no separate nav band below the header',
    await page.locator('body > nav.category-bar').count() === 0);

  // Icon-only, with the label kept as the tooltip and the accessible name.
  for (const [id, label] of [['upload-toggle', 'Upload'], ['filters-toggle', 'Filters']]) {
    const button = page.locator(`#${id}`);
    check(`${label} is icon-only but still named (title="${await button.getAttribute('title')}")`,
      (await button.innerText()).trim() === ''
        && await button.getAttribute('title') === label
        && await button.getAttribute('aria-label') === label);
  }

  // --- sort is a menu of links, not a <select> (4.31) --------------------
  check('the sort control is an icon-only trigger with a dropdown',
    await page.locator('.sort-menu .sort-trigger').count() === 1
      && await page.locator('select#sort').count() === 0);

  const sortLinks = await page.locator('.sort-menu-dropdown a').count();
  check(`the dropdown offers every sort (${sortLinks})`, sortLinks === 8);
  check('exactly one entry is marked as the current sort',
    await page.locator('.sort-menu-dropdown a.active').count() === 1);

  // The menu is hidden until hovered, exactly like the user menu.
  check('the dropdown is closed until the trigger is hovered',
    await page.locator('.sort-menu-dropdown').isHidden());
  await page.hover('.sort-menu');
  check('hovering opens it', await page.locator('.sort-menu-dropdown').isVisible());

  // --- favicon -----------------------------------------------------------
  check('the page declares a favicon',
    await page.locator('link[rel="icon"]').count() === 1);

  const favicon = await page.request.get(`${BASE}/favicon.ico`);
  check(`/favicon.ico is served as an image (${favicon.status()} ${favicon.headers()['content-type']})`,
    favicon.ok() && favicon.headers()['content-type'].startsWith('image/'));

  // --- download: the way back out of the stash (4.30) --------------------
  await page.goto(`${BASE}/video.php?id=${VIDEO_ID}`, { waitUntil: 'networkidle' });

  const title = (await page.locator('.watch-title').innerText()).trim();
  const downloadLink = page.locator('.form-actions a[href*="download=1"]');
  check('the watch page offers a download', await downloadLink.count() === 1);

  const saved = await page.request.get(`${BASE}/media.php?id=${VIDEO_ID}&type=video&download=1`);
  const disposition = saved.headers()['content-disposition'] || '';
  console.log('  content-disposition:', disposition);
  check('it is sent as an attachment named after the video',
    disposition.includes('attachment') && disposition.includes(`${title}.mp4`));

  const streamed = await page.request.get(`${BASE}/media.php?id=${VIDEO_ID}&type=video`);
  check('the downloaded bytes are the whole video, same as the player streams',
    (await saved.body()).length === (await streamed.body()).length
      && (await saved.body()).length > 0);
  console.log('  downloaded', (await saved.body()).length, 'bytes');

  // Without the flag it must still play inline, or the player would start
  // prompting to save the file instead of showing it.
  check('the same URL without the flag is still inline',
    !(streamed.headers()['content-disposition'] || '').includes('attachment'));

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
