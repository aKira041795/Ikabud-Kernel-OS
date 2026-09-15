# SLICE — update the independent evaluation brief to 2026-09-15

project: harness-docs · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "brief-update", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: CD-41 (the measurement programme) and CD-48 (prohibitions get leeway; mechanisms get repaired). This
is a **documentation slice**: it changes one Markdown file and nothing else.

## Objective

Bring `docs/reviews/harness-independent-evaluation-brief.md` up to date. It was last revised **2026-09-14
evening** on tree `995553a`; there are now **14 commits** since, HEAD `baa02f1`. The brief is the artefact an
independent senior engineer reads first, so **a stale number in it is a defect**.

The document's own standard, which you must hold: *"Please treat every claim in §2 as false until you reproduce
it"*, and *"A reviewer who finds unlisted weaknesses here should discount this entire document."* Add to it in
that spirit — widen the limits, never narrow them.

## Measured facts — use these, do not invent numbers

Measured on tree `baa02f1`, 2026-09-15. Every value below is a real command's output. Where you update a
number, keep the old value beside it exactly as the document already does elsewhere.

**Test suites — all seven are now green:**

```
ai_autonomy_test               exit=0  78/78 passed      (was 59/59)
ai_run_test                    exit=0  79/79 passed      (was 67/67)
ai_project_test                exit=0  18/18 passed
ai_project_metrics_test        exit=0  16/16 passed      (was exit=1, 15/16 — see below)
ai_contract_lint_test          exit=0   3/3 passed
ai_loop_test                   exit=0  18/18 passed
ai_autonomy_glob_scope_test    exit=0   5/5 passed
```

**§3.17 is CLOSED.** The stale `runs_by_status` assertion was corrected; the suite is 16/16 with zero skips.
The correction added `'blocked' => 0` to the expectation, keeping the strict full-array `===` and naming one
more required key (6 → 7), so the assertion was **strengthened, not weakened**. Evidence: commit `02a4559`,
GEN4-R1 S1.

**Corpus lint:** `php tools/ai-contract-lint.php` → **exit 3**,
`SUMMARY total=85 live=46 stale=5 unknown=34 live_parse_failures=40 live_with_phantoms=3 with_phantoms=4
missing_status=17`. (The document currently records `total=78 live=43 stale=5 unknown=30
live_parse_failures=39`.)

**Decision and amendment counts:** `.ai/chair-decisions.md` now has **49** `## CD-` headings (CD-1…CD-49).
`.ai/trust-surface-amendments.json` holds **11** records (TSA-0001…TSA-0011); the last is **TSA-0011** under
director decision **CD-48**. The document says seven — five amendments have been added since.

**GEN4-R1 (`php tools/ai-project.php metrics --project=gen4-r1`) — the measurement programme:**

```
slices dispatched 2 (S1, S2)      slices completed 2
runs completed    9               runs blocked     2
claims RE_DERIVED 13              claims UNVERIFIED 14
claims CONTRADICTED 0             contract violations 2
chair decisions   49              chair decisions incorrect 8 (CE-01…CE-08)
```

## What to change

1. **The revision note and the header.** New revision line for 2026-09-15; commits under review become
   `baa02f1` (HEAD) with `995553a` as the previous tree; state that **14** commits landed between them. Keep the
   existing "Numbers taken from a named artefact rather than re-run are labelled `NOT RE-MEASURED`" rule and
   keep every old value beside its new one.
2. **§1.2 component table** — the test-suite row (seven suites, current counts), the decision-record row
   (CD-1…CD-49), the trust-surface row (TSA-0001…TSA-0011), and anything else the table now understates.
3. **C6** — the new suite table, and say plainly that the previously red suite is now green **and that the
   correction strengthened the assertion rather than weakening it**.
4. **C7** — the new lint summary.
5. **C11** — eleven amendments, and the decisions they cite.
6. **§3 limits** — keep all seventeen, do not renumber them, and mark status honestly:
   - **§3.17 → closed**, with the evidence above;
   - **§3.2, §3.11** — update the counts they quote (live contracts, and the GEN4-R1 sample under the current
     rules, which is now **2** slices, not one), and keep them **open**.
