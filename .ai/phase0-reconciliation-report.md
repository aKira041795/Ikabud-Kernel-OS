# Phase 0 — deployed-state reconciliation report

**Tenant:** 54 (`akiracms-001`) · **Date:** 2026-09-13 · **Author:** chair
**Purpose:** replace every source-derived claim about Akira with a deployed-state measurement, before any
feature is planned as fact.
**Scope discipline:** measurement only. **No code was changed, no fix applied, no capability added.**

Rule applied to every row: `verified` requires its own evidence line. Anything not measured is
`unverified` and is not a fact.

---

## P0.1 — Build identity

| Claim | State | Evidence |
|---|---|---|
| Docroot serving the tenant | **verified** | `/var/www/html/ikabudsix`; `public/` resolves to `/var/www/html/ikabudsix/public` |
| Deployed commit | **verified** | `git rev-parse HEAD` = `d29c4c0495f9ee95b9fd15e6afc62b5c9cfebb6f`, branch `feat/akira-editorial-and-authority-coverage` |
| Deployed == checkout | **verified, with a caveat** | Same filesystem — but the tree is **DIRTY: 9 modified/untracked files**. The running code is *not* a clean commit |
| Install epoch | **verified** | `storage/.installed` mtime 2026-08-20 12:17:39 |
| Compiled caches lag source | **verified** | `storage/cache/compiled` 14 entries, newest 2026-09-13 19:18:56 |

**Consequence:** "the deployed commit" is not a reproducible artifact. Any reproduction must record the
9 dirty files as well as the hash.

## P0.2 — Database topology (the central reconciliation)

| Claim | State | Evidence |
|---|---|---|
| Kernel DB | **verified** | `app()->db()` → `ikabudsix` |
| Tenant DB | **verified** | `dbForTenant(54)` → `akira` |
| **Topology** | **verified** | **DEDICATED DATABASE per tenant** (two distinct databases) |
| Kernel DB free of Akira tables | **verified** | `SHOW TABLES` on `ikabudsix` → **0** `cms_akira_*` |
| Akira tables in tenant DB | **verified** | **12** |
| **`tenant_id` present** | **verified** | **12 / 12** tables carry it — `int unsigned NOT NULL` |
| `tenant_id` in unique keys | **verified** | Composite uniques throughout: `uq_tenant_slug(tenant_id,slug)`, `uq_media_tenant_key(tenant_id,media_key)`, `uq_search_tenant_entity_document(tenant_id,entity_type,document_key)`, `uq_builder_tenant_entity(tenant_id,entity_type,entity_key)`, `uq_seo_tenant_entity(...)`, `uq_menu_tenant_slug(...)`, and more |
| Migration ledger | **verified** | `_migrations` = 39 rows, earliest 2026-09-08 15:45:31 |
| `kernel_tenants` columns | **verified** | `id, tenant_key, status, entry_module_id, canonical_domain, admin_email, created_at, updated_at` |
| Tenant record | **verified** | `id=54, tenant_key=akiracms-001, status=active` |
| `canonical_domain` | **verified — and empty** | The column is **empty** for tenant 54, yet `akiracms.test` resolves to it. Host→tenant routing does **not** use this column; the mechanism is unverified |

### The reconciliation this phase existed to make

The brief asserted *"every tenant has its own database — no `tenant_id` columns"* and both models
reasoned from it. **Measured truth: both are true simultaneously.** The deployment is
**dedicated-database**, and the schema is **shared-schema-shaped with `tenant_id` in every composite
unique key**.

That is not an accident — it is a design that would survive a move to shared schema. But it means the
architecture has **no ADR stating which invariant is intended**. Until one exists, every future
contributor faces the same false dichotomy this brief fell into.

## P0.3 — Runtime module state

