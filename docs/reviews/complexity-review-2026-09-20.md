# Complexity review — harness, HARPP, instructions, workflows

Reviewed 2026-09-20 by measurement on this tree. **Not a tidy-up list: there is one structural
contradiction, and it governs everything else.**

## The headline finding

**The governance layer contradicts itself about which harness is current, and the retired harness is
still a live runtime dependency.**

```
.github/instructions/ai-autonomy-escalation.instructions.md:43    php tools/ai-autonomy.php stop-report --remaining=<n> --stop-reason=<TYPE>
.github/instructions/ai-autonomy-escalation.instructions.md:232   php tools/ai-autonomy.php models
.github/instructions/ai-autonomy-escalation.instructions.md:397   tools/ai-autonomy.php resume --from-harpp
.github/instructions/ai-autonomy-escalation.instructions.md:431   Record run state with `tools/ai-run.php`
.github/instructions/ai-autonomy-escalation.instructions.md:433   Commit eligibility is decided by the ledger (`php tools/ai-run.php commit-check`)
.github/copilot-instructions.md:64                                The retired `tools/ai-run.php` ledger … is historical; it is not the current ledger
```

Both files are `applyTo: **/*` — so every request loads **both**, and they disagree. An agent following
the autonomy policy invokes retired tooling; an agent following copilot-instructions does not.

The retired trio is not isolated dead weight. It is depended on by:

- **kernel code**: `kernel/Workbench/Retrieval/RetrievalIndex.php`, `kernel/Workbench/Retrieval/run.php`
- **7 test files**: `ai_run_test`, `ai_project_test`, `ai_project_metrics_test`, `ai_autonomy_test`,
  `ai_autonomy_glob_scope_test`, `ai_contract_lint_test`, `ai_loop_test`
- **6 sibling tools**: `ai-loop.php`, `ai-watch.php`, `ai-contract-lint.php`, `ai-authority-preflight.php`,
  `ai-task`, and each other

`tools/RETIRED.md` exists, so the retirement was recorded — but never completed. **A retirement that is
recorded and not executed is worse than no retirement**: it leaves two live instruction sets, and the
reader cannot tell which tooling to trust.

This also explains a session-level mystery: the stop invariant was rebuilt as `continue-check` without
knowledge that `ai-autonomy.php stop-report` had existed and been retired (decision
`retired-tool-authority-20260919-153252` option c). The policy still cites it. I rebuilt a wheel because a
document pointed at a deleted axle.

## The measured surface

| Surface | Lines | Assessment |
|---|---|---|
| `tools/chair.php` | 4,295 | the live harness |
| `tools/ai-run.php` | 2,397 | retired, still referenced |
| `tools/ai-autonomy.php` | 1,468 | retired, still referenced |
| `tools/ai-project.php` | 946 | retired, still referenced |
| **retired trio** | **4,811** | **53% of the tooling surface** |
| `ai-development-execution-handoff.instructions.md` | **870** | duplicates the autonomy policy and AGENTS |
| `ai-autonomy-escalation.instructions.md` | 449 | normative, cites retired tools |
| other instructions | 648 | |
| `copilot-instructions.md` + `AGENTS.md` + `token-budget.md` | 647 | overlapping doctrine |
| **instruction layer** | **2,614** | loaded/available for every request |
| `tools/harpp-bridge/harpp_wake.py` | **5,113** | 66% of the Python surface in one file |
| other bridge workers | 2,672 | |
| workflows | 271 | proportional |

### Duplication, measured

| Concept | Files stating it |
|---|---|
| `HARPP` | **8** |
| `L4` | 6 |
| `T0` | 3 |
| authority ladder | 2 |
| `stop_reason` | 2 |
| `CONTRACT_BLOCKED` | 1 |

The autonomy doctrine is stated in **three or more** files and HARPP in **eight**. That is the same defect
as the product's four authority sources — *N places that must agree, and nothing reconciling them* — and
here it already produced a contradiction.

### A declaration that is simply false

`copilot-instructions.md` declares a **"Skills registry (19 files in `.github/skills/`)"** with a
two-table breakdown. `.github/skills/` contains **3 directories**. 16 of the declared skills do not exist.
This mirrors a skills listing that references `approval-workflow`, `attendance-wage-payroll`,
`financial-immutability`, `inventory-costing` — modules the repository does not contain.

### One more contradiction, in the Python layer

`~/.local/bin/harpp` is a **symlink into `/var/www/html/applicationostest/tools/harpp-bridge/harpp`** — a
different repository — while CD-16 states the bridge is developed in-tree here. So we maintain one copy,
execute a second, and the instructions describe the first.

## Ranked removals

| # | Removal | Lines | Risk | Why the rank |
|---|---|---|---|---|
| 1 | **Complete the retirement**: delete the trio, retire its 7 tests, clean the 2 kernel references, and make ONE instruction file authoritative | ~4,811 + tests | medium — kernel and tests are entangled | It removes the contradiction *and* the largest surface. Nothing else is trustworthy until the reader can tell which harness is real. |
| 2 | **De-duplicate the instruction layer** to one normative file per subject, others pointing at it | ~1,000 | medium — normative content must survive verbatim | `HARPP` in 8 files guarantees the next contradiction. |
| 3 | **Delete the false skills registry** and the claims about modules that do not exist | ~40 | none | A declaration that is false is worse than an absent one. |
| 4 | **Split `harpp_wake.py`** (5,113 lines) | 0 | medium | Review first — do not split a monolith I have not read. |
| 5 | **Resolve the bridge-copy contradiction** | 0 | low | Decide in-tree or `applicationostest`, then delete the other. |

## What I decide now, and what needs the director

**Decided — mine, first action:** item 3, immediately (it is a false statement, not a design choice), and
the *execution plan* for item 1 in dependency order: kernel references → tests → tools → instruction
file. Order matters: deleting tools first breaks the suite and the retrieval index, and a red suite hides
the real regression.

**Yours, because it changes what governs the work:**

- **Item 1's instruction decision**: which file is the single normative statement of the autonomy policy —
  `ai-autonomy-escalation.instructions.md` (449 lines, the L0–L4 source of truth) or
  `ai-development-execution-handoff.instructions.md` (870 lines, the workflow directive)? Both currently
  claim it. Recommendation: the escalation file stays normative for authority, the handoff file shrinks to
  a pointer at it — one fact, one place.
- **Item 5**: in-tree bridge or `applicationostest`, not both.

## What I am deliberately NOT doing

- Not deleting the trio in one commit. The kernel and 7 tests depend on it; that is a slice with a red
  baseline, not a cleanup commit.
- Not splitting `harpp_wake.py` before reading it. 5,113 lines I have not reviewed is not a refactor
  target; it is an unknown.
- Not touching the workflows: 271 lines for two workflows is proportional, and no evidence they carry
  complexity.
