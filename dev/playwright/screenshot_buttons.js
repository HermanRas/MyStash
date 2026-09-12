// Buttons were sized inline, page by page, so the same class rendered at four
// different heights on one screen. This measures every rendered .btn and holds
// the site to one height, plus checks the watch page is centred rather than
// pinned to the left edge.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

// The one height every button should render at (`.small` is the named
// exception, measured separately).
const HEIGHT = 40;

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);

  // Measure every visible button on the current page: height, font size, and
  // the element tag (an <a class="btn"> and a <button class="btn"> used to
  // disagree on font, which showed up as different widths for the same text).
  const measure = () => page.$$eval('.btn', (els) =>
    els
      .filter((el) => el.offsetParent !== null)
      .map((el) => {
        const box = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        return {
          text: el.innerText.trim().slice(0, 24),
          tag: el.tagName.toLowerCase(),
          height: Math.round(box.height),
          font: style.fontSize,
          family: style.fontFamily.split(',')[0],
          small: el.classList.contains('small'),
          block: el.classList.contains('block'),
        };
      }));

  const report = (label, buttons) => {
    for (const b of buttons) {
      console.log(`    ${b.height}px  ${b.font}  <${b.tag}>  "${b.text}"${b.small ? ' [small]' : ''}${b.block ? ' [block]' : ''}`);
    }
    const regular = buttons.filter((b) => !b.small);
    if (regular.length === 0) {
      console.log(`  (${label}: no visible regular buttons to measure)`);
      return;
    }
    const heights = [...new Set(regular.map((b) => b.height))];
    check(`${label}: all ${regular.length} regular buttons are ${HEIGHT}px (got ${heights.join(', ')})`,
      heights.length === 1 && heights[0] === HEIGHT);
    const fonts = [...new Set(regular.map((b) => `${b.font}/${b.family}`))];
    check(`${label}: one font across buttons (${fonts.join(' | ')})`, fonts.length === 1);
  };

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  report('login', await measure());

  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  const videoId = await page.getAttribute('.video-card a, a.video-card', 'href')
    .then((href) => (href || '').replace(/.*id=/, '').split('&')[0]);

  // --- Watch page: the four-different-sizes screen the user reported. ---
  await page.goto(`${BASE}/video.php?id=${videoId}`, { waitUntil: 'networkidle' });
  const watch = await measure();
  report('watch', watch);
  await page.screenshot({ path: '/work/screenshots/buttons_watch.png', fullPage: true });

  // Buttons sharing a row should sit on one baseline, not step up and down.
  const tops = await page.$$eval('.form-actions .btn', (els) =>
    els.map((el) => Math.round(el.getBoundingClientRect().top)));
  check(`watch action row shares one baseline (${tops.join(', ')})`,
    new Set(tops).size === 1);

  // --- Centred layout ---
  const gaps = await page.$eval('.watch-layout', (el) => {
    const box = el.getBoundingClientRect();
    return { left: Math.round(box.left), right: Math.round(window.innerWidth - box.right) };
  });
  check(`watch page is centred, not left-pinned (left ${gaps.left}px / right ${gaps.right}px)`,
    Math.abs(gaps.left - gaps.right) <= 2 && gaps.left > 0);

  // --- Edit view: four buttons, previously four sizes. ---
  await page.goto(`${BASE}/video.php?id=${videoId}&edit=1`, { waitUntil: 'networkidle' });
  const edit = await measure();
  report('watch/edit', edit);
  check(`edit view has several buttons to compare (${edit.length})`, edit.length >= 4);
  await page.screenshot({ path: '/work/screenshots/buttons_edit.png', fullPage: true });

  for (const [label, url] of [
    ['creators', `${BASE}/creator.php?edit=default`],
    ['categories', `${BASE}/category.php`],
  ]) {
    await page.goto(url, { waitUntil: 'networkidle' });
    report(label, await measure());
  }

  // The wall's only button lives in the upload panel, which starts collapsed.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  await page.click('#upload-toggle');
  await page.waitForSelector('#upload-panel .btn', { state: 'visible' });
  report('wall/upload', await measure());
  await page.screenshot({ path: '/work/screenshots/buttons_wall.png' });

  // The .small exception should be deliberately smaller, and consistent.
  await page.goto(`${BASE}/category.php`, { waitUntil: 'networkidle' });
  const small = (await measure()).filter((b) => b.small);
  const smallHeights = [...new Set(small.map((b) => b.height))];
  check(`.small buttons share one smaller height (${smallHeights.join(', ')}px)`,
    smallHeights.length === 1 && smallHeights[0] < HEIGHT);
  await page.screenshot({ path: '/work/screenshots/buttons_categories.png', fullPage: true });

  await browser.close();
})();
