# OBJECTIVE — Complete the approved Akira CMS plan

system: HARPP v2 · started 2026-09-16
authority: `.ai/akira-completion-plan.md` (**APPROVED 2026-09-15**) with the status board and obligation list in
`.ai/akira-master-plan.md`. Both are current as of 2026-09-16.

## What must be accomplished

**Milestone 1 — Akira CMS product-complete**, as the approved plan defines it:

1. P0 accepted across **all** emission channels (the named projection contracts: row-action POST payload, inline
   edit context, custom slot / `_children`, action URL / row-click).
2. ARK contract canonicalized; request seams resolved **or explicitly recorded as `unknown`**.
3. Editorial theme complete.
4. Governed admin surface + read governance.
5. Honest Workbench console.

Milestone 2 (delegation as a product, provable history, Theme Studio, profiles + production floor) is **out of scope
until Milestone 1 is verified complete**.

## Where the work is

Dialogue between the plan and the repo. Derive the next chunk from the plan's remaining items; the status board is
the record of what is already done, with `file:line` evidence. **Do not redo completed work, and do not re-litigate a
completed phase.**

## Scope

Writable for this item — nothing else, and `kernel/EntityContext/` is named deliberately because that is where the two
resolvers live:

- `kernel/EntityContext/`
- `tests/`

## Acceptance — the item's own check, never the gates

This objective is driven **one bounded plan item per run**. The commands below are *this run's item* and its completion
test. When they pass, the run is over — that is what `verified` means here: **the item is done**, not that Akira CMS is
complete. Milestone 1 is judged against `.ai/akira-completion-plan.md` by the chair, and never by a command in this
file.

```
$ php tests/entity_view_malformed_metadata_test.php
```

**Regression guards — these must not get worse, they are NOT the goal** (run by the chair, not by the driver):

```
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php
$ php tests/workbench_governance_census_test.php
$ composer test
$ npx playwright test
```

Baseline when this run started: shell contract **116/0**, census **PASS**, `composer test` **140 passed / 53 skipped /
0 failed**, playwright **17 passed**. A run that leaves any of those worse has broken something and is not progress.

## This run's item — Phase A.1: malformed metadata must render nothing

`.ai/akira-completion-plan.md` §4 Phase A item 1. Today both `EntityViewResolver::resolveDisplayFields()` and
`DefaultEntityRenderer::resolveDisplayFields()` substitute `SAFE_FALLBACK_FIELDS` when metadata is malformed. The
intended rule is that **malformed metadata renders nothing** — a substitution silently invents fields the contract
never granted.

One rule, no contradiction:

| Case | Behaviour |
|---|---|
| explicit `visible_fields`, including `[]` | authoritative — `[]` renders nothing |
| absent, with a valid non-wildcard `fields` | use the declared list |
| absent, with wildcard `fields` | intersect with the reviewed, dated allowlist — a floor, never authority |
| **malformed** (non-array, or non-string members) | **render nothing** — never substitute the allowlist |

Deliver it in the product, and write `tests/entity_view_malformed_metadata_test.php` to prove the four cases **against
both resolvers**. The test is the run's acceptance command; a run that changes the rule to match the test rather than
the reverse has failed. `id`, `status` and `price` in any existing allowlist are **not** proven safe by their names —
review them member by member and remove or justify each.

## Boundaries for this objective

The constitution's list, plus:

- the tenant database may be **read**; migrations must be **additive and idempotent** — no `DROP`, no `TRUNCATE`, no
  destructive `ALTER`;
- `.ai/chair-decisions.md` is the Chair's record: read it, do not rewrite history in it;
- the frozen v1 apparatus (`tools/ai-autonomy.php`, `tools/ai-run.php`, the trust surface, `.ai/*.contract.md`) is
  **not** the operating path for this objective. Work under `tools/harpp2/CONSTITUTION.md`.

## First action

Start from the plan's remaining items — the admin-surface and emission-channel work that is still open — and choose
the smallest chunk that ends in a live measurement. Do not begin by improving HARPP.
