// Star Swarm — Tier A: the pixels themselves.
//
// WHY THIS EXISTS (2026-09-19)
// tests/browser/star-swarm.spec.ts opens with "It reads state, never pixels." That sentence is
// exactly the blind spot the director hit: the state spec and all three phase gates were GREEN
// while the components looked nothing like Galaga.
//
//   * the Boss Galaga — the magnet ship — was drawn ORANGE (theme.roles.threat, #f78c6b)
//   * the backdrop was a gradient nebula with planets and rings, not black space
//   * explosions were anti-aliased stroked circles, not pixel starbursts
//   * stars were anti-aliased discs, not pixel blocks
//   * the extra ship was granted SILENTLY (state.lives += 1) and never announced,
//     so the player could not tell they had earned it
//
// Not one of those is visible to an assertion over state fields, which is why a green state
// spec proved nothing about the product. So this spec asserts on the RENDERED CANVAS.
//
// HOW IT STAYS DETERMINISTIC
// Each probe calls render() and reads the backing store inside ONE synchronous evaluate, so
// requestAnimationFrame cannot interleave and the pixels read belong to the frame just drawn.
// Probes that must not be fooled by other ink clear the field first, and probes that compare
// "before" and "after" take both samples inside the same evaluate.
//
// OWNERSHIP
// This file is CHAIR-OWNED, like the requirements contract and the gate. The rebuild lane
// implements the product; it must not edit this instrument. A lane that can pass by editing
// the instrument has proved nothing.
//
// Run:
//   TENANT_URL=http://akiracms.test npx playwright test tests/browser/star-swarm-pixels.spec.ts

import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? 'http://akiracms.test';
const GAME_URL = `${TENANT}/star-swarm/`;

/**
 * Installs the pixel probe before any page script runs. Everything the probes need is defined
 * here so a test body reads as assertions rather than as boilerplate.
 */
