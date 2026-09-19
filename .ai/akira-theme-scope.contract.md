# CONTRACT — Akira CMS THEME scope · P0 runtime safe-fallback (fail-closed)

repo: `/var/www/html/ikabudsix`
slice: P0 only — the "Runtime safe-fallback" phase of `.ai/ark-direction.md` "Phased roadmap".
P1 (slot contract; identity/module boundaries; unified theming) and P2 (safety/CSP, `archive.disyl`
inline handler) are **follow-on slices and explicitly out of scope**. Do not start them here.

```yaml
harness:
  references: .ai/ai-autonomy-harness.contract.md
  autonomy: L0-L3 unattended; L4 defers to the director
  decisions_dir: .ai/decisions
  decision_transport: harpp      # the real HARPP on PATH, or .ai/harpp-sim when simulating
  evidence: real command output and exit codes; no claim without evidence
```

## Objective

Make the north-star promise "unknown fields are hidden" **true at runtime** for the ARK/Akira theme
entity-view path. Closes the P0 finding in `.ai/ark-direction.md`: internal fields can still reach
rendered output because wildcard field discovery is row-derived and `visible_fields` is neither
preserved nor interpreted presence-sensitively.

Read-only code facts this slice is bounded by (re-verify at checkout; the implementer must not assert
anything not read):

- `kernel/EntityContext/EntityViewResolver.php:306` — `resolve()` is the list data path.
- `kernel/EntityContext/EntityViewResolver.php:429` — `resolve()` computes `display_fields` from
  `array_keys($rows[0])` intersected with `DefaultEntityRenderer::SAFE_FALLBACK_FIELDS` whenever the
  contract's field list is not an array. This is row-derived field discovery.
- `kernel/EntityContext/EntityViewResolver.php:142` — `registerView()`; its `$defaults` (`:146-169`)
  and its merge loops (`:193`, `:200`) never mention `visible_fields`, so a producer-supplied
  `visible_fields` is silently dropped before storage.
- `kernel/EntityContext/EntityViewResolver.php:149` and `:795` — default and generic-fallback contracts
  use `fields => '*'`.
- `kernel/EntityContext/EntityViewResolver.php:459` `resolveAsResult()` and `:562` `resolveDetail()` are
  alternate resolver paths that also read `$contract['fields']`.
- `kernel/EntityContext/DefaultEntityRenderer.php:42-45` — `SAFE_FALLBACK_FIELDS`, the governed
  presentation-safe allowlist (includes `id`, `status`, `price`; excludes `tenant_id`, `cost`, `notes`,
  `tokens`).
- `kernel/EntityContext/DefaultEntityRenderer.php:96` — `doRenderList()`.
- `kernel/EntityContext/DefaultEntityRenderer.php:136` — `$visibleFields = $view['visible_fields'] ?? [];`
- `kernel/EntityContext/DefaultEntityRenderer.php:143-149` — wildcard expansion: `$allKeys` comes from
  `array_keys($rows[0])` (`:144`); presence is tested with `array_key_exists('visible_fields', $view)`
  (`:145`); intersection happens at `:146`/`:148`.
- `kernel/EntityContext/DefaultEntityRenderer.php:153-169` — field validation uses `$firstRowKeys` from
  `array_keys($rows[0])` (`:153`); fields absent from row 0 are dropped, so the field list is not
  applied consistently to every row.
- `kernel/EntityContext/DefaultEntityRenderer.php:327-334` — `renderDetail()` expands wildcard with
  `$view['visible_fields'] ?? self::SAFE_FALLBACK_FIELDS` and `array_keys($entity)`; a non-array
  malformed value can raise a `TypeError` out of `array_intersect`.
- `kernel/DiSyL/Component/ComponentRenderer.php:1488,1508,1607-1608` — the producer builds the visible
  set from `visible="false"` exclusions but only attaches it with `if (!empty($visibleFields))`, so an
  explicit all-hidden set is indistinguishable from absent at registration.
- Escaping/typing already exists on the paths read and must be preserved:
  `DefaultEntityRenderer.php:350-351` (detail label/value), `:373-378`, `:483`, `:507` (`renderCell` /
  fallback), `:519`, `:526` (compact), `:554`, `:561`, `:565` (card grid), `:656` (table cell).

Scope outcome: the resolver and renderer fail **closed** — wildcard display fields come from governed
metadata only, never from any row; `visible_fields` is presence-sensitive; every value stays
escaped and typed.

