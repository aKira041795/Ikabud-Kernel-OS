-- CMS Akira native composition authority (MySQL 5.7).
-- ASCII opaque keys keep tenant/type/entity uniqueness below 767 bytes.
-- Trees are canonical JSON encoded into MEDIUMTEXT and validated by the provider.
CREATE TABLE IF NOT EXISTS cms_akira_compositions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(255) NOT NULL,
    tree MEDIUMTEXT NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_revision_id BIGINT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_builder_tenant_entity (tenant_id, entity_type, entity_key),
    KEY idx_builder_tenant (tenant_id),
    KEY idx_builder_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_akira_composition_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    composition_id BIGINT UNSIGNED NOT NULL,
    tree MEDIUMTEXT NOT NULL,
    base BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    change_note VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_builder_revision_tenant (tenant_id),
    KEY idx_builder_revision_composition (composition_id, id),
    KEY idx_builder_revision_tenant_composition (tenant_id, composition_id, id),
    CONSTRAINT fk_builder_revision_composition FOREIGN KEY (composition_id)
        REFERENCES cms_akira_compositions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
