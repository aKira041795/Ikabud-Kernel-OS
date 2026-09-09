-- CMS Akira P1 post revision history.
-- Tenant-scoped append-only snapshot ledger owned by cms-akira-core. Every
-- governed post write (cacPostMutate create/update/publish/unpublish/delete)
-- and every governed akira.post.revision.revert@1 writes one row here inside
-- the same single-tenant transaction as the post row, its kernel audit row and
-- the idempotency claim, so a revision can never be lost, double-applied, or
-- detached from the write that produced it. revision_no is a per-(tenant, slug)
-- monotonically increasing ordinal assigned inside the transaction
-- (max existing + 1) and is the immutable identity a client reverts to.
-- content_snapshot stores the canonical JSON object
-- { title, subtitle, content, image }. action is one of
-- create|update|publish|unpublish|delete|revert. actor_user_id is the kernel/
-- module actor id and correlation_id links the row to its durable audit entry.
-- The _migrations ledger is the sole idempotency authority.
CREATE TABLE cms_akira_post_revisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    post_slug VARCHAR(191) NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    content_snapshot JSON NOT NULL,
    action VARCHAR(32) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    correlation_id VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_post_revision (tenant_id, post_slug, revision_no),
    KEY idx_tenant_post (tenant_id, post_slug),
    KEY idx_tenant_post_created (tenant_id, post_slug, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
