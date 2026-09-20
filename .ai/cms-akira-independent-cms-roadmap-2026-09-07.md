# CMS Akira — Independent CMS Roadmap (Kernel OS 6.x)

**Status:** authoritative build order and gate contract  
**Baseline:** repository root `/var/www/html/ikabudsix`, main `8053dc1`, 2026-09-07  
**Directive:** CMS Akira is a separate, tenant-installable CMS product. The legacy `cms` module in
`MAIN-CMS-REPO` is neither a dependency nor a source tree. Every item below is a **FORK-NATIVE** milestone.
This document authorizes no implementation by itself.

## 1. Architecture and product boundary

`cms-akira-core` remains the Akira domain/content authority. It currently owns `cms_akira_posts`, the Post
Entity Authority, `cms.post.get/list/create/update@1`, the Post Entity View bridges, governed P1 rendering and
P2 mutations. New feature modules may read Akira content only through core-owned capabilities/Entity Views and
may write only their own declared tables. They must not call, query, include, render or authenticate through the
legacy `cms` product.

**Entry-shell decision:** add a dedicated `cms-akira-shell` product member, depending only on
`cms-akira-core` and Kernel. It will be the sole `entry_module:true` member and own `/cms-akira/login`, the
Akira admin dashboard, admin navigation and product routing. It will use **Kernel users/authentication**, so it
will declare neither `auth_owned` nor `authentication_provider`. Consequently there is no Akira users table and
no `auth_owned.id_column`/`role_column`; Kernel's users/roles, CSRF and request tenant/actor context are
canonical. If Akira ever elects to own users, that is a separate architecture gate and the manifest must then
declare a complete `auth_owned` object including `id_column`, `role_column` and tenant column.
`cms-akira-profile-standard` is therefore re-scoped to a bundle only: remove `entry_module:true` and
`authentication_provider:"cms"`.

The presentation path is singular:

```text
Akira Domain truth/capability
  → EntityViewResolver (explicit allowlisted semantic projection)
  → ArkRendererResolver + one renderer-registry.json (visual/theme authority)
  → DiSyL (execution only)
  → HTML
```

ARK owns renderer selection, tokens, layouts, slots and composition rules; it owns no entities, SQL,
authorization or workflows. `cms-akira-theme` owns Akira's ARK theme lifecycle, beginning with
`storage/cms-themes/cms-akira-posts`. `cms-akira-builder`, built last, is an Akira UI for editing valid ARK
composition over the shared Kernel ARK engine, not another renderer or theme engine. Profiles are declarative
install/activation bundles, not runtime authorities.

## 2. Manifest-grounded member re-scope

Names below marked **new** or **rename** are target decisions. Capability names are the target v1 contract
families; final payload schemas must be frozen in each member gate before implementation. Every mutation uses
protocol v2, explicit authorization policy, Kernel idempotency/audit and precise `effects.invalidates` tags.

