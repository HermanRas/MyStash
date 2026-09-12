const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const pages = ['login.html', 'wall.html', 'video.html', 'creator.html', 'user.html'];

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

  for (const p of pages) {
    await page.goto(`${BASE}/${p}`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: `/work/screenshots/${p.replace('.html', '')}.png`, fullPage: true });
    console.log(`captured ${p}`);
  }

  await browser.close();
})();
