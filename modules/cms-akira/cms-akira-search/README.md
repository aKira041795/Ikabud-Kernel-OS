# CMS Akira Search

Native, tenant-scoped Akira search authority. Phase 7 records the rename from the dormant external-adapter scaffold to the stable `cms-akira-search` extension. It has no external search backend and extends only `cms-akira-core`.

## Authority and persistence

The member exclusively owns and reads `cms_akira_search_documents`. Every row is selected and mutated with the trusted Kernel tenant context. `entity_type` and `document_key` are opaque ASCII references, never foreign keys into another member. The unique identity is `(tenant_id, entity_type, document_key)`. Metadata is canonical JSON encoded in text for MySQL 5.7; the table is InnoDB `utf8mb4_unicode_ci` and its ASCII composite identity stays within the conservative index byte budget.

## Capabilities

- `akira.search.document.build@1` accepts only `entity_type`, `document_key`, and allowlisted `fields` (`title`, `body`, `summary`, `status`, `meta`). Only published `post` documents are accepted. Unknown types, fields, payload tenant identity, invalid keys, or invalid metadata fail closed.
- `akira.search.upsert@1` and `akira.search.delete@1` are protocol-v2 admin mutations using Kernel durable idempotency and same-PDO audit. Each has exactly one invalidation: `entity.list.search-document`.
- `akira.search.query@1` accepts only `term`, optional `entity_type`, `page`, and `limit`; it searches the current tenant, returns an explicit projection (`entity_type`, `document_key`, `title`, `summary`, `status`, `indexed_at`), and orders by `indexed_at DESC, document_key ASC`.
- `akira.search.rebuild@1` is a governed deterministic repair command. It pages through `akira.post.list@1`, builds all published Post documents, replaces only the current tenant's Post index, and returns stable counts. Repeating it converges without duplicates.

## Lifecycle convergence and recorded prerequisite

Search registers the replay-safe `akira.post.lifecycle.committed` seam. A committed publish event resolves the published Post through `akira.post.get@1` and upserts it; committed unpublish/delete events remove it. Event tenant identity is never trusted: any supplied tenant must match the current Kernel context. Event-derived idempotency keys bind operation, entity key, and the trusted core correlation id.

Core Phase 1 does not currently publish this event, and modifying Kernel/core lifecycle infrastructure is outside this Kernel-read-only member gate. Although Kernel has a durable event outbox API, the existing Post mutation does not write a lifecycle event into its transaction. Guaranteed automatic Post-to-search outbox propagation is therefore a **recorded Kernel prerequisite**, not claimed here. Phase 7 proves the documented consumer seam end to end and proves recovery from missed delivery/drift through `akira.search.rebuild@1`; it never claims convergence from an unprotected post-success callback.

Dedicated tenant databases additionally require Kernel migration/idempotency/audit/outbox provisioning parity, which remains a Kernel prerequisite. The same member migration and ModuleDB closure are used after Kernel selects and validates a dedicated connection.

## Verification

```sh
php modules/cms-akira/cms-akira-search/tests/search_contract_test.php
php modules/cms-akira/cms-akira-search/tests/search_dedicated_tenant_test.php
php ikabud module:certify cms-akira-search
php ikabud capability:audit --json
```
