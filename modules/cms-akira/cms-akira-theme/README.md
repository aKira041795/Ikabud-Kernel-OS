# CMS Akira Theme (native `akira.theme.*@1`)

`cms-akira-theme` is the native, table-free Akira authority over ARK theme
resolution and tenant-scoped activation. It owns **no database tables** and
reads none. Activation is a tenant-scoped module setting under the Kernel-owned
`tenant_module_settings` table (`module_id = cms-akira-theme`,
`setting_key = active_theme_slug`), never a new table and never a payload/claim
supplied tenant id.

## Authority model

- **Source of truth** — the tenant-scoped module setting `active_theme_slug`.
- **Request-context seam** — when the module is enabled and the tenant has a
  validated activation setting, `catSeedActiveThemeRequestContext()` publishes
  it as kernel request context key `active_theme_slug`. Core's existing
  `cacPostThemeSlug()` reads exactly that key and falls back to
  `cms-akira-posts`; render paths then pass the resolved slug straight to
  `app()->arkRenderers()`. No legacy CMS active-theme helper is reintroduced
  and no core render logic is rewritten — this is the exact, minimal seam.
- **Deterministic fallback** — `cms-akira-posts` is the canonical ARK fallback.
  A candidate slug is returned only when it is a canonical slug **and**
  ARK-visible (manifest + `renderer-registry.json` resolve
  `entity.list.post`/`entity.detail.post` through `ArkRendererResolver`,
  including its traversal protection). Unvalidated slugs fail closed.

## Capabilities

- `akira.theme.resolve@1` — resolve the tenant's active Akira theme slug:
  module setting → `active_theme_slug` request context → `cms-akira-posts`
  fallback; every candidate is validated before it is returned.
- `akira.theme.registry@1` — deterministic (slug-ascending) list of installed
  ARK-visible themes, projected to an explicit field allowlist
  (`slug`, `name`, `label`, `version`, `description`, `supported_surfaces`,
  `renderers`, `validated`, `active`).
- `akira.theme.validate@1` — validate a theme: manifest shape
  (`ThemeManifestValidator`), renderer-registry presence/uniqueness of known
  `entity.list|detail.*` view ids, `template` XOR `renders_as_component`,
  projected-fields-only `context_keys` (no `tenant_id`/`id`/`provider`/`*`/…),
  DiSyL file presence + lint (balanced blocks + v4 parser), traversal
  protection (realpath confinement inside the themes root), and deterministic
  fallback availability.
- `akira.theme.activate@1` — **governed v2 mutation**
  (`requires_protocol: v2`, `effects.invalidates: ["theme.active"]`):
  tenant-scoped module-setting write, idempotent via
  `kernel.idempotency.{hash,claim,commit,release}@1`, durable same-PDO audit via
  `kernel.audit.record@1`, and post-commit invalidation of the canonical
  `theme.active` fragment tag. Depends on
  `kernel.idempotency.{hash,claim,commit,release}@1` + `kernel.audit.record@1`;
  protocol-v2 policy is seeded through `CapabilityAuthorizationRegistry`
  (activation-time, idempotent), mirroring navigation/media/seo.

## Admin surface

Minimal shell-guarded surface, never the legacy CMS render/admin-context
helpers:

- `GET /cms-akira-theme` — plain-HTML theme list (active, validate state,
  activate form), guarded by Kernel `admin` role + CSRF on POST.
- Capability-backed JSON endpoints under `/api/v1/cms-akira-theme/`:
  `health`, `resolve`, `themes`, `themes/{slug}/validate`, and
  `POST themes/{slug}/activate`.

## Validation surface

`catThemeValidate($slug)` is the single validation authority used by both
`akira.theme.validate@1` and `akira.theme.activate@1`. It:

1. enforces the canonical slug grammar (`^[a-zA-Z0-9][a-zA-Z0-9_-]*$`, ≤190);
2. confines the theme directory via `realpath` prefix checks;
3. validates `theme.manifest.json` through
   `Ikabud\Kernel\Services\ThemeManifestValidator`;
4. validates `renderer-registry.json` (known view ids, one render target per
   renderer, no path escaping, projected `context_keys` only);
5. lints every `.disyl` file (balanced `{block}/{if}/{for}/{foreach}/{while}`
   plus the DiSyL v4 parser);
6. confirms the canonical `cms-akira-posts` fallback remains available.

## Notes

- `_enabled:false` is retained; explicit per-tenant activation is performed by
  the module-install service.
- This member carries no legacy CMS theme-activation, render, or
  admin-context helpers, no deleted content-get capability, and no `/cms/`
  admin route residue.
