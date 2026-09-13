// 4.11 creator view counts + 5.2 the wall's creator age/gender filters.
//
// Read-only. It never plays a video (which would count a view), never edits a
// creator and never uploads: the expected numbers are derived from what the
// wall itself shows, so the test cannot drift against the real stash.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  let failures = 0;
  const check = (label, ok) => {
    if (!ok) failures++;
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  };

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  // --- What the wall says, which is the source of truth for both features. ---
  const tiles = await page.$$eval('.video-card', (cards) => cards.map((card) => ({
    title: card.querySelector('.tile-title').innerText.trim(),
    creators: card.querySelector('.tile-creator').innerText.split(',').map((s) => s.trim()),
    views: Number((card.querySelector('.tile-stats').innerText.match(/(\d+)\s+views?/) || [0, 0])[1]),
  })));
  console.log(`  ${tiles.length} videos on the wall`);

  // --- 4.11: the creator grid's view count is the sum of their videos'. ---
  await page.goto(`${BASE}/creator.php`, { waitUntil: 'networkidle' });
  const cards = await page.$$eval('.creator-card', (els) => els.map((el) => {
    const meta = el.querySelector('.creator-meta').innerText;
    return {
      name: el.querySelector('.creator-name').innerText.trim(),
      videos: Number((meta.match(/(\d+)\s+videos?/) || [0, 0])[1]),
      views: Number((meta.match(/(\d+)\s+views?/) || [0, 0])[1]),
    };
  }));

  check('every creator card shows a view count', cards.length > 0
    && (await page.$$eval('.creator-card .creator-meta', (els) =>
      els.every((e) => /\d+\s+views?/.test(e.innerText)))));

  for (const card of cards) {
    const mine = tiles.filter((t) => t.creators.includes(card.name));
    const expected = mine.reduce((sum, t) => sum + t.views, 0);
    console.log(`  ${card.name}: ${card.videos} videos, ${card.views} views (wall says ${mine.length} / ${expected})`);
    check(`${card.name}'s view count is the sum of their videos (${card.views})`,
      card.views === expected && card.videos === mine.length);
  }

  // A co-credited video's views must count in full for each creator, not be
  // split between them — so the totals across creators can exceed the wall's.
  const shared = tiles.find((t) => t.creators.length > 1 && t.views > 0);
  if (shared) {
    for (const name of shared.creators) {
      const card = cards.find((c) => c.name === name);
      check(`a co-credited video's views count in full for ${name}`,
        card !== undefined && card.views >= shared.views);
    }
  } else {
    console.log('  (no watched co-credited video in this stash to check the full-count rule against)');
  }

  // The views sort is new alongside the count.
  await page.goto(`${BASE}/creator.php?sort=views_desc`, { waitUntil: 'networkidle' });
  const sorted = await page.$$eval('.creator-card .creator-meta', (els) =>
    els.map((e) => Number((e.innerText.match(/(\d+)\s+views?/) || [0, 0])[1])));
  check(`creators sort by views, max→min (${sorted.join(' ')})`,
    sorted.every((v, i) => i === 0 || sorted[i - 1] >= v));
  check('the views sort is offered in the menu',
    await page.locator('.sort-form select option[value="views_desc"]').count() === 1);

  // --- 5.2: the age and gender filters actually filter. ---
  // Expectations come from the creators' own records, read off the edit form,
  // so this works whatever ages and genders the stash happens to hold.
  const people = {};
  for (const card of cards) {
    await page.goto(`${BASE}/creator.php?edit=${encodeURIComponent(card.name)}`, { waitUntil: 'networkidle' });
    people[card.name] = {
      age: Number(await page.inputValue('#c-age')) || null,
      gender: (await page.inputValue('#c-gender')).trim(),
    };
  }
  console.log('  creators: ' + Object.entries(people)
    .map(([n, p]) => `${n} (${p.age ?? '?'}, ${p.gender || '?'})`).join(' | '));

  const titlesOn = async (queryString) => {
    await page.goto(`${BASE}/wall.php?${queryString}`, { waitUntil: 'networkidle' });
    return page.$$eval('.video-card .tile-title', (els) => els.map((e) => e.innerText.trim()));
  };

  // A video matches if ANY of its creators does — the rule co-credited videos
  // depend on.
  const expectTitles = (matches) => tiles
    .filter((t) => t.creators.some((name) => people[name] && matches(people[name])))
    .map((t) => t.title);

  const same = (a, b) => a.length === b.length && [...a].sort().join('|') === [...b].sort().join('|');

  const genders = [...new Set(Object.values(people).map((p) => p.gender).filter(Boolean))];
  for (const gender of genders) {
    const got = await titlesOn(`gender=${encodeURIComponent(gender)}`);
    const want = expectTitles((p) => p.gender.toLowerCase() === gender.toLowerCase());
    check(`gender "${gender}" shows ${want.length} video(s), got ${got.length}`, same(got, want));
  }

  check('the gender menu offers the genders actually in use',
    (await page.$$eval('#gender option', (els) => els.map((e) => e.value).filter(Boolean).sort()))
      .join('|') === [...genders].sort().join('|'));

  const ages = Object.values(people).map((p) => p.age).filter((a) => a !== null);
  if (ages.length > 0) {
    const oldest = Math.max(...ages);
    const youngest = Math.min(...ages);

    let got = await titlesOn(`age_min=${oldest}`);
    check(`age at least ${oldest} shows only the oldest creator's videos (${got.length})`,
      same(got, expectTitles((p) => p.age !== null && p.age >= oldest)));

    got = await titlesOn(`age_max=${youngest}`);
    check(`age at most ${youngest} shows only the youngest creator's videos (${got.length})`,
      same(got, expectTitles((p) => p.age !== null && p.age <= youngest)));

    // Nobody can be older than the oldest, so this must empty the wall — proof
    // the filter is applied rather than ignored.
    got = await titlesOn(`age_min=${Math.min(80, oldest + 1)}`);
    check(`no video survives an age floor above everyone (${got.length} shown)`, got.length === 0);

    got = await titlesOn(`age_min=18&age_max=80`);
    check(`an untouched age slider shows the whole wall (${got.length})`, got.length === tiles.length);
  }

  // The filter has to survive the round trip, or it silently resets on the next
  // interaction with any other control.
  await page.goto(`${BASE}/wall.php?gender=${encodeURIComponent(genders[0] || '')}&age_min=20`, { waitUntil: 'networkidle' });
  check('the age filter comes back on the slider',
    await page.inputValue('#age-min') === '20');
  check('the Creators accordion re-opens when an attribute filter is active',
    await page.locator('details.filter-section:has(#age-min)').evaluate((el) => el.open));
  check('the count line says the wall is filtered',
    (await page.locator('.wall-count').innerText()).toLowerCase().includes('filter'));
  check('the sort form carries the age filter through',
    await page.locator('.sort-form input[name="age_min"]').inputValue() === '20');
  await page.screenshot({ path: '/work/screenshots/creator_filters.png' });

  console.log(failures === 0 ? 'check_creator_filters: all passed' : `check_creator_filters: ${failures} failed`);
  await browser.close();
  process.exit(failures === 0 ? 0 : 1);
})();
