### Q1

**Q1 DECISION:** Use a federated authority store: tenant grants live only in that tenant’s database, while the control-plane database holds a deny-only global quarantine register; either denial wins, and no fallback store is permitted.  
   **WHY:** Tenant authority needs local sovereignty and atomicity, while a compromised kernel/module/provider must be stoppable across every tenant without an unreliable fan-out migration.  
   **CONSEQUENCES:** Resolution must take an explicit tenant ID and contract identity, consult the tenant grant plus any applicable central quarantine, fail closed on required-store failure, and expose that exact resolver to Workbench. The kernel database’s existing policy rows must be migrated or retired—not retained as fallback grants.  
   **FAILS IF:** Web, CLI, jobs, and Workbench produce different decisions for the same tenant and inputs; a tenant can override central quarantine; or lifting quarantine resurrects a tenant-revoked grant.  
   **COST:** Every quarantineable dispatch crosses two logical stores, complicating caching, availability, testing, and recovery.

### Q2

**Q2 DECISION:** No tenant grant may be centrally issued; central authority may issue only control-plane-scoped grants and may globally suspend only an exact executable artifact or capability contract—identified by provider, version, and contract hash—for security quarantine.  
   **WHY:** Central issuance of tenant authority defeats tenant sovereignty, but denying known-compromised code is a platform safety responsibility.  
   **CONSEQUENCES:** Extensions, delegated actors, roles, scopes, constraints, expiry, and delegation chains are always tenant-issued. Provisioning may create tenant-owned bootstrap grants individually, but cannot create an enduring central override. Central quarantine is deny-only, audited, and cannot edit tenant grant records.  
   **FAILS IF:** A central actor can authorize access to tenant data after the tenant denies it, or a compromised artifact continues dispatching in any tenant after a committed quarantine.  
   **COST:** Fleet-wide positive policy changes require tenant-by-tenant adoption, and the platform needs a precise quarantine classification rather than a generic superadmin switch.

### Q3

**Q3 DECISION:** Copy an immutable, content-hashed declaration projection beside tenant grant state at install or activation; never join executable PHP or package manifests during authorization.  
   **WHY:** Runtime authorization and Workbench need the same stable historical contract, while most declarations currently exist only in activation code and P4 has rejected the PKI needed to make runtime signature verification meaningful.  
   **CONSEQUENCES:** Introduce declaration and declaration-revision identities; grants reference an exact revision/hash. Move the eight PHP seeders toward one canonical manifest/projector path. Hashing provides identity and tamper detection, not publisher authenticity; unsigned third-party installation remains forbidden.  
   **FAILS IF:** Changing files on disk silently changes an existing grant, Workbench cannot reproduce the enforced declaration from stored data, or identical installed artifacts project different hashes.  
   **COST:** Declaration revisions consume storage and require migration, validation, provenance, and garbage-retention rules.

### Q4

**Q4 DECISION:** Declaration changes create a new pending declaration revision and never mutate or inherit a suspended or revoked grant; runtime permits only an exact installed-revision/grant match and otherwise denies.  
   **WHY:** Carrying authority across changed callers, roles, protocols, or providers turns deployment into an unaudited grant operation and can resurrect revoked authority.  
   **CONSEQUENCES:** Registration and grant creation occur transactionally in the tenant database. Same-hash projection is idempotent; changed hash requires explicit grant. Revoked grants remain immutable tombstones, suspended grants may resume only against their unchanged revision, rollback restores the old revision’s prior state, and reconciliation retries projection without changing grants. Multi-tenant rollout is intentionally eventual per tenant; mismatch and store failure fail closed and are visible through the shared runtime/Workbench resolver.  
   **FAILS IF:** Deploying or rerunning activation makes changed code effective under an old grant, removes a revocation tombstone, or produces an allow while installed code, projected hash, and grant revision disagree.  
   **COST:** Upgrades can interrupt capabilities until reviewed, and operators must handle pending revisions and explicit rollback/re-grant workflows.

### Q5

**Q5 DECISION:** Revocation is terminal: only a tenant human authority administrator may issue a replacement grant with a new identity, and universal dual control is not required unless that capability’s declared risk/segregation policy requires it.  
   **WHY:** A new identity prevents old delegation chains from reviving, while mandatory two-person approval would make single-administrator tenants unrecoverable without evidence that every tenant can staff dual control.  
   **CONSEQUENCES:** `revoked → granted` must be forbidden. Replacement records the revoked predecessor, reason, approver, declaration revision, scope, constraints, and expiry; descendants of the revoked grant remain dead and cannot be relinked automatically. Services, machines, grantees, module publishers, and central operators cannot replace tenant grants. High-risk capabilities can require two distinct human approvers through P3 segregation policy.  
   **FAILS IF:** A revoked identity changes back to granted, a replacement revives descendants, a non-human actor self-regrants, or a capability marked dual-control becomes effective after one approval.  
   **COST:** More grant records, explicit lineage handling, and possible lockout when no eligible tenant administrator remains.

## Final position

**THESIS:** Authority is additive only by the tenant and subtractive by either the tenant or platform safety authority; every allow is pinned to an immutable declaration revision and grant identity.

**DISAGREE:** Pure central storage confuses hosting with ownership and creates a tenant-authority superuser. Pure per-tenant storage cannot provide prompt, coherent quarantine of compromised shared code. Runtime joins to code erase historical consent, while mutable rows confuse declarations, grants, and deployment.

**UNRESOLVED:** The repository does not provide a capability risk catalogue, tenant administrator staffing guarantees, availability targets, or contractual authority for non-technical fleet-wide suspension. Therefore exact quarantineable capability IDs, cache staleness bounds, and which grants require dual control cannot yet be enumerated. Until supplied: quarantine only technical artifact compromise, use fail-closed reads with no cross-request stale allow, and require dual control only where an explicit risk/segregation declaration says so.
