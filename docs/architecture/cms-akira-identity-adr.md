# CMS Akira Identity — Architecture Decision Record (ADR)

Date: 2026-09-09
Status: **ACCEPTED** — items R1–R7 pending implementation (each gated, see roadmap)
Scope: CMS Akira suite identity — form and function on Ikabud Kernel OS 6
Evidence: three-model panel debate (2026-09-08) + final adjudication by DeepSeek Pro and GPT Sol (2026-09-09)

## Context / Problem

CMS Akira must not become "an edified ripoff of WordPress" — modular and spaghetti-free,
but making no real difference. Question to resolve: **what is the best FORM and FUNCTION of
CMS Akira given the Ikabud 6 kernel, and is it making sense of the kernel?**

## Debate outcome (multi-model, convergent)

The kernel genuinely provides five properties a conventional CMS cannot enforce:
1. Versioned, policy-gated capability contracts (`CapabilityAuthorizationRegistry` / `CapabilityBus`).
2. Idempotent writes with optimistic concurrency (`kernel.idempotency.*`).
3. A capability-driven state-machine workflow (`WorkflowRuntime` / `WorkflowEngine`).
4. Durable, tenant-scoped audit + event outbox (`kernel.audit.record@1`, `kernel_durable_event_outbox`).
5. Per-tenant DB isolation by construction (control plane + TenantProvisioner + ModuleInstallService).

Today Akira uses these as **parallel infrastructure beside a WordPress-shaped surface** rather
than as the single path an editor's click travels. The primary create/publish journey bypasses
the workflow differentiator (binary `status` select + direct `akira.post.publish@1` flag-flip),
and non-admin editorial roles are unreachable behind a hard `role === 'admin'` shell gate.

### Collapse condition

The thesis collapses if **any primary UI or API path can create, publish, read, or render
content while bypassing the kernel's authoritative lifecycle, tenant-policy context, or
projection contract.** At that point Akira is a conventional CMS with sophisticated optional
plumbing.

## Decision — Identity

> **CMS Akira is the tenant-isolated, governed content-operations layer of Ikabud OS 6:**
> one install provisions N fully isolated sites (per-tenant DB, own admin, own module closure),
> where **every piece of content is a schema-declared, role-governed, idempotent, audited state
> transition rendered through a deterministic ARK/entity-view → DiSyL projection**.
>
> **Form** stays deliberately WordPress-familiar (posts list, editor, publish rail, dashboard,
> Tailwind + Alpine + module palette) — editors should not relearn a paradigm.
> **Function** is WordPress-impossible: there is no `UPDATE` to a content table that is not a
> governed transition, and no write that can be lost, double-applied, or cross-tenant-leaked.

### Three non-negotiable function commitments

1. **One authoritative lifecycle.** Create, submit, approve, reject, publish, unpublish are
   governed transitions (role-gated, concurrency-protected, idempotent, audited). No direct
   status-flip bypass exists; `status` is a projection of workflow state, not the source of truth.
2. **One governed tenant boundary.** HTTP, capability dispatch, reads, writes, jobs, and
   rendering use the same kernel tenant + policy context; cross-tenant access is impossible by
   construction. No per-module tenant re-derivation.
3. **One declared publication boundary.** Content is validated against declared models and
   reaches the public site only through tenant-partitioned ARK/entity-view projection contracts.

## Items to resolve (R1–R7)

