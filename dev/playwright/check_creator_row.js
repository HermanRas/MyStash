// Several creators on one video should share a row, not stack with the space
// beside them empty.
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

  // Pick a video that actually credits several creators — the tile lists them
  // comma-separated — rather than whichever happens to sort first.
  const multi = await page.$$eval('.video-card', (cards) => cards
    .filter((card) => (card.querySelector('.tile-creator')?.innerText || '').includes(','))
    .map((card) => card.getAttribute('href')));

  if (multi.length === 0) {
    console.log('SKIP: no video in this stash credits more than one creator');
    await browser.close();
    return;
  }

  const id = multi[0].replace(/.*id=/, '').split('&')[0];

  await page.goto(`${BASE}/video.php?id=${id}`, { waitUntil: 'networkidle' });
  const strips = await page.$$eval('.creator-strip', (els) =>
    els.map((el) => {
      const b = el.getBoundingClientRect();
      return { name: el.innerText.split('\n')[0], top: Math.round(b.top), left: Math.round(b.left), width: Math.round(b.width) };
    }));
  console.log('  creators:', JSON.stringify(strips));
  check(`the page credits more than one creator (${strips.length})`, strips.length >= 2);
  if (strips.length >= 2) {
    check('the first two share a row (same top, different left)',
      strips[0].top === strips[1].top && strips[0].left !== strips[1].left);
  }
  await page.screenshot({ path: '/work/screenshots/creator_row.png' });
  await browser.close();
})();
