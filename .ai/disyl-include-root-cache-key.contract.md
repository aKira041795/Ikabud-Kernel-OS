# CONTRACT — DiSyL: include root must be part of the shared output cache key

task: disyl-include-root-cache-key
lane: openai-codex/gpt-5.6-sol, reasoning medium (precision engine work)
owner: chair retains verification and the gate
status: QUEUED — dispatch after the read-authority-extension lane releases the working tree

## Why this is queued rather than parallel

Both lanes would otherwise run in the same checkout. Two agents editing and testing one working tree
corrupt each other's evidence, so they run one at a time. Do not start this while another lane is
active.

## The defect, already diagnosed

**Do not re-derive this.** It was found and confirmed by the chair on 2026-09-12; reproduce it, fix
it, and prove it. If your reading contradicts the diagnosis below, say so plainly and stop — do not
silently "fix" something else.

`tests/disyl_include_root_test.php` fails **in-suite** but passes **standalone**. It also fails on
clean `main` locally with caches genuinely cleared, and CI is green on `main` — so it is
pre-existing, environment-state dependent, and currently invisible to the gate.

Two assertions fail:

```
✗ the same include yields no block content without an include root
✗ include root does not leak into later renders on the shared engine
```

**Root cause.** `TemplateEngine::renderWithin()` is correct — it saves and restores `$includeBase` in
a `finally` (`kernel/DiSyL/TemplateEngine.php:392-400`). The problem is one level up: `render()`
serves from an APCu **shared output cache** whose key omits the include root.

```php
// kernel/DiSyL/Renderer/TemplateRenderer.php:73
public function buildSharedOutputCacheKey(string $templatePath, array $context): string
{
    $mtime = (int)@filemtime($templatePath);
    return 'disyl:render:' . md5($templatePath . '|' . $mtime . '|' . $this->buildOutputCacheKey($templatePath, $context));
}
```

`includeBase` is set at `TemplateEngine.php:395` and used for relative include resolution at
`TemplateEngine.php:5377-5379`, but it never enters the key. So:

1. A **rooted** render (`renderWithin`) caches output with the include already resolved.
2. A later **unrooted** render of the same template + context gets a **cache hit** and is served that
   rooted output — the include root has leaked across renders.

That is exactly what assertions 2 and 3 detect. The interpreted pipeline resolves per render and is
unaffected; the leak appears only when the shared output cache is warm.

## The fix

Thread the include root into the shared output cache key so two renders that differ only by include
root cannot share an entry. The minimal change is to pass the current include base into
`buildSharedOutputCacheKey()` and mix it into the hashed input.

Do **not** widen this into a caching redesign. If a second, related key derivation exists with the
same omission (check `buildOutputCacheKey` and any per-request/memo cache), and it can produce the
same wrong-output class, fix it in the same change **and say so**. Otherwise leave it alone.

If the include base is `null` and a template has no relative includes, the key must remain stable —
do not churn the cache for the common case. Explain how you achieved that.

## Scope

### allowed
- `kernel/DiSyL/TemplateEngine.php`
- `kernel/DiSyL/Renderer/TemplateRenderer.php`
- `tests/disyl_include_root_test.php` — only to make the cold/warm-cache condition explicit, **never
  to weaken an assertion**
- a new or extended engine test under `tests/`

### prohibited
- Any template edit. The engine-first directive is standing: fix `kernel/DiSyL/`, never the template.
- Weakening, skipping or deleting the existing assertions. They are correct and they caught a real
  bug. Making them pass by relaxing them is the failure mode this contract exists to prevent.
- Any schema change or migration.
- Touching `modules/`, except running its tests.
- Editing `.governance-baseline.json`.
- Bumping `TemplateCompiler::COMPILER_VERSION` unless the compiled artefact format actually changes.
  This defect is in the output cache, not the compiler; a gratuitous bump invalidates every cache for
  nothing. State whether you bumped it and why.

## Acceptance

**C1 — Reproduce before fixing.** With caches cleared, show the two assertions failing. Report the
exact command, including how you cleared `storage/cache/compiled`, `storage/cache/disyl-extends` and
`storage/cache/disyl-sandbox`. Note: those directories are `www-data`-owned and the dev user cannot
write them directly; the established method is a temporary `public/_*.php` invoked with curl as
`www-data`, then deleted. If you cannot clear them, say so — do not report a warm-cache result as a
cold-cache one. **This is the single easiest way to fool yourself in this task.**

**C2 — The fix.** With caches cleared again, the test passes.

**C3 — Falsify your own fix.** Construct the case the key must separate: same template, same context,
two different include roots, warm cache. Show that the second render does **not** receive the first
render's output. This is the actual claim; a passing test alone is not evidence if the test's
conditions were not genuinely cold.

**C4 — No cache churn in the common case.** Show that a template with no relative includes, rendered
twice with no include base, still hits the cache the second time. A fix that disables the shared cache
would also make the test pass, and would be a silent performance regression.

**C5 — Suite and lint.** `composer test` reports **exactly one** failure before your change
(`disyl_include_root_test`) and **zero** after. `php ikabud disyl:lint` stays clean. If the count
moves in any other way, that is a regression — find it.

**C6 — Report the pre-existing CI gap.** State plainly that CI is currently green on `main` while this
test fails locally, and explain what that implies about the gate. The test's failure taking a
cache-dependent path means CI may not be exercising it; do not claim the gate is fixed.

## Verification commands

```
php -l on every touched file
php tests/disyl_include_root_test.php
php tests/disyl_v4_compiler_test.php
composer test
php ikabud disyl:lint
```

## Result format

```
status:        PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
task:          disyl-include-root-cache-key

C1 reproduced_before_fix:  yes | no   <command, cache state, exact failures>
C2 passes_after_fix:       yes | no
C3 key_separates_roots:    yes | no   <the falsifying case and its result>
C4 no_cache_churn:         yes | no   <evidence the shared cache still hits>
C5 suite:                  before <n failed> -> after <n failed>
   lint:
C6 ci_gap:                 <what CI currently misses, stated plainly>

root_cause_confirmed:      <agree with the diagnosis above, or your correction>
second_key_derivation:     <found and fixed? or absent — say which>
compiler_version_bumped:   yes | no   <and why>

changed:       <files>
verification:  <commands + outcomes>
scope:         <anything touched outside the allowed list>
risks:
unresolved:
recommended_next_state:
```
