# Windows application

The operator-facing half of LinkEasy Publisher. It is an Electron tray application plus a Node
worker that drives Playwright. **The end user installs one `.exe` and never touches a terminal.**

## Distribution

Two artefacts are produced from the same code (`windows-app/package.json`):

| Artefact | Use | Install location |
| --- | --- | --- |
| `LinkEasyFacebookPublisherSetup.exe` | Standard install (NSIS, per-user, optional elevation) | `C:\Program Files\LinkEasyPublisher\` |
| `LinkEasyPublisherPortable.exe` | Portable copy on a USB stick | Next to the executable, in `LinkEasyPublisherData\` |

Binaries and user data are always separate:

```
C:\Program Files\LinkEasyPublisher\        binaries (read-only for the user)
%LOCALAPPDATA%\LinkEasyPublisher\          data for this Windows user
├── config\        settings.json, worker.json, credentials.bin (DPAPI)
├── profiles\      Chromium profiles, one folder per connected Facebook account
├── cache\         media downloaded for publishing (pruned automatically)
├── downloads\     anything the browser saved during a run
├── screenshots\   failure screenshots (uploaded, then retained locally for N days)
├── traces\        Playwright traces for failed jobs
├── logs\          app.log, worker.log, browser.log, media.log, errors.log
└── backup\        pre-update snapshots of local settings
```

## Building

```bash
cd windows-app
npm install
npm run fetch-runtime     # downloads Node, Chromium and FFmpeg, pins their SHA-256 hashes
npm install …             # electron + electron-builder for the shell
npm run dist              # produces the NSIS installer and the portable exe in dist/
```

`npm run fetch-runtime` (`installer/build-runtime.js`) is what makes the "no prerequisites" promise
real: it assembles `runtime/` — the Node runtime, Playwright, Chromium and FFmpeg — and writes
`runtime/manifest.json` with the verified hashes that `src/runtime/dependency-manager.js` checks
before use. `runtime/` is shipped through electron-builder's `extraResources`.

Signing: set `WIN_CSC_LINK` / `WIN_CSC_KEY_PASSWORD` (or a certificate in the store) so both the
installer and the update payload are signed. The updater refuses unsigned payloads.

## What happens on first run

1. **Single instance check** — a second launch just raises the existing window.
2. **Runtime verification** — every bundled component is checked; a missing or damaged one blocks
   publishing and offers **Repair** rather than failing later at job time.
3. **Data folders** are created under `%LOCALAPPDATA%` (never beside the binaries when installed).
4. **Connect your workspace** — workspace address + dashboard login. The password is used once, in
   memory, to obtain a machine token; only the token is stored (DPAPI).
5. **The worker starts**: it registers, heartbeats every 20 s and begins claiming jobs.
6. **Sign in to Facebook** — a browser window opens on a login page. The operator signs in, completes
   any 2FA themselves, and the Pages are discovered and synced to the dashboard.

## Day-to-day operation

| Where | What the operator sees |
| --- | --- |
| Tray icon | Colour = state: green online, blue publishing, amber paused/waiting for a human, grey offline, red error |
| Tray menu | Status, active jobs, **Pause/Resume**, open browser, reconnect an account, check updates, repair, logs, quit |
| Window → Overview | Live counters, the jobs running right now, notifications, and an "Action needed" panel |
| Window → Accounts & Pages | Local browser profiles, a button to sign in to Facebook, the effective concurrency limits |
| Window → Components | The runtime report with a Repair button |
| Window → Logs | Redacted tails per channel |
| Window → Settings | Start with Windows, tray behaviour, media cleanup, screenshots, verbose logs, updates, disconnect |

Closing the window keeps the worker running in the tray. **Quit from the tray** stops the worker,
closes browsers cleanly and exits.

## Notifications

Windows toasts are raised for exactly four things: a post published, a failure, a security check
waiting for the operator, and an update or repair that needs attention. Repeated identical toasts are
collapsed to avoid noise.

## When something goes wrong

| Situation | What the app does |
| --- | --- |
| Chromium dies mid-job | Relaunches, restores the profile, re-verifies the session, then retries the job |
| Facebook session expired | Reports `ACCOUNT_REAUTH_REQUIRED`; the operator reconnects from the tray (signing in themselves) |
| CAPTCHA / checkpoint / 2FA | Pauses, toasts, brings the browser to the front, and waits for the human — it is never solved automatically |
| Facebook interface changed | Stops with `FACEBOOK_UI_CHANGED` and points at the selectors file instead of clicking blindly |
| Post cannot be confirmed | `PUBLISH_VERIFICATION_REQUIRED` → the job waits for a human instead of risking a duplicate |
| Server unreachable | Keeps working offline, buffers logs, retries with exponential backoff |
| Runtime component damaged | Blocks publishing, offers Repair from the window or `linkeasy-worker repair` |
| Update ready | Downloads, verifies the signature, and installs only when no job is running |

## Repair and diagnostics

From the tray (**Verify / repair components**), from the window (Components → Repair), or from a
terminal if the operator prefers:

```
linkeasy-worker verify        # per-component health report
linkeasy-worker repair        # restore from the bundled payload, or re-download from the signed feed
linkeasy-worker diagnostics   # write a support bundle to the logs folder
linkeasy-worker status        # worker, browser and queue state as JSON
linkeasy-worker once          # process everything currently available, then exit (useful for testing)
```

## Uninstalling

The NSIS uninstaller removes the application. Data under `%LOCALAPPDATA%\LinkEasyPublisher\` is kept
deliberately, so reinstalling does not force a re-authentication of Facebook — delete that folder
manually if you want a clean removal. Disconnect the PC from the dashboard as well so the stale
worker row disappears from **Admin → Fleet**.
