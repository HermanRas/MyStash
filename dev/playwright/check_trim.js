// Trim, from Video Stats: the form's three modes, and the trimmed copy's
// Play / Keep / Delete.
//
// Expects a throwaway stash where video 1 already has a trimmed copy and
// video 2 does not (see the trim e2e steps in the commit that added this).
// Read-only: it presses nothing that changes the stash.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const USER = process.env.PROBE_USER;
const PASS = process.env.PROBE_PASS;

let failed = 0;
const check = (label, ok) => {
  console.log(`${ok ? '[PASS]' : '[FAIL]'} ${label}`);
  if (!ok) failed++;
};

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/login.html`);
  await page.fill('#username', USER);
  await page.fill('#password', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

  // --- Video Stats ---
  await page.goto(`${BASE}/stats.php`, { waitUntil: 'networkidle' });
  const row1 = page.locator('#video-1');
  const row2 = page.locator('#video-2');
  check('a video with a trimmed copy offers Play, Keep and Delete',
    await row1.locator('text=Play').count() === 1
    && await row1.locator('button:has-text("Keep")').count() === 1
    && await row1.locator('button:has-text("Delete")').count() === 1);
  check('...and no Trim button', await row1.locator('a:has-text("Trim")').count() === 0);
  check('a video without one offers Trim', await row2.locator('a:has-text("Trim")').count() === 1);
  await page.screenshot({ path: '/work/screenshots/trim_stats.png', fullPage: true });

  // --- the form ---
  await page.goto(`${BASE}/trim.php?id=2`, { waitUntil: 'networkidle' });
  const visible = async () => page.locator('.trim-range:not([hidden])').getAttribute('data-mode');

  check('Start is the default and the only row shown', await visible() === 'start'
    && await page.locator('.trim-range:not([hidden])').count() === 1);
  await page.fill('input[name="start_to"]', '723029');
  check('the typed milliseconds read back as 12m3s29ms',
    (await page.locator('.trim-range[data-mode="start"] output').textContent()) === '12m3s29ms');
  await page.screenshot({ path: '/work/screenshots/trim_form_start.png', fullPage: true });

  await page.selectOption('#trim-mode', 'end');
  check('End shows the End row', await visible() === 'end');
  check('...and the hidden rows are disabled so they cannot block the submit',
    await page.locator('input[name="start_to"]').isDisabled());

  await page.selectOption('#trim-mode', 'cut');
  await page.fill('input[name="cut_from"]', '5000');
  await page.fill('input[name="cut_to"]', '10000');
  check('Cut shows both ends', (await page.locator('.trim-range[data-mode="cut"] output').textContent()) === '5s0ms to 10s0ms');
  await page.screenshot({ path: '/work/screenshots/trim_form_cut.png', fullPage: true });

  // A server-side refusal comes back onto the form.
  await page.goto(`${BASE}/trim.php?id=2&error=${encodeURIComponent('A cut has to end after it starts.')}`);
  check('a refusal is shown on the form', await page.locator('.notice.bad').count() === 1);

  // --- the trimmed copy ---
  await page.goto(`${BASE}/trim.php?id=1`, { waitUntil: 'networkidle' });
  // Not played here: the copy is H.265, and Playwright's Chromium is built
  // without it, so the element errors whatever the bytes are. What can be
  // checked is that it points at the copy and the copy is served.
  const src = await page.locator('.trim-player').getAttribute('src');
  check('the player points at the trimmed copy', src === 'media.php?id=1&type=trim');
  const served = await page.request.get(`${BASE}/${src}`);
  check(`...which is served as video (${served.status()} ${served.headers()['content-type']}, ${(await served.body()).length} bytes)`,
    served.status() === 200 && served.headers()['content-type'] === 'video/mp4');
  check('it offers Keep and Delete', await page.locator('.trim-actions button').count() === 2);
  await page.screenshot({ path: '/work/screenshots/trim_copy.png', fullPage: true });

  // --- phone width: nothing in the page runs off the side ---
  // Measured inside <main> only. The shared site header overflows a phone on
  // every page (search bar and user menu), which is not this page's to fix.
  await page.setViewportSize({ width: 390, height: 844 });
  for (const url of ['trim.php?id=2', 'trim.php?id=1']) {
    await page.goto(`${BASE}/${url}`, { waitUntil: 'networkidle' });
    const over = await page.evaluate(() => [...document.querySelectorAll('main *')]
      .filter((el) => el.getBoundingClientRect().right > window.innerWidth + 1)
      .map((el) => el.tagName.toLowerCase() + (el.className ? '.' + el.className : '')));
    check(`${url} content fits a phone${over.length ? ' (' + over.join(', ') + ')' : ''}`, over.length === 0);
  }
  await page.screenshot({ path: '/work/screenshots/trim_copy_phone.png', fullPage: true });

  await browser.close();
  console.log(failed ? `${failed} check(s) failed` : 'All trim checks passed.');
  process.exit(failed ? 1 : 0);
})();
