# Contract — module-owned entity view contracts for Posts

status: READY_FOR_IMPLEMENTATION (investigation-gated)
role: /architect
authority: chair, 2026-09-12 (review: `.ai/akira-review-2026-09-12.md`, Gaps A + B)

---

## Owner's stated philosophy

> A module owns the truth. An Entity View exposes only the meaning needed for presentation.
> The module should not hand its tables, ORM objects, or raw business structures directly to the
> theme. Instead, it provides a presentation contract.
>
> **The implementation also deliberately makes Entity Views module-owned. CMS Akira's module manifest
> exposes the versioned Entity View bridges, rather than having the Kernel invent a universal Post
> schema.**

## Problem (evidence)

The semantic boundary for Posts is currently owned by a **tenant-installed, swappable theme**:

```
storage/cms-themes/akira-ark/entity-view-map.json
  "post": { "list":   { "fields": ["title","subtitle","metadata","image","url"], ... },
            "detail": { "fields": ["title","subtitle","body","metadata","image","url","categories"] } }
```

Consequences:

1. **Presentation semantics move with the theme.** Swapping a tenant's theme changes what the outside
   world may see about a Post — the semantic firewall the philosophy describes is not module-owned.
2. **No Akira module registers a contract.** A repo-wide search finds `loadViewConfigs()` used only by
   `modules/daily-ledger/handlers.php`, and `{ikb_entity_view}` only in
   `modules/daily-ledger/helpers/views/`. Akira has no `views/` directory.
3. **A fail-closed guard depends on the theme.** `cacPostViewRegistrationValid()`
   (`cms-akira-core/handlers.php:234`) reads `app()->entityViews()->registeredViewContracts()['post.'.$view]`
   and requires `provider === 'cms-akira-core'`, non-empty fields, and no `'*'`. `/posts` calls it and
   `cacPostRouteError()`s when it fails. **Unverified but implied:** a tenant with no renderer theme
   active may fail this guard and lose `/posts` — the opposite of fail-closed-for-the-right-reason.

Note: `viewContract()` cascades to `kernel.builtin` / `kernel.generic` and **never returns null**, so
`resolve()` still works without a contract; the guard is the exposure.

## Phase 1 — Investigation (mandatory, report before editing)

Determine and report, with file:line evidence:

1. **Who loads `entity-view-map.json`**, and how the entries reach
   `EntityViewResolver::$viewContracts` (which registration method, and what `provider` they carry)?
2. **Can a module declare its own view map**, or is `{ikb_entity_view}` (the engine-validated path)
   the only module-facing mechanism?
3. **What exactly does `cacPostViewRegistrationValid('list')` see** today, and what is `provider`?
4. **Does `/posts` still work when no renderer theme is active?** Prove it — do not assume.
5. What is the engine's validated view vocabulary (`table|compact|card_grid|detailed|summary`) versus
   the theme map's `list` / `detail`, and where does each get validated?

If the answers show this cannot be done without choosing between two competing view vocabularies across
five call sites, a fail-closed guard and the theme JSON contract, **STOP** and return
`BLOCKED / ARCHITECTURE_DECISION_REQUIRED` with the options. Do not invent a resolution.

## Phase 2 — Implementation (only after Phase 1)

Make the **module** the authority for the semantic field projection of its own entity types, so the
theme declares *renderers* (ARK's job: presentation choice) while the module declares *what may be
seen* (Entity View's job: presentation semantics).

Acceptance:

1. Akira's Post presentation contracts are declared by an Akira module, loaded deterministically at
   module load — not only by whichever theme happens to be installed.
2. `cacPostViewRegistrationValid()` passes because the **module** registered the contract, so `/posts`
   cannot fail merely because no renderer theme is active.
3. Existing rendering is unchanged: `/`, `/posts`, `/posts/{slug}` still return 200 and render the
   published post; the theme's ARK renderers are still selected as they are today.
4. The public list projection still exposes **no** domain internals (no `slug`, no `tenant_id`) — the
   composition selector derives identity from `url` via `akiraShellPostKeyFromUrl()`.

## Scope

allowed:
- new view contract files under `modules/cms-akira/cms-akira-core/helpers/views/`;
- the loader line in `modules/cms-akira/cms-akira-core/handlers.php`;
- the `cacPostViewRegistrationValid()` guard and its two call sites in core;
- one focused test.

prohibited:
- changing ARK renderer selection, theme templates, or the four body-rendering templates;
- weakening `cacPostViewRegistrationValid()` into a no-op to make `/posts` pass — it is a deliberate
  fail-closed guard;
- adding `slug` (or any domain internal) to the public projection;
- editing `phpstan.neon`, `phpstan-baseline.neon`, or `.governance-baseline.json`.

## Verification

- `php _lint_disyl.php <file>` for every `.disyl` touched — `php -l` on `.disyl` is **meaningless**
  (parsed as inline HTML) and reports a false pass.
- `php -l` for PHP files.
- `php scripts/run-tests.php` → **106 passed / 0 failed / 31 skipped** or better, new test discovered.
- Live check: `/`, `/posts`, `/posts/{slug}` all 200 with the post listed/rendered.
- `php ikabud workbench:governance --all --gate` → exit 0.

## Risks

- The engine validates `{ikb_entity_view}` view names against a closed vocabulary that does not include
  `list`/`detail`; the theme map bypasses that. Registering a module contract under a name the callers
  do not request would appear to work while changing nothing — verify with the actual resolver, not by
  reading code.
- `loadViewConfigs()` **throws** on any config error (fail-closed), so a malformed contract file takes
  down every request that loads the module. Lint before finishing.
