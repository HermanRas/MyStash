// 5.4 — the parts of playlists that only exist in a browser.
//
// dev/run_playlist_check.sh proves the endpoints and what they store. This
// proves the two things no amount of curl can: that a row can actually be
// dragged to a new position, and that the resulting order is saved without
// the page being submitted.
//
// Driven by run_playlist_check.sh's environment, or standalone with
// PROBE_USER / PROBE_PASSWORD set.
const { chromium } = require('playwright');

const BASE = 'http://app:8080';
let fails = 0;

const check = (label, ok) => {
  console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  if (!ok) fails += 1;
};

(async () => {
  const user = process.env.PROBE_USER;
  const password = process.env.PROBE_PASSWORD;

  if (!user || !password) {
    console.log('FAIL: PROBE_USER / PROBE_PASSWORD must be set');
    process.exit(1);
  }

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });

  await page.goto(`${BASE}/login.html`);
  await page.fill('#username', user);
  await page.fill('#password', password);
  await page.click('button[type=submit]');
  await page.waitForURL('**/wall.php');

  // --- create a playlist and fill it from the modal --------------------
  await page.goto(`${BASE}/playlist.php`);
  await page.fill('#playlist-name', 'Drag Test');
  await page.click('button:has-text("Create")');
  await page.waitForURL('**/playlist.php?id=*');

  await page.click('#add-videos-open');
  await page.waitForTimeout(200);

  const selectable = await page.$$('#add-videos-list .modal-row input:not([disabled])');
  check('the add-videos modal offers the stash\'s videos', selectable.length >= 3);

  // The search is the same three fields VideoQuery matches on, so a title
  // must narrow the list and a nonsense term must empty it.
  await page.fill('#add-videos-search', 'Gamma');
  await page.waitForTimeout(150);
  const narrowed = await page.$$eval('#add-videos-list .modal-row', (r) => r.filter((x) => !x.hidden).length);
  check(`searching a title narrows the modal (${narrowed} shown)`, narrowed === 1);

  await page.fill('#add-videos-search', 'zzzz-no-such-thing');
  await page.waitForTimeout(150);
  const none = await page.$$eval('#add-videos-list .modal-row', (r) => r.filter((x) => !x.hidden).length);
  check('a search that matches nothing empties the modal', none === 0);
  check('...and says so', await page.$eval('#add-videos-empty', (e) => !e.hidden));

  await page.fill('#add-videos-search', '');
  await page.waitForTimeout(150);
  for (const box of (await page.$$('#add-videos-list .modal-row input:not([disabled])')).slice(0, 3)) {
    await box.check();
  }
  await page.click('button:has-text("Add selected")');
  await page.waitForURL('**/playlist.php?id=*');

  const before = await page.$$eval('.playlist-row', (rows) => rows.map((r) => r.dataset.id));
  console.log(`  order before the drag: ${before.join(', ')}`);
  check('the modal added three videos', before.length === 3);

  // --- the drag --------------------------------------------------------
  // Last row onto the first, by its handle. Native HTML5 drag and drop, which
  // is what the page actually uses — not a synthesised reorder.
  await page.dragAndDrop('.playlist-row:last-child .playlist-grip', '.playlist-row:first-child');
  await page.waitForTimeout(300);

  const after = await page.$$eval('.playlist-row', (rows) => rows.map((r) => r.dataset.id));
  console.log(`  order after the drag:  ${after.join(', ')}`);
  check('dragging a row moves it', after.join() !== before.join());
  check('...without losing or duplicating any row',
    after.length === before.length && new Set(after).size === after.length);
  check('...and the dragged row is no longer last', after[after.length - 1] !== before[before.length - 1]);

  // The save is a fetch, so wait for it to land rather than guessing at a
  // delay — a fixed wait here reported "Saving…" as a failure once already.
  const saved = await page
    .waitForFunction(() => (document.getElementById('playlist-status').textContent || '').includes('saved'),
      null, { timeout: 5000 })
    .then(() => true)
    .catch(() => false);
  check(`the page reports the save (${JSON.stringify(await page.textContent('#playlist-status'))})`, saved);

  // The real assertion: it survives a reload, so it was written to the stash
  // and not merely rearranged in the DOM.
  await page.reload();
  const reloaded = await page.$$eval('.playlist-row', (rows) => rows.map((r) => r.dataset.id));
  console.log(`  order after a reload:  ${reloaded.join(', ')}`);
  check('the new order survives a reload', reloaded.join() === after.join());

  // --- removing a row --------------------------------------------------
  await page.click('.playlist-row:first-child .playlist-remove');
  await page.waitForTimeout(400);
  await page.reload();
  const removed = await page.$$eval('.playlist-row', (rows) => rows.map((r) => r.dataset.id));
  check(`removing a row saves too (${removed.length} left)`, removed.length === after.length - 1);
  check('...and removes the right one', !removed.includes(after[0]));

  // --- the watch page dropdown ----------------------------------------
  const videoId = await page.$eval(
    'a[href*="video.php?id="]',
    (a) => new URL(a.href, location.origin).searchParams.get('id'),
  );
  await page.goto(`${BASE}/video.php?id=${videoId}`);

  const hidden = await page.$eval('.playlist-dropdown', (d) => getComputedStyle(d).display);
  check('the playlist dropdown is closed until it is opened', hidden === 'none');

  await page.$eval('.playlist-menu', (e) => e.scrollIntoView({ block: 'center' }));
  await page.hover('.playlist-menu');
  await page.waitForTimeout(300);
  const shown = await page.$eval('.playlist-dropdown', (d) => getComputedStyle(d).display);
  check('hovering opens it, the way the sort and user menus open', shown === 'block');

  const box = await page.$('.playlist-option input');
  const wasChecked = await box.isChecked();
  await box.click();
  await page.waitForTimeout(400);
  await page.reload();
  await page.$eval('.playlist-menu', (e) => e.scrollIntoView({ block: 'center' }));
  await page.hover('.playlist-menu');
  await page.waitForTimeout(200);
  const nowChecked = await page.$eval('.playlist-option input', (i) => i.checked);
  check(`ticking a playlist box persists across a reload (${wasChecked} -> ${nowChecked})`,
    nowChecked !== wasChecked);

  await page.screenshot({ path: '/work/screenshots/playlist-dropdown.png' });

  await browser.close();
  console.log(fails === 0 ? 'check_playlist: all passed' : `check_playlist: ${fails} failed`);
  process.exit(fails === 0 ? 0 : 1);
})();
