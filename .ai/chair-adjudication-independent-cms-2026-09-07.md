# CMS Akira Independent-CMS — Chair Adjudication (2026-09-07)

## Debate provenance
- Sol draft: `.ai/cms-akira-independent-cms-roadmap-2026-09-07.md` (independent Kernel-only CMS; dedicated
  `cms-akira-shell` via kernel auth; all 14 dormant members re-scoped native; profiles before Builder).
- Intent: `.ai/fork-independent-cms-debate-intent.md`
- Run: `DEBATE_MAX_ROUNDS=3 tools/pi-arch-debate.py "$(cat …)"` — **DeepSeek Pro** (drafter) ↔ **Codex Sol**
  (critic), genuine different-model debate. Verdict after 3 rounds: **REVISIONS**.
- Converged draft (baseline): `.ai/current-task.md` (25,482 chars — task/objective/scope/constraints/acceptance/
  verification/risk, gated phases). Round-3 critique preserved in `.ai/debate/round-3-critique.txt`.

## CHAIR ADJUDICATION — APPROVED with the following 8 amendments (all folded)

R1 — **Member count (correctness):** 14 existing members + the new `cms-akira-shell` = **15 product members**
(core, 9 native capability modules, 4 profiles, shell). Replace every "all 14 members" phrasing.

R2 — **Module-install persistence (design must converge):** define a Kernel-owned control-plane schema — unique
tenant/install-generation records + per-member steps — with owning table, DB boundary, migration, uniqueness key,
locking rule, retention. Pick ONE compensation strategy (no "reversible/staged/generation-aware" alternatives).
Specify exactly when tenant-DB activation and control-DB `entry_module_id` become routable.

R3 — **protocol-v2 enforcement is a REAL Phase-0 Kernel prerequisite (new gap):** `CapabilityAuthorizationRegistry::
requiresProtocol()` exists but NO caller enforces it — `authorize()` does not. Phase 0 must IMPLEMENT + test
fail-closed protocol enforcement in the canonical capability dispatcher BEFORE any Akira mutation gate. (Same class
of kernel-completion gap the fork has surfaced repeatedly.)

R4 — **MySQL index language:** 191 is the conventional CHARACTER prefix under the 767-byte InnoDB key limit (not
"191 bytes"). Require calculated byte budgets for MySQL 5.7/InnoDB incl. composite indexes.

R5 — **Migration ledgers:** each member owns its migration FILES/version namespace; the Kernel migration coordinator
owns ledger PERSISTENCE (no member-owned ledger tables).

R6 — **Shared-schema install path concretely:** pin how the installer obtains the correct tenant PDO in shared mode,
acquires a per-tenant install lock, and prevents concurrent enable/entry changes; same dependency closure / migration
/ policy / activation sequence in both modes.

R7 — **Profile vs entry:** the tenant selects an install PROFILE; ONLY `cms-akira-shell` may be assigned as
`kernel_tenants.entry_module_id`; headless install does NOT alter the entry module; profiles are never passed to
entry-routing APIs.

R8 — **Audit feasibility per DB mode:** per mutation, require tenant-local audit on the SAME PDO/transaction OR
classify the gate BLOCKED on Kernel audit parity; a control-plane capability call after domain commit does NOT
satisfy the gate.

## Status
`current-task.md` + this adjudication = **APPROVED (chair, after genuine 3-round two-model debate + 8 verified
amendments)**. Phase 0 (kernel protocol-v2 enforcement prerequisite per R3 + install-persistence control-plane
schema per R2) is the first implementable unit, then Phase 1 (shell + independent Post CMS path). Each phase = own
gate (zero-exception audits, certify, tests, logs, pristine diff pinned to 8053dc1). NO-BROADEN into unrelated
kernel/modules; the legacy `cms` is never a dependency.
