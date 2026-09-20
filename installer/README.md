# Installer build

Everything needed to turn this repository into two signed `.exe` files whose end users install
nothing else: no Node.js, no npm, no Playwright, no Chromium, no FFmpeg, no Python, no Docker, and no
command line.

## What is produced

| Artefact | Contents |
| --- | --- |
| `LinkEasyFacebookPublisherSetup.exe` | NSIS installer: app + worker + `runtime\` (Node, Playwright, Chromium, FFmpeg), Start-menu and desktop shortcuts, optional "start with Windows" |
| `LinkEasyPublisherPortable.exe` | Self-contained portable build; data lives beside the executable in `LinkEasyPublisherData\` |
| `runtime-payload/*.zip` | Per-component archives used by vendor/repair flows |
| `latest.yml`, `*.nsis.7z`, `*.blockmap` | Auto-update feed files (served over HTTPS) |

## Pipeline

```bash
cd installer
npm install                        # only build-time deps: extract-zip, fs-extra

node build-runtime.js              # 1. assemble runtime/  (Node, Playwright, Chromium, FFmpeg)
node build-runtime.js --payload    #    also emit runtime-payload/*.zip for repair installs
node verify-runtime.js             # 2. prove every binary executes and matches its hash
cd ../windows-app && npm run dist  # 3. build the NSIS installer and portable exe

pwsh release.ps1 -Version 1.0.1    # 4. sign, hash, publish to the update feed
```

`build-runtime.js` writes `runtime/manifest.json` containing the exact SHA-256 of every component.
The application verifies those hashes at startup and refuses to publish if a component does not match
— a tampered or half-downloaded runtime fails loudly instead of misbehaving quietly.

## Component sources

| Component | Source | Notes |
| --- | --- | --- |
| Node runtime | official `node-v20.x-win-x64.zip` | only used to run the worker itself; the operator's own Node is never touched |
| Playwright | npm package pinned to the version in `windows-app/package.json` | installed into `runtime/playwright`, browsers disabled |
| Chromium | Playwright's own `chromium` download | pinned revision, never the operator's Chrome |
| FFmpeg / FFprobe | a Windows static build (gyan.dev or BtbN) | probing and poster frames; used only for local validation |

Every download is verified against a pinned hash before it is unpacked. A mismatch aborts the build.

## Repair

`runtime-payload/*.zip` is what the in-app **Repair** button uses when the installed runtime is
damaged:

1. If the payload is present, the component is re-extracted from it (no network needed).
2. Otherwise the component is fetched from the signed update feed.
3. Never from an arbitrary URL, and never without passing hash verification.

## Uninstall

`nsis-extras.nsh` adds:

- removal of the auto-start registry entry on uninstall
- a prompt asking whether to keep `%LOCALAPPDATA%\LinkEasyPublisher\` (kept by default, so a
  reinstall does not force a Facebook re-authentication)
- closure of any running instance before replacing files

## Signing

Sign both the installer and the update payloads with the same certificate:

```powershell
$env:WIN_CSC_LINK = "C:\certs\linkeasy.pfx"
$env:WIN_CSC_KEY_PASSWORD = "…"
```

`release.ps1` signs, writes `latest.yml`, and only then uploads. The updater in the application
refuses a payload whose signature does not verify, so shipping an unsigned build is a no-op rather
than a security hole.

## Pre-release checklist

- [ ] `node verify-runtime.js` passes on a clean Windows 10 VM with no developer tools installed
- [ ] Installer runs without an admin prompt (per-user install), or elevates cleanly when the operator chooses `Program Files`
- [ ] First run connects to a real workspace and reaches the **Ready** state
- [ ] Signing in to Facebook happens in a visible window; a security check pauses the job
- [ ] Uninstall leaves no service, scheduled task or auto-start entry behind
- [ ] Updating from the previous version preserves the enrolment and the browser profiles
