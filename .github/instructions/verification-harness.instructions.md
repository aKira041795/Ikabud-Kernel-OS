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
  A guessed `users.tenant_id` produced an empty id, a silently wrong URL (`/users//role`) and a
  meaningless `301` — the kernel/auth-owned `users` table has no such column.
- **Do not generalise from that one table.** Tenant-scoped tables broadly **do** carry `tenant_id`:
  measured on tenant 54, **12 of 12** `cms_akira_*` tables have it, `NOT NULL`, inside their composite
  unique keys (`UNIQUE (tenant_id, slug)`, `UNIQUE (tenant_id, media_key)`, …). The dedicated
  per-tenant database is the isolation boundary; `tenant_id` is defence-in-depth on top of it. Both are
  true at once, and treating them as mutually exclusive produced a false architectural invariant that
  propagated into a research brief and two independent model answers before anyone measured the schema.
  **Authoritative rule: `docs/architecture/adr-002-tenancy-invariant.md`.**
- To know which applies to a given table, run `SHOW COLUMNS`. Never infer it from the owning module.

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
- A **repo-wide local** PHPStan run reports ~424 errors that CI does not. The cause is **not** the PHP
  version: PHP 8.3 and 8.5 report identical totals (2766 unbaselined / 424 baselined), so "CI runs 8.3"
  does not explain it. The cause is **ignored local modules** sitting under the config's `paths:` —
  `modules/*` is git-ignored (`.gitignore:10`), so `modules/daily-ledger` (0 tracked files) is analysed
  locally and absent from CI's checkout. All 424 are in that module, which is why CI is green.
- Hence: reproduce the gate by restricting the config's path set to tracked files —
  `php8.3 vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G kernel src $(git ls-files 'modules/*.php')`.
  Do **not** widen it to `$(git ls-files '*.php')`: that adds `tests/`, `scripts/` and `tools/`, which the
  gate never analyses, and inflates the total (590 vs 424) — a different file set is not a comparable
  measurement. Never regenerate the baseline from a run whose path set differs from CI's.
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

## The instrument itself — lessons from false *escalations* (2026-09-16)

Everything above was learned from false alarms. These were learned from false **escalations**, which cost
more: a stall, four discarded chunks, and a verdict that described the wrong kind of failure. Full narrative
and evidence: **`docs/testing/harness-lessons.md`**.

- **Never log in per invocation.** The acceptance harness is re-run per chunk by design, and the login limiter
  allows 5 attempts per 300 s. `globalSetup` spending one login on every run tripped it, timed out inside auth
  setup, and was reported as `1 failed` — a harness outage wearing a product failure's clothes. Reuse a fresh
  `storageState`; if a login is ever refused while a session exists, reuse it and warn loudly.
- **Re-run a failed verification once.** Pass-on-retry is `FLAKY`: recorded as harness instability, never as a
  product failure and never as "no progress". Timing-sensitive probes are the usual cause — make them
  deterministic by driving state directly, never by loosening a threshold.
- **A requirement written as prose is not enforced.** If a requirement needs evidence to decide it, that evidence
  is a command in `## Acceptance`; and the requirement list itself must be machine-readable (see
  `tools/harpp2/projects/star-swarm-concept.json` gated by `tests/star_swarm_concept_test.php`). Two iterations
  in one day were reported green while half their brief was unbuilt.
- **A stop condition names a breach, not a mood.** "No progress after N approaches" is a defective objective or a
  flaky instrument — a chair correction, never an authority/boundary/irreversibility stop.
- **A grep is an instrument; verify it.** Do not conclude a capability is missing because your vocabulary for it
  is absent from the source — read the artifact's own assertions first.
- **Falsification needs restore discipline.** Back up, patch, test, restore, and verify the restore by hash.
- **A dispatched run needs a watcher.** `tools/harpp2/status.sh` answers "what is happening now?" in one command;
  an escalation writes a `NEEDS_CHAIR` marker that status surfaces first.
