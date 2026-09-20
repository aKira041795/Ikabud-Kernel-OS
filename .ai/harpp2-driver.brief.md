# BRIEF — build the HARPP v2 execution core

Read `tools/harpp2/CONSTITUTION.md` first. It is the specification and it is short. Also read
`tools/harpp2/objectives/akira-cms.md` — that is the objective the core must be able to run.

## Deliverable

**One driver: `tools/harpp2/harpp2.php`.** A single PHP file, no framework, no new dependency, PHP 8.2-compatible.
It implements the constitution's loop and nothing else:

```
while objective_not_verified:
    action = determine_next_action()
    if safe_and_authorized(action):   execute(action); observe(action)
    elif another_safe_path_exists:    replan()
    else:                             escalate()
```

## Non-negotiable semantics — this is the whole point of v2

The v1 harness stalled on steps that had nothing to do with completing the project: measurements, rule checks,
censuses, contract refinements. The owner's verdict: *it has become baggage instead of a useful, intuitive, capable,
dependable, accurate, efficient tool.* The driver exists to make that class of stall **impossible**, not merely
discouraged. Build these in as behaviour, and prove each one:

1. **Every stop names one of the three conditions** — `authority`, `boundary`, `irreversibility` — or the driver does
   not stop. A stop without a condition id is a defect: log it in the journal and **continue**. The driver never
   surfaces a question to the owner that it could have answered itself.
2. **A lane that stops to ask is a stall, not a result.** Detect it (report contains a question instead of an
   artifact; no file changed; no command run), log it as `stall`, and re-dispatch that chunk with the anti-stall
   clarification — up to a bounded number of attempts (`--max-stalls`, default 2). Never forward the lane's question
   to the owner.
3. **The driver never creates governance artifacts** — no measurement framework, pillar, census, taxonomy, contract
   file, or status report about the process. If a lane produces only such a document, treat it as no progress.
4. **Progress means an artifact plus evidence.** A chunk is progress only if it changed a product file *and* a command
   the driver re-ran supported the claim. A chunk with neither is recorded as `no-progress` and the next action must
   differ from the last.
5. **No-progress is bounded, not terminal.** Repeated `no-progress` promotes the reasoning level (replan, then a
   different approach, then escalate as `irreversibility`), and never becomes an owner question by default.

### Commands it must provide

```
php tools/harpp2/harpp2.php run --objective=tools/harpp2/objectives/akira-cms.md
php tools/harpp2/harpp2.php status   --objective=...
php tools/harpp2/harpp2.php journal  --objective=...
```

- **`run`** — executes the loop: chooses the next chunk, dispatches **one** executor lane serially through
  `tools/harpp2/dispatch.sh`, waits for it, then **verifies for itself** by re-running the commands the lane's
  claim rests on, then continues with the next chunk. It exits when the objective's acceptance gates pass, or on a
  boundary violation, or on an escalation.
- **`status`** — a one-screen summary: objective, chunks attempted, verified, blocked, and why.
- **`journal`** — the append-only record (`.jsonl`) of every action, its command, its exit code and its result.

### Hard requirements

1. **The verifier is not the executor.** A lane's report is a *claim*. The driver re-runs the evidence commands
   itself and records real exit codes. A claim it cannot reproduce is recorded as unverified — never as success.
2. **Boundaries come from the constitution's list** — a short hard-coded array of the seven prohibitions, checked
   against the file paths a chunk touched. **No taxonomy. No regex-per-path classification. No trust surface.**
   Nothing beyond: was a path outside the objective, was data destroyed, was a security control touched, was a test
   weakened.
3. **Serial dispatch.** One lane at a time, one working tree. Refuse to start a second lane while one is running.
4. **Escalation is the only stop.** When — and only when — proceeding would exceed authority, violate a boundary, or
   require an irreversible/high-impact decision, write one plain-Markdown file to `tools/harpp2/escalations/` naming:
   the objective, the exact blocker, two or three options with their consequences, and a recommendation. Then stop.
   No schema, no decision-request object, no queue.
5. **Resume the same objective.** After any correction to itself, the driver resumes the same objective. There is no
   mode in which it studies or improves itself while the objective is unfinished.
6. **State on disk only.** `tools/harpp2/state/<objective-slug>.json` plus the `.jsonl` journal. No database.

### Judgement file — the one thing that makes this different from v1

After each chunk, append **one paragraph** to `.ai/harpp2-judgement.md` answering only: *why couldn't it continue
further?* — and, if a rule of its own prevented progress, name the rule so it can be removed. This file is how the
system improves: by removing obstructions, not by accumulating rules.

## Acceptance

1. `php tools/harpp2/harpp2.php status --objective=tools/harpp2/objectives/akira-cms.md` runs and prints a real summary.
2. A **dry run against a throwaway objective** proves the loop end to end without touching Akira: a trivial objective
   file whose acceptance is one command, one chunk dispatched, evidence re-run, journal written, `verified` recorded.
   Use the real `dispatch.sh` at least once with a cheap model so the dispatch path is exercised, or — if you judge a
   live model call disproportionate — state exactly which part of the dispatch path is then unproven.
3. Boundary enforcement is **falsified, not asserted**: show a run being refused/flagged for touching a file outside
   the objective's scope, and for a destructive operation, with the real output.
4. `php -l tools/harpp2/harpp2.php` is clean.

## Do not build

A metrics framework · a census · a pillar list · a contract validator · an L0–L4 ladder · a trust surface · a commit
gate · a dashboard · a plugin registry · multi-agent consensus · a Workbench feature. If you believe one is needed,
**do not build it**: write it in `.ai/harpp2-judgement.md` as a blocker with the exact situation that demanded it, and
carry on with the driver.

## Boundaries

- Do not touch `tools/ai-run.php`, `tools/ai-autonomy.php`, or `.github/instructions/` — v1 is frozen as the record.
- Do not touch `kernel/`, `src/`, `modules/`, or the tenant database.
- No new dependency. No schema. No migration.
