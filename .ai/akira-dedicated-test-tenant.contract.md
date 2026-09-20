# Contract — dedicated test tenant for Akira module tests

status: READY_FOR_IMPLEMENTATION
role: /architect
authority: chair, 2026-09-12 (owner approved 2026-09-12; review: `.ai/akira-review-2026-09-12.md`, Gap D)

---

## Problem (evidence)

Six Akira tests previously bound to the **live** tenant by hardcoding
`$_SERVER['HTTP_HOST'] = 'akiracms.test'` (→ tenant 54 → DB `akira`), so `app()->db()` handed them
real data and their teardown `DELETE`s destroyed it. Proven with a `BEFORE DELETE` trigger plus a
timestamped suite run: `posts 1 → 0`.

The fix — `requireNotLiveTenantDatabase()` in `tests/_support/env_guard.php` — is correct and stays.
But it means those tests now **SKIP locally**:

```
Total: 138 files — 106 passed, 0 failed, 32 skipped
[SKIP] post_page_cache_invalidation_test.php — resolved database 'akira' is a provisioned tenant
       database; refusing to run module tests against live tenant data
```

Two consequences:

1. **Local mutation coverage is gone.** A skipped test proves nothing (repo rule: a skip is not a pass).
   `post_page_cache_invalidation_test.php` in particular has **never executed its assertions locally** —
   its acceptance is inspection-verified only.
2. **CI runs them against the base database.** That is the shared-DB pattern the owner has dropped
   ("we have dropped shared db for modules"). A module whose tenant data must live in a dedicated
   database should not be exercised against the kernel control DB, even in CI.

## Decision (chair)

**D1 — Reuse the existing dedicated-tenant pattern.** The repo already models this:
`theme_dedicated_tenant_test.php`, `builder_dedicated_tenant_test.php`, `search_dedicated_tenant_test.php`,
and the guard `requireTwoDistinctDedicatedTenantDatabases()`. Do not invent a new harness.

**D2 — Provision, never adopt.** A test tenant DB must be created for the test run. Never point a test
at a tenant that already exists in `kernel_tenant_db_connections`, and never at `DB_DATABASE`.

**D3 — Keep the safety guard.** `requireNotLiveTenantDatabase()` must remain in place. The goal is to
give tests a legitimate database, not to relax the guard that protects live tenant data.

**D4 — A skip must stay honest.** When provisioning is unavailable, print `SKIP: <reason>` via
`testEnvironmentSkip()`. Never let a test proceed against an unprovisioned or live target.

## Scope

allowed:
- `tests/_support/` — a helper that provisions (or requires) a dedicated test tenant database;
- the Akira module tests that currently SKIP for the live-tenant reason, plus any new test added by the
  concurrent cache-invalidation slice;
- test-only migration invocation needed to give the provisioned DB the Akira schema.

prohibited:
- weakening or removing `requireNotLiveTenantDatabase()`;
- pointing any test at tenant 54 / `akira`, or at `DB_DATABASE`;
- changing production code in `modules/cms-akira/*/helpers*.php` or `handlers.php`;
- touching `kernel/Services/DatabaseManager.php` isolation guards;
- editing `phpstan.neon`, `phpstan-baseline.neon`, or `.governance-baseline.json`.

## Acceptance

1. On a machine where a dedicated test tenant DB can be provisioned, the previously-skipping Akira
   mutation tests **RUN** and pass — report the executed assertion count, not just PASS.
2. Where provisioning is impossible, they SKIP with a specific reason (never silently pass, never bind
   to a live or base database).
3. `post_page_cache_invalidation_test.php` executes its assertions in at least one reachable
   environment and its result is reported as passed / failed / **skipped** explicitly.
4. No test resolves a provisioned (live) tenant database — `requireNotLiveTenantDatabase()` still fires
   if one is attempted.
5. Provisioning is cleaned up: no orphaned test databases left behind on success or failure.

## Verification

- Report per test: passed / failed / **skipped**, and state which assertions actually ran.
- `php scripts/run-tests.php` → no regressions versus **106 passed / 0 failed / 32 skipped**; expect the
  skipped count to **drop** as tests start running.
- `php -l` on every touched PHP file.
- `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <files>` — a bare file path
  does NOT apply `phpstan.neon` and produces a false pass.
- Confirm cleanup: `SHOW DATABASES` shows no leftover test databases afterwards.

## Risks

- Provisioning needs CREATE DATABASE rights. If the environment lacks them, the correct outcome is a
  documented SKIP — **not** a fallback to the base or live database.
- The concurrent cache-invalidation slice adds `post_page_cache_invalidation_test.php`; coordinate so
  both do not edit the same test file simultaneously.
