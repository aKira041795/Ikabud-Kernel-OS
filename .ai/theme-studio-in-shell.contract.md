# SLICE — Theme Studio renders inside the one shared shell

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: P1 (the last admin surface outside the shell)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "theme-studio-in-shell", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/projects/akira-cms-completion/project.md` (ACTIVE), phase P1. HARPP #100 authorises the outcome;
option A (a kernel registry change) was found to be an **absolute prohibition** and is **not** available.

## Objective

`/cms-akira-theme` must render inside the shared Akira shell navigation, and
`tests/browser/akira-admin-shell.spec.ts` must pass.

Measured baseline — real output, this repository, 2026-09-15:

```
$ npx playwright test tests/browser/akira-admin-shell.spec.ts
0 passed, 2 failed, 0 skipped
Error: expect(locator).toBeVisible() failed
Locator: getByRole('navigation', { name: 'Akira administration' })
Error: element(s) not found
```

The spec builds its route list from every sidebar destination **plus a hardcoded `/cms-akira-theme`**, and it also
asserts the url does **not** redirect away from the route. So `/cms-akira-theme` must both remain at that path and
render the shared navigation.

## Why it fails — already diagnosed. Do not re-derive this; build on it.

`cms-akira-theme/helpers.php:1390` calls `akira.shell.admin_page@1`, and `cms-akira-theme/module.json:122` declares
the dependency. The live tenant-54 policy row refuses the caller:

```
policy_version 30 · akira.shell.admin_page@1 · is_active 1
caller_module = cms-akira-shell,cms-akira-seo,cms-akira-navigation      # cms-akira-theme absent
```

The committed seed (`cms-akira-shell/helpers.php:48`) **already names the fourth caller**, but
`CapabilityAuthorizationRegistry::seedPolicy()` deliberately refuses to **widen** an existing granted row —
`widening_refused`, reason `operator_regrant_required`, the ratified **D-Q4** rule. So the committed seed can never
reach the tenant, and `kernel/Capabilities/` is an **absolute prohibition** this contract may not touch.

**Your task is to find a lawful mechanism that achieves the objective. It is not to defeat that guard.**

## Files likely affected

- `modules/cms-akira/cms-akira-theme/helpers.php`
- `modules/cms-akira/cms-akira-theme/handlers.php`
- `modules/cms-akira/cms-akira-theme/routes.php`
- `modules/cms-akira/cms-akira-theme/module.json`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/handlers.php`
- `modules/cms-akira/cms-akira-shell/routes.php`
- `modules/cms-akira/cms-akira-shell/module.json`
- `modules/cms-akira/cms-akira-core/helpers.php`

## Forbidden changes

- `kernel/`
- `tools/`
- `phpstan-baseline.neon`
- `tests/browser/auth.setup.ts`
- `tests/browser/akira-admin-shell.spec.ts`

## Architectural constraints

- This slice must place `/cms-akira-theme` inside the shared shell without editing the kernel capability registry.
- **Do not defeat the D-Q4 widening guard by aliasing.** Introducing a renamed duplicate of the chrome capability
  purely to obtain a wider caller list achieves by renaming exactly what `widening_refused` forbids by design. If
  that is the only route you can find, **stop and report it** — a clear "no lawful route exists" is a valuable
  result and will be accepted.
- **Do not weaken the spec.** `/cms-akira-theme` must stay in its route list under its own path. Deleting the entry,
  skipping the route, relaxing the assertion, or allowing a redirect is a failure, not a fix.
- **Do not fix this by editing the tenant database.** A hand-edited row proves nothing and leaves every other tenant
  broken.
- Never disable, skip, or delete an existing test to obtain a pass.
- Reuse the existing chrome (`akira.shell.admin_page@1` / `akiraShellPage()`); do not build a second page builder or
  copy the navigation list.

## Required tests

```
npx playwright test tests/browser/akira-admin-shell.spec.ts     # must be 2 passed, 0 skipped
npx playwright test                                             # must be 11 passed
php modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php
php -l <every changed .php file>
vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <every changed file>
```

Report exact passed / failed / skipped counts. A skip is not a pass.

## Acceptance criteria

1. `/cms-akira-theme` renders the shared navigation, stays at its own path, and both viewports of
   `akira-admin-shell.spec.ts` pass with the route still asserted.
2. The full browser suite is `11 passed`.
3. There is no change to `kernel/`, and no existing policy row was re-granted or widened.
4. Every changed file passes PHPStan under `-c phpstan.neon`.

## Risks

- **Aliasing the capability** is the fastest-looking path and is the one thing explicitly refused above.
- **Weakening the assertion** would green the suite and destroy its purpose.
- **The honest negative result is acceptable.** If no lawful mechanism exists, say so with the evidence; that is a
  better outcome than a green suite obtained by defeating the guard.
