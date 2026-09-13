# Contract — Akira post mutations must invalidate the public page cache

status: READY_FOR_IMPLEMENTATION
role: /architect
authority: chair, 2026-09-12 (review: `.ai/akira-review-2026-09-12.md`, Gap C)

---

## Problem (evidence)

The admin UI publishes correctly and public pages refresh. The **capability API path does not**.

- `modules/cms-akira-shell/handlers.php` (368, 416) and `helpers.php` (539, 570, 588) call
  `akiraShellInvalidatePublicCache()` on the save/publish/delete success path.
- `modules/cms-akira-builder/helpers.php` (694–701) calls it too, guarded by `function_exists`.
- **`modules/cms-akira-core/helpers/capabilities.php` contains no invalidation at all** — a grep for
  `invalidate|Cache` in that file returns nothing.

So a post published through `akira.post.publish@1` / `akira.post.update@1` directly (API, automation,
another module) can leave the public home/archive/detail stale for up to `PAGE_CACHE_TTL` (300s).

The semantic half already works: mutations declare `effects.invalidates: [entity.list.post]` and
`CapabilityBus::invalidateProviderEffects()` routes that to `entityViews()->invalidateEntityCache()`.
Only the **public page cache** is missed.

## Decision (chair)

**D1 — Follow the existing in-repo convention.** The builder already reaches the shell's invalidator
behind `function_exists('akiraShellInvalidatePublicCache')`. Use that same guarded call from the core
mutation success path. Do **not** invent a new mechanism, and do not make the dependency hard.

**D2 — Commit path only.** Call it after the transaction commits, never on failure or rollback. A
failed mutation must not purge a healthy cache. `akiraShellInvalidatePublicCache()` already documents
"success/commit path only".

**D3 — Do not touch the entity cache mechanism.** `effects.invalidates` handling is correct and
already tested. This slice is only about the page cache.

## Scope

allowed:
- `modules/cms-akira/cms-akira-core/helpers/capabilities.php` — the post mutation success path
  (create, update, publish, unpublish, delete).
- `modules/cms-akira/cms-akira-core/tests/` — one new focused test.

prohibited:
- changing `pageCacheInvalidateModule` / `pageCacheInvalidateUrl` semantics or TTLs;
- changing capability `effects.invalidates` declarations;
- touching the shell's or builder's existing invalidator;
- adding a hard dependency on the shell module (must stay `function_exists`-guarded);
- cross-module table reads.

## Acceptance

1. A post published via the core capability purges the `cms-akira-shell` page-cache scope
   (and `/posts/{slug}` when the slug is known).
2. A failed/rolled-back mutation purges nothing.
3. With the page cache unavailable, the mutation still succeeds (fail-open, no fatal).
4. No new cross-module hard dependency; the shell module may be absent.

## Verification

- `php -l` on every touched file.
- New test proving (1) and (2) — assert the cache scope is cleared on success and untouched on
  failure. If the page-cache helpers are unavailable in CLI, print `SKIP: <reason>` via
  `testEnvironmentSkip()` rather than silently passing.
- Call `requireNotLiveTenantDatabase()` in any new test that touches a database — tests must never
  bind to a provisioned tenant DB.
- `php scripts/run-tests.php` → expect **106 passed / 0 failed / 31 skipped** or better, with the new
  test discovered by the runner.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <files>` — a bare file
  path does NOT apply `phpstan.neon` and looks like a false pass.

## Risks

- Purge-on-commit ordering is the main correctness risk; a purge inside the transaction would clear a
  cache that a rollback then makes stale-but-correct.
- Keep the call cheap: it runs on every post mutation.
