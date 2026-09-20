<?php
declare(strict_types=1);

namespace App\Core;

/**
 * SQLite mirror of database/schema.sql.
 *
 * Used for the demo mode, the developer sandbox, and the automated test-suite,
 * so the entire platform can be exercised without a MySQL server. Column types
 * and semantics match the MySQL schema 1:1 (ENUMs become CHECK constraints,
 * DATETIMEs are stored as UTC 'YYYY-MM-DD HH:MM:SS' strings).
 */
final class SqliteSchema
{
    /** @return list<string> */
    public static function statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user','admin')),
                timezone TEXT NOT NULL DEFAULT 'UTC',
                status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','SUSPENDED')),
                last_login_at TEXT NULL,
                last_login_ip TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",

            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                successful INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts (email, ip_address, created_at)",

            "CREATE TABLE IF NOT EXISTS browser_workers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                installation_id TEXT NOT NULL UNIQUE,
                worker_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                os TEXT NULL, arch TEXT NULL,
                app_version TEXT NULL, worker_version TEXT NULL,
                playwright_version TEXT NULL, browser_version TEXT NULL, ffmpeg_version TEXT NULL,
                cpu_pct REAL NULL, mem_mb INTEGER NULL,
                internet_ok INTEGER NOT NULL DEFAULT 1,
                server_ok INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'OFFLINE'
                    CHECK (status IN ('INSTALLING','STARTING','ONLINE','BUSY','PAUSED','OFFLINE','UPDATING','ERROR')),
                is_enabled INTEGER NOT NULL DEFAULT 1,
                max_concurrent_jobs INTEGER NOT NULL DEFAULT 2,
                max_concurrent_browsers INTEGER NOT NULL DEFAULT 2,
                current_job_id INTEGER NULL,
                pending_count INTEGER NOT NULL DEFAULT 0,
                token_hash TEXT NOT NULL,
                token_prefix TEXT NOT NULL,
                token_rotated_at TEXT NULL,
                capabilities TEXT NULL,
                last_heartbeat_at TEXT NULL,
                connected_at TEXT NULL,
                last_error TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_workers_status ON browser_workers (status)",
            "CREATE INDEX IF NOT EXISTS idx_workers_heartbeat ON browser_workers (last_heartbeat_at)",

            "CREATE TABLE IF NOT EXISTS facebook_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                label TEXT NOT NULL,
                fb_account_name TEXT NULL,
                fb_account_id TEXT NULL,
                profile_ref TEXT NOT NULL UNIQUE,
                worker_id INTEGER NULL REFERENCES browser_workers(id) ON DELETE SET NULL,
                status TEXT NOT NULL DEFAULT 'DISCONNECTED'
                    CHECK (status IN ('CONNECTED','DISCONNECTED','AUTH_REQUIRED','CHALLENGE_REQUIRED','DISABLED','ERROR')),
                last_verified_at TEXT NULL,
                last_error_code TEXT NULL,
                last_error TEXT NULL,
                page_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_fb_accounts_user ON facebook_accounts (user_id)",
            "CREATE INDEX IF NOT EXISTS idx_fb_accounts_status ON facebook_accounts (status)",

