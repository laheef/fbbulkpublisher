-- ============================================================================
-- LinkEasy Publisher — MySQL / MariaDB schema (production control plane)
-- ----------------------------------------------------------------------------
-- All timestamps are stored in UTC. Display conversion happens in the app layer.
-- Engine: InnoDB. Charset: utf8mb4 (emoji-safe, required by the post composer).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120)     NOT NULL,
  `email`           VARCHAR(190)     NOT NULL,
  `password_hash`   VARCHAR(255)     NOT NULL,
  `role`            ENUM('user','admin') NOT NULL DEFAULT 'user',
  `timezone`        VARCHAR(64)      NOT NULL DEFAULT 'UTC',
  `status`          ENUM('ACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  `last_login_at`   DATETIME         NULL,
  `last_login_ip`   VARCHAR(45)      NULL,
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- login_attempts  (brute-force throttling, per email+ip)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(190) NOT NULL,
  `ip_address`  VARCHAR(45)  NOT NULL,
  `successful`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`email`, `ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- facebook_accounts
-- NOTE: Facebook passwords are NEVER stored. `profile_ref` is an opaque
-- identifier of an encrypted, per-account browser profile that lives only on
-- the operator's Windows machine. It is meaningless outside that machine.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `facebook_accounts` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `label`            VARCHAR(120)    NOT NULL,
  `fb_account_name`  VARCHAR(190)    NULL,
  `fb_account_id`    VARCHAR(64)     NULL,
  `profile_ref`      VARCHAR(64)     NOT NULL,
  `worker_id`        BIGINT UNSIGNED NULL,
  `status`           ENUM('CONNECTED','DISCONNECTED','AUTH_REQUIRED','CHALLENGE_REQUIRED','DISABLED','ERROR')
                     NOT NULL DEFAULT 'DISCONNECTED',
  `last_verified_at` DATETIME        NULL,
  `last_error_code`  VARCHAR(64)     NULL,
  `last_error`       VARCHAR(500)    NULL,
  `page_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fb_accounts_profile` (`profile_ref`),
  KEY `idx_fb_accounts_user` (`user_id`),
  KEY `idx_fb_accounts_status` (`status`),
  KEY `idx_fb_accounts_worker` (`worker_id`),
  CONSTRAINT `fk_fb_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fb_accounts_worker` FOREIGN KEY (`worker_id`) REFERENCES `browser_workers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- facebook_pages
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `facebook_pages` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `account_id`          BIGINT UNSIGNED NOT NULL,
  `page_id`             VARCHAR(64)     NOT NULL,
  `page_name`           VARCHAR(190)    NOT NULL,
  `page_url`            VARCHAR(500)    NULL,
  `avatar_url`          VARCHAR(500)    NULL,
  `category`            VARCHAR(120)    NULL,
  `status`              ENUM('ENABLED','DISABLED','ERROR','AUTH_REQUIRED','CHALLENGE_REQUIRED')
                        NOT NULL DEFAULT 'ENABLED',
  `last_verified_at`    DATETIME        NULL,
  `last_published_at`   DATETIME        NULL,
  `last_published_url`  VARCHAR(500)    NULL,
  `scheduled_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `failed_count`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fb_pages_account_page` (`account_id`, `page_id`),
  KEY `idx_fb_pages_user` (`user_id`),
  KEY `idx_fb_pages_account` (`account_id`),
  KEY `idx_fb_pages_status` (`status`),
  CONSTRAINT `fk_fb_pages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fb_pages_account` FOREIGN KEY (`account_id`) REFERENCES `facebook_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- browser_workers  (one row per Windows installation/machine)
-- `token_hash` is a SHA-256 of the worker token. The plaintext token is shown
-- exactly once, at registration, and is stored on the machine via DPAPI.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `browser_workers` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`               BIGINT UNSIGNED NOT NULL,
  `installation_id`       CHAR(36)        NOT NULL,
  `worker_id`             CHAR(36)        NOT NULL,
  `name`                  VARCHAR(120)    NOT NULL,
  `os`                    VARCHAR(120)    NULL,
  `arch`                  VARCHAR(32)     NULL,
  `app_version`           VARCHAR(32)     NULL,
  `worker_version`        VARCHAR(32)     NULL,
  `playwright_version`    VARCHAR(32)     NULL,
  `browser_version`       VARCHAR(64)     NULL,
  `ffmpeg_version`        VARCHAR(64)     NULL,
  `cpu_pct`               DECIMAL(5,2)    NULL,
  `mem_mb`                INT UNSIGNED    NULL,
  `internet_ok`           TINYINT(1)      NOT NULL DEFAULT 1,
  `server_ok`             TINYINT(1)      NOT NULL DEFAULT 1,
  `status`                ENUM('INSTALLING','STARTING','ONLINE','BUSY','PAUSED','OFFLINE','UPDATING','ERROR')
                          NOT NULL DEFAULT 'OFFLINE',
  `is_enabled`            TINYINT(1)      NOT NULL DEFAULT 1,
  `max_concurrent_jobs`   TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `max_concurrent_browsers` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `current_job_id`        BIGINT UNSIGNED NULL,
  `pending_count`         INT UNSIGNED    NOT NULL DEFAULT 0,
  `token_hash`            CHAR(64)        NOT NULL,
  `token_prefix`          CHAR(8)         NOT NULL,
  `token_rotated_at`      DATETIME        NULL,
  `capabilities`          JSON            NULL,
  `last_heartbeat_at`     DATETIME        NULL,
  `connected_at`          DATETIME        NULL,
  `last_error`            VARCHAR(500)    NULL,
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workers_installation` (`installation_id`),
  UNIQUE KEY `uq_workers_worker_id` (`worker_id`),
  KEY `idx_workers_user` (`user_id`),
  KEY `idx_workers_status` (`status`),
  KEY `idx_workers_heartbeat` (`last_heartbeat_at`),
  CONSTRAINT `fk_workers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- media
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `kind`             ENUM('IMAGE','VIDEO') NOT NULL,
  `original_name`    VARCHAR(255)    NOT NULL,
  `stored_name`      VARCHAR(255)    NOT NULL,
  `relative_path`    VARCHAR(500)    NOT NULL,
  `mime_type`        VARCHAR(120)    NOT NULL,
  `size_bytes`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width`            INT UNSIGNED    NULL,
  `height`           INT UNSIGNED    NULL,
  `duration_s`       DECIMAL(10,3)   NULL,
  `fps`              DECIMAL(8,3)    NULL,
  `codec`            VARCHAR(64)     NULL,
  `aspect_ratio`     VARCHAR(16)     NULL,
  `checksum_sha256`  CHAR(64)        NULL,
  `thumbnail_path`   VARCHAR(500)    NULL,
  `probe_status`     ENUM('PENDING','PROBED','FAILED') NOT NULL DEFAULT 'PENDING',
  `probe_error`      VARCHAR(500)    NULL,
  `strategy`         ENUM('SERVER','LOCAL','EXTERNAL','HYBRID') NOT NULL DEFAULT 'SERVER',
  `external_url`     VARCHAR(1000)   NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `idx_media_user` (`user_id`),
  KEY `idx_media_kind` (`kind`),
  KEY `idx_media_created` (`created_at`),
  CONSTRAINT `fk_media_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- posts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `posts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36)        NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `kind`          ENUM('TEXT','IMAGE','VIDEO','REEL','MIXED') NOT NULL DEFAULT 'TEXT',
  `provider`      ENUM('BROWSER','OFFICIAL_API','SIMULATED') NOT NULL DEFAULT 'BROWSER',
  `caption`       TEXT            NULL,
  `hashtags`      VARCHAR(1000)   NULL,
  `link_url`      VARCHAR(1000)   NULL,
  `title`         VARCHAR(255)    NULL,
  `media_id`      BIGINT UNSIGNED NULL,
  `settings`      JSON            NULL,
  `status`        ENUM('DRAFT','SCHEDULED','QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING',
                       'VERIFYING','PUBLISHED','PARTIAL','FAILED','RETRYING','PAUSED',
                       'USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED','CANCELLED')
                  NOT NULL DEFAULT 'DRAFT',
  `scheduled_at`  DATETIME        NULL,
  `timezone`      VARCHAR(64)     NOT NULL DEFAULT 'UTC',
  `published_at`  DATETIME        NULL,
  `page_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posts_uuid` (`uuid`),
  KEY `idx_posts_user` (`user_id`),
  KEY `idx_posts_status` (`status`),
  KEY `idx_posts_scheduled` (`scheduled_at`),
  KEY `idx_posts_created` (`created_at`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- post_pages  (fan-out: one row per target Page)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `post_pages` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id`       BIGINT UNSIGNED NOT NULL,
  `page_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `status`        ENUM('PENDING','QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING',
                       'PUBLISHED','FAILED','RETRYING','PAUSED','USER_ACTION_REQUIRED',
                       'ACCOUNT_REAUTH_REQUIRED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `job_id`        BIGINT UNSIGNED NULL,
  `published_url` VARCHAR(1000)   NULL,
  `published_at`  DATETIME        NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_pages` (`post_id`, `page_id`),
  KEY `idx_post_pages_page` (`page_id`),
  KEY `idx_post_pages_status` (`status`),
  KEY `idx_post_pages_job` (`job_id`),
  CONSTRAINT `fk_post_pages_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_post_pages_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- scheduled_posts  (PHP is the scheduling authority)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_posts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `post_id`        BIGINT UNSIGNED NOT NULL,
  `page_id`        BIGINT UNSIGNED NULL,          -- NULL = all Pages attached to the post
  `timezone`       VARCHAR(64)     NOT NULL DEFAULT 'UTC',
  `scheduled_at`   DATETIME        NOT NULL,      -- UTC
  `recurrence`     ENUM('NONE','EVERY_X_MINUTES','EVERY_X_HOURS','CUSTOM') NOT NULL DEFAULT 'NONE',
  `interval_value` INT UNSIGNED    NULL,
  `stagger_seconds` INT UNSIGNED   NOT NULL DEFAULT 0, -- sequential bulk spacing between Pages
  `next_run_at`    DATETIME        NULL,          -- UTC
  `last_run_at`    DATETIME        NULL,
  `runs_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `max_runs`       INT UNSIGNED    NULL,
  `status`         ENUM('SCHEDULED','PAUSED','COMPLETED','CANCELLED','ERROR') NOT NULL DEFAULT 'SCHEDULED',
  `last_error`     VARCHAR(500)    NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sched_status_next` (`status`, `next_run_at`),
  KEY `idx_sched_user` (`user_id`),
  KEY `idx_sched_post` (`post_id`),
  KEY `idx_sched_scheduled_at` (`scheduled_at`),
  CONSTRAINT `fk_sched_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sched_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sched_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- jobs  (central queue authority — claimed atomically by Windows workers)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jobs` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)        NOT NULL,
  `idempotency_key`   CHAR(64)        NOT NULL,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `account_id`        BIGINT UNSIGNED NOT NULL,
  `page_id`           BIGINT UNSIGNED NOT NULL,
  `post_id`           BIGINT UNSIGNED NOT NULL,
  `post_page_id`      BIGINT UNSIGNED NULL,
  `media_id`          BIGINT UNSIGNED NULL,
  `provider`          ENUM('BROWSER','OFFICIAL_API','SIMULATED') NOT NULL DEFAULT 'BROWSER',
  `job_type`          ENUM('PUBLISH_POST','PUBLISH_IMAGE','PUBLISH_VIDEO','PUBLISH_REEL','VERIFY_SESSION','DETECT_PAGES','PROBE_MEDIA')
                      NOT NULL DEFAULT 'PUBLISH_POST',
  `priority`          TINYINT UNSIGNED NOT NULL DEFAULT 100,   -- lower = sooner
  `scheduled_at`      DATETIME        NOT NULL,                -- UTC
  `status`            ENUM('DRAFT','SCHEDULED','QUEUED','CLAIMED','PROCESSING','UPLOADING','PUBLISHING',
                           'VERIFYING','PUBLISHED','FAILED','RETRYING','PAUSED',
                           'USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED','CANCELLED')
                      NOT NULL DEFAULT 'QUEUED',
  `stage`             VARCHAR(64)     NULL,
  `progress_pct`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `attempts`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `next_attempt_at`   DATETIME        NULL,
  `worker_id`         BIGINT UNSIGNED NULL,
  `locked_at`         DATETIME        NULL,
  `lease_expires_at`  DATETIME        NULL,
  `started_at`        DATETIME        NULL,
  `completed_at`      DATETIME        NULL,
  `result_url`        VARCHAR(1000)   NULL,
  `error_code`        VARCHAR(64)     NULL,
  `error_message`     VARCHAR(1000)   NULL,
  `screenshot_path`   VARCHAR(500)    NULL,
  `trace_path`        VARCHAR(500)    NULL,
  `payload`           JSON            NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_uuid` (`uuid`),
  UNIQUE KEY `uq_jobs_idempotency` (`idempotency_key`),
  KEY `idx_jobs_status_sched` (`status`, `scheduled_at`),
  KEY `idx_jobs_claim` (`status`, `next_attempt_at`, `priority`, `scheduled_at`),
  KEY `idx_jobs_worker` (`worker_id`),
  KEY `idx_jobs_account` (`account_id`),
  KEY `idx_jobs_page` (`page_id`),
  KEY `idx_jobs_post` (`post_id`),
  KEY `idx_jobs_created` (`created_at`),
  KEY `idx_jobs_lease` (`lease_expires_at`),
  CONSTRAINT `fk_jobs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_account` FOREIGN KEY (`account_id`) REFERENCES `facebook_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_worker` FOREIGN KEY (`worker_id`) REFERENCES `browser_workers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- job_events  (immutable state-transition audit trail)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`     BIGINT UNSIGNED NOT NULL,
  `from_state` VARCHAR(32)     NULL,
  `to_state`   VARCHAR(32)     NOT NULL,
  `stage`      VARCHAR(64)     NULL,
  `message`    VARCHAR(1000)   NULL,
  `actor`      VARCHAR(64)     NOT NULL DEFAULT 'system',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_events_job` (`job_id`, `created_at`),
  CONSTRAINT `fk_job_events_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notifications
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `level`      ENUM('info','success','warning','error','action_required') NOT NULL DEFAULT 'info',
  `title`      VARCHAR(190)    NOT NULL,
  `body`       VARCHAR(1000)   NULL,
  `link`       VARCHAR(500)    NULL,
  `job_id`     BIGINT UNSIGNED NULL,
  `read_at`    DATETIME        NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `read_at`),
  KEY `idx_notifications_created` (`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- activity_logs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NULL,
  `actor_type`  ENUM('user','worker','system') NOT NULL DEFAULT 'system',
  `actor_id`    BIGINT UNSIGNED NULL,
  `action`      VARCHAR(120)    NOT NULL,
  `entity_type` VARCHAR(64)     NULL,
  `entity_id`   BIGINT UNSIGNED NULL,
  `ip_address`  VARCHAR(45)     NULL,
  `meta`        JSON            NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`, `created_at`),
  KEY `idx_activity_entity` (`entity_type`, `entity_id`),
  KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- worker_logs  (mirrored from the Windows machine, redacted before storage)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `worker_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_id`  BIGINT UNSIGNED NULL,
  `user_id`    BIGINT UNSIGNED NULL,
  `channel`    ENUM('app','worker','browser','scheduler','errors') NOT NULL DEFAULT 'worker',
  `level`      ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  `message`    VARCHAR(2000)   NOT NULL,
  `context`    JSON            NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_worker_logs_worker` (`worker_id`, `created_at`),
  KEY `idx_worker_logs_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_worker_logs_worker` FOREIGN KEY (`worker_id`) REFERENCES `browser_workers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings  (global / per-user / per-worker)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`      ENUM('global','user','worker') NOT NULL DEFAULT 'global',
  `user_id`    BIGINT UNSIGNED NULL,
  `worker_id`  BIGINT UNSIGNED NULL,
  `key_name`   VARCHAR(120)    NOT NULL,
  `value`      TEXT            NULL,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_scope` (`scope`, `user_id`, `worker_id`, `key_name`),
  KEY `idx_settings_key` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
