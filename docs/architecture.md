# Architecture

## Two halves, one queue

```
┌────────────────────────── Control plane (PHP, MySQL) ──────────────────────────┐
│                                                                               │
│  Dashboard  ──►  PostDispatcher  ──►  jobs table  ──►  SchedulerService (cron) │
│  (users,          one job per Page      atomic claims      promote / expand /   │
│   pages,          idempotency key       leases, retries     lease recovery      │
│   media, posts)                                                               │
│        ▲                                              │                        │
│        │ writes                                       │ /worker/*  (bearer token)│
└────────┼──────────────────────────────────────────────┼────────────────────────┘
         │                                              ▼
   operator's browser                        ┌──────────────────────────┐
   (dashboard session)                       │  Windows desktop app     │
                                             │  tray + local dashboard  │
                                             │  worker loop             │
                                             │  Playwright → Chromium   │
                                             └──────────┬───────────────┘
                                                        │ real browser session
                                                        ▼
                                                  facebook.com
```

The PHP server is the **only** authority for state. The Windows app is a stateless executor: it
claims a job, performs it, reports the outcome, and never decides policy (who may publish, to which
Page, how often, with what retry budget) on its own.

## Data model (see `database/schema.sql`)

| Table | Purpose |
| --- | --- |
| `users` | Operator accounts, roles (`admin`/`user`), status, timezone |
| `browser_workers` | One row per PC: `token_hash`, `token_prefix`, capabilities, heartbeat, live job |
| `facebook_accounts` | One Facebook login per row; `profile_ref` is an opaque folder name, never a cookie |
| `facebook_pages` | Pages discovered from an account, with `status` (`ENABLED`/`DISABLED`/`AUTH_REQUIRED`/`CHALLENGE_REQUIRED`) |
| `media` | Uploaded files: sniffed MIME, size, probe metadata, checksum, soft delete |
| `posts` | A composed post: caption, kind, targets, schedule policy |
| `post_targets` | Which Page a post goes to (join table) |
| `scheduled_posts` | Recurrence: `next_run_at`, `interval`, `max_runs`, `runs_count` |
| `jobs` | The unit of work: one post × one Page × one run, with `idempotency_key` (unique) |
| `job_events` | Append-only state changes per job, with stage timing |
| `worker_logs` | Redacted log lines mirrored from workers |
| `notifications` | In-app notifications for operators |
| `activity_logs` | Who did what (logins, rotations, admin actions) |
| `settings` | Server-side defaults; per-user overrides |

## Job lifecycle

```
DRAFT ─► SCHEDULED ─► QUEUED ─► CLAIMED ─► PROCESSING ─► UPLOADING ─► PUBLISHING ─► VERIFYING ─► PUBLISHED
                                      │                                                        │
                                      ├─► RETRYING ──(attempts < 3, backoff 60/300/900s)──────┘
                                      ├─► USER_ACTION_REQUIRED   (CAPTCHA, checkpoint, 2FA, reauth)
                                      ├─► FAILED                 (permanent error, or retries exhausted)
                                      └─► CANCELLED
```

Rules the server enforces:

1. **One job per Page per run.** `${post} × ${page} × ${run}` produces a stable
   `idempotency_key = sha256("linkeasy|postId|pageId|runIndex|salt")`. The unique index on that
   column makes a duplicate publish impossible even if two schedulers race.
2. **Claims are atomic.** `Job::claimForWorker()` selects `QUEUED`/`RETRYING` rows inside a
   transaction and locks them (`FOR UPDATE SKIP LOCKED` on MySQL), so two PCs never take the same job.
3. **Leases expire.** A claimed job carries `lease_expires_at` (default 900s). If a PC dies mid-job,
   `SchedulerService::tick()` releases the lease and the job becomes claimable again — and because the
   job is retried only after the worker checks whether the post already exists, this cannot duplicate a post.
4. **Retries are capped.** `MAX_RETRIES = 3` with backoff `[60, 300, 900]` seconds. Permanent codes
   (`FACEBOOK_UI_CHANGED`, `PAGE_NOT_FOUND`, `MEDIA_INVALID`, …) stop immediately.
5. **Success needs evidence.** `POST /worker/jobs/{id}/complete` is refused (HTTP 422) unless it
   carries a `result_url` or `verified: true`. If the worker cannot confirm the post, it raises
   `PUBLISH_VERIFICATION_REQUIRED`, which converts the job to `USER_ACTION_REQUIRED` for a human.

## Failure taxonomy (`app/Services/JobFailureCodes.php`)

