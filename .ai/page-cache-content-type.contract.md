# SLICE — A cached response must replay its own Content-Type

project: akira-completion · status: READY_FOR_IMPLEMENTATION · revision: 1
milestone: 1 · phase: cross-cutting (public emission correctness)
repo: `/var/www/html/ikabudsix`
lane: openai-codex/gpt-5.6-sol
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "openai-codex/gpt-5.6-sol", "--name", "page-cache-content-type", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/chair-decisions.md` **CD-56, Finding 2** — *"the root defect is in `src/`, which was out of scope.
Every future non-HTML response (feeds, JSON, images, sitemap indexes) inherits this bug, and the fix belongs in the
cache, not in each handler. Own slice, recorded."* This is that slice.

## Objective

The page cache stores a response's **body and status** but not its **headers**, and `pageCacheServe()` hardcodes
`Content-Type: text/html; charset=UTF-8` (`src/helpers/page-cache.php:337`). Consequence: **any non-HTML response
that reaches the cache is served with the wrong content type on every hit after the first.**

Measured live on 2026-09-15: the first `/sitemap.xml` response carried `application/xml; charset=utf-8`; every
repeat carried `text/html`. The module currently dodges this with a per-path workaround —
`akiraPublicBypassPageCacheForNonHtml()` in `modules/cms-akira/cms-akira-shell/helpers.php:108`, which sets
`$_GET['nocache'] = '1'` for `/sitemap.xml` and `/robots.txt`.

**Deliver the mechanism fix in the cache itself**, so that response headers are part of what is cached and replayed.

## What to build

1. **Resolve.** Add a *pure* function that resolves a response content type from a supplied header list
   (case-insensitive `Content-Type` lookup) with a deterministic fallback. Pure means the header list is an
   argument — that is what makes it CLI-testable, since `header()` and `headers_list()` are inert in CLI.
2. **Persist.** `pageCacheSet()` stores the resolved content type on the entry. Its existing signature and every
   existing caller must keep working (add an optional parameter; do not break `pageCacheSet('/', $html, 'mod')`).
3. **Replay.** `pageCacheServe()` sends the entry's own content type instead of the hardcoded one. An entry with no
   stored content type (legacy entries written before this change) keeps **today's** behaviour — state that.
4. **Capture at the write seam.** `src/helpers/module-manager.php` (the `pageCacheSet()` call at `:3458`) supplies
   the content type observed for the response it is caching. Diagnose what is observable at that point under FPM and
   under CLI and **state both**; the fallback must be deterministic, not incidental.
5. **Make the emission decision testable.** `header()` is inert in CLI, so emitting through it directly makes the
   outcome unverifiable by a test. Compute the headers a cached entry will be served with in a **pure function**
   (e.g. `pageCacheServeHeaders(array $entry): array`) and have `pageCacheServe()` emit exactly what it returns. The
   test then asserts on the real decision, not on a restatement of it.
6. **Decide the non-HTML safety rule and state it.** A body that is not HTML must never be served as HTML because a
   header was missing. Choose fail-safe behaviour for the case where no content type can be resolved for a
   non-HTML body (do not cache, or cache only with an explicitly declared type) and justify the choice in one or
   two sentences.

## Verify the mechanism before changing it

Read `pageCacheShouldCache()`, `pageCacheSet()`, `pageCacheServe()` and the `executeModuleHandler()` call site
before editing. **Do not assume** that headers set by a handler are visible at the `ob_get_clean()` seam — measure
it. If your first hypothesis is wrong, say so in the report; the diagnosis is part of the deliverable.

## Architectural constraints

- One mechanism, one place. Do not add a second content-type convention, and do not special-case a path.
- **Do not change what is cacheable.** `pageCacheShouldCache()`, `pageCacheTags()`, `pageCacheKey()` and
  `config/page-cache-prefixes.php` are out of scope and must be byte-identical at the end.
- **The `/sitemap.xml` + `/robots.txt` workaround is not in scope and must not be touched.** Removing it would newly
  cache two live public paths whose freshness depends on post mutations, which is a different argument. The final tree
  must show it byte-identical to its current content, so **prove the mechanism without touching any module file**
  (see acceptance 4).
- Do not edit any existing test file. New test files only; the new test is `tests/page_cache_content_type_test.php`.
- You may create temporary probe scripts under `.ai/`; delete them before reporting.
- PHP 8.2-compatible syntax. No new runtime dependency. No schema, no migration, no database write.
- `php -l` on every changed PHP file.

## Files likely affected

- `src/helpers/page-cache.php` — resolution, persistence, emission
- `src/helpers/module-manager.php` — the `pageCacheSet()` call seam only
- `tests/page_cache_content_type_test.php` — new root test (does not exist at dispatch)

## Acceptance criteria

1. An entry stored with a non-HTML content type is served with **that** content type through the real serve path,
   not merely stored correctly — asserted against the pure emission function that `pageCacheServe()` uses.
2. The content type round-trips in the new CLI test for at least two distinct non-HTML types (e.g. `application/xml`
   and `text/plain`) plus one HTML type.
3. Legacy entries without a stored type keep today's behaviour — asserted, not assumed.
4. **Red-first, live, real HTTP on tenant `akiracms.test`, with no module or config change.** Drive the real serve
   path with a controlled non-HTML entry: seed a cache entry for a genuinely cacheable path through the cache's own
   API (`pageCacheSet()` with an XML body and an XML content type), then issue a real HTTP GET to that path and
   observe **`Content-Type: application/xml` together with `X-Page-Cache: hit`**. Run the identical probe *before*
   your change and record it showing `Content-Type: text/html` — that falsification is required evidence, not an
   optional extra. Afterwards **invalidate the probe entry** (`pageCacheInvalidateUrl()`) and show the path served
   normally again, so the tenant is left clean. If you find a stronger probe that reaches the same seam (a real
   non-HTML route that the cache legitimately stores), use it instead and state why it is stronger.
5. No regression: the existing page-cache and sitemap tests pass **unmodified**, and `/` and `/posts` still report
   `X-Page-Cache: hit` (the cache is still doing its job).
6. `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G` over the two changed `src/` files
   reports no **new** error. A bare file path does not apply `phpstan.neon`; the `-c` flag is mandatory.
7. Every changed file is inside the approved scope, and the temporary probe scripts are gone. Report
   `git status --porcelain` in full.

## Required tests

**Every command in this section must be one of these admissible shapes** — `php tests/<name>.php`, `php
modules/<mod>/tests/<name>.php`, `php -l <file>.php` — because an unlisted command binds **no claim at all**
(`tools/ai-run.php:COMMAND_ALLOWLIST`) and a report with no bound claims blocks the run with its work complete.

Report each command on its own `$`-prefixed line with its result lines beneath it, **unchained**. A SKIP is not a
pass — state which assertions actually ran. Report a command you did not run as **not run**.

```
$ php tests/page_cache_content_type_test.php
$ php modules/cms-akira/cms-akira-core/tests/post_page_cache_invalidation_test.php
$ php modules/cms-akira/cms-akira-shell/tests/sitemap_robots_test.php
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php
$ php -l src/helpers/page-cache.php
$ php -l src/helpers/module-manager.php
```

The new root test is created by this slice (it does not exist at dispatch, so it is an addition — the driver
records that at `start`). The other three exist and must be **unmodified**.

## Additional evidence (binds no claim — still required)

These are not on the command allowlist, so they extract no claim. Run them and paste their real output anyway;
they are the live corroboration and the report is incomplete without them.

```
$ curl -sSI -H 'Host: akiracms.test' <url>          # the red probe before the change, then the green probe after
$ vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G src/helpers/page-cache.php src/helpers/module-manager.php
$ git --no-pager diff --stat
```

Note: `scripts/run-tests.php` ignores a path argument and unlinks `storage/modules.json`; do not use it.

## Risks

- **Assuming headers are visible at the write seam.** Under FPM the handler's `header()` calls have been made by the
  time output is captured; under CLI they have not. Getting this wrong produces a fix that passes CLI and fails live.
- **Breaking existing callers** of `pageCacheSet()` by making a new parameter mandatory. Two call sites exist today
  (the module manager and a module test).
- **Leaving the tenant dirty.** The probe writes a synthetic entry to a real cached path. Invalidate it at the end
  and show the path serving normally; a probe that leaves a fake body cached is a defect in the run, not the fix.
- **Widening the slice.** Touching cacheability, TTL, tags or a module is out of scope; if you believe it is
  required, **stop and report** rather than reaching outside the envelope.

## Forbidden changes

- `kernel/`
- `tools/`
- `modules/`
- `.github/`
- `config/`
- `storage/`
- `phpstan-baseline.neon`
- `.ai/dispatch-lane.sh`
- `.ai/chair-decisions.md`
- `.ai/akira-master-plan.md`
- `.ai/akira-completion-plan.md`
