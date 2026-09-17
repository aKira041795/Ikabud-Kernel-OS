# OBJECTIVE — Akira item A.2a: serialized payloads obey a declared field contract

system: HARPP v2 · prepared 2026-09-16 · follows `.ai/akira-completion-plan.md` §4 Phase A item 2
authority: `.ai/akira-completion-plan.md` (APPROVED 2026-09-15) — *"One metadata rule, no contradiction"* and the
**Named projection contracts** table.

## Scope

- `kernel/EntityContext/`
- `tests/`

## Acceptance

```
$ php tests/entity_view_payload_projection_test.php
```

## The item

Phase A.2 names four emission channels that take row data somewhere other than a visible cell. This item covers **the
two that serialize row data into a payload**; the other two (custom slot / `_children`, and action URL / row-click) are
the next item.

| Channel | Code | Contract (declared field list) |
|---|---|---|
| row-action POST hidden inputs | `renderRowActions()` — serializes every scalar row member | `action_payload_fields` |
| inline edit | `renderCellEditable()` — embeds the whole row as `rowData` | `editable_context_fields` |

**The rule, for both channels — the same shape as A.1:**

| Case | Behaviour |
|---|---|
| the channel's declared field list is present | **authoritative** — only those fields are emitted; an empty list emits nothing |
| absent | the channel emits **no row data** |
| **malformed** (non-array, or non-string members) | **emit nothing** — never fall back to "all scalar members" |
| a field name in the list that the row does not have | skip it; never emit a placeholder |

**Two further requirements, both from the plan:**

1. **POST rendering is omitted when a trusted CSRF token cannot be acquired.** A row action that cannot carry a valid
   token must not render as a submittable form — fail closed.
2. **URL values are validated, not merely HTML-escaped.** A field used in an action URL must be scheme- and
   target-checked; escaping alone is not validation.

Write `tests/entity_view_payload_projection_test.php` proving, **against both channels**: the declared case emits
exactly the declared fields; the absent case emits no row data; the malformed case emits no row data; an empty declared
list emits nothing; and a missing field is skipped rather than substituted. The test is the run's acceptance command.

**A run that changes the rule to match the test, rather than the test to match the rule, has failed.** If the current
implementation cannot express the rule, state why in the report and stop — that is a finding, not a failure.

## Regression guards (must not get worse; run by the chair, not by the driver)

```
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php     # 116/0 baseline
$ php tests/workbench_governance_census_test.php                          # PASS baseline
$ composer test                                                           # 140 passed / 53 skipped / 0 failed
$ npx playwright test                                                     # 17 passed
```

## Boundaries

The constitution's list, plus: the tenant database may be read but not written destructively; migrations must be
additive and idempotent. These two files are the only writable product paths; anything else needs a new objective.
