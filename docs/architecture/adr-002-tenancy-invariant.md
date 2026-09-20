# ADR-002: Tenancy invariant — dedicated database with tenant discriminator columns

- **Status:** RATIFIED (2026-09-13)
- **Supersedes:** the implicit and contradictory guidance previously spread across instructions and docs
- **Evidence base:** `.ai/phase0-reconciliation-report.md` (measured on live tenant 54, 2026-09-13)

---

## Context

Two statements about tenancy circulated in this repository and were treated as mutually exclusive:

1. *"Every tenant has its own database; tenant tables have no `tenant_id` — the tenant **is** the database."*
2. *"Tenant-scoped data carries `tenant_id`."*

A 2026-09-13 architecture brief adopted (1) as an architectural invariant without checking the schema.
Both external reviewers reasoned from it. **It was false**, and the review round had to be re-run.

This matters beyond one document: the invariant determines how every future module scopes queries,
writes migrations, and reviews for cross-tenant leakage. Leaving it implicit produced a factual error
that propagated into a research brief and two model answers before anyone measured.

## Measured evidence (Phase 0, tenant 54)

| Fact | Measurement |
|---|---|
| Kernel database | `app()->db()` → **`ikabudsix`** |
| Tenant database | `dbForTenant(54)` → **`akira`** |
| Topology | **Two distinct databases** — dedicated per tenant |
| Kernel DB contamination | `SHOW TABLES` on `ikabudsix` → **0** `cms_akira_*` tables |
| Akira tables in the tenant DB | **12** |
| `tenant_id` presence | **12 / 12**, `int unsigned NOT NULL` |
| `tenant_id` in uniqueness | Composite unique keys throughout — `uq_tenant_slug(tenant_id,slug)`, `uq_media_tenant_key(tenant_id,media_key)`, `uq_search_tenant_entity_document(tenant_id,entity_type,document_key)`, `uq_builder_tenant_entity(tenant_id,entity_type,entity_key)`, `uq_seo_tenant_entity(tenant_id,entity_type,entity_key)`, `uq_menu_tenant_slug(tenant_id,slug)`, `uq_item_tenant_key`, `uq_tenant_type_slug`, `uq_tenant_post_revision`, `uq_tenant_post_taxonomy` |

Both claims are true simultaneously. The disagreement was never factual — it was a missing decision.

## Decision

**Dedicated database per tenant is the deployment topology. `tenant_id` is retained as a mandatory
tenant discriminator inside every tenant-scoped table, and MUST participate in the table's uniqueness
constraint wherever a natural key exists.**

Rationale:

1. **Dedicated database is the isolation boundary.** It is what protects tenants from each other in
   production, and it is what Phase 0 measured. Application code MUST NOT treat `tenant_id` as a
   substitute for it.
2. **`tenant_id` is defence-in-depth, not decoration.** It is the second line of defence if a
   connection ever resolves to the wrong database — the failure mode already encountered once, when
   `db()` lacked the identity guard that `dbForTenant()` had. A query filtered by `tenant_id` fails
   safe in that scenario; an unfiltered one silently returns another tenant's rows.
3. **It preserves portability.** The composite-unique shape means the schema would remain correct
   under a shared-schema deployment, which is a plausible future for cost or density reasons. That
   option is preserved deliberately rather than foreclosed.
4. **It is already the reality.** Twelve of twelve tables carry it inside composite uniques. Ratifying
   the existing design is cheaper and safer than migrating twelve tables to remove a column that
   provides real protection.

## Consequences

**Module authors MUST:**

- Include `tenant_id` in every new tenant-scoped table, `NOT NULL`, `int unsigned`.
- Include `tenant_id` as the **leading column** of any composite unique key over a natural key
  (`UNIQUE (tenant_id, slug)`), so uniqueness is per-tenant by construction.
- Filter every read and write by `tenant_id`, even when the connection is believed to be tenant-scoped.
- Never assume a dedicated database removes the need for the discriminator.

**Reviewers MUST:**

- Reject a new tenant-scoped table that omits `tenant_id`.
- Reject a unique key over a natural key that does not lead with `tenant_id`.
- Treat a query against tenant data without a `tenant_id` filter as a defect, not a style issue.

**Explicitly permitted:** the deployment may run in either topology. Nothing in this ADR requires a
change to the current dedicated-database deployment, and nothing forbids a future shared-schema
deployment provided the discriminator rules above hold.

## What this ADR does NOT decide

- **Which topology is used in production for new tenants.** Today it is dedicated. Switching is a
  separate decision with its own consequences (connection limits, provisioning, backup granularity).
- **Whether the kernel's own tables should carry a discriminator.** They are control-plane and
  single-instance; that is out of scope here.
- **The `canonical_domain` question.** Phase 0 found that column empty for tenant 54 while host
  routing works, so host→tenant resolution uses some other mechanism that remains **unverified**.
  Recorded as an open question, not decided here.

## Why this is recorded rather than assumed

The cost of the missing decision is measured: a research brief, two independent model answers, and a
reconciliation round were built partly on a false invariant, and the error was caught only when
someone read `SHOW COLUMNS` instead of the instructions. An ADR makes the invariant reviewable and
gives reviewers a citation to enforce against.

**Correction to be applied wherever the old rule appears:** any instruction or document stating
"tenant tables have no `tenant_id`" is wrong and should be updated to reference this ADR.
