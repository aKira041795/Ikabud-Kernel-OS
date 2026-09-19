# MILESTONE — Akira Theme Editor usable

system: HARPP v2 · 2026-09-17 · **director-issued**: *"Akira milestone: Theme editor UI adjustments. Current state,
unusable."*

## What the director is asking for, and how I read it

The milestone is stated as an **outcome**, not a list of steps. The order, the decomposition, and the choice of what to
do first belong to the executor — that is the point of the experiment (CD-78): *"complete this milestone"*, not
*"implement item 1"*.

**"Unusable" is read here as three measured defects**, reproduced before writing anything (not assumed):

1. **There is no preview anywhere.** `iframes: []`, no `[data-theme-preview]`, no preview container — so an operator
   cannot see what a colour change does without saving, leaving the page, and looking at the site.
2. **The colour controls render no visible value.** 22 `input[type=color]` fields carry correct values in the DOM
   (`#005c55`, `#0f766e`, …) but changing a value **does not repaint the control** — measured, not eyeballed: a
   `locator.screenshot()` before and after a value change is byte-identical for every sampled field. The operator sees
   a box that shows nothing.
3. **There is no way to abandon changes.** Controls are *Save customization* and *Activate*; nothing discards.

If the director meant something narrower or broader, this reading is the thing to correct — it is written down so it
can be overruled cheaply rather than discovered later.

## The milestone's requirements are DATA

`tools/harpp2/projects/akira-theme-editor.json` — six requirements, each with its own gate command and its recorded
`measured_before`. **The machine exhausts them; the verdict is per requirement.** Requirements R4 and R6 already pass
and are **regression guards**: keep them passing, do not "fix" what is not broken.

## Acceptance — the milestone's own gates

```
$ npx playwright test tests/browser/akira-theme-editor.spec.ts   # R1-R6, one test each
$ php tests/theme_read_authority_test.php                        # the theme's authority invariant
```

The project-level verdict — including `composer test` — comes from the milestone gate, and is the only thing that may
declare the milestone complete:

```
$ tools/harpp2/e2e.sh akira-theme-editor
```

## Red baseline (measured at authoring time, 2026-09-17)

```
R1 preview      FAIL  no preview surface exists
R2 legibility   FAIL  the control renders no visible value (8 of 8 sampled fields)
R3 liveness     FAIL  nothing to update
R4 persistence  PASS  regression guard
R5 recovery     FAIL  no discard control
R6 integrity    PASS  regression guard
=> 4 failed, 2 passed
```

## Scope

- `modules/cms-akira/cms-akira-theme/`
- `modules/cms-akira/cms-akira-shell/`
- `templates/`
- `public/`
- `tests/browser/akira-theme-editor.spec.ts`

## Boundaries

- **`tests/browser/akira-theme-editor.spec.ts` is the chair's evidence.** You may ADD assertions. You may not remove,
  skip, or weaken one. The guard now compares assertion **sets and bounds** rather than lines (L8), so a
  reorganisation is fine and a deletion or a loosened threshold is not.
- Do not break the public site: the active theme must stay valid and render. Previewing must not change what the front
  end serves unless a save is made.
- No new dependency, no CDN, no bundler, no build step, no schema change, no migration. `php -l` clean; every `.disyl`
  passes `php _lint_disyl.php`.
- Do not weaken auth, authority, or a check to obtain a pass. If a requirement cannot be met honestly, say so and stop;
  that is a finding, not a failure.
