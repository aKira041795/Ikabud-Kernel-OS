# Chair decisions — recorded provenance

Per `.github/instructions/ai-autonomy-escalation.instructions.md`: the Chair records far more decisions than
it escalates. These are IN-CONTRACT decisions, recorded so the owner can inspect provenance after the fact.
No owner intervention was required or requested.

---

## CD-1 — Lane assignment by cost shape, not headline price

**Issue:** which executor for each of the two approved slices.

**Options:**
- A. Both slices on `openai-codex/gpt-5.6-sol` — higher judgement on both, but spends a fixed-cost lane on
  mechanical work and serialises two independent slices.
- B. Slice A on `deepseek/deepseek-v4-flash` (low), Slice B on Sol (medium) — matches each lane's cost shape.
- C. Both on flash — cheapest, but the enforcement slice rewrites the rules that govern every future run.

**Chosen:** B.
**Reason:** `php tools/ai-autonomy.php models` reports `deepseek-v4-flash` as **variable** cost (every token
charged → minimise context, keep the contract tight) and Sol as **fixed** (spend already committed → the
marginal token is free, so spend attention where judgement matters). Slice A is bounded, mechanical and
specified to the line; Slice B decides what the harness may never authorise and therefore deserves the
fixed-cost lane. Running them concurrently is possible precisely because they touch disjoint files.
**Authority:** approved course of action 2026-09-14.
**Owner intervention:** not required.

## CD-2 — Retrofit contracts just-in-time, not in bulk

**Issue:** 47 of 60 contracts fail to parse, 27 of them self-declaring `READY_FOR_IMPLEMENTATION`. Bulk-retrofit
all 27 into conformant envelopes, or measure conformance and retrofit only what is scheduled?

**Options:**
- A. Bulk-retrofit 27 envelopes now — the corpus becomes uniformly runnable in one pass.
- B. Lint + triage report now; retrofit one worked example to prove the procedure; retrofit each remaining
  contract at the moment it is scheduled.
- C. Leave the corpus alone and only ensure new contracts conform.

**Chosen:** B.
**Reason:** an envelope **is** an authority boundary. Fabricating one from a stale contract authorises scope
its author never approved — strictly worse than an unparseable file that simply refuses to run. Chronology
supports the staleness concern: the split is by authoring date, not by intent, so "READY" reflects when the
file was written rather than whether the work is still wanted. B keeps the harness safe, makes the gap
measurable in one command, and pays the authoring cost only where it is about to be used.
**Authority:** approved course of action 2026-09-14.
**Owner intervention:** not required.

## CD-3 — One writer per file, so the driver is owned by one lane

**Issue:** two plausible homes for "`plan` should warn when a contract lacks the `harness:` reference block" —
the conformance slice (where the finding came from) or the enforcement slice.

**Chosen:** the enforcement slice, as D7.
**Reason:** concurrent lanes must not edit the same file, and `tools/ai-autonomy.php` is Slice B's primary
deliverable. Both warnings concern `plan` itself, so grouping them keeps one source of truth for the driver.
**Authority:** Chair, IN-CONTRACT.
**Owner intervention:** not required.

## CD-4 — Two envelope defects: authoring-side workaround now, parser fix deferred

**Issue:** while validating this session's own two contracts against the real driver, two defects were measured:

1. `## Forbidden changes` held 8 bullets; `plan --json` returned **7** `forbidden_scope` entries. The bullet
   beginning `` `git add` `` was dropped silently — a binding prohibition that does not bind.
2. `` `.ai/*.contract.md` `` was parsed as **directory `.ai`**, intersecting this contract's own allowed path
   `.ai/ai-autonomy-harness.contract.md`; fail-closed precedence would escalate work the contract authorises.

**Options:**
- A. Fix `kernel/Workbench/Development/DevelopmentTaskContract.php` now.
- B. Work around authoring-side, and add driver-side detection so the defect can never be silent again.
- C. Record as known risk, change nothing.

