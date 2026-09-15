# Project — Akira CMS completion: measured against the popular-CMS bar

status: **ACTIVE** · chair: this session · authorised: director, 2026-09-15
authority: `.ai/akira-completion-plan.md` (APPROVED 2026-09-15) + director instruction
*"another evaluation of Akira CMS … find the missing features, gaps and benchmark it against popular CMS in terms of
UI/UX, with WordPress at the top … define the project plan and phases … manage the implementation until done …
You can refactor if needed."*

## The two rules this project runs on

**R1 — Evidence is behavioural, never counted.** A surface is judged by driving it in a browser and observing the
journey. The prior audit failed by counting `.disyl` files and missing an entire PHP-built admin. **When a claim about
the product is made, it carries a route, a screenshot and an observed behaviour — or it is marked unverified.**

**R2 — The browser is a gate, not a nicety.** `tests/browser/akira-admin-shell.spec.ts` already exists and falsified a
real defect (Sign out colliding with nav at 900px and 600px). **Every UI slice in this project extends or consumes a
browser journey, and the journey must FAIL before the fix.** A slice whose only evidence is a test exit code or a byte
count does not pass review — that is the exact failure that let a wedged Sign out survive a week of "verification".

## Honest limitation, stated up front

The **Akira column is measured**. The **WordPress column is reference knowledge**, not measured in this environment.
Where that knowledge is uncertain it will be marked `(ref, unverified)` rather than asserted. A benchmark with one
measured column and one expert column is useful; a benchmark that pretends both are measured is not.

## Definition of done

Two milestones, both falsifiable:

**M1 — "Does not embarrass itself."** No surface is broken, inconsistent, or a dead end. Every list has search, filter,
pagination, empty state and its destructive actions confirm. Every admin page renders in the shared shell. Every
journey a WordPress user would expect to complete **in Akira's own scope** completes, verified by browser journey.

**M2 — "Comparable to a popular CMS on the journeys it claims."** The gap matrix is re-scored and every remaining
red cell is either **closed**, **recorded as a decision you made**, or **explicitly refused with a reason**. Zero
unexplained red.

Explicitly **not** done: feature-count parity with WordPress. `.ai/akira-completion-plan.md` deliberately refuses
*"block-editor competition"* and *"an admin-UX arms race"*, and this project does not overturn that. The target is
**depth over breadth** — every surface that exists should be good, and missing features are ranked by what a paying
user needs, not by count. If you want raw parity, that is a different project and I will say so.

## Phases

| # | Phase | Deliverable | Gate |
|---|---|---|---|
| **P0** | **Measure** | `docs/reviews/akira-cms-gap-analysis.md` — a scored matrix: feature × {works / partial / absent} × evidence (route + screenshot) × WordPress reference | Every Akira cell carries evidence or is marked unverified. No cell is inferred from file counts. |
| **P1** | **Quality floor** | Fix every broken/inconsistent surface P0 found | Each fix has a browser journey that failed before it |
| **P2** | **IA and navigation** | Grouped sidebar (mechanism already exists: `group` is normalised and partitioned by `kernelContributionBridgeCmsNavItems()`, and **zero** of 9 sidebar contributions declare it) | Grouping proven by screenshot; nav contract test green |
| **P3** | **Core CMS completeness** | The P0 matrix's high-severity gaps: list-table affordances, form validation, revisions, media depth, scheduling, bulk actions | Per-surface parity proof, browser-verified |
| **P4** | **Depth pass** | Every surface: consistent chrome, empty states, error states, confirmations, a11y (labels, focus, keyboard), no dead ends | Screenshot set per surface |
| **P5** | **Re-score and release** | Matrix re-scored; remaining red cells closed, decided or refused | Zero unexplained red |

Sequencing note: **P1 and P2 can run in parallel with P3** because they touch chrome and IA while P3 touches surface
behaviour. **P0 must precede P3** — building against an unmeasured gap list is how the first audit went wrong.

### Dependency reality — both defects the director reported are governance-blocked

