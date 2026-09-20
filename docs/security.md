# Security

## Credentials

| Secret | Where it lives | How it is protected |
| --- | --- | --- |
| Facebook password | **Nowhere.** Never requested, never transmitted, never stored | The operator types it into the real Facebook login page in the browser window |
| Facebook session | The Chromium profile on the operator's PC | Owned by Chromium, scoped to the Windows user account, never readable through the dashboard or the API |
| Worker token (`lkw_…`) | `%LOCALAPPDATA%\LinkEasyPublisher\config\credentials.bin` | DPAPI (per Windows user) via Electron `safeStorage`; AES-256-GCM with a machine-derived key off-Windows |
| Dashboard password | Server database | Argon2id hash, never reversible, never logged |
| Dashboard session | HTTP-only, SameSite=Lax cookie | `SESSION_SECURE` must be `true` behind HTTPS in production |

Rotation: an administrator can invalidate a machine from **Admin → Fleet**, or the operator can rotate
from the dashboard (`POST /workers/{id}/rotate`). The previous token stops working immediately.

## Logging

`Support::redact()` (PHP) and `redact()` (`windows-app/src/logging/logger.js`) strip, before anything
is written:

- `c_user`, `xs`, `fr`, `datr`, `sb`, `presence` cookie values
- `Bearer …` tokens and `lkw_…` worker tokens
- `password`, `passwd`, `pwd`, `secret`, `token`, `api_key`, `client_secret` key/value pairs
- `access_token`, `id_token`, `refresh_token` JSON fields

The redaction runs on both sides: the worker redacts before sending, and the server redacts again
before storing, so a leak in one layer is still caught by the other.

Diagnostics bundles (`GET /admin/diagnostics`) are built from already-redacted logs and contain no
cookies, tokens or session data.

## Tenancy

- Every query that returns user data starts from the signed-in user: `Auth::id()` is bound into the
  model layer (`Job::forUser`, `Media::forUser`, `BrowserWorker::onlineForUser`, …).
- Cross-tenant access is answered with **404**, not 403, so resource existence is not leaked
  (`Auth::requireOwns()`).
- Admin-only routes sit behind the `admin` middleware; the last active administrator cannot be
  demoted or suspended (enforced in `AdminController::updateUser`).
- Worker API calls are scoped to the worker's own workspace: a job that belongs to another workspace
  is rejected even if the job id is guessed.

## Local surface (operator PC)

- The local dashboard API binds to `127.0.0.1` on a random free port. It is never reachable from the
  network, and there is no firewall rule to create.
- Every endpoint requires a per-run bearer token generated at startup; the Electron renderer receives
  it over IPC, so another local process cannot drive the API from a browser tab.
- The renderer runs with `contextIsolation: true`, `nodeIntegration: false`, no remote module, and a
  strict CSP (`default-src 'none'` plus the loopback origin). Navigating away is blocked; external
  links open in the operator's default browser.
- The only outbound traffic is HTTPS to the configured workspace server (and the update feed).

## What the automation will not do

These are product requirements, not configuration options:

1. **No CAPTCHA or checkpoint bypass.** `challenge-detector.js` recognises the page Facebook serves
   and returns `CAPTCHA_DETECTED` / `SECURITY_CHALLENGE` / `CHECKPOINT` / `2FA_REQUIRED` /
   `IDENTITY_VERIFICATION`. The job pauses, the browser comes to the front, and the human completes it.
   There is no solver, no audio-challenge reader, no token harvesting, no retry-until-it-passes loop.
2. **No anti-detection or "human-like" behaviour.** The worker launches stock Chromium through
   Playwright, with no stealth plugin and no fingerprint patching. Waits are Playwright's own
   synchronisation primitives (visibility, network idle, element state) — never randomised delays
   intended to look human.
3. **No rate-limit evasion.** Backoff exists to be a good citizen after a transient failure, never to
   push more work through a restriction. `RATE_LIMITED` pauses rather than retries aggressively.
4. **No unsigned updates.** `updater.requireSignature` defaults to `true`; an update whose signature
   does not verify is refused and reported, never installed. Installation waits until no job is running.
5. **No password storage, ever.** There is no code path that accepts a Facebook password.

## Failure containment

- A browser crash relaunches Chromium, restores the profile and re-verifies the session before the job
  continues (`BrowserManager::recover()`), so a broken browser cannot become a broken account.
- `FACEBOOK_UI_CHANGED` stops the job instead of clicking unpredictable controls. Selectors are
  centralised in `windows-app/src/facebook/selectors/` so a UI change is a one-file fix.
- `PUBLISH_VERIFICATION_REQUIRED` prevents the worst outcome in publishing automation: a silent
  duplicate post. Ambiguity is escalated to a human, never resolved by guessing.
- Sessions that fail verification produce `ACCOUNT_REAUTH_REQUIRED`; the worker never attempts to
  re-authenticate on its own.

## Reporting a problem

Generate a diagnostics bundle from **Admin → Diagnostics** (server) or `linkeasy-worker diagnostics`
(PC) and attach it. Both are already redacted; if you find anything sensitive in one, that is a bug
worth reporting.
