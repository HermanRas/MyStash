const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg');
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');

  // Which video to open is discovered from the wall, never hardcoded.
  // TestUser is a working stash: its videos get deleted and re-uploaded, so
  // ids do not stay put — the demo entries 1-6 were replaced by 7-9 and every
  // check that assumed `id=1` started timing out on a page that redirects.
  const VIDEO_ID = await page.$eval(
    'a[href*="video.php?id="]',
    (a) => new URL(a.href, location.origin).searchParams.get('id'),
  );
  console.log(`  using video id ${VIDEO_ID} from the wall`);

  await page.goto(`${BASE}/creator.php?edit=Alex%20R.`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/creator_edit.png', fullPage: true });
  console.log('captured creator_edit.png');

  await page.goto(`${BASE}/video.php?id=${VIDEO_ID}`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/video_view.png', fullPage: true });
  console.log('captured video_view.png');

  await page.goto(`${BASE}/video.php?id=${VIDEO_ID}&edit=1`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: '/work/screenshots/video_edit.png', fullPage: true });
  console.log('captured video_edit.png');

  await browser.close();
})();
