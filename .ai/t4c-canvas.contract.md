# T4c CONTRACT — Structural canvas for the Akira composer

status: READY_FOR_IMPLEMENTATION
owner: implementation agent (Sol, low reasoning)
repo: /var/www/html/ikabudsix — branch from current `main` (HEAD >= 3329e78)

## objective

Add a **structural canvas** to the Akira composer so operators can see, select,
reorder, duplicate, delete and edit the blocks of a composition without hand-editing
JSON — while staying inside the existing governance boundary.

## hard constraints (from docs/architecture/cms-akira-extensibility-adr.md)

The ADR is explicit about T4. Violating any of these fails review:

- "React canvas edits JSON only" — the canvas is a **projection of the canonical tree**,
  never a second source of truth.
- "preview = server render of draft" — preview must be the server-rendered output.
- **No client-authoritative rendering**: do NOT reimplement block/theme rendering in JS.
  The JSON textarea, the tree passed to the API, and the canvas must always agree.
- No HTML-as-source. No new npm dependencies. No kernel/DiSyL/theme-definition changes.
- No new capabilities and no capability-policy changes in this phase.

## current state (main @ 3329e78)

`modules/cms-akira/cms-akira-builder/admin-ui/src/app.tsx` (React 18 + Vite, only dep
`react`/`react-dom`) renders two columns:

- left: Title, "Add a theme block" (catalogue select + schema-driven prop form + Add),
  `Tree (JSON)` textarea, Change note, `Validate` / `Create & save draft`|`Save draft`
- right: Revisions list, "Preview (server-rendered)" buttons + `iframe sandbox="" srcDoc`,
  Lifecycle (Publish / Unpublish / Delete)

The tree is held as `treeText` (JSON string); `currentTree()` parses it and every mutation
re-encodes it (`setTreeText(encodeTree(tree))`). Existing helpers: `parseTree`,
`encodeTree`, `canonicalBlocks`, `cloneDefaults`, `seedValue`, `inputValue`, `setProp`,
`addArrayRow`, `updateArrayRow`, `addBlock`, `selectedDefinition`, `blockProps`.

**Missing (this phase):** there is no way to see the block list, select a block for
editing, reorder, duplicate or delete an individual block — all of which currently
require hand-editing the JSON.

## required changes

1. **Canvas panel** (new component file is fine, e.g. `src/Canvas.tsx`) rendering the
   composition's blocks in document order as cards. Each card shows:
   - ordinal + the catalogue `label` for that block id (fallback to the raw block id if
     the id is no longer in the catalogue — must not throw) and its `category`
   - a short props summary (e.g. the first non-empty string prop, truncated)
   - per-block actions: **Select**, **Move up**, **Move down**, **Duplicate**, **Delete**
   - the selected card is visually marked (`aria-selected` / `aria-current`)
   - blocks with an id absent from the catalogue are flagged as **unknown block** in the
     canvas (the server will reject them on validate) — this is diagnosis, not a crash

2. **Selection-driven editing**: the existing schema-driven prop form must edit the
   **selected block's** props (writing through `setProp` into that block of the tree),
   not only seed a new one. "Add block" keeps working and appends, selecting the new block.
   Changing the selected block's *type* via the catalogue select is allowed (resets props
   to that definition's defaults).

3. **All ordering/duplication/deletion must mutate the canonical tree** and re-encode it,
   so the JSON textarea reflects every canvas action immediately (and vice versa: an
   external JSON edit re-renders the canvas). Guard malformed JSON: while the textarea is
   invalid, keep the last valid canvas projection and show the existing parse error rather
   than crashing.

4. **Reordering accessibility**: Move up/down buttons are mandatory (keyboard-operable).
   Native HTML5 drag-and-drop (`draggable`, `onDragStart`/`onDragOver`/`onDrop`) is
   permitted as an *additional* affordance — no drag library may be added.

5. **Preview after save**: after a successful `Save draft`, automatically re-render the
   preview (existing `/render?source=preview`) so the canvas → save → preview loop is one
   action. Keep the manual buttons. Label the preview clearly as the **last saved draft**;
   do NOT imply unsaved edits are previewed. No new endpoint may be added for this.

6. **Styling** in `src/styles.css` using the existing `ab-` prefix/conventions; keep the
   layout usable at narrow widths (single column).

7. **Docs**: update `modules/cms-akira/cms-akira-builder/README.md` with the canvas
   workflow and the "canvas is a projection of the canonical tree; server validates and
   renders" rule.

8. **Build**: `npm run type-check && npm run build` and commit the regenerated
   `public/admin/assets/cms-akira-builder/**` artifacts together with the source.

## scope

allowed:
- `modules/cms-akira/cms-akira-builder/admin-ui/src/**`
- `modules/cms-akira/cms-akira-builder/README.md`
- `modules/cms-akira/cms-akira-builder/tests/**` (only if an existing assertion must be
  updated for the new UI; do not weaken assertions)
- regenerated `public/admin/assets/cms-akira-builder/**`

prohibited:
- `kernel/**`, `storage/cms-themes/**`, `src/**`, other modules' PHP
- new npm dependencies, new capabilities, manifest/policy changes, DB changes
- client-side block rendering or any HTML-as-source behaviour

## acceptance

- `npm run type-check` clean; `npm run build` succeeds and updates the bundle
- canvas lists existing blocks in order for `t4a-page` on tenant 54 (hero, card-grid) with
  catalogue labels
- selecting a card loads its props into the form; editing a prop updates the JSON textarea
  immediately
- Move up/down reorders the JSON blocks array; Duplicate inserts an identical copy
  (deep-cloned props); Delete removes the block
- Add block appends and selects the new block; the select can retype the selected block
- after Delete/Reorder the tree still validates server-side via `/validate`
- Save draft then preview reflects the saved tree (iframe shows themed blocks)
- malformed JSON does not crash the canvas
- shell contract test still 100/100; builder module tests unchanged in status
  (NOTE: `builder_contract_test.php` fails on this multi-tenant box for a **pre-existing**
  unrelated reason — synthetic test tenants 994701/994702 have no module activation, so the
  kernel's Activation-Before-Participation gate denies the provider. Do NOT try to "fix"
  that here; it needs an architecture decision.)
- `php ikabud architecture:check` clean

## verification recipe (tenant 54, host `akiracms.test`)

```bash
curl -s -c /tmp/ck.txt -H "Host: akiracms.test" http://127.0.0.1/login -o /dev/null
curl -s -b /tmp/ck.txt -c /tmp/ck.txt -H "Host: akiracms.test" -H "Content-Type: application/json" \
  -X POST http://127.0.0.1/api/v1/auth/login \
  -d '{"username":"charlienacario884","password":"iKabud6123!#"}'
curl -s -b /tmp/ck.txt -H "Host: akiracms.test" http://127.0.0.1/api/v1/cms-akira-theme/blocks
curl -s -b /tmp/ck.txt -H "Host: akiracms.test" http://127.0.0.1/api/v1/cms-akira/builder/compositions/t4a-page
```

- reuse the existing `t4a-page` composition (published). Do **not** leave tenant data
  mutated: if you save drafts while testing, restore the original tree afterwards and say
  so in the report. Prefer exercising reorder/duplicate/delete in the browser/client state
  and only saving when you intend to keep it.
- `update` requires a positive integer `base_revision_id`; `create` must not send it.

## deliverable

Report the implementation result block (status / changed / verification / scope / risks /
unresolved / recommended_next_state) with raw command outputs as evidence. Do not claim
success without evidence.
