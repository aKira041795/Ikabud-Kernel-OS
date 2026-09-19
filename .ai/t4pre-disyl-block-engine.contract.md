# T4-PRE CONTRACT — DiSyL block-engine readiness (ADR gate b): document gaps + close them at the ENGINE ROOT

## Why
Extensibility ADR (PR #95) gates the page builder (T4) on: (a) block-schema contract [DONE: T2/T3], (b) a
DOCUMENTED DiSyL block-engine gap list CLOSED AT THE ENGINE ROOT, (c) user direction [GRANTED]. This slice
delivers (b). It does NOT build the builder.

The operating directive is explicit: "if DiSyL doesn't support it, fix DiSyL, not the template" — engine root,
never template bandaids. Panel C's warning: the builder secretly budgets engine work; price it now.

## What T4 will need the engine to do (assess each empirically)
1. **Dynamic per-block template resolution** — a composition has N sections, each with a block id whose
   renderer template comes from data (theme `block-definitions.json` → `renderer.template`, e.g.
   `blocks/hero.disyl`). Today `{include "..."}` takes a STATIC quoted path (v4 `parseIncludeTag`), so
   selecting a template per block requires an `{if}` ladder = template bandaid. NEED: engine-supported
   dynamic render (e.g. `{include $templateVar}` / `{render <expr> with <ctx>}` / dynamic component).
2. **Passing a whole context object** (the block props array) into the rendered template — today include
   params are `key=value` pairs; need to pass an array/map wholesale (e.g. `props` accessible as
   `{props.title}`, `{foreach props.items as item}`).
3. **Loops/conditionals over block arrays** — `{foreach sections as section}`, `{if section.children}`,
   `{if props.image}`. (Likely already supported — VERIFY with evidence.)
4. **Nested composition recursion** — a block with children renders each child (recursion with depth
   safety; must not blow MAX_PARSE_DEPTH / infinite-loop). NEED: a depth guard / iterative strategy.
5. **Component/slot mechanism** — what `{component ...}` / `renderComponent($component,$attrs,$children,$ctx)`
   already supports (kernel/DiSyL/TemplateEngine.php:5507 + ComponentRegistry): is it theme-block aware,
   dynamic, and context-passing? Document precisely.
6. **Escaping + safety** — block template output auto-escapes like entity views; `|raw` only where the theme
   already allows it (theme safety-policy.json). No new bypass.
7. **Compiled-mode parity** — everything must work in compiled mode (PR #94 fixed `<style>` raw-text; keep
   that intact).

## Deliverable 1 — documented gap list
Write `docs/kernel/disyl-block-engine-gaps.md`: one row per capability above with
`{capability, needed-by (T4), current status: supported|partial|missing, EVIDENCE (the test template used +
observed output/error), decision: fix-at-engine-root | satisfied-by-design}`. Evidence must be REAL (a
template you actually rendered + its output), not asserted from reading code.

## Deliverable 2 — close the gaps at the engine root
For each `fix-at-engine-root` item, implement the MINIMAL additive change in `kernel/DiSyL/**`
(likely: dynamic template render + explicit array context passing + recursion depth guard), with:
- backwards compatibility (existing static `{include "..."}` semantics unchanged);
- compiled-mode support (do not regress compiled rendering);
- no new template-facing escape hatch that bypasses escaping;
- engine tests under the existing DiSyL test conventions (kernel/DiSyL tests or tests/disyl*_test.php).

## Deliverable 3 — readiness proof (the point of this slice)
A focused test that renders a small composition END-TO-END through the engine using the REAL theme blocks:
`{foreach sections as section}` → dynamic render of that block's template with `props` → a nested/child block
→ a `card-grid` block iterating `props.items`. Assert the produced HTML. This is the T4 feasibility proof:
if it renders, T4 is unblocked; if it cannot, the gap list documents exactly why.
Use the T2 theme assets as the block source: `storage/cms-themes/akira-ark/block-definitions.json` +
`storage/cms-themes/akira-ark/blocks/{hero,richtext,card-grid,quote,cta}.disyl`.

## Constraints
- Scope: `kernel/DiSyL/**`, `docs/kernel/**`, DiSyL tests. NO builder, NO shell/seo/theme module changes,
  NO new tables, NO capability changes. If a non-DiSyL change seems required, STOP → report BLOCKED.
- Preserve all existing DiSyL behaviour: run the DiSyL regression suite (tests/disyl*_test.php + the v4
  compiler test) and keep it green.
- CI gates are MANDATORY before finishing (CI scans kernel/ with phpstan baseline + cs-fixer):
  `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>` (options BEFORE files; no
  --path-mode) and `php vendor/bin/phpstan analyse <paths> --memory-limit=1G`. NEW phpstan errors are NOT
  baselined — the code must be clean. Use MULTILINE @return docblocks for new iterables.
- Do NOT commit; leave for review. `php -l` every touched file.
- AFTER your run (important): clear the web APCu cache (see ops note) because your run may create transient
  fixtures that poison the module-scan cache.

## Ops note (learned this cycle)
`discoverModulesScanAll()` caches the module-manifest scan in APCu (300s). Transient fixture module dirs can
poison it and take tenants offline. If you create any module dirs under a scanned root, remove them, and
clear APCu from a WEB context (temporary `public/_apcu_reset.php` → curl with `Host: akiracms.test` →
`apcu_clear_cache()`; delete the script after). Ideally avoid creating module dirs at all — DiSyL engine work
should not need them.

## Acceptance (verify; report)
1. `docs/kernel/disyl-block-engine-gaps.md` exists with evidence-backed rows for all 7 capabilities.
2. Engine gaps closed at the root (list the exact kernel/DiSyL changes) with tests; existing DiSyL suite green.
3. The readiness proof renders the composed block tree to expected HTML (paste the assertion + result).
4. Compiled-mode parity preserved (run a compiled-mode render if the suite exercises it; note result).
5. php -l clean; cs-fixer 0; phpstan 0 new errors; no non-DiSyL files touched.

## Result format (final message)
status: PASS|FAIL|PARTIAL|BLOCKED
changed:
gap_list: {supported | partial | missing counts}
engine_changes:
verification: {proof_render, disyl_suite, compiled_parity, php_lint, cs_fixer, phpstan}
scope: unexpected_files:
risks:
unresolved:
Stop + escalate on repeated failure or any need to change non-DiSyL code.
