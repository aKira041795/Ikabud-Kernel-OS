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
4. **Make the recall gate green.** `php kernel/Workbench/Retrieval/run.php recall` currently reports
   **1 of 3 cases found within their rank**, so the acceptance below cannot be met without it. This is
   not a regression and not the chair's judgement: the required-tests bug (fixed 2026-09-20, commit
   `e37018d`) meant only the FIRST command in `## Required tests` ever executed, so this gate had
   **never run** before today. Teated it as a specification that has now been measured.

   The measured starting state, so the lane does not have to rediscover it:

   ```
   [MISS] rank -  of 3  kernel/Workbench/Retrieval/RetrievalIndex.php
          query: retrieval index stale paths refused before dispatch
          returned: run.php (118), tools/chair.php (99), docs/reviews/harness-...-brief.md (95), RetrievalIndex.php (93)
          -- it IS indexed, 851 lines, matched 5 of 7 query terms: a near-tie lost on coverage, not a missing document
   [MISS] rank -  of 5  docs/testing/harness-lessons.md
          query: playwright spec failing console error trace
          -- the case written to fail when breadth is bought with near-copies of one directory
   ```

5. **Strengthen the retired exclusion with evidence that the retrieval gate can report the truth.**
   The gate is what proves item 2, so its red and green states must themselves be trustworthy.

## Why the two are one contract

The gate was red before the exclusion was written, and it is the instrument that measures the
exclusion. Fixing the ranking is what makes the exclusion verifiable; leaving it red would mean the
retired-driver check could only ever be a claim. This is one objective -- *the retrieval gate tells
the truth about this repository* -- and that is the only reason it is not two.

## Architectural constraints

- **The retired rule is declared once**, as prefixes in `RETIRED_PREFIXES`. Do not scatter
  `str_contains($path, 'ai-run')` checks. A prefix like `tools/ai-run.php` is a file, not a directory —
  make sure the matcher handles both, and that `tools/ai-run.php.bak` style near-misses are considered
  deliberately rather than by accident.
- **Fix the RANKING, not the cases.** The recall cases and their expected ranks are the specification.
  A mechanism that names a path, or special-cases a case, to satisfy them is the defect this contract
  exists to prevent: it would report a green gate for exactly this repository while proving nothing
  about retrieval. If a case cannot be satisfied by a general rule, say so and stop -- a documented
  miss is evidence, a faked pass is not.
- **Diagnosis precedes correction.** Do not adjust a scoring weight until the case's miss has been
  explained in terms of the mechanism (term extraction, coverage scaling, the spread quota, the use
  boost). The evidence is already in the acceptance output: the scores and matched terms are printed.
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
- `kernel/Workbench/Retrieval/run.php`
- `tools/RETIRED.md`
- `.github/copilot-instructions.md`

## Acceptance criteria

- A retired legacy driver is not returned as context by default: `php kernel/Workbench/Retrieval/run.php search "autonomy driver run ledger commit-check" --limit=8`
- The retrieval self-test still passes, including the retired-exclusion checks in both directions: `php kernel/Workbench/Retrieval/run.php --self-test`
- The retrieval module test still passes: `php tests/retrieval_index_test.php`
- The recall gate reports every case within its rank: `php kernel/Workbench/Retrieval/run.php recall`
- The recall gate can still report a miss, so a green gate is not vacuous: `php kernel/Workbench/Retrieval/run.php recall --control`
- **A query the cases were not written for is also retrieved.** Run
  `php kernel/Workbench/Retrieval/run.php search "spread fan five angles volleys in flight" --limit=5`
  and `star-swarm.js` must appear. This is deliberately NOT one of the recall cases: the cases are in
  the repository and could be satisfied by naming them, whereas this one can only be satisfied by a
  mechanism that ranks on the query. Report the rank whether it passes or not.

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
- **Satisfying the cases instead of the query.** The three recall cases are visible in `run.php`, so
  they can be passed by a path whitelist or a per-case tweak. That would turn the gate green while
  making it say less than it does now, and the control query above exists to catch exactly that.
- **Reporting a tied score as an improvement.** `RetrievalIndex.php` currently loses by 6 points to a
  file in its own directory. If the fix is a re-weighting, say which term or coverage term moved and
  why that generalises, or the change is indistinguishable from fitting.

## Forbidden changes

- `tools/chair.php`
- `public/`
- `modules/`
- `tests/browser/`
- `.github/instructions/`
- Do not delete any file under `tools/`.
- Do not change the live harness, the delivery path, or the decision/return path.
- `tests/retrieval_index_test.php`
- Do not delete, skip, loosen or renumber a recall case in `kernel/Workbench/Retrieval/run.php`, and
  do not change an expected rank. The cases are the specification; the mechanism answers to them.
