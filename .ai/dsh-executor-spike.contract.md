# CONTRACT — dsh executor-runtime spike: a second dispatch backend behind harpp2's acceptance authority

status: DONE — implemented and dispatched to Sol 2026-09-18, then independently verified and tested by the Chair
repo: `/var/www/html/ikabudsix` — branch `main` (work in the tree)
owner: implementation agent (`openai-codex/gpt-5.6-sol`) via `tools/harpp2/dispatch.sh`
chair: this session · authority: product-owner directive 2026-09-18 — *"create the contract, assign to Sol
or Astra. verify and then test"*. `openai-codex/gpt-5.6-astra` does not exist for this account (probed
2026-09-18: `Codex error: The 'gpt-5.6-astra' model is not supported when using Codex with a ChatGPT
account.`), so **Sol is the directed substitute**, as the directive itself allows.

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

**Director-authorised L4, and its limits.** Installing the external `@deepseek-ai/dsh` runtime is a *new
runtime dependency* — an L4 in the autonomy vocabulary. The directive above authorises it **for this spike
only**, in the isolated form fixed in Architectural constraints (pinned version, installed under
`tools/dev/dsh-spike/home/`, never global, never in `package.json`, never on the product runtime path). Any
*other* L4 — schema, product path, credential change, publishing, scope widening — is not authorised here:
stop, record it, do not take it.

## Objective

Answer one question with evidence: **can DeepSeek Harness (`dsh`) serve as an alternative executor runtime
behind the dispatch interface this harness already owns, while `harpp2` keeps acceptance authority
unchanged?**

Deliver an additive dispatch backend and the evidence that it runs one bounded task correctly, with the
verdict still owned by `harpp2` — nothing more. **A negative answer, evidenced, is a PASS**: it retires a
candidate cheaply, which is the point of a spike.

## Architectural constraints

- **Additive only.** The spike adds a *second backend beside* `dispatch.sh`. `dispatch.sh`, `chain.sh`,
  `harpp2.php`, `verify.php`, `assertions.php`, `ai-autonomy.php` and `ai-run.php` are read-only references,
  not files to improve.
- **Not HARPP v3.** CD-78 stands. No new governance layer, framework, pillar, taxonomy, census, scoreboard
  or contract file beyond this one. This is an executor-runtime experiment.
- **Authority unchanged.** `dsh` gains no authority. The objective, the `$ ` acceptance commands, the scope
  delta and the verdict stay with `harpp2`. A model saying "done" remains a claim until evidence is
  re-derived (`tools/harpp2/harpp2.php:603-650`).
- **Isolated installation.** Pin `@deepseek-ai/dsh@0.1.5-rc.2` and run it with
  `DSH_HOME="$repo/tools/dev/dsh-spike/home"`, inside `tools/dev/dsh-spike/`. No global `-g` install, no
  edit to the repo's `package.json`/lockfiles, no entry on the product runtime path.
- **Secrets stay secret.** Read a model credential from an existing store (`~/.pi/agent/auth.json`) without
  printing, echoing, logging or committing it; never print or persist `~/.config/harpp/config.json`. If no
  usable credential exists, record `BLOCKED` with the exact error and stop — do not create accounts, do not
  purchase, do not widen scope.
- **Bounded spend.** The only model calls are the equivalence probes, one short prompt each. `dsh` is a
  developer tool in this spike, never a product dependency.
- **One writer.** The new backend takes the *same* lock (`tools/harpp2/runs/.lane.lock`) and journals to the
  same `tools/harpp2/runs/journal.jsonl`, excluding its own pid the way `dispatch.sh` does, so at most one
  lane ever writes this tree and a nested runtime cannot deadlock on the lock.
- **Prose is not enforcement.** Every acceptance criterion below is a command with an exit code. If a
  criterion cannot pass as written, repair it against the artifact and say why — never weaken it to obtain a
  green.
- **Honesty.** Record failures as well as passes, in the same report.

## Files likely affected

- `tools/harpp2/dispatch-dsh.sh`
- `tools/dev/dsh-spike/`
- `tools/harpp2/runs/`
- `docs/testing/dsh-executor-spike.md`

## Acceptance criteria

- **AC1 — contract lint.** `php tools/ai-contract-lint.php --contract=.ai/dsh-executor-spike.contract.md`
  exits `0` with this contract parsed and phantom-free.
- **AC2 — backend is real.** `bash -n tools/harpp2/dispatch-dsh.sh` exits `0`, and the file is executable.
- **AC3 — pinned runtime recorded.** `tools/harpp2/runs/dsh-spike.install.json` records the resolved `dsh`
  version and the exact install command; it contains `0.1.5-rc.2`.
- **AC4 — runtime equivalence (the real test).** One bounded task — *print the SHA-256 of
  `tools/harpp2/CONSTITUTION.md`* — runs once through `dsh` and once through the existing `pi` runtime,
  writing `tools/harpp2/runs/dsh-spike.dsh.answer.md` and `tools/harpp2/runs/dsh-spike.pi.answer.md`. Both
  files must contain the hash computed here, independently of either runtime:

  ```sh
  H=$(sha256sum tools/harpp2/CONSTITUTION.md | cut -d' ' -f1)
  grep -qF "$H" tools/harpp2/runs/dsh-spike.dsh.answer.md
  grep -qF "$H" tools/harpp2/runs/dsh-spike.pi.answer.md
  ```
