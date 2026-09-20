# Deployment

## 1 · Server requirements

| Component | Minimum |
| --- | --- |
| PHP | 8.2 (8.3+ recommended) with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `zip`, `curl` |
| Database | MySQL 8.0 or MariaDB 10.6+ (SQLite is supported for a single-operator install or a demo) |
| Web server | nginx or Apache with PHP-FPM |
| Disk | Media storage plus headroom for exports; videos dominate |
| Cron | Two entries (below) |

No Composer, no build step and no Node on the server: the PHP application ships as plain files.

## 2 · Install

```bash
# 1. Files
sudo mkdir -p /var/www/linkeasy && cd /var/www/linkeasy
# copy the php-app directory here, then:
sudo chown -R www-data:www-data /var/www/linkeasy

# 2. Configuration
cp php-app/.env.example php-app/.env
```

Edit `.env`:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://publisher.example.com

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=linkeasy
DB_USERNAME=linkeasy
DB_PASSWORD=…            # generated, not shared

SESSION_SECURE=true      # requires HTTPS
SESSION_LIFETIME=28800

PUBLISHING_PROVIDER=browser
MEDIA_STRATEGY=SERVER
LOG_LEVEL=warning
```

Any setting can also be placed in `php-app/config/local.php` (see `config/local.php.example`), which
overrides `config/config.php` without touching tracked files.

```bash
# 3. Database
mysql -u root -p -e "CREATE DATABASE linkeasy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'linkeasy'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON linkeasy.* TO 'linkeasy'@'localhost';"

cd php-app
php bin/console.php migrate

# 4. Writable paths
mkdir -p storage/{logs,uploads,exports,framework}
chmod -R 0770 storage && chown -R www-data:www-data storage

# 5. First administrator
php bin/console.php user:create "Your Name" you@example.com "A-strong-password" Asia/Karachi
php bin/console.php user:admin you@example.com
```

## 3 · Web server

Point the document root at `php-app/public` — never at the application root. A ready-to-edit nginx
configuration is in `docs/nginx-example.conf`; the equivalent Apache vhost needs
`AllowOverride All` for the bundled `.htaccess`.

Confirm the install:

```bash
curl -s https://publisher.example.com/health | jq
# { "ok": true, "checks": { "database": "ok", "storage": "ok", "logs": "ok" } }
```

`/health` is public and intentionally minimal: it reports service checks but nothing about users,
jobs or media. Add `?detailed=1` on a trusted network to include queue depth and online workers.

## 4 · Cron

```cron
# Promote due jobs, expand recurrences, release dead leases, sweep offline machines
* * * * * www-data php /var/www/linkeasy/php-app/cron/scheduler.php >> /var/log/linkeasy-scheduler.log 2>&1

# Nightly retention: prune old logs, exports and orphaned files
5 3 * * * www-data php /var/www/linkeasy/php-app/cron/housekeeping.php >> /var/log/linkeasy-housekeeping.log 2>&1
```

Both scripts print a JSON summary, which makes `grep` and monitoring straightforward:

```json
{ "promoted_jobs": 4, "expanded_schedules": 2, "released_leases": 0, "workers_offline": 0, "errors": 0, "duration_ms": 12 }
```

Also install `php bin/console.php tick` as a systemd timer if you prefer not to use crontab.

## 5 · Hardening

- **HTTPS only.** `SESSION_SECURE=true`; the worker refuses to weaken TLS verification.
- **Least privilege.** The web user owns `storage/`; nothing else needs to be writable.
- **Media outside the web root.** Uploads land in `storage/uploads` and are served through
  `GET /media/{id}/file`, which enforces ownership and supports range requests.
- **Firewall.** The server needs 443 inbound only. The operator PC needs **no** inbound port: it polls.
- **Backups.** Dump the database and `storage/uploads` together — a database row without its media is
  useless, and media without the row is unreferenced.
  ```bash
  mysqldump --single-transaction linkeasy | gzip > linkeasy-db-$(date +%F).sql.gz
  tar czf linkeasy-media-$(date +%F).tar.gz -C php-app/storage uploads
  ```
- **Secrets.** `config/local.php` and `.env` hold database credentials only. Facebook credentials
  never exist on the server in any form.

## 6 · Upgrades

1. Back up, per above.
2. Deploy the new `php-app/` over the old one (keep `config/local.php`, `.env` and `storage/`).
3. `php bin/console.php migrate` — additive migrations, safe to run while the scheduler is idle.
4. Check `/health` and **Admin → Diagnostics**.

Windows clients update themselves from your signed feed (see `docs/windows-app.md`); an old client
keeps working because the worker API is versioned by capability, not by build number.

## 7 · Scaling notes

- One PHP server handles many operators; the scheduling cost is dominated by the per-minute tick.
- Media throughput is bounded by disk and network. Keep uploads on local disk rather than a network
  share unless you have measured the latency.
- Every connected PC publishes in parallel; the queue self-balances because claims are atomic and
  leases are re-issued when a machine goes quiet.
- Do **not** raise the worker concurrency limits on a whim: each concurrent browser costs several
  hundred MB of RAM on the operator's machine, and Facebook's own pacing is per account, not per
  worker.

## 8 · Troubleshooting

| Symptom | Check |
| --- | --- |
| Jobs stay `QUEUED` | Is cron running? `php bin/console.php tick` should show `promoted_jobs > 0` |
| Worker shows `OFFLINE` | Heartbeat interval, server URL reachability, firewall, and `GET /health` |
| Jobs pile up in `USER_ACTION_REQUIRED` | Open **Queue → the job**: it names the security check a human must complete |
| Uploads rejected | MIME sniffing is strict; the file must really be a JPG/PNG/WEBP/MP4/MOV |
| `FACEBOOK_UI_CHANGED` | Facebook changed its interface: update `windows-app/src/facebook/selectors/` |
| Diagnostics export fails | Ensure `storage/exports` exists and is writable by the web user |
