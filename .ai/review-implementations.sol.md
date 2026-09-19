# Independent implementation review

Date: 2026-09-14  
Scope: harness implementations and uncommitted `tools/harpp-bridge/` changes  
Method: report-only review; no implementation files were edited.

## Executive verdicts

- **Area A — UNSOUND.** The ordinary evidence path is fail-closed, but the verifier trust surface is contract-authorisable, the loop never enforces the contract against the resulting tree, malformed ledger records fail open in `commit-check`, and project completion trusts forgeable/stale JSON evidence.
- **Area B — SOUND_WITH_FINDINGS.** B1 is demonstrated and `evidence: "required"` is enforced, not decorative. The changed Python test module passes 207/207 and all six workflow manifests validate. A vacuous verifier such as `true` is nevertheless accepted as authoritative evidence, and routine test invocations have undocumented live-service call paths.
- **B4 commit verdict: SAFE TO COMMIT the reviewed bridge diff**, based on the passing scoped Python suite, direct B1 probe, source trace, and manifest validation below. This does not make `verify` semantically non-vacuous; that is the follow-up finding B-F1. The live-test provenance in B3 should also be accepted/cleaned up deliberately.

## Area A — harness implementations

### A-F1 — A contract can authorise modification of the verifier trust surface

- **Severity:** critical
- **File:** `tools/ai-autonomy.php:554-570`, `tools/ai-autonomy.php:630-675`
- **What is wrong:** `tools/ai-run.php` is absent from every absolute matcher. Once a contract lists it in `allowed_scope`, `check` returns `RECORD` at L2 even for the explicit action “widen the command allowlist.” The verifier's unreachability is therefore prose, not an invariant. An executor can be authorised to change the code that decides which commands execute and which claims become `RE_DERIVED`.
- **Evidence — required A1 probe:** `/tmp/ai-trust-surface-probe.md` included exactly `tools/ai-run.php` under `Files likely affected`.

```text
$ php tools/ai-autonomy.php plan --json --contract=/tmp/ai-trust-surface-probe.md
{"envelope":{"objective":"Probe whether the verifier trust surface can be placed in scope.","contract_revision":"c847cd5c3c12ce82","allowed_scope":[{"path":"tools/ai-run.php","kind":"file"}],"forbidden_scope":[{"path":"kernel","kind":"directory"}],"forbidden_rules":[]}, ... "warnings":[] ...}
PLAN_EXIT=0

$ php tools/ai-autonomy.php check "widen the command allowlist" --contract=/tmp/ai-trust-surface-probe.md --path=tools/ai-run.php
VERDICT: RECORD
level: L2
action: widen the command allowlist
paths:
  - tools/ai-run.php
reasons:
CHECK_EXIT=0
```

### A-F2 — the loop does not enforce scope or trust-surface immutability against the post-dispatch tree

- **Severity:** major
- **File:** `tools/ai-loop.php:198-279`
- **What is wrong:** after dispatch, the loop calls `finish`, `claims`, `verify`, and the project `done` transition. It never calls `ai-autonomy.php check`, never computes changed paths, and never compares those paths with the contract. Thus evidence can pass while unrelated/out-of-scope files remain changed. Combined with A-F1, an authorised dispatch can change the verifier before the loop invokes it at line 249.
- **Why it matters:** the statement “did the change exceed allowed_scope or touch forbidden_scope” exists as policy, but is not an advancement gate. A passing claim is not evidence of contract conformance unless the report happens to declare an `ai-contract-lint` claim.
- **Evidence:** source command and relevant output:

```text
$ rg -n "commit-check|claims|verify|ai-autonomy|git diff|scope" tools/ai-loop.php
198:        $commit = loopRun([PHP_BINARY, $runTool, 'commit-check', ...]);
240:            $claims = loopRun([PHP_BINARY, $runTool, 'claims', ...]);
249:            $verify = loopRun([PHP_BINARY, $runTool, 'verify', ...]);
257:            if ($verify['code'] !== 0 || ... $statuses[0] !== 'RE_DERIVED') {
271:        $done = loopRun([... 'transition', ... '--state=done', ...]);
```

There is no post-dispatch autonomy/scope invocation in the complete loop body (`tools/ai-loop.php` is 295 lines).

### A-F3 — `commit-check` treats unreadable ledger records as eligible

