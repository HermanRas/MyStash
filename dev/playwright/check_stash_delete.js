// 7.0.2 — deleting a stash, through the real form on the Profile screen.
//
// Runs entirely against a throwaway stash created for this test. It must never
// be pointed at a real user: what it is testing is that the stash is gone
// afterwards, and there is nothing to restore from.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const USER = process.env.PROBE_USER;
const PASSWORD = process.env.PROBE_PASSWORD;

if (!USER || !PASSWORD) {
  console.log('FAIL: PROBE_USER / PROBE_PASSWORD must be set');
  process.exit(1);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1100 } });
  const check = (label, ok) => console.log(`${ok ? 'PASS' : 'FAIL'}: ${label}`);

  const login = async (password) => {
    await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle' });
    await page.fill('#username', USER);
    await page.fill('#password', password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type="submit"]'),
    ]);
    return page.url();
  };

  check('the throwaway stash logs in', (await login(PASSWORD)).includes('wall.php'));

  await page.goto(`${BASE}/user.php`, { waitUntil: 'networkidle' });
  check('the Profile screen offers to delete the stash',
    await page.locator('#delete-form').count() === 1);
  check('the danger card names the stash it would delete',
    (await page.locator('.danger-card').innerText()).includes(USER));

  // --- the typed-name guard ---------------------------------------------
  const button = page.locator('#delete-submit');
  check('the delete button starts disabled', await button.isDisabled());

  await page.fill('#confirm-name', USER.toLowerCase());
  check('a near-miss on the name does not enable it', await button.isDisabled());

  await page.fill('#confirm-name', USER);
  check('typing the name exactly enables it', await button.isEnabled());

  await page.screenshot({ path: '/work/screenshots/profile_delete.png', fullPage: true });

  // --- refusals, posted directly so the browser guard cannot mask them ---
  const submit = async (name, password) => {
    const response = await page.request.post(`${BASE}/stash_delete.php`, {
      form: { confirm_name: name, current_password: password },
      maxRedirects: 0,
    });
    return response.headers()['location'] || '';
  };

  check('the wrong stash name is refused',
    (await submit('NotTheName', PASSWORD)).includes('error=name'));
  check('the wrong password is refused',
    (await submit(USER, 'definitely-not-the-password')).includes('error=wrong'));

  // Every refusal must have left the stash completely alone.
  check('the stash still opens after those refusals',
    (await login(PASSWORD)).includes('wall.php'));

  // --- the real thing, through the form ----------------------------------
  await page.goto(`${BASE}/user.php`, { waitUntil: 'networkidle' });
  await page.fill('#confirm-name', USER);
  await page.fill('#delete-password', PASSWORD);

  // The form asks for a confirm() as well; accept it.
  page.once('dialog', (dialog) => {
    console.log(`  confirm said: ${dialog.message()}`);
    dialog.accept();
  });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('#delete-submit'),
  ]);

  check(`it lands on the login page (${page.url().split('/').pop()})`,
    page.url().includes('login.html') && page.url().includes('deleted=1'));
  check('the login page says the stash is gone',
    await page.locator('#login-deleted').isVisible());

  // --- and it really is gone ---------------------------------------------
  const after = await login(PASSWORD);
  check(`the old credentials no longer log in (${after.split('/').pop()})`,
    !after.includes('wall.php'));

  // The session that did the deleting must be over too, not still holding a
  // password for a stash that no longer exists.
  const wall = await page.request.get(`${BASE}/wall.php`, { maxRedirects: 0 });
  check('the session was ended by the deletion',
    (wall.headers()['location'] || '').includes('login'));

  await browser.close();
})();
