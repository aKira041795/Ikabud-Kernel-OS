# CONTRACT — dsh executor parity: carry one real harpp2 objective to a verdict

status: PARTIAL — boundary. The adapter and the instrument are proven, and `harpp2` produced a verdict, but the fixed target was already green so **no executor lane was ever dispatched**: parity on real work remains unproven. See CD-80.
repo: `/var/www/html/ikabudsix` — branch `main` (work in the tree)
owner: implementation agent (`openai-codex/gpt-5.6-sol`) via `tools/harpp2/dispatch.sh`
chair: this session · authority: **CD-79** (`.ai/chair-decisions.md`) — *"Next bounded action (not taken
here). Run one real `harpp2` objective end-to-end through `dispatch-dsh.sh` and let `harpp2` gate it, so the
comparison is an acceptance verdict rather than a checksum."*

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

**No new L4 is taken here.** The runtime is already installed and director-authorised by the previous slice
(`.ai/dsh-executor-spike.contract.md`); this slice only *uses* it. If satisfying the contract would require
another L4 — schema, authority semantics, a product path, a credential change, publishing, scope widening —
stop, record it, do not take it.

## Objective

Replace the previous slice's checksum probe with a **real acceptance verdict**: run one genuine `harpp2`
objective end to end with `dsh` as the executor, and let `harpp2` own the gate.

**Both outcomes are PASS for this contract** — `verified`, or `escalated` with a recorded, evidenced reason.
What is not acceptable is a verdict produced by anything other than `harpp2`'s own acceptance commands, or a
verdict reached by weakening a gate.

## The target objective — fixed, do not substitute