| Defect the director reported | Phase | Blocked by |
|---|---|---|
| Theme Studio renders without the sidebar | P1 | **CD-58.** Proven deterministically from the tenant policy row: `akira.shell.admin_page@1` v30, active, `caller_module = cms-akira-shell,cms-akira-seo,cms-akira-navigation`. `cms-akira-theme` is absent — though it declares the dependency (`module.json:122`) and calls it (`helpers.php:1390`). Exactly **one** shell-owned capability carries a caller allowlist, so the class is contained to that one row. |
| Sidebar is a flat 20-link list (IA) | P2 | **CD-59.** Grouping needs a `group` field on sidebar contributions — a `module.json` edit — which the ledger blocks unconditionally. |
| Other admin pages lack the sidebar | P1 | **Substantially resolved — statically verified.** Only three modules consume the shared chrome (theme, seo, navigation); `cms-akira-shell` serves the other 19 admin routes directly through `akiraShellPage()`. seo and navigation were fixed in `345fcaa`. The remaining gap is therefore **Theme Studio alone**, i.e. the CD-58 row above. Live-session confirmation is still required before P1 chrome can be called done. |

Both things the director noticed by hand are the two things that cannot proceed without an owner decision. That is
not a coincidence, and it is the most important fact about this project's critical path: **the gates are the
bottleneck, not implementation capacity.** The revised plan therefore front-loads every unblocked slice and puts
the decisions first rather than last.

**And the gate hid a red test — the single most important artifact in this project.** The `admin-shell-integrity`
run wrote `tests/browser/akira-admin-shell.spec.ts`, which builds its route list from every sidebar destination **plus
`/cms-akira-theme` explicitly**, then asserts the shared nav is visible on each. That test is **RED in the tree right
now**: both viewports fail with `getByRole('navigation', { name: 'Akira administration' })` not found on Theme Studio.

The run was `blocked`, acknowledged, and `commit-check` reported the ledger clean — so **a failing test sat in the
tree while the gate declared the run final.** A red test is the clearest statement of a defect this project will ever
produce, and the gate discarded its signal. That is the forest that was being missed: not a missing measurement, but
a failing one nobody read.

## Standing constraints

- One writer per file. Never dispatch two lanes concurrently (the ledger's `finish` absorbs the other run's delta).
- Never touch the verifier trust surface, `phpstan-baseline.neon`, `.governance-baseline.json`, or an existing test.
- Run `phpstan analyse -c phpstan.neon` on changed files before every commit — a bare path reports nothing and looks
  like a pass.
- Cache-bust every probe (`?cb=$RANDOM`); the page cache has already produced one false finding.
- **Re-authenticate, and prove a known-good page still works, before ANY measurement sweep.** A stale session jar
  returns `403 Access denied` for every route, and an Access-Denied page has no table, no search, no filter and no
  pagination — so a sweep over an expired session **manufactures a complete, plausible, entirely false gap report**
  ("Akira has no list affordances anywhere"). On 2026-09-15 exactly this happened: 19 routes measured, 19 routes
  reported empty, and the only reason it was caught is that `posts` returning "no table" is impossible.
  **Sanity-check one page you already know renders before trusting a sweep.**
- Prefer a script file over a long inline shell loop: the terminal wrapper has spliced a nested loop and silently
  truncated the body once already, yielding a clean-looking table of zeros.
- A collision/overlap assertion must account for scroll clipping, or it fails forever on a scrollable element.
- Report `SKIP` as `SKIP`. A skip is not a pass.
- **`php tools/ai-watch.php --watch` while a slice is dispatched.** Nothing else in the harness monitors a running
  process (see the defect record below), so an unwatched dispatch is an unobserved one.

### Measurement harness — RESOLVED: use the repo's own browser suite (2026-09-15)

**The measurement already existed, and it was rebuilt badly.** `npx playwright test` runs **11 specs in 7 files**
(`akira-admin-shell`, `akira-builder-admin`, `akira-media-admin`, `akira-post-publish-journey`, `akira-theme-activate`,
`live-hosts`, `read-authority`), with `tests/browser/auth.setup.ts` as a Playwright `globalSetup` that logs in **once**
and persists a `storageState` the chromium project reuses.

