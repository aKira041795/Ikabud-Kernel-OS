# SLICE — harpp-gen4 S5: the first real run — remove marker-trust from the bridge's own loops

project: harpp-gen4 · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "harpp-gen4-s5-bridge-gate", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

stage-note: **this is the first slice to modify the subject (`tools/harpp-bridge/`), and the first real
project work dispatched by the loop rather than by hand.** It is the Gen 4 proof.
authority: owner directive 2026-09-14 — HARPP is the project (CD-16); prove the concept *stable* and *provable*.
directive: **you hold the decision.** Decide, record, continue (CD-8).

## Objective

The bridge's own loops decide whether a stage succeeded by looking for a **string the executor printed about
itself** — `marker: "SOL_IMPL status=PASS"`. That is the exact anti-pattern the harness removed from itself in
S2+S3, and it survives in the subject. Make the **evidence gate authoritative** and the marker informational.

## Verified facts — measured, do not re-derive

1. `tools/harpp-bridge/workflows/governed-loop.json` defines stages with
   `{"name", "model", "prompt_file", "marker", "verify", "timeout"}` — e.g.
   `marker: "SOL_ARCH status=PASS"` with a separate `verify: "test -s ARCHITECTURE.md && ..."`.
   **The marker is the self-report; the `verify` command is the real evidence — and today the marker is what
   the stage is keyed on.**
2. `roadmap-slices.json` carries stale model identifiers (`openai-codex/gpt-5.4`). Per recorded history that
   model was rejected by the account, and DeepSeek v4 Pro was discontinued 2026-09-14. Stale ids in a workflow
   manifest are a latent failure.
3. The bridge has its own tests, including `tests/test_harpp_wake.py` (~170 KB) and a `harpp self-test`.
4. **Absolute constraint from the policy:** *no test may create a live decision on the host.* Every test
   invocation sets `HARPP_NOTIFY=0` **and** puts a **stubbed `harpp` first on `PATH`** with a sandbox
   `HARPP_CONFIG`. The bridge's own docstring records that earlier test runs created live decisions — so this
   is a known, real hazard, not a theoretical one.
5. `tools/harpp-bridge/` was copied in-tree as-is (CD-16, 39 files). The **service** remains external and is
   reached through `PATH`; only the bridge code may change.
6. `.ai/runs/` and `tools/ai-project.php metrics` now exist (S4) for the run's metric table.

## Deliverables

### D1 — The evidence gate is authoritative

- A stage passes **only** when its `verify` command exits `0` **and** the stage's produced claim is
  `RE_DERIVED`. The `marker` string is retained for human logs but **must not gate anything**.
- Where a stage today has a `marker` but no `verify`, supply a real `verify` or mark the stage
  `evidence: none` explicitly — a stage with no evidence must be **visibly** unevidenced, never silently
  passing.
- Keep the change additive to the manifest format: existing keys keep their meaning for humans; document any
  new key. Do not silently reinterpret an existing key.

### D2 — No stale model identifiers

The workflow manifests must reference only models that exist and are permitted today. Report every identifier
you change and every one you leave, with a reason. Do not invent identifiers — verify them against
`.pi/agent/settings.json` or the recorded policy before writing.

### D3 — A test that proves marker-only does not pass

Add or extend a bridge test so that a stage whose executor prints `status=PASS` **without** a passing `verify`
and without re-derived claims does **not** pass, and the failure is visible. This is the non-vacuity anchor:
it must fail against the pre-change behaviour.

### D4 — Prove no live decision was created

Any test invocation must show, in its own output or in your transcript: `HARPP_NOTIFY=0` in the environment,
the **stubbed** `harpp` resolved first on `PATH` (show which binary ran), and a sandbox `HARPP_CONFIG`. Then
demonstrate that **no live decision was created** — e.g. the decision count on the host is unchanged, or the
stub's own log records the call. A test run that could reach the real service has not been proven safe.

### D5 — The run's metric table

After the change, produce the project's metric table with `tools/ai-project.php metrics --project=harpp-gen4`.
This run is itself a data point.

## Architectural constraints

- **NEVER create a live decision on the host.** No invocation of the real `harpp` service during
  verification. If you cannot stub it, stop and report rather than running it.
- **Do not edit `~/.config/harpp`** — it is chair-owned user configuration.
- **Do not touch the service.** Only `tools/harpp-bridge/` may change. The protocol stays as it is.
- Do not weaken any existing bridge test. If a test currently depends on marker behaviour, changing it must be
  justified in the D1 terms above and disclosed explicitly.
- Pure/isolated tests only: **do not run `scripts/run-tests.php`, `composer test`, or Playwright.** Do not
  bootstrap the CMS app or touch its web cache.
- Existing harness suites must stay green: `ai_project_test` 6/6, `ai_loop_test` 8/8, `ai_run_test` 31/31,
  `ai_autonomy_test` 46/46, zero skips.
- PHP 8.2 / Python 3 compatible as applicable; PHPStan clean for any PHP file you touch.
- Do not commit, stage or push.

## Files likely affected

- `tools/harpp-bridge/workflows/*.json` — the manifests
- `tools/harpp-bridge/harpp_wake.py` — the runner's stage gate (locate it; do not guess)
- `tools/harpp-bridge/tests/` — the marker-only test
- `tools/harpp-bridge/README.md` — the gate's semantics
- `.ai/projects/harpp-gen4/metrics.json` — tool output

## Acceptance criteria

1. A marker-only stage **does not pass**, demonstrated with output — and the test that asserts it **fails**
   against the pre-change behaviour. Paste that falsification.
2. Every workflow manifest's stage gate is evidence-based; no stage passes on a marker. Show the manifests.
3. `grep -rn "gpt-5.4" tools/harpp-bridge/workflows/` returns nothing, and every model identifier used is
   verified to exist today — state how you verified each.
4. The bridge's tests run with `HARPP_NOTIFY=0`, a stubbed `harpp` first on `PATH`, and a sandbox
   `HARPP_CONFIG`; paste the invocation and the result.
5. **No live decision created on the host** — the evidence for this is explicit, not assumed.
6. The metric table for this run is produced by S4's tool and pasted.
7. Harness suites still green with zero skips; if you changed any bridge test, say which and why.
8. Any product finding you could not fix is recorded (for example: a stage that cannot be evidenced must be
   marked `evidence: none`, not left ambiguous).

## Required tests

- The marker-only falsification (acceptance 1), before and after.
- The bridge test invocation with the sandboxed environment, and its counts.
- `grep` proofs for stale identifiers.
- The five harness suites with counts and zero skips.
- The metric table.
- `php -l` on any touched PHP; PHPStan under `-c phpstan.neon` for them.

## Risks

- **The live-decision hazard is the sharpest risk in this slice.** Treat the real service as loaded.
- `harpp_wake.py` is ~231 KB: locate the gate by reading, do not assume a line number. Report `file:line` for
  every behavioural change.
- Changing a gate can make previously-passing stages fail. That is the *point* (they were passing on a
  self-report), but say so plainly rather than hiding it behind a passing suite.
- Do not widen this slice into a general bridge refactor. The gate and the stale identifiers only.

## Forbidden changes

- `.github/workflows/`
- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tools/ai-loop.php`
- `tools/ai-project.php`
- `tests/ai_project_test.php`
- `tests/ai_loop_test.php`
- `tests/ai_run_test.php`
- `tests/ai_autonomy_test.php`
- `.ai/runs/`
- `kernel/`
- `modules/`
- `src/`
- `phpstan.neon`
- `phpstan-baseline.neon`
- `composer.json`
- `package.json`
