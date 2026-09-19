# Every character the game displays must have a glyph

## Objective

The pixel renderer silently skips any character it has no glyph for. Measured:

```
these characters are drawn by the game but the renderer silently skips them, so they vanish:
["/","#","%","&","*","+","=","<",">","?","@","[","]","{","}","~","^","|"]
```

**18 of them.** This is not hypothetical: the same defect already produced `P ASMA ANCE` for
`PLASMA LANCE`, and the instructions page still renders `A/D` as `A D`.

Add the missing glyphs so every character the game displays is actually drawn.

## Architectural constraints

- **The glyph tables are `EXTRA_SHIP_GLYPHS` and `OPENING_GLYPHS`.** The renderer looks up a
  character and skips it when absent. Add the missing entries to the table the renderer actually
  reads — adding a glyph to the wrong table is the exact mistake that produced `P ASMA ANCE`, and it
  looks like success because nothing errors.
- Glyphs are pixel matrices in the existing style; match the surrounding entries in shape and size.
  A glyph that is the wrong height or width will render misaligned beside its neighbours.
- **The probe tests 18 characters.** All 18 must be present. Fixing some and not others still fails,
  and the probe names which are missing, so there is no need to guess.
- Do not remove any existing glyph. `A/D` renders `A D` today because `/` is absent, not because the
  other letters are wrong.
- Do not change the renderer's skip behaviour. A renderer that drew a visible placeholder for unknown
  characters would hide this class of defect rather than fix it, and would change the look of text that
  is currently correct.
- Bump the `?v=` asset query string in `index.html` to the new `star-swarm.js` mtime — a version guard
  asserts the query string equals the file's mtime.
- Vanilla JS on a canvas. No frameworks, no new files, no build step, no dependency.

## Files likely affected

- `public/star-swarm/star-swarm.js`
- `public/star-swarm/index.html`

## Acceptance criteria

- No character the game displays is silently skipped: `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p20" --reporter=line`
- The whole rendered-canvas suite still passes: `npx playwright test tests/browser/star-swarm-pixels.spec.ts --reporter=line`
- The PHP-side visual test still passes: `php tests/star_swarm_visual_test.php`

## Required tests

- `npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "@p20" --reporter=line`

## Risks

- **Adding to the wrong table.** Silent, and it looks like success. This is the recorded mistake.
- **Wrong glyph dimensions.** The character renders but sits wrong beside its neighbours; the probe
  only measures that something was drawn, so check a screenshot of the instructions page.
- **Fixing the characters the probe names and no others.** The probe enumerates them, so partial work
  is visible; the risk is stopping early rather than missing one.
- **Editing the probe.** It is the definition of done.

## Forbidden changes

- `tests/`
- `tools/`
- `kernel/`
- `modules/star-swarm/`
- Do not change the renderer's behaviour for unknown characters.
- Do not remove or alter existing glyphs.
- Do not edit, weaken, skip or delete any assertion in the acceptance spec, and do not add a
  `waitForTimeout` to make anything pass.
