const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const ID = process.env.VIDEO_ID || '9';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  page.on('console', (msg) => console.log('CONSOLE:', msg.type(), msg.text()));
  page.on('requestfailed', (req) => console.log('REQUEST FAILED:', req.url(), req.failure()?.errorText));
  page.on('response', (res) => {
    if (res.url().includes('media.php')) {
      console.log('RESPONSE:', res.status(), res.url(), res.headers()['content-type']);
    }
  });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', 'testpass123');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  await page.goto(`${BASE}/video.php?id=${ID}`, { waitUntil: 'networkidle' });
  await page.click('#player-play');
  await page.waitForTimeout(2000);

  const state = await page.evaluate(() => {
    const v = document.querySelector('#player video');
    return v ? {
      src: v.src,
      paused: v.paused,
      currentTime: v.currentTime,
      readyState: v.readyState,
      networkState: v.networkState,
      error: v.error ? { code: v.error.code, message: v.error.message } : null,
      canPlayMp4H264: v.canPlayType('video/mp4; codecs="avc1.42E01E"'),
      canPlayMp4Hevc: v.canPlayType('video/mp4; codecs="hvc1"'),
    } : null;
  });
  console.log('STATE:', JSON.stringify(state, null, 2));

  await browser.close();
})();