| Member | Current verified residue | FORK-NATIVE target ownership and contract shape | Kernel dependencies |
|---|---|---|---|
| `cms-akira-core` | Active tracked core, `_enabled:false`; no legacy adapter remains. Manifest owns/reads `cms_akira_posts`, exposes `cms.post.*@1` plus `entity.list/get.post@1`; Post route bridge also uses the resolver's unversioned IDs. | Content authority. Retain `cms_akira_posts`, Post CRUD/read contracts and explicit `post.list`/`post.detail` projections. Add content types only as later, separately gated core migrations/capability families; never import `cms_content`. | Capability bus, Entity Authority/View resolver, Kernel auth/CSRF, `kernel.idempotency.*`, `kernel.audit.record@1`, cache effects, ARK resolver. |
| `cms-akira-shell` **(new)** | No current member; the only entry posture is incorrectly attached to standard profile. | Sole `entry_module:true`; Kernel-auth login/admin shell, dashboard, module settings and extension-point host. No domain table and no user table. Shell consumes core Entity Views/caps and exposes only shell-specific health/context contracts if needed. | Kernel users/auth/session, tenant router/context, CSRF, policy registry, module lifecycle, DiSyL. |
| `cms-akira-editor` | Depends on deleted `akira.content.get@1`; implementation delegates normalize/sanitize/assets to TinyMCE/CMS helpers. | Own `editor.render/normalize/sanitize/validate/assets@1` with Akira-owned sanitization policy, document schema and bundled editor assets/UI. No table unless versioned editor presets are approved; no TinyMCE contract. Uses core get/update for authoring. | Kernel auth/CSRF, capability bus, DiSyL/assets, idempotency/audit for save actions. |
| `cms-akira-theme` | Depends on deleted `akira.content.get@1`; PHP calls `cmsThemeRuntimeDiagnostics`, `cmsAvailableThemes`, `cmsRender`, `cmsAdminContext` and links `/cms/admin/*`. | Akira ARK theme authority: `akira.theme.resolve/list/activate@1`; own admin theme library/customizer and Akira theme source/validation. Active theme is tenant-scoped Kernel module setting (no legacy theme table). No Entity View provider. | `ArkRendererResolver`, `ThemeManifestValidator`, tenant module settings, Kernel auth/CSRF, DiSyL, audit/idempotency for activation. |
| `cms-akira-navigation` | Depends on deleted `akira.content.get@1` and foreign `cms.menus.get/tree@1`; handlers call both. | Own `cms_akira_menus` and `cms_akira_menu_items`; `akira.navigation.menu.get/list/create/update/delete@1` and `akira.navigation.resolve@1`; explicit `menu.list`/`menu.detail` projections for admin/presentation. Links reference core content by stable type/key, not foreign rows. | ModuleDB/tenant context, EntityViewResolver, Kernel auth/CSRF, idempotency/audit/effects. |
| `cms-akira-media` | Depends on deleted `akira.content.get@1` and foreign `cms.media.get@1`; handler delegates resolution. | Own `cms_akira_media` (metadata/tenant/storage key) and, if derivatives are shipped, `cms_akira_media_variants`; `akira.media.get/list/create/update/delete/resolve@1`; `media.list`/`media.detail` projections; Akira upload/library UI and controlled storage namespace. | Kernel file/storage safeguards, MIME validation, ModuleDB/tenant context, Entity Views, auth/CSRF, idempotency/audit/effects. |
| `cms-akira-seo` | Depends on deleted `akira.content.get@1` and foreign `cms.seo.resolve@1`; handler delegates resolution. | Own `cms_akira_seo_metadata`, uniquely keyed by tenant + Akira entity type/key; `akira.seo.get/upsert/delete/meta.build@1`; explicit `seo.detail` admin projection. Defaults derive from core's allowlisted content contract. | ModuleDB/tenant context, Entity Views, auth/CSRF, idempotency/audit/effects. |
| `cms-akira-workflow` | Deleted `akira.content.get@1`; `workflow.state.get@1` is valid Kernel dependency, but implementation still uses CMS-owned `cms.content` workflow assumptions. | Own Akira workflow definition/transition behavior and `akira.workflow.evaluate/transition@1` over Akira entity keys. Definitions use an Akira key such as `cms-akira.content`; run/state persistence remains Kernel WorkflowEngine authority, so no duplicate workflow tables. | `WorkflowEngine`, `workflow.state.get@1` and other frozen Kernel workflow contracts, auth, capability bus, audit/correlation evidence. |
| `cms-akira-search-adapter` → `cms-akira-search` **(rename)** | Deleted `akira.content.get@1`; foreign `search.index.upsert@1`; helpers such as `searchStrip`; description explicitly says external adapter. | Native Akira indexing/query module. Own `cms_akira_search_documents`; `akira.search.document.build/upsert/delete/query@1`; `search-result.list/detail` projections. Subscribe to governed Akira content mutation/publication outcomes or receive explicit calls; no external search requirement. | ModuleDB/tenant context, Entity Views, Kernel events/capability bus, auth, idempotency/audit/effects. |
| `cms-akira-ai` | Depends on deleted `akira.content.get@1`; currently only exposes `akira.ai.summary.suggest@1`. | Retain `akira.ai.summary.suggest@1`, add keyword/assist operations only when separately schema-frozen. Fetch core's projected content and invoke an available Kernel-governed optional `ai.*@1` capability; return a typed unavailable result when none is configured. No provider SDK/table in Akira and no mandatory non-Kernel module. | Kernel AI governance/optional capability inventory, capability bus, auth/rate/policy controls, audit/correlation. |
| `cms-akira-builder` | Empty `exposes`; only scaffold routes/helpers/migration. | Last-built ARK composition editor. Own `cms_akira_compositions` and `cms_akira_composition_revisions`; `akira.builder.composition.get/list/create/update/publish@1`; `composition.list/detail` projections and Akira admin UI. It edits ARK-declared allowed blocks/slots and stores compositions, but renderer selection stays in ARK. | ARK resolver/manifest validator, Entity Views, Kernel auth/CSRF, ModuleDB, idempotency/audit/effects, DiSyL. |
| `cms-akira-profile-minimal` | Empty scaffold; manifest currently depends only on core although inventory intent said core+editor. | Data-free bundle for `core + shell + editor`; no capabilities, routes or runtime handlers beyond optional install health. | Kernel module dependency/tenant migration planner. |
| `cms-akira-profile-standard` | Empty scaffold; depends core+editor+theme+navigation; foreign `entry_module:true` + `authentication_provider:"cms"`. | Data-free bundle for `core + shell + editor + theme + navigation + media + seo`; **not** an entry/auth authority. | Kernel module dependency/tenant migration planner. |
| `cms-akira-profile-headless` | Empty scaffold; depends core+search-adapter+workflow. | Data-free API bundle for `core + search + workflow`; no shell requirement and no entry/auth declaration. Rename dependency with native search member. | Kernel module dependency/tenant migration planner. |
| `cms-akira-profile-visual` | Empty scaffold; depends core+editor+theme+navigation+builder+media+seo+workflow+search-adapter. | Data-free full bundle: `standard + workflow + search + builder` (AI remains opt-in). Rename search dependency and include shell through standard/dependency closure. It remains disabled until the final Builder gate. | Kernel module dependency/tenant migration planner. |