`auth.setup.ts` already documents the exact failure hit three times in this session:

> *"The kernel login limiter permits 5 attempts per 300s window. Every authenticated spec used to submit the login
> form itself (7 POSTs in this tree…), so the limiter refused the later attempts and the specs failed with a timeout
> that reads exactly like an authorisation regression."*

**Rule: never hand-roll a login or a sweep — run the suite.** Measured 2026-09-15: **9 passed, 2 failed in 2.3m**, and
the `read-authority` spec reports every declared admin surface at **200**, so the 403s seen from bespoke curl and
browser sweeps were entirely a session artefact — for the third time.

The bespoke `scripts/akira-admin-affordance-probe.sh` has been **deleted**: it could not authenticate, it duplicated a
better harness, and its unvalidated marker set is exactly the false-gap-report trap this project exists to avoid.

### Measurement harness — the parts that bit us (2026-09-15)

Three separate sweeps have now been invalidated by auth, so the mechanics are recorded rather than rediscovered:

- **Admin pages are session-authenticated; the API login is not.** `POST /api/v1/auth/login` returns a **JWT and no
  `Set-Cookie`**, so a `curl` cookie jar cannot authenticate an admin page — every route answers the kernel 403
  "Forbidden — Ikabud Kernel OS". Measuring the admin UI therefore needs either the HTML login form plus a CSRF
  token, or a real browser session.
- **The login identity is the email**, `charlienacario884@gmail.com` — not the username. `akiraadmin` and `ikabud6`
  both answer `401`. This is a correction: the recorded credential was the username form and was wrong.
- **The limiter trips after ~5 attempts** and then answers `429` to every login for the retry window. Log in **once
  per run**, and never retry a `401` blindly.
- **`scripts/akira-admin-affordance-probe.sh` asserts a known-good page before reporting anything.** It aborted
  (exit 2) rather than emit a 20-row "everything is missing" table. Three sweeps have been invalidated by auth;
  this is the first that refused to lie.
- **The marker set is unproven.** No authenticated admin page has yet been read, so whether these surfaces use
  `<table>` at all is unknown — the probe's own markers are the first thing to validate once a session exists.
  An unvalidated marker produces exactly the false gap report this project exists to prevent.
- **`unknown_role` means *unauthenticated*, not *unauthorised*.** `CapabilityAuthorizationRegistry.php:79` emits it
  when `$actorRole === ''`. A sweep where every route carries this reason is a dead session, not a policy finding.
  Mislabelling this produced a false "confirmed live" claim about CD-58 on 2026-09-15 which had to be corrected in
  the decision record. **Read what a reason code means before it becomes evidence.**
- **Authority state is per-tenant, and CLI `app()->db()` is not the tenant.** Measured: CLI resolves to `ikabudsix`
  with **12** policy rows, while tenant 54's `akira` holds **1531**. A query for the chrome capability's policy
  returned "zero rows" from the wrong database and nearly produced the opposite conclusion. Use
  `app()->dbForTenant(<id>)`.
- **A `403` or `404` is never evidence about a product surface until an authenticated session is proven.** Every
  sweep must first fetch a page known to render and assert it, or it must refuse to report.

## Harness defect found and fixed: nothing monitored a running process

Director direction, 2026-09-15: *"no running process is monitored."* Correct, and verified:

| Evidence | What it means |
|---|---|
| `grep -nE "log\|pid\|monitor\|watch\|alive\|heartbeat" tools/ai-loop.php` → **zero matches** across 599 lines | the dispatcher has no concept of a process |
| `--log` is supported but was **never passed**: all 49 records carry `"log": null` and every run lists `log=0B` | the capability already existed; the recipe left it optional, so not one byte of lane output was ever captured |
| pid→`abandoned` reconciliation (`ai-run.php:1162`) runs **only** when `status` is polled | liveness was a pull operation, never a watch |
| 20 of 49 recorded runs sit in `blocked`, a state set when `finish`'s conformance check fails (`:1109`) | **"awaiting a decision" and "finish failed validation" are indistinguishable**, so the queue cannot be triaged |
| Dispatch pattern `pi … 2>&1 \| tee $RPT \| tail -30`, then `finish --exit=$EXIT` | `$EXIT` is the **pipeline's** status = `tail`'s = always 0, so a crashed run records exit 0 |

