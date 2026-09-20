# SLICE — Akira view contracts: a theme's declaration must agree with the module's contract

project: akira-authority · status: READY_FOR_IMPLEMENTATION · revision: 2 (CD-52: the module test path was added after dispatch)
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "view-contract-drift", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `docs/reviews/akira-view-layout-architecture-review.md` findings **V3**, **V4** and **V6**.
This contract authorises correcting a theme's declaration and adding a validation check. It does NOT
authorise changing the module's registered contract.

## Objective

A theme declares which fields its views expect, in `entity-view-map.json`. The module registers a
contract for the same views, in `cms-akira-core/helpers/entity-views.php`. **Today the two disagree,
and nothing checks them.** Measured drift:

| view | module registers (`cms-akira-core/helpers/entity-views.php`) | theme declares (`storage/cms-themes/akira-editorial/entity-view-map.json`) |
|---|---|---|
| `post.list` | `title, subtitle, image, metadata, categories, actions, url` (`:45-46`) | `title, subtitle, metadata, image, url` (`:6`) |
| `post.detail` | `title, subtitle, image, body, metadata, categories, actions, url` (`:70-71`) | `title, subtitle, body, metadata, image, url, categories` (`:11`) |

So the theme's map is a lossy duplicate that has silently drifted. Worse, the theme declares
`composition.detail` (`entity-view-map.json:13-19`) for which **no PHP registers any contract at all**
— grep for `registerView` across `kernel/`, `src/`, `modules/` finds `post.list`/`post.detail` only,
plus a `module.json`-driven path (`src/helpers/module-routes.php:375`) that no Akira manifest uses.

**Deliver two things:** make the shipped declarations true, and make future drift impossible by
checking them.

## What to build

1. **A pure comparison in the kernel.** Add
   `kernel/Services/ThemeViewContractDrift.php` — a final class with one static method that takes
   **arrays only** and returns findings:

   ```php
   /**
    * @param array<string,array<string,mixed>> $declaredViews     entity => view => {fields: list<string>}
    * @param array<string,list<string>>        $registeredFields "entity.view" => list<string>
    * @return list<array{entity:string,view:string,field:string,reason:string}>
    */
   public static function compare(array $declaredViews, array $registeredFields): array
   ```

   Two reasons, and the distinction is the whole point:
   - `contract_not_registered` — no entry for `"<entity>.<view>"` in `$registeredFields`. The caller
     treats this as a **warning** (fail-open). `composition.detail` is today's live example.
   - `field_not_in_contract` — a contract exists and the declared field is not in it. The caller
     treats this as an **error** (fail-closed).

   It must be a pure function of its arguments: no `app()`, no file I/O, no globals. It must tolerate
   a malformed `fields` value (non-array, or containing non-strings) without throwing.

2. **Wire it into theme validation.** In `catThemeValidate()` (`modules/cms-akira/cms-akira-theme/helpers.php:450`),
   read the theme's `entity-view-map.json`, obtain the registered contract fields from
   `app()->entityViews()`, call the comparison, append `field_not_in_contract` findings to
   `$result['errors']` and `contract_not_registered` findings to `$result['warnings']`, and record a
   `checks` entry so the check is visible. Each message must name the entity, the view and the field.

   **`app()->entityViews()` may not have the contract.** A CLI bootstrap does not register module
   capabilities, and a theme may be validated for a module whose views are not loaded. When no
   contract is obtainable at all, **skip the check and record it as skipped** — do not error, and do
   not manufacture a pass. This fail-open is a requirement, not a nicety.

3. **Correct the shipped declarations** so they match the contract exactly: add the missing fields in
   `storage/cms-themes/akira-editorial/entity-view-map.json` and
   `storage/cms-themes/akira-ark/entity-view-map.json`. Check `storage/cms-themes/akira-ark-demo/`
   too and correct it if it carries the same drift. Change **only** `fields`; leave `actions`, the
   entity/view names and everything else byte-identical.

4. **A pure test** at `tests/akira_theme_view_contract_drift_test.php`.

## Architectural constraints

The module's registered contract is the **source of truth**. If a declaration and a contract differ,
correct the declaration. Never change `cms-akira-core` to agree with a theme, and never delete a
declared field to make a check pass — a field the theme genuinely reads must be added to the
declaration, and if the contract does not carry it then that is a finding to report, not to hide.

