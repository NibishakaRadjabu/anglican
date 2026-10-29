USE anglican_church;

CREATE TABLE branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    branch_type VARCHAR(50) NOT NULL DEFAULT 'Parish',
    location VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO branches (name, branch_type)
VALUES ('Headquarters', 'Headquarters');

ALTER TABLE users
    ADD COLUMN branch_id INT UNSIGNED NULL;

ALTER TABLE members
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE clergy
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE baptisms
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE confirmations
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE marriages
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE funerals
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE attendance
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE offerings
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE expenses
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE budgets
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE events
    ADD COLUMN branch_id INT UNSIGNED NULL;
ALTER TABLE announcements
    ADD COLUMN branch_id INT UNSIGNED NULL;

INSERT IGNORE INTO branches (name, branch_type)
SELECT DISTINCT TRIM(branch_name), COALESCE(NULLIF(TRIM(church_type), ''), 'Branch')
FROM members
WHERE branch_name IS NOT NULL AND TRIM(branch_name) <> '';

INSERT IGNORE INTO branches (name, branch_type)
SELECT DISTINCT TRIM(branch_name), COALESCE(NULLIF(TRIM(church_type), ''), 'Branch')
FROM clergy
WHERE branch_name IS NOT NULL AND TRIM(branch_name) <> '';

UPDATE members m
LEFT JOIN branches b ON b.name = NULLIF(TRIM(m.branch_name), '')
SET m.branch_id = COALESCE(b.id, (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1));

UPDATE clergy c
LEFT JOIN branches b ON b.name = NULLIF(TRIM(c.branch_name), '')
SET c.branch_id = COALESCE(b.id, (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1));

UPDATE baptisms r
JOIN members m ON m.id = r.member_id
SET r.branch_id = m.branch_id;
UPDATE confirmations r
JOIN members m ON m.id = r.member_id
SET r.branch_id = m.branch_id;
UPDATE funerals r
JOIN members m ON m.id = r.member_id
SET r.branch_id = m.branch_id;
UPDATE attendance r
JOIN members m ON m.id = r.member_id
SET r.branch_id = m.branch_id;
UPDATE marriages r
LEFT JOIN members m ON m.id = COALESCE(r.spouse_one_id, r.spouse_two_id)
SET r.branch_id = COALESCE(m.branch_id, (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1));

UPDATE offerings SET branch_id = (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1);
UPDATE expenses SET branch_id = (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1);
UPDATE budgets SET branch_id = (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1);
UPDATE events SET branch_id = (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1);
UPDATE announcements SET branch_id = (SELECT id FROM branches WHERE name = 'Headquarters' LIMIT 1);

UPDATE users u
JOIN members m ON m.id = u.member_id
SET u.branch_id = m.branch_id
WHERE u.role = 'member';

ALTER TABLE users
    ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

ALTER TABLE members
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_members_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE clergy
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_clergy_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE baptisms
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_baptisms_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE confirmations
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_confirmations_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE marriages
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_marriages_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE funerals
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_funerals_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE attendance
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_attendance_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE offerings
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_offerings_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE expenses
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE budgets
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_budgets_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE events
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_events_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE announcements
    MODIFY branch_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_announcements_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
