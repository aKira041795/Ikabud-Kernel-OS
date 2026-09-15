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

## Outstanding owner decisions (do not block the project)

| ID | Question | Effect if unanswered |
|---|---|---|
| **CD-55** | Does the absolute prohibition cover *additive, non-weakening* changes to the capability registry? | `ModuleInstallService.php:78` stays defective with no lawful route |
| **CD-58** | How does a capability gain a caller? (governed grant / operator command / unrestricted presentation caps) | Theme Studio cannot join the shared shell; the `widening_refused` class persists |
| **CD-59** | May the ledger gain a heartbeat, per-run log capture and a hang timeout? (trust-surface change) | Monitoring stays observational only; a hung run still holds the dispatch slot indefinitely, and `blocked` stays untriageable |

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
