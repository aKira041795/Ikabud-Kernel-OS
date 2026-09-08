-- CMS Akira native search document authority (MySQL 5.7).
-- Opaque ASCII identity columns keep the unique key at 4 + 32 + 190 = 226
-- bytes, below the conservative 767-byte InnoDB composite-key budget.
-- Metadata is JSON encoded into LONGTEXT; no MySQL-8 JSON operators are used.
CREATE TABLE IF NOT EXISTS cms_akira_search_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    summary TEXT NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    meta LONGTEXT NOT NULL,
    indexed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_search_tenant_entity_document (tenant_id, entity_type, document_key),
    KEY idx_search_tenant_type_indexed (tenant_id, entity_type, indexed_at),
    KEY idx_search_tenant_document (tenant_id, document_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