Consequences: a process that died mid-flight left a record reading `running` that nothing contradicted until a human
polled `status`; a hung process held the single dispatch slot with no timeout; two concurrent lanes corrupted the
ledger silently.

**Fixed — observability half: `tools/ai-watch.php`.** Strictly read-only; it never writes to `.ai/runs/`, so
reconciliation authority stays in exactly one place and the two tools cannot disagree. It reports what is in flight,
whether the owning pid is genuinely alive, and whether the single-dispatch invariant is honoured. Exit 3 on a stale
run or on concurrent live runs.

Falsified before trust — three cases, all against a synthetic `--runs-dir` so the real ledger was never written to:

| Case | Expected | Observed |
|---|---|---|
| `running` + dead pid | STALE, exit 3 | STALE named the dead pid, exit 3 |
| one `running` + live pid | clean, exit 0 | exit 0 |
| two `running` + live pid | concurrency anomaly, exit 3 | anomaly named both runs, exit 3 |

Liveness is deliberately derived from the pid alone, mirroring the ledger's own `pidIsAlive()`. The command line is
shown as *evidence* only — the falsification run displayed `/usr/lib/systemd/systemd` for pid 1, so deriving liveness
from it would have manufactured a false "live run". PHPStan `[OK] No errors`; `--watch` verified to exit with the
status it actually observed on SIGINT.

**Not fixed — the root cause sits on the trust surface.** Heartbeat, per-run log capture and a hang timeout belong
*inside* `tools/ai-run.php` / `tools/ai-loop.php`. Every run records a `trust_surface_hash`, so an unannounced edit
would invalidate the provenance of all 49 existing records — that is an owner-authorised change, not a chair one. The
monitor makes the gap visible; closing it needs authorisation.

**Third defect, found while verifying the fix: `commit-check` prints a claim its own records contradict.** With 20 of
49 runs in `blocked`, the ledger reports:

```
COMMIT-CHECK — .ai/runs
  ELIGIBLE — all 49 run(s) are completed
```

All 49 are not completed. `blocked` *is* genuinely gating in `commit-check`'s own logic (`ai-run.php:2052-2077`); it is
skipped only when an entry in `.ai/runs/.acknowledged-blocks.v1` names the run. So eligibility is correct while the
**message is false**. All 20 acknowledged runs carry `scope_conformance_ok_observed: false` — scope conformance was
never verified for any of them.

That matters beyond wording. A gate that says "all completed" while 41% are blocked reads as a clean ledger, and it
is precisely why nothing needed monitoring: the acknowledgement route satisfied the gate, so no one had to look at a
running process. And because the recipe took the exit code from `tail`, the ledger never learned a run's real
outcome — so `blocked` could not be told apart from `crashed`.

**Fixed — dispatch discipline (runbook, not trust surface).** The capability was already there and unused. The recipe
in `.ai/ai-autonomy-harness.contract.md` now treats `--log` as mandatory, captures the lane's **own** exit code
(`set -o pipefail` / `${PIPESTATUS[0]}`) rather than a pipeline's, and documents running `ai-watch` alongside every
dispatch.

**Still unfixed — two trust-surface items:** a real heartbeat/hang-timeout in `ai-loop.php`, and a `commit-check`
message that distinguishes `completed` from `blocked-and-acknowledged`. Both need owner authorisation (CD-59).

## Chair decision CD-60 — the block was the harness, not the contracts

**Issue.** 20 of 49 runs sit `blocked` and are waived through by acknowledgement. Is the conformance gate
miscalibrated, are the contracts badly written, or is the harness unable to hear what it demands?

**Options, and the evidence that decides between them.**