## 3. Ordered phases and independent gates

A gate is mergeable only when its member has removed all foreign residue, frozen schemas, passed authority and
certification checks, added member tests, been removed from the CMS Akira ignore rule, and remains
`_enabled:false` by default but is proven activatable for one tenant. No later gate may waive an earlier gate.

### Phase 0 — Freeze the independent boundary

**Scope:** Record `8053dc1` manifests and P1/P2 evidence as baseline; add a forbidden-residue inventory covering
legacy `cms` functions, tables, templates, routes, capabilities and `authentication_provider:"cms"`. Reserve
Akira table/capability/entity-view names and the one-entry-module rule.

**Acceptance:** P1 read/render and P2 mutation tests remain green; `cms-akira-core` is the sole content authority;
real-repo capability authority audit is zero; a clean pristine comparison is recorded. No dormant member is
enabled or tracked by this phase.

### Phase 1 — Core product/content gate (`cms-akira-core`)

**Scope:** Treat shipped P1/P2 as the minimum product contract; reconcile manifest and runtime Entity View IDs,
public/admin policy boundaries, activation policy seeding, tenant migration reruns and shell-facing admin APIs.
Do not broaden beyond Post unless a new core entity has its own migration, authority and projection sub-gate.

**Acceptance:** published-only tenant reads; fail-closed registered `post.list/detail` projections with no internal
field leakage; governed create/update with CSRF, role policy, durable idempotency, audit/correlation and exact
cache invalidation; migration 016 prerequisite and `cms_akira_posts` migration rerun proven per tenant; existing
P1/P2 tests, certification and all cross-cutting gates pass.

### Phase 2 — Tenant entry and admin shell gate (`cms-akira-shell`)

**Scope:** Create and track the dedicated shell. Implement Kernel-auth login/logout/session flow, Akira dashboard,
admin layout/navigation, authorization failures and module health. Set only this manifest to `entry_module:true`;
use no `auth_owned` and no `authentication_provider`.

**Acceptance:** selecting `cms-akira-shell` as `kernel_tenants.entry_module_id` routes tenant login/admin to Akira,
uses the tenant's Kernel `users` table, cannot enter another tenant, and can administer core Posts without any
legacy `cms` code loaded. Failed dependency migration/policy seed leaves the tenant inactive/pending and not
routable. Shell certification, auth/CSRF/tenant tests and login/admin smoke tests pass.

### Phase 3 — Native authoring gate (`cms-akira-editor`)

**Scope:** Delete `akira.content.get@1`, TinyMCE and CMS-helper paths; ship the native editor contracts, assets,
validation, normalization and sanitization; integrate authoring through core's governed Post contracts.

**Acceptance:** malicious/invalid documents fail closed, sanitize/render parity is deterministic, assets are
Akira-owned, save/replay/audit behavior is proven, no network/provider is required, and editor-only tenant
enable/disable behavior is tested.

### Phase 4 — Native presentation/navigation gates

1. **4A `cms-akira-theme`:** replace all CMS diagnostics/helpers/routes with ARK APIs, Akira admin UI and
   tenant-scoped activation. Gate on registry uniqueness, theme validation, DiSyL lint, traversal protection,
   projected-fields-only rendering and deterministic fallback to `cms-akira-posts`.
2. **4B `cms-akira-navigation`:** add menu/item migrations, CRUD/resolve capabilities and explicit projections.
   Gate on tenant isolation, cycle/orphan/order handling, valid Akira content links, governed mutations and ARK
   consumption without SQL or capability calls from themes.