| Claim | State | Evidence |
|---|---|---|
| Modules with tenant settings rows | **verified** | **8**: builder, core, editor, media, navigation, seo, shell, theme |
| Their enabled flag | **verified** | All 8 `_module_enabled = true`, all `_module_activation_state = "committed"` |
| **Not activated for this tenant** | **verified** | `cms-akira-ai`, `cms-akira-search`, `cms-akira-workflow`, and all 4 profiles have **no rows** |
| **The manifest anomaly — explained** | **verified** | Every module manifest declares `_enabled: false`, yet all 8 run. **The manifest `_enabled` is a kernel-control-plane default, not a tenant switch.** Per-tenant activation lives in `tenant_module_settings` |

This closes the open question recorded on 2026-09-13 ("the loading mechanism is unexplained"). The
apparent contradiction was two different scopes being read as one.

**Correction to the roadmap's own premise:** `navigation` and `seo` are activated, but `search` and
`workflow` are **not** — so "workflow is embedded in the post form" (a claim both models repeated)
cannot hold for this tenant. The `akira.workflow.transition` audit rows exist, so transitions *do*
occur — meaning the workflow capability is reached without the workflow module being activated for the
tenant. **That is unverified and is the single most surprising result of this phase.**

## P0.4 — Authority state

| Claim | State | Evidence |
|---|---|---|
| Tenant policy rows | **verified** | **1514** total, **51** active |
| Policy churn | **verified** | **1463 inactive historical rows** on a tenant created 5 days ago |
| Theme activation policy | **verified** | Exactly **1 active** row for `akira.theme.activate@1` with `allowed_roles = admin,administrator,superadmin`, `caller=cms-akira-theme`, `grant=granted` |
| Theme customize policy | **verified** | 1 active row, `allowed_roles = admin,editor,administrator,superadmin` |
| Kernel DB contamination | **verified** | Kernel policy table still holds rows; a backup `authority-base-contamination-20260911-112700.{json,sql}` exists from the 2026-09-11 incident |

### The role-gate defect, now precisely characterised

**The nav contribution declares fewer roles than the capability policy admits.**

- Nav declaration: `roles: ["admin"]` → excludes `administrator` → **link hidden**
- Active policy row: `allowed_roles = admin,administrator,superadmin` → **the destination would authorize**

So the defect is **not** an authorization failure. It is a *declaration* narrower than the *policy it
points at*, which makes a working, authorized surface unreachable. That is a different bug class from
anything previously recorded, and it will recur in every contribution until declarations and policies
are reconciled by construction rather than by hand.

## P0.5 — Rendered UI (the measurement both models demanded)

| Claim | State | Evidence |
|---|---|---|
| Nav destinations for `administrator` | **verified** | **9** |
| Both models' source prediction | **confirmed** | Source predicted 9 — the rendered HTML agrees |
| The brief's "14" | **refuted** | The page has **15** total hrefs; 6 are not nav: `/`, `/auth/logout`, `/cms-akira-shell/posts/new`, and 3 per-post edit links |
| Every nav destination responds | **verified** | All 9 return **HTTP 200** |
| Theme Studio in the nav | **verified absent** | Confirmed at the rendered level, not inferred |
| Nav destinations measured | Dashboard, Posts, Categories, Content types, Media, Compositions, Permissions, Users, Module health | — |

**Phase 0 acceptance criterion met:** the sidebar count is reproduced from authenticated HTML and
matches the source prediction, with the inflation explained (counting page hrefs instead of nav
destinations).

## P0.6 — Theme state

| Claim | State | Evidence |
|---|---|---|
| Packages on disk | **verified** | **5**: `akira-ark` 1.0.0, `akira-ark-demo` 1.0.1, `akira-editorial` 1.0.0, `ark-renderer-fixture`, `cms-akira-posts` |
| Executable content in any package | **verified absent** | **0** `.php/.phtml/.sql/.sh` files across all 5 — the declarative sandbox holds in practice |
| Active theme | **verified** | `active_theme_slug = "akira-editorial"` |
| External URL dependencies | **verified** | Active package references **NONE** (zero CDN) |
| **Install / admission path** | **verified ABSENT** | No install-like capability among declares; source grep for `akira.theme.install`, `theme_admit`, `themePackageInstall` → **no matches** |
| activate / customize / validate / rollback | **verified present** | Handlers and routes exist for all four |
| **Duplicate theme identity** | **verified defect** | `akira-ark-demo` (v1.0.1) declares `name = "akira-ark"` — two distinct packages claim one name |

