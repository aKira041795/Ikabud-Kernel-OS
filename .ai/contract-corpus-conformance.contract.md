# CONTRACT — Contract-corpus conformance: make it measurable, then retrofit just-in-time

status: DONE — implemented and verified by the Chair 2026-09-14 (lint reproduced independently; p2.1 retrofit corrected under CD-6)
repo: `/var/www/html/ikabudsix` — work in the current tree; do not create or switch branches

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```
authority: approved course of action 2026-09-14 — conformance first, retrofit only what is live

## Objective

The harness can only govern contracts it can read. Today it can read 13 of 60. Make corpus conformance
**measurable in one command**, then prove the retrofit procedure on one contract rather than bulk-editing
envelopes — because a wrong envelope authorises the wrong scope, and many "ready" contracts are probably
stale. **Do not wholesale-rewrite the corpus.**

This slice is deliberately read-only with respect to existing contracts except for one worked example.

## Verified facts — measured, do not re-derive

- `.ai/*.contract.md` total 60; `plan --json` parses **13**; **47 fail with exit 2**.
- Of the failures, **27 self-declare `status: READY_FOR_IMPLEMENTATION`**, **7 still carry the unfilled
  template placeholder `status: PASS|FAIL|PARTIAL|BLOCKED`**, and 2 are explicitly live
  (`QUEUED — dispatch after the re…`, `BLOCKING — PR #135 is RED`). 8 have no status line at all.
- The split is chronological: the newest P-series conforms (`p1.3`, `p2.2a`, `p2.4`, `p3.1`, `p3.1b`,
  `p3.2`, `p4.2a-r2`, `p6a`), while earlier ones do not (`p2.1`, `p2.2`, `p2.3`, `p4.0`, `p5.1`, `p5.2`,
  `p5.2fix`), so authoring is inconsistent inside one roadmap.
- **The reference block is unused**: `p1.3`, `p2.2a` and `p3.1b` contain no `harness` reference and no
  `L0–L4` vocabulary (counts of 0). They adopted the syntax but inherit no envelope.
- **Phantoms persist**: prose bullets inside `## Forbidden changes` are parsed as paths, so a prohibition
  silently does not bind. Counts: `playwright-rate-limit-cap` **5**, `akira-theme-scope` **2**,
  `ai-autonomy-remote-shape-repair` **1**, and 1 each across most of the P-series.
- Root cause of phantoms is the kernel parser (`DevelopmentTaskContract::parseScopeBullets` treats the
  first whitespace token of a forbidden bullet as a path). Fixing the parser is **out of scope** here and
  needs its own contract; this slice works around it authoring-side.

## Deliverables

### A1 — `tools/ai-contract-lint.php` (new, zero dependencies)

For every `.ai/*.contract.md`, report one line and a summary:

```
<name>  parse=ok|FAIL  harness_ref=yes|no  phantoms=N  status=<value>|none|PLACEHOLDER  class=live|stale|unknown
```

- `parse`: shell out to `php tools/ai-autonomy.php plan --json --contract=<file>` and use its exit code
  (do not re-implement the parser).
- `harness_ref`: whether the file contains a `harness:` block referencing the standing contract.
- `phantoms`: count and **list** forbidden-scope entries that are single bare words with no `/` or `.`
  (the prose-as-path signature). Parsing the contract yourself is acceptable only for this; prefer the
  `plan --json` output's `forbidden_scope`.
- `status`: the first `^status:` line; classify `PLACEHOLDER` when it still contains the template
  alternation `PASS|FAIL|PARTIAL|BLOCKED`, `none` when absent.
- `class`: `live` for `READY_FOR_IMPLEMENTATION` / `QUEUED` / `BLOCKING` / `READY_FOR_MEASUREMENT`;
  `stale` for `DONE` / `SHIPPED` / `CLOSED` / `COMPLETE` / `SUPERSEDED` / `ADOPTED`; `unknown` otherwise.
- Summary: totals per class, how many live contracts fail to parse, how many have phantoms, how many
  lack a status.
- `--json` for machine use; `--live-only` to restrict output.
- **Exit codes**: `0` when every `live` contract parses and has no phantoms; `3` when a live contract
  fails either test (so it can gate a run); `2` on usage error. A stale contract failing must **not**
  affect the exit code — history is allowed to be unparseable.

### A2 — Triage report (no contract edits)

Produce `.ai/contract-conformance-report.md`: the full lint table, plus a proposed classification of the
47 failures into (a) live-and-should-be-retrofitted, (b) stale-by-status, (c) unknown-status-needs-owner,
(d) placeholder-status-needs-owner. State the counts plainly and do not guess a contract's intent from its
filename. This report is the artefact that lets a later run retrofit deliberately instead of in bulk.

### A3 — One worked example, to prove the procedure

Take **`p2.1-navigation-surface.contract.md`** (legacy format, no status line) and retrofit it into a
harness-runnable envelope. Rules:

- **Preserve every existing line of prose.** You may add heading lines and you may add a new top-level
  section, but you may not rewrite, reorder or delete existing prose. The original must remain readable
  as history, e.g. keep its content beneath a clearly named section if a heading must be added.
- Introduce the seven canonical headings the kernel parser requires and non-empty:
  `## Objective`, `## Architectural constraints`, `## Files likely affected`, `## Acceptance criteria`,
  `## Required tests`, `## Risks`, `## Forbidden changes`.
- `Files likely affected` and `Forbidden changes` bullets must **start with a backticked path**; directories
  end in `/`. Prose prohibitions belong in `## Architectural constraints`.
- Insert the `harness:` reference block referencing `.ai/ai-autonomy-harness.contract.md`, with
  `autonomy: L0-L3 unattended; L4 defers to the director`, `decisions_dir: .ai/decisions`,
  `decision_transport: harpp`, and the evidence rule.
- If you cannot determine a section's content without inventing it, **stop and report** that the contract
  needs its author rather than fabricating scope. A fabricated envelope is worse than an unparseable one.
- Proof: `php tools/ai-contract-lint.php --live-only` shows it as `parse=ok`, and `git diff` on that file
  shows only added lines plus heading-only changes — paste the diff stat.

### A4 — Do not touch anything else

No edits to any other contract, to `tools/ai-autonomy.php`, or to the standing contract. In particular do
**not** edit `playwright-rate-limit-cap.contract.md` or any contract with a matching `*-run.log` newer
than the contract itself: those are in flight, and editing a live envelope is a contract change, which is
an owner decision, not a cleanup.

## Architectural constraints

- Read-only except A1's new file, A2's report, and A3's single contract.
- Never weaken a contract's obligations to make it parse. If a section cannot be filled honestly, report.
- Do not re-implement the kernel parser in the lint; delegate to `plan --json` and use only its output.
- Zero dependencies; no network; no `~/.config/harpp`; no DB; PHP 8.2 compatible.
- The lint must be safe to run repeatedly and must not write anything except with an explicit flag.

## Files likely affected

- `tools/ai-contract-lint.php` — new; the conformance lint (A1)
- `.ai/contract-conformance-report.md` — new; the triage report (A2)
- `.ai/p2.1-navigation-surface.contract.md` — the single worked retrofit (A3)

## Acceptance criteria

- `php tools/ai-contract-lint.php` runs and prints a line per contract plus a summary; exit `0` or `3`
  per the stated rule and nothing else.
- The lint's `parse` column agrees with `plan --json` for all 60 files (spot-check at least 10, including
  the three known-good and three known-bad).
- The report states the live / stale / unknown / placeholder counts and lists the phantom entries by name.
- The retired example is parseable, references the harness, has zero phantoms, and its prose is
  demonstrably preserved (diff shows no rewrites).
- Nothing else in `.ai/` changed — prove it with `git status --short .ai/`.

## Required tests

- `php tools/ai-contract-lint.php` — real output and exit code.
- `php tools/ai-contract-lint.php --json` — valid JSON (pipe through `php -r 'json_decode…'`).
- `php tools/ai-autonomy.php plan --json --contract=.ai/p2.1-navigation-surface.contract.md` — exit `0`,
  with a non-empty allowed scope and **zero** phantom forbidden entries.
- `git diff --stat .ai/p2.1-navigation-surface.contract.md` and the added-vs-removed line counts.
- `php tests/ai_autonomy_test.php` — still exit `0` (proves nothing in the harness was disturbed).

## Risks

- A lint that re-implements parsing would drift from the driver; delegating to `plan` avoids it.
- Bulk retrofitting is the tempting shortcut and is explicitly out of scope: a fabricated envelope
  authorises fabricated scope.
- Exit-code design: a stale contract must not gate anything, or the harness becomes red forever on its
  own history.

## Forbidden changes

- `tools/ai-autonomy.php` — no edit; the driver is another slice's file.
- `tests/ai_autonomy_test.php` — no edit.
- `.github/instructions/ai-autonomy-escalation.instructions.md` — no edit.
- `.ai/ai-autonomy-harness.contract.md` — no edit.
- `kernel/` — no change; the parser stays as it is.
- `phpstan-baseline.neon` — no edit to the quality-gate baseline.
- `composer.json` — no new PHP dependency.
- `git add` — no staging, commit, push, branch creation or switching (a rule, not a path).
