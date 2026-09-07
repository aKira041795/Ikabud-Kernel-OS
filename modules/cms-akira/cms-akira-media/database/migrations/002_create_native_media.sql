-- CMS Akira native media authority (MySQL 5.7).
-- The Kernel-owned _migrations ledger applies this member migration once per
-- tenant database. CREATE IF NOT EXISTS also makes an interrupted DDL rerun safe.
-- Media keys are stable ASCII and all foreign keys remain within this member;
-- media references in Akira content are type/key values and never foreign SQL rows.
-- The composite unique index (tenant_id, media_key) is 4 + 32 = 36 bytes, well
-- below the conservative 767-byte InnoDB composite-key byte budget.
CREATE TABLE IF NOT EXISTS cms_akira_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    media_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    alt VARCHAR(255) NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_tenant_key (tenant_id, media_key),
    KEY idx_media_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
