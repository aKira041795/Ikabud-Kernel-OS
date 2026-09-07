-- Kernel capability authorization policy registry.
-- The natural key matches CapabilityAuthorizationRegistry::seedPolicy() upserts.

CREATE TABLE IF NOT EXISTS capability_authorization_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    policy_version INT UNSIGNED NOT NULL,
    capability_id VARCHAR(191) NOT NULL,
    capability_version VARCHAR(32) NOT NULL,
    provider VARCHAR(191) NOT NULL,
    caller_module VARCHAR(191) NULL,
    allowed_roles TEXT NULL,
    provider_activation_required TINYINT(1) NOT NULL DEFAULT 1,
    requires_protocol VARCHAR(16) NOT NULL DEFAULT 'v1',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_capability_authorization_policy (policy_version, capability_id, capability_version, provider),
    INDEX idx_capability_authorization_policy_active (policy_version, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
