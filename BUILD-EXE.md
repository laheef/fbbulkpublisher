# How to get the Windows `.exe`

The installer cannot be committed to this repository: it is an Electron + NSIS build of roughly
150–250 MB whose contents are mostly the bundled Chromium runtime, and it has to be compiled on
Windows. Two supported ways to produce it, both about ten minutes of machine time.

| Situation | Use |
| --- | --- |
| You have a Windows PC (10 or 11) | **A. One-click local build** |
| You do not, or you want a reproducible artefact | **B. GitHub Actions** |

Either way you end up with two files:

- `LinkEasyFacebookPublisherSetup.exe` — the installer (Start-menu + desktop shortcuts, per-user install)
- `LinkEasyPublisherPortable.exe` — a portable copy for a USB stick

---

## A · One-click local build (Windows)

1. Copy the project folder to the Windows machine.
2. Install **Node.js 20 LTS** if it is not already there — <https://nodejs.org/en/download>.
   (This is a *build-time* tool for you, the builder. The person who runs the finished
   installer needs nothing.)
3. Right-click `installer\Build-Installer.ps1` → **Run with PowerShell**.
   If Windows blocks the script, open PowerShell in the project folder and run:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\installer\Build-Installer.ps1
   ```

The script checks Node, downloads and verifies the runtime (Node, Playwright, Chromium, FFmpeg —
this is the slow step, 5–15 minutes on a normal connection), installs the application packages,
builds both executables, reports whether they are signed, and opens the output folder.

Useful switches:

```powershell
# rebuild only the app, reusing an existing runtime/ folder (fast)
.\installer\Build-Installer.ps1 -SkipRuntime

# portable .exe only
.\installer\Build-Installer.ps1 -PortableOnly

# sign with a certificate
.\installer\Build-Installer.ps1 -Certificate C:\certs\linkeasy.pfx
```

Building requires roughly 4 GB of free disk space and an internet connection.

---

## B · GitHub Actions (no Windows machine)

Push this repository to GitHub, then **Actions → Build Windows installer → Run workflow**.
The workflow runs the PHP and Node test suites first, assembles the runtime, builds both
executables, and attaches them to the run as the artifact **`LinkEasyPublisher-windows`**.

Download it from the run summary and unzip — you have your `.exe`.

Tagged builds (`git tag v1.0.1 && git push --tags`) additionally publish a GitHub Release with the
installer attached, which is the easiest link to hand to an operator.

Add these repository secrets to get signed builds:

| Secret | Value |
| --- | --- |
| `WIN_CSC_LINK` | base64 of your `.pfx`, or a URL to it |
| `WIN_CSC_KEY_PASSWORD` | the `.pfx` password |

---

## About signing (worth five minutes)

Without a code-signing certificate:

- Windows **SmartScreen** shows "Windows protected your PC" the first time the installer runs on a
  machine that has not seen it before. The operator has to choose *More info → Run anyway*.
- The application's **built-in updater refuses unsigned updates**, by design — so auto-update will
  not work for those builds. Manual reinstalls still do.

With a certificate (an OV or EV code-signing cert from a public CA, or your own enterprise CA if the
PCs trust it), everything is clean: no warnings, and auto-update works. See
`installer/README.md` for the release feed layout.

---

## What the end user actually does

Nothing technical. They run the installer, launch the app, type the workspace address and their
dashboard sign-in, then sign in to Facebook themselves in the browser window that opens. They never
install Node, npm, Playwright, Chromium, FFmpeg, Python, Docker or Git, and never open a terminal.

---

## Verifying a build before you ship it

```powershell
cd installer
node verify-runtime.js          # every bundled binary executes and matches its pinned hash
```

Then run the new-release checklist at the bottom of `installer/README.md` on a clean Windows 10 VM:
install with no developer tools present, connect to a workspace, confirm a security check pauses the
job, and uninstall to check nothing is left behind.
