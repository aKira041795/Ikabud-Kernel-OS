# Contract — P6 recovery: governed bundle diff and additive apply

## Provenance

Chair-selected, not a director decision. It is **obligation row 2** of the approved plan
`.ai/akira-master-plan.md` (`:71`) — the only item that table marks `— unblocked` — and the plan defines
it at `:354`:

> **P6 · Production floor** — governed export/import bundles (dry-run diff + audited apply), recovery,
> backup proof.

The same ask appears independently in `.ai/akira-cms-roadmap.synthesis.md:85` (P6),
`docs/architecture/akira-product-direction.md:95` (C5), `.ai/akira-completion-plan.md:207` (Phase I) and
`docs/architecture/cms-akira-extensibility-adr.md:108` (T5). Nothing here is invented for the harness's
benefit.

## Objective

Give CMS Akira a **recovery path**: export a bundle, compute a **dry-run diff** against the tenant, then
**apply it under audit** — additively.

The defect shape is the one this programme keeps finding, and it is at its sharpest here. The **export
half already ships**: `akira.backup.list@1`, `akira.backup.create@1` and `akira.export.create@1` are
declared in `modules/cms-akira/cms-akira-core/module.json` with handlers
(`cac_cap_akira_backup_list_1`, `cac_cap_akira_backup_create_1`, `cac_cap_akira_export_create_1` in
`helpers/backup.php`) and a shipped console. The **import half does not exist at all**: no
`akira.bundle.*` capability, no diff, no apply, no refusal rule. A CMS that can export but cannot restore
has a production floor with a hole in it, and "recovery" is the word the plan uses.

**Scope is deliberately additive.** This slice reads and adds. It never deletes, never overwrites a
conflict destructively, and never reaches `ModuleDataResetService`.

## Architectural constraints

- **Capability-first.** Declare `akira.bundle.diff@1` and `akira.bundle.apply@1` in
  `cms-akira-core/module.json` (`capabilities.exposes`) **before** writing routes. The declaration drives
  everything else; it is not paperwork added afterwards.
- **`apply` is a mutation and must say so:** `requires_protocol: "v2"` (so its idempotency key is
  enforced) and an `effects.invalidates` list naming what it can change. `diff` is a read and must claim
  **no** invalidation.
- **The interface is already fixed by a test the chair wrote and that you must not edit.**
  `modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php` requires:
  - `cacBundlePlan(array $bundle, array $current): array` → `['add'=>[], 'update'=>[], 'skip'=>[], 'remove'=>[]]`,
    each a list of `"kind:key"` strings;
  - `cacBundleRefusal(array $plan): ?string` → a reason string containing "remov" when the plan contains
    removals, `null` otherwise.
  Read that test first: it is the acceptance, and it is the specification.
- **Identity and change detection are hashes, not timestamps.** An entry is a `skip` when its payload hash
  already matches the tenant's; an `update` when it differs; an `add` when the tenant lacks it; a `remove`
  when the tenant has something the bundle does not. Deterministic ordering is required — the same inputs
  must give an identical plan.
- **The plan is pure.** `cacBundlePlan()` performs no write, opens no transaction, and leaves its inputs
  unchanged. Prove it.
- **Additive apply only.** Apply never issues a delete. A plan containing removals is refused by
  `cacBundleRefusal()`, and the refusal is **surfaced to the operator** with the entries named — not
  swallowed and not silently skipped. Refusing is the correct outcome, not a failure.
- **Reuse the existing export path.** `cacExportCollectPostRows()` and `cacExportExcerpt()` already exist;
  a bundle is built from them. Do not write a second export implementation.
- **`cms-akira-shell` remains contractually table-free** (`owns_tables: []`, `reads_tables: []`). No SQL
  in the shell. The shell renders and calls capabilities.
- **Audit every invocation** through `kernel.audit.record@1`. If the audit fails, the operation fails.
- **Idempotency.** Apply takes an idempotency key. A replay with the same key changes nothing and says so.
- **Declaration test:** every new POST route is declared in `capabilities.routes` **and** its handler calls
  that same capability.
- **Policy pre-flight:** confirm in the tenant DB that an **active** policy row exists for each consumed
  capability whose `caller_module` permits the calling module and whose `allowed_roles` admits the tier
  you gate to. Report the row. An ungoverned capability is fail-open — if you find one, **govern it by
  adding a narrowing row**, never by widening an existing row.
- **Role-set rule:** a contribution must declare the **same role set as the policy row it points at**.
- Admin-tier only, and audited. Recovery is privilege-sensitive.
- No GET side effects. Applying a bundle is an explicit, audited POST.
- MySQL 5.7 compatible: no CTEs, no window functions, `ENGINE=InnoDB`.

Binding prohibitions retained as text: no migration, DDL or schema change; no weakening of auth,
authorisation, policy or security; no widening of any role set or policy row; no editing any test,
contract or baseline to obtain a pass; no SQL in `cms-akira-shell`; no new capability in `kernel/`; no
destructive or irreversible operation of any kind; no widening scope to meet an acceptance criterion; do
not read, copy or link `gui-settings`.

## Files likely affected

