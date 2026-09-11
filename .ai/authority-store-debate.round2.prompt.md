# ROUND 2 — adjudicate the authority-store disagreement

You are the **third panellist**. Two independent panellists answered the same five questions. They
agreed on three and split on two. You have not seen their identities — judge the arguments only.
Read `.ai/authority-store-debate.brief.md` for the context, facts and constraints. Read the repo
(`docs/architecture/authority-store-adr.md`, `kernel/Capabilities/CapabilityAuthorizationRegistry.php`,
`kernel/Capabilities/CapabilityBus.php`, `kernel/TenantResolver.php`, `src/helpers/module-manager.php`)
before judging. Your job is not to summarise — it is to decide, and to find what BOTH missed.

## Where they agreed (do not re-litigate unless you think they are jointly wrong)

- **Q1** Federated: tenant grants live in the tenant database; the control plane holds a **deny-only**
  containment overlay that can never grant; either denial wins.
- **Q2** Nothing is centrally *issued*; central authority may only *suspend/quarantine* (denial), and
  only for an identifiable artifact/capability contract.
- **Q5** Revocation is **terminal** — re-granting requires a **new grant identity**, not a state flip
  back to granted; dual control only for a declared sensitive class, not universally.

## The disagreement you must settle

### Q3 — how does the declaration reach authorization?

**Position A — copy an immutable projection.** Store a content-hashed, immutable *declaration
projection* beside tenant grant state at install/activation. Never join executable PHP or manifests
during authorization. Grants reference an exact declaration revision/hash. Runtime and Workbench both
read stored data, so they cannot disagree. Cost: storage, migration, validation, retention rules.

**Position B — join at runtime, pin only a fingerprint.** Join declarations from versioned module
contracts at dispatch; the grant row stores an immutable *declaration fingerprint* pinned at grant
time, never a mutable copy of roles/callers/protocol. Rationale: the current copy is unsafe in both
directions — `seedPolicy()` refreshes `caller_module`/`allowed_roles`/`requires_protocol` on
**granted** rows (`CapabilityAuthorizationRegistry.php:160-164`), so a module upgrade silently
*widens* a live grant with no operator decision; while a suspended/revoked row's declaration freezes
forever, so a security fix (`requires_protocol v1→v2`) never reaches it. Cost: hashes are opaque in
SQL (needs a readable version integer); requires declarations to actually exist as data first — today
only 2 manifests declare `capabilities.policy` while 8 modules seed from PHP `helpers.php`.

### Q4 — declarations change while a grant is suspended/revoked

**Position A** — a declaration change creates a new *pending* declaration revision; it never mutates
or inherits a suspended/revoked grant; runtime permits only an exact installed-revision ↔ grant match
and otherwise denies.

**Position B** — asymmetry by direction: **narrowing applies immediately with no operator action;
widening fails closed** (the grant becomes `declaration_drift` and needs an audited re-grant); revoked
stays terminal regardless. Effective decision = the stricter of {pinned fingerprint, live declaration}.

## What to produce

1. **Adjudicate Q3** — A, B, or a synthesis. Be concrete about what is stored on the grant row.
2. **Adjudicate Q4** — A, B, or a synthesis.
3. **What both missed.** At least one thing neither position accounts for. Look hard at: the CLI /
   cron / queue context where `TenantResolver` falls through to a default and `app()->user()` is null
   (so `transitionGrantState()`'s `id > 0` assertion cannot pass); the 8 PHP seeders that must become
   declarations before "join from contract" is even implementable; the check that
   `replaceActiveRowRoles()` is "caller owns the transaction" and what happens if a future caller
   forgets; shared-hosting/MySQL-5.7 limits.
4. **Jointly wrong?** Say so plainly if the shared Q1/Q2/Q5 position is wrong. In particular: is a
   deny-only control-plane overlay actually implementable given that `kernel_tenant_db_connections`
   credentials are used to reach tenant databases, and does the two-store read create a new failure
   mode on shared hosting?
5. **The decisive question.** If one thing must be true for your answers to hold, what is it, and is
   it currently true in this repo?

Format:

```
Q3 VERDICT:  <A | B | synthesis — then the concrete design in <=6 lines>
Q4 VERDICT:  <A | B | synthesis — then the concrete design in <=6 lines>
MISSED:      <2-4 findings, each with file:line evidence where possible>
JOINTLY WRONG (if any): <what, and the correction>
DECISIVE PRECONDITION: <the one thing that must hold>
```

Be decisive and specific. "Both have merit" is a failed answer. Cite file paths for factual claims —
if you cannot verify a claim in the repo, label it as unverified.
