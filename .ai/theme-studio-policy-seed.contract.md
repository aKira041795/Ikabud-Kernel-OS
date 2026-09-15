# SLICE — the seeded chrome policy must actually reach the tenant

> **NOT DISPATCHABLE — do not dispatch this contract.**
>
> The director authorised option A of HARPP #100 (extend scope to
> `kernel/Capabilities/CapabilityAuthorizationRegistry.php` and add a guarded declaration-successor
> reconciliation API). The authority pre-flight refuses that path as an **absolute prohibition**:
>
> ```
> BLOCK  kernel/Capabilities/CapabilityAuthorizationRegistry.php  [L4]
>        path trips an absolute prohibition: auth, authorisation, policy or security weakening
>        (no justification can authorise it)
> ```
>
> Per `.github/instructions/ai-autonomy-escalation.instructions.md`, an absolute prohibition is one
> **"no contract can authorise ... and no escalation can obtain permission"**. So a director decision
> cannot unlock this path, and the harness may not take it. This is **CD-55** — *"does the absolute
> prohibition cover additive, non-weakening changes to the capability registry?"* — occurring again,
> now with a concrete instance that proves it is load-bearing rather than theoretical.
>
> The scope below is retained because it records the authorised design. **It is not executable.**
> The lawful alternatives are tracked in `.ai/cd-100-option-a-blocked.md`.

project: akira-completion · status: **BLOCKED — absolute prohibition** · revision: 2
milestone: 1 · phase: P1 (quality floor — the last admin surface outside the shell)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "theme-studio-policy-seed-sol", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/projects/akira-cms-completion/project.md` (ACTIVE) — P1, "Theme Studio renders without the sidebar".

## Objective

**Theme Studio must render inside the shared shell on tenant 54, and the browser suite must go 11/11.**

The code is believed to be already correct; the *data* is stale. Measured on 2026-09-15:

```
source            modules/cms-akira/cms-akira-shell/helpers.php:48
                  caller_module = cms-akira-shell,cms-akira-seo,cms-akira-navigation,cms-akira-theme

live tenant row   capability_authorization_policies, tenant 54 ("akira")
                  policy_version 30 · akira.shell.admin_page@1 · is_active 1
                  caller_module = cms-akira-shell,cms-akira-seo,cms-akira-navigation   # theme ABSENT
```

`cms-akira-theme` declares the dependency (`cms-akira-theme/module.json:122`) and calls the capability
(`cms-akira-theme/helpers.php:1390`), so fail-closed dispatch refuses it and the surface degrades to its
chrome-free fallback — which the fallback itself announces with `role="alert"`.

The red test proving it is already in the tree:

```
$ npx playwright test tests/browser/akira-admin-shell.spec.ts
  Error: expect(locator).toBeVisible() failed
  Locator: getByRole('navigation', { name: 'Akira administration' })
  Error: element(s) not found            # both 900px and 600px
