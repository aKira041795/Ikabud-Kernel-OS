# TASK CONTRACT — DiSyL compiled-mode `<style>` CSS-rule misparse (null - string)

## Task
Fix the DiSyL v4 Parser so that CSS rule braces inside a `<style>...</style>` element body are emitted as
raw text in COMPILED mode, while scalar-value interpolations (`{gui.color_primary}`, `{gui.font_family | raw}`)
still resolve. Currently compiled-mode misparses CSS as DiSyL expressions:
`*{box-sizing:border-box}` compiles to `($ctx->get('box') - 'sizing:border') - $ctx->get('box')` →
`Unsupported operand types: null - string` at render → compiled render throws → engine falls back to the
legacy interpreted pipeline (deprecated warning + ~1s slow_request). Reproduced on tenant 54 anon GET `/`
and `/posts` (theme `storage/cms-themes/akira-ark/layouts/public.disyl`, which contains a large inline
`<style>` block of CSS rules).

## Scope
ALLOWED (only these edits):
- `kernel/DiSyL/v4/Parser.php` — add a lexical `<style>`/`<script>`/`<textarea>` region state so that, inside
  such an element body, a `{...}` is parsed as a DiSyL expression ONLY when its trimmed content is a
  processable template expression AND contains NO unquoted `:` and NO `;` and NO nested `{`.
  Otherwise emit it as raw text (preserve the bytes verbatim, including the `{` and `}`).
- Add/extend a parser regression test under `kernel/DiSyL/` tests or `tests/` (follow existing DiSyL test
  conventions in the repo — check how Parser/v4 is tested today) covering BOTH cases:
  1. `<style>` CSS with `{}`, hyphens, colons, semicolons, `var(--x)`, `:root{...}` → compiled output
     byte-identical CSS (no subtraction/ternary mangling, no throw).
  2. `<style>` containing `{gui.color_primary}` / `{gui.font_family | raw}` → still interpolates.
  3. `<script>` JS object literal `{a:1}` (if you add script handling) stays raw; `${...}` template literals stay raw.

FORBIDDEN:
- Do NOT change any template files (`storage/cms-themes/akira-ark/**`, `templates/**`, `modules/**`).
- Do NOT blanket-raw `<style>` (breaks `{gui.color_primary}` used by login/reset/forgot/app layouts).
- Do NOT touch inline `style="color:{x}"` attribute interpolation (different code path — must keep working).
- Do NOT touch the interpreted/legacy pipeline.
- Do NOT weaken escape/`|raw` behavior. Do NOT commit/PR/branch — leave working tree changes only.

## Constraints
- MySQL/compat n/a. Preserve PHP 8.5 style of the file. Keep diff minimal and surgical.
- `{verbatim}`/`{literal}` and top-level (non-style) behavior must be unchanged.

## Acceptance (verify all)
1. New regression test passes.
2. Existing DiSyL test suite passes (run the targeted DiSyL tests; then the full `composer test` shell ONLY if quick —
   note: full composer test clobbers `storage/modules.json`; if you run it, restore modules.json after:
   gui-settings ON, all `cms-akira-*` ON, daily-ledger OFF).
3. HTTP repro: `curl -s -H "Host: akiracms.test" http://127.0.0.1/` and `/posts` render HTTP 200 with
   `akira-ark-card`, AND `storage/logs/app.log` shows NO new `disyl.strict.Compiled render failed` /
   `disyl.compile.fallback` / `disyl.interpreted.deprecated` lines and no `slow_request` > ~400ms for those URIs.
   Clear the relevant lines baseline before testing. The CSS `<style>` block in the HTML must be byte-identical CSS.
4. No compile errors: `php -l kernel/DiSyL/v4/Parser.php`.

## Result format (return in final message)
```
status: PASS|FAIL|PARTIAL|BLOCKED
changed: <files>
implementation_summary: <how the region state was added + the exact disambiguation rule>
verification:
  regression_test: <cmd + result>
  disyl_tests: <cmd + result>
  http_home: <status + time>
  http_posts: <status + time>
  log_after: <grep result — no new fallback/slow lines>
scope:
  unexpected_files:
risks:
unresolved:
```
Stop and escalate (do NOT continue editing) if: repeated attempts fail, or the fix needs to change template
files or more than the two allowed paths, or full test suite reveals unrelated regressions.
