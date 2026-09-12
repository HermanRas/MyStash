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

  const id = await page.locator('.video-card').first().getAttribute('href');
  const videoId = new URL(id, BASE).searchParams.get('id');

  // Each creator's video count off the creator grid, keyed by name, so the
  // assertions below don't depend on how many videos the stash happens to hold.
  const videoCounts = async () => Object.fromEntries(
    await page.$$eval('.creator-card', (cards) => cards.map((card) => [
      card.querySelector('.creator-name').innerText.trim(),
      Number((card.querySelector('.creator-meta').innerText.match(/(\d+)\s+video/) || [0, 0])[1]),
    ])),
  );

  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  const beforeCounts = await videoCounts();

  // Credit two creators on the video.
  await page.goto(`${BASE}/video.php?id=${videoId}&edit=1`, { waitUntil: 'networkidle' });
  const boxes = page.locator('input[name="creators[]"]');
  check(`the edit screen offers every creator as a checkbox (${await boxes.count()})`, await boxes.count() >= 2);

  // Capture exactly who was credited, so the restore at the end puts *that*
  // back rather than assuming the video started with a single creator — this
  // is real user data, and unchecking blind would quietly strip a co-credit.
  const original = await page.locator('input[name="creators[]"]:checked')
    .evaluateAll((els) => els.map((el) => el.value));
  const before = original.length;
  console.log(`  video #${videoId} originally credits: ${original.join(', ') || 'none'}`);

  await boxes.nth(1).check();
  await boxes.nth(2).check();
  const credited = await page.locator('input[name="creators[]"]:checked')
    .evaluateAll((els) => els.map((el) => el.value));
  const added = credited.filter((name) => !original.includes(name));
  await page.click('button[type="submit"]:has-text("Save Changes")');
  await page.waitForSelector('.watch-title');

  // Count once — reading the locator twice (label and condition) can catch the
  // page mid-render and report two different numbers.
  const shown = await page.locator('.creator-strip').count();
  check(`the watch page lists every credited creator (${shown} of ${credited.length}, was ${before})`,
    shown === credited.length);
  console.log('  heading:', await page.locator('.section-title').first().innerText());
  await page.screenshot({ path: '/work/screenshots/creators_video.png', fullPage: true });

  // The wall tile should list them all.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  const tile = await page.locator('.tile-creator').first().innerText();
  check(`the wall tile names all of them ("${tile.trim()}")`, tile.includes(',')),
  await page.screenshot({ path: '/work/screenshots/creators_wall.png' });

  // Each creator's video count should include the shared video. Compare each
  // creator against their own count from before the edit — asserting a flat
  // "everyone shows 1 video" only held while the stash had a single video.
  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  const after = await videoCounts();
  console.log('  counts before:', JSON.stringify(beforeCounts));
  console.log('  counts after: ', JSON.stringify(after));

  // Whoever was newly added gains exactly this one video; whoever was already
  // credited is unchanged. How many that is depends on the stash, so it is
  // derived rather than hard-coded.
  const gained = added.filter((name) => after[name] === (beforeCounts[name] ?? 0) + 1);
  check(`each newly credited creator gains the video (${gained.join(', ') || 'none'} of ${added.length})`,
    gained.length === added.length && added.length > 0);
  check('an already-credited creator’s count does not change',
    original.every((name) => after[name] === beforeCounts[name]));
  check('every credited creator now counts it',
    credited.every((name) => after[name] >= 1));
  await page.screenshot({ path: '/work/screenshots/creators_grid.png' });

  // Put the original credits back exactly as they were.
  await page.goto(`${BASE}/video.php?id=${videoId}&edit=1`, { waitUntil: 'networkidle' });
  for (const box of await page.locator('input[name="creators[]"]').all()) {
    await box.setChecked(original.includes(await box.inputValue()));
  }
  await page.click('button[type="submit"]:has-text("Save Changes")');
  await page.waitForSelector('.watch-title');
  check(`the original credits are restored (${original.join(', ')})`,
    (await page.locator('.creator-strip .creator-name').allInnerTexts())
      .map((t) => t.trim()).sort().join(',') === [...original].sort().join(','));

  await browser.close();
})();
