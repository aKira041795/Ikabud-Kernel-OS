# ENGINE REPAIR (P0) — DiSyL interpolation broken inside `<script>` blocks (live regression from PR #94)

## Symptom (reproducible, live on main)
`GET http://akiracms.test/login` returns a page where the JS contains a **literal `{login_endpoint}`**:
```
const response=await fetch('{login_endpoint}',{method:'POST',headers:{'Content-Type':'application/json'},...})
```
Consequence: the login JS POSTs to the literal `{login_endpoint}` → server 500 / "Invalid CSRF token" → **login is
broken for tenant 54**. Template: `templates/modules/cms-akira-shell/pages/login.disyl` line 36 (context key
`login_endpoint` is provided by `modules/cms-akira/cms-akira-shell/helpers.php:131`). Login worked before PR #99
(T4-PRE) which carried the PR #94 `<style>`/`<script>` raw-text handling.

## Root cause (chair diagnosis — verify, then fix at the ENGINE ROOT)
In `kernel/DiSyL/v4/Parser.php` the raw-region handling (`rawTextElement` + `readPlainText` + `findRawTextBraceEnd`)
does this at a `{` inside a raw element that is NOT a DiSyL expression: it **consumes the entire balanced
`{ ... }` block as raw text and jumps past it**. In a `<script>` block the first `{` belongs to a JS
function/object (`function akiraLogin(){return{loading:false,...}}`), so the whole function body is swallowed as
raw text — including nested DiSyL tags such as `{login_endpoint}` — which therefore never evaluate.
The balanced-skip was an over-correction intended only to stop CSS `{}` bodies being parsed as expressions.

**Fix direction:** inside a raw element, when a `{` is not a DiSyL expression, **do not skip the balanced block** —
emit the `{` as plain text and continue scanning one character at a time, so nested DiSyL tags inside the region are
still found and evaluated. Keep the existing discriminator (`looksLikeDisyl()` in raw elements: content must have no
nested `{`, no unquoted `:`, no unquoted `;`, and be a processable expression). Verify this still keeps CSS literal:
`*{box-sizing:border-box}` and `:root{--x:#123}` must render verbatim (the `:` in the content makes them
non-DiSyL → plain text), and the PR #94 regression case (`<style>` with CSS rules no longer breaking COMPILED mode)
must stay fixed. Keep the 20-deep bounded include stacks from PR #99 untouched.

## Required work
1. Fix `kernel/DiSyL/v4/Parser.php` (engine root; no template bandaids — per the operating directive do NOT edit
   `login.disyl` or any template to work around it).
2. **Regression tests** (engine tests, following the existing DiSyL test conventions):
   - `<script>` JS containing an interpolated DiSyL tag inside a quoted string AND inside a nested JS function/object
     → the tag interpolates (e.g. `fetch('{login_endpoint}')` → `fetch('/api/v1/auth/login')`, and a nested-object
     case like `function f(){return{a:1,b:'{v}'}}` → `b:'X'`).
   - CSS still verbatim: `<style>*{box-sizing:border-box}:root{--x:#123}</style>` renders byte-identical, in BOTH
     interpreted and compiled modes (this is the PR #94 guard — must not regress).
   - A `<script>` containing `${...}` JS template literals stays raw (PR #94 guard).
3. **Prove the live fix**: after the change, `GET /login` (Host `akiracms.test`) must contain
   `fetch('/api/v1/auth/login'` (NOT `fetch('{login_endpoint}'`). Then verify a REAL login works over HTTP:
   POST `/api/v1/auth/login` with a valid session cookie + CSRF token and the tenant admin credentials
   (`charlienacario884` / `iKabud6123!#`) — expected a JSON body with `ok:true` and a `redirect`. Report the
   status/body. (If you cannot obtain the CSRF token shape, at minimum prove the endpoint now receives the correct
   URL and report exactly what you verified vs not.)
4. Run the full DiSyL suite by **exit code** (`tests/disyl*_test.php` — 27 files) plus
   `tests/disyl_block_engine_readiness_test.php` (the T4-PRE proof) — all must pass.
5. CI gates: `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>` (options BEFORE files, no
   `--path-mode`); `php vendor/bin/phpstan analyse kernel/DiSyL --memory-limit=1G` (0 new errors; MULTILINE
   docblocks); `php -l` on touched files.

## Constraints
- Scope: `kernel/DiSyL/**` + DiSyL tests ONLY. **Do NOT change templates** (the interpolation must work, not be
  worked around) and do NOT touch modules.
- Clear the stale **compiled template cache** after the change if needed (compiled cache is keyed on source mtime,
  not engine version — `touch`ing templates OR clearing `storage/cache/compiled` forces a rebuild) so the live
  `/login` render uses the fixed engine. Verify the live HTML actually shows the substituted endpoint.
- AFTER the run: clear the web APCu cache (temporary `public/_apcu_reset.php` → curl with `Host: akiracms.test` →
  delete) and verify tenant 54 (`/login` 200 with the substituted endpoint, `/` 200,
  `data-akira-theme="akira-ark"`).
- Do NOT commit; leave changes for review.

## Result format
status: PASS|FAIL|PARTIAL|BLOCKED
engine_change:   (exact change + why it preserves the CSS/PR#94 guards)
regression_tests: {added tests + result}
disyl_suite:     (27-file exit-code result + readiness proof)
live_login:      {before: literal tag present?; after: substituted? + POST /api/v1/auth/login result}
cache_handling:  (how the stale compiled template was rebuilt)
php_lint / cs_fixer / phpstan:
tenant54_health:
unresolved:
This is a P0 live regression — prioritise correctness + proof over speed.
