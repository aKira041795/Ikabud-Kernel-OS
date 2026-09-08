# CMS Akira Builder — server composition authority

`cms-akira-builder` owns Akira's tenant-scoped composition and revision records. Phase 10A is server-side only; the React/Vite administration and authenticated preview transport are Phase 10B.

## Persistence and revisions

- `cms_akira_compositions` has one row per trusted-tenant + opaque `post` slug. Its `tree` is the current preview and is never read from another member's table.
- `cms_akira_composition_revisions` is an append-only same-member chain. Create writes the initial revision. Update requires the exact current `base_revision_id`; a stale base returns a typed 409 conflict.
- Every query includes the Kernel tenant context. Payload tenant ids are rejected. The same migration and ModuleDB closure applies to shared and dedicated databases.
- MySQL 5.7 storage is InnoDB/utf8mb4_unicode_ci. Trees are canonical JSON in MEDIUMTEXT; runtime does not depend on MySQL JSON operators.

## Preview and publish contract

The composition row's tree and newest revision are preview state. Publish validates the tree and resolves the referenced published-capable Akira Post through `akira.post.get@1`, then promotes the current revision by setting `published_revision_id` and bumping `version`. Later preview edits append revisions and replace only preview `tree`; published rendering continues to load the promoted revision. Unpublish clears the pointer. Delete cascades only same-member revisions.

**Recorded integration decision:** Builder does not write `cms_akira_posts` or any other member's table. Public Post routing continues to use core's content until a separately gated core/public-path integration consumes `akira.builder.render@1`. That capability seam is a prerequisite for composition output to replace Post body output.

## Validation and rendering

The fixed tree shape is `{version: 1, blocks: [...]}`. Allowed blocks are `section`, `heading`, `paragraph`, `rich_text`, `image`, and `button`; each has an exact property allowlist. Unknown blocks/properties, executable markup/protocols, template includes, PHP/SQL payloads, unsafe URLs, over-depth trees (>8), over-count trees (>500), and encoded trees over 256 KiB fail closed. Only sections may have children. Rich text must already equal the canonical result from `akira.editor.sanitize@1`; Builder does not implement a competing HTML sanitizer.

`akira.builder.render@1` revalidates persisted input, resolves a validated explicit slug through `akira.theme.resolve@1`, and passes that slug to `ArkRendererResolver::render()`. The canonical theme registers exact view `entity.detail.composition`, whose DiSyL template receives only an allowlisted composition projection. Missing theme/view/render execution fails closed. `source=published` always loads `published_revision_id`; `source=preview` loads current preview.

## Capability contract

Reads: `akira.builder.compositions/get/revisions/render@1`.

Governed protocol-v2 operations: `akira.builder.create/update/publish/unpublish/delete/validate@1`. Every governed call requires an admin actor, Kernel idempotency claim/commit, durable audit on the caller-managed tenant PDO transaction, correlation id, and exactly one invalidation tag: `entity.list.composition`.

The module remains `_enabled:false`: repository tracking never installs or activates it for a tenant.
