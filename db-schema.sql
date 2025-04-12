CREATE TABLE IF NOT EXISTS `monitors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_monitor_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitor_tags` (
    `monitor_id` INT NOT NULL,
    `tag_id` INT NOT NULL,
    PRIMARY KEY (`monitor_id`, `tag_id`),
    FOREIGN KEY (`monitor_id`) REFERENCES `monitors` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `monitor_id` INT NOT NULL,
    `unique_id` VARCHAR(255) NOT NULL,
    `state` ENUM('run', 'complete', 'fail') NOT NULL,
    `duration` FLOAT NULL,
    `exit_code` INT NULL,
    `host` VARCHAR(255) NULL,
    `timestamp` INT NOT NULL,
    `received_at` INT NOT NULL,
    `ip` VARCHAR(45) NULL,
    `error` TEXT NULL,
    FOREIGN KEY (`monitor_id`) REFERENCES `monitors` (`id`) ON DELETE CASCADE,
    INDEX `idx_unique_id` (`unique_id`),
    INDEX `idx_state` (`state`),
    INDEX `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ping_tags` (
    `ping_id` INT NOT NULL,
    `tag_id` INT NOT NULL,
    PRIMARY KEY (`ping_id`, `tag_id`),
    FOREIGN KEY (`ping_id`) REFERENCES `pings` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;