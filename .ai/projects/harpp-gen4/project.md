# PROJECT — harpp-gen4: prove the governed-autonomy concept on HARPP itself

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — the harness **and** the subject.
subject: `tools/harpp-bridge/` — the HARPP bridge, copied here as-is by owner decision (CD-16, 2026-09-14).
revision: 3 — S2 and S3 delivered together; slice S1 remains withdrawn (see below).
references: `.ai/ai-autonomy-harness.contract.md`
authority: owner directive 2026-09-14 — *"make this concept provable, stable, measurable, repeatable, seamless"*, with **HARPP as the project** and the Sol lane reset.

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

## Objective

Make the Gen 4 proposition **provable, stable, measurable, repeatable and seamless**, using HARPP itself as
the first governed project.

Gen 4 is: *complete this approved project; make subordinate decisions yourself; prove what happened; stop only
when the contract says so.* Today three of those four clauses have machinery and the first does not: the
harness completes **slices**, not projects, and every slice in this session was dispatched by hand.

### The five properties, made concrete

| Property | Meaning here | Delivered by |
|---|---|---|
| **Provable** | every stage gate consumes **re-derived claims**, never a self-reported marker; evidence names the tree it describes | S3, S5 |
| **Stable** | deterministic, fail-closed; a `silent`, `failed`, `abandoned` or `CONTRADICTED` stage blocks; no reliance on strings an executor prints about itself | S3 |
| **Measurable** | the metric table is produced **from artefacts**, per project, including cost/tokens or an explicit `unavailable` | S4, S5 |
| **Repeatable** | one harness governs many repos via a project manifest that can be re-run unchanged | S1, S2 |
| **Seamless** | ≥2 consecutive slices run without a conversational turn; the director is reached only at L4 | S3, S5 |

### Architectural decision — **revised by owner decision (CD-16)**

**Superseded.** The original reasoning below assumed the subject lived in another tree
(`/var/www/html/applicationostest`) and therefore needed a control-plane/subject split with `--repo=<path>`.
On 2026-09-14 the owner directed: *"copy harpp in this workspace as is and develop it"*. The bridge now lives at
`tools/harpp-bridge/` in this repository, so **governing a different tree is no longer required** and slice S1
is withdrawn under YAGNI. `--repo` parameterisation is deferred, not abandoned: it returns when a project
legitimately lives outside this tree, and dropping it now avoids building transport for a case that no longer
exists.

Retained as history — the reasoning that made the split look necessary:

> The HARPP tree contains none of the updated harness: no `tools/ai-autonomy.php`, `tools/ai-run.php`,
> `tools/ai-contract-lint.php`, no standing contract; it has an older `.ai/` of its own. The harness would be
> the control plane, projects its subjects, addressed by `--repo=<path>` — copying the harness into each
> project would create two copies to keep in sync and destroy the single source of truth that makes
> governance meaningful.

The second half of that reasoning still holds and is now satisfied by the opposite route: there is **one**
harness and **one** subject tree, because the subject was brought to the harness rather than the harness
copied to the subject.

**Convention this establishes (and S2 must enforce):**

```
.ai/projects/<project-id>/project.md      this file — objective, slices, acceptance, budget, metrics
.ai/projects/<project-id>/slices/<n>.md   one slice contract per stage
.ai/projects/<project-id>/metrics.json    derived metrics, written by tooling, never hand-edited
```

**Slice ids must be `S<digits>`.** The loader scans a slice file's **first 12 lines** for `\bS\d+\b` and falls
back to the filename with `/s(\d+)/i`, so a filename such as `s3b-claim-commands.md` silently resolves to `S3`
and collides with an existing slice (`ERROR: slice S3 is declared by more than one contract`). Observed on
2026-09-14; the slice was renamed rather than the loader relaxed.

## Slices

Ordered; each slice is dispatched, gated on evidence, and recorded in the ledger.

| # | Slice | Delivers | Unlocks |
|---|---|---|---|
| **~~S1~~** | ~~**Repo parameterisation**~~ — **WITHDRAWN** (CD-16): the subject is now in-tree, so no cross-repo transport is needed. Deferred until a project legitimately lives elsewhere | — | — |
| **S2 — DELIVERED** | **Project object** — `tools/ai-project.php` (`status`, `next`, `obligations`); the project format above; obligations computed across slices, so the **stop invariant works at project level** | measurable · provable | S3 |
| **S3 — DELIVERED** | **Evidence-gated loop** — `tools/ai-loop.php --project=<id>`: dispatch by lane policy → ledger `start`/`finish` → **verify (must be `RE_DERIVED`)** → next. `commit-check` runs before every dispatch. Stops on the first failed gate. **No marker trust.** | provable · stable · seamless | S5 |
| **S6** | **Claim-command convention** — a claim declares its command; the extractor prefers command-bearing evidence; exact-match derivation only where the file exists and is pure, recorded as *derived*; plus a recorded re-queue path out of `blocked` | provable · stable | unblocks S4 |
| **S4** | **Metrics capture** — derive the metric table from artefacts; capture cost/tokens where the runner exposes them, otherwise record `unavailable` **explicitly**; accept director-minutes as a logged input | measurable | S5 |
| **S5** | **First real run** — bind the harness to the HARPP tree and run a small real HARPP task end-to-end through the loop, unattended, ≥2 slices, and produce the metric table from artefacts | the Gen 4 proof | — |

