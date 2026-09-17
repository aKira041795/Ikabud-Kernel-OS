# OBJECTIVE — STAR SWARM: a themable Galaga-style game module

system: HARPP v2 · director's project, 2026-09-16 · **new project, not the Akira plan**
goal: **playable over HTTP on localhost when the director returns.** That is the acceptance.

## Scope

- `modules/star-swarm/`
- `templates/modules/star-swarm/`
- `public/star-swarm/` (static assets, if the kernel's asset path requires it)
- `tests/`

## Acceptance

```
$ php tests/star_swarm_game_test.php
```

…and **the live proof, which is the point**: a `curl` of the game URL on localhost returning HTTP 200 with the game
markup present. Report the exact URL and its status line, verbatim.

## The deliverable

A **Galaga-style fixed shooter** built the ikabud way:

| Piece | Requirement |
|---|---|
| Module | `modules/star-swarm/module.json` — id `star-swarm`, no migrations, no tables, **no database use at all** |
| Page | rendered by a **DiSyL** template, `templates/modules/star-swarm/star-swarm.disyl` |
| Theme | **ARK/theme tokens** drive every colour and size — the game must look different under a different theme without editing the game |
| Engine | the game itself is client-side JavaScript on a `<canvas>` in one file; no framework, no CDN, no new dependency |
| Reachability | `GET /star-swarm` returns the page; assets load from the same origin |

**Gameplay — it must actually be a game, not a mockup:**

- player ship moves left/right (arrow keys **and** A/D; also usable on a touch screen if trivial to add);
- a **formation** of enemies that sways, then dives at the player;
- player fires (space / up); enemies fire back;
- **waves**: when a formation is cleared, the next wave starts, faster or denser;
- collision, score, lives, and a **game over with restart** that works without a page reload;
- a start screen and a paused state — the page must not require a click-capture hack to take focus;
- `requestAnimationFrame` loop with delta time, so it does not run at a different speed on a slower machine.

**Themable, concretely — this is a stated requirement, not a nicety.** Read the theme's tokens (the ARK/`akira-editorial`
design-token mechanism already used by the public layout — find it, do not invent a second convention) and inject them
as CSS custom properties on the game root. Every colour the game draws must come from a token **with a fallback**, so
the game still renders if a token is missing. State in the report, as a table, each token → what it paints.

**No database, no tenant work.** Do not add migrations, do not create tables, do not activate anything for a tenant.
Prefer, in order:

1. **Tier 1 — the module route**, served by the kernel: `GET /star-swarm` → the handler → the DiSyL template. A public
   route cannot be *declared* (CD-56 Finding 1: anonymous dispatch has no actor and therefore no role), so it takes a
   **reasoned exemption** in the manifest — the same mechanism the shell's `sitemap.xml` uses. Local serving facts:
   the app answers on `127.0.0.1` with tenant host `akiracms.test` (tenant 54, entry `cms-akira-shell`).
2. **Tier 2 — if, and only if, Tier 1 needs a database write to become reachable: deliver playability anyway.**
   Render the DiSyL template through the kernel from the CLI into a static entry (`public/star-swarm/index.html`) and
   serve the JS/CSS beside it, so `http://127.0.0.1/star-swarm/` is playable with **zero** database involvement. The
   DiSyL template remains the source of the markup — do not hand-write a second copy of the page.

**Tier 2 is not a failure and not a fallback of last resort: it is the required outcome if Tier 1 is blocked.** The
director must be able to open a URL and play. Stopping with "a database write is required" and delivering nothing is
the one outcome this item forbids. State which tier you delivered, and why, in one sentence.

Also note which of the two the **test** exercises, so the evidence matches the delivered tier.

## The test must prove the page serves — and a browser must prove it PLAYS

**Tier A — `tests/star_swarm_game_test.php`** — deterministic, no database:

- the module manifest is valid and declares the route;
- **every** `.disyl` in the template directory passes `php _lint_disyl.php <file>`;
- the route maps to a handler that exists (`module-id:functionName`);
- the served markup contains the canvas and the asset references;
- the game JS contains the mechanics it claims (formation, fire, wave, score, restart) — assert on named functions or
  constants, not on vibes;
- **the token contract**: the page injects at least the tokens the JS consumes, and each has a fallback.

**Tier B — `tests/browser/star-swarm.spec.ts`, and this is the one that decides "good for playing".** A page that
returns 200 with a canvas and a dead game is a **false completion**, and markup assertions cannot tell the difference.
Playwright is already installed and the suite already drives the live tenant — use it:

- the page loads and **no console errors** are emitted on boot;
- the canvas is visible and has non-zero size;
- the game **boots**: the exposed state (`window.StarSwarm` or equivalent) reports a started run with score and lives;
- **input actually does something**: a key press changes observable state — the ship's x moves on ArrowRight, and fire
  creates a projectile. Assert on state, not on pixels;
- **the loop runs**: state advances between two samples taken a second apart without further input (enemies move);
- leaving the page clean: no unhandled rejection, no runaway timers.

Run it, and report its pass/fail/skip counts verbatim. **If Tier B cannot run in this environment, say exactly why and
what remains unproven** — an unproven claim stated as unproven is acceptable; a "playable" claim resting only on
markup is not.

## Boundaries

The constitution's list, plus: **no new dependency**, no CDN, no build step, no bundler, no schema, no database write.
`php -l` clean on every PHP file, and `php _lint_disyl.php` clean on every template. An existing test may not be
edited. Do not weaken a check to obtain a pass.