**This settles the director's complaint.** "No themes install view" is **correct**: there is no
admission path. My earlier statement that it "exists and is only hidden" was wrong, and the debate
caught it.

## P0.7 — Operability

| Claim | State | Evidence |
|---|---|---|
| Audit rows | **verified** | **132** |
| Idempotency keys | **verified** | **20** |
| Events | **verified** | **4** |
| Durable outbox | **verified** | **0** |
| Jobs | **verified** | **0** |
| Backup artifacts | **verified** | 4, including the 2026-09-11 authority-contamination backup |
| app.log | **verified** | 1286 lines |
| error.log | **verified clean of product errors** | 1 line — **caused by this phase's own probe script**, not the product |

### NEW DEFECT — provenance gap in the audit trail

**10 of 132 audit rows (7.6%) carry no actor.** By action:

| Action | Rows with no actor |
|---|---|
| `akira.theme.activate` | **5** |
| `akira.builder.create` | 3 |
| **`akira.post.publish`** | **1** |
| `akira.post.create` | 1 |

`akira.post.publish` with no actor is the significant one. This product's central claim is *provable
history — who authorized this, under which policy*. A publish operation that records no actor is a
direct contradiction of that claim, found only because Phase 0 looked at the data rather than the code.

**This was not visible from source.** It is the strongest argument for having done Phase 0 at all.

---

## Summary: what Phase 0 changed

| Prior belief | Measured reality |
|---|---|
| "No `tenant_id` columns" | 12/12 tables have it, in composite unique keys |
| "14 sidebar links" | **9**, reproduced from authenticated HTML |
| "Theme install exists, hidden by a role gate" | Install **does not exist**; only activate/customize/rollback are hidden |
| "Theme Studio is a complete feature" | Incomplete — no admission path |
| "The module loading mechanism is unexplained" | **Explained**: manifest `_enabled` is control-plane, tenant rows are authoritative |
| "Workflow is embedded in the post form" | **Contradicted** — workflow is not activated for tenant 54, yet transitions are audited |
| Audit trail is trustworthy | **7.6% of rows have no actor**, including a publish |

## Acceptance criteria — self-assessed

| Criterion | Result |
|---|---|
| Reproduce the exact sidebar count from authenticated HTML, matching or contradicting source's 9/10 with explanation | **PASS** — 9, inflation explained |
| State tenant 54 topology unambiguously and resolve `tenant_id` with `SHOW CREATE TABLE` output | **PASS** — dedicated DB + tenant_id in composite uniques |
| Name the deployed commit and whether it equals this checkout | **PASS, with caveat** — hash named; tree is dirty, so not a clean artifact |
| Confirm or refute any theme package admission path in the deployed kernel | **PASS** — confirmed absent |
| Fail if any Akira table or tenancy claim lacks its own evidence line | **PASS** — every row carries one |

## Newly opened questions (not answered here, deliberately)

1. **Which tenancy invariant is intended** — dedicated DB, `tenant_id`, or both? Needs an ADR. This is
   the question that produced the brief's central error.
2. **How is `akira.workflow.transition` reached** when the workflow module is not activated for tenant
   54, and why do its audits sometimes lack an actor?
3. **Why is `canonical_domain` empty** while host routing works — what actually resolves the host?
4. **Why 1463 inactive policy rows** in 5 days, and is that churn intended?
5. **`akira-ark-demo` claiming `name=akira-ark`** — which package is authoritative?

## Recommended next phase

**P1 as scoped, unchanged** — but now with a precise target. The role-gate fix is not merely a
one-word change: P1 should also **declare `POST /cms-akira-theme/activate`** in `capabilities.routes`
(verified undeclared) and reconcile contribution role declarations against policy rows so this defect
class cannot recur. The provenance gap found in P0.7 deserves its own bounded slice, and should be
sequenced **before** any phase that claims provable history as a feature.
