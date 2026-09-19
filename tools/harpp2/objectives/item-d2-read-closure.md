# OBJECTIVE — Akira item D.2: read-authority closure across the Akira modules

system: HARPP v2 · prepared 2026-09-16 · `.ai/akira-completion-plan.md` §4 **Phase D**
authority: the plan's read-authority target and **CD-56 Finding 1** (a public route cannot be declared; the honest
mechanism is a reasoned exemption).

## Scope

- `modules/cms-akira/`
- `templates/modules/cms-akira/`
- `tests/`

## Acceptance

```
$ php tests/read_governance_closure_test.php
```

## The item

D.1 closes `cms-akira-shell`. This item closes **the rest of the Akira suite**, and it is deliberately a different kind
of work: it begins with a measurement and may legitimately conclude that some modules are already closed.

1. **Measure first, and report the numbers.** For every `cms-akira-*` module, record `read_total`,
   `read_dispatch_enforced`, `read_exempt`, `read_undeclared` from
   `php ikabud workbench:governance --all --json`. State the before-state in the report as a table — do not assume any
   module's numbers.
2. **Close each module with undeclared reads** to `read_undeclared: 0`, using exactly the two mechanisms D.1
   established: **declare** the route against an existing or added capability, or **exempt it with a checkable
   reason**. A public route takes the exemption — it cannot be declared, because anonymous dispatch has no actor and
   therefore no role.
3. **Write `tests/read_governance_closure_test.php`** asserting, for every `cms-akira-*` module, that
   `read_undeclared == 0`, that every exemption carries a non-empty reason, and that
   `read_dispatch_enforced + read_exempt == read_total`. Also assert the **non-regression** of the write side:
   `write_ratio == 100` per module.
4. **State the target's limits honestly.** The plan asks for closure *against a frozen, dated route inventory*; if you
   find the census counts a route the inventory does not contain (or vice versa), say so in the report rather than
   making the numbers agree.

## Regression guards

```
$ php tests/shell_read_declarations_test.php            # item D.1
$ php tests/workbench_governance_census_test.php        # PASS
$ php tests/read_authority_probe_test.php
$ composer test                                         # 141 passed / 53 skipped / 0 failed
```

## Boundaries

The constitution's list. **Do not widen an existing grant's caller set**, do not weaken an existing check to make the
census agree, and do not declare a public route (it would 403 anonymous callers — CD-56 F1). If a module's reads
cannot honestly be closed, report which and why, and stop: an honest partial is a finding, a padded one is a defect.