- **AC5 — provenance.** `tools/harpp2/runs/journal.jsonl` gains an event for the `dsh` dispatch carrying
  `"runtime":"dsh"`, and the `pi` control carries no such key.
- **AC6 — authority untouched.** `git diff --stat` shows no change under `tools/harpp2/dispatch.sh`,
  `tools/harpp2/chain.sh`, `tools/harpp2/harpp2.php`, `tools/ai-autonomy.php`, `tools/ai-run.php`.
- **AC7 — honest report.** `docs/testing/dsh-executor-spike.md` records: what worked, what failed, exact
  versions, the *source* of the credential (never its value), the wording of the `dsh` profile used, and a
  one-paragraph chair recommendation on adopting `dsh` as a second executor runtime. Every claim carries the
  command that produced it and its real output.

## Required tests

- `php tools/ai-contract-lint.php --contract=.ai/dsh-executor-spike.contract.md` (AC1)
- `bash -n tools/harpp2/dispatch-dsh.sh` (AC2)
- the AC4 hash gate above, run as one shell command over both answer files (this is the spike's pass/fail)
- `git status --porcelain` and `git diff --stat` (AC6) — read-only; no staging

## Risks

- **Preview churn.** `dsh` is `0.1.5-rc.2`; its README promises breaking changes. The pin is the mitigation;
  nothing in this spike may reach the product runtime path.
- **A false equivalence.** A hash task proves the *plumbing*, not that `dsh` reasons as well as `pi`. State
  that limit in the report; do not overclaim from one probe.
- **Credential exposure.** The credential is read from an existing store; any echo of it in a log is a defect
  to be fixed before the run is called done.
- **Verdict by assertion.** If an answer file is written by hand rather than produced by the runtime, the
  spike proves nothing — the gate must fail when the file is absent, stale or wrong.
- **Nested writer.** Running a runtime inside `dispatch.sh` can deadlock on the lane lock if the backend does
  not exclude its own pid; copy that exclusion rather than rediscovering it.
- **Install cost.** A first `npx` fetch may take minutes and need the registry; bound it with a timeout and
  record the elapsed time rather than hanging the lane.

## Forbidden changes

- `kernel/`
- `src/`
- `modules/`
- `templates/`
- `public/`
- `migrations/`
- `tests/`
- `tools/harpp2/dispatch.sh`
- `tools/harpp2/chain.sh`
- `tools/harpp2/harpp2.php`
- `tools/harpp2/verify.php`
- `tools/harpp2/assertions.php`
- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tools/harpp-bridge/`
- `.github/`
- `.ai/ai-autonomy-harness.contract.md`
- `package.json`
- `pnpm-lock.yaml`
- `composer.json`

## Completion record

**Status: PASS** (bounded plumbing equivalence; no claim about reasoning parity). Dispatch: `openai-codex/gpt-5.6-sol`, thinking `medium`, log `tools/harpp2/runs/dsh-executor-spike.log`, exit 0.

Chair-verified acceptance, re-derived rather than taken from the worker's report:

| Criterion | Evidence | Verdict |
|---|---|---|
| AC1 contract lint | `parse=ok harness_ref=yes phantoms=0`, exit 0 | PASS |
| AC2 backend | `dispatch-dsh.sh` 4430 bytes, executable, `bash -n` exit 0 | PASS |
| AC3 pin | `dsh-spike.install.json`: resolved `0.1.5-rc.2`, isolated `--prefix home --no-save`, 238 s | PASS |
| AC4 equivalence | independent `sha256sum tools/harpp2/CONSTITUTION.md` = `483de0f3…d700b0`; both answer files contain exactly that value | PASS |
| AC5 provenance | 2 journal events carry `"runtime":"dsh"`; the `pi` control carries the key nowhere | PASS |
| AC6 authority | `git diff --stat` over `dispatch.sh`, `chain.sh`, `harpp2.php`, `ai-autonomy.php`, `ai-run.php` empty | PASS |
| AC7 report | `docs/testing/dsh-executor-spike.md`, evidence per claim, failures recorded | PASS |
| Chair's own test | fresh prompt on a different file (`verify.php`): backend exit 0, answer `cdaada9c…bc17c` = independent hash, 7 s, lock released | PASS |

**Scope attribution.** The four `M` paths in `git status` (`.ai/harpp2-judgement.md`, `modules/cms-akira/cms-akira-theme/handlers.php`, `tests/browser/akira-theme-editor.spec.ts`, `tools/harpp2/projects/akira-theme-editor.json`) are pre-existing 2026-09-17 baseline work, confirmed by mtime; the spike added only untracked files inside its allowed scope.

**Failures recorded, not hidden.** Install-time Node `v22.11.0` is below transitive requirements (`commander@15`, `undici@8.10.2`, `@earendil-works/pi-ai@0.85.1` all want ≥22.12/≥22.19); the stock launcher under that Node exits 0 with **no output at all** — a silent-success shape, and the backend now refuses to run below Node 22.19 rather than trusting the exit code. The pin is `-rc.2` and the README promises breaking changes.

**Not proven by this slice:** reasoning/reliability/security/cost parity with `pi`; only that the dispatch plumbing works end-to-end and that `harpp2` keeps the verdict. The pin, the 522-package install and the ≥22.19 requirement are the costs of retaining the backend.

**Chair decision:** CD-79 (`.ai/chair-decisions.md`) — retained as an experimental second executor backend; `harpp2` keeps objectives, scope, evidence and verdicts.
