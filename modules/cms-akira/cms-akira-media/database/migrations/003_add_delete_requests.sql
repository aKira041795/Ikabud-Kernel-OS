-- CMS Akira two-phase media deletion request state.
-- MySQL 5.7-compatible and independently idempotent for interrupted/rerun ledgers.
SET @cam_delete_requested_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_akira_media'
      AND column_name = 'delete_requested_at'
);
SET @cam_delete_requested_at_sql := IF(
    @cam_delete_requested_at_exists = 0,
    'ALTER TABLE cms_akira_media ADD COLUMN delete_requested_at DATETIME NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE cam_delete_requested_at_stmt FROM @cam_delete_requested_at_sql;
EXECUTE cam_delete_requested_at_stmt;
DEALLOCATE PREPARE cam_delete_requested_at_stmt;

SET @cam_delete_requested_by_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_akira_media'
      AND column_name = 'delete_requested_by'
);
SET @cam_delete_requested_by_sql := IF(
    @cam_delete_requested_by_exists = 0,
    'ALTER TABLE cms_akira_media ADD COLUMN delete_requested_by INT UNSIGNED NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE cam_delete_requested_by_stmt FROM @cam_delete_requested_by_sql;
EXECUTE cam_delete_requested_by_stmt;
DEALLOCATE PREPARE cam_delete_requested_by_stmt;

SET @cam_delete_request_reason_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_akira_media'
      AND column_name = 'delete_request_reason'
);
SET @cam_delete_request_reason_sql := IF(
    @cam_delete_request_reason_exists = 0,
    'ALTER TABLE cms_akira_media ADD COLUMN delete_request_reason VARCHAR(255) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE cam_delete_request_reason_stmt FROM @cam_delete_request_reason_sql;
EXECUTE cam_delete_request_reason_stmt;
DEALLOCATE PREPARE cam_delete_request_reason_stmt;
