-- Separate a policy declaration from its operator-controlled grant lifecycle.
-- MySQL 5.7 has no ADD COLUMN IF NOT EXISTS; MigrationRunner treats duplicate
-- column error 1060 as an idempotent success when an installation was repaired
-- or migrated manually.

ALTER TABLE capability_authorization_policies
    ADD COLUMN grant_state ENUM('granted', 'suspended', 'revoked') NOT NULL DEFAULT 'granted' AFTER is_active,
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
