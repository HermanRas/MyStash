// 3.9 — setting and changing a video's preview image, through the real forms.
//
// Runs against a throwaway stash: this rewrites the preview archive, which is
// not something to do to someone's stash to prove a point.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const USER = process.env.PROBE_USER;
const PASS = process.env.PROBE_PASS;

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  let fails = 0;
  const check = (label, ok) => {
    if (!ok) fails++;
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);
  };

  await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
  await page.fill('#username', USER);
  await page.fill('#password', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

  // The bytes the wall is showing now, to prove later that they changed.
  const thumbBytes = async () => {
    const res = await page.request.get(`${BASE}/media.php?id=1&type=thumb`);
    return (await res.body()).length;
  };
  const before = await thumbBytes();
  console.log(`  preview is ${before} bytes to start with`);

  await page.goto(`${BASE}/video.php?id=1&edit=1`, { waitUntil: 'networkidle' });
  check('the edit screen has a preview card', await page.locator('.preview-edit').count() === 1);
  check('it shows the current preview', await page.locator('.preview-current').count() === 1);
  check('it offers a timestamp capture', await page.locator('#preview-at').count() === 1);
  check('it offers an image upload', await page.locator('#preview-file').count() === 1);
  await page.screenshot({ path: '/work/screenshots/preview_edit.png', fullPage: true });

  // --- take a frame from a different point ---
  await page.fill('#preview-at', '7');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('.preview-row:has(#preview-at) button[type="submit"]'),
  ]);
  check(`it reports the new capture point (${page.url().split('preview=')[1] || ''})`,
    page.url().includes('preview='));
  check('the card says where the preview now comes from',
    (await page.locator('.preview-forms .hint').first().textContent()).includes('0:07'));

  const afterCapture = await thumbBytes();
  console.log(`  preview is ${afterCapture} bytes after the capture`);
  check('the served thumbnail actually changed', afterCapture !== before);

  // --- supply a picture instead ---
  await page.setInputFiles('#preview-file', '/work/avatar_fixture.png');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('.preview-row:has(#preview-file) button[type="submit"]'),
  ]);
  check('an uploaded picture is accepted', page.url().includes('preview='));
  check('the card stops claiming a timestamp',
    (await page.locator('.preview-forms .hint').first().textContent()).includes('supplied'));

  const afterUpload = await thumbBytes();
  console.log(`  preview is ${afterUpload} bytes after the upload`);
  check('the served thumbnail changed again', afterUpload !== afterCapture);

  // It must still be a JPEG: media.php serves this as image/jpeg.
  const res = await page.request.get(`${BASE}/media.php?id=1&type=thumb`);
  const body = await res.body();
  check(`it is still served as JPEG (${res.headers()['content-type']})`,
    res.headers()['content-type'] === 'image/jpeg');
  check('...and the bytes really are a JPEG', body[0] === 0xff && body[1] === 0xd8);

  // --- a refusal must leave the picture alone ---
  await page.setInputFiles('#preview-file', {
    name: 'notreally.png', mimeType: 'image/png', buffer: Buffer.from('not an image at all'),
  });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('.preview-row:has(#preview-file) button[type="submit"]'),
  ]);
  check('a file that is not an image is refused', page.url().includes('preview_error='));
  check('the refusal is explained on the page',
    await page.locator('.card p[style*="ff6b6b"]').count() > 0);
  check('the previous preview survived the refusal', (await thumbBytes()) === afterUpload);

  await page.screenshot({ path: '/work/screenshots/preview_refused.png', fullPage: true });
  await browser.close();
  console.log(fails === 0 ? 'check_preview: all passed' : `check_preview: ${fails} failed`);
  process.exit(fails);
})();
