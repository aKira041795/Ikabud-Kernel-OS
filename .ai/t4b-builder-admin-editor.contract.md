# T4b CONTRACT — Schema-driven Akira builder admin editor

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, low reasoning)
repo: /var/www/html/ikabudsix (branch from current `main`; HEAD should be >= 7bd5498)

## objective

Make the existing Akira builder admin editor **driven by the canonical theme block
contract** (T2 block definitions + T4a builder binding) instead of a hardcoded legacy
block list, and make every tree the editor can produce pass T4a validation.

## current state (verified on main, 2026-09-10)

The editor is a React app at `modules/cms-akira/cms-akira-builder/admin-ui/`, built to
`public/admin/assets/cms-akira-builder/assets/cms-akira-builder-admin.js` (bundle is
committed and served by the shell at `/cms-akira-shell/compositions[/{key}/edit]`).

It is **broken against T4a** — verified live against tenant 54:

| # | defect | live evidence |
|---|---|---|
| G1 | `Block.type` used instead of canonical `block` key (`src/types.ts`, `src/app.tsx`) | `POST /api/v1/cms-akira/builder/validate` with `{"type":"hero",...}` → `{"ok":false,"error":"block contains unknown fields: type."}` |
| G2 | hardcoded `const ALLOWED = ['section','heading','paragraph','rich_text','image','button']` (`src/app.tsx:6`) — none exist in the theme | `{"block":"heading"}` → `{"ok":false,"error":"Unknown theme block: heading."}` |
| G3 | `sampleBlock()` hardcodes legacy prop names (`text`, `level`, `content`, `src`, `label`, `url`) | theme `hero` expects `eyebrow/title/subtitle/cta_label/cta_href` |
| G4 | committed bundle was built **before** T4a (Sep 8) | needs rebuild after source fix |

Ground truth catalogue: `GET /api/v1/cms-akira-theme/blocks` (admin session) returns
`{ok, theme_slug, blocks:[{id, label, category, schema:{props:{<name>:{type,label,default}}}, defaults, slots, renderer}]}`.
Current `akira-ark` blocks: `hero, richtext, card-grid, quote, cta` (see
`storage/cms-themes/akira-ark/block-definitions.json`).

## scope

allowed:
- `modules/cms-akira/cms-akira-builder/admin-ui/src/**` (types.ts, api.ts, app.tsx, styles.css as needed)
- `modules/cms-akira/cms-akira-builder/admin-ui/package.json` ONLY if a script is genuinely missing
- `modules/cms-akira/cms-akira-shell/handlers.php` — inject the catalogue endpoint into the
  builder bootstrap (one field; no other shell behaviour changes)
- `modules/cms-akira/cms-akira-builder/tests/**` (new/extended tests)
- regenerated bundle under `public/admin/assets/cms-akira-builder/**` (committed artifact)
- `modules/cms-akira/cms-akira-builder/README.md` (document the catalogue wiring)

prohibited:
- no changes to `kernel/**`, DiSyL engine, `storage/cms-themes/**`, or builder capability
  validation semantics (`cabBuilderValidateBlock` / `cabBuilderValidateProps` stay the authority)
- no new npm runtime dependencies
- no client-side HTML rendering of blocks (server `/validate` + `/render` remain authoritative)
- no hardcoded fallback block list anywhere
- no `git add -A` (untracked `.ai/**` and `storage/cms-themes/akira-ark-demo/` must stay untracked)
- no tenant DB schema/data changes

## required changes (decision-locked)

1. **G1** — canonical tree shape everywhere:
   ```ts
   export interface Block { block: string; props: Record<string, unknown>; children?: Block[] }
   export interface Tree { version: 1; blocks: Block[] }
   ```
   Replace every `type` usage (interfaces, encode/decode, sample builder, JSX keys, add/remove
   handlers). Trees emitted by the editor must contain `block`, never `type`.

2. **G2/G3** — remove `const ALLOWED` and the legacy `sampleBlock()` map. Source the catalogue at
   runtime from the theme blocks endpoint. The shell injects its path into the bootstrap JSON as
   `blocks_endpoint` (keep the existing `apiBase/mode/entity_key/posts` fields intact); `Boot` in
   `types.ts` gains `blocks_endpoint: string`; `api.ts` `readBootstrap()` reads it with a sane
   default of `/api/v1/cms-akira-theme/blocks`.

3. **Schema-driven block form** — the "add block" UI must be generated from the catalogue:
   - picker options from `{id, label, category}`
   - prop inputs from `schema.props`: `string` → text input, `url` → url input, `array` of objects
     (e.g. `card-grid.items`) → repeatable sub-rows with one input per item key, other types →
     text input with a type hint
   - prefill values from `defaults` when present
   - added block is `{ block: <id>, props: <prefilled defaults>, children: [] }`
   - the raw JSON tree editor stays as the power-user path

4. **Fail closed** — if the catalogue request fails, render an explicit error and disable adding
   blocks. Never silently fall back to a hardcoded list.

5. **G4** — rebuild the committed artifact: `npm run type-check && npm run build` inside
   `admin-ui/`, and include the regenerated files under
   `public/admin/assets/cms-akira-builder/` in the same commit as the source.

6. **Tests** — extend the builder module tests with a contract assertion that the emitted tree
   shape uses `block` (not `type`) and that every catalogue block id is accepted by
   `cab_builder_cap_validate_1`; keep all existing tests green.

## acceptance

- `npm run type-check` clean; `npm run build` succeeds and updates the bundle
- `grep -rn "section\|heading\|paragraph\|rich_text\|image\|button" admin-ui/src` shows no legacy
  block names left in block definitions
- for **every** block in `GET /api/v1/cms-akira-theme/blocks`, a tree `{version:1,
  blocks:[{block:<id>, props:<defaults>, children:[]}]}` posted to
  `POST /api/v1/cms-akira/builder/validate` returns `data.valid === true`
- an update `POST /api/v1/cms-akira/builder/compositions/{key}` with such a tree and a real
  `base_revision_id` returns `ok:true`
- preview (`/render?source=preview`) and publish/unpublish still work on tenant 54
- touched PHP: `php -l`, `php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <files>`
  (options before files), `php vendor/bin/phpstan analyse <files> --memory-limit=1G` clean
- `php ikabud architecture:check` clean
- all existing builder + shell tests pass

## verification recipe (tenant 54, host `akiracms.test`)

```bash
# login (cookie jar) — admin charlienacario884 / iKabud6123!#
curl -s -c /tmp/ck.txt -H "Host: akiracms.test" http://127.0.0.1/login -o /dev/null
curl -s -b /tmp/ck.txt -c /tmp/ck.txt -H "Host: akiracms.test" -H "Content-Type: application/json" \
  -X POST http://127.0.0.1/api/v1/auth/login \
  -d '{"username":"charlienacario884","password":"iKabud6123!#"}'
curl -s -b /tmp/ck.txt -H "Host: akiracms.test" http://127.0.0.1/api/v1/cms-akira-theme/blocks
curl -s -b /tmp/ck.txt -H "Host: akiracms.test" http://127.0.0.1/cms-akira-shell/compositions/t4a-page/edit
```

Notes:
- builder `update` requires a positive integer `base_revision_id` (optimistic concurrency);
  `create` must NOT send it.
- publish/unpublish/delete accept only `idempotency_key` (entity comes from the route).
- reuse the existing `t4a-page` composition (published) on tenant 54; do not create clutter.

## deliverable

Report the implementation result block (status / task / changed / verification / scope /
risks / unresolved / recommended_next_state) with the concrete command outputs used as
evidence. Do not claim success without evidence.
