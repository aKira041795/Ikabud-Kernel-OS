# OBJECTIVE — Akira item A.2b: row context and row-click obey a declared field contract

system: HARPP v2 · prepared 2026-09-16 · follows `.ai/akira-completion-plan.md` §4 Phase A item 2
authority: `.ai/akira-completion-plan.md` (APPROVED 2026-09-15) — the **Named projection contracts** table.

## Scope

- `kernel/EntityContext/`
- `tests/`

## Acceptance

```
$ php tests/entity_view_context_projection_test.php
```

## The item

Phase A.2 names four emission channels that take row data somewhere other than a visible cell. **A.2a did the two that
serialize row data into a payload; this item does the remaining two** — the ones that leak row data into rendered
context and into URLs.

| Channel | Code | Contract (declared field list) |
|---|---|---|
| custom slot / `_children` | `renderWithRowContext()` | `template_fields` |
| action URL / row-click | `renderRowClickAttrs()` — unrestricted row context | `url_key_fields` |

**The rule — the same shape as A.1 and A.2a, deliberately identical:**

| Case | Behaviour |
|---|---|
| the channel's declared field list is present | **authoritative** — only those fields reach the context/URL; an empty list emits nothing |
| absent | the channel receives **no row data** |
| **malformed** (non-array, or non-string members) | **emit nothing** — never fall back to the whole row |
| a declared field the row does not have | skip it; never substitute a placeholder |

**URL values must be validated, not merely HTML-escaped.** A field used to build an action URL or a row-click target
must be scheme- and target-checked; escaping alone is not validation. State in the report which schemes are permitted
and what happens to anything else (including protocol-relative and `javascript:` forms).

Write `tests/entity_view_context_projection_test.php` proving, **against both channels**: the declared case emits
exactly the declared fields; absent emits no row data; malformed emits no row data; an empty declared list emits
nothing; a missing field is skipped; and a disallowed URL scheme is refused. The test is the run's acceptance command.

The rule is the spec and the test proves it — **never the reverse.**

## Regression guards (must not get worse; run by the chair, not by the driver)

```
$ php tests/entity_view_malformed_metadata_test.php            # 39/39 — item A.1
$ php tests/entity_view_payload_projection_test.php            # pass — item A.2a
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php   # 116/0
$ composer test                                                # 141 passed / 53 skipped / 0 failed
$ npx playwright test                                          # 17 passed
```

## Boundaries

The constitution's list. These two files are the only writable product paths; anything else needs a new objective.
An existing test may be adjusted only to correct a **stale expectation whose invariant is unchanged** — and the
comment must say which route or rule moved it. Anything else that weakens an existing check is a boundary violation.