| Class | Codes | Behaviour |
| --- | --- | --- |
| Retryable | `NETWORK_ERROR`, `SERVER_UNREACHABLE`, `BROWSER_CRASHED`, `BROWSER_LAUNCH_FAILED`, `UPLOAD_INTERRUPTED`, `TIMEOUT`, `PAGE_LOAD_TIMEOUT`, `MEDIA_DOWNLOAD_FAILED`, `WORKER_RESTARTED`, `LEASE_EXPIRED`, `RATE_LIMITED`, `UNKNOWN_TRANSIENT` | Retried with backoff, and only after a duplicate check |
| Human required | `CAPTCHA_DETECTED`, `SECURITY_CHALLENGE`, `CHECKPOINT`, `2FA_REQUIRED`, `IDENTITY_VERIFICATION`, `ACCOUNT_REAUTH_REQUIRED`, `PROFILE_LOCKED` | Job → `USER_ACTION_REQUIRED`; browser brought to the front; toast notification; queue paused for that Page |
| Permanent | `FACEBOOK_UI_CHANGED`, `PUBLISH_VERIFICATION_REQUIRED`, `ACCOUNT_DISABLED`, `PAGE_NOT_FOUND`, `PAGE_NO_PERMISSION`, `MEDIA_INVALID`, `MEDIA_MISSING`, `POST_EMPTY`, `UNSUPPORTED_MEDIA`, `JOB_CANCELLED`, `DUPLICATE_PUBLICATION_CONFIRMED`, `PERMANENT_PLATFORM_REJECTION` | Job stops; nothing else is attempted |

## Concurrency

| Limit | Where it lives | Default | Effect |
| --- | --- | --- | --- |
| `MAX_CONCURRENT_BROWSERS` | settings, sent in `/worker/config` | 2 | Chromium processes per PC |
| `MAX_CONCURRENT_JOBS` | settings | 2 | Jobs in flight per PC |
| `MAX_JOBS_PER_WORKER` | settings | 10 | Jobs held per claim window |
| `queue.batch` | `config/config.php` | 5 | Jobs handed out per HTTP poll |
| `queue.lease_seconds` | `config/config.php` | 900 | Crash recovery window |

Thousands of Pages do **not** mean thousands of browsers. One signed-in Facebook account is one
Chromium profile, and that one profile can publish to every Page it administers, one job at a time.
The PHP side independently refuses to queue two live jobs for the same Page.

## Publishing providers

`PUBLISHING_PROVIDER` selects how a job is actually executed:

| Provider | Use |
| --- | --- |
| `browser` (default) | The Windows worker drives the real Facebook composer through Playwright |
| `simulated` | Local demos and tests: no browser, no network, deterministic outcomes |
| `official_api` | Optional path for Pages where a legitimate Graph API token exists (never used to work around a restriction) |

The abstraction lives in `php-app/app/Services/Providers/`. The provider declares
`key/label/requiresWorker/supports/supportedJobTypes/validateJob/executionPlan`, so the dashboard and
the dispatcher agree on what a job will do before it is queued.

## Where the pieces live (server)

| Path | Responsibility |
| --- | --- |
| `app/Core/` | Router, request/response, session, CSRF, auth, config, DB, view, UI helpers |
| `app/Models/` | Thin data access + state transitions (`Job::claimForWorker`, `Post::rollUpStatus`, …) |
| `app/Services/SchedulerService.php` | Per-minute tick: promote due jobs, expand recurrences, release leases, sweep offline workers, roll up post statuses |
| `app/Services/PostDispatcher.php` | One independent job per Page, with skip reasons for unusable Pages |
| `app/Services/MediaService.php` | Upload validation by content sniffing, chunked/resumable writes, range streaming |
| `app/Controllers/WorkerController.php` | The whole worker API surface |
| `cron/scheduler.php`, `cron/housekeeping.php` | The two cron entry points |

## Where the pieces live (Windows app)

| Path | Responsibility |
| --- | --- |
| `src/desktop/` | Electron shell: tray, window, local dashboard API, notifications, auto-start |
| `src/worker/` | Registration, heartbeat, job claiming, concurrency, media cache, job runner |
| `src/browser/` | Browser lifecycle, session checks, challenge detection, uploads, verification |
| `src/facebook/` | Composer and account flows; `selectors/` holds every Facebook DOM detail |
| `src/api/` | HTTPS client with backoff, resume-capable media download, multipart uploads |
| `src/security/` | DPAPI-backed credential store (worker token only) |
| `src/runtime/` | Bundled runtime manifest, verification, and repair |
| `src/updater/` | Signature-checked, idle-aware auto-update |
