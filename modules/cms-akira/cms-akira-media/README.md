# CMS Akira Media

Native, tenant-installable media for CMS Akira. This member owns
`cms_akira_media`; it does not read legacy CMS tables, another Akira member's
tables, or any object-storage SDK.

## Contracts

Public reads:

- `akira.media.library@1` — explicit media projections for the current tenant.
- `akira.media.get@1` — one projected media record.
- `akira.media.resolve@1` — resolve a media reference to a public projection
  for rendering (stable `url`, `alt`, dimensions, MIME, and filename).

Admin mutations are `akira.media.upload@1`, `akira.media.update@1`, and
`akira.media.delete@1`. Every mutation requires protocol v2, Kernel admin
identity, Kernel idempotency, durable audit in the same application-PDO
transaction, and invalidates the single canonical `entity.list.media` tag after
success. Tenant identity always comes from Kernel context, never payload
fields.

Uploads are validated against a fixed MIME allowlist (`image/jpeg`,
`image/png`, `image/gif`, `image/webp`, `application/pdf`). The declared MIME,
the client filename extension, and the sniffed file magic bytes must agree; the
server derives the storage extension and location from the validated MIME, never
from the client filename. The decoded upload is capped at 5 MiB. `update@1`
only changes `alt`/`width`/`height`; `delete@1` is a hard delete that also
removes the stored file.

## Storage

Files are stored deterministically on local filesystem under
`storage/private/cms-akira-media/tenant_{tenant_id}/{media_key}.{extension}`.
The relative `storage_path` in `cms_akira_media` is server-derived and validated
on every read/stream so a path can never escape its tenant root. Streaming is
served by `GET /api/v1/cms-akira-media/stream/{media_key}` and fails closed
(404) for missing keys, missing files, cross-tenant reads, or unsafe paths.

**Runtime requirement.** The web process must be able to create and write under
`storage/private/cms-akira-media/`. `camMediaWriteFile()` creates its tenant directory with a
recursive `mkdir(..., 0775)`, so it can create missing parents *beneath* `storage/` — but it cannot
create `storage/` itself, and an upload is the first operation in the product that writes there.
That is the general storage-writability requirement documented in
[`docs/kernel/production-deployment-guide.md`](../../../docs/kernel/production-deployment-guide.md)
(`chmod -R 775 storage/`); this note exists because media fails *first* and reports it as a 503
"Media storage unavailable", which is not obvious from the outside.

### Cleanup policy

- `delete@1` removes the stored file after the DB row delete commits.
- A failed upload rolls back its DB row and removes the partially written file.
- A commit whose outcome is uncertain may leave one orphan file; the
  deterministic layout makes orphans safe to remove with a tenant-scoped sweep,
  e.g. `rm -f storage/private/cms-akira-media/tenant_{id}/.*.tmp` for partial
  writes and, for committed rows no longer present, the file paths encoded in
  `storage_path` are only ever `{media_key}.{extension}` inside that tenant dir.
- No object-storage SDK is wired at this gate.

## Persistence

`database/migrations/001_initial.sql` is a table-free ledger marker;
`002_create_native_media.sql` creates `cms_akira_media` with InnoDB and
`utf8mb4_unicode_ci`, MySQL 5.7 syntax only, no JSON or MySQL-8-only features.
The composite unique `(tenant_id, media_key)` is 4 + 32 = 36 bytes, below the
conservative 767-byte InnoDB composite-key budget. Shared-schema access always
carries `tenant_id` predicates. Dedicated tenants use the Kernel-selected
tenant PDO and must pass the Kernel base-database rejection guard.

The manifest stays `_enabled:false`; installation is explicit through the
module-install service after `cms-akira-core`.

## Verification

```bash
php modules/cms-akira/cms-akira-media/tests/media_contract_test.php
php modules/cms-akira/cms-akira-media/tests/media_dedicated_tenant_test.php
php ikabud module:certify cms-akira-media
```
