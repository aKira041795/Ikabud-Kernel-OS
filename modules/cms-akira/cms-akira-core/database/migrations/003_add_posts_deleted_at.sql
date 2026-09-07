-- CMS Akira Phase 1 lifecycle: soft deletion preserves auditability.
-- MySQL 5.7-compatible and independently idempotent for interrupted/rerun ledgers.
SET @cac_deleted_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_akira_posts'
      AND column_name = 'deleted_at'
);
SET @cac_deleted_at_sql := IF(
    @cac_deleted_at_exists = 0,
    'ALTER TABLE cms_akira_posts ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL, ADD INDEX idx_tenant_deleted (tenant_id, deleted_at)',
    'SELECT 1'
);
PREPARE cac_deleted_at_stmt FROM @cac_deleted_at_sql;
EXECUTE cac_deleted_at_stmt;
DEALLOCATE PREPARE cac_deleted_at_stmt;
