# SLICE — Admin chrome: every admin page renders inside the one shell chrome

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 3 (CD-57: the two consumer manifests were added after dispatch)
milestone: 1 · phase: D (admin surface)
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "admin-chrome", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/akira-completion-plan.md`, **APPROVED 2026-09-15 by the director**. Phase D. The director's own
report: *"UI for example, other page views does not have the sidebar."*

## Objective

**Measured defect.** Two admin pages render a **complete second HTML document** with a three-link header and their
own Tailwind load, so a user who clicks "SEO metadata" or "Navigation" in the sidebar **loses the sidebar**:

| Page | Bytes | Chrome source |
|---|---|---|
| `/cms-akira-seo` | **2094** | `modules/cms-akira/cms-akira-seo/templates/admin.disyl:1` (`<!doctype html>`) |
| `/cms-akira-navigation` | **3102** | `modules/cms-akira/cms-akira-navigation/templates/admin.disyl:1` (`<!doctype html>`) |

Both are reached identically — `akiraSeoRender()` (`modules/cms-akira/cms-akira-seo/handlers.php:55-57`) does
`casCtx()->render(__DIR__ . '/templates/admin.disyl', $context + …)`.

**The chrome already exists and must not be re-implemented.** `akiraShellPage()` (`cms-akira-shell/helpers.php:430`)
builds the sidebar, the palette, the Alpine config and the layout. Its navigation comes from **two sources**:

1. a base list inline in the function (dashboard, posts, categories, content-types, media, plus admin-only entries);
2. **the contribution registry** — `kernelContributionsForHostLocation('cms-akira-shell', 'sidebar', null,
   kernelContributionRequestContext())` — which is where Theme Studio, Navigation, SEO, Search, Workflow, Modules,
   Authority, Provenance and Redirects come from.

**So the design is: reuse that chrome through the capability bus. Do not build a second chrome, and do not copy the
navigation.**

## What to build

1. **A shell capability `akira.shell.admin_page@1`** exposed by `cms-akira-shell`.
   - Input: `title` (string), `body` (string, already-rendered HTML), `active` (string, the sidebar key).
   - Output: `['html' => …]` — the result of passing those through `akiraShellPage()`.
   - It must be a **thin wrapper over `akiraShellPage()`**, not a second page builder. If it re-implements the
     chrome, this slice has failed.
   - Register it in `module.json` `capabilities.exposes` and in `cms_akira_shell_capability_handlers()`, following
     the module's existing pattern.

2. **Seed its policy, admitting its callers.** Add it to the shell's policy seed with `allowed_roles` set to the
   **admin tier the shell's admin pages already require** (`akiraShellAuthorizeAdmin()`), and `caller_module`
   admitting `cms-akira-shell,cms-akira-seo,cms-akira-navigation`. A declared capability whose `caller_module`
   omits a consumer is denied `disabled_caller` and the consumer silently degrades — that defect has shipped once in
   this suite already.
   **Do not pin a literal `policy_version`.** Use `cacActivePolicyVersion()` from
   `modules/cms-akira/cms-akira-core/helpers.php`. A row at a literal version is invisible on any tenant whose active
   version has advanced, and fail-closed dispatch then refuses every operator with `missing_policy_row`. That
   defect shipped once and must not be repeated.

3. **Convert the two module surfaces.** For `cms-akira-seo` and `cms-akira-navigation`:
   - Their `templates/admin.disyl` becomes a **content fragment**: no `<!doctype html>`, no `<head>`, no `<body>`,
     no `<header>`, no Tailwind `<script>`, no second palette. Keep the title/description text and every form
     exactly as it is.
   - Their render function wraps the rendered fragment:
     `cap()->call('akira.shell.admin_page@1', ['title' => …, 'body' => $fragment, 'active' => …])`, then echoes
     `['html']`.
   - Pass the correct `active` key so the sidebar highlights the current surface. The existing keys come from the
     contributions' `active_key` (`seo`, `navigation` — confirm them; do not guess).
   - Their form markup must be **structurally identical**: same `name`, `action`, `method`, `maxlength`, `pattern`,
     `placeholder`, labels, `required` and error rendering.

4. **`cms-akira-theme` is OUT of scope.** Its chrome is PHP-built at `cms-akira-theme/helpers.php:1344`, a different
   conversion. Leave it alone and **say so in the report** — it is the third known offender and a separate slice.

## The safety invariant — the one thing that matters

**`shell_contract_test.php` must pass UNMODIFIED at 116 passed / 0 failed.** It asserts the shell's navigation
(`:203`). If adding the capability or touching `akiraShellPage()` changes its output, you have broken the shell —
report it, do not adjust the test.

**`akiraShellPage()` must keep producing byte-identical output.** The capability is a new *caller* of it, not a
modification of it. If you must change `akiraShellPage()` at all, say exactly why and show the before/after.

A chrome change that loses a form field, a validation attribute or an error message is a **product regression**, not
a styling change.

## Architectural constraints

Reuse the existing chrome through the capability bus. Never copy the navigation list, and never build a second page
builder — one chrome implementation, three consumers.

Authorisation must be correct in both directions: the capability must be reachable by its module callers
(`caller_module`) and refused to everyone outside the admin tier (`allowed_roles`). Verify the seeded row is
**active** at the tenant's active version before reporting success.

Do not change any handler's routing, policy semantics or authorisation behaviour beyond the new capability.

Do not edit any existing test file. Add new tests only.

`php -l` is **meaningless on a `.disyl` file** — use `php _lint_disyl.php <file>` for every template you change.

PHP 8.2-compatible syntax. No new runtime dependency.

## Files likely affected

- `modules/cms-akira/cms-akira-shell/module.json`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/handlers.php`
- `modules/cms-akira/cms-akira-seo/templates/admin.disyl`
- `modules/cms-akira/cms-akira-seo/handlers.php`
- `modules/cms-akira/cms-akira-seo/module.json`
- `modules/cms-akira/cms-akira-navigation/templates/admin.disyl`
- `modules/cms-akira/cms-akira-navigation/handlers.php`
- `modules/cms-akira/cms-akira-navigation/module.json`
- `modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php`

