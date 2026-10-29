CREATE TABLE IF NOT EXISTS user_profile_pictures (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    image_mime ENUM('image/jpeg', 'image/png', 'image/webp') NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_pictures_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
