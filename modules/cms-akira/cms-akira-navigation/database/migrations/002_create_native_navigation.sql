-- CMS Akira native navigation authority (MySQL 5.7).
-- The Kernel-owned _migrations ledger applies this member migration once per
-- tenant database. CREATE IF NOT EXISTS also makes an interrupted DDL rerun safe.
-- Stable Akira menu/item keys are ASCII and all foreign keys remain within this
-- member; content references are type/key values and never foreign SQL rows.
CREATE TABLE IF NOT EXISTS cms_akira_menus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    menu_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    slug VARCHAR(190) NOT NULL,
    location VARCHAR(190) NOT NULL,
    title VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_tenant_key (tenant_id, menu_key),
    UNIQUE KEY uq_menu_tenant_slug (tenant_id, slug),
    UNIQUE KEY uq_menu_tenant_location (tenant_id, location),
    KEY idx_menu_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_akira_menu_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    menu_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    parent_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    label VARCHAR(255) NOT NULL,
    url VARCHAR(2048) NULL,
    reference_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reference_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    weight INT NOT NULL DEFAULT 0,
    depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_tenant_key (tenant_id, item_key),
    UNIQUE KEY uq_item_scope_key (tenant_id, menu_key, item_key),
    KEY idx_item_tree_order (tenant_id, menu_key, parent_key, weight, item_key),
    CONSTRAINT fk_akira_item_menu FOREIGN KEY (tenant_id, menu_key)
        REFERENCES cms_akira_menus (tenant_id, menu_key) ON DELETE CASCADE,
    CONSTRAINT fk_akira_item_parent FOREIGN KEY (tenant_id, menu_key, parent_key)
        REFERENCES cms_akira_menu_items (tenant_id, menu_key, item_key) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
