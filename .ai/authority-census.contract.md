# Contract — Authority census: declaration vs store

task:
  id: task-authority-census
  objective: A read-only census that reports where a capability's DECLARED authority diverges from the
    authority the store actually grants, so divergence is named instead of inferred from log noise.

## Objective

A capability's authority is expressed in four places that must agree, and nothing reconciles them:

| # | Source | Location |
|---|---|---|
| 1 | `capabilities.exposes[]` | `modules/*/module.json` |
| 2 | `capabilities.routes` | `modules/*/module.json` |
| 3 | declared policy rows | `kernel/Services/ModuleInstallService.php` (feeds `seedPolicy()` at `:90`) |
| 4 | the policy store | `capability_authorization_policies` (versioned; one active row per capability) |

Nine separate instances of "a declaration does not control what it appears to control" are recorded in
this programme, and the sharpest was measured today on tenant 54:

```
capability    : akira.shell.admin_page@1
policy_version: 30                     <- the store's ACTIVE version
widened field : caller_module          <- NOT roles; the role sets are identical
roles both sides: admin,administrator,superadmin
```

The shipped declaration asks for a broader caller scope than the live active row grants, so the code can
never obtain the authority it declares. Until today that was one indistinguishable warning among nine
routine ones. **This slice covers sources 3 vs 4** — where that instance and the nine others live. Route
mapping (sources 1 vs 2) is a deliberate follow-up, not an omission.

## Files likely affected

- `kernel/Workbench/Governance/AuthorityCensus.php` (new)
- `ikabud` (add the reading command)

## Architectural constraints

- **Read-only.** No transaction, no write, no policy row touched, no schema, no `grant_state` change. The
  census reports; it decides nothing. Correcting a divergence changes what the system permits, so those
  corrections stay with the director.
- `AuthorityCensus::compare(array $declarations, array $activeRows): array` is **pure**: no I/O, inputs
  unchanged, deterministic ordering. This is the tested core.
- Return shape exactly as the probe fixes it: `counts` keyed
  `matching, declared_absent, divergent, superseded` in that order, and `findings` entries carrying
  `capability_id, class, direction, fields, declared_version, active_version`.
- **Roles compare as SETS.** `admin,editor` and `editor,admin` are equal. Order is not authority.
- `direction` is `widening` when the declaration has authority the store lacks, `narrowing` when the
  store has authority the declaration omits, `mixed` when both. **Widening and narrowing are different
  findings and must never be merged** — one is refusal-by-design, the other is an operator decision.
- **A superseded declaration is not a live divergence.** When the declaration's `policy_version` is not
  the store's active version, class it `superseded`: the seed is inert bookkeeping (the defect fixed in
  `beace5c`), and reporting it as live would recreate exactly the noise this census replaces.
- A capability whose declaration matches the active row must be reported `matching`, **not** omitted — so
  the total is auditable. A census that flags everything is the same defect as a warning nobody reads.
- The command exits 0 when it reports (a report is not a failure) and prints counts per class. Read-only,
  so it is safe to run anywhere, including against a live tenant.
- PHP 8.3. No new dependency. No public API or capability contract change.

## Acceptance criteria

1. The chair-authored probe passes in full:
   `php tests/authority_census_contract_test.php` → exit 0, `0 failed`.
2. The census is **wired, not merely defined** — a defined-but-unused class reports nothing:
   `grep -c "AuthorityCensus" ikabud` → ≥ 2.
3. The command runs and reports: `php ikabud capability:census` → exit 0.
4. **The known-good positive case**, on the live tenant — a silent census fails:
   `php ikabud capability:census --tenant=54 | grep -c "akira.shell.admin_page@1"` → ≥ 1.
5. No syntax error: `php -l kernel/Workbench/Governance/AuthorityCensus.php` → exit 0.
6. No harness regression: `php tools/chair.php --self-test` → exit 0, `0 failed`.
7. Static analysis clean on the new file, **with the config**:
   `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Workbench/Governance/AuthorityCensus.php` → exit 0.
   (If this file cannot start clean, say so with the error rather than widening scope to fix the
   repository — a criterion that is already red cannot be an acceptance criterion.)

## Required tests

```
php tests/authority_census_contract_test.php
php -l kernel/Workbench/Governance/AuthorityCensus.php
php tools/chair.php --self-test
php ikabud capability:census
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel/Workbench/Governance/AuthorityCensus.php
```

## Risks

- **Flagging everything.** If `matching` is not reported, or role order counts as difference, the census
  becomes noise. The probe asserts both directions of that.
- **Merging widening and narrowing.** They require different human responses; one is the kernel refusing
  by design, the other an operator's decision. Merged, the report cannot be acted on.
- **Reporting superseded rows as live.** That is the `beace5c` defect rebuilt inside the census.
- **Silently reading the wrong store.** `app()->db()` and `app()->dbForTenant($id)` resolve differently;
  the census must read the same store the seeder writes to, and must name which it read in its output.
- Live correction of any divergence is out of scope by design, and belongs to the director.

## Forbidden changes

- `tests/authority_census_contract_test.php` (chair-owned acceptance)
- `tests/`
- `kernel/Capabilities/CapabilityAuthorizationRegistry.php`
- `kernel/Services/ModuleInstallService.php`
- `modules/`
- `src/`
- `migrations/`
- `storage/`
- `tools/`
- `phpstan-baseline.neon`
- Do not weaken, skip, delete or disable any test or gate to obtain a pass.
- Do not write to the policy store: this slice only reads.