| # | Decision | Recommended answer | Gate | Acceptance (testable) |
|---|----------|--------------------|------|------------------------|
| R1 | Workflow authoritative lifecycle | Route submit/approve/reject/publish/unpublish through `akira.workflow.transition@1`; `status` = projection; delete direct flag-flip | **P0 blocker** | Approved post cannot be pushed live by non-publish role; idempotent double-publish = one transition/audit/outbox; editor renders `evaluate()` allowed actions |
| R2 | Non-admin editorial roles | Shell auth from workflow `allowed_actions`, not hard `admin` gate | P0/P3 | Playwright: author drafts+submits (no publish), admin approves, list shows state + audit |
| R3 | Policy row = sole role authority | Delete duplicate handler/shell role checks; seed policies for all content roles; bind `caller_module` | P0/P3 | Revoke role via `policy_version: 2` blocks write at bus with zero code; CI fails per-module role checks |
| R4 | One kernel governed-write convention | Kernel owns CSRF field helper + policy seeding (`ModuleInstallService::policySeeder`); `requires_protocol:"v2"` is the only governance declaration | **P0 blocker** | Remove live `_csrf_token` in `cms-akira-theme/helpers.php:728`; new v2 module governed with zero per-module code |
| R5 | Govern reads at policy layer | Seed read policies (`akira.post.get/list`, `entity.*.post`) allowed_roles + tenant, no idempotency | P3/P5 | Role not allowed drafts cannot read `include_unpublished` |
| R6 | HTTP-only two-tenant acceptance journey | Real router → DiSyL render → real session CSRF → real bootstrap tenant resolution; no context injection, no source-text greps; fix DiSyL Alpine parsing at engine | **P0 exit** + every release | Provision tenant B, role-separated publish journey, tenant A cannot read/mutate B — over HTTP only |
| R7 | Roadmap re-prioritization | P0 repair → P1 content model → P4+P5 projection/public → P3 policy UI → thin P2 → defer P6 | Release scope | No WP-clone surface merges before R1–R3 + R6 proof green (CI differentiation gate) |

## Addendum 2026-09-09 — R1 architecture decision (tenant-colocated authoritative lifecycle)

R1 implementation was initially BLOCKED on an atomicity concern (workflow state in
"control-plane PDO" vs tenant content DB). Investigation resolved it:

- `WorkflowRuntime` (kernel/WorkflowRuntime.php) persists via `runPrimaryDbOperation()`
  → `app()->db()`, which in a tenant request is the **tenant DB** (e.g. `akira`). Its
  tables `workflow_definitions` / `workflow_instances` / `workflow_transition_logs` are
  provisioned per-tenant (tenant-safe artifact `006_kernel_workflow_tables.sql`); tenant
  DBs already hold them. **Not control-plane.**
- `cms-akira-workflow` transition (helpers.php `cawTransition`) already runs idempotency
  claim + `WorkflowRuntime::transition()` + audit inside **one `app()->db()` transaction**
  (single tenant-DB PDO), per its README and passing contract tests.
- Therefore the post `status` projection is NOT cross-DB: an `UPDATE cms_akira_posts SET
  status = …` can be added inside that same transaction, atomically with the transition,
  idempotency, and audit.
- The kernel `WorkflowEngine` (`workflow_runs` / multi-step, base DB) is a separate
  orchestration concern and is NOT used for the Akira editorial lifecycle.

**Decision:** tenant-colocated authoritative lifecycle. Workflow state (`workflow_instances`)
and the projected content state (`cms_akira_posts.status`) live in the same tenant DB and are
written in one transaction on `app()->db()`. No cross-database projection is needed; if a
future control-plane side effect is required, use the durable outbox
(`kernel_durable_event_outbox`, tenant-provisioned) — never for the status projection.

## Consequences

- P0/P1 scope is not new admin screens; it is collapsing the governance seams first.
- Reads must eventually be governed at the policy layer; writes are already v2 (27 policy rows).
- Reference-parity pressure (media/menus/settings/page-builder) is a pull toward WP-clone
  surface and must be gated behind the R1–R3 + R6 proof (R7 ordering rule).
- Akira "makes sense of the kernel" only when the kernel guarantees are the single path an
  editor's click travels — otherwise it is a well-engineered cost center.

## Related docs
- docs/kernel/cms-akira-reference-cms-adoption-roadmap.md (phase list + ground rules; R1–R7 folded in)
- docs/architecture/product-suite-extension-adr.md (suite extension model this identity builds on)
