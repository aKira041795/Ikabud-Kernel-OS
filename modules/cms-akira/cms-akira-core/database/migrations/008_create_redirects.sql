-- CMS Akira P2.2a post-path redirect ledger.
-- Tenant-scoped, exact-match, single-hop redirects owned by cms-akira-core.
-- A row maps one retired post path (source_path, always /posts/<slug>) to one
-- same-origin internal target path (target_path). Resolution happens only at
-- the module-level not-found seam akiraPublicNotFound(); a row whose target is
-- itself another row's source is never re-resolved, so there are no chains and
-- no loops. Targets are validated on every governed write and can never be
-- absolute, protocol-relative or scheme-qualified (no open redirect). The
-- composite unique key (tenant_id, source_path) makes a repeated write for the
-- same source an in-place update rather than a duplicate row. The _migrations
-- ledger is the sole idempotency authority; CREATE TABLE IF NOT EXISTS keeps the
-- statement itself re-runnable.
CREATE TABLE IF NOT EXISTS cms_akira_redirects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    source_path VARCHAR(255) NOT NULL,
    target_path VARCHAR(255) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    correlation_id VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_redirect_source (tenant_id, source_path),
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
