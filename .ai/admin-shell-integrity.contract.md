# SLICE — Admin shell integrity: fix the defects a browser sees, and build the tier that would have seen them

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: D (admin surface) + verification tier PW-2
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "admin-shell-integrity", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/akira-completion-plan.md`, **APPROVED 2026-09-15**. The director's report, verbatim:
*"It did not detect, thru playwright, that the theme studio view did not have a sidebar. that the sign out is wedged
between compositions and permissions."*

## Objective

**Two measured defects, and the missing verification tier that let both survive.**

**Defect 1 — Sign out collides with nav items.** Measured live on tenant 54:

```
signOutBox.y                          = 517
nav link "Compositions"  y=484, h=48  -> spans 484-532
nav link "Permissions"   y=532
navLinksVisuallyCollidingWithSignOut  = [Compositions, Permissions]
```

The sidebar has **20** nav links. In `akiraShellPage()` (`cms-akira-shell/helpers.php:430`) the `aside` is
`fixed inset-y-0 ... p-5`, and the Sign out block is a **sibling `div` with `absolute bottom-5`**. It therefore
anchors to the *viewport* bottom and lands **inside** the nav list instead of below it. It must be a **flex child in
normal flow**, not absolutely positioned.

**Defect 2 — `/cms-akira-theme` has no sidebar.** It renders `11,842` bytes of its own chrome from
`cms-akira-theme/helpers.php:1344` (`<!doctype html>`, own nav) instead of the shared shell. Two sibling pages were
converted already (`cms-akira-seo` 2,094 → 6,647 B, `cms-akira-navigation` 3,102 → 7,531 B) via the
`akira.shell.admin_page@1` capability. This is the third and last offender.

**The missing tier, and the reason this slice exists.** Both defects were invisible to `200 OK`, byte counts and
property assertions — which is all the verification this programme had. There is **no browser journey** asserting the
admin surface is structurally sound. Build it.

## What to build

### A. Fix the sidebar layout so Sign out cannot collide

In `akiraShellPage()`: make the `aside` a **flex column**, put the nav in a **scrollable middle**
(`flex-1 overflow-y-auto`), and place the Sign out block **in normal flow at the end** (`mt-auto` or equivalent).
Remove the `absolute` positioning.

It must stay usable at short viewports: the nav scrolls, Sign out stays visible and never overlaps a link.

### B. Render `/cms-akira-theme` inside the shared shell

Convert it to the existing pattern: render its content as a **fragment** and wrap it via
`akira.shell.admin_page@1`, exactly as `cms-akira-seo` and `cms-akira-navigation` now do. Pass the correct `active`
key so the sidebar highlights Theme Studio. Preserve every existing control, form field and attribute — this is a
chrome change, not a behaviour change. If its policy seed needs the theme module added as a caller, do it at the
**resolved active version** via `cacActivePolicyVersion()` — never a pinned literal.

### C. The missing verification tier — a browser journey over the whole admin surface

Add `tests/browser/akira-admin-shell.spec.ts`. It must:

1. **Log in ONCE** for the run (the kernel auth limiter is 5 attempts / 300s — a per-step login trips it), using
   credentials from the environment or an existing spec's convention. Do **not** hardcode placeholder credentials.
2. Visit **every** admin route the sidebar links to, plus `/cms-akira-theme`.
3. On **each** page assert the shell invariants:
   - the shared sidebar is present (`nav[aria-label="Akira administration"]`);
   - **no nav link's bounding box intersects the Sign out link's bounding box** — this is the assertion that was
     missing and it is the point of the slice;
   - "Sign out" is the **last** link in DOM order within the sidebar;
   - exactly **one** `cdn.tailwindcss.com` script tag (no per-page duplicate);
   - the **active** nav item corresponds to the visited route.
4. Capture a screenshot per page into `test-results/` so a human can look at the product.
5. Use resilient locators (`getByRole`, `getByLabel`) and **no** `waitForTimeout` for synchronisation.

### D. Prove the instrument works — falsification is required

The spec **must fail** against the pre-fix layout and **pass** after the fix. Demonstrate it: revert or stub the
layout fix temporarily, run the spec, and record the failure output naming the colliding links. Then restore and show
it pass. **A verification that passes both before and after proves nothing** — that is exactly how these two defects
survived.

## Architectural constraints

One chrome. Reuse `akiraShellPage()` through `akira.shell.admin_page@1`; do not build a second page builder and do
not copy the navigation list (it has two sources: an inline base list **plus** the contribution registry via
`kernelContributionsForHostLocation('cms-akira-shell','sidebar',...)`).

Layout fixes are presentation structure only. Do not change any route, capability, policy or authorisation behaviour,
beyond adding the theme module as a caller of the shell page capability if required.

`modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` must pass **UNMODIFIED**, 116 passed / 0 failed.

Do not edit any existing test file. Add new ones only.

`php -l` is **meaningless on a `.disyl` file** — use `php _lint_disyl.php <file>`.

PHP 8.2-compatible syntax. No new runtime dependency. CI must stay **headless** — `PW_HEADED=1` is an opt-in for
human viewing only.

## Files likely affected

- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/handlers.php`
- `modules/cms-akira/cms-akira-shell/module.json`
- `modules/cms-akira/cms-akira-theme/helpers.php`
- `modules/cms-akira/cms-akira-theme/module.json`
- `modules/cms-akira/cms-akira-theme/templates/admin.disyl`
- `tests/browser/akira-admin-shell.spec.ts`

## Acceptance criteria

1. On every admin route, **no nav link's bounding box intersects the Sign out link's box** — asserted by the new spec,
   at more than one viewport height.
2. Sign out remains visible and reachable while the nav scrolls at a short viewport (~600px tall).
3. `/cms-akira-theme` contains the shared sidebar, and its controls and form fields are preserved.
4. The spec visits every sidebar destination and asserts all five invariants on each, with a screenshot per page.
5. The spec **fails** on the pre-fix layout and **passes** after — both outputs recorded.
6. `php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` passes **unmodified**.
7. `php -l` reports no syntax errors on every changed PHP file, and `php _lint_disyl.php` passes on every changed
   template.

## Required tests

Report each command on its own line, prefixed `$ `, with its result lines beneath it, **unchained**.

```
$ npx playwright test tests/browser/akira-admin-shell.spec.ts
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php
$ php -l modules/cms-akira/cms-akira-shell/helpers.php
$ php -l modules/cms-akira/cms-akira-theme/helpers.php
$ php ikabud theme:validate akira-editorial
$ php ikabud workbench:governance --all --json
```

For the falsification in criterion 5, report the pre-fix run and the post-fix run separately, each with its own
result lines. Report a command you did not run as **not run**.

## Risks

- **The login limiter.** 5 attempts / 300s. Log in once; do not retry blindly, and do not leave the tenant locked for
  the director.
- **A spec that passes for the wrong reason.** `toHaveCount(0)` on a selector that matches nothing always passes.
  Assert on the real element, and prove falsification.
- **Screenshot noise in CI.** Keep CI headless and write screenshots under `test-results/`.
- **The 20-link sidebar is itself a design problem** — a flat list of everything. Do not fix that here; record it as
  an observation for the director rather than inventing an information architecture.

## Forbidden changes

- `kernel/`
- `src/`
- `tools/`
- `storage/cms-themes/`
- `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`
- `.github/workflows/`
- `phpstan-baseline.neon`
- `.governance-baseline.json`
- `docs/architecture/`
- `.ai/akira-master-plan.md`
