# CONTRACT — Explicit authority scope replacing ambient `app()->db()` (P2 closure, prerequisite slice)

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, via Pi)
repo: `/var/www/html/ikabudsix` — branch: `feat/authority-scope` (already created; work in the tree)
chair: this session · authority: product owner ratified the authority-store decisions 2026-09-11

Read first: `docs/architecture/authority-store-adr.md` (**ratified**) and
`docs/architecture/akira-beyond-the-cms.md` §P2 + §*Standing authority architecture findings*.

The ADR names this slice as **"the one precondition that makes all five hold"**: until an explicit,
transportable authority scope replaces ambient resolution, **none** of the ratified decisions
(D-Q1…D-Q5) can be enforced. `docs/architecture/akira-beyond-the-cms.md` states the remaining work
"is gated on one prerequisite: an explicit authority scope replacing ambient `app()->db()`
resolution". This slice builds that prerequisite. Do not re-derive the rationale.

## Verified facts — do not spend tokens re-deriving these

1. `kernel/Capabilities/CapabilityAuthorizationRegistry.php:681` — `db()` is the ambient fallback:

   ```php
   private function db(): PDO
   {
       if ($this->db instanceof PDO) { return $this->db; }
       if (function_exists('app')) { $db = app()->db(); if ($db instanceof PDO) { return $db; } }
       throw new CapabilityAuthorizationRegistryUnavailableException('...');
   }
   ```

2. `kernel/Capabilities/CapabilityBus.php:330` and `:567` construct
   `new CapabilityAuthorizationRegistry()` with **no** PDO — so every authorization read on the bus
   path resolves ambiently. This is the hot path.

3. `modules/cms-akira/cms-akira-core/helpers/governance.php:104` constructs
   `new CapabilityAuthorizationRegistry(app()->db())` — an explicit ambient call in the read path.

4. `kernel/Services/DatabaseManager.php:412` — the ambient target is request-scoped:
   `$tenantTarget = ($this->resolveRequestTenant)();` With no request tenant (CLI, cron, queue,
   Workbench, service call) it falls through to a configured default — the **kernel** database.

5. Consequence, already measured and since corrected: a CLI run that loaded module helpers wrote
   **46 declaration rows into the kernel authority table** instead of the tenant's (removed
   2026-09-11; kernel table is now **0 rows**, tenant 54 holds **43**). The write path was fixed in
   PR #115. **The read path is still ambient** — that is this slice.

## Why this matters now

The registry answering "does this caller hold this authority?" from the wrong store is worse than an
error: outside web requests it silently reads the **kernel** authority table, so a cron/queue/CLI
capability call is judged against authority that tenant does not own. `akira-beyond-the-cms.md`
explicitly lists scheduled jobs, event handlers and CLI handlers as *not yet covered*.

## Deliverables

### A1 — `AuthorityScope` value object and one explicit resolver

- An immutable value object carrying `{tenantId, actor, declarationRevision}` (plus whatever
  transport/entry-point discriminator you need for diagnosis and logging).
- **One** resolver that produces a scope for each entry point: web request, CLI command, cron/job
  queue, service call, Workbench, tests. These must not each invent their own path.
- Resolution is **explicit**. Deriving the target by asking `app()->db()` what database it happens to
  be on is exactly the defect — do not reintroduce it in a new name.
- When no tenant scope can be established, the result is **no scope** — not a default store. Callers
  fail closed and record why. Say precisely which contexts legitimately have no tenant (e.g. global
  kernel maintenance) and what they do instead.

### A2 — The registry reads through a scope

- `CapabilityAuthorizationRegistry` derives its database from an `AuthorityScope`; the ambient
  `app()->db()` fallback in `db()` is **removed**, not wrapped.
- An explicitly injected `PDO` stays supported — the test suite and contract tests depend on it.
- Convert the ambient constructions at `CapabilityBus.php:330,567` and `governance.php:104`.
- **Web parity is mandatory**: a tenant-scoped web request must resolve exactly the same database as
  today. If any web-visible behaviour changes, stop and report it rather than adjusting expectations.

### A3 — Prove parity and fail-closed, and publish the remainder

- A test proving that for tenant 54 the scope resolved from a simulated **web** request, from **CLI**,
  and from a **cron/queue** context all resolve the **same tenant database** (`akira`) and never the
  kernel database.
- A test proving that with **no** tenant scope an authorization read **denies** and records why.
  State the falsifier: the test must fail if the ambient fallback is restored.
- Report honestly which read paths remain outside the scope after this slice. Do not claim coverage
  you did not implement — the census in this repository is explicit about declared vs reachable, and
  this slice must be equally explicit about what it did not reach.

## Constraints

- **Blast radius is the core authorization read path.** Every capability call passes through it.
  Behaviour parity for web is non-negotiable; a regression here is a security regression.
- Do **not** implement D-Q3's declaration-manifest schema, do not materialize declaration revisions,
  and do not add dual-control (`sensitive`) handling. Those are later slices. Carry
  `declarationRevision` because the scope shape requires it, and **state plainly in your result that
  it does not yet enforce anything**.
- Do **not** move the authority store. The `withKernelTableAccess()` escalation question stays
  deferred; it must be re-tested when the table moves.
- MySQL 5.7: no window functions, no CTEs, no `JSON_TABLE`, no enforced `CHECK`. Any DDL idempotent
  and `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- Do not touch `modules/daily-ledger/**` (untracked, live money domain).
- No new dependencies. Reuse kernel primitives.
- Back up `storage/modules.json` before `composer test` and **restore it with its original ownership
  and permissions** (`kajagogoo:www-data`, mode 666). A plain `cp` restore recreates it 0600 and takes
  the live site down with a 503.
- **Do not commit, push or branch.** Leave everything in the working tree for chair review.

## Acceptance

1. **A1**: the scope for tenant 54 resolves to the tenant database from web, CLI and cron contexts —
   prove it by test, and show it is not the kernel database.
2. **A1**: with no tenant scope, an authorization read denies and records a reason. The falsifier must
   be stated and must fail if the guard is removed.
3. **A2**: `CapabilityAuthorizationRegistry` no longer calls `app()->db()`; no ambient construction
   remains in the read path. Paste grep evidence.
4. **A2**: web parity — live `/`, `/login`, `/posts` all 200, and a capability-governed action still
   authorizes as before. `error.log` empty.
5. Full suite `0 failed`; `php ikabud workbench:governance --all --gate` PASS.
6. Raw command output pasted as evidence for 1–4.
7. Honest statement of what remains outside the scope.

## Deliverable

Result block: status, changed, implementation summary, verification, evidence, scope, risks,
unresolved, recommended next state. If you cannot finish a deliverable, say which and why rather than
weakening a test to make it pass.