async function installProbe(page: Page) {
    await page.addInitScript(() => {
        const w = window as any;
        const canvas = () => document.getElementById('star-swarm-canvas') as HTMLCanvasElement;

        const classify = (
            data: Uint8ClampedArray,
            rowWidth: number,
        ) => {
            let green = 0;
            let cyan = 0;
            let bright = 0;
            let black = 0;
            let partial = 0;
            const rowMeans: number[] = [];
            // What a sprite is PAINTED WITH, not merely what colours appear in its box.
            // A formation box can overlap a neighbour or the nursery planet, and counting
            // raw colour hits then measures the neighbour: this was a real false pass on
            // 2026-09-19, where the boss box contained bees during the entrance animation
            // and the boss itself was never green. The dominant ink in the box is the sprite.
            const histogram = new Map<string, number>();
            let rowSum = 0;
            let rowCount = 0;
            for (let i = 0; i < data.length; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];
                const max = Math.max(r, g, b);
                const lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
                if (max < 26) black += 1;
                if (lum > 0.62) bright += 1;
                if (g > r + 24 && g > b + 24) green += 1;
                if (b > r + 20 && g > r + 12) cyan += 1;
                // A pixel-art edge is abrupt: lit pixels are either near-full or absent.
                // Anti-aliasing (a stroked ring, an arc) leaves a wide band of half-tones.
                if (lum > 0.16 && lum < 0.5) partial += 1;
                if (max >= 40) {
                    const key = `${r >> 4},${g >> 4},${b >> 4}`;
                    histogram.set(key, (histogram.get(key) ?? 0) + 1);
                }
                rowSum += lum;
                rowCount += 1;
                if (rowCount === rowWidth) {
                    rowMeans.push(rowSum / rowCount);
                    rowSum = 0;
                    rowCount = 0;
                }
            }
            let bucket = '';
            let bucketCount = 0;
            for (const [key, count] of histogram) {
                if (count > bucketCount) {
                    bucket = key;
                    bucketCount = count;
                }
            }
            const [qr, qg, qb] = bucket ? bucket.split(',').map(Number) : [0, 0, 0];
            const dominant = {
                r: qr * 16 + 8,
                g: qg * 16 + 8,
                b: qb * 16 + 8,
                count: bucketCount,
                green: qg * 16 + 8 > qr * 16 + 8 + 24 && qg * 16 + 8 > qb * 16 + 8 + 24,
                cyan: qb * 16 + 8 > qr * 16 + 8 + 20 && qg * 16 + 8 > qr * 16 + 8 + 12,
            };
            const total = data.length / 4;
            const lit = total - black;
            const levels = new Set(rowMeans.map((mean) => Math.round(mean * 20)));
            return {
                total,
                lit,
                black,
                bright,
                green,
                cyan,
                partial,
                dominant,
                blackShare: total ? black / total : 0,
                partialShare: lit ? partial / lit : 0,
                rowLevels: levels.size,
            };
        };

        const read = (x: number, y: number, width: number, height: number) => {
            const target = canvas();
            const box = {
                x: Math.max(0, Math.round(x)),
                y: Math.max(0, Math.round(y)),
                w: Math.max(1, Math.round(width)),
                h: Math.max(1, Math.round(height)),
            };
            if (box.x >= target.width || box.y >= target.height) {
                return { pixels: new Uint8ClampedArray(4), rowWidth: 1 };
            }
            box.w = Math.min(box.w, target.width - box.x);
            box.h = Math.min(box.h, target.height - box.y);
            const ctx = target.getContext('2d') as CanvasRenderingContext2D;
            return {
                pixels: ctx.getImageData(box.x, box.y, box.w, box.h).data,
                rowWidth: box.w,
            };
        };

        w.__px = {
            /** Render the live state, then read the frame that was just drawn. */
            frame() {
                w.StarSwarm.render();
            },
            /** Colour and edge statistics for a region of the frame just rendered. */
            stats(x: number, y: number, width: number, height: number) {
                w.StarSwarm.render();
                const region = read(x, y, width, height);
                return classify(region.pixels, region.rowWidth);
            },
            /** How much ink is in a sprite-sized box: "is anything actually drawn here?" */
            ink(x: number, y: number, width: number, height: number) {
                w.StarSwarm.render();
                const region = read(x, y, width, height);
                let ink = 0;
                for (let i = 0; i < region.pixels.length; i += 4) {
                    if (Math.max(region.pixels[i], region.pixels[i + 1], region.pixels[i + 2]) >= 40) {
                        ink += 1;
                    }
                }
                return ink;
            },
        };
    });
}

/** Boot the live game and start it, so the loop and the input path are both real. */
async function boot(page: Page) {
    await installProbe(page);
    const response = await page.goto(GAME_URL, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), 'the game URL must answer 200').toBe(200);

    await expect
        .poll(async () => page.evaluate(() => typeof (window as any).StarSwarm === 'object'))
        .toBe(true);

    await page.locator('[data-star-swarm="action"]').click();
    await expect
        .poll(async () => page.evaluate(() => (window as any).StarSwarm.state.running))
        .toBe(true);
}

/** Boot to the pre-play title screen: the opening sequence plays BEFORE the player starts. */
async function bootToTitle(page: Page) {
    await installProbe(page);
    const response = await page.goto(GAME_URL, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), 'the game URL must answer 200').toBe(200);
    await expect
        .poll(async () => page.evaluate(() => typeof (window as any).StarSwarm === 'object'))
        .toBe(true);
    await expect
        .poll(async () => page.evaluate(() => (window as any).StarSwarm.state.running))
        .toBe(false);
}

/**
 * Puts a diving Boss Galaga on the field with its tractor beam open, and returns its index.
 * Shared by the magnet-ship probes: the beam is only reachable from a diving boss, so the
 * probes drive the same path the game does rather than a test-only shortcut.
 */
const OPEN_BEAM = `
    const openBeam = () => {
        const game = window.StarSwarm;
        game.test.spawnWave(2);
        // Let the colony finish entering first (entrance is ~1.15s plus per-enemy delay).
        // Until it does, nothing is 'settled', launchDiveGroup returns an empty array by
        // construction, and a beam probe would fail for an instrument reason, not a product
        // one. step() takes a frame COUNT, not a delta.\n        game.test.step(150);
        for (let size = 1; size <= 3; size += 1) {
            game.test.startDive(size);
            const index = game.state.enemies.findIndex(
                (enemy) => enemy.caste === 'boss' && enemy.alive && enemy.diving,
            );
            if (index >= 0 && game.test.startTractorBeam(index)) return index;
        }
        return -1;
    };
`;

