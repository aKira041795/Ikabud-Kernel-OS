-- Kernel-owned durable event outbox. Idempotency claims remain in
-- kernel_idempotency_keys; this table stores event delivery work only.

CREATE TABLE IF NOT EXISTS kernel_durable_event_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    event_name VARCHAR(191) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    source VARCHAR(191) NOT NULL DEFAULT 'kernel',
    actor_id VARCHAR(191) NULL,
    actor_role VARCHAR(191) NULL,
    request_id VARCHAR(191) NULL,
    event_id VARCHAR(191) NULL,
    idempotency_key VARCHAR(255) NULL,
    status ENUM('pending', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_durable_outbox_created (created_at),
    INDEX idx_durable_outbox_pending (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
