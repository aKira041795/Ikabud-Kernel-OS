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

## Phase 10B — builder admin UI, JSON bridge, CSP build, preview transport

The React/Vite/TypeScript builder admin lives under `admin-ui/` and is mounted by the
authenticated `cms-akira-shell` pages (`/cms-akira-shell/compositions` and
`/cms-akira-shell/compositions/{key}/edit`). The shell pages serve the committed build plus a
mount container and an authorized JSON bootstrap (available Akira posts for attachment). No
second entry module exists — the shell is the single admin host.

### Authenticated JSON capability bridge (`/api/v1/cms-akira/builder/*`)

`routes.php` maps thin HTTP handlers in `handlers.php` onto the `akira.builder.*@1` handler map
(no new business logic). Endpoints: `GET /compositions`, `GET /compositions/{key}`,
`GET /compositions/{key}/revisions`, `GET /compositions/{key}/render?source=preview|published`;
`POST /validate`, `POST /compositions` (create), `POST /compositions/{key}` (save draft, base
revision), and `POST /compositions/{key}/publish|unpublish|delete`. Every request is
Kernel-auth + admin-role guarded (session cookie or JWT bearer — Kernel resolves `app()->user()`),
reads a JSON body, and forwards it; the tenant is always taken from Kernel context. Typed
`CabBuilderException` failures map to their HTTP status (e.g. stale base → 409); any unexpected
error fails closed as 500.

### Admin UI (React 18 + Vite ^5 + TypeScript ^5)

The functional MVP (`admin-ui/src`) lists compositions and available posts, offers a JSON tree
editor with an allowlisted validated-block form, saves drafts against the current base revision,
validates, lists revisions, and renders server-rendered preview vs published HTML in a
`sandbox`ed iframe (`srcDoc`) — React escapes by default and preview HTML is never injected as
live markup.

Build/run (node 18.19.1 + npm 9.2.0; do **not** use Vite 6/7):

```bash
cd admin-ui
npm install          # or npm ci once package-lock.json is committed
npm run type-check
npm run build        # emits public/admin/assets/cms-akira-builder/*
```

`npm run build` outputs the production bundle under `public/admin/assets/cms-akira-builder` so it
is served as static `self` assets. The committed bundle is minified with sourcemaps disabled and
contains no `new Function`/`eval`; it never requires `script-src 'unsafe-eval'`. CSP posture:
static `self` scripts/styles, preview HTML isolated in a sandboxed iframe, no runtime eval, no
inline script payloads (the JSON bootstrap is a `text/json` element parsed at runtime).

### Install / enable path

Tracking stays `_enabled:false`. A tenant activates the full visual graph (including this module,
core, editor, theme, and the shell) through the Kernel module-install planner using the
`cms-akira-profile-visual` install bundle (certified/tracked in 10B). Only after activation does
the shell expose the builder admin and the `/api/v1/cms-akira/builder/*` routes become routable.
