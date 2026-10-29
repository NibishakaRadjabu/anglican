CREATE TABLE IF NOT EXISTS staff_departures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id INT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    action_type ENUM('resignation', 'dismissal') NOT NULL,
    letter_or_reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_staff_departures_status_created (status, created_at),
    INDEX idx_staff_departures_worker_created (worker_id, created_at),
    CONSTRAINT fk_staff_departures_worker FOREIGN KEY (worker_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_departures_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_staff_departures_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
