// 6.1/6.6 — the Profile screen's password change, end to end through the real
// form, now that the re-encryption runs as a detached background job.
//
// Runs entirely against a throwaway stash created for this test and deleted
// afterwards. It never touches the real user: changing a password re-encrypts
// every archive, which is not something to do to someone's stash to prove a
// point.
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://app:8080';
const USER = process.env.PROBE_USER;
const OLD = process.env.PROBE_OLD;
const NEW = process.env.PROBE_NEW;

if (!USER || !OLD || !NEW) {
  console.log('FAIL: PROBE_USER / PROBE_OLD / PROBE_NEW must be set');
  process.exit(1);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
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

  check('the throwaway stash logs in with its original password',
    (await login(OLD)).includes('wall.php'));

  // The Profile page must actually be reachable from the nav — it was a dead
  // link to a static mockup until now.
  await page.goto(`${BASE}/wall.php`, { waitUntil: 'networkidle' });
  const profileHref = await page.getAttribute('.user-menu-dropdown a:has-text("Profile")', 'href');
  check(`the user menu points at a real page (${profileHref})`, profileHref === 'user.php');

  await page.goto(`${BASE}/user.php`, { waitUntil: 'networkidle' });
  check('the Profile page renders', await page.locator('#password-form').count() === 1);
  check('it shows the username', await page.inputValue('#p-username') === USER);
  await page.screenshot({ path: '/work/screenshots/profile.png' });

  // --- Rejections. None of these should re-encrypt anything. ---
  const submit = async (current, next, confirm) => {
    const response = await page.request.post(`${BASE}/password_change.php`, {
      form: { current_password: current, new_password: next, confirm_password: confirm },
      maxRedirects: 0,
    });
    return response.headers()['location'] || '';
  };

  check('a wrong current password is refused',
    (await submit('definitely-not-the-password', NEW, NEW)).includes('error=wrong'));
  check('mismatched new passwords are refused',
    (await submit(OLD, NEW, NEW + 'x')).includes('error=mismatch'));
  check('a short new password is refused',
    (await submit(OLD, 'tooshort', 'tooshort')).includes('error=short'));
  check('reusing the current password is refused',
    (await submit(OLD, OLD, OLD)).includes('error=same'));

  // After all that, the stash must still be on the original password.
  check('the original password still works after the refusals',
    (await login(OLD)).includes('wall.php'));

  // The browser catches the mismatch before it reaches the server.
  await page.goto(`${BASE}/user.php`, { waitUntil: 'networkidle' });
  await page.fill('#new-password', NEW);
  await page.fill('#confirm-password', `${NEW}x`);
  check('the form warns about a mismatch in the browser',
    !(await page.locator('#match-hint').isHidden()));

  // --- The real change, through the form. ---
  //
  // 6.6: this no longer re-encrypts inside the request. The POST starts a
  // detached job and comes straight back to the Profile screen, which polls and
  // shows progress; when the job finishes the page signs the user out.
  await page.fill('#current-password', OLD);
  await page.fill('#new-password', NEW);
  await page.fill('#confirm-password', NEW);

  const startedAt = Date.now();
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('#password-form button[type="submit"]'),
  ]);
  const postMs = Date.now() - startedAt;

  console.log(`  the submit returned in ${postMs}ms`);
  check('the submit returns at once instead of blocking on the re-encryption', postMs < 5000);
  check(`it lands back on the Profile screen (${page.url().split('?')[1] || ''})`,
    page.url().includes('user.php') && page.url().includes('rekeying=1'));
  check('a progress card is shown in place of the form',
    await page.locator('#job-card').count() === 1
      && await page.locator('#password-form').count() === 0);
  await page.screenshot({ path: '/work/screenshots/profile_rekeying.png' });

  // While the job runs, the rest of the site must be out of reach — a page
  // saving anything under the old password mid-run would split the stash
  // across two keys. A probe stash is tiny, so the run may already be over by
  // the time this asks; only assert it if it is genuinely still going.
  const stillRunning = async () => {
    const status = await page.request.get(
      `${BASE}/job_status.php?kind=rekey&target=`, { maxRedirects: 0 });
    return status.ok() && (await status.json()).state === 'running';
  };

  if (await stillRunning()) {
    const blocked = await page.request.get(`${BASE}/wall.php`, { maxRedirects: 0 });
    check('the rest of the site is locked while the re-key runs',
      (blocked.headers()['location'] || '').includes('user.php'));
  } else {
    console.log('SKIP: the lock check (the probe stash finished re-keying too quickly)');
  }

  // The card's poller sends the browser to logout.php when the job succeeds.
  await page.waitForURL(/login|logout/, { timeout: 120000 });
  check(`the finished job signs the user out (${page.url().split('/').pop()})`,
    !page.url().includes('user.php'));

  // --- And it really is the new password now. ---
  await page.goto(`${BASE}/logout.php`, { waitUntil: 'networkidle' });
  check('the new password logs in', (await login(NEW)).includes('wall.php'));

  await page.goto(`${BASE}/logout.php`, { waitUntil: 'networkidle' });
  const oldAttempt = await login(OLD);
  check(`the old password no longer logs in (${oldAttempt.split('/').pop()})`,
    !oldAttempt.includes('wall.php'));

  await browser.close();
})();
