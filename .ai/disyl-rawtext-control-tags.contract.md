# CONTRACT — DiSyL: control tags in raw-text elements (finish + close the open question)

status: READY_FOR_IMPLEMENTATION
repo: `/var/www/html/ikabudsix` — branch: `fix/disyl-rawtext-control-tags` (already exists, `ac4c1ce`)
chair: this session

Read first: `docs/kernel/disyl-development-workflow.md`,
`.github/instructions/disyl-grammar-gaps.instructions.md`.

## Owner directive governing this work

*"everytime there's a disyl related issue. fix at the engine level/kernel level and no bandaid fixes."*

So: the fix belongs in `kernel/DiSyL/`. Do **not** edit `templates/pages/login.disyl` — that template
is correct. Do not restructure any template to dodge the engine. Do not move the value into the
handler.

## The defect (already diagnosed and fixed on the branch — validate, don't redo)

`Parser::looksLikeDisyl()` has a raw-text branch for `<script>` / `<style>` / `<textarea>`. It
accepted a brace only if it matched `isProcessableTemplateExpression()` — i.e. only **expressions**.
Control **tags** (`if x`, `else`, `/if`) never match the expression grammar, so inside a raw-text
element `looksLikeDisyl()` returned false and the tag was emitted as literal text.

Consequence, proven in a real browser: `templates/pages/login.disyl` line 135 renders its JS
endpoint as the literal `{if login_endpoint}/api/v1/auth/login/if`, so the login form POSTs to
`/ %7Bif%20login_endpoint%7D/api/v1/auth/login/if` and gets HTTP 500. **The kernel admin could not
sign in at all.** The login *API* was fine, which is why HTTP checks missed it. The interpreted
pipeline handled it correctly; only the compiled path was broken.

The fix on the branch: keep the existing guards (no nested brace, no unquoted `:`, no unquoted `;` —
these are what stop CSS rules and JS object literals being parsed as DiSyL) and additionally accept a
control tag from the same vocabulary `parseTag()` dispatches on, via a new `isRawTextTag()`. Compiler
version 13 → 14 so warm compiled caches invalidate.

## Deliverables

### A1 — Establish a clean baseline, then attribute the `disyl_include_root_test` failure

**This is the open question and the first thing to do.** With the fix in the tree,
`tests/disyl_include_root_test.php` reports two failures:
*"the same include yields no block content without an include root"* and *"include root does not leak
into later renders on the shared engine"*.

Two facts make it unattributable from the chair's shell:

- `storage/cache/disyl-extends` is `www-data:www-data drwxr-sr-x` — **not writable** by the
  development user, so the compiled include cache could not be cleared for a clean run.
- The test now fails on **clean `main` too** (fix stashed), which means the cache was already
  polluted by the time that was measured.

So: get a genuine cold-cache baseline (clear `storage/cache/disyl-extends`, `disyl-sandbox` and
`compiled` with the privileges required), then run the test on **clean `main`** and **on the branch**
and report both. Do not guess; state the result.

- If the branch fails and clean main passes, this fix has a real regression: find it and fix it in
  the engine.
- If both fail, the failure pre-dates this work — report it as a separate finding and confirm the fix
  is not responsible.

Note for whoever investigates: temporary instrumentation of `isRawTextTag()` recorded **zero hits**
during that test, i.e. the new branch never fires in it. That is a strong hint the failure is not
caused by the new predicate, but it is not proof.

### A2 — Add a permanent engine regression test

The defect was invisible because no test covered a control tag inside a raw-text element. Add one to
the existing DiSyL suite (follow `tests/disyl_v4_compiler_test.php`; `renderString()` exists, but the
defect only manifests through **`render()` on a file** — that distinction is the whole reason it was
missed, so the test must go through the file path).

It must cover, at minimum:
- `{if}` / `{else}` / `{/if}` inside `<script>`, **inside a quoted string** (the shape that broke)
- `{if}` inside `<script>`, unquoted (worked before — must not regress)
- `{foreach}` inside `<style>` or `<script>`
- a CSS rule with braces inside `<style>` (`{box-sizing:border-box}` and a rule containing `:` and
  `;`) still **not** parsed as DiSyL — this is the guard from the earlier fix and must stay
- a JS object literal inside `<script>` still not parsed as DiSyL

### A3 — Verify live, in a browser

The chair has already confirmed both of these; re-confirm after any further change:

- `curl` the kernel host and assert **0** unrendered `{if}` markers and
  `loginEndpoint = '/api/v1/auth/login'`:
  `curl -s --resolve ikabudsix.test:80:127.0.0.1 http://ikabudsix.test/login`
- Browser proof with Playwright (a spec exists at `tests/browser/live-hosts.spec.ts`; it must pass):

```
export PATH=/home/kajagogoo/.local/node-v22.23.2-linux-x64/bin:$PATH
npx playwright test -c /tmp/pw-live.config.js tests/browser/live-hosts.spec.ts --reporter=list
```

  Expected: kernel login lands on `/admin/platform`, tenant login lands on `/cms-akira-shell`, both
  with zero console errors. (System Node is 18; Playwright needs 20+ — hence the PATH. The repo's own
  `playwright.config.js` references a `WorkbenchReporter.js` that does not exist, so use the temp
  config, not `npm test`.)

### A4 — Check for the same shape elsewhere

`templates/pages/login.disyl` had **4** affected lines. A repo scan found legitimate raw-text tags in
`templates/modules/daily-ledger/**`, `templates/pages/{superadmin-settings,admin-profile}.disyl` and
`templates/layouts/kernel-admin.disyl`. Re-run that scan after the fix and confirm each renders
correctly — a silent literal tag in a script is a broken page, not a cosmetic issue.

## Constraints

- Engine-level only. No template edits, no handler moves, no "just interpolate it directly" workaround.
- Do not weaken the existing raw-text guards; CSS/JS must still be excluded from DiSyL parsing.
- No schema changes, no migrations.
- Do not touch `modules/daily-ledger/**` beyond checking its templates render.
- Do not commit, push or branch; the branch already exists.
- `storage/cache/disyl-extends`, `disyl-sandbox` and `compiled` are `www-data`-owned. Clearing them
  needs the appropriate privileges; if you cannot, say so rather than reporting an unattributable
  result.

## Acceptance

1. Cold-cache baseline reported for clean `main` **and** the branch, with the exact commands used.
2. `disyl_include_root_test` outcome attributed explicitly, or an honest statement that it could not be.
3. A2's engine test added; it **fails without the fix** and passes with it (demonstrate both).
4. The whole DiSyL suite green by **exit code** (26 files today; check exit status, not output text —
   several tests print expected error strings).
5. Playwright: both host tests pass, pasted output.
6. The 4 login literals correct on the live page, and the other raw-text-tag templates rendering.
7. `composer test` `0 failed` with the `Total:` line.

## Deliverable

Result block: status, changed, verification, the **attribution of the include-root test** (the point
of this contract), scope, unresolved, recommended next state. If the fix is not responsible for that
failure, say so plainly; if it is, fix it at the engine level rather than reverting the tag support.
