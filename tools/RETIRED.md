# RETIRED — the harness this replaces

**Status: retired 2026-09-19. Nothing here is deleted.** These tools still run, and this file is the
record of what they taught, so the good parts are not lost with them and the bad parts are not rebuilt.

Superseded by [`tools/chair.php`](chair.php), which drives `kernel/Workbench/Development/` (the
repository's own control plane) and `kernel/Workbench/Retrieval/` (the retrieval index).

## What is retired

| Path | Size | Superseded by |
|---|---|---|
| `tools/harpp2/` | — | `tools/chair.php plan` / `run` and contract probes |
| `tools/ai-autonomy.php` | 88 KB | `tools/chair.php` |
| `tools/ai-run.php` | 111 KB | `tools/chair.php` and `kernel/Workbench/Development` task records |
| `tools/ai-project.php` | 40 KB | `tools/chair.php` and the kernel contract format |

This table is the single authoritative list of what is retired. A directory path covers everything
beneath it.

**EXECUTED 2026-09-20 — the three files above are deleted, not merely listed.** 4,811 lines removed.
Their seven tests were retired by this repository's own convention (renamed `*.php.retired` under
`tests/_retired/`, so the `*_test.php` runner does not discover them). Suite afterwards: `201 files —
148 passed, 53 skipped, 0 failed` — exactly the previous run less the seven tests, and nothing else
changed. `RETIRED_PREFIXES` in `kernel/Workbench/Retrieval/RetrievalIndex.php` still names these three
paths; they are now vestigial and deliberately left alone, because excluding absent files is harmless
and touching the retrieval index buys nothing.

**Five further tools were retired in the same commit, with the evidence recorded here:** they each
`require` a file deleted above, so they could not load at all.

| Tool | Requires | Now |
|---|---|---|
| `tools/ai-loop.php` | `ai-project.php` | deleted |
| `tools/ai-watch.php` | `ai-run.php` | deleted |
| `tools/ai-contract-lint.php` | `ai-autonomy.php` | deleted |
| `tools/ai-authority-preflight.php` | `ai-run.php` | deleted |
| `tools/ai-task` | `ai-autonomy.php` | deleted |

Their two tests went with them. **A tool that cannot load is not a tool**: leaving them would have been
dead code wearing a live name, which is the same defect this repository spent 2026-09-20 removing from
the instruction layer — an agent told to use a path that no longer resolves. The retrieval index mirrors these four entries in `RETIRED_PREFIXES` in
`kernel/Workbench/Retrieval/RetrievalIndex.php`, so a lane is never briefed from any retired path;
`--include-retired` is the one way to search it deliberately. Retiring the three drivers retires the
*drivers*, not the L0–L4 authority vocabulary they carried, which policy documents still cite.

## Why it was retired — the measurements, not the impression

| Measured 2026-09-19 | |
|---|---|
| Star Swarm p12 through harpp2 | **46 min**, escalated, then **14 min** more |
| the same item's work | had already finished at 18:52 — the harness refused to RECORD it |
| Star Swarm p13 done directly | **10 min** |
| the harness's own measurements that were wrong | **four**, each costing more than the work it guarded |

The four, because they are the argument:

1. **The DROP guard** refused `npx playwright test --grep "the drop is legible as it expires"` as "destroy
   data". The word *drop* — from a requirement about a weapon that drops — cost two runs and 46 minutes,
   and the completed feature was thrown away over a substring.
2. **A moon threshold** that scored a pale-cyan planet `[232, 248, 248]` as "grey" and passed on the
   unfixed product. A check that passes before the work is done is measuring the wrong property.
3. **A stale assertion** carrying a comment saying RETIRED while the `$check()` call stayed in place, and
   passing only because a planet's radial gradient happened to satisfy a needle about the background.
4. **A "simplification"** that moved the acceptance battery under a new heading — `acceptanceCommands()`
   keys off `$ ` inside any fenced block, not off headings, so 11 per-chunk commands became **13**.

The pattern in all four: **the machinery was trusted, and never proved.** Which is why the replacement
ends every guard with a `--self-test` that asserts both directions.

## The best things in there, and where they went

Extracted rather than lost:

- **`assertions.php` → `kernel/Workbench/Development/AssertionChange.php`.** Telling DELETING a check from
  MOVING it: assertion sets compared with whitespace normalised and strings masked, plus numeric bounds so
  `toBeGreaterThan(8) → toBeGreaterThan(2)` is still caught. The new harness calls it as a gate and refuses
  a pass bought by weakening the tests around it. Two limits are asserted rather than assumed (a line is an
  assertion, so minified or reflowed assertions are not covered), and the markers had to be widened: the
  default set does not recognise this repository's own `$check(...)`, and the first attempt at widening it
  lost the word boundaries and counted a *comment* and `$fail = 0;` as assertions.
- **`destructive-introduction.php` → the delta-aware idea.** A destructive statement counts only if the run
  ADDS it; a file that merely *touches* a line it already had is not accused. This is what stopped a
  legitimate restructure being refused, and the pattern is single-sourced so the two copies cannot drift.
- **`verify.php` → `classifyGateOutcome` → the FLAKY classification.** Failed once and passed on retry is
  instrument instability, never a clean pass. `chair.php run` records FLAKY explicitly.
- **The chair-owned instrument principle.** A lane that can satisfy a requirement by editing the instrument
  has proved nothing. Kept, and it is why the pixel spec and the gate are not in a lane's scope.
- **The red baseline.** Assert the probe FAILS before the work starts. Promoted from a habit into rule 2:
  a probe that already passes stops the run.
- **The pixel-truth lesson.** Every gate was green while the components looked nothing like Galaga, because
  every probe read STATE and none read PIXELS. Kept as the reason probes assert on rendered output.
- **The run ledger idea.** Never infer run state from log size or `pgrep`. Kept as a Workbench task record.

## What not to rebuild

- **An escalation taxonomy.** `escalate()` existed to ask the director questions the chair is appointed to
  answer. A taxonomy of stops is a way of not deciding. The replacement has none: failure promotes the lane
  and retries, exhaustion records a decision naming the options considered.
- **Contracts about contracts.** Objectives, phases, gate phase-maps, projects and ladders existed to prove
  the PROCESS ran. No product value came from any of them in a whole day of p12.
- **A guard without controls.** `verify.php` and `assertions.php` were extracted into their own files
  specifically so they could be unit-tested — the comment says so — and the tests were never written.
  `verify.php --self-test` exited 0 printing nothing, which looks exactly like a pass.
- **Acceptance measured per chunk.** It re-ran the full suite on every chunk. Session expiry within each
  cycle then made specs fail for harness reasons and dispatched chunks to fix a product that was fine.

## Using it for reference

It still runs, and the self-tests still pass, which is what makes it useful as a worked example:

```bash
php tools/harpp2/boundaries_self_test.php          # both directions of the destructive guard
php tools/harpp2/assertions.php --self-test        # the original assertion comparison
php tools/harpp2/status.sh                         # the one thing it did genuinely well
```

`tools/harpp2/CONSTITUTION.md` is worth reading once. It is a clear statement of intent whose enforcement
outgrew its purpose — and that is the lesson, not a reason to keep it running.
