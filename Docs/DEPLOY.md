# Deploying MyStash

MyStash is a single container: nginx on port 8080 with PHP-FPM behind it on
the container's own loopback, and `ffmpeg` and `7z` alongside them. There is no
database, no queue and no external service — the whole of a user's data is
encrypted files under `App/Data`, and the whole of the runtime state is in
`/dev/shm`.

That shape makes deployment short. It also concentrates every risk in two
places, so this document spends most of its length on those: **where the
plaintext goes** and **what a backup is worth without the password.**

---

## 1. What you need

| | |
| --- | --- |
| Docker Engine + Compose v2 | `docker compose version` should report v2.24 or newer — the production overlay uses `!override`, which is not in older versions |
| Disk | the size of your library, plus room for one video at a time while it is being converted |
| RAM | 2 GB free for `/dev/shm` on top of whatever else the host does (see §6) |
| A host you trust | the password is typed into this app, held in RAM for the session, and never stored |

Nothing else. No PHP, no ffmpeg and no 7-Zip on the host — they are in the
image.

---

## 2. The short version

```bash
git clone <your-remote> mystash && cd mystash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Then open <http://127.0.0.1:8080> and create a stash.

That is a complete, working deployment **for one machine, used locally**. If
anyone reaches it over a network, read §4 before you tell them the address.

---

## 3. Development versus production

There are three compose files and they layer:

| File | What it adds |
| --- | --- |
| `docker-compose.yml` | the app itself: the one `app` container, port 8080, the 2 GB `/dev/shm` |
| `docker-compose.prod.yml` | loopback-only port, read-only code, `restart: unless-stopped`, `App/php.prod.ini` |
| `docker-compose.dev.yml` | the Playwright container the `dev/` checks drive |

Used as:

```bash
# production
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# development, with the browser checks available
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

The base file on its own is the development configuration: the port is
published on every interface, the code is mounted writable, and PHP renders
its errors to the browser. That last one is the reason the overlay exists.

### One container, two processes

nginx and php-fpm run side by side inside the `app` container, started by
`App/docker-entrypoint.sh`. nginx reaches php-fpm on `127.0.0.1:9000`, which is
not published and not on the compose network — nothing outside the container
can speak FastCGI to it at all.

They are together because they are not independently useful: nginx here serves
one document root, out of the same files php-fpm executes, and neither is ever
restarted or scaled without the other.

The entrypoint exits as soon as *either* process does, which is deliberate. The
failure worth guarding against is half a container — nginx still answering on
8080 while php-fpm is dead, so every page is a 502 and `docker compose ps`
reports the service as up. Instead the container exits, and
`restart: unless-stopped` brings the whole thing back.

Both processes log to the container's stdout/stderr, so `docker compose logs -f
app` is the access log, the nginx error log and PHP's `error_log()` interleaved
in one stream.

### What the production overlay actually changes

**PHP stops talking to the browser.** A fatal error renders a stack trace, and
a PHP stack trace carries each frame's arguments — which in this app means the
user's password, truncated to fifteen characters, printed on the page. The
overlay sets `display_errors = Off` and sends errors to the container log
instead, and sets `zend.exception_ignore_args = 1` so the log does not collect
what the page no longer shows. Verify it:

```bash
docker compose exec app php -i | grep -E '^display_errors|^zend.exception_ignore_args'
```

**The code is mounted read-only.** Only `App/Data` is writable. Every
persistent write this application makes lands there; sessions, job records,
decrypt staging and the login counters are all in `/dev/shm`. Nothing writes
to `App/src` or `App/public` at run time, so nothing needs permission to — and
an upload that found a way to place a file in the web root would have nowhere
to put it.

**The session cookie is hardened.** `HttpOnly`, `SameSite=Lax` and
`session.use_strict_mode`. The cookie is the key to a decrypted stash for as
long as the login lasts; it is worth three lines.

**The port is bound to `127.0.0.1`.** Not a firewall — a default that makes
exposing the app a decision rather than an accident. See §4.

---

## 4. Exposing it to a network

**There is no TLS in this application, and the password is the encryption
key.** Over plain HTTP it crosses the network in a form POST in the clear, and
so does every byte of decrypted video. On `127.0.0.1` that does not matter. On
anything else it is the whole ballgame.

If the app is to be reachable from another machine, put a TLS-terminating
proxy in front of it and leave MyStash bound to loopback. Caddy is two lines:

```
stash.example.internal {
    reverse_proxy 127.0.0.1:8080
}
```

or nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name stash.example.internal;
    ssl_certificate     /etc/ssl/stash.crt;
    ssl_certificate_key /etc/ssl/stash.key;

    # Videos are large and streamed; the app's own nginx allows 2G bodies and
    # this one has to agree or the upload dies at the outer hop.
    client_max_body_size 2G;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;

        # media.php streams byte ranges out of a decrypted file. Buffering
        # here stages the whole response before the first byte reaches the
        # browser, so seeking in a long video stalls until the entire file
        # has been decrypted.
        proxy_buffering off;
    }
}
```

Then **uncomment `session.cookie_secure = 1`** in `App/php.prod.ini` and
restart PHP. Do this only once TLS is actually in front: with it set, the
browser will not send the cookie over plain HTTP at all, and the symptom is a
login that appears to succeed and bounces straight back to the login screen.

Two things a proxy does not fix:

- **The login throttle becomes nearly global.** It keys on `REMOTE_ADDR`,
  which behind a proxy is the proxy. The per-address limit is deliberately
  loose (50 failures in 10 minutes) precisely because of this; the per-username
  limit of 10 is the one that does the work. If you want the per-address limit
  to mean anything, configure nginx's `real_ip` module in `App/nginx.conf` so
  `REMOTE_ADDR` is the client's — and only if you trust the header, which you
  do only if the proxy always sets it.