- `modules/cms-akira/cms-akira-core/module.json`
- `modules/cms-akira/cms-akira-core/helpers.php`
- `modules/cms-akira/cms-akira-core/helpers/backup.php`
- `modules/cms-akira/cms-akira-core/handlers.php`
- `modules/cms-akira/cms-akira-core/tests/`
- `modules/cms-akira/cms-akira-shell/routes.php`
- `modules/cms-akira/cms-akira-shell/handlers.php`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/module.json`
- `modules/cms-akira/cms-akira-shell/templates/`
- `modules/cms-akira/cms-akira-shell/tests/`

## Acceptance criteria

1. `php -l` clean on every changed PHP file; `php _lint_disyl.php` clean on every changed template dir.
2. **The chair's acceptance test passes: 9 passed, 0 failed, exit 0** —
   `modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php`. It is red before your work
   (measured 2026-09-20: 0 passed, 9 failed) and green after it. Do not edit it.
3. `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` **still 116 passed, 0 failed, exit 0**
   (measured green before your work). Do not edit that test.
4. `php ikabud module:validate cms-akira-core` and `php ikabud module:validate cms-akira-shell` — report
   real output. If they report pre-existing environmental failures, say so; do not edit a baseline.
5. **Dry-run writes nothing.** Live: take a state fingerprint for the affected tables before and after
   calling `akira.bundle.diff@1` twice, and show they are identical. Show that no audit row claims an
   apply. A dry run that writes is the single most important thing this criterion exists to catch.
6. **A replay changes nothing.** Apply twice with the same idempotency key: the second must report it was a
   replay, and the state fingerprint must be unchanged between the two calls. Show the fingerprint and the
   audit rows — exactly one apply transition, not two.
7. **A bundle whose plan removes an entry is refused.** Live: show the refusal and its named entries, and
   prove the state fingerprint is unchanged. Do not implement a deletion path to make this "work".
8. Unauthorized role refused fail-closed — demonstrate with a real request.
9. **Report the policy row** for every consumed capability: `capability_id`, `caller_module`,
   `allowed_roles`, `is_active`. If any is missing, add a **narrowing** row and say which you added.
10. **State the population honestly:** how many posts exist for the tenant, how many the export
    collected, and what the diff classified (add/update/skip). Numbers from the real service, not from
    the test fixtures.
11. `storage/logs/error.log` **empty**. `storage/logs/app.log` may contain **exactly the 3 known
    `capability.policy.seed.widening_refused` warnings** that every bootstrap in this repository emits
    (measured: 2212 bytes, identical for an unrelated existing test, baseline taken 2026-09-20). Anything
    else in either log is a failure.
12. Report residue: artifacts/bundles created, audit rows written, policy rows added; what was cleaned up
    and what was left.

## Required tests

- `php modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php`
- `php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`

Both must pass, and the full set must have failed before the work started.

## Risks

- **Destructive drift.** An import that overwrites is one editing session away from an import that
  deletes. This slice is additive by construction: `cacBundleRefusal()` is the guard, and criteria 5–7
  are what prove the guard is real rather than decorative. Do not "fix" a refusal by adding a delete.
- **A button that does nothing.** The programme has found nine instances of a declaration that does not
  control what it appears to control. Criteria 5–7 exist to prevent a tenth: a console that renders a
  diff it did not really compute is worse than no console.
- **A vacuous probe.** The chair's test asserts the capability EXISTS before asserting anything about it,
  because `!isset(...)` alone passes when the whole declaration is missing. Keep that property in
  anything you add.
- **Policy inflation.** Governing by widening an existing row is prohibited; a new narrower row is the
  only sanctioned direction.
- **Live-tenant damage.** Tests must never bind to tenant 54. Module tests refuse a provisioned tenant
  database by design (`requireNotLiveTenantDatabase()`), which is why the chair's probe touches no
  database at all.
- **Egress.** A bundle contains content. Admin-tier only, audited, and never written outside the path the
  existing export service already uses.

## Forbidden changes

- `kernel/App.php`
- `kernel/Contracts/`
- `kernel/Database/`
- `kernel/Services/`
- `modules/gui-settings/`
- `migrations/`
- `storage/cms-themes/`
- `phpstan-baseline.neon`
- `modules/cms-akira/cms-akira-core/tests/bundle_recovery_contract_test.php`
- Do not edit `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`, or any other existing
  test, to obtain a pass.

## Live verification credentials

Tenant 54 on `http://akiracms.test` — user `charlienacario884`, password `aki123` followed by `!#`.
Chair-verified. **The shell mangles `!`**: write the login JSON to a file and post it with
`curl -d @file`. Log in **once** and reuse the session.

**If every page returns 403, your session expired — it is not a product defect.** Confirm by requesting a
page you already know works before reporting an authorization problem.

## Failure protocol

Return `BLOCKED / ARCHITECTURE_DECISION_REQUIRED` with evidence and options if recovery cannot be built
additively through the existing services, if a consumed capability turns out to be ungoverned in a way
that cannot be repaired by adding a narrowing row, or if the console cannot reach the diff without SQL in
the shell. Do not invent a parallel export implementation, do not expose anything destructive, and do not
report a `SKIP` as a pass.