7. **Add new limits** discovered on 2026-09-15, as **§3.18 … §3.22**, in the document's existing voice (a
   heading, a bolded status, the evidence, and what it means for a reviewer). These are measured, not
   inferred:
   - **§3.18 — the harness's own report format was undocumented.** `tools/ai-run.php` has **no** handling of
     `CLAIM:` / `COMMAND:` / `OBSERVED:`. `parseCommandLine()` strips only a leading `>` or `$`, so a line
     labelled `COMMAND: php tests/x.php` classifies as nothing and **binds no claim**. Contract templates
     prescribed that shape, so executors wrote a format the extractor ignores; a report written that way
     extracts claims with `command_source: null` and `verify` returns `no_command_declared` for every one. The
     binding shapes are a `$`-prefixed command line with its output beneath, or a line carrying the command and
     its result together. Recorded as **CD-49**. *A report convention is a mechanism; an untested mechanism is
     a belief.*
   - **§3.19 — the allowlist can admit a command that cannot be verified.** `php ikabud
     workbench:governance --all --json` was added to the allowlist (TSA-0010) and binds as a claim, but
     `verify` returns **`nothing_to_compare`**: the verifier has no census observation, so a declared census can
     never re-derive. Being *allowed* is not the same as being *re-derivable*, and only the second makes
     evidence. The fix used was to assert the census inside a pure test, where the comparison target is the
     test's own pass/fail counts.
   - **§3.20 — the ledger's liveness model trusts a pid that may be a shell.** A run started from an
     interactive shell records that shell's pid, so a killed executor leaves a record reading `running` with no
     `finished_at`; `status` only reconciles a **dead** pid to `abandoned`. Measured twice on 2026-09-15.
   - **§3.21 — an `abandoned` run has no unblock route.** `--acknowledge-block` requires `blocked` **and**
     `scope_conformance.ok=false`, and only `failed`/`silent` runs are excused when a successor links to them.
     So a genuinely dead run can wedge commit eligibility permanently. Both this and §3.20 are **recorded, not
     repaired**.
   - **§3.22 — the ledger is blind to concurrent runs, and a late `finish` misattributes.** The dispatch-time
     baseline assumes one run per working tree. Two overlapping runs made a late `finish` absorb the other
     run's files into its delta (`delta=6` where the true delta was `3`), leaving that record's scope
     conformance unusable as evidence of what its own executor changed. The failure is silent: nothing
     compares the two baselines.
