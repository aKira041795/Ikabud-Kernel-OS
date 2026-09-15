# /implement — PHASE 2 ROUND 2 (bounded repair) — Entity-view render-path cache (Codex Sol)

You are the /implement agent on Codex Sol. Working directory: /var/www/html/ikabudsix.
Phase-2 review returned CHANGES_REQUIRED (7 findings on cache-key correctness + semantic transparency). Repair per
the remediations below. BOUNDED repair — do not expand scope; production render semantics stay transparent.

## Authority & context (read first)
1. Contract: /var/www/html/ikabudsix/.ai/phase2-entity-view-render-cache-contract-2026-09-06.md
2. Review findings: /var/www/html/ikabudsix/test_results/review-phase2.log (7 findings — your directive)
3. Governing: /var/www/html/ikabudsix/.github/instructions/ai-development-execution-handoff.instructions.md
   (bounded repair) + .github/copilot-instructions.md (check BOTH logs after runs).
4. Read the ACTUAL code: kernel/DiSyL/Component/ComponentRenderer.php (prepareEntityCache + the two
   renderEntity*ViaService methods ~L1999-2339), kernel/EntityContext/DefaultEntityRenderer.php (how list query
   state, base_url, _queryState, CSRF/bulk actions, and random IDs affect output: ~L106,192,731-764,942-1000,
   1060-1082), the pagination/query-state resolver, and kernel/EntityContext/EntityViewResolver.php.

## Findings to FIX (with chair guidance)
1. Canonicalize the EFFECTIVE namespaced query state in the cache key: derive exactly what DefaultEntityRenderer /
   the query-state resolver consumes (<listId>_page, <listId>_limit, <listId>_sort, <listId>_cursor, <listId>_prev)
   plus any explicitly supplied _queryState — NOT generic $_GET keys. Different pages must never share HTML.
2. Semantic transparency: DO NOT cache whenever output depends on unsafe request/session-variant material
   (CSRF-bearing POST/bulk actions, nondeterministic generated list IDs, base_url/session variance) unless those
   values are fully deterministic or safely partitioned in the key. When in doubt, SKIP caching (conservative
   correctness over hit rate). Prove same-key ⇒ byte-identical HTML.
3. Per-user protection: detect an authenticated identity from the AUTHORITATIVE application principal (auth
   runtime), not only from selected template-context shapes. Default NO-cache whenever an identified principal
   exists; require explicit cache-user="true" AND include the stable identity in the key.
4. Fail-open must be observable: FragmentStore currently suppresses filesystem failures internally
   (kernel/DiSyL/Cache/FragmentStore.php ~L85-89). Make lookup/write failures surface (return false / allow the
   caller to log) so ComponentRenderer can log a warning and render normally. Chair-APPROVED minimal change to
   FragmentStore for observability — do not broaden cache work. Add bounded failure-path tests via an injectable
   seam (do not mock the whole store; use a seam that lets you force a write/lookup failure and assert warning +
   normal render).
5. Extend tests/entity_view_render_cache_test.php: collision checks for EVERY key dimension — namespaced
   pagination/cursors, render attributes/children, detail ID, differing render context (base_url/_queryState),
   user vs anonymous, tenant — plus fail-open assertions (forced store failure => normal render + warning). Keep
   existing coverage green.
6. Revert the unrelated "Timeout Handling" documentation edit in docs/kernel/entity-context-system.md (outside
   this cache unit). Keep only the cache-contract doc.
7. Regenerate the RAW evidence transcript (test_results/phase2-evidence-r2.log) and include a scope check:
   `git diff --name-only` + `git diff --stat` for the phase files (proves exactly which files changed).

## Prohibitions (unchanged)
- No DDL/migration; no MySQL-8-only SQL; no capability-dispatch/data-fetch change; no DefaultEntityRenderer
  behaviour change beyond what is required for deterministic key derivation (if you must touch it, make the change
  purely additive/read-only and justify in your report); no ARK/CMS; no other roadmap phases; no full-suite runs.
- Only: kernel/DiSyL/Component/ComponentRenderer.php, kernel/DiSyL/Cache/FragmentStore.php (minimal observability),
  kernel/EntityContext/EntityViewResolver.php (invalidator), tests/entity_view_render_cache_test.php,
  docs/kernel/entity-context-system.md (cache contract only).

## Verification (capture RAW to test_results/phase2-evidence-r2.log)
- php -l on changed files; new + existing targeted tests full output; PHPStan level 6 on changed files;
  git diff --check; git diff --name-only + --stat; logs before/after (error.log must stay empty).

## Return format (final message; concise; file:line refs)
status: PASS | FAIL | PARTIAL | BLOCKED | REVIEW_REQUIRED
findings_addressed: (#1..#7: how + file:line + key assertions)
changed:
implementation_summary:
verification: (point to test_results/phase2-evidence-r2.log; summarize counts)
risks:
unresolved:
recommended_next_state: (REVIEW_REQUIRED if all findings addressed)
Do NOT return empty. Do NOT report success without the raw evidence file complete.
