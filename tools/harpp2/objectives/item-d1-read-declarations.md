# OBJECTIVE — Akira item D.1: the shell's undeclared reads are declared or reasoned

system: HARPP v2 · prepared 2026-09-16 · `.ai/akira-completion-plan.md` §4 **Phase D — administered surface + read
governance**
authority: the plan's read-authority target: *"closure against a frozen, dated route inventory … with
declared/enforced/exempt/undeclared counted separately"*, and **CD-56 Finding 1**, which established the rule this item
must respect.

## Scope

- `modules/cms-akira/cms-akira-shell/`
- `templates/modules/cms-akira-shell/`
- `tests/`

## Acceptance

```
$ php tests/shell_read_declarations_test.php
```

## The item

Measured on 2026-09-15 (`php ikabud workbench:governance --all --json`): **`cms-akira-shell` reports
`read_undeclared: 8`** with `read_dispatch_enforced: 16`, `read_exempt: 0`, `read_total: 24`. Every one of those eight
is a GET route reachable without a declared capability.

**CD-56 Finding 1 is binding, and it is the whole difficulty:** a **public** route cannot be declared — anonymous
dispatch fails with `unknown_role` when `actor_role === ''`, so declaring a public route would 403 every anonymous
caller regardless of any policy row. Public surfaces therefore take a **reasoned exemption**
(`governance.exemptions`), which is the honest mechanism and which PR #124 added.

So, for each of the eight, do exactly one of:

1. **Declare it** — in `module.json` `capabilities.routes`, mapped to a capability that already exists or that you add
   following the module's existing capability-handler pattern, with a policy row at the tier of its neighbours. A
   declared route must be *enforced*, not merely named.
2. **Exempt it with a reason** — only if it is genuinely public. The reason must be a sentence a reviewer can check
   (e.g. *"public archive page; anonymous callers have no actor and therefore no role"*), not a restatement of the
   route name.

Then write `tests/shell_read_declarations_test.php` asserting, from the census and the manifest:
- `cms-akira-shell` reports **`read_undeclared: 0`**;
- every declaration maps to a capability that exists;
- **every exemption carries a non-empty reason**;
- `read_dispatch_enforced + read_exempt == read_total` — the invariant, unchanged.

Report the before/after census block verbatim.

## Regression guards (must not get worse; the chair runs these)

```
$ php tests/workbench_governance_census_test.php        # PASS baseline
$ php tests/read_authority_probe_test.php               # declared-reads inventory
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php   # 116/0
$ composer test                                         # 141 passed / 53 skipped / 0 failed
```

## Boundaries

The constitution's list. **Do not widen an existing grant's caller set** — the authority store refuses widening from
code by design; add a new declaration or a reasoned exemption instead. Do not weaken an existing check to make the
census agree. If a route cannot honestly be declared *or* exempted, say so and stop — that is a finding.