The comparison must be pure so it can be tested without the application and without a database. Do
not reach for `app()`, a container, or a file read inside `compare()`.

`storage/cms-themes/` is tracked code, not runtime state — editing those files is a normal code
change. The theme layout, the region mechanism and the shell fallback layout are **out of scope**;
this slice changes declarations and adds a check, nothing else.

Do not weaken, skip or delete any existing assertion. If an existing test fails, report it — do not
adjust it. Do not introduce a runtime dependency. Use PHP 8.2-compatible syntax; the production
target is Bluehost/MySQL 5.7, so no MySQL 8 features anywhere.

## Files likely affected

- `kernel/Services/ThemeViewContractDrift.php`
- `modules/cms-akira/cms-akira-theme/helpers.php`
- `storage/cms-themes/akira-editorial/entity-view-map.json`
- `storage/cms-themes/akira-ark/entity-view-map.json`
- `storage/cms-themes/akira-ark-demo/entity-view-map.json`
- `tests/akira_theme_view_contract_drift_test.php`
- `modules/cms-akira/cms-akira-theme/tests/theme_view_contract_drift_test.php` (added in rev 2 — see CD-52)

## Acceptance criteria

1. `php tests/akira_theme_view_contract_drift_test.php` exits 0 and reports zero failures.
2. The comparison reports **zero** `field_not_in_contract` findings for the shipped
   `akira-editorial` and `akira-ark` maps when compared against the field lists the contract
   registers — i.e. the shipped declarations are now true.
3. Both directions are proven: a declaration carrying a field the contract does not have yields
   `field_not_in_contract`; a declaration for an entity+view with no contract yields
   `contract_not_registered`.
4. `composition.detail` yields `contract_not_registered` and no error, proving the fail-open path.
5. `catThemeValidate()` surfaces a `field_not_in_contract` finding as an error and a
   `contract_not_registered` finding as a warning — and records a `checks` entry.
6. `php -l` reports no syntax errors on every changed PHP file.

## Required tests

Report each command on its own line, prefixed `$ `, with its own result lines beneath it. Do not
chain commands with `;`, `&&`, `|` or `$(…)` — a chained line binds no claim and is refused.

```
$ php tests/akira_theme_view_contract_drift_test.php
$ php -l kernel/Services/ThemeViewContractDrift.php
$ php -l modules/cms-akira/cms-akira-theme/helpers.php
```

The test must be **pure**: it must not contain the string `bootstrap.php` and must not contain
`MODE_INTEGRATION`, or the ledger will refuse the claim (`test_file_impure`). That is exactly why
`compare()` must be a pure function — require the kernel class directly and pass it arrays.

If you also run a module test under `modules/cms-akira/cms-akira-theme/tests/`, it may bootstrap, but
if it reaches the application database it must call `requireNotLiveTenantDatabase()`
(`tests/_support/env_guard.php`) or the ledger will refuse the claim.

Report a command you did not run as **not run**. A test named but not executed is not evidence.

## Risks

- **Fail-open mistaken for a pass.** If `app()->entityViews()` cannot supply a contract in the
  environment you test in, the check silently skips and a green test proves nothing about the
  wiring. Report explicitly whether the wiring path was exercised or only the pure function.
- **Declaring a field the contract lacks.** If a theme template genuinely reads a field the contract
  does not register, adding it to the declaration produces a `field_not_in_contract` error by
  design. That is a **finding to report**, not something to silence — say which field and stop.
- **`app()` unavailable in a pure test.** `tests/` admits only tests containing neither
  `bootstrap.php` nor `MODE_INTEGRATION`. A test that needs the application belongs under
  `modules/<mod>/tests/`, not `tests/`.

## Forbidden changes

- `kernel/EntityContext/`
- `modules/cms-akira/cms-akira-core/`
- `modules/cms-akira/cms-akira-shell/`
- `storage/cms-themes/akira-editorial/layouts/`
- `storage/cms-themes/akira-editorial/templates/`
- `storage/cms-themes/akira-editorial/entity-views/`
- `tests/_support/`
- `.github/workflows/`
- `phpstan-baseline.neon`
- `.governance-baseline.json`
- `tools/`
