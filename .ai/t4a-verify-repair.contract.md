# T4a VERIFY-AND-REPAIR — end-to-end governed composition journey + public render

## Context
T4a is implemented but NOT end-to-end verified (your previous run was blocked). The chair has since:
- activated `cms-akira-builder` for tenant 54 (`php ikabud tenant:module:install 54 cms-akira-builder` → committed),
- verified `akira.builder.create@1` works (draft + revision),
- verified the canonical-block validation errors are correct (`Unknown theme block: no-such-block.`,
  `card-grid.items must be an array.`),
- verified publish is correctly gated (requires the composition's `entity_key` to resolve to a real post via
  `akira.post.get@1`),
- RESET the capability circuit breakers your failing probes tripped (`storage/cache/capability_breakers.json`
  entries for `akira.builder.*` removed — if you trip them again, reset them the same way before continuing;
  repeated failures open the circuit and every later call fails fast with "Capability circuit open").

Composition tree shape: `{version: 1, blocks: [{block: <theme-block-id>, props: {...}, children?: [...]}]}`.
Create payload keys: `idempotency_key, entity_type (=post), entity_key, title, tree, change_note?`;
publish/unpublish: `idempotency_key, entity_type, entity_key`; validate: `idempotency_key, tree`.
Theme blocks available: hero, richtext, card-grid, quote, cta (see
`storage/cms-themes/akira-ark/block-definitions.json` for prop schemas).
Your harness must register the module capability handlers manually (CLI bootstrap does not), as in
`modules/cms-akira/cms-akira-theme/tests/theme_contract_test.php` — you need **builder + theme + core**
(`cms_akira_builder_capability_handlers()`, `cms_akira_theme_capability_handlers()`,
`cms_akira_core_capability_handlers()`), each wrapped in `moduleWithContext('<module>', …)`. A working
reference harness the chair used is at `.ai/t4a_e2e.php` (tenant 54, admin user id 1) — extend it.

## Task
1. **Drive the governed journey on tenant 54** (through capabilities, not raw SQL):
   a. Create a post (`akira.post.create@1`, slug e.g. `t4a-page`) — status must NOT be passed (lifecycle-only).
   b. Publish that post through WHATEVER the governed path requires (R1 workflow-authoritative: submit/approve
      first if required, then publish). If `app()->entityAuthority()->isAuthoritative('post','cms-akira-core')`
      is false under CLI, prefer driving this over REAL HTTP with an authenticated admin session instead.
   c. Create the composition bound to that post's slug (`entity_key = 't4a-page'`) with a tree containing
      `hero` + `card-grid` (props per the theme schemas). Publish it.
   d. Assert the published composition's `published_revision_id` is set and `status = published`.
2. **Verify the PUBLIC render** (anonymous, no session): `GET /p/t4a-page` with `Host: akiracms.test`.
   - Assert the output contains the theme-rendered hero and card-grid markup (theme CSS classes from
     `blocks/*.disyl`, e.g. `akira-ark-block`/`akira-ark-hero`, card `<h3>`, the CTA link) — i.e. blocks were
     rendered by the THEME templates, not the old bespoke vocabulary.
   - **REPORT whether the response is a complete themed page** (public layout: doctype/`<head>`/`data-akira-theme`
     /header region/footer region) **or a bare fragment** (just the `<article>`). The route currently echoes the
     theme view output directly — if it is a bare fragment, that is a GAP vs the ADR acceptance ("render publicly
     through the active theme"): **FIX it minimally** so a published composition renders through the theme's
     public layout exactly like the post pages do (title + header region + composition content in the page
     region + footer region). Keep it in `cms-akira-builder` (+ theme only if required); no kernel edits.
   - `GET /p/definitely-not-published` → **404**.
3. **Unpublish → 404**: unpublish the composition, assert `/p/t4a-page` now 404s.
4. **Idempotency + audit**: replay the SAME publish idempotency key → replayed (no second transition) and assert
   exactly ONE `akira.builder.*` audit row for that publish in the tenant DB (`audit_logs`).
5. **Cache invalidation**: assert publish/unpublish is reflected immediately on the next anonymous request (no
   stale page). If the public page cache can serve a stale composition page, fix it (invalidate the public page
   cache for that URL/prefix on publish/unpublish — reuse `akiraShellInvalidatePublicCache()` /
   `pageCacheInvalidateModule()` as the theme module does).
6. **Tests**: add/extend a focused builder test covering the canonical-block binding + validation + published-only
   public render + 404 when unpublished (hermetic where possible; do not depend on the synthetic-tenant policy
   pattern that is pre-existing-broken). Keep existing builder/theme tests green.
7. **Cleanup**: delete the throwaway composition/post rows you created if they are not needed as fixtures
   (tenant 54 is a live demo tenant — leave it tidy and note what you left).

## Constraints
- Scope: `modules/cms-akira/cms-akira-builder/**` + `storage/cms-themes/akira-ark/**` (+ theme module only if
  required). **NO kernel/ edits.**
- Keep the governed pipeline (capability → idempotency → one-tenant-tx audit) intact. No raw-SQL writes.
- CI gates before finishing: `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>`
  (options BEFORE files; no --path-mode); `php vendor/bin/phpstan analyse <paths> --memory-limit=1G`
  (MULTILINE docblocks; no new errors); `php -l` on touched files.
- Do NOT commit. Leave changes for review.
- AFTER the run: clear the web APCu module-scan cache (temporary `public/_apcu_reset.php` → curl with
  `Host: akiracms.test` → delete) and verify tenant 54 is healthy (`/` 200, `data-akira-theme="akira-ark"`).

## Acceptance / report evidence for each
1. post created + published (state + evidence)
2. composition created + published (`published_revision_id`, `status`)
3. `GET /p/t4a-page` anon → status + **full page or bare fragment** (show the first ~300 chars + the block markup
   found); if you fixed the layout gap, show before/after
4. `GET /p/unknown` → 404; after unpublish `GET /p/t4a-page` → 404
5. duplicate publish → replayed + exactly 1 audit row (paste the query result)
6. cache: publish/unpublish visible immediately (describe how you proved it)
7. tests: command + result; php -l / cs-fixer / phpstan results; tenant 54 health after; breakers clean.

## Result format
status: PASS|FAIL|PARTIAL|BLOCKED
changed:
gaps_found_and_fixed:   (esp. public layout wrapping / cache invalidation)
verification: {post_lifecycle, composition_publish, public_render, unpublished_404, idempotency_audit,
               cache_invalidation, tests, php_lint, cs_fixer, phpstan, tenant_health}
left_on_tenant54:       (rows/fixtures you left)
unresolved:
Stop + escalate if the governed post lifecycle cannot be driven in CLI (say so, then use real HTTP with an
authenticated session) or if a fix would require kernel/ changes.