            "CREATE TABLE IF NOT EXISTS facebook_pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                account_id INTEGER NOT NULL REFERENCES facebook_accounts(id) ON DELETE CASCADE,
                page_id TEXT NOT NULL,
                page_name TEXT NOT NULL,
                page_url TEXT NULL,
                avatar_url TEXT NULL,
                category TEXT NULL,
                status TEXT NOT NULL DEFAULT 'ENABLED'
                    CHECK (status IN ('ENABLED','DISABLED','ERROR','AUTH_REQUIRED','CHALLENGE_REQUIRED')),
                last_verified_at TEXT NULL,
                last_published_at TEXT NULL,
                last_published_url TEXT NULL,
                scheduled_count INTEGER NOT NULL DEFAULT 0,
                failed_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (account_id, page_id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_fb_pages_user ON facebook_pages (user_id)",
            "CREATE INDEX IF NOT EXISTS idx_fb_pages_account ON facebook_pages (account_id)",
            "CREATE INDEX IF NOT EXISTS idx_fb_pages_status ON facebook_pages (status)",

            "CREATE TABLE IF NOT EXISTS media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                kind TEXT NOT NULL CHECK (kind IN ('IMAGE','VIDEO')),
                original_name TEXT NOT NULL,
                stored_name TEXT NOT NULL,
                relative_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                width INTEGER NULL, height INTEGER NULL,
                duration_s REAL NULL, fps REAL NULL, codec TEXT NULL,
                aspect_ratio TEXT NULL,
                checksum_sha256 TEXT NULL,
                thumbnail_path TEXT NULL,
                probe_status TEXT NOT NULL DEFAULT 'PENDING' CHECK (probe_status IN ('PENDING','PROBED','FAILED')),
                probe_error TEXT NULL,
                strategy TEXT NOT NULL DEFAULT 'SERVER' CHECK (strategy IN ('SERVER','LOCAL','EXTERNAL','HYBRID')),
                external_url TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                deleted_at TEXT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_media_user ON media (user_id)",
            "CREATE INDEX IF NOT EXISTS idx_media_kind ON media (kind, created_at)",

            "CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                kind TEXT NOT NULL DEFAULT 'TEXT' CHECK (kind IN ('TEXT','IMAGE','VIDEO','REEL','MIXED')),
                provider TEXT NOT NULL DEFAULT 'BROWSER' CHECK (provider IN ('BROWSER','OFFICIAL_API','SIMULATED')),
                caption TEXT NULL, hashtags TEXT NULL, link_url TEXT NULL, title TEXT NULL,
                media_id INTEGER NULL REFERENCES media(id) ON DELETE SET NULL,
                settings TEXT NULL,
                status TEXT NOT NULL DEFAULT 'DRAFT'
                    CHECK (status IN ('DRAFT','SCHEDULED','QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING',
                                      'VERIFYING','PUBLISHED','PARTIAL','FAILED','RETRYING','PAUSED',
                                      'USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED','CANCELLED')),
                scheduled_at TEXT NULL,
                timezone TEXT NOT NULL DEFAULT 'UTC',
                published_at TEXT NULL,
                page_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_posts_user ON posts (user_id)",
            "CREATE INDEX IF NOT EXISTS idx_posts_status ON posts (status, scheduled_at)",

            "CREATE TABLE IF NOT EXISTS post_pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                page_id INTEGER NOT NULL REFERENCES facebook_pages(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                job_id INTEGER NULL,
                published_url TEXT NULL,
                published_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (post_id, page_id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_post_pages_page ON post_pages (page_id)",

            "CREATE TABLE IF NOT EXISTS scheduled_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                page_id INTEGER NULL REFERENCES facebook_pages(id) ON DELETE CASCADE,
                timezone TEXT NOT NULL DEFAULT 'UTC',
                scheduled_at TEXT NOT NULL,
                recurrence TEXT NOT NULL DEFAULT 'NONE'
                    CHECK (recurrence IN ('NONE','EVERY_X_MINUTES','EVERY_X_HOURS','CUSTOM')),
                interval_value INTEGER NULL,
                stagger_seconds INTEGER NOT NULL DEFAULT 0,
                next_run_at TEXT NULL,
                last_run_at TEXT NULL,
                runs_count INTEGER NOT NULL DEFAULT 0,
                max_runs INTEGER NULL,
                status TEXT NOT NULL DEFAULT 'SCHEDULED'
                    CHECK (status IN ('SCHEDULED','PAUSED','COMPLETED','CANCELLED','ERROR')),
                last_error TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_sched_status_next ON scheduled_posts (status, next_run_at)",

            "CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                idempotency_key TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                account_id INTEGER NOT NULL REFERENCES facebook_accounts(id) ON DELETE CASCADE,
                page_id INTEGER NOT NULL REFERENCES facebook_pages(id) ON DELETE CASCADE,
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                post_page_id INTEGER NULL,
                media_id INTEGER NULL REFERENCES media(id) ON DELETE SET NULL,
                provider TEXT NOT NULL DEFAULT 'BROWSER' CHECK (provider IN ('BROWSER','OFFICIAL_API','SIMULATED')),
                job_type TEXT NOT NULL DEFAULT 'PUBLISH_POST'
                    CHECK (job_type IN ('PUBLISH_POST','PUBLISH_IMAGE','PUBLISH_VIDEO','PUBLISH_REEL','VERIFY_SESSION','DETECT_PAGES','PROBE_MEDIA')),
                priority INTEGER NOT NULL DEFAULT 100,
                scheduled_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'QUEUED'
                    CHECK (status IN ('DRAFT','SCHEDULED','QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING',
                                      'VERIFYING','PUBLISHED','FAILED','RETRYING','PAUSED',
                                      'USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED','CANCELLED')),
                stage TEXT NULL,
                progress_pct INTEGER NOT NULL DEFAULT 0,
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                next_attempt_at TEXT NULL,
                worker_id INTEGER NULL REFERENCES browser_workers(id) ON DELETE SET NULL,
                locked_at TEXT NULL,
                lease_expires_at TEXT NULL,
                started_at TEXT NULL,
                completed_at TEXT NULL,
                result_url TEXT NULL,
                error_code TEXT NULL,
                error_message TEXT NULL,
                screenshot_path TEXT NULL,
                trace_path TEXT NULL,
                payload TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_jobs_status_sched ON jobs (status, scheduled_at)",
            "CREATE INDEX IF NOT EXISTS idx_jobs_claim ON jobs (status, next_attempt_at, priority, scheduled_at)",
            "CREATE INDEX IF NOT EXISTS idx_jobs_worker ON jobs (worker_id)",
            "CREATE INDEX IF NOT EXISTS idx_jobs_account ON jobs (account_id)",
            "CREATE INDEX IF NOT EXISTS idx_jobs_page ON jobs (page_id)",
            "CREATE INDEX IF NOT EXISTS idx_jobs_lease ON jobs (lease_expires_at)",

            "CREATE TABLE IF NOT EXISTS job_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
                from_state TEXT NULL,
                to_state TEXT NOT NULL,
                stage TEXT NULL,
                message TEXT NULL,
                actor TEXT NOT NULL DEFAULT 'system',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_job_events_job ON job_events (job_id, created_at)",

            "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                level TEXT NOT NULL DEFAULT 'info' CHECK (level IN ('info','success','warning','error','action_required')),
                title TEXT NOT NULL,
                body TEXT NULL,
                link TEXT NULL,
                job_id INTEGER NULL,
                read_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications (user_id, read_at, created_at)",

            "CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                actor_type TEXT NOT NULL DEFAULT 'system' CHECK (actor_type IN ('user','worker','system')),
                actor_id INTEGER NULL,
                action TEXT NOT NULL,
                entity_type TEXT NULL,
                entity_id INTEGER NULL,
                ip_address TEXT NULL,
                meta TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_activity_user ON activity_logs (user_id, created_at)",
            "CREATE INDEX IF NOT EXISTS idx_activity_entity ON activity_logs (entity_type, entity_id)",

            "CREATE TABLE IF NOT EXISTS worker_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                worker_id INTEGER NULL REFERENCES browser_workers(id) ON DELETE SET NULL,
                user_id INTEGER NULL,
                channel TEXT NOT NULL DEFAULT 'worker' CHECK (channel IN ('app','worker','browser','scheduler','errors')),
                level TEXT NOT NULL DEFAULT 'info' CHECK (level IN ('debug','info','warn','error')),
                message TEXT NOT NULL,
                context TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_worker_logs_worker ON worker_logs (worker_id, created_at)",

            "CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT NOT NULL DEFAULT 'global' CHECK (scope IN ('global','user','worker')),
                user_id INTEGER NULL,
                worker_id INTEGER NULL,
                key_name TEXT NOT NULL,
                value TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (scope, user_id, worker_id, key_name)
            )",
        ];
    }

    public static function install(Database $db): void
    {
        foreach (self::statements() as $sql) {
            $db->pdo()->exec($sql);
        }
    }
}
