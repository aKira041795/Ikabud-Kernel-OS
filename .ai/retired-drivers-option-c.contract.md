# Apply option c: retire the legacy drivers, and stop briefing lanes from them

## Provenance

This is not chair judgement. It is **decision `retired-tool-authority-20260919-153252`, answer: option
c**, from the director on 2026-09-20. That decision was filed because `tools/RETIRED.md` declares
`ai-run.php`, `ai-project.php` and `ai-autonomy.php` superseded while `copilot-instructions.md`
documents them as the current drivers — two authoritative documents making opposite claims, so an
agent's choice of tool is arbitrary.

The answer is option c, in full:

> **c | Retire them AND exclude them from retrieval** — as option a, plus add the retired prefixes to
> the index so lanes are never briefed from dead tools.

This is the first decision the chair has sent that came back and changed the plan. Record that link:
the change below exists because of that decision, and a future reader must be able to establish it.

## Objective

1. Make `tools/RETIRED.md` the **single authoritative list** of what is retired, covering
   `tools/ai-run.php`, `tools/ai-project.php`, `tools/ai-autonomy.php` and `tools/harpp2/`.
2. Exclude those paths from retrieval, so a lane is never briefed from a tool the repository has
   retired. The mechanism exists: `RETIRED_PREFIXES` in
   `kernel/Workbench/Retrieval/RetrievalIndex.php`, already carrying `tools/harpp2/`.
3. Correct the contradiction: `copilot-instructions.md` must stop presenting the retired drivers as
   the current ones. Point at the live harness (`tools/chair.php`) instead.

## Architectural constraints

- **The retired rule is declared once**, as prefixes in `RETIRED_PREFIXES`. Do not scatter
  `str_contains($path, 'ai-run')` checks. A prefix like `tools/ai-run.php` is a file, not a directory —
  make sure the matcher handles both, and that `tools/ai-run.php.bak` style near-misses are considered
  deliberately rather than by accident.
- **`copilot-instructions.md` is read by every agent on every task.** Edit it surgically: correct the
  claims about the retired drivers, and do not rewrite unrelated content. This is a correction, not an
  editorial pass.
- **Do not delete any retired file.** The director's earlier instruction stands: retire, do not delete,
  because there is still something to learn from them. Retirement means "not current and not briefed",
  not "gone".
- **Do not touch the live harness.** `tools/chair.php` is the current driver. `ai-autonomy.php`'s
  authority-ladder vocabulary (L0–L4) is still referenced by policy documents; retiring the *driver*
  does not retire the vocabulary. If a reference to `ai-autonomy.php` is genuinely about the ladder
  rather than the tool, leave it and say so.
- Re-index after changing the prefixes so the exclusion takes effect, and confirm it.

## Files likely affected

- `kernel/Workbench/Retrieval/RetrievalIndex.php`
- `tools/RETIRED.md`
- `.github/copilot-instructions.md`

## Acceptance criteria

- A retired legacy driver is not returned as context by default: `php kernel/Workbench/Retrieval/run.php search "autonomy driver run ledger commit-check" --limit=8`
- The retrieval self-test still passes, including the retired-exclusion checks in both directions: `php kernel/Workbench/Retrieval/run.php --self-test`
- The retrieval module test still passes: `php tests/retrieval_index_test.php`

## Required tests

- `php kernel/Workbench/Retrieval/run.php --self-test`

Also run, and they must still pass:

- `php tests/retrieval_index_test.php`

## Risks

- **Over-excluding.** `tools/ai-autonomy.php` carries the L0–L4 authority vocabulary that policy
  documents still cite. Excluding the file from *retrieval* is right; deleting the vocabulary's
  provenance would be wrong. Read before you strip.
- **A prefix that matches too much.** `tools/ai-` would swallow `tools/ai-autonomy-harness.contract.md`
  and similar live artifacts. Be exact.
- **Editing `copilot-instructions.md` beyond the correction.** It steers every agent; a broad rewrite
  is a much larger change than this decision authorises.
- **Claiming the exclusion works without re-indexing.** Confirm it with the acceptance command.

## Forbidden changes

- `tools/chair.php`
- `public/`
- `modules/`
- `tests/browser/`
- `.github/instructions/`
- Do not delete any file under `tools/`.
- Do not change the live harness, the delivery path, or the decision/return path.
