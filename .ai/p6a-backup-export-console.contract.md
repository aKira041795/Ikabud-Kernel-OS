# Contract — P6a Backup and export console

## Objective

Give CMS Akira an operator surface for **backup and export** — the operational floor a production CMS
needs and the thing most obviously missing from the admin.

Three services already exist in `kernel/Services/` and are unreachable from the admin:
`ModuleBackupService.php`, `KernelExport.php`, `ModuleDataResetService.php`. No capability is registered
for any of them. This is the fifth instance of the defect shape this programme keeps finding: the
machinery exists and the product is invisible.

**Scope is deliberately narrowed:** this slice delivers backup and export only. **Nothing destructive.**

## Architectural constraints

- Reuse the existing kernel services. Do not write a second backup or export implementation.
- `cms-akira-shell` remains contractually table-free (`owns_tables: []`, `reads_tables: []`). No SQL in
  the shell.
- Do not add capabilities to `kernel/`. If a capability is genuinely required, it belongs to the owning
  module (`cms-akira-core` is the likely owner) and must delegate to the kernel service.
- **`ModuleDataResetService` is OUT OF SCOPE. Do not call it, wire it, or expose it.** No reset, no
  purge, no delete, no uninstall, no drop. This slice creates and reads; it never destroys.
- **Declaration test:** every new POST route is declared in `capabilities.routes` **and** its handler
  calls that same capability.
- **Policy pre-flight:** confirm in the tenant DB that an **active** policy row exists for each consumed
  capability whose `caller_module` permits the calling module and whose `allowed_roles` admits
  `administrator`. Report the row. An ungoverned capability is fail-open — if you find one, **govern it
  by adding a row (narrowing)**, never widen an existing row.
- **Role-set rule:** a contribution must declare the **same role set as the policy row it points at**.
- Backup and export are privilege-sensitive. Gate them to the administrator tier and **audit every
  invocation** via `kernel.audit.record@1`; if the audit fails, the operation fails.
- A backup must be **idempotent** for a repeated idempotency key. Show it.
- Backups write to the filesystem through the existing service. Do not hand-roll paths, do not write
  outside the service's own location, and do not delete existing backups.
- Large operations must not be triggered by a GET. Creating a backup is an explicit, audited POST.
- MySQL 5.7 compatible: no CTEs, no window functions, `ENGINE=InnoDB`.

Binding prohibitions retained as text: no migration, DDL or schema change; no weakening of auth,
authorisation, policy or security; no widening of any role set or policy row; no editing any test,
contract or baseline to obtain a pass; no SQL in `cms-akira-shell`; no new capability in `kernel/`;
no destructive or irreversible operation of any kind; no widening scope to meet an acceptance criterion;
do not read, copy or link `gui-settings`.

## Files likely affected

- `modules/cms-akira/cms-akira-core/helpers.php`
- `modules/cms-akira/cms-akira-core/helpers/`
- `modules/cms-akira/cms-akira-core/module.json`
- `modules/cms-akira/cms-akira-core/tests/`
- `modules/cms-akira/cms-akira-shell/routes.php`
- `modules/cms-akira/cms-akira-shell/handlers.php`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/module.json`
- `modules/cms-akira/cms-akira-shell/templates/`
- `modules/cms-akira/cms-akira-shell/tests/`

## Acceptance criteria

1. `php -l` clean on every changed PHP file; `php _lint_disyl.php` clean on changed template dirs.
2. `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` **still 116/0, exit 0**. Do not edit
   that test.
3. `php ikabud module:validate cms-akira-core` and `... cms-akira-shell` pass. If the command reports
   pre-existing environmental dependency failures, say so — do not edit the baseline to hide them.
4. `GET /cms-akira-shell/backups` returns **HTTP 200** for the administrator on tenant 54, listing
   backups **read from the real service**, with an explicit empty state if none exist.
5. **State the population honestly.** Report how many backups exist for tenant 54 before your work. If
   none, show the empty state. Do not fabricate entries.
6. **Proof a backup is created:** trigger one backup through the console, show the response, then show
   the artifact read back **from the store/service** (path, size, timestamp, or record). A success
   message is not evidence; the artifact is.
7. **Proof export works:** produce an export and show real exported content. If the export is large,
   show a bounded excerpt and state the total size and record count. If no export path is usable, say so
   plainly rather than shipping a button that does nothing.
8. Idempotency: two identical backup requests with the same idempotency key do not create two backups.
9. Unauthorized role refused fail-closed — demonstrate with a real request.
10. **Confirm nothing destructive was exposed:** state explicitly that `ModuleDataResetService` is not
    reachable from any new route, and show the route list you added.
11. `storage/logs/error.log` unchanged; no `disabled_caller` authorization failures for the new paths.
12. Report residue: backups created, policy rows, audit entries; what was cleaned and what retained.

## Required tests

- `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` (must stay 116/0)
- `php ikabud module:validate cms-akira-core` and `cms-akira-shell`
- A test asserting the console's read path returns real state and its create path refuses an
  unauthorized role
- Live verification on tenant 54

## Risks

- **Exposing something destructive.** The single worst outcome of this slice is accidentally wiring
  `ModuleDataResetService`. It is explicitly out of scope; confirm it is unreachable.
- **A button that does nothing.** The programme has found nine instances of a declaration that does not
  control what it appears to control. Acceptance 6 and 7 exist to prevent a tenth.
- **Filesystem mutation.** Backups write files. Use the service's own path handling; do not invent paths
  and do not remove existing backups.
- **Data egress.** Export moves content out. Keep it admin-tier only and audited.
- **GET-side effects.** Never create a backup on page load.
- **Live-tenant damage.** Tests must never bind to tenant 54; use a synthetic tenant.

## Forbidden changes

- `kernel/App.php`
- `kernel/Contracts/`
- `kernel/Database/`
- `kernel/Services/`
- `modules/gui-settings/`
- `migrations/`
- `storage/cms-themes/`
- `phpstan-baseline.neon`

## Live verification credentials

Tenant 54 on `http://akiracms.test` — user `charlienacario884`, password `aki123` followed by `!#`.
Chair-verified. **The shell mangles `!`**: write the login JSON to a file and post it with
`curl -d @file`. Log in **once** and reuse the session.

**If every page returns 403, your session expired — it is not a product defect.** Confirm by requesting a
page you already know works before reporting an authorization problem.

## Failure protocol

Return `BLOCKED / ARCHITECTURE_DECISION_REQUIRED` with evidence and options if backup or export cannot be
reached through the existing services without a kernel change. Do not invent a parallel implementation.
Do not expose anything destructive. Do not report a `SKIP` as a pass.
