CREATE TABLE IF NOT EXISTS `dtr_trainees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `course` VARCHAR(150) DEFAULT NULL,
  `year_level` VARCHAR(30) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `contact_number` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `face_descriptor` JSON DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `dtr_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trainee_id` BIGINT UNSIGNED NOT NULL,
  `log_type` ENUM('time_in','time_out') NOT NULL,
  `logged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `match_distance` DECIMAL(6,4) DEFAULT NULL,
  `source` VARCHAR(30) NOT NULL DEFAULT 'face_kiosk',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dtr_logs_trainee_time` (`trainee_id`, `logged_at`),
  CONSTRAINT `fk_dtr_logs_trainee` FOREIGN KEY (`trainee_id`)
    REFERENCES `dtr_trainees` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `dtr_schedule` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `am_login` TIME NOT NULL DEFAULT '08:00:00',
  `am_logout` TIME NOT NULL DEFAULT '12:00:00',
  `pm_login` TIME NOT NULL DEFAULT '13:00:00',
  `pm_logout` TIME NOT NULL DEFAULT '17:00:00',
  `grace_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_dtr_schedule_single_row` CHECK (`id` = 1)
);

INSERT INTO `dtr_schedule` (`id`) VALUES (1)
  ON DUPLICATE KEY UPDATE id = id;

INSERT INTO `roles` (`role_name`, `is_active`)
SELECT 'Time Keeper', 1
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_name` = 'Time Keeper');
