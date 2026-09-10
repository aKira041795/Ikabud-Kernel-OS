# CMS Akira Builder — server composition authority

`cms-akira-builder` owns Akira's tenant-scoped composition and revision records. Phase 10A is server-side only; the React/Vite administration and authenticated preview transport are Phase 10B.

## Persistence and revisions

- `cms_akira_compositions` has one row per trusted-tenant + opaque `post` slug. Its `tree` is the current preview and is never read from another member's table.
- `cms_akira_composition_revisions` is an append-only same-member chain. Create writes the initial revision. Update requires the exact current `base_revision_id`; a stale base returns a typed 409 conflict.
- Every query includes the Kernel tenant context. Payload tenant ids are rejected. The same migration and ModuleDB closure applies to shared and dedicated databases.
- MySQL 5.7 storage is InnoDB/utf8mb4_unicode_ci. Trees are canonical JSON in MEDIUMTEXT; runtime does not depend on MySQL JSON operators.

## Preview and publish contract

The composition row's tree and newest revision are preview state. Publish validates the tree and resolves the referenced published-capable Akira Post through `akira.post.get@1`, then promotes the current revision by setting `published_revision_id` and bumping `version`. Later preview edits append revisions and replace only preview `tree`; published rendering continues to load the promoted revision. Unpublish clears the pointer. Delete cascades only same-member revisions.

Builder does not write `cms_akira_posts` or any other member's table. Published compositions are anonymously available, tenant-scoped, at `GET /p/{key}`; missing and draft compositions return 404. Preview remains available only through the admin-governed capability/API. Publish and unpublish invalidate the public page cache after the governed transaction commits.

## Validation and rendering

The fixed tree shape is `{version: 1, blocks: [{block, props, children?}]}`. Block ids, defaults, property schemas, and renderer templates come exclusively from the active validated theme's `akira.theme.blocks@1` catalogue. Unknown blocks and unknown properties are errors. Values are recursively type-checked (including arrays and objects), while unsafe strings/URLs, over-depth trees (>8), over-count trees (>500), and encoded trees over 256 KiB fail closed.

`akira.builder.render@1` resolves the validated active theme and projects each block to `{template, props}`. The composition DiSyL view invokes the theme-owned block templates through dynamic includes; PHP never builds block HTML. Invalid legacy/corrupt persisted blocks are skipped atomically and reported in `data.warnings`, without raw or partial fallback markup. Missing theme/view/render execution fails closed. `source=published` always loads `published_revision_id`; `source=preview` loads current preview.

## Capability contract

Reads: `akira.builder.compositions/get/revisions/render@1`.

Governed protocol-v2 operations: `akira.builder.create/update/publish/unpublish/delete/validate@1`. Every governed call requires an admin actor, Kernel idempotency claim/commit, durable audit on the caller-managed tenant PDO transaction, correlation id, and exactly one invalidation tag: `entity.list.composition`.

The module remains `_enabled:false`: repository tracking never installs or activates it for a tenant.

## Phase 10B — builder admin UI, JSON bridge, CSP build, preview transport

The React/Vite/TypeScript builder admin lives under `admin-ui/` and is mounted by the
authenticated `cms-akira-shell` pages (`/cms-akira-shell/compositions` and
`/cms-akira-shell/compositions/{key}/edit`). The shell pages serve the committed build plus a
mount container and an authorized JSON bootstrap (available Akira posts for attachment). The
bootstrap's `blocks_endpoint` points to `/api/v1/cms-akira-theme/blocks`; the editor fails closed
if that active-theme catalogue cannot be loaded and derives its picker, property form, and block
defaults exclusively from the returned schemas. No second entry module exists — the shell is the
single admin host.

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
