// The display face is served by this app and by nothing else.
//
// Carter One arrived as an @import from fonts.googleapis.com, which meant
// every page load of a private, encrypted, self-hosted stash announced itself
// to Google. The face is now in assets/fonts/. Two things have to hold, and
// the second is the one worth a browser:
//
//  - no page fetches anything from a host that is not this app;
//  - the headings are really being drawn in Carter One, not merely able to be.
//
// `document.fonts.check()` answers the second question about the *font*, not
// about the *element* — it returns true as soon as the face is loaded, whether
// or not a single heading uses it. So the element is measured twice, once as
// it renders and once forced into the fallback, and the widths must differ.
// A stylesheet that had lost its heading layer would still pass the load
// check and fail this one.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const USER = process.env.PROBE_USER || 'TestUser';
const PASSWORD = process.env.PROBE_PASS || process.env.STASH_PASSWORD || 'DS89HONPtufGDncNUoGfshCg';

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 950 } });
  let fails = 0;
  const check = (label, ok) => {
    if (!ok) fails++;
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  };

  // Every request the browser makes, on every page visited below. Recorded
  // from `request`, not `response`: a blocked or failed call to Google is
  // still a call to Google, and it is the leaving that matters.
  const foreign = new Set();
  const appHost = new URL(BASE).host;
  page.on('request', (r) => {
    const host = new URL(r.url()).host;
    if (host && host !== appHost) foreign.add(host);
  });

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });

  // Before any session exists. Nothing on the login screen is set in the
  // display face — its wordmark is an image — so the browser never downloads
  // it here, and `fonts.check()` would honestly answer false. What matters on
  // this page is that the face is *declared*, and declared from this origin:
  // that is the @import's replacement, on the one page a stranger can reach.
  await page.evaluate(() => document.fonts.ready);
  const declared = await page.evaluate(() => {
    for (const face of document.fonts) {
      if (face.family.replace(/["']/g, '') === 'Carter One') return true;
    }
    return false;
  });
  check('the login screen declares the face', declared);

  const widths = async (selector) => page.$eval(selector, (el) => {
    const before = el.getBoundingClientRect().width;
    const was = el.style.fontFamily;
    el.style.fontFamily = "'Trebuchet MS', system-ui, sans-serif";
    const after = el.getBoundingClientRect().width;
    el.style.fontFamily = was;
    return { before, after };
  });

  await page.fill('#username', USER);
  await page.fill('#password', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForSelector('.video-grid');
  await page.evaluate(() => document.fonts.ready);

  check('the face is loaded on the wall', await page.evaluate(
    () => document.fonts.check('16px "Carter One"'),
  ));

  // The wordmark in the header, which is text. `.brand-name` is a span, so
  // its box is the lettering; on a block element both measurements would come
  // back as the column width for a reason that has nothing to do with fonts.
  const brand = await widths('.brand-name');
  console.log(`  .brand-name: ${Math.round(brand.before)}px, fallback ${Math.round(brand.after)}px`);
  check('the wordmark is drawn in Carter One, not merely able to be',
    Math.round(brand.before) !== Math.round(brand.after));

  // Tile titles are deliberately NOT the display face: one weight and that
  // much personality is a logotype, not a paragraph, and titles wrap.
  const tileFont = await page.$eval('.video-grid a h3, .video-grid .tile-title, .video-grid h3',
    (el) => getComputedStyle(el).fontFamily);
  console.log(`  tile title font-family: ${tileFont}`);
  check('tile titles stay on the body face', !/Carter One/.test(tileFont));

  // The wall has no page heading of its own — it leads with the filter bar —
  // so the heading layer is read where headings actually are.
  await page.goto(`${BASE}/user.php`, { waitUntil: 'networkidle' });
  const headingFont = await page.$eval('.page-title', (el) => getComputedStyle(el).fontFamily);
  console.log(`  page heading font-family: ${headingFont}`);
  check('page headings ask for the display face', /Carter One/.test(headingFont));

  // The pages a heading actually lives on, so the request log covers the site
  // rather than the login screen alone.
  for (const path of ['playlist.php', 'creator.php', 'category.php']) {
    await page.goto(`${BASE}/${path}`, { waitUntil: 'networkidle' });
  }

  console.log(`  foreign hosts contacted: ${foreign.size === 0 ? '(none)' : [...foreign].join(', ')}`);
  check('nothing on the site is fetched from another host', foreign.size === 0);

  // Positive control for the check above: the recorder is watching, and would
  // have seen Google had anything asked for it. Without this, a listener that
  // was never wired up passes exactly as well as a clean site.
  await page.goto('data:text/html,<img src="https://fonts.gstatic.com/x.woff2">');
  await page.waitForTimeout(200);
  check('the request recorder really sees foreign hosts', foreign.has('fonts.gstatic.com'));

  await browser.close();
  console.log(fails === 0 ? 'check_font: all passed' : `check_font: ${fails} failed`);
  process.exit(fails === 0 ? 0 : 1);
})();
