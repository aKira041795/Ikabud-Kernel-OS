# Harness Lessons — false alarms, false escalations, and the fixes that followed

**What this file is for.** Every lesson below cost real work: a stall, a wasted escalation, a false "verified", or a
product that looked broken when the harness was. Each one is written as *symptom → root cause → evidence → fix →
the generalisable rule*, so it can be retrieved by symptom as well as by rule. Source: the Star Swarm / HARPP v2
sessions of 2026-09-16, where all of these were measured rather than theorised.

**The unifying failure mode.** *An unreliable instrument reports a reliable-looking verdict.* In every case the
product was fine and the check was not — and the check's output was indistinguishable from a product defect.

---

## L1 — A login inside the acceptance harness trips the rate limiter and reads as a product failure

- **Symptom.** `TimeoutError: page.waitForURL` inside `tests/browser/auth.setup.ts:48`, reported by Playwright as
  `1 failed`. Downstream, the driver recorded `verified: 0, no_progress: 4, blocked: 2`.
- **Root cause.** `globalSetup` logged in on **every** `npx playwright test` invocation. The kernel login limiter
  permits **5 attempts per 300 s**, and the driver re-runs the acceptance for every chunk by design. Its own
  verification tripped the limiter, the setup timed out, and the spec was counted as a failing product.
- **Evidence.** The limit is written in `playwright.config.js`'s own comment. Reproduced directly: a five-run burst
  produced one failure with **no probe involved**; a later eight-run burst passed 8/8 once sessions were reused.
- **Fix.** `auth.setup.ts` now **reuses a fresh `storageState`** (10-minute window, zero login POSTs) and, if a login
  is ever refused while a usable session exists, **reuses that session and warns loudly** instead of failing the
  suite. Measured after: five consecutive invocations, zero login POSTs, all passing.
- **Rule.** *An authentication step inside an acceptance harness must be idempotent per session, never per
  invocation.* A harness outage must be structurally incapable of looking like a failing product.

## L2 — A harness failure was escalated as an "irreversibility" boundary

- **Symptom.** `condition: "irreversibility"`, `reason: "three materially different attempts produced no verified
  product progress"`.
- **Root cause.** The stop-condition classifier maps *no progress* onto a boundary condition. It is not one:
  authority, boundary and irreversibility describe **breaches**, while "no progress after three approaches" describes
  a **defective objective or a flaky instrument** — the chair's work, not a boundary.
- **Evidence.** The same tree passed all four acceptance gates minutes later (concept 23/0, structural 18/0, artifact
  4/0, spec 8/8). The escalation asserted a ceiling that did not exist.
- **Fix.** Flakiness is classified separately from no-progress (L4), and the no-progress path no longer has to *claim*
a boundary to be heard: `escalate()` only accepts the three breach conditions, so a non-breach finding now goes
through `needsChair()`, which writes `**Boundary:** NONE` in the first line of its hand-off and records
`boundary: false` in state and journal.
- **Rule.** *A stop condition names a breach, not a mood.* "No progress" is a finding about the objective or the
  instrument — escalate it as a defect to be corrected, never as a boundary.

## L3 — A requirement written as prose is not enforced (twice in one day)

- **Symptom (iteration 2).** The driver reported `verified` while the director's requirement — dark space — was
  unmet: the play field was light, because the game inherited light theme tokens. Pixel probes: **0**; screenshot:
  **none**. The decisive evidence was in a prose section; only a structural test was in `## Acceptance`.
- **Symptom (iteration 3).** The driver honestly reported *"all objective acceptance gates passed"* while **half the
  brief was unbuilt**: no `scale`, no `class`, no `role`, no `nursery`, and no probes for overlap, centroid,
  dispersal, scale bands, nursery origin, silhouette separability or role coverage. The pillar table listing the
  probes was prose.
- **Fix (structural, not a note).** The requirement list became **data** —
  `tools/harpp2/projects/star-swarm-concept.json` — and a gate reads it: `tests/star_swarm_concept_test.php`, which
  failed 12/11 on the day it was written and 23/0 once the work was done. Aliases (`moodSamples|moods|mood`) keep it
  about behaviour rather than spelling.
- **Rule.** *If a requirement needs evidence to decide it, that evidence is a command in `## Acceptance` — and the
  requirement list itself must be machine-readable.* Prose in a brief is a wish; a probe in a gate is a requirement.

