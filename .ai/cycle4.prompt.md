# CYCLE 4 CONTRACT — Provenance drawer + "view as of" (pillar P1)

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, low reasoning)
repo: /var/www/html/ikabudsix — branch from current `main` (HEAD >= a7d9c01)
read first: `docs/architecture/akira-product-direction.md` (thesis + binding constraints)

## objective

Make a composition's history **provable in the product**: a unified, governed timeline per
entity, a block-level diff between revisions, and the ability to render any historical
revision ("view as of"). This is the positioning demo for pillar P1.

## hard constraints (Ikabud philosophy)

- New behaviour arrives as a **declared capability** with `exposes` (+ `depends`), never as
  a direct cross-module call. Reads are governed too: an unauthorized caller must be denied,
  and that path must be tested.
- **No cross-module table access**; `php ikabud architecture:check` is the arbiter.
- **Deterministic server render.** No HTML-as-source, no client-side block rendering.
- **No new npm dependencies.** The canvas/drawer edits JSON-facts and calls server endpoints.
- This cycle is **read-only**: no new writes, no schema changes, no migration.
- Do NOT `git commit`, push, or branch. Leave changes uncommitted for chair review.
- Do NOT touch `.ai/**`. Back up `storage/modules.json` before running the suite and restore
  it afterwards (gui-settings ON, all `cms-akira-*` ON, `daily-ledger` OFF).

## required behaviour

1. **Provenance timeline capability** (read-only, governed, tenant-scoped) exposed by
   `cms-akira-builder`: given `entity_type` (post) + `entity_key`, return ONE ordered
   timeline combining, at minimum:
   - composition revisions: `revision_id`, `base_revision_id`, author, `change_note`,
     `created_at`, and whether it was ever the published pointer (`published_revision_id`
     history / publication entries)
   - audit entries for that entity from `audit_logs` scoped to the module: action, actor,
     timestamp, and `correlation_id` / `request_id` when present
   - publish / unpublish events with timestamps and actor
   Use the kernel's existing audit records — do NOT invent a parallel log table. If some fact
   is not recoverable from existing records, **report that** instead of fabricating it.

2. **Revision-scoped render** via the EXISTING `akira.builder.render@1` capability: add an
   optional `revision_id` to its payload (same capability id ⇒ no new policy row) that renders
   that stored revision read-only. Validate it belongs to the tenant's composition; unknown or
   foreign revision ids fail closed (404-style error, never another tenant's data).

3. **Semantic diff**: given two revisions (or a revision + the current draft), return a
   block-level diff as JSON: blocks added, removed, reordered, and props changed (per block,
   per prop, old → new). No HTML diffing, no DOM comparison.

4. **UI** in the composition editor's React app (`admin-ui/`):
   - a "History & provenance" drawer listing the timeline (time, actor, action, capability,
     note, correlation id when present)
   - per revision: **View as of** → renders that revision in the existing sandboxed preview
     iframe via `render@1 {revision_id}`
   - a compact, readable diff between the selected revision and the current draft
   - reuse existing styles/`ab-` conventions; keep it usable at narrow widths

5. **Tests** (extend, do not weaken):
   - builder contract test: the timeline capability is exposed and tenant-scoped; rendering a
     foreign/nonexistent revision fails closed; the diff is correct for a known revision pair
   - the capability's denial path for an unauthorized caller
   - shell contract test: if the drawer is shell-hosted, assert the mounting contract

## acceptance (evidence required for each)

- `php ikabud architecture:check` clean; `php -l` on touched PHP; cs-fixer `Fixed 0 of N`;
  phpstan `[OK] No errors` on touched files
- if a new capability was added: its policy row exists for tenant 54 (show the command and
  the query output) and the denial path is proven
- live on tenant 54 (host `akiracms.test`): the timeline for `t4a-page` lists its revisions
  (r3…r6 as present) with actors/notes plus publish/unpublish events; a historical render of
  an earlier revision differs from the current one when their trees differ
- browser: the drawer opens in the composition editor, "View as of" renders the chosen
  revision in the preview iframe, and the diff view shows the changes — capture the evidence
- `npm run type-check` clean; `npm run build` run and the regenerated bundle committed with
  the source
- existing suites unchanged in status: `php scripts/run-tests.php` must stay
  `0 failed` (skips allowed), and the shell contract test must stay green

## environment notes

- Login for tenant 54 (host `akiracms.test`): see the recipe in
  `docs/kernel/` or session memory — `curl -c /tmp/ck -b /tmp/ck -H "Host: akiracms.test"
  -H "Content-Type: application/json" -X POST http://127.0.0.1/api/v1/auth/login -d
  '{"username":"charlienacario884","password":"iKabud6123!#"}'`
- `storage/cache/compiled` may not be writable by the CLI user; the engine now degrades to
  the interpreted pipeline with a warning. That is expected — do not "fix" it.
- The runner deletes `storage/modules.json`; back it up first and restore after.
- Builder `update` requires a positive integer `base_revision_id`; `create` must not send it.

## deliverable

Implementation result block (status / changed / verification / scope / risks / unresolved /
recommended_next_state) with raw command output and the browser evidence. Do not claim
success without evidence; report anything you could not verify.

---
CHAIR NOTES:
- Work on current main. Do NOT commit/push/branch. Leave everything uncommitted.
- Read docs/architecture/akira-product-direction.md first: the philosophy section is binding.
- Prefer extending an existing read-only capability (e.g. a revision_id on akira.builder.render@1) over inventing new authority surfaces; if you must add a capability, declare it and prove the tenant-54 policy row plus the denial path.
- Report anything you cannot verify rather than guessing. Evidence over assertion.
