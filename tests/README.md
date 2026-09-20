# Tests

Two suites, no external tooling: no Composer, no PHPUnit, no Jest. They run on a machine that has
only PHP and Node.

```bash
./tests/run.sh                      # everything that can run offline
./tests/run.sh php                  # server suite only
./tests/run.sh node                 # desktop/worker suite only
./tests/run.sh integration          # the live end-to-end contract (needs a running server)
```

## php/ — server behaviour

```bash
php tests/php/run.php               # all
php tests/php/run.php JobLifecycle  # only files whose name matches
```

Runs against a throwaway SQLite database in the system temp directory; nothing touches MySQL,
Facebook, or the network. `LINKEASY_TEST_KEEP=1` keeps the database for inspection and
`LINKEASY_TEST_TRACE=1` prints stack traces.

| File | Covers |
| --- | --- |
| `CoreTest.php` | idempotency keys, token generation, redaction (cookies, bearer tokens, `access_token`-style prefixed keys), safe file names, path-traversal refusal, failure-code taxonomy, status tones, JSON helpers |
| `JobLifecycleTest.php` | atomic claims, duplicate-queue refusal by idempotency key, lease expiry and recovery, refusal of unverified success, idempotent completion, retry cap with backoff, permanent errors never retried, challenges pausing for a human, tenant isolation, cancellation, event trail, post roll-up |
| `MediaSecurityTest.php` | content sniffing beats the extension (a PHP payload named `.png` is rejected), oversized uploads, stored-name regeneration and permissions, referenced media is never pruned, per-tenant isolation |
| `WorkerProtocolTest.php` | token hashing and rotation, disabled machines, lease release and offline sweeps, scheduler promotion, repeated ticks, housekeeping safety, worker throttling |

## windows-app/tests — desktop and worker

```bash
cd windows-app
node --test tests/unit/                                   # offline, no browser needed
node --test tests/integration/protocol.test.js            # skipped unless configured
```

| File | Covers |
| --- | --- |
| `unit/redaction.test.js` | the logging guarantee: cookies, bearer tokens and password-shaped values never reach disk |
| `unit/selectors.test.js` | every Facebook selector entry is well-formed, and the ones publishing depends on are marked required |
| `unit/media-cache.test.js` | cache naming, cache reuse, truncated downloads rejected, byte-budget pruning that never removes a just-written file |
| `unit/credentials.test.js` | DPAPI/AES-GCM round-trip, no plaintext on disk, no password material in the stored payload, clean removal |
| `unit/settings.test.js` | safe defaults, server config overriding only the keys the server controls, persistence |
| `integration/protocol.test.js` | the real HTTP contract: enrol → heartbeat → claim → progress → complete (rejected without evidence) → duplicate completion → failure reporting → challenge reporting → log redaction |

### Running the integration suite

It is skipped unless a server is reachable, so `npm test` stays green on a laptop:

```bash
LINKEASY_TEST_SERVER=http://127.0.0.1:8080 \
LINKEASY_TEST_EMAIL=demo@linkeasy.local \
LINKEASY_TEST_PASSWORD='Demo-Publishing-2026!' \
node --test tests/integration/protocol.test.js
```

The claim/complete steps need at least two queued jobs; the suite skips them with a diagnostic when
the queue is empty. To queue work on the demo server, sign in at `/login` and create a post through
`POST /posts` with `intent=publish`, or press **Publish now** in the composer.

## What is deliberately not covered here

Anything that requires real Facebook credentials or a real Chromium: publishing to a live Page,
composer interaction, and challenge handling. Those are verified manually against a test Page —
see `docs/windows-app.md` for the operator-level checklist. The selector registry, the challenge
taxonomy and the verification logic are unit-tested so a regression surfaces before it reaches a
live Page.
