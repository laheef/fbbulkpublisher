# Worker protocol

The contract between the Windows automation runtime and the PHP control plane. Every endpoint lives
in `php-app/routes/web.php` under `/worker/*` and is implemented by
`php-app/app/Controllers/WorkerController.php`.

## Authentication

| Situation | Credential |
| --- | --- |
| First enrolment / re-enrolment | `POST /worker/register` with the operator's **workspace email + password** (used once, in memory, never stored) |
| Everything afterwards | `Authorization: Bearer lkw_…` plus `X-LinkEasy-Worker: <worker uuid>` |

The plaintext token is returned exactly once at registration. The server keeps only
`sha256(token)` and an 8-character prefix for display. Rotating the token from the dashboard
(`POST /workers/{id}/rotate`) invalidates the previous one immediately. Registration is rate-limited
per IP.

Every response is JSON. Errors use the shape:

```json
{ "ok": false, "error": "Human readable message", "details": { } }
```

with `401` (bad token), `403` (job not yours), `404` (unknown), `409` (conflict), `419` (CSRF on web
routes), `422` (validation), `429` (throttled), `500` (server fault).

## Endpoint reference

### `POST /worker/register`

```json
{
  "installation_id": "uuid",
  "worker_id": "uuid",
  "name": "DESKTOP-OFFICE",
  "email": "operator@example.com",
  "password": "…",
  "meta": { "os": "Windows 11 Pro", "app_version": "1.0.0", "worker_version": "1.0.0",
            "playwright_version": "1.49.0", "browser_version": "Chromium 131",
            "ffmpeg_available": true, "hostname": "DESKTOP-OFFICE", "arch": "x64" }
}
```

Returns the worker row, the one-time `token`, and the behaviour config bundle:

```json
{ "ok": true,
  "worker": { "id": 7, "worker_id": "uuid", "name": "DESKTOP-OFFICE", "status": "STARTING" },
  "token": "lkw_…",
  "config": { "max_concurrent_browsers": 2, "max_concurrent_jobs": 2, "max_jobs_per_worker": 10,
              "idle_browser_timeout_s": 300, "trace_mode": "failures_only", "capture_screenshots": true,
              "media_strategy": "SERVER", "cleanup_after_publish": true, "retention_traces_d": 14,
              "retention_screenshots_d": 30 } }
```

Calling it again with a valid `Bearer lkw_…` token rotates the machine token instead.

### `POST /worker/heartbeat`

```json
{ "status": "ONLINE", "cpu_pct": 12.4, "mem_mb": 640, "pending_count": 0, "internet_ok": true,
  "active_jobs": 1, "browsers": 1, "paused": false, "uptime_s": 3600,
  "stats": { "published": 12, "failed": 1, "active": 1 }, "challenges": [] }
```

```json
{ "ok": true, "server_time_utc": "2026-09-19 18:51:31", "next_heartbeat_s": 20, "pending_jobs": 2,
  "is_paused": false, "config": { … }, "want_logs": false }
```

The response is also how the dashboard pushes configuration changes and pause/resume commands to a
running worker. A worker that misses the offline threshold is marked `OFFLINE` by
`BrowserWorker::sweepOffline()` and its leases are released.

### `GET /worker/config`, `GET /worker/jobs?limit=N`

`/worker/jobs` returns jobs that were just claimed — `{ jobs: [ … ] }` — and each job carries
everything the worker needs to act without asking again:

```json
{ "id": 23, "uuid": "…", "job_type": "PUBLISH_IMAGE", "idempotency_key": "…", "attempts": 1,
  "last_error_code": null, "result_url": null,
  "account": { "id": 2, "label": "Demo Business Account", "profile_ref": "acct_2", "status": "CONNECTED" },
  "page": { "id": 14, "name": "Demo Brand 02", "external_id": "104…", "url": "https://facebook.com/…" },
  "content": { "kind": "IMAGE", "caption": "…", "hashtags": "#tag", "link_url": null },
  "media": { "id": 7, "kind": "IMAGE", "mime_type": "image/png", "size_bytes": 106,
             "checksum_sha256": "f903…", "original_name": "launch.png" },
  "scheduled_at": "2026-09-25 04:30:00", "stagger_seconds": 30 }
```

