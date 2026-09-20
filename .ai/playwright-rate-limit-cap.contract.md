# CONTRACT — Cap the Playwright suite inside the kernel login limiter

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

## Objective

`npx playwright test` currently **fails 3 of 9 tests**, and the cause is the harness rather than the
product. The suite issues **9 login POSTs**; the kernel limiter permits **5 per 300 seconds**.

Measured evidence (chair, 2026-09-14):

```
auth.login_rate_limited {"identifier":"t54:ip:127.0.0.1","max_attempts":5,"window_seconds":300,...}
```

The three failures are exactly the specs that authenticate; `live-hosts.spec.ts` passes when run
alone (2.8s) and times out at 22.0s inside the full run. This is a false alarm that reads like an
authorisation regression.

**Make the full suite authenticate at most 4 times per 300s window, without weakening anything.**

## Architectural constraints

- **Never weaken auth to make a test pass.** The limiter, `max_attempts`, the window, and any
  kernel auth code are out of bounds. This is an absolute prohibition, not an L4.
- **Never delete, skip, or `test.fixme` a test to reduce login count.** 9 tests must still run.
- Use Playwright's own mechanism: a `setup` project that logs in once per host and saves
  `storageState`, with the suite project depending on it.
- Credentials come from `.env` (loaded by `playwright.config.js`). **Never hardcode a password in a
  tracked file** — one already reached 8 commits of history.
- `live-hosts.spec.ts` exists to test the login form itself. It must keep exercising a real login
  and must opt out of the shared state (start from a clean context).
- Tests that assert *anonymous* behaviour (media "fresh anonymous shell login entry",
  read-authority "login entry point and anonymous public browsing") must run with an empty
  storage state, not an inherited authenticated one.

## Files likely affected

- `playwright.config.js`
- `tests/browser/auth.setup.ts` (new)
- `tests/browser/*.spec.ts`
- `.gitignore` (ignored storage-state directory)

## Acceptance criteria

1. `npx playwright test` with **no inline environment variables** exits `0` and reports
   **9 passed / 0 failed**.
2. Total login POSTs in one full-suite run is **<= 4**.
3. `auth.login_rate_limited` occurrences in `storage/logs/app.log` during the run: **0**.
4. Test count is still **9** — nothing skipped, deleted, or renamed away.
5. `live-hosts.spec.ts` still performs a real form login for both kernel and tenant hosts.
6. Evidence pasted in the result: the suite exit code, the passed/failed counts, and the login
   count you measured.

## Required tests

```bash
php -l tools/ai-autonomy.php                      # unchanged, sanity only
npx playwright test --list                        # must list 9 tests
npx playwright test                               # must exit 0 with 9 passed
grep -c "auth.login_rate_limited" storage/logs/app.log
```

Also re-run the whole suite **twice in a row** to prove a second run inside the same 300s window
still passes. If a second run trips the limiter, that is a real finding — report it, do not relax
the limiter.

## Risks

- A `storageState` file committed to git would leak a live session token. It must be git-ignored.
- Ordering: the limiter window is 300s. If you burn logins while iterating, wait for the window
  rather than editing the limiter.
- A "passing" suite that skipped the auth-dependent tests is a **false pass**. Criterion 4 exists
  to make that detectable.

## Forbidden changes

- `kernel/`
- `modules/`
- `src/`
- `config/`
- `migrations/`
- `storage/cache/`
- `phpstan-baseline.neon`
- `phpunit.xml`
- `.github/instructions/`

## Out of scope

Changing the limiter, adding retries to mask flakiness, changing the app's auth behaviour, or
touching anything outside `tests/browser/` and `playwright.config.js`.
