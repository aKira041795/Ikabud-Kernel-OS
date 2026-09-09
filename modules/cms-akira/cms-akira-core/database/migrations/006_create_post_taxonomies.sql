-- CMS Akira P1 post <-> taxonomy assignment (categories).
-- Tenant-scoped link rows owned by cms-akira-core. taxonomy_id is an
-- FK-less integer reference to cms_akira_taxonomies.id by design: taxonomy
-- delete stays allowed (its increment added no linkage contract) and the
-- governed set_taxonomies capability replaces a post's whole assignment set
-- atomically, so any dangling link left by a term delete is cleared on the
-- next assignment. Reads join back to cms_akira_taxonomies, so dangling links
-- never surface in akira.post.get/list projections.
-- The _migrations ledger is the sole idempotency authority.
CREATE TABLE cms_akira_post_taxonomies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    post_slug VARCHAR(191) NOT NULL,
    taxonomy_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_post_taxonomy (tenant_id, post_slug, taxonomy_id),
    KEY idx_tenant_post (tenant_id, post_slug),
    KEY idx_tenant_taxonomy (tenant_id, taxonomy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
