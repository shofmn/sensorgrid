# Sensor Grid

A self-hosted website change monitor: Watch pages (or one element of it) for changes and get notified. Runs without user management and uses htaccess instead. No database required. Since this is a small personal tool, and I'm a big Star Trek fan, the UI is TNG LCARS themed.

## Features

- Monitor any http(s) URL, the whole `<body>`, a single element by `#id`, or every element with a `.class`, in intervals from 1 minute to 24 hours.
- Emails the exact change, one email per changed site.
- Optional Telegram messages from your own bot, sent only to you (see [Telegram](#telegram)).
- Per-site change history (last 20 entries) in an expandable row.
- Three mail transport options: `smtp` (vendored PHPMailer), `mail` (PHP `mail()`), `log` (a file, for development).
- Build-in resilience:
  - one email (and Telegram message) when a site fails N checks in a row, re-armed after the next success.
  - a cron-monitor in the UI warns if the cronjob hasn't been running as scheduled.
- No database required: Flat files only (`data/`); backup is a folder copy.
- Simple setup: just push the files to your web root instead of executing any build processes.

## How change detection works

1. **Fetch** the URL with cURL (TLS verification always on, redirects followed, size and time limits).
2. **Extract** the inner HTML of the element with the configured `#id`, or `<body>` when none is set. With a `.class`, extract every element that has that class, including each element's own tag and attributes — so for example, a button whose class flips from `.button--unpurchasable` to `.button--purchasable` counts as a change.
3. **Strip** `<script>`, `<style>`, `<noscript>` and comments.
4. **Normalise**: one line per tag boundary, whitespace collapsed.
5. **Hash** the lines with SHA-256 and compare against the stored snapshot.

The **first check only establishes a baseline** — it stores a snapshot and sends no email. Changes are reported from the second check onward. Editing a monitor's URL or element discards its snapshot so the next check re-baselines instead of reporting a bogus change.

## Requirements

- PHP 8.0+ with `curl`, `dom`, `libxml`, `mbstring` and `openssl` (developed on 8.3).
- Apache with `.htaccess` enabled (`AllowOverride All`, `mod_headers` optional).
- A shell cron or a web-cron panel that can run once a minute.
- An SMTP account, or a host where PHP `mail()` works.

## Setup

1. **Copy or clone** the folder to your web root (e.g. `/var/www/sensorgrid`).
2. **Make** `data/` **writable** by the web server user *and* the user that runs cron: `chmod 775 data`.
3. **Copy** `.htaccess.example` **to** `.htaccess` and create the password file:
  ```bash
   htpasswd -c /path/outside/webroot/.htpasswd yourname
  ```
   then set `AuthUserFile` in `.htaccess` to that absolute path. The example file uses the placeholder `/path/outside/webroot/.htpasswd`; Apache answers `500` until it points at a real file — failing closed beats serving the app unprotected. (No `htpasswd` binary? See `.htpasswd.example` for a PHP one-liner.)
4. **Open the app** in a browser and log in. `data/settings.txt` is created on first load.
5. **Fill in Settings**: notify email, mail transport and SMTP details, timezone, app URL. Use **Send test email** to verify.
6. **Add a monitor**, use **Test run** to check the URL and element.
7. **Install the cron job** (next section).
8. **Confirm** the header shows `LAST CRON RUN: …s ago` in blue rather than a red `CRON OFFLINE`.

## Cron setup

**Why system cron.** PHP has no dependable in-process timer for a web app:

- *Lazy cron* (run due checks when someone loads a page) does nothing for days if the only user does not visit, slows page loads unpredictably, and can run twice on parallel requests.
- *A self-looping script* (`while (true) { …; sleep(60); }`) is killed by PHP-FPM's `request_terminate_timeout`, Apache restarts, opcache resets and deploys. Nothing restarts it and nothing tells you it died; start it twice and you get duplicate emails.
- *pcntl / supervisord* is a heavier dependency than the whole app.

System cron is supervised by the OS, survives reboots and costs one line. It calls `cron.php` every minute; each monitor's own interval is honoured inside `cron.php`, so a 1-minute tick supports the full 1 minute – 24 hour range. A lock file stops a slow run from overlapping the next tick.

**Preferred (CLI)** — no HTTP, no Basic Auth, no request timeout:

```
* * * * * /usr/bin/php /var/www/sensorgrid/cron.php >> /var/www/sensorgrid/data/cron.log 2>&1
```

**Fallback (HTTP)** — for hosts that only offer a web-cron panel:

```
* * * * * curl -fsS "https://example.com/sensorgrid/cron.php?token=YOUR_CRON_TOKEN" > /dev/null
```

`cron.php` is exempt from Basic Auth and protects itself with the token (wrong or missing token → `403`). The token is generated on first run and shown, with ready-to-paste crontab lines, in **Settings → Cron** (also `cronToken` in `data/settings.txt`). With the CLI line, each run's summary appears in `data/cron.log` twice (once written by the app, once by the shell redirect); that is harmless.

## Settings reference

All keys of `data/settings.txt` (JSON). Everything except the read-only ones is editable in the UI, or by hand.


| Key                | Default                  | Meaning                                                                                        |
| ------------------ | ------------------------ | ---------------------------------------------------------------------------------------------- |
| `version`          | `1`                      | Settings schema version.                                                                       |
| `timezone`         | `Europe/Berlin`          | Timezone for displayed times and emails.                                                       |
| `appUrl`           | `http://localhost:1337/` | Public URL of the app; linked in emails and used in the HTTP cron line.                        |
| `notifyEmail`      | `""`                     | Recipient for change and failure emails. Nothing is sent while empty.                          |
| `fromEmail`        | `sensorgrid@localhost`   | Sender address.                                                                                |
| `fromName`         | `Sensor Grid`            | Sender display name.                                                                           |
| `mail.transport`   | `smtp`                   | `smtp`, `mail` or `log`.                                                                       |
| `mail.smtpHost`    | `""`                     | SMTP server host.                                                                              |
| `mail.smtpPort`    | `587`                    | SMTP port.                                                                                     |
| `mail.smtpSecure`  | `tls`                    | `tls` (STARTTLS), `ssl` (implicit TLS) or `none`.                                              |
| `mail.smtpAuth`    | `true`                   | Authenticate with user/password.                                                               |
| `mail.smtpUser`    | `""`                     | SMTP username.                                                                                 |
| `mail.smtpPass`    | `""`                     | SMTP password. Never sent back to the browser; leaving the field empty keeps the stored value. |
| `telegram.enabled` | `false`                  | Also send change and failure notifications to Telegram.                                        |
| `telegram.botToken` | `""`                     | Bot token from @BotFather. Never sent back to the browser; an empty field keeps the stored value. |
| `telegram.chatId`  | `""`                     | Your Telegram chat id, the only recipient.                                                     |
| `cronToken`        | generated                | Token for HTTP cron. Read-only in the UI.                                                      |
| `userAgent`        | `SensorGrid/1.0 (…)`     | User-Agent header for fetches.                                                                 |
| `requestTimeout`   | `20`                     | Whole-request timeout, seconds.                                                                |
| `connectTimeout`   | `10`                     | Connection timeout, seconds.                                                                   |
| `maxRedirects`     | `5`                      | Redirects followed per fetch (file only).                                                      |
| `maxContentBytes`  | `2000000`                | Larger responses fail with `CONTENT_TOO_LARGE` (file only).                                    |
| `notifyOnFailure`  | `true`                   | Send a failure email (and Telegram message) when the threshold is crossed.                     |
| `failureThreshold` | `3`                      | Consecutive failures before that email.                                                        |
| `lastCronRun`      | `null`                   | Written by `cron.php`; drives the CRON OFFLINE warning.                                        |
| `lastCronDuration` | `null`                   | Seconds the last cron run took.                                                                |


## Telegram

Optionally, every change and failure alert is also sent as a Telegram message from your own bot:

1. In Telegram, talk to **@BotFather**, send `/newbot` and copy the **bot token** (`123456789:AA…`). Treat it like a password.
2. Recommended: in @BotFather, `/setjoingroups` → your bot → **Disable**, so nobody can add it to a group.
3. Open your new bot and press **Start** (a bot can only message people who wrote to it first).
4. In **Settings → Telegram**, tick *Also send notifications to Telegram*, paste the token, click **Detect chat ID** and check that the name shown is yours. Then **Send test message** and **Save settings**.

**Only you receive anything.** Sensor Grid only *sends*: every message goes to the one chat id in Settings. It never registers a webhook and never reads or answers incoming messages, so anyone else who writes to the bot gets no reply at all. The only read is **Detect chat ID**, which runs when you click it and just shows who last messaged the bot. Send and failure results are logged as `telegram sent|FAILED (…)` in `data/cron.log`; the token is never logged.

## Data files

Everything lives under `data/` (`SG_DATA_DIR` at the top of `lib.php` moves it, e.g. outside the web root). **Backup is** `cp -r data/`**.**


| File                 | Contents                                                                                                        |
| -------------------- | --------------------------------------------------------------------------------------------------------------- |
| `settings.txt`       | Settings (JSON). Contains the SMTP password and cron token — keep it private.                                   |
| `monitoredPages.txt` | JSON array of monitors, including status and failure counters.                                                  |
| `snapshots/<id>.txt` | Normalised content of the last check, plain text.                                                               |
| `history/<id>.txt`   | JSON, newest first, max 20 entries; each stores up to 40 lines of 500 characters per side plus the true counts. |
| `cron.log`           | Run summaries and mail results; trimmed to the last 2000 lines when it passes 5000.                             |
| `mail.log`           | Messages written by the `log` transport.                                                                        |
| `cron.lock`          | Lock file; safe to ignore.                                                                                      |
| `dev-fixture.txt`    | Content served by `dev-fixture.php`.                                                                            |


`data/.htaccess` denies all web access. The files are hand-editable JSON; if one becomes invalid, the UI says so instead of overwriting it.

## Troubleshooting

- `CRON OFFLINE` — cron has not run for over 5 minutes. Run `php cron.php` by hand and read the output and `data/cron.log`. Check the crontab line, the PHP path, and that the cron user can write `data/`. For the HTTP variant check the token.
- `ELEMENT_NOT_FOUND` — no element with that `id` (or `class`) exists in the fetched HTML. It may be added by JavaScript (Sensor Grid does not run scripts) or differ for bots. Use **Test run**, or leave the element empty to watch the whole body.
- **TLS errors (**`CURL_60`**)** — PHP cannot verify the site's certificate. Point `curl.cainfo` in `php.ini` at a current CA bundle (e.g. Mozilla's `cacert.pem`). Verification is never disabled.
- **Mail not arriving** — switch the transport to `log` and send a test email; if it appears in `data/mail.log` the detection side works and the problem is SMTP. Check `data/cron.log` for the `mail[…] FAILED (…)` reason, and that `notifyEmail` is set.
- **Permission errors on** `data/` — the web server user and the cron user both need write access (`chmod 775`, shared group).
- **A site reports a change on every check** — the fragment contains something that changes per load (a CSRF token, timestamp, ad slot, visitor counter). The history drawer shows exactly which lines. Pick a narrower element: an `#id`, or a `.class` that covers only the part you care about (useful when the relevant elements have no ids).
- `HTTP_401` **/** `HTTP_403` — the target refuses the request. Sensor Grid does not support logins or custom headers for monitored sites.



## Local development

Developed on Windows with Laragon.

- Run cron manually with the PHP CLI (not on `PATH` by default):
  ```
  C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe cron.php
  ```
- For testing without a real mail server, set the transport to `log`, or start **Mailpit** from the Laragon menu (SMTP `localhost:1025`, encryption `none`, auth off; UI at `http://localhost:8025`). PHP `mail()` also lands in Mailpit through `sendmail_path`.
- `dev-fixture.php` is a local test target: it renders `data/dev-fixture.txt` inside `<div id="watch">`, surrounded by noise (a random number, a script) that changes on every load. Monitor `http://localhost:1337/dev-fixture.php` with element id `watch`; edit the txt file to trigger a change; leave the id empty to see the noise trigger one. `?sleep=5` simulates a slow site. `.htaccess` makes it reachable from loopback only. **Delete it on a production host.**
- Basic Auth applies locally too. `curl -u user:pass http://localhost:1337/` works; `cron.php?token=…` needs no credentials.



## Security notes

- **Use HTTPS in production.** Basic Auth sends the password in clear text over HTTP.
- Access control is Apache Basic Auth and nothing else; there are no accounts. Keep `.htpasswd` outside the web root.
- Monitored URLs are fetched **server-side**, so anyone who can add a monitor can make your server request any address it can reach (SSRF). Do not expose this tool to untrusted users, and preferably keep it off the public internet.
- Every state-changing request carries a CSRF token; all output is HTML-escaped; a Content-Security-Policy restricts the page to its own origin.
- `data/settings.txt` holds the SMTP password and the Telegram bot token in plain text. `data/` is denied by Apache, but moving it outside the web root (`SG_DATA_DIR`) is better.
- `.git/`, dotfiles and directory listings are blocked in `.htaccess`. Copy `.htaccess.example` and set `AuthUserFile` to the absolute path of your password file.



## License

MIT — see [LICENSE](LICENSE).

Third-party components:

- **Antonio** font — © The Antonio Project Authors, SIL Open Font License 1.1 (`assets/fonts/Antonio-OFL.txt`).
- **PHPMailer 6.12.0** — LGPL-2.1 (`lib/PHPMailer/LICENSE`), vendored unmodified.

