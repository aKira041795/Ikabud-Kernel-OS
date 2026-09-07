# CMS Akira SEO

Native tenant-scoped SEO metadata authority for the CMS Akira suite.

## Responsibility

Owns `cms_akira_seo_metadata` and exposes the `akira.seo.*@1` capability family.
SEO metadata is keyed by `(tenant_id, entity_type, entity_key)` — entity type/key
are stable Akira ASCII strings and are **string references to content**, never
foreign SQL keys into another member's rows. This member does not verify that the
referenced entity still exists (core owns the content lifecycle); a stale or
missing reference fails closed deterministically (see below).

## Schema

`cms_akira_seo_metadata` (MySQL 5.7, InnoDB, `utf8mb4_unicode_ci`):

- `tenant_id INT UNSIGNED NOT NULL`
- `entity_type VARCHAR(64) ascii_bin NOT NULL` (e.g. `post`)
- `entity_key VARCHAR(190) ascii_bin NOT NULL` (canonical Akira key, e.g. a post slug)
- `title`, `meta_description`, `og_title` (`VARCHAR(255)`), `og_description`
  (`VARCHAR(500)`) — nullable plain text
- `canonical_url`, `og_image` (`VARCHAR(2048)`) — nullable, URL policy enforced
- `robots VARCHAR(255)` — nullable, directive allowlist enforced
- `created_at`, `updated_at`

Composite unique index `uq_seo_tenant_entity (tenant_id, entity_type, entity_key)`
is `4 + 64 + 190 = 258` bytes, below the conservative 767-byte InnoDB composite
key budget.

## Capabilities

- `akira.seo.get@1` — projected single record; `ok:false, error:"SEO metadata not found"`
  when missing (fail closed).
- `akira.seo.meta.build@1` — build final escaped SEO metadata for a rendering
  request from a stored record plus optional `defaults`. Missing records return the
  escaped defaults with `resolved_from:"default"` (no crash, no cross-tenant leak).
- `akira.seo.upsert@1` — governed v2 mutation (create-or-update), idempotent via
  `kernel.idempotency`, durable same-PDO audit, invalidates the single tag
  `entity.list.seo-metadata`.
- `akira.seo.delete@1` — governed v2 mutation (hard delete), same idempotency /
  audit / invalidation contract.

## Escaping and validation policy

- `canonical_url` / `og_image`: local absolute path (`/…` but never `//`) or an
  `http`/`https` URL; control characters and backslashes are rejected. Scheme-less
  `javascript:`, `data:`, and relative URLs are rejected on write.
- `robots`: explicit directive allowlist (`index`, `noindex`, `follow`,
  `nofollow`, `noarchive`, `nosnippet`, `noimageindex`, `notranslate`); any other
  token is rejected on write.
- `title`, `meta_description`, `og_title`, `og_description`: trimmed plain text,
  length-capped, and HTML-attribute-escaped on output (`htmlspecialchars`
  `ENT_QUOTES | ENT_SUBSTITUTE`) so hostile input cannot inject attributes/HTML.
- `meta.build` re-validates stored values defensively and drops unsafe
  canonical/robots/OG values to `null` rather than emitting them.

## Tenant separation

Every row is tenant-scoped through `ModuleDB`/Kernel tenant context. Tenant id
is never read from the payload; a payload `tenant_id` is rejected on read and
mutation paths. Executable shared-schema and dedicated-database isolation tests
cover cross-tenant read/write denial.

## Activation

`_enabled:false` is retained; the member is installed/activated explicitly per
tenant through the module-install service, never auto-enabled by bundling.
