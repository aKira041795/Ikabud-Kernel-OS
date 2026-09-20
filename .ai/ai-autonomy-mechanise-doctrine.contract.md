# CONTRACT — Mechanise the doctrine: one enforcement source, a real safety floor, and a checkable stop invariant

status: DONE — implemented and verified by the Chair 2026-09-14 (suite 46/46; safety floor probed; PHPStan clean)
repo: `/var/www/html/ikabudsix` — work in the current tree; do not create or switch branches

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```
authority: owner directive 2026-09-14 — *"harness must be capable now to think and decide for itself"*,
plus the approved doctrine: the Chair owns completion, ambiguity is not an escalation condition, and
stopping with obligations outstanding is a system defect.

## Objective

The doctrine is now written but not enforced. Three parts of it must become mechanical, because the
whole design principle is *prefer deterministic software where a rule can be enforced mechanically*:

1. **One source of truth for L4 triggers.** Today the taxonomy is printed by `plan` while `check`
   enforces a separate hardcoded list, and the function that would merge them is dead code.
2. **A real safety floor.** A contract that authorises an *absolute* prohibition currently gets it
   waved through as `RECORD`, which contradicts the policy's strongest claim.
3. **A checkable stop invariant.** The doctrine's best rule — *if an approved contract has unsatisfied
   obligations and no contract blocker, the harness is not idle* — exists only as prose.

## Verified facts — measured, do not re-derive

- `l4Triggers()` is defined at `tools/ai-autonomy.php:74` and **never called** (one occurrence in the
  file, its own definition). The enforced list is a separate function, `sensitiveReasons()` at `:443`,
  with five path classes: `schema or DDL change`, `dependency change`, `quality-gate baseline change`,
  `module manifest/contract change`, `authority or security path`.
- **Demonstrated gap.** With a contract listing `phpstan.neon` and `tests/` in `allowed_scope`:
  `check "lower the phpstan level or widen excludes" --path=phpstan.neon` → **exit 0, `VERDICT: RECORD`**;
  `check "skip or weaken an existing test" --path=tests/entity_fallback_test.php` → **exit 0, `RECORD`**;
  control `--path=phpstan-baseline.neon` → **exit 3, `ESCALATE`** (it is the one gate artefact with a
  class). The policy calls test/gate weakening an *absolute* prohibition — "no contract can authorise
  these, and no escalation can obtain permission".
- `plan --json` emits ten keys including `absolute_prohibitions` (5), `contract_relative_l4` (10),
  `chair_decisions` (6), `model_tiers` (T0–T4) and `model_policy` (with `deterministic_first: true` and
  `project_budget_usd`). Those 204 driver lines have **zero test coverage**: `tests/ai_autonomy_test.php`
  is unchanged at 27 cases and contains no reference to any of the new keys, and CI reaches the driver
  only through that suite.
- No driver code reads a contract's `status:` field or computes remaining obligations (`grep -E
  "obligation|APPROVED" tools/ai-autonomy.php` → nothing).
- The phrase **"presumptive authority" does not appear** in the policy file.

## Deliverables

### D1 — One source of truth

`sensitiveReasons()` must derive from the taxonomy instead of duplicating it. `l4Triggers()` (or its
successor) must be **called** by the enforcement path. If a taxonomy entry cannot be decided from a
path, it must still be represented — marked as judgement-based — rather than silently absent, so the
gap between printed and enforced is visible in the code rather than discovered by probe.

### D2 — Enforce the safety floor

Add path classes so that weakening a test or a gate escalates **even when the path is inside
`allowed_scope`**, with a reason naming the absolute prohibition:
- test files (`tests/**/*_test.php`, `modules/*/tests/**/*.php`),
- gate configuration (`phpstan.neon`, `phpstan-baseline.neon`, `.github/workflows/**`),
- any other artefact whose purpose is verification.

An absolute prohibition must be unauthorisable: no `--justify` grounding, no allowed-scope membership,
may let it through. If a contract legitimately needs to *add* a test file, that must still be allowed —
distinguish *adding* from *weakening* only where the distinction is decidable from the path, and say so
in the reason when it is not.

### D3 — `stop-report`: make the stop invariant checkable

New subcommand:

```
php tools/ai-autonomy.php stop-report --remaining=N --stop-reason=TYPE [--json]
```

Legitimate stops (`exit 0`): `remaining=0` with any reason, or `remaining>0` with a contract-level
reason: `CONTRACT_BLOCKED`, `RESOURCE_EXHAUSTED`, `EXTERNAL_DEPENDENCY_BLOCKED`, `SAFETY_BLOCKED`.
Illegitimate (`exit 3`, and it must say so): `remaining>0` with `PROJECT_COMPLETE` or with an
ambiguity/uncertainty-style reason — the doctrine's "illegitimate stop" case. Print the invariant it
applied, so the reasoning is visible, and name the unsatisfied obligation count. Document the exit codes
in the usage block and in the standing contract's runbook.

### D4 — Policy: name the missing principle

Add the doctrine's core sentence to `.github/instructions/ai-autonomy-escalation.instructions.md`:
once a contract is approved the Chair has **presumptive authority** over subordinate decisions, and
state that `stop-report` is the mechanical expression of the stop invariant. Additive only.

### D7 — Make the reference block visible to the harness (additive warning)

`plan` must **warn** — not fail — when a contract contains no `harness:` block referencing the standing
contract, and must surface it in `--json` (e.g. a boolean or a `warnings` list). Rationale: it is how a
slice inherits the autonomy envelope and the L0–L4 vocabulary. `p1.3`, `p2.2a` and `p3.1b` currently have
zero such references, so they adopt the syntax but inherit no envelope. Do not make it an error: legacy
contracts must remain runnable while the corpus is retrofitted. This must not change any exit code.

### D8 — Stop an envelope defect from being silent (measured on this very contract)

While authoring this contract, two envelope defects were measured — they are the exact failure the
doctrine calls a system defect, so make them visible:

1. **A forbidden rule silently vanished.** `## Forbidden changes` had 8 bullets; `plan --json` returned
   **7** `forbidden_scope` entries. The bullet beginning `` `git add` `` was dropped entirely: a binding
   prohibition that does not bind. `plan` must **warn** (and surface it in `--json`) when it can account
   for fewer forbidden bullets than the section contains, naming the dropped lines. Do **not** change the
   kernel parser — detect it driver-side and report it.
2. **Allowed and forbidden scope overlapped.** `` `.ai/*.contract.md` `` was parsed as **directory `.ai`**,
   which intersected this contract's own allowed path `.ai/ai-autonomy-harness.contract.md`. Fail-closed
   precedence would escalate work the contract explicitly authorises. `plan` must **warn** on any
   allowed/forbidden intersection, naming both entries. `check` must continue to treat the forbidden path
   as binding (fail-closed stays as it is); the fix is visibility at plan time, not a relaxation.

Both warnings must be testable and must not alter exit codes: a present-but-defective envelope is still a
runnable envelope, and silently unenforceable prohibitions are the thing being eliminated.

### D5 — Tests (the gap that let 204 lines ship unverified)

Add cases that fail against today's code:
1. the three probes above flip from `RECORD`/0 to `ESCALATE`/3 — test file, `phpstan.neon`, and
   `.github/workflows/`;
2. adding a *new* test file inside an allowed `tests/` path still proceeds (the floor must not block
   legitimate work);
3. the taxonomy is single-sourced: assert the enforced set is derived, e.g. by checking that every
   path-decidable taxonomy entry produces an escalation for a representative path;
4. `plan --json` emits all ten keys with the expected types and non-empty counts (guards the projection);
5. `stop-report` matrix: `remaining=0` → 0; `remaining=3 --stop-reason=CONTRACT_BLOCKED` → 0;
   `remaining=3 --stop-reason=PROJECT_COMPLETE` → 3; `remaining=3 --stop-reason=UNCERTAINTY` → 3;
   malformed `--remaining` → 2.

### D6 — Do not weaken anything to get green

No existing case may be deleted, skipped or relaxed. Adding a path class that escalates too eagerly is a
regression: verify the existing scope probes (in-scope `0`, forbidden `3`, DDL-without-justification
`3`, grounded-justification `0`) still behave exactly as they do now.

## Architectural constraints

- **No other contract in `.ai/` may be edited** apart from the standing contract named in scope
  (a rule, not a path: the parser reads a glob such as `.ai/*.contract.md` as the whole `.ai` directory,
  which collides with allowed scope and would be escalated fail-closed).
- **No `git add`, commit, push, branch creation or branch switching** (a rule, not a path: the parser
  drops a forbidden bullet whose first token is not a path, so it cannot be represented as scope).
- Prefer one list over two; if duplication is unavoidable, make the duplication detectable in a test.
- No new dependency, no network, no `~/.config/harpp` access, no DB, no bootstrap.
- Fail closed: an unknown or ambiguous case must escalate, never silently proceed.
- The `--justify` grounding mechanism stays as-is for contract-relative triggers; it must NOT be able to
  ground an absolute prohibition.
- Keep PHP 8.2 compatibility and PHPStan clean under `-c phpstan.neon` for the changed files.

## Files likely affected

- `tools/ai-autonomy.php` — single-source enforcement, safety-floor classes, `stop-report`
- `tests/ai_autonomy_test.php` — the new cases
- `.github/instructions/ai-autonomy-escalation.instructions.md` — presumptive authority, additive
- `.ai/ai-autonomy-harness.contract.md` — document `stop-report` in the runbook, additive

## Acceptance criteria

- The three gate/test-weakening probes escalate with exit `3` **even though the contract allows the path**.
- Adding a new test file under an allowed `tests/` path still proceeds with exit `0`.
- No taxonomy entry that is decidable from a path is missing from enforcement, and the code contains one
  list rather than two.
- `stop-report` returns the documented codes for all five matrix cases, and states the rule it applied.
- The policy names presumptive authority and points at `stop-report`.
- `php tests/ai_autonomy_test.php` exits `0` with every new case present and no existing case relaxed.

## Required tests

- `php tests/ai_autonomy_test.php` — exit `0`; report passed / failed / skipped and the case count.
- `php -l tools/ai-autonomy.php`.
- `/usr/bin/php8.3 vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G tools/ai-autonomy.php`
  — report the real output. Judge against the CI-parity path set, not a widened one.
- The three weakening probes plus the new-test-file control, pasted with real exit codes.
- The `stop-report` matrix, five lines with real exit codes.

## Risks

- Escalating too eagerly makes the harness unusable and pushes work outside it; the control case in D5.2
  is the guard.
- A "single source" that is really a second copy would be worse than honest duplication — test the
  property, not the claim.
- `stop-report` is a self-report: an agent can pass `--remaining=0` falsely. Say plainly in the report
  that it makes the invariant *checkable*, not *unfalsifiable*, and that the honest input is the
  agent's obligation.

## Forbidden changes

- `phpstan-baseline.neon` — no edit to the quality-gate baseline.
- `phpstan.neon` — no edit to gate configuration.
- `composer.json` — no new PHP dependency.
- `package.json` — no new Node dependency.
- `.github/workflows/` — no edit to CI workflow definitions.
- `kernel/` — no kernel change; this slice is the driver and its tests.
