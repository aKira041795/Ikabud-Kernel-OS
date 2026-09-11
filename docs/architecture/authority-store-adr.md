# ADR: authority-store ownership and resolution

status: decisions taken (2026-09-11, three-model debate); implementation not started
deciders: product owner / architecture chair
scope: policy declarations and grant state used by `CapabilityAuthorizationRegistry`

> The five questions this ADR originally left open were answered on 2026-09-11 after a debate between
> GPT Sol, Claude and DeepSeek Flash. See **Decisions** below. The **Decision** section immediately
> following scopes only the P2-closure slice that has already shipped — it is a subset of, and not in
> conflict with, the later answers.

## Context

A policy declaration and an authority grant are different facts:

- A **declaration** is structural module information: a capability requires a role, protocol,
  provider activation, and bounded callers. Code and module contracts own that statement.
- A **grant state** is an operator-controlled authority decision: the declared integration is
  `granted`, `suspended`, or `revoked`. The tenant's authority store owns that decision. Code may
  create a new declaration as granted, but may not silently restore or rewrite a suspended or
  revoked grant.

The current registry does not resolve one stable store. `CapabilityAuthorizationRegistry::db()`
falls back to `app()->db()`. Measurement for the same tenant found that a web request resolves the
tenant database while CLI resolves the kernel database. Consequently the effective decision may
change with execution context rather than authority scope. This is a defect, not an implicit
multi-tenancy policy.

## Decision

1. Policy declarations belong in code/module contracts and are projected into the registry for
   runtime enforcement.
2. Grant state belongs to the tenant's authority store and is changed only by an explicit audited
   transition carrying actor, timestamp, and reason.
3. This slice adds durable lifecycle semantics to the existing registry but **does not move policy
   rows or select a new physical store**. Moving every row to tenant databases could destroy valid
   centralized-authority semantics; moving every row centrally could erase tenant sovereignty.
4. Callers must not treat `app()->db()` as the long-term authority-store selector. P3/P5 must first
   introduce an explicit, context-independent authority-store resolution contract.

## Consequences

- Declaration seeding can add a new granted row and refresh declaration fields on a granted row.
  It cannot alter a suspended or revoked row.
- Authorization denies suspended and revoked grants with state-specific reasons.
- Operator grant-state changes use `kernel.audit.record@1`; no parallel audit log is introduced.
- Web and CLI can remain physically ambiguous during this bounded closure slice. That known defect
  must not be used as evidence that grants are tenant-correct.
- Store migration, replication, and reconciliation are deliberately out of scope here.

## Requirements for P3 and P5

Before delegated actors (P3) or extension grants (P5) ship, the authority-store contract must:

- resolve the same logical authority scope for web, CLI, scheduled jobs, events, services, and
  capability dispatch;
- identify tenant, grant issuer, grantee/actor, capability, subject scope, constraints, expiry,
  revocation state, and policy version without relying on ambient DB selection;
- define which decisions are tenant-local and which may be centrally administered;
- preserve explicit audit attribution and revocation across every execution context;
- define failure and consistency behavior when declaration and grant-state stores are unavailable
  or temporarily disagree; and
- make Workbench inspect the same effective decision the runtime enforces.

## Decisions (chair, 2026-09-11 — after a three-model debate)

Panels: GPT Sol (OpenAI), Claude Code Reviewer (Anthropic), DeepSeek Flash. Brief and verbatim
answers: `.ai/authority-store-debate.brief.md`, `.ai/authority-store-sol.panel.md`,
`.ai/authority-store-flash.panel.md`. The panels converged on three questions and split on two; the
split was adjudicated and the losing argument is recorded rather than dropped.

### D-Q1 — Federated, with containment **materialized** into the tenant store

Tenant grant state is authoritative and lives in the **tenant's** database. The control plane holds
containment (withdrawal of a provider/artifact), which is **materialized into each tenant's authority
store** through an audited, narrow control→tenant sync carrying a per-tenant acknowledged control
revision. "Tenant's acknowledged revision is stale" is itself a fail-closed condition.

