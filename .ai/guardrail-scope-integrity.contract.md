# SLICE A — the harness binds consequence: fail-closed scope, a director route, and a loop that checks

project: harness-guardrail · status: DIRECTOR_AUTHORISED (§see below) · revision: 1
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "high", "--model", "openai-codex/gpt-5.6-sol", "--name", "guardrail-scope-integrity", "<CONTRACT>"]

## THIS CONTRACT WILL BE REFUSED BY `plan`. THAT IS EXPECTED — READ FIRST.

**Authority: owner directive 2026-09-14, verbatim:** *"close it then, use sol. then we can test with harpp"*

This contract names the verifier's **trust surface** in `Files likely affected`, so
`php tools/ai-autonomy.php plan --contract=<this file>` **must exit 2** with *"contract names the verifier trust
surface in its scope and is refused … the trust surface is not contract-authorisable (owner directive
2026-09-14)"*. **That refusal is rule 1 working correctly and is itself part of the evidence to collect.**
The work proceeds on the director directive above, which is the external act CD-21 rule 4 requires; it is
recorded in `.ai/chair-decisions.md` as **CD-28**, before this dispatch (CD-22's razor).

This is the **second and final bootstrap** (CD-28). It is final because **D2 creates the mechanism that makes it
unnecessary in future**. After this slice lands, no contract, agent or Chair may change the trust surface by
instruction alone: it will require `trust-surface amend --director-decision=<ref>`, which records provenance.

**Do not remove or weaken rule 1 to make this contract plannable.** The refusal is the feature.

## Objective

Make the harness bind **consequence** — what the work actually touches — instead of only the truth of its
claims. Three defects, all verified by the Chair (CD-26/27), are closed here:
a contract scope that *covers* the trust surface is accepted at plan (rule 3 unbuilt); the loop never compares
changed paths against the envelope (A-F2, demonstrated live on the Chair's own dispatch); and there is no
sanctioned way for the director to change the verifier (rule 4 has no route).

## Architectural constraints

- **Deterministic first.** Reuse `l4Taxonomy()` in `tools/ai-autonomy.php` — the single prohibition list. Do not
  create a second list.
- **Fail closed on ambiguity.** A path that cannot be resolved, a glob whose expansion is unknown, a symlink, or
  a directory that *contains* a trust-surface file: all are treated as touching it. Ambiguity resolves toward
  protection, never toward the requester's preferred reading.
- **The route performs no change.** It validates, records and re-hashes. It must never edit a trust-surface file
  itself — a tool that both authorises and performs would reintroduce exactly the problem it exists to solve.
- **A-F2 must compare against the dispatch-time baseline, not against HEAD.** This repository has concurrent
  uncommitted work from another lane. Attributing pre-existing modifications to a run would produce false blocks
  and make the gate untrustworthy. Capture the changed-file set at `start`; attribute only the **delta** at
  `finish`.
- PHP 8.3-compatible (`/usr/bin/php8.3`). No new runtime dependency.
- Exit codes unchanged: `0` ok, `2` malformed/refused, `3` escalate, `4` filed-but-undelivered.
- **Do not weaken, skip, delete or relax any existing test or gate.** Five tests in `tests/ai_project_test.php`
  and the guardrail assertions in `tests/ai_contract_lint_test.php` currently protect this work — they must still
  pass, unmodified in substance.

## Files likely affected

- `tools/ai-autonomy.php`
- `tools/ai-run.php`
- `tools/ai-loop.php`
- `tools/ai-project.php`
- `tests/ai_autonomy_test.php`
- `tests/ai_run_test.php`
- `tests/ai_loop_test.php`

## Deliverables

### D1 — Rule 3: a scope that COVERS the trust surface is refused (CD-21 rule 3)

Today `plan` accepts a contract with `tools/` in `Files likely affected` (verified: exit 0). Close it:
- Refuse (`plan` → exit **2**) any contract whose scope **covers** a trust-surface path — by directory prefix, by
  glob match, or by an unresolvable entry treated as covering. Name the covering entry and the path it reaches.
- Escalate (`check` → **3**) for the same covering cases when supplied as `--path`.
- **Proof required:** `tools/` refused; `tools/ai-*.php` refused; `**/*.php` refused; `docs/` **accepted**
  (positive control, so this is not a blanket tightening). Paste real exit codes for all four.

### D2 — The director route: the missing half of rule 4

```
php tools/ai-autonomy.php trust-surface amend --reason="…" --director-decision=<ref>
```
- **Refuses** (exit `3`, message pointing at how to obtain one) when `--director-decision` is absent, empty, or
  does not resolve to a recorded decision — checked against `.ai/decisions/` and the `## CD-<n>` headings in
  `.ai/chair-decisions.md`.
- On success: **records** the amendment (reason, decision ref, acting context, timestamp) in
  `.ai/trust-surface-amendments.json`, recomputes and stores the trust-surface hash so `commit-check`'s hash gate
  (D2 of the previous slice) stays consistent rather than blocking the director's own authorised change, and
  prints what it recorded.
- **It performs no file change.** Say so in the command's own output.
- **State the honest limit in a comment:** this cannot *prove* the authorisation is genuine — no code can. What it
  guarantees is that **every amendment is visible, attributed and attributed to a named decision**, so the
  verifier can never be changed invisibly. Do not describe it as tamper-proof.
- **Proof required:** refused without a decision (exit 3); succeeded with a real recorded decision; the
  amendment visible in the amendment record afterwards. Paste real output.

### D3 — A-F2: scope conformance becomes an advancement gate

After dispatch and before `done`, the loop must determine what the run **actually changed** and refuse to
advance if any of it lies outside the envelope.
- Compute changed + untracked paths in the working tree (`git status --porcelain` / `git diff --name-only` as
  appropriate). **No test suites, no CMS bootstrap** — the APCu hazard stands.
- Compare each against the contract's `allowed_scope` / `forbidden_scope` using the **same matcher as `check`**,
  including D1's covering rules.
- Any path outside scope, or inside `forbidden_scope`, or touching the trust surface → **block the slice**
  (`blocked`, not `done`), record the offending paths in the run record and the loop event stream, and stop.
- **Baseline rule (see constraints):** capture the changed-file set in the run record at `start`; compare the
  **delta** at `finish`. A file already modified at dispatch must not be attributed to the run.
- **Proof required:** (a) reproduce the CD-26 breach — a run that writes an out-of-scope file is **blocked**, not
  advanced; (b) **positive control** — a run whose changes are all in scope still **advances**; (c) a file
  modified *before* dispatch does **not** block. All three with real exit codes and the loop's event output.

### D4 — Non-vacuity

Every new assertion must **fail when its fix is reverted**. For each of D1/D2/D3, state which assertion fails and
how you reverted it to check.

## Acceptance criteria

- **AC1** — D1: `tools/`, `tools/ai-*.php`, `**/*.php` refused (exit 2); `docs/` accepted (exit 0).
- **AC2** — D2: refused without a director decision (exit 3); succeeds with one; the record is visible.
- **AC3** — D3: out-of-scope write blocks the slice; in-scope run advances; pre-existing modification does not
  block.
- **AC4** — `php tests/ai_autonomy_test.php`, `tests/ai_run_test.php`, `tests/ai_project_test.php`,
  `tests/ai_contract_lint_test.php` all pass with **zero skips**, and the guardrail's existing refusals
  (exact trust-surface path → exit 2; widen-allowlist → ESCALATE/L4/exit 3; benign in-scope path → RECORD/0)
  still hold **exactly as before**.
- **AC5** — rule 1 is **not** weakened anywhere: a contract naming an exact trust-surface path is still refused.

## Required tests

Pure PHP for the changed tools. **Do not run `scripts/run-tests.php`, `composer test`, or anything that
bootstraps the CMS application** — a full run poisons the APCu module-scan cache and 503s the live tenant.

```
php tests/ai_autonomy_test.php
php tests/ai_run_test.php
php tests/ai_project_test.php
php tests/ai_contract_lint_test.php
```

## Report format — required

Every claim declares its command so the verifier re-derives rather than trusts:

```
CLAIM: <short assertion>
COMMAND: <exact command, copy-pasteable>
OBSERVED: <verbatim output, including the exit code>
```

Report path: `.ai/guardrail-scope-integrity.report.txt`.
**Include the `plan` refusal of this very contract** as the first claim — it is evidence that rule 1 still holds.

## Risks

- **False blocks from the concurrent lane.** If D3 compares against HEAD instead of the dispatch baseline, the
  other lane's uncommitted work will block unrelated slices. The baseline rule exists for this; test it (AC3c).
- **A route that becomes the bypass.** If `trust-surface amend` can be satisfied by any string, rule 4 is
  replaced by a formality. It must resolve the reference against the real decision record and refuse otherwise.
- **Over-blocking.** `**/*.php` refused is correct for a *trust-surface-covering* scope but must not make
  ordinary contracts unplannable. AC1's `docs/` control and AC4 cover this.

## Forbidden changes

- `phpstan.neon`
- `phpstan-baseline.neon`
- `.github/workflows/`
- `scripts/`
- `modules/`
- `tools/harpp-bridge/`