- **Anyone who can reach the login page can attempt a login**, and each attempt
  against an existing stash spawns a 7-Zip decrypt. Exposing this to the open
  internet is not a thing to do casually.

---

## 5. Creating the first stash

Open the app and register. There is no administrator and no first-run setup:
a user *is* a directory plus an archive encrypted with their password.

- Usernames are letters and digits, up to 32 characters.
- Passwords are at least 24 characters, because the password is the AES key
  and anyone holding a copy of `App/Data` can attack it offline at whatever
  speed their hardware allows. Length is the only defence that matters.
- **There is no reset.** Not "an administrator has to do it" — there is no
  stored password to reset, no recovery key, and no copy of the plaintext
  anywhere. A forgotten password is a destroyed library. Say this out loud to
  anyone you hand a stash to.

To fill a stash with demo content instead — a dozen videos, three creators,
categories and playlists, all through the real endpoints:

```bash
dev/make_sample_clips.sh          # synthesises twelve clips
dev/seed_sample_data.sh           # uploads them as TestUser
```

Do that on a development instance, not on the machine holding real data.

---

## 6. Sizing and limits

**`/dev/shm` is 2 GB and that number is load-bearing.** Every decrypt stages
plaintext there: playing a video extracts it, converting one extracts it,
uploading one writes previews there, and re-keying works through the whole
stash. Docker's default is 64 MB, which is smaller than a single video —
extraction simply fails. If your videos are larger than about 1 GB, raise
`shm_size` in `docker-compose.yml` to comfortably exceed the largest file you
will hold, because the original and the conversion can both be resident.

**Uploads are capped in two places** and both have to agree: `client_max_body_size`
in `App/nginx.conf` and `upload_max_filesize` / `post_max_size` in
`App/php.ini`, all 2 GB by default. A proxy in front makes a third (§4).

**PHP-FPM runs 16 workers.** A range request holds a worker for as long as the
player is pulling bytes, so a wall of hovering tiles plus one playing video is
several at once. Raise `pm.max_children` in `App/php-fpm.conf` if you have the
RAM and the viewers.

**Long jobs are detached.** Conversion and re-keying run in
`bin/job_worker.php` with no request behind them, and the browser polls
`job_status.php`. Closing the tab does not stop them; restarting the container
does.

---

## 7. Backups

`App/Data` is the entire library. Everything in it is already encrypted at
rest, which makes backing it up unusually simple and unusually unforgiving:

```bash
docker compose stop app          # so nothing is mid-write
tar -czf mystash-$(date +%F).tar.gz App/Data
docker compose start app
```

- **Back up while nothing is writing.** A backup taken during an upload or a
  re-key can catch a half-written archive. Stopping the container for the
  duration is the cheap way to be sure — and `docker compose stop app` is a
  clean stop: the entrypoint signals nginx and php-fpm to finish, and the
  container is down in under a second rather than being killed after Docker's
  ten-second grace period.
- **The password is not in the backup and cannot be recovered from it.** A
  backup of a stash whose password is forgotten is a folder of noise. Whatever
  you use to remember passwords, that is the other half of this backup.
- **Test a restore.** Copy the tarball to a scratch machine, unpack it, bring
  the stack up and log in. A backup nobody has restored is a hypothesis.
- `/dev/shm` is never backed up, by design — it holds the only plaintext the
  system ever produces.

---

## 8. Upgrading

```bash
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

- **Do not upgrade while a re-key is running.** Changing a stash's password
  rewrites every archive in it and keeps `.enc.old` copies until the run
  completes; killing the container mid-run leaves that half-done. The Profile
  screen shows progress — wait for it.
- **Restarting ends every session** (`/dev/shm` is cleared) and kills any
  in-flight conversion. A conversion can simply be run again; nothing is lost
  but the CPU time.
- The datastore format has changed once before, and one-off migrations live in
  `App/bin/` (`migrate_categories.php`, `migrate_records.php`). If an upgrade
  needs one, it will say so in the release notes. Back up first.

---

## 9. Verifying an install

```bash
docker compose exec app php /app/tests/smoke_test.php
```

226 checks over encryption, ingestion, querying, re-keying, playlists and the
injection guards. It is self-contained: the one real video it needs is
synthesised with ffmpeg into tmpfs and thrown away afterwards, so this runs on
a fresh clone with an empty datastore.

The browser checks under `dev/` need the development overlay (they drive a
Playwright container) and a seeded `TestUser`:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
dev/run_font_check.sh        # nothing on the site is fetched from another host
dev/run_flow_check.sh        # the app flow in SPECIFICATIONS.md §2, end to end
dev/run_isolation_check.sh   # one stash cannot see another
```

`dev/run_font_check.sh` is the one worth running on a machine you are about to
expose: it fails if any page fetches anything from a host that is not this app.

---

## 10. What this deployment is not

Stated plainly, because the gap between "runs on my machine" and "runs for
other people" is where the surprises live:

- **No TLS of its own.** §4.
- **No multi-tenancy beyond the datastore.** Two stashes cannot read each
  other — that is enforced and tested — but they share one host, one CPU and
  one `/dev/shm`, and one user converting a 4K video is felt by the other.
- **No quotas.** Anyone with a stash can fill the disk.
- **No password reset, no account recovery, no administrator.** §5.
- **Not hardened against a hostile host.** The password is in RAM for the
  session and the plaintext is in `/dev/shm` while a video plays. Anyone with
  root on the machine can read both. The threat model is a stolen disk and a
  curious housemate, not a compromised server.