8. **Add a §7.6 dated update — 2026-09-15**, in the same style as §7.5. Cover, honestly:
   - the **freeze and the measurement programme** (CD-41): the apparatus was frozen *before* the first
     dispatch, deliberately, so a harness that changes after every failure can never fail the same way twice;
   - the **two authorised widenings**, both owner-decided: the command allowlist gained a bounded module-test
     path and the bounded census shape (TSA-0010, decision `gen4-r1-d1`), and **two over-broad prohibition
     mechanisms were repaired** (TSA-0011, **CD-48**, owner ruling verbatim: *"prohibition is fine but allow
     leeway. pure prohibition stifles the harness"*) — the `authority` matcher now tokenises a path instead of
     substring-matching it, so `authority` is no longer `auth`, and `isExistingTestPath()` decides from the
     **dispatch baseline** instead of a post-run `file_exists()`, so a run can finally create a test file.
     Neither repair inspects *what a diff does*; one fixes **how a path is read**, the other **when a question
     is asked**;
   - the **adversarial verification that found a real hole**: the tokenised matcher passed every assertion it
     was given and had still **silently lost coverage** for OAuth, OAuth2, authenticator, unauthorized and
     reauthentication paths; that was found and repaired on the fixed-cost lane, and is the concrete argument
     for spending judgement capacity on review rather than on transcription;
   - the **distribution so far** (2 slices, 9 completed runs, 2 blocked, 13 RE_DERIVED, 0 CONTRADICTED,
     14 UNVERIFIED, 8 recorded incorrect Chair decisions) and the honest reading of it: **every one of the
     blocked attempts was an authoring defect, none was the work and none was the apparatus**. Name them, since
     that is the point of the section: a criterion demanding a demonstration with no command for it; prose
     inside a scope section parsed as a scope entry; a timestamp written from memory rather than read from the
     clock; the inert report format (§3.18); and a census offered as a claim it could not verify (§3.19);
   - that **S2 completed through the harness's own gate** — 2/2 claims `RE_DERIVED`, `scope OK delta=0` — and
     that its evidence test is genuinely pure (no app bootstrap, no database) and states its own residual gap
     rather than hiding it: the **live tenant policy row was verified separately and belongs to Chair
     provenance, not to the run's claims**. The criterion was decomposed by provenance, not dropped.
9. **Appendix A** — refresh the paths and counts it quotes (CD-1…CD-49, TSA-0001…TSA-0011, the new test file).
10. **§5 questions and §6 verdict template** — leave them intact. If §3 has grown, ensure §5's numbering still
    reads correctly.

## Architectural constraints

- **Do not delete or soften a single existing limit.** The document grows; it does not shrink. If a limit is
  now closed, mark it closed **with the evidence** and leave the text in place.
- **Every number you write must come from a command you actually ran, or be labelled `NOT RE-MEASURED` with
  its provenance.** Do not carry a stale number forward unlabelled and do not round one into a claim.
- **Keep the document's existing voice and structure**: the C-claim sections, the `Status:` tags
  (open / partially closed / closed), the `NOT RE-MEASURED` convention, the old-value-beside-new-value rule.
- **This is a documentation slice.** Change no code, no test, no tool, and no other document.

## Files likely affected

- `docs/reviews/harness-independent-evaluation-brief.md`

## Acceptance criteria

1. The revision note and header name `baa02f1` and state the 14-commit gap from `995553a`.
2. Every suite count matches a re-run, and **§3.17 is marked closed with its evidence**.
3. C7 carries the new lint summary verbatim.
4. Eleven trust-surface amendments and 49 Chair decisions are recorded where the document quotes those counts.
5. New limits §3.18–§3.22 exist, each with measured evidence and a status, and **no existing limit was removed
   or weakened**.
6. §7.6 exists and covers every bullet in instruction 8, including the four named authoring defects and the
   provenance point about S2's policy row.
7. Say plainly whether this edit **weakens** any statement in the document. If it does, say so and stop.

## Required tests

```
$ php tools/ai-contract-lint.php
```

It must still exit `3` — a documentation change must not move the corpus metric. Report the summary line before
and after your edit. Also re-run, and report, the seven suites listed above, so every number you wrote into the
C6 table is one you measured.

## Report format — required

Write a **shell transcript** to the path the harness sets via `--report`, and nothing else: a `$`-prefixed
command line, its real output beneath it, then the next command.

**Do not write `CLAIM:`, `COMMAND:` or `OBSERVED:`.** Those labels are **inert** — the extractor has no marker
handling (`parseCommandLine()` strips only `>` and `$`), so a labelled report extracts claims with no command
and `verify` returns `no_command_declared` for every one. This is measured, recorded as CD-49, and it has cost
runs. Anything you want to explain goes in your reply, not in the report file.

Declare only keys the command's actual output prints. Paste real output; do not summarise it.

## Risks

- **A stale number is worse than a missing one**, because the brief is what a reviewer trusts. If you cannot
  measure something, label it `NOT RE-MEASURED` with its provenance rather than carrying it forward.
- **Narrowing §3 would move the document from honest to promotional.** The instruction is to grow it.
- **Misattributing a defect to the apparatus.** Where a defect was the author's (the report format, the
  timestamps, a criterion with no command), say so — the document's credibility rests on that distinction.

## Forbidden changes

- `tools/` — no tool change; this slice documents, it does not repair.
- `tests/` — no test change.
- `kernel/` — no engine change.
- `modules/` — no product change.
- `phpstan-baseline.neon` — never edited to make a gate pass.
- `.github/workflows/` — no CI definition change.
- `.ai/chair-decisions.md` — the decision record is not this slice's to edit.