```

That spec builds its route list from every sidebar destination **plus a hardcoded `/cms-akira-theme`**.

**The mechanism is already proven — do not re-diagnose it.** HARPP #100 (option A, authorised by the director
2026-09-15) established the following, and the previous attempt reverted every experimental change:

1. **Load order is not the cause.** `discoverModules()` reports `cms-akira-core` at index 2 and `cms-akira-shell` at
   index 12; a full enabled-module load printed `loaded:cms-akira-core core_after=1` before `cms-akira-shell`, so
   `cacActivePolicyVersion()` already exists when the shell helper self-invokes.
2. **`seedPolicy()` is not insert-only.** It prepares and executes updates for *narrowing* changes, but explicitly
   detects *widening* and refuses it, logging `widening_refused` with reason `operator_regrant_required`. Existing
   lifecycle tests call this the ratified **D-Q4** rule. It is therefore a **deliberate authority guard**, not a bug.
3. The tenant-54 v30 row is granted and is exactly the historical three-caller declaration.
4. A bounded shell-local reconciliation was rejected by KernelPDO (`Blocked direct module DB escalation request`,
   `ModuleDB DENIED ... capability_authorization_policies`). A module-local SQL bypass violates table ownership and
   is **not** a fix.

**The authorised change (option A): a declaration-successor reconciliation API.** Add it to
`kernel/Capabilities/CapabilityAuthorizationRegistry.php` as a **sibling of `seedPolicy()`** — not a change to it. It
must:

- match only the **exact previously granted** declaration and the **exact unchanged governed fields**;
- update only to the **exact committed successor** declaration;
- **preserve** suspended, revoked or operator-modified rows — never resurrect or overwrite them;
- be **audited**, and covered by lifecycle + shell tests;
- leave `seedPolicy()`'s generic widening refusal **fail-closed and unchanged**.

The fix must make **every** tenant converge with the committed source. A test that passes only because tenant 54's
row was edited by hand is not a fix.

## Files likely affected

- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php`
- `modules/cms-akira/cms-akira-core/helpers.php`
- `kernel/Capabilities/CapabilityAuthorizationRegistry.php`

## Forbidden changes

- `kernel/Workbench/`
- `tools/`
- `phpstan-baseline.neon`
- `tests/browser/auth.setup.ts`

## Architectural constraints

- **Do not fix this by editing the tenant database.** A hand-edited row proves nothing and leaves every other tenant
  broken. Fix the mechanism by which the committed seed reaches a tenant.
- **Do not weaken authority to make the test pass.** Adding `cms-akira-theme` to the allowlist is legitimate because
  the committed source already declares it; widening roles, disabling `provider_activation_required`, or making the
  registry permissive is not, and will be rejected.
- **Do not weaken the spec.** `/cms-akira-theme` must remain in `akira-admin-shell.spec.ts`'s route list. Deleting the
  entry, skipping the route, or narrowing the assertion is a failure, not a fix.
- Never disable, skip, or delete an existing test to obtain a pass.
- **Leave `seedPolicy()`'s generic widening refusal fail-closed and unchanged.** The new API is a *sibling*, not a
  relaxation of the ratified D-Q4 rule. If the only way to satisfy the acceptance criteria is to weaken that generic
  refusal, **stop and say so** rather than doing it.
- **Preserve operator intent.** A suspended, revoked or operator-modified row must never be resurrected or
  overwritten by reconciliation.

## Required tests

```
npx playwright test tests/browser/akira-admin-shell.spec.ts     # must be 2 passed
npx playwright test                                             # must be 11 passed
php modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php
```

Report exact counts. A skip is not a pass.

## Acceptance criteria

1. Both viewports of `akira-admin-shell.spec.ts` pass, with `/cms-akira-theme` still asserted.
2. The full suite is `11 passed`.
3. The tenant-54 row admits `cms-akira-theme` **because the committed seed caused it**, and you state the mechanism
   you fixed and the evidence that it now reaches a tenant.
4. Every changed file passes `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <file>`.
5. A lifecycle test proves `seedPolicy()`'s generic widening refusal is unchanged and still fails closed.
6. A lifecycle test proves reconciliation leaves a suspended, revoked or operator-modified row untouched.

## Risks

- **Fixing the row instead of the mechanism.** The fast path is to `UPDATE` the tenant row directly. That makes tenant
  54 pass while every other tenant stays broken, and will be rejected at review.
- **Weakening the assertion.** The tempting way to green the suite is to drop `/cms-akira-theme` from the spec's route
  list. The contract forbids it; asserting that surface is the spec's entire purpose.
- **Both hypotheses may be wrong.** If neither candidate explains the staleness, report the mechanism you actually
  found rather than changing something adjacent and declaring victory.
- **Blast radius on authority.** `seedPolicyForCurrentScope()` is shared by every module that seeds a policy, so
  changing how it writes affects more than the chrome capability. Prefer the smallest change that makes a corrected
  seed converge.
