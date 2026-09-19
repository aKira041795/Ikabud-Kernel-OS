# SLICE — Theme options: give the theme a real design surface, each control with a consumer

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: C (editorial theme)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "theme-options", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/akira-completion-plan.md`, **APPROVED 2026-09-15 by the director**. Phase C. The director's own
report: *"Theme options lack many options and features."*

## Objective

**The diagnosis, measured — and it is not what the complaint implies.** The customizer has **23 controls** and the
theme has **21 design tokens**, and *every token is already exposed*: `design_colors` 15, `design_typography` 2,
`design_layout` 4, plus `header.brand` and `footer.message`.

**So the customizer is not thin — the theme's design surface is.** The gap is that `akira-editorial` has only 21
design values to expose:

- **Typography** is two font-family strings. No base size, no heading scale, no line height, no letter spacing.
  The layout hardcodes sizes in CSS (`clamp(36px,6vw,56px)` and relatives).
- **Colours** are 15 generic slots. There is no link colour, heading colour, button background/text/hover, form-field
  border/background/focus, or code-block surface — so a site owner cannot theme the controls they actually see.
- **Components**: only card radius and page spacing. No button radius/padding, card shadow or border, header height.
- **Control types**: the schema uses only `text` and `color`. A numeric option (size, spacing, radius, height) cannot
  be expressed properly, so no numeric option exists.

**Deliver: a real design surface, with every control consumed by something.**

## The rule that governs this entire slice

**Every control you add must have a named render-time consumer, and you must state it.**

A setting with no consumer is a **defect, not a feature**. This was learned in this programme already: P3.1b added a
`maintenance_mode` constant with no consumer, no default and no validation, and it was **reverted** as the exact
defect that slice forbade. A control that renders in the panel and changes nothing is worse than no control, because
it silently lies to the operator.

For **each** control you add, the report must name the file and the CSS rule or template expression that consumes it.

## What to build

1. **Typography scale.** Add tokens and controls for base font size, a heading scale (ratio or explicit sizes), body
   line height, and heading letter spacing. Wire each into the layout CSS so the existing headings and body text
   actually respond. The layout's hardcoded sizes must give way to the tokens for the properties you cover.

2. **Component colours.** Add tokens and controls for at least: link colour, heading colour, button background,
   button text, button hover background, form-field border, form-field focus, and code/surface-container. Consume
   each in the layout CSS where that component is styled. Do not add a token you cannot point at a rule for.

3. **Component/metric knobs.** Add numeric controls for button radius, button padding, card shadow (a select of
   named steps is acceptable), and header height. Consume each.

4. **Control type support — verify first, then extend only if needed.** Determine which control types the theme
   admin renderer actually supports by reading it (`modules/cms-akira/cms-akira-theme/handlers.php` and the
   customizer orchestration), and **state what you found**. Numeric controls need a real numeric input: if `number`
   is unsupported, add it, with server-side validation of the accepted range and a stated rejection behaviour.
   **Never** render a numeric control as free text and hope.

5. **Defaults must be complete.** Every new token appears in `tokens.json` with a default equal to the value the
   theme renders today, so the theme's appearance does not change until an operator changes something. This is the
   acceptance criterion that stops a "feature" from silently re-designing the live site.

## The safety invariant — the one thing that matters

**The live theme's appearance must not change.** After this slice, with no customizer values saved,
`/` on tenant 54 must render the same visual result as before: same colours, same sizes. Every new token's default
is today's value. If any pixel moves, you have changed the design, not added an option.

**And the customizer must still actually reach the render.** Values are merged into tokens in the public render path
(`cms-akira-shell/helpers.php` around the customizer merge). Confirm the new tokens flow through that path — a token
that exists but is never merged is the same lie as a control with no consumer.

## Architectural constraints

The theme is declarative data plus DiSyL templates. **No PHP may be added to the theme.** Theme files live under
`storage/cms-themes/akira-editorial/` and are tracked code.

One token, one consumer. Do not add "reserved for future use" tokens.

Do not change the theme's structure: no new layouts, no renderer-registry changes, no new entity views. This slice
widens the design surface; it does not restructure the theme.

`php -l` is **meaningless on a `.disyl` file** — use `php _lint_disyl.php <file>` for every template you change.

Do not edit any existing test file. Add new tests only.

Do not touch `kernel/`, `src/`, `tools/`, `cms-akira-shell/`, or any other module. If a change is genuinely needed
there, **stop and report** rather than reaching outside the envelope.

PHP 8.2-compatible syntax. No new runtime dependency.

## Files likely affected

- `storage/cms-themes/akira-editorial/tokens.json`
- `storage/cms-themes/akira-editorial/customizer.schema.json`
- `storage/cms-themes/akira-editorial/layouts/public.disyl`
- `modules/cms-akira/cms-akira-theme/handlers.php`
- `modules/cms-akira/cms-akira-theme/helpers.php`
- `modules/cms-akira/cms-akira-theme/tests/theme_options_test.php`

## Acceptance criteria

1. **Control count rises substantially** above 23, and every single control is reachable in the theme admin panel.
2. Every token added to `tokens.json` appears in the rendered CSS as a custom property on the public layout.
3. Every new control has a **named consumer**, stated in the report as file + rule/expression. No orphans.
4. Every new token's default equals the value the theme renders today, so an untouched site is visually unchanged.
5. Numeric controls use a real numeric input, with server-side range validation and a stated rejection behaviour.
6. All three shipped themes still pass `php ikabud theme:validate <slug>`.
7. `php _lint_disyl.php storage/cms-themes/akira-editorial/layouts/public.disyl` passes.
8. `php -l` reports no syntax errors on every changed PHP file.

## Required tests

Report each command on its own line, prefixed `$ `, with its result lines beneath it, **unchained**.

```
$ php modules/cms-akira/cms-akira-theme/tests/theme_options_test.php
$ php modules/cms-akira/cms-akira-theme/tests/theme_contract_test.php
$ php ikabud theme:validate akira-editorial
$ php _lint_disyl.php storage/cms-themes/akira-editorial/layouts/public.disyl
$ php -l modules/cms-akira/cms-akira-theme/handlers.php
```

A module test that reaches the application database must call `requireNotLiveTenantDatabase()`, which makes it SKIP
here — **a SKIP is not a pass**, so state which assertions actually ran.

Report a command you did not run as **not run**. Never claim a command you did not execute.

## Risks

- **Silently redesigning the live site.** The default-equals-today rule exists for this and nothing else. Verify it
  by rendering before and after with no saved values.
- **Orphan controls.** The most likely failure in this slice, and the reason criterion 3 exists.
- **A token that never reaches CSS.** The merge path may key on a naming convention (`color_primary` →
  `--color-primary`). Read it and follow it exactly; do not invent a second convention.
- **Range validation missing on numerics.** An unbounded `font-size` accepts `99999px` and breaks the site.
- **The theme-validation gate may reject new content.** `catThemeValidate()` now checks declared entity-view fields
  against the registered contract; if your token work touches anything it validates, confirm it still passes.

## Forbidden changes

- `kernel/`
- `src/`
- `tools/`
- `modules/cms-akira/cms-akira-shell/`
- `tests/`
- `storage/cms-themes/akira-ark/`
- `storage/cms-themes/akira-ark-demo/`
- `.github/workflows/`
- `phpstan-baseline.neon`
- `.governance-baseline.json`
- `docs/architecture/`
- `.ai/akira-master-plan.md`