4B may start only after 4A passes; each is separately tracked, certified and tenant-enabled.

### Phase 5 — Native asset/metadata gates

1. **5A `cms-akira-media`:** own upload/storage metadata, library CRUD/resolution and Entity Views. Gate on MIME,
   size, path and authorization abuse tests; tenant storage isolation; missing-file behavior; cleanup policy;
   idempotent/audited mutations.
2. **5B `cms-akira-seo`:** own metadata persistence and deterministic merge/default rules over core projections.
   Gate on canonical URL/robots/OpenGraph escaping, tenant/entity uniqueness, stale-reference behavior and
   audited invalidation.

No media/SEO row may point at or query a legacy CMS row.

### Phase 6 — Native lifecycle gate (`cms-akira-workflow`)

**Scope:** Replace `cms.content` assumptions with Akira-owned workflow keys/definitions and core entity keys;
implement evaluate and authorized transition behavior through Kernel WorkflowEngine.

**Acceptance:** valid/invalid transitions, role denial, concurrency/retry/cancel/replay, tenant isolation and
correlation evidence are tested; WorkflowEngine remains persistence/execution authority; `workbench:audit` and
runtime dispatch guard are clean; no Akira workflow table duplicates Kernel state.

### Phase 7 — Native discovery gate (`cms-akira-search`)

**Scope:** Rename the dormant adapter before tracking; remove external index calls/helpers; add local document
migration, build/upsert/delete/query contracts, projections and lifecycle integration.

**Acceptance:** content create/update/publish/unpublish/delete converges the tenant index; retries are idempotent;
query results expose only allowlisted fields; rebuild-from-core is deterministic; disabled search does not break
core; no `search.index.*` dependency remains.

### Phase 8 — Optional native assistance gate (`cms-akira-ai`)

**Scope:** Remove deleted content dependency; consume core projections and only Kernel-governed optional AI
capabilities. Freeze prompt/input/output, redaction, consent, timeout and unavailable-provider behavior.

**Acceptance:** no raw tenant/internal fields leave the projection boundary; policy/rate/size limits and audit
correlation are tested; unsafe output is not auto-published; absent AI yields a typed non-fatal response; AI is
never a dependency of another Akira member or profile.

### Phase 9 — Profile bundle gates (no Builder implementation)

Profiles contain no business tables, handlers or authority. For each profile, activation planning must compute
and validate dependency closure before making any member visible.

1. **9A minimal:** independently certify and release `core + shell + editor`.
2. **9B standard:** first remove its foreign entry/auth fields; independently certify and release
   `core + shell + editor + theme + navigation + media + seo`.
3. **9C headless:** independently certify and release `core + workflow + native search`; prove no shell/UI
   dependency.
4. **9D visual contract-only hold:** update the ignored manifest/documentation to the final native dependency
   graph, but do **not** track, certify or enable it while Builder is non-functional. Its member release gate is
   explicitly deferred into Phase 10 after Builder passes.

**Tenant installation transaction:** the tenant chooses either `cms-akira-shell` as entry or an applicable
profile in the module-selection flow. The Kernel planner resolves exact Akira dependency closure, verifies
entitlement/loadability, runs Kernel prerequisites then each member's migration ledger against that tenant DB,
seeds protocol-v2 capability policies idempotently, and only after all succeed writes tenant module activation
settings. For UI profiles it then sets `kernel_tenants.entry_module_id='cms-akira-shell'`; headless leaves the
entry unchanged. Any failure records evidence and leaves the new closure and entry switch inactive/pending.
Rerun must converge without duplicate schema, policy or data. Uninstall/deactivation preserves owned data unless
a separately confirmed purge operation is specified.

### Phase 10 — Builder last (`cms-akira-builder`), then visual-bundle release

**Scope:** Only after Phases 0–9 pass, implement the ARK composition schema/storage, revision/publish contracts,
Entity Views and shell UI. Builder reads ARK's allowed blocks, controls, slots and renderer metadata; it neither
forks the ARK engine nor becomes renderer/domain authority.

**Builder acceptance:** invalid trees/blocks/props fail closed; draft/revision/publish, optimistic concurrency,
idempotency, authorization, audit, tenant isolation and preview/published separation are proven; generated
composition validates against ARK and renders through the same ArkRendererResolver → DiSyL path; no raw PHP,
SQL, arbitrary include, script or path injection is possible. Track/certify/tenant-enable Builder only after all
checks pass.

**Final packaging sub-gate:** after—not before—the Builder acceptance result, independently certify and track
`cms-akira-profile-visual`, prove its complete closure installs from empty tenant state and activates Builder,
then permit tenant enablement. This sub-gate makes no feature/runtime changes; Builder remains the last feature
built.

