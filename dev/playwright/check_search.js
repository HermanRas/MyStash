// 5.1 — the header search box, which sat on every page connected to nothing.
// Drives the real box against the real stash. Read-only: no uploads, no view
// counting, nothing written.
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

  const titles = () => page.$$eval('.tile-title', (els) => els.map((e) => e.innerText.trim()));
  const all = await titles();
  console.log(`  stash holds ${all.length}: ${all.join(' | ')}`);

  // The box has to be a real form, or typing into it does nothing.
  check('the search box is a form that targets the wall',
    (await page.getAttribute('.search-bar', 'action') || '').includes('wall.php'));
  check('the input is named q', await page.getAttribute('.search-bar input[name="q"]', 'name') === 'q');

  // Search for a word from the first video's title.
  const word = all[0].split(/\s+/).find((w) => w.length > 3) || all[0];
  await page.fill('.search-bar input[name="q"]', word);
  await page.press('.search-bar input[name="q"]', 'Enter');
  await page.waitForLoadState('networkidle');

  const hits = await titles();
  console.log(`  "${word}" -> ${hits.length}: ${hits.join(' | ')}`);
  check(`searching a title word finds that video ("${word}")`, hits.includes(all[0]));
  check('the search reaches the URL', page.url().includes('q='));
  check('the box still shows the term after the results load',
    await page.inputValue('.search-bar input[name="q"]') === word);
  check('the count line names the term',
    (await page.locator('.wall-count').innerText()).includes(word));
  await page.screenshot({ path: '/work/screenshots/search_hit.png' });

  // Searching from another page should land on the wall. The Creators screen
  // is the deliberate exception — there the same box searches creators in
  // place, which check_creator_search.js covers.
  await page.goto(`${BASE}/category.php`, { waitUntil: 'networkidle' });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    (async () => {
      await page.fill('.search-bar input[name="q"]', word);
      await page.press('.search-bar input[name="q"]', 'Enter');
    })(),
  ]);
  check('searching from the Categories page lands on the wall',
    page.url().includes('wall.php') && page.url().includes('q='));

  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  check('on the Creators page the one box searches creators instead',
    (await page.getAttribute('.search-bar', 'action') || '').includes('creator.php'));

  // A creator name should find their videos.
  const creator = await page.$$eval('.tile-creator', (els) => els.map((e) => e.innerText.trim()));
  if (creator.length) {
    const name = creator[0].split(',')[0].trim();
    await page.goto(`${BASE}/wall.php?q=${encodeURIComponent(name)}`, { waitUntil: 'networkidle' });
    const byCreator = await titles();
    console.log(`  creator "${name}" -> ${byCreator.length}`);
    check(`searching a creator name returns their videos ("${name}")`, byCreator.length >= 1);
  }

  // A category tag should find its videos. Take one off a tile rather than off
  // the manage screen, which also lists categories no video carries — searching
  // for one of those correctly returns nothing.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  const stats = await page.$$eval('.tile-stats', (els) => els.map((e) => e.innerText));
  const cat = stats
    .map((s) => s.split('•').slice(2).join('•').trim())
    .filter(Boolean)
    .flatMap((s) => s.split(',').map((c) => c.trim()))
    .filter(Boolean)[0];
  if (cat) {
    await page.goto(`${BASE}/wall.php?q=${encodeURIComponent(cat)}`, { waitUntil: 'networkidle' });
    console.log(`  category "${cat}" -> ${(await titles()).length}`);
    check(`searching a category tag returns its videos ("${cat}")`, (await titles()).length >= 1);
  }

  // No match: a clear message and a way back, not an empty screen.
  await page.goto(`${BASE}/wall.php?q=zzzznotathing`, { waitUntil: 'networkidle' });
  check('no matches shows the empty state', (await titles()).length === 0);
  check('the empty state explains and offers a way back',
    (await page.locator('.hint').innerText()).toLowerCase().includes('nothing matches'));
  await page.screenshot({ path: '/work/screenshots/search_empty.png' });

  // The clear affordance returns the whole wall.
  await page.click('.search-clear');
  await page.waitForLoadState('networkidle');
  check('clearing the search restores every video', (await titles()).length === all.length);

  // Search must survive narrowing and re-sorting, or the composition is a lie.
  await page.goto(`${BASE}/wall.php?q=${encodeURIComponent(word)}`, { waitUntil: 'networkidle' });
  await page.selectOption('#sort', 'title_asc');
  await page.waitForLoadState('networkidle');
  check('changing the sort keeps the search',
    page.url().includes('q=') && await page.inputValue('.search-bar input[name="q"]') === word);

  const box = page.locator('.filter-panel input[type="checkbox"]').first();
  if (await box.count()) {
    await box.check();
    await page.waitForLoadState('networkidle');
    check('ticking a filter keeps the search',
      page.url().includes('q=') && await page.inputValue('.search-bar input[name="q"]') === word);
  }

  await browser.close();
})();
