// 5.8 / 5.9 — creator search and sort. Search is the site’s single header box,
// which targets this page while you are on it.
// Read-only: nothing is uploaded, edited or counted.
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

  // The sort menu and the search box both submit their form, so every such
  // action has to be paired with the navigation it causes — otherwise the next
  // read can land mid-navigation and blow up on a destroyed context.
  const submitting = (action) => Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    action(),
  ]);

  const chooseSort = (value) => submitting(async () => {
    await page.hover('.sort-menu');
    await page.click(`.sort-menu-dropdown a[href*="sort=${value}"]`);
  });
  const searchFor = (term) => submitting(async () => {
    await page.fill('.search-bar input[name="q"]', term);
    await page.press('.search-bar input[name="q"]', 'Enter');
  });

  const names = () => page.$$eval('.creator-card .creator-name', (els) => els.map((e) => e.innerText.trim()));
  const counts = () => page.$$eval('.creator-card .creator-meta', (els) =>
    els.map((e) => Number((e.innerText.match(/(\d+)\s+video/) || [0, 0])[1])));

  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  const all = await names();
  console.log(`  creators: ${all.join(' | ')}`);
  check(`the stash has several creators to sort (${all.length})`, all.length >= 2);

  check('there is exactly one search box', await page.locator('input[name="q"]:visible').count() === 1);
  check('it targets the Creators page', (await page.getAttribute('.search-bar', 'action') || '').includes('creator.php'));
  check('the page has a sort menu', await page.locator('.sort-menu-dropdown').count() === 1);

  // Default order is name A→Z.
  check(`the default order is name A→Z (${all.join(', ')})`,
    JSON.stringify(all) === JSON.stringify([...all].sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()))));

  // --- 5.8 search ---
  const target = all.find((n) => n !== 'default') || all[0];
  const term = target.split(/[\s.]+/)[0];
  await searchFor(term);

  const hits = await names();
  console.log(`  "${term}" -> ${hits.join(' | ') || 'none'}`);
  check(`searching a name finds that creator ("${term}")`, hits.includes(target));
  check('searching narrows the grid', hits.length < all.length);
  check('the search reaches the URL', page.url().includes('q='));
  check('the box still shows the term', await page.inputValue('.search-bar input[name="q"]') === term);
  check('the count line names the term',
    (await page.locator('.wall-count').innerText()).includes(term));
  await page.screenshot({ path: '/work/screenshots/creator_search.png' });

  // Opening a creator from a filtered grid keeps the search.
  await submitting(() => page.click('.creator-card'));
  check('opening a creator keeps the search',
    page.url().includes('q=') && await page.inputValue('.search-bar input[name="q"]') === term);

  // No match: a message and a way back.
  await page.goto(`${BASE}/creator.php?q=zzzznotaperson`, { waitUntil: 'networkidle' });
  check('no matches shows an empty grid', (await names()).length === 0);
  check('the empty state explains',
    (await page.locator('.hint').first().innerText()).toLowerCase().includes('no creator matches'));

  await submitting(() => page.click('.search-clear'));
  check('clearing the search restores every creator', (await names()).length === all.length);

  // --- 5.9 sort ---
  await chooseSort('name_desc');
  const desc = await names();
  console.log(`  name Z→A: ${desc.join(', ')}`);
  check('name Z→A is the exact reverse of A→Z',
    JSON.stringify(desc) === JSON.stringify([...all].reverse()));

  await chooseSort('videos_desc');
  const byVideos = await counts();
  console.log(`  videos max→min: ${(await names()).map((n, i) => `${n}=${byVideos[i]}`).join(', ')}`);
  check('videos max→min is non-increasing',
    byVideos.every((v, i) => i === 0 || byVideos[i - 1] >= v));

  await chooseSort('videos_asc');
  const byVideosAsc = await counts();
  check('videos min→max is non-decreasing',
    byVideosAsc.every((v, i) => i === 0 || byVideosAsc[i - 1] <= v));

  // Ages only appear on the card when one is set; creators without one sort
  // last in both directions, so only the leading run of known ages is ordered.
  const ages = () => page.$$eval('.creator-card .creator-meta', (els) => els.map((e) => {
    const m = e.innerText.match(/Age\s+(\d+)/);
    return m ? Number(m[1]) : null;
  }));

  await chooseSort('age_asc');
  const young = await ages();
  console.log(`  age young→old: ${(await names()).map((n, i) => `${n}=${young[i] ?? '-'}`).join(', ')}`);
  check('age young→old is non-decreasing over the known ages',
    young.filter((a) => a !== null).every((a, i, xs) => i === 0 || xs[i - 1] <= a));
  check('creators with no age sort last',
    young.indexOf(null) === -1 || !young.slice(young.indexOf(null)).some((a) => a !== null));

  await chooseSort('age_desc');
  const old = await ages();
  console.log(`  age old→young: ${(await names()).map((n, i) => `${n}=${old[i] ?? '-'}`).join(', ')}`);
  check('age old→young is non-increasing over the known ages',
    old.filter((a) => a !== null).every((a, i, xs) => i === 0 || xs[i - 1] >= a));
  await page.screenshot({ path: '/work/screenshots/creator_sort.png' });

  await chooseSort('age_asc');

  // Sort and search have to survive each other.
  await searchFor(term);
  check('searching keeps the chosen sort',
    (await page.locator('.sort-menu-dropdown a.active').getAttribute('href')).includes('sort=age_asc'));
  check('the sort menu keeps the search',
    page.url().includes('q='));

  await browser.close();
})();
