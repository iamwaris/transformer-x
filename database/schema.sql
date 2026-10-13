-- Transformer X database schema (MariaDB 10.4+ / MySQL 8+).
-- Applied by bin/install.php. Uploaded and generated files are never stored; only statistics are kept.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username              VARCHAR(50)  NOT NULL,
    display_name          VARCHAR(100) NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    role                  ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active             TINYINT(1)   NOT NULL DEFAULT 1,
    must_change_password  TINYINT(1)   NOT NULL DEFAULT 1,
    failed_logins         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until          DATETIME     NULL,
    theme                 ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    last_login_at         DATETIME     NULL,
    password_changed_at   DATETIME     NULL,
    created_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every sign-in attempt, for rate limiting by IP address.
CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45)  NOT NULL,
    username    VARCHAR(100) NOT NULL,
    success     TINYINT(1)   NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_attempts_ip (ip, created_at),
    KEY ix_login_attempts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per uploaded file. Kept permanently as history/statistics.
--   status: rejected  = the file itself was unusable (wrong format, bad headers)
--           blocked   = rows have errors, conversion not allowed
--           ready     = previewed, waiting for the user to convert
--           converted / cancelled / expired = closed; staged rows deleted
CREATE TABLE IF NOT EXISTS imports (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    file_size          INT UNSIGNED NOT NULL,
    file_sha256        CHAR(64)     NOT NULL,
    status             ENUM('rejected','blocked','ready','converted','cancelled','expired') NOT NULL,
    data_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    blank_rows         INT UNSIGNED NOT NULL DEFAULT 0,
    error_count        INT UNSIGNED NOT NULL DEFAULT 0,
    warning_count      INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json       LONGTEXT     NOT NULL,
    file_issues_json   LONGTEXT     NOT NULL,
    engine_version     VARCHAR(20)  NOT NULL,
    analysis_ms        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at          DATETIME     NULL,
    KEY ix_imports_user (user_id, created_at),
    KEY ix_imports_created (created_at),
    KEY ix_imports_status (status, created_at),
    CONSTRAINT fk_imports_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Error/warning counts per import, by code (for "most common problems" statistics).
CREATE TABLE IF NOT EXISTS import_issue_counts (
    import_id  INT UNSIGNED NOT NULL,
    severity   ENUM('error','warning') NOT NULL,
    code       VARCHAR(50)  NOT NULL,
    count      INT UNSIGNED NOT NULL,
    PRIMARY KEY (import_id, severity, code),
    CONSTRAINT fk_issue_counts_import FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parsed rows held only while a preview is open. Deleted on convert, cancel or expiry.
CREATE TABLE IF NOT EXISTS import_rows (
    import_id    INT UNSIGNED NOT NULL,
    seq          INT UNSIGNED NOT NULL,
    row_no       INT UNSIGNED NOT NULL,
    status       ENUM('ok','warning','error') NOT NULL,
    search_text  VARCHAR(600) NOT NULL DEFAULT '',
    data_json    LONGTEXT     NOT NULL,
    PRIMARY KEY (import_id, seq),
    KEY ix_import_rows_status (import_id, status, seq),
    KEY ix_import_rows_row (import_id, row_no),
    CONSTRAINT fk_import_rows_import FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per successful conversion. Kept permanently.
CREATE TABLE IF NOT EXISTS conversions (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_id                INT UNSIGNED NOT NULL,
    user_id                  INT UNSIGNED NOT NULL,
    output_filename          VARCHAR(100) NOT NULL,
    rows_out                 INT UNSIGNED NOT NULL,
    total_cents              BIGINT       NOT NULL,
    zero_price_rows          INT UNSIGNED NOT NULL,
    blank_price_rows         INT UNSIGNED NOT NULL,
    negative_price_rows      INT UNSIGNED NOT NULL,
    attributes_dropped_rows  INT UNSIGNED NOT NULL,
    attribute_count          INT UNSIGNED NOT NULL,
    output_size              INT UNSIGNED NOT NULL,
    output_sha256            CHAR(64)     NOT NULL,
    duration_ms              INT UNSIGNED NOT NULL,
    engine_version           VARCHAR(20)  NOT NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conversions_import (import_id),
    KEY ix_conversions_user (user_id, created_at),
    KEY ix_conversions_created (created_at),
    CONSTRAINT fk_conversions_import FOREIGN KEY (import_id) REFERENCES imports (id),
    CONSTRAINT fk_conversions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-conversion totals by Product Category and by Product Type.
CREATE TABLE IF NOT EXISTS conversion_breakdown (
    conversion_id    INT UNSIGNED NOT NULL,
    dimension        ENUM('category','product_type') NOT NULL,
    name             VARCHAR(255) NOT NULL,
    row_count        INT UNSIGNED NOT NULL,
    total_cents      BIGINT       NOT NULL,
    zero_price_rows  INT UNSIGNED NOT NULL,
    PRIMARY KEY (conversion_id, dimension, name),
    CONSTRAINT fk_breakdown_conversion FOREIGN KEY (conversion_id) REFERENCES conversions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(50)  NOT NULL,
    target_type  VARCHAR(30)  NULL,
    target_id    INT UNSIGNED NULL,
    details_json LONGTEXT     NULL,
    ip           VARCHAR(45)  NOT NULL,
    user_agent   VARCHAR(255) NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_audit_created (created_at),
    KEY ix_audit_user (user_id, created_at),
    KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name        VARCHAR(50)  NOT NULL PRIMARY KEY,
    value       VARCHAR(255) NOT NULL,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value) VALUES
    ('max_upload_mb', '20'),
    ('max_rows', '20000'),
    ('preview_expiry_hours', '24'),
    ('session_idle_minutes', '30'),
    ('lockout_attempts', '5'),
    ('lockout_minutes', '15');
