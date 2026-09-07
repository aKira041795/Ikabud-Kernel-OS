-- CMS Akira P1 Post authority.
-- Provisioning contract: the repository MigrationRunner ledger applies this
-- migration in every applicable tenant database before that tenant is activated.
-- A failed tenant remains inactive/pending; rerunning the ledger converges it.
-- The _migrations ledger is the sole idempotency authority.
CREATE TABLE cms_akira_posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    slug VARCHAR(191) NOT NULL,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(255) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    image VARCHAR(2048) NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_slug (tenant_id, slug),
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