A claimed job is leased: a second poll never returns it.

### `POST /worker/jobs/{id}/progress`

```json
{ "status": "UPLOADING", "stage": "media_upload", "progress_pct": 45, "message": "Uploading media" }
```

`stage` is recorded in `job_events`, which is what produces the per-stage timing in the dashboard.

### `POST /worker/jobs/{id}/complete`

```json
{ "result_url": "https://www.facebook.com/…/posts/123", "verified": true,
  "meta": { "evidence": "caption_match" } }
```

**Refused with 422 unless `result_url` or `verified: true` is present.** Completing the same job twice
is accepted and changes nothing — the first completion wins.

### `POST /worker/jobs/{id}/fail`

```json
{ "error_code": "NETWORK_ERROR", "error_message": "Connection reset while uploading",
  "screenshot_path": "job-23-NETWORK_ERROR-1695.png", "retryable": true }
```

The server decides the next state from the taxonomy in `docs/architecture.md`: retry with backoff,
`USER_ACTION_REQUIRED`, or `FAILED`. `retryable` is advisory — a permanent code is never retried even
if the worker asks for it.

### `POST /worker/jobs/{id}/verify`

Used after an uncertain attempt (crash, timeout, lease loss). The worker reports what it found:

```json
{ "verified": false, "reason": "PUBLISH_VERIFICATION_REQUIRED",
  "message": "No post appeared on the Page timeline in time.",
  "candidate_url": null }
```

The server either resolves the job itself (when it already knows the post URL) or raises
`PUBLISH_VERIFICATION_REQUIRED` → `USER_ACTION_REQUIRED` for a human. It never re-publishes
blindly.

### `POST /worker/challenge`

```json
{ "job_id": 18, "account_id": 2, "challenge_type": "CAPTCHA_DETECTED",
  "message": "Facebook presented a CAPTCHA for Demo Brand 01." }
```

```json
{ "ok": true, "acknowledged": true, "job_id": 18,
  "instruction": "Automation is paused. Bring the browser to the foreground and let the operator finish the check." }
```

The worker then pauses, brings the browser window to the front, and shows a Windows notification.
It resumes only when the human says the check is done.

### `GET /worker/jobs/{id}/media`

Streams the job's media file with HTTP range support (`Accept-Ranges: bytes`, 256 KB chunks) so the
worker can resume an interrupted download. Large videos are never loaded into PHP memory.

### `POST /worker/jobs/{id}/screenshot`, `POST /worker/media/{id}/thumbnail`, `POST /worker/media/{id}/probe`

Multipart uploads that attach a failure screenshot, a poster frame, or FFprobe metadata (produced by
the worker's bundled FFmpeg) to the record the dashboard displays.

### `POST /worker/logs`

```json
{ "lines": [ { "channel": "browser", "level": "warn", "message": "…", "context": { } } ] }
```

Accepts up to 500 lines per call. Every line passes through `Support::redact()` **before** storage, so
a cookie or token that slipped into a message is still not persisted.

### `POST /worker/accounts`, `POST /worker/accounts/{id}/pages`, `POST /worker/accounts/{id}/status`

Account lifecycle. `.../pages` replaces the discovered Page list, preserving each Page's
`ENABLED`/`DISABLED` choice and flagging anything that disappeared rather than deleting it.
`.../status` reports `CONNECTED`, `AUTH_REQUIRED`, `CHALLENGE_REQUIRED` or `ERROR` after a session check.

## Client behaviour (`windows-app/src/api/client.js`)

- Exponential backoff with jitter for read paths, capped by `server.maxBackoffSeconds` (default 300s).
- Writes are retried only when the server confirms they are safe; job reporting is idempotent by design.
- Media downloads resume from the last byte and are verified against the recorded size and checksum.
- TLS verification is always on; `server.verifyTls` exists only to make a local test server possible.
