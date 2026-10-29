CREATE TABLE IF NOT EXISTS parish_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requested_name VARCHAR(150) NOT NULL,
    location VARCHAR(255) NULL,
    requested_by INT UNSIGNED NOT NULL,
    requester_branch_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_parish_requests_status_created (status, created_at),
    CONSTRAINT fk_parish_requests_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_parish_requests_requester_branch FOREIGN KEY (requester_branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_parish_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
