---
description: "Verification harness discipline: how to drive live HTTP/browser checks without producing false alarms. Covers the login rate limiter, CSRF token sourcing, schema probing, capability registration context, running the repo's own gates the way CI does, and making the browser visible. Applies to any agent or human verifying behaviour against a live tenant."
applyTo: "**/*"
---

# Verification Harness Discipline

Every rule here was learned from a false alarm that cost a CI round or nearly shipped a
wrong conclusion. The failure mode is always the same: **the harness breaks, the product
looks broken, and the finding gets reported before the harness is ruled out.**

**Rule zero: verify the harness before believing the finding.** If a check reports that
the product is broken, first prove the check itself is sound. Most "defects" found this
way were harness faults.

## Authentication

- **Log in ONCE per role per run.** The kernel auth limiter returns
  `429 {"ok":false,"error":"Too many login attempts","retry_after":N}` after repeated
  `POST /api/v1/auth/login`. A scripted journey that logs in per step will trip it.
- **Check the login response code explicitly** and stop on anything but `200`. A failed
  login leaves an unauthenticated session, and the **fail-closed route authority guard**
  then answers every protected path with the kernel **403 "Forbidden — Ikabud Kernel OS"**
  page *before* any handler runs. That is indistinguishable from a permission regression
  unless you look at the login code first.
- Symptom to memorise: **`login -> 429` and every shell surface `403` = rate limited, not
  an authorization problem.**

## CSRF tokens

- Token is session-bound and stable — fetch once and reuse for the batch.
- **Never scrape a CSRF token from a failed response.** Scraping it from a 403 page yields
  `{"ok":false,"error":"Invalid CSRF token"}` with an HTTP **500**, which looks like a
  crash in the mutation path.
- Shell pages may be served from `src/helpers/page-cache.php`, which returns another
  session's stale token. If a POST returns `Invalid CSRF token` after a fresh GET, suspect
  the page cache before the mutation.

## Database and schema

- **Probe the schema; never guess a column.** `SHOW COLUMNS FROM <table>` first.
  A guessed `users.tenant_id` (which does not exist — tenant DBs are separate) produced an
  empty id, a silently wrong URL (`/users//role`) and a meaningless `301`.
- Tenant tables have **no `tenant_id`**; the tenant is the database.

## Capability context

- **A bare CLI bootstrap does not register module capabilities.** Calling e.g.
  `akira.media.resolve@1` from `php -r` with only `bootstrap.php` throws
  `CapabilityNotFoundException: Capability not found`. Verify capability behaviour through
  the module's own test, or over real HTTP — not from an improvised bootstrap.
- Do not construct file paths by hand. Locate a stored file with
  `find storage -name "<key>.*"`; a guessed path reported `file=GONE` for a file that
  existed.

## Running the repo's own gates

- **PHPStan: a bare file path does NOT apply `phpstan.neon`.**
  `vendor/bin/phpstan analyse <file>` reports nothing and looks like a pass — which is how
  a real error reached CI after "targeted PHPStan passed". Use:
  `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G <file>`.
- A **repo-wide local** PHPStan run reports ~424 environment-related errors that CI's PHP 8.3
  does not; local totals are not comparable to CI's. Judge by the config-scoped per-file run,
  or by CI.
- **Inserting lines shifts line numbers and can surface a baseline-suppressed error.**
  When a pre-existing error appears right after an insert, that is why — fix the underlying
  omission rather than editing `phpstan-baseline.neon`. `reportUnmatchedIgnoredErrors: false`
  makes fixing it safe.
- New `array` parameters without a `@param array<string, mixed>` docblock fail
  `static-analysis`. Add the docblock with the parameter.

## Tests: a skip is not a pass

- A `SKIP:` means the test **did not run**; it proves nothing. Always report
  passed / failed / **skipped** and state which assertions actually ran.
- Check whether a test also skips in CI:
  `gh run view --job <id> --log | grep -E '\[(PASS|FAIL|SKIP)\] <file>'`.
  A test that skips everywhere is dead, not passing.
- Tests requiring a writable DiSyL fragment cache skip locally because that directory is
  `www-data`-owned. To actually run them, hold it aside by **same-filesystem rename**
  (parent write is enough; a cross-filesystem `mv` to `/tmp` fails) and create your own:

  ```bash
  HOLD=storage/cache/.disyl-fragments-hold
  mv storage/cache/disyl-fragments "$HOLD" && mkdir -p storage/cache/disyl-fragments
  # ... run the test ...
  rm -rf storage/cache/disyl-fragments && mv "$HOLD" storage/cache/disyl-fragments
  ```
  Always verify the restore (`www-data:www-data 2755`). Never `chmod` around it.

## Browser verification

- Playwright is **headless by default**; nothing is visible unless you ask. Opt in with
  `PW_HEADED=1 npx playwright test …` (or `--headed`). CI must stay headless.
- Drive the live tenant with the repo's own specs where they exist; check them before
  writing a new one.
- **Prefer Playwright over curl for UI journeys.** Real form fields, real redirects and
  server-rendered JS are what an editor actually experiences; curl field-guessing is
  fragile and has produced wrong conclusions.
- A spec that hardcodes placeholder credentials or sniffed selectors is a harness defect —
  fix it rather than working around it.

## Scope and provenance

- `git push` can fail silently: always confirm with
  `git ls-remote origin <branch>` before waiting on CI. Preparing to watch a run that does
  not exist has happened.
- Never widen an authorization policy, a production guard, or a baseline to make a check
  pass. Report the tension instead.
