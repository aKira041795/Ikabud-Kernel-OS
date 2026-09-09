-- CMS Akira P1 governed content-type registry (declared field schemas).
-- Tenant-scoped declared content models owned by cms-akira-core.
-- field_schema stores the canonical JSON object { fields: { <name>: { type,
-- required, label } } }; validation happens on every governed write, so the
-- stored TEXT is always a well-formed declared model. The table deliberately
-- carries no FK to posts yet: a later increment will add the reference seam
-- (delete stays allowed here precisely because no linkage exists).
-- The _migrations ledger is the sole idempotency authority.
CREATE TABLE cms_akira_content_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    slug VARCHAR(191) NOT NULL,
    label VARCHAR(191) NOT NULL,
    field_schema TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_slug (tenant_id, slug),
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
