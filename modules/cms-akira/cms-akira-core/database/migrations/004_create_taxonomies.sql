-- CMS Akira P1 governed taxonomy authority (categories/tags).
-- Tenant-scoped content taxonomy table owned by cms-akira-core.
-- The _migrations ledger is the sole idempotency authority.
CREATE TABLE cms_akira_taxonomies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    parent_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_type_slug (tenant_id, type, slug),
    KEY idx_tenant_type (tenant_id, type),
    KEY idx_tenant_parent (tenant_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
