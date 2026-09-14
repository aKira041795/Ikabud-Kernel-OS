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

## CD-7 — The conformance lint's phantom rule produces only false positives (fold into the parser slice)

**Issue:** verifying Slice A's own deliverable, `tools/ai-contract-lint.php` reported `phantoms=1 [kernel]`
against **both** of this session's contracts. Reading the rule (`isPhantom`, applied at
`tools/ai-contract-lint.php:229` to the driver's normalised `forbidden_scope`) explains it:

- the driver strips a trailing slash, so the legitimate prohibition `kernel/` arrives as `kernel`;
- a bare-word test (no `/` and no `.`) then labels it a phantom.

So **every single-segment directory prohibition** (`kernel/`, `tests/`) is misreported. Worse, the primary
path can never detect the defect it was written for: a genuine phantom such as `` `git add` `` is *dropped by
the kernel parser*, so it is absent from `forbidden_scope` entirely, and only the raw-text fallback (`:125`)
can see it. The rule is unsound in both directions — it misses the true positives and invents false ones.

**Options:**
- A. Patch the lint's heuristic now (accept the trailing slash, use the driver's `kind`).
- B. Fold the lint correction into the deferred kernel-parser slice (step 4 of the approved plan).
- C. Record as a known limitation and change nothing.

**Chosen:** B.
**Reason:** the lint and the parser share one semantic defect — *what counts as a path in `Forbidden
changes`* — so fixing both in one slice keeps a single source of truth for that question. A would leave the
two halves disagreeing while the parser still widens `.ai/*.contract.md` to the whole `.ai` directory; C
leaves a tool whose phantom findings the owner would reasonably act on. The harness-discipline rule applies
directly: *a check that reports the product is broken must first be proven sound* — this one was not, and it
must not be trusted until it is.
**Also recorded:** the driver-side D8 warnings are unaffected and remain trustworthy, because they compare
forbidden bullets against parsed entries rather than re-testing normalised paths.
**Authority:** Chair, IN-CONTRACT.
**Owner intervention:** not required.

## CD-8 — Decidability is authority: a diagnosed blocker must be answered, not escalated

**Owner directive (2026-09-14, verbatim):** *"if the model, using the harness, can identify the problem and
provides options, therefore it can answer the issues that surfaced, therefore it is allowed to redefine the
context within the scope (to avoid drifting)… my goals and objective for harpp and the harness, regardless
if HARPP or VSCODE is used, is a self reviewing, healing, decisive process. I, as the director, creative,
conceptualizer need not be bogged down with decisions the harness can do."*

**Context — the reported case this responds to.** A slice was authorised to align `cms-akira-builder`'s admin
role gate with the core's canonical tier so a Playwright spec could reach its acceptance line. The executor
found the gate in **three** places, fixed all three, and proved the fix live (the page no longer renders
*"Error: Administrator role required."*). It then found **four independent impossibilities** in
`tests/browser/akira-builder-admin.spec.ts` — all introduced by the one commit that created it (`a6c76f5`) —
fixed three of them, correctly diagnosed the fourth (the spec clicks `button:has-text("+ heading")` while the
active theme exposes only `hero/richtext/card-grid/quote/cta`, and the control renders `+ Add <label>`), and
**stopped**, reasoning that choosing the block flow would be *authoring* a test rather than repairing one.