## L4 — A check that fails 1 run in 5 is read as a failing product by anything that retries

- **Symptom.** After the login defect was fixed, the spec still failed **once in five runs**, then passed 8/8 — and
  on a later ten-run loop it failed on **run 2**, this time captured:
  `Error: a fired shot creates bright pixels above the ship` — `Expected: > 8, Received: 0`.
- **Root cause (isolated, not guessed).** The probe sampled a fixed 30×130 window above the ship **once**. The
  projectile genuinely existed — the `state.bullets.length` poll above it passed — but the shot crosses that window
  in a couple of hundred milliseconds, so the single sample **raced it** and read zero. A probe that samples live
  animation once is a race by construction.
- **Fix.** The same region and the same threshold are now **polled** (`expect.poll`, 4 s, intervals
  25/50/100/200 ms). A race removed, not a bar lowered: if the shot is never drawn, the poll times out and fails.
- **Evidence.** Before: 1 failure in 5 runs, `Received: 0`. After: **10/10 consecutive runs passed.**
- **Fixed in the driver (2026-09-16).** The acceptance is now **re-run once** on failure, and
  `tools/harpp2/verify.php::classifyGateOutcome()` returns `passed` / `flaky` / `failed`. A pass-on-retry journals
  `flaky_verification`, sets the reason to *"FLAKY (harness instability, not a product failure)"*, writes a
  judgement line, and continues with the retry's result — so an instrument race can no longer be recorded as
  no-product-progress or escalated as a boundary. The classifier is unit-tested in place (3/3: passes · flaky ·
  fails-twice). The lane brief was also corrected: an executor that was not actually stopped is told to use `null`
  and explicitly *not* to name a breach that does not exist.
- **Rule.** *Before a failed check is allowed to mean "the product is broken", it must be re-run — and a flaky
  verdict must be its own recorded outcome.* Retrying to hide instability is wrong; retrying to *classify* it is
  essential.

## L5 — Evidence gaps hide in naming: the instrument was a grep of my expectations

- **Symptom.** I concluded "no mood probes and no silhouette classes"; the artifact disagreed.
- **Root cause.** My greps searched for **my** vocabulary (`separation`, `role`, `class`). The implementation used
  its own (`sway`, `lean`, `breathe`, `commander`/`fighter`/`scout`), and the spec probed moods under `moods`.
- **Evidence.** The test file's own section headers listed the moods and the three named silhouettes; two acceptance
  failures I initially reported were **naming, not missing behaviour** — corrected by adding `|` aliases to the
  contract.
- **Rule.** *A grep is an instrument; verify it.* Read the artifact's own assertions before concluding an artifact
  lacks a capability.

## L6 — A dispatched run without a watcher creates a silent stall

- **Symptom.** Iteration 3b escalated at **14:23:51**; the tree sat **idle for 4h15m**; the next thing anyone did was
  ask "what's happening now?".
- **Root cause.** The run was dispatched and never watched. The escalation **was delivered** — to the terminal inbox
  at 14:15:29 and 14:23:49 and to HARPP (message 1100) — but nothing woke the chair. A message that is read by
  nobody is a stall with good bookkeeping.
- **Fix.** `tools/harpp2/status.sh` answers *running · awaiting chair · last verdict · gates* in one command, and an
  escalation writes a `NEEDS_CHAIR` marker that status surfaces first.
- **Rule.** *Every dispatched run needs a watcher that reaches the chair; and "what is happening now?" must be one
  command with a non-empty answer.*

## L7 — Falsification by editing the artifact requires restore discipline

- **Symptom.** Proving a probe is non-vacuous means breaking the feature on purpose — which risks leaving the tree
  broken.
- **Evidence.** The negative control (disable `separateColony()`'s relaxation loop) was backed up first and restored
  byte-identically: sha256 `422f8442574c0d7e…` before and after, zero lingering patches.
- **Rule.** *Back up, patch, test, restore, and verify the restore by hash.* A restore mismatch is a stop-the-world
  event, not a footnote.

---

## The three questions this file exists to answer

1. **Is the check sound?** (L1, L4, L5) — verify the instrument before believing the finding.
2. **Is the requirement enforced?** (L3) — if it is not a command, it is not a requirement.
3. **Did anyone hear it?** (L2, L6) — a verdict that reaches nobody, or that describes the wrong kind of failure,
   costs the same as no verdict at all.