## Architectural constraints

- The approved contract is the permission envelope. L0-L3 proceed unattended; only an L4 condition
  stops the run with a filed decision (`tools/ai-autonomy.php defer`).
- Autonomy is L0-L3 unattended; L4 defers to the director. Per `.ai/ai-autonomy-harness.contract.md`,
  a second failed repair attempt on the same failure is an L4 stop, not a third attempt.
- No P1 or P2 work: no slot-contract changes, no README/slot count changes, no identity or module
  boundary moves, no theming unification (tokens/Tailwind), no CDN removal, no security/CSP scan, and
  no `archive.disyl` inline-handler change.
- No schema, DDL, migration or persistence change of any kind.
- No authentication, authorisation, policy, capability or security change. This slice must not weaken
  or alter any authority path, capability policy row, or CSRF behaviour.
- No new runtime or development dependency; no change to `composer.json`.
- Do not modify any file listed in "Forbidden changes"; those are standing surfaces and the harness
  files.
- Do not weaken, skip, delete or loosen an existing test or quality gate to reach a pass. A `SKIP` is
  never reported as a pass.
- No `git add`, commit, push, branch creation or switching. Leave the working tree as found plus the
  changed source and the new test.
- Fail closed, do not throw: malformed `visible_fields` (non-array, or array with non-string members)
  must render nothing beyond the governed allowlist and must not surface a `TypeError`/exception to the
  caller.
- Field discovery must not read any row. No display-field list may be derived from row keys, including
  `rows[0]` or the detail entity's keys. Requested fields are intersected with contract metadata and
  the result is applied identically to every row.
- `SAFE_FALLBACK_FIELDS` stays the single governed allowlist. Its membership is **unchanged** in this
  slice: do not add or remove `id`, `status`, `price`, or any other entry. Reassessing allowlist
  membership (per the P0 note in `.ai/ark-direction.md`) is a separate, director-approved change and
  must be filed as an L4 decision if the implementer believes it is required.
- Preserve the existing typed/escaped rendering path; no raw interpolation of values.
- Keep changes additive and minimal; do not restructure `registerView()`'s provenance or cache logic.
- Evidence or it did not happen: report real command output, real exit codes, and the third number
  (what never ran).

## Files likely affected

- `kernel/EntityContext/EntityViewResolver.php` — carry `visible_fields` through `registerView()` defaults and merge with presence semantics (absent vs explicit empty both preserved); stop computing `display_fields` from `array_keys($rows[0])`; keep resolver field lists metadata-driven.
- `kernel/EntityContext/DefaultEntityRenderer.php` — presence-sensitive wildcard expansion in `doRenderList()` and `renderDetail()`; intersect metadata with requested fields; apply one field list to every row; keep typed/escaped cell rendering; handle malformed metadata without throwing.
- `kernel/DiSyL/Component/ComponentRenderer.php` — producer seam: stop dropping an explicit all-hidden visible set; preserve absent vs explicit-empty when building and registering the view contract.
- `tests/akira_theme_safe_fallback_test.php` — new regression suite proving the fail-closed behaviour (see Required tests).

## Acceptance criteria

- **A1 — Presence-sensitive visible metadata.** Both `doRenderList()` and `renderDetail()` test `visible_fields` by presence, not truthiness. Absent metadata fails closed for wildcard (governed allowlist only). Explicit `visible_fields: []` renders **zero** field values. Explicit non-empty metadata renders exactly the intersection of that set with the requested fields. Malformed metadata (non-array, or non-string members) fails closed without throwing.
- **A2 — No row-derived discovery.** No display-field decision in `EntityViewResolver` or `DefaultEntityRenderer` reads row keys. Specifically, `EntityViewResolver.php:429` no longer derives `display_fields` from `array_keys($rows[0])`, and `doRenderList()` no longer derives `$allKeys`/`$firstRowKeys` from `array_keys($rows[0])`.
- **A3 — Wildcard resolves only from approved metadata.** `'*'` or `['*']` resolves to the explicit visible set when present, otherwise to `SAFE_FALLBACK_FIELDS` only. The result is intersected with the requested fields and applied to every row identically.
- **A4 — Cross-row consistency.** A field that is absent from row 0 but present in a later row is not dropped solely because of row 0. Missing values render consistently (empty) through the typed/escaped path, and the same field list applies to every row.
- **A5 — Escaping and typing preserved.** Every value continues through `renderCell()` / `htmlspecialchars`; `php -l` is clean and `vendor/bin/phpstan analyse -c phpstan.neon` stays clean for the changed files.
- **A6 — Regression proof.** The new test asserts that `tenant_id`, `cost`, `notes`, `tokens` and other non-allowlisted keys never appear in rendered output for wildcard, absent and malformed metadata, across table, compact, card_grid and detail modes. The test must fail for a permissive implementation (it asserts absence of internal keys, not merely presence of allowlisted ones).
- **A7 — Mechanism only.** `SAFE_FALLBACK_FIELDS` membership is unchanged; no module/view-contract data changes are made in this slice.