## S2+S3 delivery record — 2026-09-14

Chair decision: deliver S2 and S3 as one bounded implementation because the loop needs project
obligations to exercise its gates; this is the decomposition authorised by the S2+S3 slice, not a scope
increase. Owner intervention was not required (CD-8).

Ledger run `s2s3-delivery` is `completed` and its four claims are independently `RE_DERIVED`, bound to
revision `b0e57e2aaeade1052c782920817001a64c2bcc47` with `dirty=yes`:

- `php tests/ai_project_test.php` — 6/6, exit 0, zero skips;
- `php tests/ai_loop_test.php` — 8/8, exit 0, zero skips, including marker-only refusal,
  commit-check refusal, dry-run no-write, all fail-closed modes, two-slice unattended progression, and
  the hard slice bound;
- `php tests/ai_run_test.php` — 31/31, exit 0, zero skips;
- `php tests/ai_autonomy_test.php` — 46/46, exit 0, zero skips.

`state.json`, written through `ai-project.php`, records `pending → running → done` for S2 and S3 with that
run id. Two project obligations remain (S4 and S5); `stop-report --remaining=2
--stop-reason=PROJECT_COMPLETE` correctly exits 3 with `ILLEGITIMATE_STOP`. The project is therefore not
claimed complete.

## Architectural constraints

- **The loop must never trust a marker.** `marker: "SOL_IMPL status=PASS"` is a string the executor prints
  about itself; a stage passes on `RE_DERIVED` claims or it does not pass.
- **Fail closed.** Unknown state blocks. A missing report is `silent`, not success.
- **Never commit while any run is not `completed`** — `commit-check` decides, not the appearance of the tree.
- **No new authority semantics.** This project adds transport and accounting, never a new way to authorise
  work. Autorisation stays contract-relative, and the absolute prohibitions stay absolute.
- **The harness must not be the source of its own metrics.** Derive from artefacts; the director logs
  director-minutes; incorrect Chair decisions are counted (three already are: CD-6, CD-11, CD-12).
- Do not modify the HARPP tree from the control plane during S1–S4 beyond reading it. Writing to the subject
  tree begins at S5, under its own slice contract.

## Files likely affected

- `tools/ai-run.php` — `--repo`, tree binding per repo
- `tools/ai-project.php` — new; project status, next slice, obligations
- `tools/ai-loop.php` — new; the evidence-gated loop
- `tests/ai_project_test.php`, `tests/ai_loop_test.php` — new; pure suites
- `.ai/projects/harpp-gen4/` — this project's artefacts
- `.ai/ai-autonomy-harness.contract.md` — runbook: the project convention and the loop

## Acceptance criteria

1. `--repo=<path>` works end to end: a run recorded against the HARPP tree reports that tree's `rev` and
   `dirty` state in its tree binding, and `commit-check` evaluates **that** repo.
2. `ai-project.php obligations` reports remaining obligations for this project as a number, derived from the
   slice table — and **the stop invariant consumes it**: `stop-report --remaining=<that number>` behaves.
3. The loop runs **≥2 consecutive slices without a conversational turn**, gating each on `RE_DERIVED` claims;
   a stage with a `silent`, `failed` or `CONTRADICTED` result **blocks the project** rather than advancing.
4. A deliberately marker-only stage (evidence absent, marker string present) **does not pass** — demonstrated,
   with the output pasted. This is the non-vacuity anchor for "no marker trust".
5. The metric table for this project is produced **from artefacts**, with cost/tokens either captured or
   explicitly `unavailable`.
6. Every new suite exits `0` with zero skips, and `ai_run_test` / `ai_autonomy_test` remain green.
7. The runbook documents the project convention and the loop, and `ai-contract-lint.php` learns
   `.ai/projects/**` or states plainly why it does not lint projects.

## Required tests

- The `--repo` proof: a run bound to the HARPP tree showing its `rev`/`dirty`, and `commit-check` against it.
- The obligations count, and `stop-report` consuming it.
- A ≥2-slice unattended loop run, with the stage-by-stage evidence.
- The marker-only blocking demonstration (falsification).
- The derived metric table, pasted.
- `php tests/ai_project_test.php`, `php tests/ai_loop_test.php`, plus the two existing suites.

## Risks

- **The loop is the most dangerous artefact yet built here**: it dispatches work by itself. It must be
  fail-closed, must respect `commit-check`, and must stop on the first contradiction rather than pushing on.
- **Cross-tree writes** (S5) are the first time this harness modifies a repo other than its own. Subject-tree
  writes need their own contract, their own allowed scope, and a pre-flight `git status` in that tree.
- **Cost/token capture may not be possible** from the runner. Record `unavailable` rather than estimate — a
  fabricated cost figure would poison the one metric the entire cost thesis rests on.
- The `roadmap-slices.json` precedent exists but uses stale model identifiers; do not copy it blindly.

## Forbidden changes

- `.github/workflows/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
- `kernel/`
- `modules/`
- `src/`
- `tests/browser/`
- `playwright.config.js`
