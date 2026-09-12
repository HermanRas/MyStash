const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const ID = process.env.VIDEO_ID || '8';

/**
 * Diagnostic: does clicking a category chip create the player and request a
 * seek to that timestamp? Note the Playwright image's Chromium ships without
 * H.264/HEVC decoders, so the media never reaches readyState >= 1 here and the
 * seek stays pending — that part needs a codec-capable browser to observe.
 */
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  page.on('console', (msg) => console.log('CONSOLE:', msg.type(), msg.text()));
  page.on('response', (res) => {
    if (res.url().includes('media.php')) {
      console.log('RESPONSE:', res.status(), res.url().replace(BASE, ''), res.headers()['content-type']);
    }
  });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', 'testpass123');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  await page.goto(`${BASE}/video.php?id=${ID}`, { waitUntil: 'networkidle' });

  const chips = page.locator('.tag-jump');
  console.log('category chips on the page:', await chips.count());

  // Click the last chip (the one with a non-zero timestamp).
  const target = chips.last();
  console.log('clicking chip:', (await target.innerText()).replace(/\s+/g, ' ').trim());
  await target.click();
  await page.waitForTimeout(1500);

  const state = await page.evaluate(() => {
    const v = document.querySelector('#player video');
    return v ? {
      created: true,
      src: v.src.split('/').pop(),
      requestedSeekPending: v.readyState < 1,
      readyState: v.readyState,
      error: v.error ? v.error.message : null,
      canPlayH264: v.canPlayType('video/mp4; codecs="avc1.42E01E"') || '(unsupported)',
    } : { created: false };
  });
  console.log('PLAYER STATE:', JSON.stringify(state, null, 2));

  await browser.close();
})();