## Required tests

Existing tests that must stay green (run each; report pass/fail and exit code):

- `php tests/entity_fallback_test.php` — resolver generic fallback and registered-contract priority.
- `php tests/default_entity_renderer_post_row_action_test.php` — `DefaultEntityRenderer::renderList()` output.
- `php tests/entity_view_resolver_version_fallback_test.php` — resolver registration/fallback.
- `php tests/entity_list_result_sort_validation_test.php` — resolver sort validation.
- `php tests/entity_cell_renderer_registry_test.php` — cell renderers and escaping.
- `php tests/entity_view_render_cache_test.php` — resolver view-contract caching.
- `php tests/architecture_component_integration_test.php` — resolver integration.
- `php tests/disyl_v11_verify_test.php` — registered view contract via `EntityViewResolver`.
- `php tests/ai_autonomy_test.php` — standing harness suite (must not be edited) stays green.

New test file that proves this slice:

- `php tests/akira_theme_safe_fallback_test.php` (auto-discovered by `scripts/run-tests.php`) with at least these cases, each assertion attributable to a failure message:
  1. Wildcard contract with **absent** visible metadata renders only `SAFE_FALLBACK_FIELDS` keys; assert `tenant_id`, `cost`, `notes`, `tokens`, and a synthetic provider key are absent from the HTML in table, compact and card_grid modes.
  2. Contract with explicit `visible_fields: []` renders no field values (detail mode included).
  3. Contract with explicit non-empty `visible_fields` renders exactly that set and nothing else.
  4. Malformed `visible_fields` (a string, an int, and an array containing non-string members) fails closed with no exception escaping and no non-allowlisted key rendered.
  5. A field present only in `rows[1]` (absent from `rows[0]`) is still rendered for row 1 and the same field list is used for every row.
  6. Resolver-level: after `registerView()` with and without `visible_fields` (including explicit empty), `viewContract()` preserves presence; and `resolve()` `display_fields` is derived from contract metadata, not from row keys (verified against a stub capability result).
  7. `php -l` on every changed PHP file and a real `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G` run over the changed files, with output recorded.

## Risks

- Failing closed changes visible output for wildcard views that previously leaked row-derived fields; this is the intended fix, so screens may show fewer columns until contracts declare `visible_fields`.
- Fixing only `DefaultEntityRenderer` but not `EntityViewResolver::registerView()` or the `ComponentRenderer` producer leaves the feature unreachable end to end; all three seams must be covered.
- Deriving the intersection from a row again (a tempting "just intersect with row keys") silently reintroduces the bug; the new test must fail if any row-derived discovery is restored.
- `renderDetail()` currently uses `??` plus `array_keys($entity)`; making it presence-sensitive must not regress existing detail escaping.
- Touching `registerView()` merge logic could disturb `_provenance` or the resolved-cache invalidation; keep the change additive.
- A permissive test (asserting only that allowlisted fields render) would pass on the broken code; assert the absence of internal fields explicitly.
- `SAFE_FALLBACK_FIELDS` membership changes are tempting while here but are out of scope; if judged necessary, file an L4 decision instead of editing the allowlist.

## Forbidden changes

- `migrations/` — no schema, DDL or migration change.
- `phpstan-baseline.neon` — no quality-gate baseline edit.
- `composer.json` — no dependency change.
- `tools/ai-autonomy.php` — standing autonomy driver; no edits.
- `tests/ai_autonomy_test.php` — standing harness tests; no edits.
- `.github/instructions/ai-autonomy-escalation.instructions.md` — standing autonomy policy; no edits.
- `kernel/Workbench/Schemas/development-decision-request.v1.schema.json` — standing decision schema; no edits.
- `kernel/Workbench/Development/` — no edits to Workbench development classes.
- `.ai/harpp-sim/` — simulation harness; no edits.
- `modules/` — no module behaviour, manifest or capability changes.
