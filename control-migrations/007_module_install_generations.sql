-- Kernel module-install lifecycle persistence (CONTROL DB only).
-- MySQL 5.7 / InnoDB. Generation tokens are ASCII, so the composite unique
-- key budget is 4 + (64 * 1) bytes (well below the legacy 767-byte limit).
CREATE TABLE IF NOT EXISTS `kernel_module_install_generations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT UNSIGNED NOT NULL,
    `install_generation` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `selection_id` VARCHAR(100) NOT NULL,
    `entry_module_id` VARCHAR(100) DEFAULT NULL,
    `previous_entry_module_id` VARCHAR(100) DEFAULT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'install_requested',
    `error_message` VARCHAR(1000) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `committed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_install_generation` (`tenant_id`, `install_generation`),
    KEY `idx_install_tenant_status` (`tenant_id`, `status`),
    KEY `idx_install_retention` (`updated_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kernel_module_install_steps` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `install_generation_id` BIGINT UNSIGNED NOT NULL,
    `module_id` VARCHAR(100) NOT NULL,
    `step_name` VARCHAR(40) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `detail_json` JSON DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_install_member_step` (`install_generation_id`, `module_id`, `step_name`),
    KEY `idx_install_step_status` (`install_generation_id`, `status`),
    CONSTRAINT `fk_install_step_generation` FOREIGN KEY (`install_generation_id`)
        REFERENCES `kernel_module_install_generations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