## 4. Cross-cutting release rules, risks and follow-ups

### Zero-exception baseline for every member gate

- **No foreign residue:** recursive scan of the member and its templates/tests must find no legacy `cms` include,
  helper, table, route, template, authentication provider or capability, and no deleted
  `akira.content.get@1`. The established `cms.post.*@1` names are valid only with sole provider
  `cms-akira-core`; no member may shadow them.
- **Declared authority:** `owns_tables`, `reads_tables`, capability expose/depends, entities, effects, migrations,
  routes and admin contributions match runtime exactly. Cross-member reads use capabilities/Entity Views, never
  another member's SQL. Public projections are explicit allowlists and fail closed if registration/provider is
  wrong.
- **Kernel 6.x guarantees:** prove Identity/Tenant, Authority, Execution, Consistency and Evidence for every read,
  mutation and asynchronous path. Request tenant/actor identity is never accepted from payload claims.
- **Governed mutation:** protocol v2 policy (migration 016), role + CSRF enforcement, canonical payload hash,
  durable idempotency replay/conflict/in-progress semantics, same-transaction domain write and
  `kernel.audit.record@1` where supported, correlation ID and exact post-success invalidation.
- **Required commands:** member-focused lint/static/unit/integration tests; `php ikabud architecture:check`;
  `php ikabud capability:audit --json` with zero findings; `php tests/capability_authority_audit_test.php` at the
  zero real-repo baseline; `php ikabud module:certify <member>`; `php ikabud workbench:audit`; relevant
  `php ikabud theme:validate` and `php ikabud disyl:lint`; then full `composer test`. No warning waiver or changed
  expected baseline is allowed to make a gate green.
- **Operational evidence:** clear before the run and require clean `storage/logs/app.log` and audit/security log
  output after all success and negative tests; retain command transcripts, migration/policy rows and audit/event
  correlation evidence.
- **Change control:** compare recursively against a pristine worktree pinned to `8053dc1` (the existing
  `/var/www/html/applicationostest` may be used only if its commit is verified). Record only intended member,
  tests, templates/theme, docs and `.gitignore` deltas. Unrelated generated/cache/vendor files fail the gate.
- **Track and enable last:** keep the member ignored and `_enabled:false` while rebuilding. At its own successful
  gate, remove only that path from `.gitignore`, commit its complete source/tests/migrations/assets, certify it,
  and prove explicit per-tenant activation. Repository bundling never means automatic tenant installation.

### Principal risks and required containment

- **Generic capability collision:** legacy CMS and Akira may coexist in a Kernel deployment. Assert the unique
  provider/authorization policy for core's already-established `cms.post.*@1`; all new contracts use the Akira
  namespace and activation must fail on ambiguous providers.
- **Tenant provisioning skew:** dedicated tenant DBs may lack Kernel migrations 011/015/016 or audit parity.
  Keep activation pending until prerequisite/migration/policy checks pass; test partial failure and rerun.
- **Ignored-scaffold blind spots:** disabled source is still scanned by the authority auditor. Foreign calls must
  be removed before tracking/enabling, and ignored files must never be treated as evidence of completion.
- **Projection leakage/XSS/upload abuse:** maintain explicit DTO allowlists, output escaping, sanitizer tests and
  storage path/MIME/size controls; ARK/DiSyL never receive tenant IDs, provider internals or undeclared columns.
- **Lifecycle/event drift:** search, workflow, SEO and Builder can become stale after content changes. Freeze
  event/effect semantics, make consumers replay-safe and provide deterministic reconciliation/rebuild commands.
- **Scope pressure:** new content types, owned auth, remote search, AI providers and alternate render engines are
  not implicit requirements. Each requires a separate authority/schema/security gate and cannot delay the
  independent Post CMS path.

### Recorded follow-ups (not CMS Akira dependencies)

1. `MAIN-CMS-REPO` adoption or capability additions remain relevant only to consumers of the **legacy `cms`**
   product, if those consumers still need them. Nothing from that work is backported, copied or required here.
2. Dedicated-DB provisioning parity for Kernel migrations 011/015/016 and audit storage remains a Kernel
   operations workstream and is a hard prerequisite for affected Akira tenants.
3. If Kernel later standardizes native search or additional AI contracts, Akira may adopt them through an
   explicit compatibility gate; current independence and local fallback remain mandatory.
4. Any future Akira-owned admin identity store requires a new entry/auth ADR and full `auth_owned` conventions;
   the authoritative plan today is Kernel-auth shell.