**Chosen:** B.
**Reason:** the kernel parser is not in either slice's scope, and the standing policy already classifies the
parser's prose-as-path behaviour as a known defect requiring its own contract. B removes the immediate harm
(both defects were fixed in the envelopes before dispatch: the two rules moved to `## Architectural
constraints`, where prose belongs) **and** closes the detection gap, which is the part that actually caused
the harm — silence. C was rejected because an unenforceable prohibition that nobody notices is the exact
category the doctrine calls a system defect.
**Follow-up owed:** the kernel parser root fix (glob → directory, and non-path bullets vanishing) still needs
its own contract; D8 makes the symptom reportable in the meantime.
**Authority:** Chair, IN-CONTRACT.
**Owner intervention:** not required.

## CD-5 — Executor exhaustion is reallocation, not a stop

**Issue:** the enforcement slice (Slice B) was dispatched to `openai-codex/gpt-5.6-sol` and died on its first
call: `Codex error: The usage limit has been reached` (exit 1, 46-byte log). The Slice A lane
(`deepseek/deepseek-v4-flash`) completed normally in the same window.

**Options:**
- A. Wait for the Sol quota reset (a 4-hour cycle) before running Slice B.
- B. Reallocate Slice B to a lane with capacity and continue now.
- C. Reallocate Slice B to a different quota meter (the `groq` metered-burst lanes).

**Chosen:** B, to `deepseek/deepseek-v4-flash` at `medium` reasoning.
**Reason:** the doctrine is explicit — *"When one executor hits a rate cap, quota, or budget: the Chair
reallocates… Record the reallocation; do not idle and do not escalate a resource problem to the owner."*
One executor's exhaustion is not project exhaustion, and no owner-defined project budget is in play. A was
rejected because it converts a resource problem into idle time for no gain; C was rejected because the flash
lane had just demonstrably handled a comparable slice, so the cheapest adequate lane was already proven.
The cap is recorded here rather than escalated.
**Evidence of the cap:** `.ai/ai-autonomy-mechanise-doctrine.sol-run.log` (46 bytes, single line).
**Authority:** Chair, IN-CONTRACT.
**Owner intervention:** not required.

## CD-6 — A retrofit may not manufacture readiness (withdrawing a self-defeating proof requirement)

**Issue:** the conformance slice retrofitted `p2.1-navigation-surface.contract.md`. It honestly flagged one
judgement call: it added `status: READY_FOR_IMPLEMENTATION` because the contract's own proof requirement asked
for `--live-only` to show the file, and a no-status contract classifies as `unknown`, not `live`.
Probing the module shows that value is **false**: `routes.php` declares three routes
(`GET /api/v1/cms-akira-navigation/health`, `GET /cms-akira-navigation`,
`POST /cms-akira-navigation/menu/create`), `module.json` carries `capabilities.routes` plus an
`admin_contributions` sidebar entry (`cms-akira-navigation.menus` → `/cms-akira-navigation`), `templates/admin.disyl`
exists, and `navigation_surface_test.php` runs **8 passed, 0 failed** (exit 0). The retrofit's objective
asserts the module has "zero routes" — the premise is falsified on disk.

**Options:**
- A. Leave `READY_FOR_IMPLEMENTATION`; it is what the proof requirement asked for.
- B. Set an honest status (`DONE`) that records the measured contradiction, and withdraw the proof requirement.
- C. Revert the retrofit to its original unparseable form.

**Chosen:** B.
**Reason:** this is the manufactured-authorisation risk of CD-2 occurring in practice, one level down: not a
fabricated *scope*, but a fabricated *readiness*. Left as-is, the harness would advertise as dispatchable a
slice whose work is already delivered, and an executor would re-implement delivered code inside a scope the
contract authorises. The root cause is a defect in **my** A3 proof requirement, not in the executor's
reasoning — demanding that a parsed file appear in the `live` set made the status value instrumentally
chosen. Requiring a status to be *honest* and requiring it to be *live* are incompatible when the contract is
already done, so the proof requirement is withdrawn: a retrofit is proven by `plan --json` exiting 0 with a
non-empty allowed scope and zero phantoms, which the executor also demonstrated.
**Verified after the correction:** `p2.1` → `parse=ok harness_ref=yes phantoms=0 class=stale`;
`plan_exit=0`; live set 36 → 35, stale 1 → 2.
**Also noted:** the executor reported the judgement call rather than hiding it, which is the behaviour the
doctrine asks for — escalation was not required because the decision was in-contract, and correcting it was a
one-line Chair action.
**Authority:** Chair, IN-CONTRACT.
**Owner intervention:** not required.