**Rejected: a live deny-only overlay read at dispatch.** All three panels' first instinct was a live
overlay; the third panel broke it. Runtime authorization opens exactly one connection today
(`CapabilityAuthorizationRegistry::db()` → `app()->db()`); adding a control-DB read per dispatch
doubles concurrent connections against Bluehost's `max_user_connections` (a limit the code already
retries around), and forces an impossible choice — fail-closed lets any control-plane blip deny every
tenant, fail-open lets loss of connectivity bypass central suspension. It would also force Workbench
to read the control DB from tenant context, re-creating the very context-dependent read this ADR
exists to remove.

**Also rejected: "deny-only" as a structural guarantee.** The control plane reaches tenant databases
with the same `kernel_tenant_db_connections` credentials, which are equally capable of writing a
grant, and MySQL 5.7 has no row-level security to enforce the asymmetry. Deny-only is a *convention
enforced by code review and audit*, not a property of the store — so it must be stated as such.

### D-Q2 — Nothing is centrally issued; containment is centrally **withdrawn**, keyed on the contract

No central actor may ever grant authority over tenant data. Central authority may withdraw/contain an
identifiable artifact, and that propagates as **denial only**.

A central denial keys on the **capability contract** — `capability_id` + `capability_version` +
`provider` — **never** on `policy_version` or `grant_id`. Otherwise a routine module upgrade or a Q5
re-grant would silently lift a containment.

Provisioning materializes real `granted` rows into the tenant's own store inside the tenant's
transaction; runtime never reads the control plane to *allow*.

### D-Q3 — Materialize immutable declaration revisions; grants carry a pointer and a hash

Store declarations as **immutable data in the tenant database**, written at install/activation.
Runtime never joins executable PHP or manifests during authorization.

```
capability_declarations(
  capability_id, capability_version, provider, revision, declaration_hash,
  caller_module, allowed_roles, requires_protocol, provider_activation_required, installed_at
)                      -- one immutable row per revision

grant row carries: grant_id (new identity), capability_id, capability_version, provider,
  declaration_revision (readable integer), declaration_hash, grant_state,
  actor, reason, granted_at, updated_at
                       -- and NO roles / callers / protocol of its own
```

`authorize()` reads declaration + grant from the tenant store and fails closed when
`grant.declaration_hash !== sha256(declaration payload)`. A readable `declaration_revision` integer
accompanies the hash because hashes are opaque in SQL.

**Prerequisite, and the real first work item:** *declarations are not currently data.* `allowed_roles`
and `caller_module` appear in **zero** `module.json` files; only `requires_protocol` does (8 files).
The two `capabilities.policy` blocks that exist are `allow_callers` provider-selection policy consumed
by `CapabilityBus::applyPolicy()` — a different schema. The tuple `seedPolicy()` writes exists only in
the 8 modules' PHP seeders. "Join from contract" therefore requires a **new manifest schema**, not
merely the removal of the seeders.

### D-Q4 — Declaration change: narrow immediately, widen only by re-grant

Reconciliation happens in the **same tenant transaction as the install**, not on every dispatch. A
declaration change installs a new immutable revision and diffs it against each grant's pinned revision:

| Direction | Effect |
|---|---|
| **narrowing** | applies immediately — audited *system* transition, no operator action |
| **widening** | `grant_state = declaration_drift` → **denied**; requires an audited re-grant with a **new grant identity** |
| suspended | stays suspended on its pin; resuming takes the stricter of {pinned, live} |
| revoked | terminal — never touched by a declaration change |

Rationale for the asymmetry: the safe direction of an inconsistency is *deny*. Auto-narrowing only
removes authority; auto-widening creates it. Runtime admits only an exact
`declaration_revision` ↔ installed-active-revision match; the effective decision is always the stricter.

