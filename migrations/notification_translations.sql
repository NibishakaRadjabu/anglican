ALTER TABLE notifications
    ADD COLUMN notification_type VARCHAR(50) NOT NULL DEFAULT 'generic',
    ADD COLUMN data_json TEXT NULL;