- **Severity:** major
- **File:** `tools/ai-run.php:469-491`, `tools/ai-run.php:1150-1199`
- **What is wrong:** `loadAllRuns()` puts malformed records in `errors` and omits them from `runs`; `commandCommitCheck()` warns but bases eligibility only on the remaining runs. Corruption/tampering can therefore erase a blocking run from the gate without deleting its file.
- **Why it matters:** this is a direct fail-open path in a gate advertised as ledger-authoritative.
- **Evidence:**

```text
$ printf '{not-json\n' > /tmp/ai-review-malformed-runs/broken.json
$ php tools/ai-run.php commit-check --runs-dir=/tmp/ai-review-malformed-runs
COMMIT-CHECK — /tmp/ai-review-malformed-runs
  ELIGIBLE — no runs recorded; nothing can be mid-flight
WARN: unreadable run record /tmp/ai-review-malformed-runs/broken.json
EXIT=0
```

### A-F4 — project completion accepts unauthenticated, stale, or unrelated JSON as “independently re-derived” evidence

- **Severity:** major
- **File:** `tools/ai-project.php:248-266`, `tools/ai-project.php:269-318`
- **What is wrong:** `assertRunReDerived()` checks only `status=completed`, a non-empty `results` array, and each claim's string status. It does not check the record's `id`, contract revision, slice/contract identity, verification method, verifier, current Git revision, or current dirty-tree content. The test suite itself constructs `good.json` by hand with only `{"status":"completed","claim_verification":{"results":[{"status":"RE_DERIVED"}]}}`, and the tool accepts it as completion evidence.
- **Why it matters:** a caller able to write the selected `runs-dir` can bypass actual verification. Separately, a real verification can go stale after `ai-run` records only `rev` plus a boolean `dirty` (`tools/ai-run.php:1320-1327`); `done` never checks either against the current tree. A repair or concurrent edit can therefore change what was verified before completion is recorded.
- **Evidence:**

```text
$ php tests/ai_project_test.php
...
── Done requires independently re-derived claims ──
  ✅ 3. UNVERIFIED claims cannot mark a slice done
  ✅ 4. RE_DERIVED evidence marks done and removes only that slice obligations
...
11/11 passed
EXIT=0
```

The accepted “good” record is created at `tests/ai_project_test.php:71` and consumed at lines 75-78; it has no independent-execution metadata or binding.

### A2 probe summary

- **Advancement without evidence:** marker-only and empty-claim paths were not falsified; the loop rejects them at `tools/ai-loop.php:244-258`.
- **Wrong-command binding:** no demonstrated wrong-command binding was found in the reviewed parser. Declared commands open bounded blocks, headings/fences close them, and bare test-name derivation requires an existing pure test (`tools/ai-run.php:782-799`, `904-952`).
- **Gate bypasses actually found:** A-F1 through A-F4 above. In particular, scope is not a loop gate and completion evidence is not authenticated/fresh.

## Area B — Python bridge

### B1 — non-vacuity anchor: **DEMONSTRATED; marker-only fails**

A job whose output contains `SOL_IMPL status=PASS` but has no `verify` is classified `UNVERIFIED`, receives outcome `FAILED`, and cannot yield a matching stage result.

- **File:** `tools/harpp-bridge/harpp_wake.py:1621-1656`, `tools/harpp-bridge/harpp_wake.py:2395-2415`
- **Test:** `tools/harpp-bridge/tests/test_harpp_wake.py:501-518`
- **Evidence:**

```text
$ PYTHONDONTWRITEBYTECODE=1 HARPP_TESTING_MODE=1 python3 -m unittest -v \
  tools.harpp-bridge.tests.test_harpp_wake.HarppWakeTest.test_monitor_rejects_marker_only_without_rederived_verification \
  tools.harpp-bridge.tests.test_harpp_wake.WorkflowManifestValidationTest.test_marker_without_verify_must_be_visibly_unevidenced \
  tools.harpp-bridge.tests.test_harpp_wake.WorkflowManifestValidationTest.test_evidence_none_cannot_hide_a_configured_verify \
  tools.harpp-bridge.tests.test_harpp_wake.WorkflowManifestValidationTest.test_structured_stage_result_identity \
  tools.harpp-bridge.tests.test_harpp_wake.WorkflowManifestValidationTest.test_structured_result_identity_mismatch_rejected
...
Ran 5 tests in 0.017s
OK
EXIT=0
```

The marker-only test's monitor output included:

```text
job ... task='marker-only stage'
job ... reported to conversation 3 (FAILED)
```

### B2 — `evidence: "required"` is **ENFORCED, not decorative**

Trace:

1. `_normalize_stage()` defaults evidence to `required` when `verify` exists — `harpp_wake.py:1219-1229`.
2. Manifest validation reads `stage.get("evidence")`; invalid values fail, missing verification fails unless mode is `none`, and `none` plus a verifier fails — `harpp_wake.py:2326-2341`.
3. `_stage_result_matches()` reads the stage's evidence mode and refuses `none`, refuses absent `verify`, requires `claim_status == "RE_DERIVED"`, and requires `verify:PASSED` — `harpp_wake.py:2395-2415`.
4. `advance_workflows()` marks a stage/workflow failed when that matcher refuses the result — `harpp_wake.py:2728-2742`.

Therefore the newly added manifest key participates in both preflight and advancement. It is not merely carried in JSON.

### B-F1 — any exit-zero shell command is treated as re-derived evidence

- **Severity:** major
- **File:** `tools/harpp-bridge/harpp_wake.py:1578-1589`, `tools/harpp-bridge/harpp_wake.py:1635-1656`
- **What is wrong:** `_run_verify()` equates shell exit 0 with verification success, and `_report_job()` maps that directly to `RE_DERIVED`. There is no non-vacuity requirement on the command. The suite explicitly proves that `verify="true"` advances a job with a missing marker.
- **Why it matters:** B1 prevents marker-only advancement, but an accidentally or maliciously vacuous verifier restores the same false-confidence path. The manifest field enforces the presence/mode of verification, not that it checks the stage's acceptance criteria. Several shipped manifests use only `git diff --check`, which checks patch whitespace rather than task correctness.
- **Evidence:**

```text
$ PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -v \
  tools.harpp-bridge.tests.test_harpp_wake.HarppWakeTest.test_passing_verify_succeeds_when_informational_marker_is_missing
...
job ... task='verified stage'
job ... reported to conversation 3 (DONE)
ok
Ran 1 test in 0.008s
OK
EXIT=0
```

The fixture sets `marker="SOL_IMPL status=PASS", verify="true"` at `test_harpp_wake.py:485-499` and deliberately omits the marker.

### B3 — live-service provenance

**Invocations:**

- The five-test B1/B2 command above does **not** reach the service: the monitor's `send_message` is patched, and `HARPP_TESTING_MODE=1` suppresses notifications.
- `PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -v tools.harpp-bridge.tests.test_harpp_wake` can reach the live service under the host's normal notification-enabled configuration.
- `python3 tools/harpp-bridge/harpp self-test` can reach the same paths because `harpp:1063-1071` includes the entire `test_harpp_wake` module. (`test_harpp_bridge` itself sets `HARPP_DRY_RUN=1` in its client fixtures.)

A full-module API instrumentation probe (API replaced with a recorder, notifications otherwise enabled) ran 207 tests successfully and observed these unmocked calls:

- GET conversation context: tests at `test_harpp_wake.py:2958`, `2992`, `3010`, `3121`, `3155`, `3173` — conversations 911/913/914 and 811/813/814.
- POST decisions: `test_budget_exhaustion_blocks_never_loops` (`:2012`), `test_workflow_auto_repairs_review_then_succeeds` (`:997`), `test_workflow_blocks_when_all_models_exhausted` (`:1090`), and `test_workflow_stops_after_max_repairs` (`:1032`).
- POST messages: three each from `test_failed_agent_leaves_staged` (`:1654`) and `test_unverifiable_agent_output_is_retried` (`:1659`), plus one from `test_workflow_auto_repairs_review_then_succeeds` (`:997`).

Probe summary:

```text
{
  "successful": true,
  "testsRun": 207,
  "api_calls": [
    {"test":"test_advisor_failure_keeps_staged_and_never_codex","method":"GET","path":"/api/v1/harpp/bridge/conversations/913/context?limit=20"},
    ... 6 context GETs total ...,
    {"test":"test_budget_exhaustion_blocks_never_loops","method":"POST","path":"/api/v1/harpp/bridge/decisions"},
    ... 4 decision POSTs total ...,
    ... 7 message POSTs total ...
  ]
}
EXIT=0
```

