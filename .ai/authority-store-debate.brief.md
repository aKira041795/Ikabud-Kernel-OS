# DEBATE BRIEF — Authority store: answer the five open questions

repo: `/var/www/html/ikabudsix` · read the repo yourself; this brief is the question, not the evidence
role: you are ONE panellist. Answer independently. You are not being asked to agree with anyone.

## Context

Ikabud Kernel OS is a governed application substrate. Modules declare capabilities; a capability bus
enforces authorization policy; every action is a capability + actor + audit record. The kernel is the
product. Two applications prove it: `cms-akira` (publication) and `daily-ledger` (money/POS — the
second domain that exists to break publishing-shaped assumptions).

Today the operator-controlled **grant state** lives in `capability_authorization_policies`, which
`CapabilityAuthorizationRegistry` reads through `app()->db()`. That resolution is **context-dependent**:
measured for the same tenant, a web request resolves the tenant database while a CLI invocation
resolves the kernel database. The ADR classifies this as a defect, not a policy. It is being fixed now,
before delegated actors (P3) and extension grants (P5) are built on top of it.

## Measured facts you must respect

1. **Topology.** One codebase; tenant resolved from the request host. One control-plane database holds
   `kernel_tenants` and `kernel_tenant_db_connections` (encrypted credentials). **One database per
   tenant.** Target deployment is Bluehost shared hosting, MySQL 5.7 — the control plane runs on the
   same shared host as the tenants.
2. `capability_authorization_policies` **already exists in both** the kernel database and each tenant
   database (measured: kernel has 1 row for one capability; tenant 54 has 43 rows). Nothing enforces
   which one is authoritative.
3. `CapabilityAuthorizationRegistry::db()` falls back to `app()->db()` — no explicit store selector.
4. **Declarations are currently code, not data.** 8 modules seed policy rows from PHP (`helpers.php`
   functions calling `seedPolicy()`); only 2 manifests declare `capabilities.policy`. Roles, callers
   and `requires_protocol` are written by module code at activation.
5. Grant lifecycle just landed: `grant_state ∈ {granted, suspended, revoked}`; `seedPolicy()` may
   refresh declaration fields **only** while the grant is granted and can never resurrect a
   revocation; `transitionGrantState()` is the only state mover and is audited with actor + mandatory
   reason.
6. Route authority (P2) is enforced at dispatch, before any handler body runs. Provenance (P1) already
   records capability + actor + timestamp + correlation.

## Constraints

- No new dependencies. Reuse kernel primitives.
- MySQL 5.7 (no window functions, no CTEs, no `JSON_TABLE`, no enforced `CHECK`), shared hosting.
- Every answer must be **falsifiable** — state what would prove it wrong.
- Workbench (the instrument) must be able to inspect the same effective decision the runtime enforces.
- Must serve P3 (delegated actors: `human | service | machine`, grants with scope/constraints/expiry,
  delegation chains, segregation of duties) and P5 (extensions declare needed authority; tenant grants
  or denies it).
- P4 refuses blockchain, public ledgers, PKI infrastructure and DID for now.

## The five questions (answer every one)

1. **Is the authority store physically per-tenant, centrally hosted with tenant partitioning, or a
   federated combination with an explicit precedence rule?**
2. **Which grant classes, if any, may be centrally issued or centrally suspended across tenants?**
3. **Is a declaration projection copied beside grant state, or joined at runtime from signed/versioned
   module contracts?**
4. **What consistency and recovery model applies when code declarations change while a tenant grant is
   suspended or revoked?**
5. **Which actor may re-grant after revocation, and does re-grant require dual control or a new grant
   identity rather than a state transition?**

## What a good answer looks like

Tight, decisive, and specific. For **each** question:

```
Q<n> DECISION:   <one sentence, unambiguous>
   WHY:          <the load-bearing reason>
   CONSEQUENCES: <what this forces elsewhere, incl. what it forbids>
   FAILS IF:     <the concretely observable thing that would prove this wrong>
   COST:         <what it makes harder or more expensive>
```

Then a final section:

```
THESIS:      <the single principle that makes all five answers one answer>
DISAGREE:    <where you expect the other panellists to be wrong, and why>
UNRESOLVED:  <what genuinely cannot be decided from the facts above>
```

Be blunt. A polite non-answer ("it depends", "both have merits") is a failed contribution. If a
question is genuinely undecidable without information, say exactly which information is missing and
what you would decide without it.
