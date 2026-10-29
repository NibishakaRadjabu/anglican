CREATE TABLE IF NOT EXISTS login_attempts (
    throttle_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_login_attempts_updated (updated_at)
) ENGINE=InnoDB;
