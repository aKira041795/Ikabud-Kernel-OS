# ADR: authority-store ownership and resolution

status: accepted direction; storage migration deferred (2026-09-11)
deciders: product owner / architecture chair
scope: policy declarations and grant state used by `CapabilityAuthorizationRegistry`

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

## Open questions for the chair

1. Is the authority store physically per-tenant, centrally hosted with tenant partitioning, or a
   federated combination with an explicit precedence rule?
2. Which grant classes, if any, may be centrally issued or centrally suspended across tenants?
3. Is a declaration projection copied beside grant state, or joined at runtime from signed/versioned
   module contracts?
4. What consistency and recovery model applies when code declarations change while a tenant grant
   is suspended or revoked?
5. Which actor may re-grant after revocation, and does re-grant require dual control or a new grant
   identity rather than a state transition?
