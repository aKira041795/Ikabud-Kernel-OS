# Contract — finish the `akira-editorial` theme

status: READY_FOR_IMPLEMENTATION
role: /implement
authority: chair, 2026-09-13

---

## Context

A new ARK theme `storage/cms-themes/akira-editorial` was built from an approved design. The
**homepage is done and verified** — do not rework it:

```
theme:validate akira-editorial  →  ✓ All checks passed (0 errors, 0 warnings)
render probe entity.list.post   →  2509 bytes; card ✓ hero ✓ badge ✓ count ✓; no unresolved tags
DiSyL lint                      →  ✓ 96 files valid
```

Already built: `tokens.json`, `theme.manifest.json`, `layouts/public.disyl` (full plain-CSS design
system), `templates/regions/{header,footer}.disyl`, `entity-views/post-list.disyl`,
`blocks/{hero,cta}.disyl`, `block-definitions.json`.

## Problem

Three blocks were **inherited from the previous theme and never restyled**. They still carry the old
`akira-ark-*` class names, which the new stylesheet does not define, so they render unstyled:

- `blocks/card-grid.disyl`
- `blocks/quote.disyl`
- `blocks/richtext.disyl`

`entity-views/composition.disyl` may have the same problem — check it and treat it the same way.

## Scope

allowed:
- `storage/cms-themes/akira-editorial/blocks/card-grid.disyl`
- `storage/cms-themes/akira-editorial/blocks/quote.disyl`
- `storage/cms-themes/akira-editorial/blocks/richtext.disyl`
- `storage/cms-themes/akira-editorial/entity-views/composition.disyl` (only if it carries stale classes)
- `storage/cms-themes/akira-editorial/layouts/public.disyl` — **additive CSS only**, to support the
  restyled blocks. Do not restructure or restyle the existing sections.

prohibited:
- `entity-views/post-list.disyl` — the approved homepage layout. Do not touch it.
- `entity-views/post-detail.disyl` — the article section is a separate, later slice.
- `theme.manifest.json`, `tokens.json`, `renderer-registry.json`, `entity-view-map.json`,
  `slots.json`, `safety-policy.json` — the theme contract. Preserve every `context_keys` entry.
- `block-definitions.json` prop names/types — the block catalogue is a content contract; existing
  compositions depend on it. Defaults may be left as they are.
- ANY change to tenant or database state. The live site is served from tenant 54; do not install,
  activate, migrate, or seed anything.
- `src/helpers/security.php` (CSP) — webfonts are a separate decision, not part of this slice.

## Requirements

1. **Match the existing design system.** Reuse the CSS custom properties already declared in
   `layouts/public.disyl` (`--color-primary`, `--color-surface`, `--font-serif`, `--font-ui`,
   `--radius-*`, `--shadow-*`, `--container`, `--reading`). Do **not** introduce new palette values,
   and do **not** add a second font stack.
2. **No `akira-ark-` residue in the blocks you restyle.** After the change,
   `grep -rn "akira-ark-" storage/cms-themes/akira-editorial/blocks storage/cms-themes/akira-editorial/entity-views/composition.disyl`
   must return **nothing**.

   **CAREFUL — this exception is deliberate:** `entity-views/post-detail.disyl` is the *inherited*
   article view and legitimately still uses `akira-ark-article` / `akira-ark-kicker` /
   `akira-ark-body`. The matching rules at the end of `layouts/public.disyl` are an **intentional
   bridge** so the article page renders coherently until its own section is built. **Do NOT delete
   those rules and do NOT restyle `post-detail.disyl`** — the article section is a later slice.
   An earlier draft of this contract contradicted itself on this point; this is the resolution.
3. **Declarative only.** No `<script>`, no CDN, no inline event handlers, no external stylesheet
   links. Plain HTML + CSS custom properties, same as the rest of the theme.
4. **Guard optional props.** Every `{props.*}` must be wrapped so a missing value renders nothing
   rather than empty markup (follow `blocks/hero.disyl`).
5. **Accessibility**: semantic elements (`<figure>`/`<figcaption>` for quote attribution, real
   headings for richtext), and keep contrast at WCAG AA against the surface colours.
6. **Responsive**: usable at 360px, 768px and 1120px.

## Acceptance

1. `php ikabud theme:validate akira-editorial` → **✓ All checks passed**, 0 errors and **0 warnings**.
2. `php _lint_disyl.php storage/cms-themes/akira-editorial` → all templates valid.
3. No `akira-ark-` references anywhere in the theme.
4. Each restyled block renders with props and produces **no unresolved DiSyL tags** — verify with a
   render probe (below), not by reading the file.
5. `php scripts/run-tests.php` → **no regression versus 139 files — 107 passed / 0 failed / 32 skipped**.

## Verification (exact commands — two traps here)

- **`php -l` on a `.disyl` file is MEANINGLESS** — it is parsed as inline HTML and reports success for
  broken templates. Always use: `php _lint_disyl.php <dir>`.
- The theme validator is `php ikabud theme:validate akira-editorial`.
- A **context comment must be exact**: `{# Context: posts #}`. Trailing prose on that line makes the
  validator report "missing declared context key". Keep extra notes on their own `{# ... #}` line.
- Render probe pattern (proves output, not just lint). Note the ARK registry
  (`renderer-registry.json`) only covers **entity views** (`entity.list.post`, `entity.detail.post`,
  `entity.detail.composition`) — **blocks are not in it**. Render a block template directly instead:

```php
require 'bootstrap.php';
// Entity view (registry path):
$html = app()->arkRenderers()->render('entity.list.post', ['posts' => [/* ... */]], 'akira-editorial');
// Block (direct template path): give the engine the block template + a `props` context.
// Confirm the exact helper by reading how block renderers are invoked before assuming one.
// In every case assert: non-empty, expected classes present, and NO /\{[a-z]/ leftover tags.
```

## Risks

- Importing a palette value not declared in the layout will silently do nothing (undefined custom
  property) — check the property exists before using it.
- Changing a block's `context_keys` breaks compositions that already reference it.