test.describe('star swarm pixels', () => {
    test('the play field is 16:9 @p5', async ({ page }) => {
        await boot(page);

        const size = await page.evaluate(() => {
            const canvas = (window as any).StarSwarm.lane.canvas as HTMLCanvasElement;
            return { width: canvas.width, height: canvas.height };
        });

        expect(size.width / size.height, 'the play field is 16:9').toBeCloseTo(16 / 9, 2);
        expect(size.width, 'the wide field carries at least 1280 backing pixels').toBeGreaterThanOrEqual(1280);
    });

    test('sprites are pixel matrices, not vector outlines @p4', async ({ page }) => {
        await boot(page);

        const sprites = await page.evaluate(() => {
            const source = (window as any).StarSwarm.sprites;
            if (!source || typeof source !== 'object') return null;
            const out: Record<string, { rows: number; width: number; uniform: boolean; pixelsOnly: boolean }> = {};
            for (const key of Object.keys(source)) {
                const rows = source[key] as unknown;
                if (!Array.isArray(rows) || rows.some((row) => typeof row !== 'string')) continue;
                out[key] = {
                    rows: rows.length,
                    width: rows.length ? String(rows[0]).length : 0,
                    uniform: rows.every((row) => String(row).length === String(rows[0]).length),
                    pixelsOnly: rows.every((row) => /^[.#]+$/.test(String(row))),
                };
            }
            return out;
        });

        expect(sprites, 'the game exposes sprite pixel matrices').not.toBeNull();
        for (const caste of ['bee', 'butterfly', 'boss', 'fighter']) {
            expect(sprites![caste], `a pixel matrix is defined for ${caste}`).toBeTruthy();
            expect(sprites![caste].rows, `${caste} is at least 8 rows tall`).toBeGreaterThanOrEqual(8);
            expect(sprites![caste].width, `${caste} is at least 8 columns wide`).toBeGreaterThanOrEqual(8);
            expect(sprites![caste].uniform, `${caste} rows are all the same length`).toBe(true);
            expect(sprites![caste].pixelsOnly, `${caste} is written as . and # pixels`).toBe(true);
        }
    });

    test('the boss is the green magnet ship @p4', async ({ page }) => {
        await boot(page);

        const boss = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(2);
            // Settle the formation: while the colony is entering, the whole swarm is stacked
            // on the nursery and any box drawn around one sprite is full of its neighbours.
            game.test.step(150);
            const index = game.state.enemies.findIndex(
                (enemy: any) => enemy.caste === 'boss' && enemy.alive && !enemy.diving && !enemy.entering,
            );
            if (index < 0) return null;
            const enemy = game.test.snapshotEnemy(index);
            const stats = (window as any).__px.stats(enemy.x, enemy.y, enemy.width, enemy.height);
            return { stats, ink: stats.dominant };
        });

        expect(boss, 'a settled Boss Galaga exists in the formation').not.toBeNull();
        expect(boss!.ink.count, 'the boss box is dominated by its own ink').toBeGreaterThan(12);
        expect(boss!.ink.green, 'the boss renders green').toBe(true);
    });

    test('the space field is black with pixel stars @p4', async ({ page }) => {
        await boot(page);

        const field = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            // Only the starfield: any sprite ink would mask what is being measured.
            game.state.enemies.length = 0;
            game.state.bullets.length = 0;
            game.state.enemyBullets.length = 0;
            game.state.explosions.length = 0;
            game.state.scorePopups.length = 0;
            const canvas = game.lane.canvas as HTMLCanvasElement;
            return (window as any).__px.stats(0, 0, canvas.width, canvas.height);
        });

        expect(field.blackShare, 'the field is black space').toBeGreaterThan(0.9);
        expect(field.partialShare, 'stars are pixel blocks, not anti-aliased discs').toBeLessThan(0.25);
    });

    test('explosions are pixel starbursts @p4', async ({ page }) => {
        await boot(page);

        const burst = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(150);
            game.state.explosions.length = 0;
            const candidates = game.state.enemies
                .map((enemy: any, index: number) => ({ enemy, index }))
                .filter((entry: any) => entry.enemy.alive && !entry.enemy.diving && !entry.enemy.entering);
            if (!candidates.length) return null;
            // Farthest from the nursery planet on purpose: its smooth radial gradient fills a
            // sample box with half-tones and would fail a pixel-art check for the wrong reason.
            const nurseryDistance = (entry: any) => Math.hypot(
                entry.enemy.x - game.state.nursery.x,
                entry.enemy.y - game.state.nursery.y,
            );
            candidates.sort((a: any, b: any) => nurseryDistance(b) - nurseryDistance(a));
            const index = candidates[0].index;
            game.test.kill(index);
            // Leave only the burst on the field, then age it along the real update path.
            game.state.enemies.length = 0;
            game.test.step(1);
            const explosion = game.state.explosions[0];
            if (!explosion) return null;
            const reach = 30;
            return {
                whole: (window as any).__px.stats(
                    explosion.x - reach,
                    explosion.y - reach,
                    reach * 2,
                    reach * 2,
                ),
                // A stroked ring is HOLLOW in the middle. A Galaga burst has a solid bright
                // core, so the centre of the box is what tells the two apart: without this the
                // check passed against the stroked ring it exists to catch (measured 2026-09-19).
                core: (window as any).__px.stats(explosion.x - 4, explosion.y - 4, 8, 8),
            };
        });

        expect(burst, 'destroying an enemy produces an explosion').not.toBeNull();
        expect(burst!.whole.bright, 'the burst has a bright core').toBeGreaterThan(3);
        expect(burst!.core.bright, 'the burst has a solid core').toBeGreaterThan(4);
        // A stroked ring is one thin anti-aliased curve, so most of its lit pixels are
        // half-tones. Hard-edged pixel blocks leave almost none. No blur or soft glow:
        // Galaga's explosions are opaque pixels.
        expect(burst!.whole.partialShare, 'the explosion is a pixel starburst, not a stroked ring').toBeLessThan(0.2);
    });

    test('the tractor beam is a cyan cone with scan lines @p4', async ({ page }) => {
        await boot(page);

        const beam = await page.evaluate(`(() => {
            const game = window.StarSwarm;
            ${OPEN_BEAM}
            const index = openBeam();
            if (index < 0) return null;
            const boss = game.test.snapshotEnemy(index);
            const canvas = game.lane.canvas;
            const centre = boss.x + boss.width / 2;
            const top = boss.y + boss.height;
            // Stop above the fighter. The player ship is cyan BY ROLE (#4cc9f0), so a box
            // that reaches the bottom would let a warm beam pass a cyan check on the
            // player's own paint -- measured contamination, not a hypothetical.
            const bottom = Math.min(canvas.height - 4, game.state.player.y - 8);
            return window.__px.stats(centre - 72, top, 144, bottom - top);
        })()`);

        expect(beam, 'a diving boss can open a tractor beam').not.toBeNull();
        expect(beam!.cyan, 'the tractor beam is a cyan cone').toBeGreaterThan(40);
        expect(beam!.rowLevels, 'the cone carries horizontal scan lines').toBeGreaterThan(2);
        expect(beam!.dominant.cyan, 'the cone is painted cyan, not merely tinted by it').toBe(true);
    });

    test('the captured fighter is drawn under the magnet ship @p5', async ({ page }) => {
        await boot(page);

        const capture = await page.evaluate(`(() => {
            const game = window.StarSwarm;
            ${OPEN_BEAM}
            const index = openBeam();
            if (index < 0) return null;
            const boss = game.test.snapshotEnemy(index);
            // Fly the fighter into the beam and let the real update path resolve it.
            game.state.player.invulnerable = 0;
            game.state.player.x = boss.x + boss.width / 2 - game.state.player.width / 2;
            for (let step = 0; step < 240 && !game.state.capturedFighter; step += 1) {
                game.test.step(1);
            }
            const fighter = game.state.capturedFighter;
            if (!fighter) return null;
            return {
                status: fighter.status,
                captor: fighter.captorIndex,
                boss: index,
                ink: window.__px.ink(fighter.x, fighter.y, fighter.width, fighter.height),
            };
        })()`);

        expect(capture, 'the magnet ship captures the fighter').not.toBeNull();
        expect(capture!.captor, 'the captured fighter belongs to the capturing ship').toBe(capture!.boss);
        expect(capture!.ink, 'the captured fighter is drawn under the magnet ship').toBeGreaterThan(20);
    });

    test('an extra ship is announced on screen @p5', async ({ page }) => {
        await boot(page);

        const award = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            // The announcement must be the new ink, so the field starts empty.
            game.state.enemies.length = 0;
            const canvas = game.lane.canvas as HTMLCanvasElement;
            const band = { x: 0, y: canvas.height * 0.38, w: canvas.width, h: canvas.height * 0.24 };
            const before = (window as any).__px.stats(band.x, band.y, band.w, band.h);
            const livesBefore = game.state.lives;
            game.addScore(game.constants.FIRST_EXTRA_SHIP_SCORE);
            const after = (window as any).__px.stats(band.x, band.y, band.w, band.h);
            return { livesBefore, livesAfter: game.state.lives, before: before.bright, after: after.bright };
        });

        expect(award.livesAfter, 'earning the threshold grants a life').toBe(award.livesBefore + 1);
        expect(award.after, 'extra ship is announced on screen').toBeGreaterThan(award.before + 30);
    });

    test('the opening sequence runs before play @p6', async ({ page }) => {
        await bootToTitle(page);

        const opening = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            const opening = game.state.opening;
            if (!opening) return null;
            const stages: string[] = [String(opening.stage)];
            // Advance on the game's own clock. No wall-clock wait: a sleep would make this
            // probe a race, and the repository forbids waitForTimeout for the same reason.
            for (let frame = 0; frame < 600; frame += 1) {
                game.test.step(1);
                const stage = String(game.state.opening.stage);
                if (stage !== stages[stages.length - 1]) stages.push(stage);
                if (stage === 'done' || stage === 'play') break;
            }
            return {
                stages,
                title: game.state.opening.title,
                byline: game.state.opening.byline,
            };
        });

        expect(opening, 'the game exposes its opening sequence as state').not.toBeNull();
        expect(opening!.title, 'the opening names the game').toBe('Star Swarm');
        expect(opening!.byline, 'the opening credits the studio').toBe('by IKON');
        expect(opening!.stages[0], 'the opening starts on the title').toBe('title');
        expect(opening!.stages.indexOf('byline'), 'the sequence reaches the byline after the title')
            .toBeGreaterThan(opening!.stages.indexOf('title'));
    });

    test('the title is drawn, then the byline @p6', async ({ page }) => {
        await bootToTitle(page);

        const frames = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            if (!game.state.opening) return null;
            const canvas = game.lane.canvas;
            const band = { x: 0, y: canvas.height * 0.32, w: canvas.width, h: canvas.height * 0.34 };
            game.state.opening.stage = 'title';
            const title = (window as any).__px.stats(band.x, band.y, band.w, band.h);
            game.state.opening.stage = 'byline';
            const byline = (window as any).__px.stats(band.x, band.y, band.w, band.h);
            return {
                titleBright: title.bright,
                bylineBright: byline.bright,
                titlePartial: title.partialShare,
                bylinePartial: byline.partialShare,
            };
        });

        expect(frames, 'the opening can be held on a named stage').not.toBeNull();
        expect(frames!.titleBright, 'the title is drawn as logo ink').toBeGreaterThan(200);
        expect(frames!.bylineBright, 'then the byline is drawn').toBeGreaterThan(60);
        // The logo is a large lockup and the byline is a line of small type, so the title
        // carries markedly more ink: that is how the two stages are told apart, not by a timer.
        expect(frames!.titleBright, 'the title lockup is the larger mark').toBeGreaterThan(frames!.bylineBright * 1.5);
        expect(frames!.titlePartial, 'the title is pixel blocks, not anti-aliased type').toBeLessThan(0.3);
        expect(frames!.bylinePartial, 'the byline is pixel blocks too').toBeLessThan(0.3);
    });
});
