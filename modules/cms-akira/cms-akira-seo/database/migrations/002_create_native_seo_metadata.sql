-- CMS Akira native SEO metadata authority (MySQL 5.7).
-- The Kernel-owned _migrations ledger applies this member migration once per
-- tenant database. CREATE IF NOT EXISTS also makes an interrupted DDL rerun safe.
-- entity_type/entity_key are stable Akira ASCII strings (never foreign SQL keys
-- into another member's rows). The composite unique index is
-- 4 + 64 + 190 = 258 bytes, well below the conservative 767-byte InnoDB
-- composite-key byte budget.
CREATE TABLE IF NOT EXISTS cms_akira_seo_metadata (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    canonical_url VARCHAR(2048) NULL,
    robots VARCHAR(255) NULL,
    og_title VARCHAR(255) NULL,
    og_description VARCHAR(500) NULL,
    og_image VARCHAR(2048) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seo_tenant_entity (tenant_id, entity_type, entity_key),
    KEY idx_seo_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