**Finding: that stop was a system defect, not diligence.** Two of the four defects were *provable* from
`file:line` (the panel is selected from `boot.mode`, so `"Composition editor"` cannot appear on the list
route; the suite's only relative `goto` resolved against a `baseURL` that `.env` points at the kernel host).
The fourth was equally decidable — the theme's real block list is queryable. Every option was enumerable,
which by the owner's rule means the decision was IN-CONTRACT.

**The deeper problem was a documentation gap.** The policy *already* made this an illegitimate stop:
`UNCERTAINTY` with obligations remaining returns exit `3` from `stop-report`, and "ambiguity is not an
escalation condition" is stated explicitly. The rule failed because it never named the excuse actually used —
*"I would be authoring it, not repairing it"* — so the executor did not recognise itself in the prohibition.
A rule that cannot be recognised at the moment of decision does not bind.

**Options considered:**
- A. Record the stop as correct — rewriting a spec is a different role's work.
- B. Add the options test and the verification-repair carve-out to the policy, then apply it to this case.
- C. Leave the policy and simply instruct the executor to continue.

**Chosen:** B.
**Reason:** C fixes one run and leaves the rule unrecognisable for the next one; A contradicts the owner's
doctrine. B makes the boundary checkable — *"can I state the problem and enumerate the options? then decide"* —
names the excuse so the next executor recognises it, and keeps the one guardrail that prevents the carve-out
from becoming permission to tune tests green: a repaired check must assert the **same user-observable
outcome**, and a defect in the *product* must be recorded rather than absorbed into an assertion. The owner's
doctrine and the existing absolute prohibitions are compatible on exactly that line: **repair** a verification,
never **weaken** one; authorization and security semantics stay contract-relative L4.
**Applied immediately:** the spec rewrite is dispatched under this rule with the block-flow choice left to
the executor (`.ai/builder-spec-truth.contract.md`).
**Authority:** owner directive, 2026-09-14.
**Owner intervention:** required and given (this is the directive itself).

## CD-9 — A slice died mid-flight: verify the residue, finish the gap, produce the control

**Issue:** the parser slice (`.ai/scope-path-semantics.contract.md`) was dispatched, then the run vanished —
**0-byte log**, no report, no evidence — while leaving edited files behind, including
`kernel/Workbench/Development/DevelopmentTaskContract.php`, the parser that governs every one of the 64
contracts in the corpus. A partial, unreported change to the most cross-cutting file in the harness is the
worst possible residue to find.

**Options:**
- A. Revert the residue and re-dispatch the slice from scratch.
- B. Re-dispatch to finish and report.
- C. Verify the residue deterministically, complete the one missing deliverable, and produce the control
  evidence myself.

**Chosen:** C.
**Reason:** the doctrine's own ordering — deterministic tools first, never spend a model on what software can
answer. The residue was provably coherent (all three files syntax-clean, harness suite **46/46**, corpus
phantoms **13 → 4**, parse failures **unchanged at 31**), so A would have discarded correct work and B would
have paid a full model round to finish one discriminator plus evidence a script can produce. The remaining
question — *did any contract silently gain authorisation?* — is decidable by comparison, not by judgement.

**What was already correct (retained):** the glob rule — `` `.ai/*.contract.md` `` now parses as
`kind: glob` instead of widening to directory `.ai`; and legitimate single-segment directory prohibitions
(`kernel/`, `tests/`) are no longer misreported as phantoms.

**What was missing, and now fixed:**
- **P1 was not implemented.** The parser routed non-path bullets to `kind: rule` only when the token failed
  the charset `^[A-Za-z0-9_./*?\[\]{}\-]+$` — but that class **admits bare letters**, so a prose bullet
  beginning with a single word passed as a path. Measured: `never stage anything without asking first` became
  `{"path":"never","kind":"file"}` with an empty `forbidden_rules`. Only backticked prose (which contains a
  space) reached the rule branch. Fixed by making the discriminator honest: a backticked token is an
  author-marked path; an **unmarked** first word is a path only if it carries a path signal (`/`, `.`, glob)
  or stands alone. Now: `forbidden_scope` holds the four real paths, and
  `forbidden_rules: ["never stage anything without asking first"]`.
- **P4 had no evidence.** The slice ended before producing it; the chair produced it.

**P4 — corpus control (64 contracts, HEAD parser vs working parser):** `both_fail=46`, `unchanged=16`,
`changed=2`, `only_before_ok=0`, `only_after_ok=0`. **No contract gained authorisation, and no contract's
parseability changed.** Both differences are explained:
1. `ai-autonomy-remote-shape-repair` — removed the bogus forbidden path `this|file` (prose whose first word
   was the word *this*); it is now retained as an advisory rule. A prohibition moved from an unenforceable
   nonsense path to an honest advisory bucket.
2. `playwright-rate-limit-cap` — allowed scope **narrowed** from directory `tests/browser` to the glob
   `tests/browser/*.spec.ts`. Tightening, not widening.

**Residual obligation flagged:** because (2) narrows a **live** contract, resuming
`playwright-rate-limit-cap` could now hit an out-of-scope denial for a nested file under `tests/browser`.
Recorded here rather than silently absorbed; that contract should be re-measured before its next run.

**Harness observation:** a slice that dies leaves no report, and an unreported partial change to a
cross-cutting file defeats the evidence model. The 0-byte log also means stdout was never flushed, so
"the run failed" and "the run produced nothing" are indistinguishable after the fact. Verification of the
tree — not the absence of a report — is what established the true state.
**Authority:** Chair, IN-CONTRACT (completing work already diagnosed and diagnosed in hand).
**Owner intervention:** not required.