`tools/harpp2/objectives/star-swarm-deterministic-probes.md` — real, **unverified** (`state` shows
`escalated`, `verified: 0`, reason *"test assertion removed or weakened: tests/browser/star-swarm.spec.ts"`,
which was the assertion guard's false positive, repaired in CD-78).

**Why this target and not Akira D.1/D.2 (chair decision, recorded):** `dsh` is a `-rc.2` preview runtime and
`harpp2` bounds *paths*, not *meaning* — it cannot verify that a changed authorisation declaration is still
correct. The Akira read-governance items touch the authority surface and one of them has the executor author
its own acceptance test. A first parity run must not put an unproven runtime on that surface. Star Swarm is a
sandboxed demo with five **pre-existing** gates, so the verdict cannot be self-authored.

## Verified facts — do not re-derive

1. **`harpp2` is already runtime-pluggable.** `tools/harpp2/harpp2.php:418-424` reads `HARPP2_MODEL` and
   `HARPP2_DISPATCH`, and invokes `$dispatcher <name> <model> <thinking> <briefPath>`. `harpp2.php` therefore
   needs **no edit** for this slice.
2. **The probe backend exists** from the previous slice: `tools/harpp2/dispatch-dsh.sh`, 3-arg
   (`<name> <prompt> <answer-file>`), writes `runs/<name>.dsh.log`, journals `{"runtime":"dsh"}`, takes the
   shared lane lock with self-pid exclusion, fails closed with exit 4 when the runtime or credential is
   missing, requires Node ≥22.19, and passes the credential as `DEEPSEEK_API_KEY` only — never printed,
   never persisted (verified: a grep of the whole `DSH_HOME` for the key returns nothing).
   **It hard-caps the runtime at `timeout 180`**, which is fine for a checksum probe and far too small for
   real implementation work — see the timeout bullet under Architectural constraints.
3. **The pinned runtime is installed**: `tools/dev/dsh-spike/home`, `@deepseek-ai/dsh@0.1.5-rc.2`, 522
   packages, git-ignored via `tools/dev/dsh-spike/.gitignore`. Node 22.23.2 is present at
   `$HOME/.local/node-v22.23.2-linux-x64/bin/node`.
   **Timeout budget:** `harpp2.php:222` gives a lane **1800 s** (`runCommand`'s default). The 4-arg
   interface must use most of that, the 3-arg probe mode must stay at 180 s.
4. **CLI shape**: `php tools/harpp2/harpp2.php <run|status|journal> --objective=<file> [--max-stalls=2]`.
5. **The target's five gates** (all pre-existing, `star-swarm-deterministic-probes.md` §Acceptance):
   `bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10`,
   `php tests/star_swarm_concept_test.php`, `php tests/star_swarm_visual_test.php`,
   `npx playwright test tests/browser/star-swarm.spec.ts`, `php tests/star_swarm_shot_check.php`.
6. **A suspected structural blocker to settle before the run.** `tools/harpp2/stability.sh:24-25` loops
   `npx playwright test "$spec"` `$runs` times (default 10). Recorded envelope: the kernel login limiter
   allows **5 attempts per 300 s**, and `globalSetup` logs in per invocation — i.e. at most **2 full runs
   per 300 s window**. Ten consecutive runs may therefore be structurally impossible on this machine. This
   is an **instrument question, not a product question**: settle it cheaply and honestly (AC2) before
   attributing any failure to `dsh`.

## Architectural constraints

- **Additive.** Extend `dispatch-dsh.sh` to accept `harpp2`'s 4-arg interface *while keeping* the existing
  3-arg probe mode working. Do not edit `harpp2.php`, `chain.sh`, `dispatch.sh`, `verify.php`,
  `assertions.php`, `ai-autonomy.php` or `ai-run.php`.
- **The verdict is `harpp2`'s.** If the `dsh` lane fails, that is a legitimate result: escalate with the
  evidence. Do not rescue it by hand, and do not re-run gates until they pass.
- **Write-capable but bounded.** The lane must be able to edit files — the objective requires
  implementation. The permission mode used for the parity run must permit writes inside the objective's
  scope only; record exactly which mode was used and why.
- **Gate integrity (absolute).** Never loosen a threshold, delete or skip an assertion, add a sleep or
  `waitForTimeout`, raise or bypass the login limiter, edit a gate baseline, or edit a gate to obtain a
  pass. If a gate cannot run, that is a finding to record — not a gate to lower.
- **Bounded repair.** At most 2 lane attempts on the objective. On a second failure, stop and escalate.
- **One writer.** Same `runs/.lane.lock`, same journal, with the self-pid exclusion so a nested runtime
  cannot deadlock.
- **Timeout budget.** The 4-arg interface must default to a runtime timeout that fits inside harpp2's
  1800 s lane budget with room for the driver's own checks — **1500 s**, overridable by
  `DSH_TIMEOUT_SECONDS`. The 3-arg probe mode keeps its 180 s cap. A timeout must be reported as a
  timeout, never as a product failure.
- **Secrets.** Never printed, echoed, logged or persisted.
- **Bounded spend.** One objective, its gates, at most 2 attempts. No new accounts, no purchases.
- **Prose is not enforcement.** Every criterion below is a command with an exit code. Also: never judge a
  piped test run by `$?` — that reports the pipe's last command, not the test's.

## Files likely affected

- `tools/harpp2/dispatch-dsh.sh`
- `tools/dev/dsh-spike/`
- `tools/harpp2/runs/`
- `tools/harpp2/state/`
- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/`
- `tests/browser/star-swarm.spec.ts`
- `tests/star_swarm_visual_test.php`
- `tools/harpp2/stability.sh`
- `docs/testing/dsh-executor-parity.md`

## Acceptance criteria

- **AC1 — adapter speaks `harpp2`'s interface.** `bash -n tools/harpp2/dispatch-dsh.sh` exits `0`; the 4-arg
  form `<name> <model> <thinking> <brief>` runs a trivial brief to exit `0` and journals
  `"runtime":"dsh"`; the 3-arg probe mode still works (re-prove it once); the 4-arg form's runtime timeout
  is 1500 s by default and honours `DSH_TIMEOUT_SECONDS`, shown by the value actually used in its log or
  journal event.
- **AC2 — the instrument is settled first.** Run the exact command, **unpiped**, and record its real exit
  code, attempt count and elapsed time:

  ```sh
  bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10
  ```

  If it cannot complete (limiter, missing browser, unreachable host), record that verbatim as the finding
  and **STOP** — do not weaken it, do not raise the limiter, do not reinterpret it as a `dsh` failure.
- **AC3 — the parity run.** Execute, to completion:

  ```sh
  HARPP2_DISPATCH="$PWD/tools/harpp2/dispatch-dsh.sh" php tools/harpp2/harpp2.php run --objective=tools/harpp2/objectives/star-swarm-deterministic-probes.md
  ```

  The driver's journal must record the dispatch, the lane name, and the verdict.
- **AC4 — a verdict exists and is specific.** `tools/harpp2/state/star-swarm-deterministic-probes.json` is
  `verified`, **or** carries a `reason` that names a specific, evidenced condition. "The model gave up" is
  not a reason. Either outcome satisfies this criterion.
- **AC5 — authority untouched.** `git diff --stat` is empty for `tools/harpp2/harpp2.php`,
  `tools/harpp2/dispatch.sh`, `tools/harpp2/chain.sh`, `tools/harpp2/verify.php`,
  `tools/harpp2/assertions.php`, `tools/ai-autonomy.php`, `tools/ai-run.php`.
- **AC6 — honest report.** `docs/testing/dsh-executor-parity.md` states: the exact commands, the verdict,
  the number of attempts, what `dsh` did and did **not** do, which permission mode was used, every gate that
  could not run and why, and whether a `pi` control run on the same objective is warranted. Every claim
  carries its command and real output.
- **AC7 — the escalation path is not bypassed.** If the run escalates, `tools/harpp2/escalations/` gains the
  record and the report names the exact next action. If it verifies, say what that does and does not prove
  about `dsh`.

## Required tests

- `bash -n tools/harpp2/dispatch-dsh.sh` (AC1) + one trivial 4-arg invocation and one 3-arg re-prove
- `bash tools/harpp2/stability.sh tests/browser/star-swarm.spec.ts 10` — unpiped (AC2)
- the parity run (AC3)
- `git diff --stat` over the seven protected paths (AC5)

## Risks

- **A false negative for `dsh`.** The likeliest outcome of AC2 is that the stability gate is structurally
  un-runnable here; attributing that to the runtime would be the exact false alarm this corpus keeps
  recording. Settle the instrument first.
- **Self-inflicted limiter contamination.** Repeated gate runs can trip the limiter and poison later
  evidence. Space the runs; never raise the limit.
- **`dsh` writing outside the objective's scope.** `harpp2`'s delta check is the designed backstop. If it
  does *not* catch an out-of-scope write, that is a harness finding of the first order — record it loudly.
- **Preview runtime with write access.** Contained by the target choice (sandboxed demo); no
  `modules/cms-akira/`, kernel, or authority path is in scope.
- **Long run.** Bounded at 2 attempts; a third is a defect, not persistence.
- **A self-authored gate.** The target's five gates pre-exist, which is why it was chosen; if the lane edits
  a gate, AC2/AC6 must say so.

## Forbidden changes

- `kernel/`
- `src/`
- `modules/cms-akira/`
- `templates/modules/cms-akira/`
- `migrations/`
- `tools/harpp2/harpp2.php`
- `tools/harpp2/dispatch.sh`
- `tools/harpp2/chain.sh`
- `tools/harpp2/verify.php`
- `tools/harpp2/assertions.php`
- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tools/harpp-bridge/`
- `tests/_retired/`
- `.github/`
- `.ai/ai-autonomy-harness.contract.md`
- `package.json`
- `pnpm-lock.yaml`
- `composer.json`

## Completion record (partial) — chair-verified 2026-09-18

Lane: `openai-codex/gpt-5.6-sol`, thinking `medium`, exit 0. Escalation:
`tools/harpp2/escalations/dsh-executor-parity-20260918-133733.md`. Report:
`docs/testing/dsh-executor-parity.md`. Chair decision: **CD-80**.

| Criterion | Evidence (re-derived by the Chair) | Verdict |
|---|---|---|
| AC1 adapter | `bash -n` exit 0; 4-arg mode `PERMISSION_MODE=workspace-write`, `TIMEOUT_SECONDS=${DSH_TIMEOUT_SECONDS:-1500}`; 3-arg probe stays `read-only` / 180 s; trivial 4-arg run exit 0 in 6 s with `{"runtime":"dsh","mode":"harpp2"}` journalled; override proven at 120 s | PASS |
| AC2 instrument | `stability.sh tests/browser/star-swarm.spec.ts 10` → 10/10. **Independently re-derived** from the raw per-run logs: `/tmp/harpp2-stability/run-{1..10}.log`, all dated 13:35–13:37 today, each with a `passed (` line and **zero** `failed` lines. The suspected login-limiter blocker did **not** materialise | PASS |
| AC3 parity run | executed; driver journal `tools/harpp2/state/star-swarm-deterministic-probes.jsonl` for this run = `run_started,command×5,objective_verified` → **`dispatch_events=0`** | executed, no lane |
| AC4 verdict | state `status: verified`, reason `all objective acceptance gates passed` | PASS |
| AC5 authority | `git diff --stat` over the seven protected paths empty | PASS |
| AC6 report | `docs/testing/dsh-executor-parity.md`, evidence per claim | PASS |
| AC7 escalation | escalation written, exact next action named | PASS |

**What was actually learned (the honest version).** Two claims were retired and one was not answered:

1. *Retired:* "the ≥2.19. Node floor and the 522-package install make dsh unusable here" — it ran a confined
   `workspace-write` brief to completion in 6 s.
2. *Retired:* "`stability.sh … 10` cannot run here because of the login limiter" — 10/10, measured two ways.
3. *Not answered:* **whether `dsh` can carry real work to a `harpp2` verdict.** The fixed target turned out to
   be already green (its own objective work had landed on 2026-09-17; the remaining escalation was the assertion
   guard's false positive, which CD-78's repair cleared — so the item is now genuinely `verified`). The driver
   therefore verified it from **initial acceptance**, with no lane. That also retires yesterday's escalation:
   the L8 guard repair works.

**A second, unplanned positive:** a previously-escalated backlog item,
`star-swarm-deterministic-probes`, is now `verified` rather than `escalated` — the re-run confirmed it was never
broken, only mis-instrumented.

**The lane's refusal was correct and is worth keeping.** Offered the choice between manufacturing a red target
and reporting, it reported: *"forcing a dsh lane would require manufacturing a failure, weakening a gate,
substituting the fixed target, or editing the protected driver."* That is the corpus's rule applied against the
harness's own convenience.

**Open fork (CD-80, filed as an L4).** The only genuinely red real objective left is Akira **D.1**, whose gate is
red because `tests/shell_read_declarations_test.php` does not exist yet. But D.1 changes a **capability contract**
(`module.json` `capabilities.routes` + policy rows/exemptions) — an L4 by the policy, and exactly the authority
surface this contract excluded on purpose. `harpp2` bounds paths, not meaning, and D.1's acceptance test would be
**authored by the executor**. That choice belongs to the director.
