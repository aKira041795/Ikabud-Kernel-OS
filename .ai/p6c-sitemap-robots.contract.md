# SLICE — P6c Production floor: the public site serves sitemap.xml and robots.txt

project: akira-master-plan · status: READY_FOR_IMPLEMENTATION · revision: 1
repo: `/var/www/html/ikabudsix`
lane: deepseek/deepseek-v4-flash
dispatch: ["pi", "-p", "-a", "--thinking", "medium", "--model", "deepseek/deepseek-v4-flash", "--name", "p6c-sitemap-robots", "<CONTRACT>"]

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp
  evidence: real command output and exit codes; no claim without evidence
```

authority: `.ai/akira-master-plan.md` — `status: APPROVED`, remaining obligation **#2 "P6 recovery / export, production
floor"**. This contract authorises the two read routes and the capability declaration it requires.

## Objective

`kernel/Http/TenantEntryRouter.php:359-376` lists `/robots.txt`, `/sitemap.xml`, `/favicon.ico`, `/manifest.json` and
`/.well-known/` as paths that must **NOT** be fast-rejected, so module routing can claim them. Two of those are real
production requirements and **nothing in the product serves them** — a repo-wide grep for `sitemap` and `robots.txt`
finds only that router list. A tenant site therefore answers 404 to the two files every crawler asks for first.

Fill the slot. `cms-akira-shell` owns the public routes (`/`, `/posts`, `/posts/{slug}`), so it owns these.

## What to build

1. **`GET /sitemap.xml`** — a valid XML sitemap of the tenant's **published** posts, served by `cms-akira-shell`.
   - Absolute URLs using the request's own host and scheme, so the file is correct per tenant without configuration.
   - Derive post URLs from the canonical `url` field the module already exposes; **do not** hand-build
     `/posts/{slug}` from the slug, and never from a title.
   - Include `<lastmod>` from the post's own timestamp when present. Omit the tag rather than invent a date.
   - Correct `Content-Type: application/xml; charset=utf-8`. Not `text/html`.
   - **Exclude drafts and deleted records.** The existing public read path already applies the
     `status='published'` boundary without `include_unpublished` (see `akiraPublicPostList` in
     `modules/cms-akira/cms-akira-shell/handlers.php`); reuse that behaviour rather than writing a new filter.
   - Cap the number of entries at a declared maximum and say so in the response when the cap is hit, rather than
     emitting a silently truncated file. A sitemap index is out of scope.
   - XML-escape every value.

2. **`GET /robots.txt`** — `Content-Type: text/plain; charset=utf-8`, allowing normal crawling and naming the sitemap
   absolutely (`Sitemap: <scheme>://<host>/sitemap.xml`). No user-agent-specific rules, no crawl-delay.

3. **Declaration** — both routes declared in `modules/cms-akira/cms-akira-shell/module.json` under
   `capabilities.routes`, keyed `"<METHOD> <path>" => "<capability>"`.

4. **The capability.** If no existing capability covers "read the published post list for syndication", expose a new
   read capability from `cms-akira-shell` and seed it. If you add one, seed it through **`cacActivePolicyVersion()`**
   in `modules/cms-akira/cms-akira-core/helpers.php` — the version-resolving helper proven live in `6605efb`. **Do NOT
   pin a literal `policy_version`**: a row pinned to a literal is invisible on any tenant whose active version has
   advanced, and fail-closed dispatch then refuses every operator with `missing_policy_row`. That defect shipped once
   already and must not be repeated.

## The safety invariant — the one thing that matters

**A declared route whose capability has no active policy row 403s every visitor.** Sitemap and robots are
**public, unauthenticated** paths, so a fail-closed refusal here breaks crawling for the whole site. In order:

**seed the policy → verify a row exists → only then declare the route.**

The seeded `allowed_roles` must reflect who may read published content — these are public reads, so model them the way
the existing public read capabilities are modelled. **Read the existing public read declarations and copy their
shape**; do not invent a stricter one, because a public route that admits nobody is a 403 wall.

## Architectural constraints

`cms-akira-shell` must remain **SQL-free**. Reach data only through `akiraShellCall()` and the existing published-post
read capability. No new SQL, no direct table access, no `app()->db()`.

Reuse the existing published-post read path. Do not write a second status filter or a second visibility rule.

Do not edit any existing test file. Add new tests only; existing test files escalate as an absolute prohibition.

`modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` must pass UNMODIFIED. Its route assertions use
`isset()` on specific paths, so added routes cannot break it. If it fails, report it — do not adjust it.

Add no GET route other than the two named here, and do not remove or reorder any existing route.

PHP 8.2-compatible syntax. No new runtime dependency. No MySQL 8 features.

## Files likely affected

- `modules/cms-akira/cms-akira-shell/routes.php`
- `modules/cms-akira/cms-akira-shell/handlers.php`
- `modules/cms-akira/cms-akira-shell/module.json`
- `modules/cms-akira/cms-akira-shell/helpers.php`
- `modules/cms-akira/cms-akira-shell/tests/sitemap_robots_test.php`

## Acceptance criteria

1. `GET /sitemap.xml` returns **200**, `Content-Type: application/xml`, and parses as XML.
2. Every `<loc>` is an absolute URL whose host matches the request host, and every URL resolves to a real published
   post.
3. A draft, unpublished or deleted post never appears in the sitemap.
4. `GET /robots.txt` returns **200**, `Content-Type: text/plain`, and contains an absolute `Sitemap:` line.
5. Both routes are declared in the shell manifest and both resolve to their handlers.
6. Any new capability has an **active** policy row whose `policy_version` equals the tenant's active policy version —
   not a pinned literal.
7. `php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php` passes **unmodified**.
8. `php -l` reports no syntax errors on every changed PHP file.

## Required tests

Report each command on its own line, prefixed `$ `, with its result lines beneath it, **unchained**. A chained line
binds no claim and is refused.

```
$ php modules/cms-akira/cms-akira-shell/tests/sitemap_robots_test.php
$ php modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php
$ php -l modules/cms-akira/cms-akira-shell/handlers.php
$ php -l modules/cms-akira/cms-akira-shell/routes.php
$ php ikabud workbench:governance --all --json
```

A module test may bootstrap the application, but if it reaches the application database it **must** call
`requireNotLiveTenantDatabase()` (`tests/_support/env_guard.php`) or the ledger refuses the claim. That guard makes the
test SKIP on this checkout. **A SKIP is not a pass** — say plainly which assertions actually executed.

For the census, report only the `cms-akira-shell` summary object.

Report a command you did not run as **not run**. Never claim a command you did not execute.

## Risks

- **A public route behind a missing policy row is a site-wide crawl failure.** Verify the active row before declaring.
- **XML injection via post titles or URLs.** Escape everything; a title containing `<` must not break the document.
- **Serving a stale or cross-tenant sitemap.** Build URLs from the current request's host, never a configured
  constant, so tenant A can never emit tenant B's URLs.
- The sitemap cannot be verified through a unit test alone — the executor should state which assertions ran and which
  behaviour needs a live HTTP check.

## Forbidden changes

- `modules/cms-akira/cms-akira-shell/tests/shell_contract_test.php`
- `tests/`
- `kernel/`
- `src/`
- `tools/`
- `storage/cms-themes/`
- `.github/workflows/`
- `phpstan-baseline.neon`
- `.governance-baseline.json`
- `docs/architecture/`
- `.ai/akira-master-plan.md`
