// 4.32 — the management screens sit in the same centred column as the watch
// page, and the convert progress panel leads the watch page rather than
// hiding below the fold.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const PASSWORD = process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 950 } });
  let fails = 0;
  const check = (label, ok) => {
    if (!ok) fails++;
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  };

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', 'TestUser');
  await page.fill('#password', PASSWORD);
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

  // The watch page is the reference the other two are being matched to.
  await page.goto(`${BASE}/video.php?id=${VIDEO_ID}`, { waitUntil: 'networkidle' });
  const reference = await page.locator('.watch-layout').boundingBox();
  console.log(`  watch-layout: x=${Math.round(reference.x)} width=${Math.round(reference.width)}`);

  // The Creators screen only grows a card when a creator is being added or
  // edited; its normal content is the grid. Both are measured against the same
  // column, which is the point of the check.
  const screens = [
    ['Categories', 'category.php', '.card'],
    ['Creators', 'creator.php?edit=', '.card'],
  ];

  for (const [name, url, content] of screens) {
    await page.goto(`${BASE}/${url}`, { waitUntil: 'networkidle' });

    const main = await page.locator('main.manage-layout').boundingBox();
    check(`${name} uses the same column as the watch page`,
      Math.abs(main.x - reference.x) < 2 && Math.abs(main.width - reference.width) < 2);

    // Centred means equal slack either side.
    const viewport = page.viewportSize().width;
    const left = main.x;
    const right = viewport - (main.x + main.width);
    console.log(`  ${name}: left gutter ${Math.round(left)}px, right gutter ${Math.round(right)}px`);
    check(`${name} is centred in the window`, Math.abs(left - right) < 2);

    // The heading must line up with the content it heads.
    const title = await page.locator('.page-title').boundingBox();
    const card = await page.locator(content).first().boundingBox();
    check(`${name}'s heading lines up with its content`, Math.abs(title.x - card.x) < 2);

    // The card filled only 480/560px inside the column before this.
    const inner = main.width - 48; // the column's own 24px padding
    check(`${name}'s card fills the column (${Math.round(card.width)} of ${Math.round(inner)}px)`,
      card.width > inner - 2);

    await page.screenshot({ path: `/work/screenshots/layout_${name.toLowerCase()}.png` });
  }

  // --- the convert panel leads the page ---
  await page.goto(`${BASE}/video.php?id=${VIDEO_ID}`, { waitUntil: 'networkidle' });
  const hasCard = await page.locator('#job-card').count();

  if (hasCard === 0) {
    console.log('SKIP: no conversion running, checking the markup order instead');
    const order = await page.evaluate(() => {
      const html = document.querySelector('.watch-layout > div').innerHTML;
      return { job: html.indexOf('job-card'), title: html.indexOf('watch-title'),
               player: html.indexOf('id="player"') };
    });
    // The card is inside a PHP conditional, so with no job running it is not in
    // the DOM at all. What can still be checked is that the title and player
    // are where they were, and the hoist is covered by the running-job case in
    // run_convert_check.sh.
    check('the player still follows the title', order.title < order.player);
  } else {
    const job = await page.locator('#job-card').boundingBox();
    const player = await page.locator('#player').boundingBox();
    check('the convert panel is above the player', job.y < player.y);
    check('the convert panel is visible without scrolling', job.y + job.height < 950);
  }

  await browser.close();
  console.log(fails === 0 ? 'check_layout: all passed' : `check_layout: ${fails} failed`);
  process.exit(fails);
})();