### D-Q5 — Revocation is terminal; re-granting is a new identity, not a state flip

`revoked → granted` is **forbidden**. Re-granting creates a **new grant identity** issued by an actor
**distinct from the revoker**, with a mandatory reason referencing the revoked identity. Descendants
of the revoked grant stay dead and are never automatically relinked. Dual control (two distinct
approvers) applies only to a **declared** `sensitive` class — not universally, because a
single-administrator tenant would otherwise be unrecoverable, and two logins for one human is the
appearance of safety rather than its substance.

Grant identity must be **persisted on the grant**, not derived from `app()->user()` at write time.
Today `transitionGrantState()` asserts only `id > 0` against the ambient user, which makes revocation
impossible from any non-web context and makes the rule unenforceable where it matters.

### The one precondition that makes all five hold

**Replace ambient resolution with an explicit, transportable authority scope**
(`{tenantId, actor, declarationRevision}`), resolved identically for web, CLI, cron, queue, services
and Workbench. This is currently **false**: `CapabilityAuthorizationRegistry::db()` falls back to
`app()->db()`, which is tenant-aware only for web requests because `DatabaseManager::db()` derives its
target from the request tenant; under CLI the resolver falls through to a configured default.

Until that selector exists, none of the five decisions can be enforced.

## Defects found while deciding (verified, not yet fixed)

The debate surfaced four defects that were invisible before it. Each is verified in the repository:

1. **CLI/cron contaminates the kernel authority store.** Module helpers run their seed functions at
   file scope, and `seedPolicy()` writes `app()->db()` — which in a CLI context is the **kernel**
   database. Measured: the kernel table holds **46 rows, 44 of them `akira.*`**, against the tenant's
   43. The observed "two stores" is therefore not a deliberate federation; one of them is accidental
   contamination. Any declaration-projection design must stop seeding on helper load.
2. **`seedPolicy()` rewrites live declarations on every request, not just at upgrade.** Module helpers
   are loaded per request and the upsert refreshes `caller_module` / `allowed_roles` /
   `requires_protocol` (and `updated_at`) whenever `grant_state = 'granted'`. A deployed module change
   therefore **silently widens live grants**, and even a no-op request rewrites the authority table.
   This is the load-bearing reason D-Q3 materializes at install time.
3. **`replaceActiveRowRoles()` re-grants suspended/revoked rows mid-clone.** It inserts the N+1 clone
   through `seedPolicy()` (which writes `grant_state = 'granted'`) and only afterwards restores
   non-granted states; the old version is deactivated last, and `resolvePolicyVersion()` picks
   `MAX(policy_version) WHERE is_active = 1`. Its "the caller owns the transaction" contract is
   documentation only — the method never checks `inTransaction()`. Verified that the current caller
   *does* open a transaction, so this is latent rather than firing, but a future caller that forgets
   makes the window observable to concurrent requests, and `kernel.audit.record@1` already dispatches
   mid-window.
4. **The declaration tuple in D-Q3 is nowhere in the manifests** (see D-Q3's prerequisite).

## Still open for the chair

1. Exact per-tenant control→tenant sync mechanics for containment (D-Q1) and the acknowledged-revision
   marker, once the authority scope from the precondition exists.
2. Which capabilities are `sensitive` for dual control (D-Q5) — requires a risk catalogue that does not
   exist yet.
3. Whether `capability_authorization_policies` remains kernel-escalated via `withKernelTableAccess()`
   once it lives in a tenant database; if that escalation is ever removed, module-originated policy
   reads will be denied by the module access firewall.
4. Whether `kernel_tenant_db_connections` credentials are usable from a background process with no
   HTTP request, which the containment sync (D-Q1) depends on.

## Superseded

The five questions originally listed here were answered above; the original framing (choose between
per-tenant, central, and federated) assumed the store was the defect. The debate established that the
**address** was a symptom: the defect is ambient resolution, which is why the answers above are
consequences of one move rather than five independent choices.