These calls are **unintentional during a routine suite run** relative to the module's “no network” docstring (`test_harpp_wake.py:2`). CD-17 permits live decisions, so reachability itself is not reported as a prohibition. The provenance issue is that these particular tests do not consistently patch all client operations. Setting `HARPP_TESTING_MODE=1` avoids live calls but is not currently a valid blanket test invocation: it suppresses notifications before the tests' patched send functions and caused 10 failures plus 9 errors in the full module (`Ran 207 ... FAILED ... EXIT=1`).

### B4 — verification and commit verdict

**SAFE TO COMMIT.** Evidence:

```text
$ PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -v tools.harpp-bridge.tests.test_harpp_wake
...
Ran 207 tests in 18.215s
OK
EXIT=0
```

All workflow manifests shipped in this tree pass the actual validator:

```text
$ PYTHONDONTWRITEBYTECODE=1 python3 <manifest-validation-probe>
tools/harpp-bridge/workflows/governed-loop-flash.json: PASS
tools/harpp-bridge/workflows/governed-loop.json: PASS
tools/harpp-bridge/workflows/harpp-module-repair-loop.json: PASS
tools/harpp-bridge/workflows/roadmap-slices.json: PASS
tools/harpp-bridge/workflows/standalone-harpp-loop.json: PASS
tools/harpp-bridge/workflows/standalone-harpp-remediation-loop.json: PASS
EXIT=0
```

Nothing needed for the stated B1/B2 gate is missing. What remains is hardening, not missing proof of this change: reject/flag vacuous verification configurations (B-F1), and make routine tests hermetic while retaining explicit CD-17 live-decision tests with provenance.

## Final integrity evidence

The bridge diff stat was byte-for-byte identical in shape and counts before and after review:

```text
$ git diff --stat -- tools/harpp-bridge/
 tools/harpp-bridge/README.md                       | 12 ++-
 tools/harpp-bridge/harpp_wake.py                   | 61 ++++++++++------
 tools/harpp-bridge/tests/test_harpp_wake.py        | 85 +++++++++++++++++++---
 .../workflows/governed-loop-flash.json             |  4 +
 tools/harpp-bridge/workflows/governed-loop.json    |  4 +
 .../workflows/harpp-module-repair-loop.json         |  3 +
 .../workflows/roadmap-slices.json                   | 12 ++-
 .../workflows/stages/slice2-authority.md           |  2 +-
 .../workflows/stages/slice3-escalation.md          |  2 +-
 .../workflows/stages/slice4-decision-recorder.md   |  4 +-
 .../workflows/stages/slice5-summaries.md           |  2 +-
 .../workflows/standalone-harpp-loop.json            |  3 +
 .../standalone-harpp-remediation-loop.json         |  3 +
 13 files changed, 153 insertions(+), 44 deletions(-)
EXIT=0
```

This exact output occurred both **BEFORE** and **AFTER** the review.

Final status:

```text
$ git status --porcelain
 M .ai/chair-decisions.md
 M tools/harpp-bridge/README.md
 M tools/harpp-bridge/harpp_wake.py
 M tools/harpp-bridge/tests/test_harpp_wake.py
 M tools/harpp-bridge/workflows/governed-loop-flash.json
 M tools/harpp-bridge/workflows/governed-loop.json
 M tools/harpp-bridge/workflows/harpp-module-repair-loop.json
 M tools/harpp-bridge/workflows/roadmap-slices.json
 M tools/harpp-bridge/workflows/stages/slice2-authority.md
 M tools/harpp-bridge/workflows/stages/slice3-escalation.md
 M tools/harpp-bridge/workflows/stages/slice4-decision-recorder.md
 M tools/harpp-bridge/workflows/stages/slice5-summaries.md
 M tools/harpp-bridge/workflows/standalone-harpp-loop.json
 M tools/harpp-bridge/workflows/standalone-harpp-remediation-loop.json
?? .ai/_p13_closure_probe.php
?? .ai/_p13_phase1_probe.php
?? .ai/_p13_state_probe.php
?? .ai/plan-review/
?? .ai/research/
?? .ai/review-implementations.sol-run.log
?? .ai/review-implementations.sol.contract.md
?? .ai/review-implementations.sol.md
?? .ai/standing-authorization-and-decisions.md
EXIT=0
```

Relative to the initial status, the only new repository path is this report, `.ai/review-implementations.sol.md`. The pre-existing uncommitted bridge paths are unchanged in diff statistics; Python was run with `PYTHONDONTWRITEBYTECODE=1`.