- *Tighten the acknowledgement route* — **rejected.** Reading all 20, the agents were *right*: CD-53, CD-56 and CD-58
each argue that the edit "is authorised by this contract's Acceptance criteria", and the contract does ground it in
prose. Making acknowledgement harder would have blocked correct work, not bad work.
- *Blame the contracts* — **partly true.** CD-51, CD-52, CD-55 and CD-57 are genuine authoring errors: scope omitted
a path the prose required, or listed a prohibited one.
- *The harness cannot hear the justification it demands* — **the dominant, mechanical cause.** A relative L4 trigger
resolves to L3 only when `isGrounded($justification, $contract)` holds (`ai-autonomy.php:1074-1082`: the
justification must be a verbatim substring of a contract `constraints`/`acceptance` line), and
`scopeConformance()` never passes one. So `module.json` — needed by almost every module slice — **blocks
unconditionally, whatever the contract says.**

**Chosen:** treat the harness as the root cause; deliver the preventive half now and escalate the mechanical half.

**Delivered:** `tools/ai-authority-preflight.php` — read-only; asks the ledger's own per-path question for every
`allowed_scope` entry and exits `3` when one will escalate. Validated against history, not asserted: it reproduces
the exact path `finish` flagged for `admin-shell-integrity`, and of the blocked contracts that still exist,
**4 of 4 are predicted** (2 more could not be tested — those contract files no longer exist, which the tool reports
correctly rather than counting as a miss).

**Escalated (CD-59):** pass a justification through `scopeConformance()`, so a grounded justification stops being
invisible to the ledger. Until then every L4-trigger path blocks regardless of the contract, and acknowledgement
remains the only remedy — which is precisely why the gate read clean while 41% of runs were blocked.

Authority: this project, director instruction 2026-09-15. Owner intervention: not required for the pre-flight;
required for the CD-59 trust-surface change.

## Outstanding owner decisions (do not block the project)

| ID | Question | Recommendation | Effect if unanswered |
|---|---|---|---|
| **CD-58** | How does a capability gain a caller? | Let a **presentation-only** capability (`*.shell.admin_page@1`, `*.theme.read@1`) name additional callers without a policy-version bump. These expose no data and no mutation, so the allowlist protects nothing while blocking every new admin page. | Theme Studio cannot join the shared shell; the `widening_refused` class persists into every new admin surface |
| **CD-59a** | May `scopeConformance()` forward a justification to the per-path check? | **Yes — recommended first.** `isGrounded()` already demands a justification that is a verbatim substring of a contract `acceptance`/`constraints` line, and `finish` never supplies one, so every relative-L4 path (`module.json`) blocks unconditionally. This is a channel repair, not a weakening: nothing in the taxonomy changes and every call stays auditable. | Every slice touching a `module.json` blocks, so P2 and most of P3 cannot run at all |
| **CD-59b** | May the ledger gain a heartbeat, a hang timeout and an honest `commit-check` message? | Yes. A hung slice currently holds the single dispatch slot indefinitely, and `commit-check` prints "all N run(s) are completed" while 41% are blocked. | Monitoring stays observational; a hung run is never reaped, and the gate keeps asserting something its own records contradict |
| **CD-55** | Does the absolute prohibition cover *additive, non-weakening* changes to the capability registry? | Yes — additive registrations should be L2, not L4. | `ModuleInstallService.php:78` stays defective with no lawful route |

Both are recorded with options and recommendations. Work proceeds around them; neither is on the critical path for
P0–P4.

## Anti-patterns this project will not repeat

- Calling an HTTP status or a byte count a verification of a UI.
- Claiming a surface exists because a handler or template exists.
- Measuring by counting files of one extension.
- Reporting a yellow/green matrix where a cell was inferred rather than observed.
- Asking the director to approve in-scope implementation decisions.

## Slices

| Slice | Phase | State |
|---|---|---|
| `p0-gap-analysis` | P0 | queued |
| `p2-nav-grouping` | P2 | queued (design ready; the grouping mechanism exists unused) |
| `p1-theme-studio-chrome` | P1 | blocked on **CD-58** (caller widening) |
| `p0-gap-analysis` → feeds | P3/P4 | queued behind P0 |
