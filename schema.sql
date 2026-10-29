CREATE DATABASE IF NOT EXISTS anglican CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE anglican;

CREATE TABLE IF NOT EXISTS branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    branch_type VARCHAR(50) NOT NULL DEFAULT 'Parish',
    location VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    gender VARCHAR(30) NULL,
    date_of_birth DATE NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    joined_on DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_members_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    role ENUM('bishop','archdeacon','priest','assistant_clergy','parish_council','secretary','treasurer','ministry_leader','member') NOT NULL,
    branch_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_profile_pictures (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    image_mime ENUM('image/jpeg', 'image/png', 'image/webp') NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_pictures_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS login_attempts (
    throttle_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_login_attempts_updated (updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'generic',
    data_json TEXT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_notifications_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clergy (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    title VARCHAR(50) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    parish VARCHAR(150) NULL,
    branch_id INT UNSIGNED NOT NULL,
    responsibilities TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_clergy_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS baptisms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    event_date DATE NOT NULL,
    officiant VARCHAR(150) NULL,
    godparents VARCHAR(255) NULL,
    church VARCHAR(150) NULL,
    notes TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
    CONSTRAINT fk_baptisms_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS confirmations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    event_date DATE NOT NULL,
    officiant VARCHAR(150) NULL,
    sponsor VARCHAR(150) NULL,
    church VARCHAR(150) NULL,
    notes TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
    CONSTRAINT fk_confirmations_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marriages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spouse_one_id INT UNSIGNED NULL,
    spouse_one_name VARCHAR(150) NOT NULL,
    spouse_two_id INT UNSIGNED NULL,
    spouse_two_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    officiant VARCHAR(150) NULL,
    church VARCHAR(150) NULL,
    notes TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (spouse_one_id) REFERENCES members(id) ON DELETE SET NULL,
    FOREIGN KEY (spouse_two_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_marriages_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS funerals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    event_date DATE NOT NULL,
    officiant VARCHAR(150) NULL,
    notes TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
    CONSTRAINT fk_funerals_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    service_date DATE NOT NULL,
    service_name VARCHAR(120) NOT NULL,
    group_name VARCHAR(120) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Present',
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_attendance_date (service_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offerings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received_on DATE NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(50) NULL,
    reference VARCHAR(150) NULL,
    notes TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_offerings_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_offerings_date (received_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spent_on DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payee VARCHAR(150) NULL,
    description TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_expenses_date (spent_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS budgets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_budgets_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_budgets_period (period_start, period_end)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    event_type VARCHAR(50) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    location VARCHAR(180) NULL,
    description TEXT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_events_start (starts_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    publish_at DATETIME NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_announcements_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    INDEX idx_announcements_publish (publish_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    ministry VARCHAR(120) NOT NULL,
    cadence ENUM('annual','weekly','monthly') NOT NULL,
    plan_year SMALLINT UNSIGNED NOT NULL,
    planned_month TINYINT UNSIGNED NULL,
    weekday VARCHAR(12) NULL,
    description TEXT NULL,
    suggested_responsibility VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_activity_template (title, cadence, plan_year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    ministry VARCHAR(120) NOT NULL,
    schedule_type ENUM('once','weekly','monthly','annual') NOT NULL DEFAULT 'once',
    plan_year SMALLINT UNSIGNED NULL,
    planned_month TINYINT UNSIGNED NULL,
    weekday VARCHAR(12) NULL,
    planned_date DATE NULL,
    starts_at TIME NULL,
    location VARCHAR(180) NULL,
    objective TEXT NULL,
    expected_result TEXT NULL,
    responsible_user_id INT UNSIGNED NOT NULL,
    supporting_team TEXT NULL,
    expected_participants INT UNSIGNED NULL,
    budget DECIMAL(12,2) NULL,
    funding_source VARCHAR(180) NULL,
    materials TEXT NULL,
    status ENUM('planned','in_progress','completed','postponed','cancelled') NOT NULL DEFAULT 'planned',
    actual_expenditure DECIMAL(12,2) NULL,
    actual_participants INT UNSIGNED NULL,
    completion_evidence TEXT NULL,
    report_notes TEXT NULL,
    challenges TEXT NULL,
    follow_up TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_activities_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_activities_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_activities_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_activities_branch_date (branch_id, planned_date),
    INDEX idx_activities_owner (responsible_user_id, status)
) ENGINE=InnoDB;

INSERT IGNORE INTO activity_templates (title, ministry, cadence, plan_year, planned_month, weekday, description, suggested_responsibility) VALUES
('New Year thanksgiving and annual planning', 'Parish administration', 'annual', 2027, 1, NULL, 'Review the previous year, set parish priorities, orient leaders and prepare the annual budget.', 'Parish priest and parish council'),
('Bible studies, prayer and Lent preparation', 'Christian education', 'annual', 2027, 2, NULL, 'Organize Bible study, prayer, evangelism and preparation for Lent.', 'Christian education and evangelism leaders'),
('Lent, discipleship and Holy Week preparation', 'Worship and pastoral care', 'annual', 2027, 3, NULL, 'Plan Lenten activities, discipleship, pastoral visits and Holy Week preparation.', 'Parish priest and ministry leaders'),
('Holy Week and Easter programme', 'Worship and liturgy', 'annual', 2027, 4, NULL, 'Coordinate Holy Week and Easter services, visitor follow-up and baptism preparation.', 'Worship and Christian education leaders'),
('Family ministry, pastoral care and outreach', 'Pastoral care', 'annual', 2027, 5, NULL, 'Coordinate family ministry, pastoral visits and community outreach.', 'Parish priest and pastoral care leader'),
('Pentecost, evangelism and midyear review', 'Evangelism and mission', 'annual', 2027, 6, NULL, 'Plan Pentecost activities, evangelism, youth programmes and a midyear review.', 'Evangelism and youth leaders'),
('Children programmes, leadership training and service', 'Christian education', 'annual', 2027, 7, NULL, 'Organize children''s programmes, leadership training and community service.', 'Christian education and social ministry leaders'),
('Youth fellowship and family seminars', 'Youth ministry', 'annual', 2027, 8, NULL, 'Coordinate youth fellowship, family seminars and ministry training.', 'Youth and family ministry leaders'),
('Bible teaching, parish development and finance review', 'Parish development', 'annual', 2027, 9, NULL, 'Review Bible teaching, evangelism, parish development and finances.', 'Christian education and development leaders'),
('Harvest thanksgiving and mission activities', 'Worship and evangelism', 'annual', 2027, 10, NULL, 'Plan harvest thanksgiving where customary, mission activities and choir events.', 'Worship and evangelism leaders'),
('Annual reports, budget and Advent preparation', 'Parish administration', 'annual', 2027, 11, NULL, 'Prepare annual reports, review ministry activities, plan the next budget and prepare for Advent.', 'Secretary, treasurer and parish council'),
('Advent, Christmas and annual evaluation', 'Worship and social development', 'annual', 2027, 12, NULL, 'Coordinate Advent and Christmas services, charitable activities and annual evaluation.', 'Parish priest and ministry leaders'),
('Weekly planning and administration', 'Parish administration', 'weekly', 2027, NULL, 'Monday', 'Review previous activities, coordinate the week and complete parish administration.', 'Parish priest and secretary'),
('Bible study and prayer groups', 'Christian education', 'weekly', 2027, NULL, 'Tuesday', 'Hold Bible study, discipleship and prayer meetings.', 'Christian education leader'),
('Midweek worship and intercessory prayer', 'Worship and liturgy', 'weekly', 2027, NULL, 'Wednesday', 'Hold midweek worship and intercessory prayer, with pastoral follow-up as needed.', 'Priest and prayer leader'),
('Choir rehearsal and Sunday preparation', 'Choir and music', 'weekly', 2027, NULL, 'Thursday', 'Rehearse music and prepare for Sunday worship.', 'Choir leader'),
('Youth fellowship and evangelism', 'Youth and evangelism', 'weekly', 2027, NULL, 'Friday', 'Coordinate youth fellowship, evangelism or home-cell meetings.', 'Youth and evangelism leaders'),
('Church cleaning and Sunday school preparation', 'Property and Christian education', 'weekly', 2027, NULL, 'Saturday', 'Prepare the church, Sunday school materials, rehearsals and service logistics.', 'Property coordinator and ministry leaders'),
('Main worship service', 'Worship and liturgy', 'weekly', 2027, NULL, 'Sunday', 'Plan the main worship service, preaching and Holy Communion as scheduled.', 'Parish priest and worship team'),
('Sunday school and parish groups', 'Christian education and fellowship', 'weekly', 2027, NULL, 'Sunday', 'Coordinate Sunday school, youth fellowship and parish groups.', 'Respective ministry leaders'),
('Pastoral and community visits', 'Pastoral care', 'monthly', 2027, NULL, NULL, 'Schedule home visits, hospital visits, counselling and community outreach as appropriate.', 'Pastoral care leader'),
('Monthly ministry planning and review', 'Parish administration', 'monthly', 2027, NULL, NULL, 'Review the calendar and resources, confirm leaders, track progress and prepare the next month.', 'Parish priest, secretary and ministry leaders'),
('Monthly ministry and financial reports', 'Parish administration and finance', 'monthly', 2027, NULL, NULL, 'Submit ministry summaries, reconcile documented expenditure and review results.', 'Ministry leaders, secretary and treasurer'),
('Monthly parish performance review', 'Parish governance', 'monthly', 2027, NULL, NULL, 'Review planned activities, reporting, financial documentation and follow-up actions; conduct a deeper performance review quarterly.', 'Parish priest and parish council');