## Acceptance criteria

1. `akira.shell.admin_page@1` is exposed by `cms-akira-shell`, has a handler in the capability handler map, and is a
   thin wrapper over `akiraShellPage()`.
2. It has an **active** policy row whose `policy_version` is the tenant's active policy version — **not a literal** —
   with `caller_module` admitting `cms-akira-shell,cms-akira-seo,cms-akira-navigation`.
3. Neither `cms-akira-seo/templates/admin.disyl` nor `cms-akira-navigation/templates/admin.disyl` contains
   `<!doctype`, `<head`, `<body`, or a `cdn.tailwindcss.com` script tag.
4. Both pages still render every original form field: same `name`, `action`, `method`, `maxlength`, `pattern`,
   `placeholder`, `required` and label text.
5. `php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` passes **unmodified**, 116 passed / 0 failed.
6. `php _lint_disyl.php` passes on both changed templates.
7. `php -l` reports no syntax errors on every changed PHP file.

## Required tests

Report each command on its own line, prefixed `$ `, with its result lines beneath it, **unchained**.

```
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php
$ php modules/cms-akira/cms-akira-shell/tests/admin_page_capability_test.php
$ php modules/cms-akira/cms-akira-seo/tests/seo_surface_test.php
$ php modules/cms-akira/cms-akira-navigation/tests/navigation_surface_test.php
$ php _lint_disyl.php modules/cms-akira/cms-akira-seo/templates/admin.disyl
$ php _lint_disyl.php modules/cms-akira/cms-akira-navigation/templates/admin.disyl
$ php -l modules/cms-akira/cms-akira-shell/helpers.php
$ php ikabud workbench:governance --all --json
```

A module test that reaches the application database must call `requireNotLiveTenantDatabase()`, which makes it SKIP
here — **a SKIP is not a pass**, so state which assertions actually ran. For the census report only the
`cms-akira-shell` summary object.

Report a command you did not run as **not run**.

## Risks

- **The capability could be denied `disabled_caller`.** If the policy row's `caller_module` omits `cms-akira-seo` or
  `cms-akira-navigation`, both pages break. Check the seeded row before declaring success, and state whether you
  actually confirmed it live.
- **A failure inside the module handler must not blank the page.** Decide and state what happens if the capability
  call throws. Do not reintroduce the hand-rolled chrome as a fallback — that recreates the defect.
- **Chrome is an authorisation surface.** The page is admin-only; confirm the new capability does not become
  reachable by a role that could not already read those pages.

## Forbidden changes

- `modules/cms-akira/cms-akira-theme/`
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
