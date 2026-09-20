# LinkEasy Publisher

A self-hosted publishing and scheduling platform for Facebook Pages that an operator is
authorised to manage. It has two halves:

| Component | What it is | Where it runs |
| --- | --- | --- |
| **Control plane** | PHP dashboard, scheduler, queue and API (`php-app/`) | Your web server |
| **Automation runtime** | Windows desktop app + Playwright worker (`windows-app/`) | The operator's PC |

The server decides *what* should be published and *when*. The Windows app does the actual
browser work on the operator's own machine, with their own signed-in Facebook session.

---

## What it does

- Connect Facebook accounts by signing in **by hand, in a real browser window** on the operator's PC.
- Discover the Pages those accounts administer, and let the operator choose which to publish to.
- Compose or schedule posts (text, image, video, reel) for one Page, many Pages, or all Pages.
- Queue jobs per Page, each with its own idempotency key, retry budget and lease.
- Publish through the operator's machine, then **verify the post actually appeared** before
  reporting success.
- Pause on any Facebook security check — CAPTCHA, checkpoint, 2FA, identity verification — and hand
  it to the human. Nothing is bypassed, solved or automated around.
- Show an honest audit trail: job events, worker logs, screenshots, analytics drawn only from real data.

## What it deliberately does not do

- Store Facebook passwords. Ever. They are never asked for and never written to disk.
- Bypass CAPTCHAs, checkpoints, 2FA or identity verification. Those pause the queue for a human.
- Disguise automation as human behaviour: no fingerprint spoofing, no stealth plugins, no
  randomised mouse simulation. Playwright's own synchronisation is used for waiting.
- Present unverified numbers as engagement analytics.
- Install an update that has not been verified.

---

## Requirements

**Server** — PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `zip`; MySQL 8 / MariaDB 10.6+;
nginx or Apache; PHP-FPM. (SQLite works for a local demo or a single-operator install.)

**Operator PC** — Windows 10/11 x64. Nothing else: Node, npm, Playwright, Chromium, FFmpeg and
Python are all bundled inside the installer. The operator never opens a terminal.

---

## Quick start (server)

```bash
cd php-app
cp .env.example .env              # then edit DB_* and APP_URL
php bin/console.php migrate       # create the schema
php bin/console.php user:create "Your Name" you@example.com "A-strong-password" Asia/Karachi
php bin/console.php user:admin you@example.com
```

Point your web server at `php-app/public` (see `docs/nginx-example.conf` and `docs/deployment.md`),
then add the two cron entries:

```cron
* * * * * php /path/to/php-app/cron/scheduler.php   >> /var/log/linkeasy-scheduler.log 2>&1
5 3 * * * php /path/to/php-app/cron/housekeeping.php >> /var/log/linkeasy-housekeeping.log 2>&1
```

**Demo mode** (SQLite, simulated publisher, nothing sent to Facebook):

```bash
cd php-app
mkdir -p storage/framework storage/logs storage/uploads storage/exports
DB_DRIVER=sqlite PUBLISHING_PROVIDER=simulated php bin/console.php migrate
DB_DRIVER=sqlite PUBLISHING_PROVIDER=simulated php bin/console.php demo
DB_DRIVER=sqlite PUBLISHING_PROVIDER=simulated APP_URL=http://localhost:8080 \
  php -S 0.0.0.0:8080 -t public public/router.php
```

The demo seeder prints a login. A browser-based walkthrough of every screen captured from a running
demo server is in `docs/ui-preview.html`.

## Getting the Windows `.exe`

The installer is not committed here: it is a ~200 MB Electron + NSIS build that must be compiled on
Windows. Two ways to produce it — a one-click script, or a GitHub Actions workflow that builds it for
you. Both are in **[BUILD-EXE.md](BUILD-EXE.md)**.

```powershell
# on a Windows PC with Node.js 20 installed:
powershell -ExecutionPolicy Bypass -File .\installer\Build-Installer.ps1
```

## Quick start (operator PC)

1. Run `LinkEasyPublisherSetup.exe` (installs under `C:\Program Files\LinkEasyPublisher\`).
2. Launch it. Everything needed is unpacked into `%LOCALAPPDATA%\LinkEasyPublisher\`.
3. Enter your workspace address and your dashboard login — that enrols the PC and issues a machine token.
4. Press **Sign in to Facebook**. The browser window is yours: sign in, complete any 2FA yourself.
5. Approve which Pages may be published to in the dashboard. The queue starts moving on its own.

---

## Repository layout

```
LinkEasyPublisher/
├── php-app/                 control plane: dashboard, API, scheduler, queue
│   ├── app/{Core,Models,Services,Controllers,Views}
│   ├── config/, cron/, bin/, database/, public/, storage/
├── windows-app/             desktop app + Playwright worker (Electron + Node)
│   ├── src/{api,browser,config,desktop,facebook,logging,media,runtime,security,updater,worker}
│   ├── assets/, tests/
├── installer/               build scripts for the runtime payload and the installers
├── database/schema.sql      production schema (MySQL/MariaDB, utf8mb4, InnoDB)
├── docs/                    architecture, protocol, security, deployment, user guide
└── tests/                   cross-component tests and fixtures
```

## Tests

No Composer, no PHPUnit, no Jest — just PHP and Node.

```bash
./tests/run.sh          # server suite + worker/desktop suite (offline)
./tests/run.sh php      # 39 tests: queue lifecycle, idempotency, media safety, tenancy, scheduler
./tests/run.sh node     # 23 tests: redaction, selectors, media cache, credentials, settings
./tests/run.sh integration   # 9 tests against a running server: the live worker protocol
```

The integration suite is skipped unless a server is reachable, and it exercises the real HTTP
contract — enrol, heartbeat, atomic claim, progress, refusal of an unverified success, duplicate
completion, failure reporting, challenge reporting and server-side log redaction. See
`tests/README.md`.

## Documentation

| Document | Contents |
| --- | --- |
| `docs/architecture.md` | Components, data flow, state machines, concurrency, idempotency |
| `docs/worker-protocol.md` | The HTTP contract between the Windows worker and the server |
| `docs/security.md` | Credential handling, tenant isolation, what is and is not stored |
| `docs/deployment.md` | Server installation, configuration, cron, backups, upgrades |
| `docs/windows-app.md` | Building, packaging, first run, repair and auto-update |

## Licence

Proprietary. Intended only for operators with legitimate administrative access to the Facebook
Pages they connect.
