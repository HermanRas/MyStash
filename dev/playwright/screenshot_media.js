const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const ID = process.env.VIDEO_ID || '9';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', 'testpass123');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');
  await page.waitForTimeout(500);
  await page.screenshot({ path: '/work/screenshots/wall_with_thumbs.png', fullPage: true });
  console.log('captured wall_with_thumbs.png');

  const cards = page.locator('.video-card');
  const count = await cards.count();
  let targetCard = null;
  for (let i = 0; i < count; i++) {
    const href = await cards.nth(i).getAttribute('href');
    if (href.includes(`id=${ID}`)) {
      targetCard = cards.nth(i);
      break;
    }
  }

  if (targetCard) {
    await targetCard.hover();
    await page.waitForTimeout(600);
    await page.screenshot({ path: '/work/screenshots/wall_hover_preview.png', fullPage: true });
    console.log('captured wall_hover_preview.png');

    const video = targetCard.locator('.thumb-preview');
    const isPlaying = await video.evaluate((v) => !v.paused && v.currentTime > 0);
    console.log('hover preview clip playing:', isPlaying);
  } else {
    console.log('target card not found for id', ID);
  }

  await page.goto(`${BASE}/video.php?id=${ID}`, { waitUntil: 'networkidle' });
  await page.click('#player-play');
  await page.waitForTimeout(1000);
  await page.screenshot({ path: '/work/screenshots/video_playing.png', fullPage: true });
  const playing = await page.evaluate(() => {
    const v = document.querySelector('#player video');
    return v ? { paused: v.paused, currentTime: v.currentTime, readyState: v.readyState } : null;
  });
  console.log('playback state:', JSON.stringify(playing));

  await browser.close();
})();
