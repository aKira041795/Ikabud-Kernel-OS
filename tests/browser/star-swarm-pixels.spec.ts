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
            /**
             * Distinct grey levels in a region, by share of the region. "Shaded body" and "flat disc"
             * are the same colour and different pictures, so this is the measurement that tells a
             * cratered moon from a circle: a flat disc has one level above the noise floor, a
             * cratered one has several.
             */
            shades(x: number, y: number, width: number, height: number) {
                w.StarSwarm.render();
                const region = read(x, y, width, height);
                const histogram = new Map<number, number>();
                let total = 0;
                for (let i = 0; i < region.pixels.length; i += 4) {
                    const luma = Math.round(
                        0.2126 * region.pixels[i] + 0.7152 * region.pixels[i + 1] + 0.0722 * region.pixels[i + 2],
                    );
                    const bucket = Math.min(15, Math.max(0, Math.floor(luma / 16)));
                    histogram.set(bucket, (histogram.get(bucket) ?? 0) + 1);
                    total += 1;
                }
                const shares = [...histogram.entries()]
                    .map(([bucket, count]) => ({ level: bucket * 16 + 8, share: total ? count / total : 0 }))
                    .sort((a, b) => b.share - a.share);
                // 3% is the floor that stops a stray anti-aliased edge counting as a distinct shade.
                return { total, shares, levelsAbove3pct: shares.filter((s) => s.share >= 0.03).length };
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

/**
 * Settles the formation and returns the dominant ink of one caste: rows of the colony are
 * colour-coded, so this is how the palette is asserted rather than by reading a token.
 */
const SAMPLE_CASTE = `
    const sampleCaste = (caste) => {
        const game = window.StarSwarm;
        game.test.spawnWave(2);
        game.test.step(180);
        const index = game.state.enemies.findIndex(
            (enemy) => enemy.caste === caste && enemy.alive && !enemy.diving && !enemy.entering,
        );
        if (index < 0) return null;
        const enemy = game.test.snapshotEnemy(index);
        const stats = window.__px.stats(enemy.x, enemy.y, enemy.width, enemy.height);
        const d = stats.dominant;
        return {
            ink: d.count,
            dominant: d,
            lum: Math.round(0.2126 * d.r + 0.7152 * d.g + 0.0722 * d.b),
        };
    };
`;

test.describe('star swarm pixels', () => {
    test('the play field is 16:9 @p5', async ({ page }) => {
        await boot(page);

        const size = await page.evaluate(() => {
            const canvas = (window as any).StarSwarm.lane.canvas as HTMLCanvasElement;
            const rect = canvas.getBoundingClientRect();
            return {
                backingW: canvas.width,
                backingH: canvas.height,
                shownW: rect.width,
                shownH: rect.height,
            };
        });

        expect(size.backingW / size.backingH, 'the play field is 16:9').toBeCloseTo(16 / 9, 2);
        expect(size.backingW, 'the wide field carries at least 1280 backing pixels').toBeGreaterThanOrEqual(1280);
        // The backing store is NOT what the director sees. A 16:9 canvas displayed inside a 4:3
        // box passes the two checks above and still looks wrong on screen, which is exactly the
        // report that prompted this assertion (2026-09-19), so the displayed geometry is asserted
        // too. The stale-stylesheet cause of that report is covered by the versioned-asset probe.
        expect(size.shownW, 'the field is displayed with a non-zero width').toBeGreaterThan(0);
        expect(size.shownW / size.shownH, 'the field is displayed at 16:9, not stretched').toBeCloseTo(16 / 9, 2);
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

    test('the bee is painted the arcade yellow @p7', async ({ page }) => {
        await boot(page);

        const bee = await page.evaluate(`(() => {
            ${SAMPLE_CASTE}
            return sampleCaste('bee');
        })()`);

        expect(bee, 'a settled bee exists in the formation').not.toBeNull();
        expect(bee!.ink, 'the bee box is dominated by its own ink').toBeGreaterThan(12);
        expect(bee!.dominant.r, 'the bee is yellow: a strong red channel').toBeGreaterThanOrEqual(200);
        expect(bee!.dominant.g, 'the bee is yellow: a strong green channel').toBeGreaterThanOrEqual(140);
        expect(bee!.dominant.b, 'the bee is yellow: a weak blue channel').toBeLessThanOrEqual(140);
    });

    test('the butterfly is painted red, not mud @p7', async ({ page }) => {
        await boot(page);

        const butterfly = await page.evaluate(`(() => {
            ${SAMPLE_CASTE}
            return sampleCaste('butterfly');
        })()`);

        expect(butterfly, 'a settled butterfly exists in the formation').not.toBeNull();
        expect(butterfly!.ink, 'the butterfly box is dominated by its own ink').toBeGreaterThan(12);
        // Measured 2026-09-19: the butterfly rendered (88,40,24) -- luminance 49, a dark brown
        // inherited from --color-tertiary. The arcade butterfly is vivid red (with white), so the
        // assertion is on the PAINT, not on a token name.
        const d = butterfly!.dominant;
        const vividRed = d.r >= 180 && d.r >= d.g + 80 && d.r >= d.b + 80;
        const white = d.r >= 200 && d.g >= 200 && d.b >= 200;
        expect(vividRed || white, 'the butterfly is painted red or white, not a dark brown').toBe(true);
        expect(butterfly!.lum, 'the butterfly is a vivid colour, not a muddy one').toBeGreaterThan(90);
    });

    test('the castes are painted in distinct, vivid colours @p7', async ({ page }) => {
        await boot(page);

        const castes = await page.evaluate(`(() => {
            ${SAMPLE_CASTE}
            const out = {};
            for (const caste of ['bee', 'butterfly', 'boss']) out[caste] = sampleCaste(caste);
            return out;
        })()`);

        for (const caste of ['bee', 'butterfly', 'boss']) {
            expect(castes[caste], `a settled ${caste} exists in the formation`).not.toBeNull();
        }
        const lums = ['bee', 'butterfly', 'boss'].map((caste) => castes[caste].lum);
        // A muddy formation is what the director saw: every caste must be a vivid colour, or the
        // rows read as brown mush at arcade speed whatever the tokens say.
        expect(Math.min(...lums), 'no caste is painted muddy (luminance floor)').toBeGreaterThan(90);
        const keys = ['bee', 'butterfly', 'boss'].map(
            (caste) => `${castes[caste].dominant.r},${castes[caste].dominant.g},${castes[caste].dominant.b}`,
        );
        expect(new Set(keys).size, 'the three castes are told apart by colour').toBe(3);
    });

    test('the page versions its assets so a visual change cannot be cached away @p7', async ({ page }) => {
        await boot(page);

        const assets = await page.evaluate(() => ({
            css: Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((node) => (node as HTMLLinkElement).href),
            js: Array.from(document.querySelectorAll('script[src]')).map((node) => (node as HTMLScriptElement).src),
        }));

        expect(assets.css.length, 'the page links a stylesheet').toBeGreaterThan(0);
        // An unversioned asset URL is why the director saw a 4:3 field after it became 16:9: the
        // browser kept the old stylesheet, which carried no aspect-ratio. A version tied to the
        // asset's own timestamp makes a stale copy impossible to keep.
        const versioned = (url: string) => /[?&]v=\d{9,}/.test(url);
        expect(assets.css.some(versioned), 'the stylesheet URL carries a timestamp version').toBe(true);
        expect(assets.js.some(versioned), 'the script URL carries a timestamp version too').toBe(true);
    });

    test('the fighter crosses the field at arcade speed @p8', async ({ page }) => {
        await boot(page);

        const move = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.player.x = 4; // left edge, so a whole second of travel is available
            const from = game.state.player.x;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }));
            game.test.step(60); // one second of game time, through the real input path
            const moved = game.state.player.x - from;
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowRight' }));
            return { moved, fieldWidth: game.lane.canvas.width };
        });

        // Measured 2026-09-19: 420 px/s against a 1280 px field = 3.05 s to cross, where the same
        // speed took 1.9 s on the pre-widening 800 px field. The director felt exactly that, and it is
        // why the requirement is expressed as a FRACTION OF THE FIELD rather than in pixels per second:
        // a future resize must not be able to reintroduce the lag.
        expect(move.moved / move.fieldWidth, 'the fighter crosses half the field in a second').toBeGreaterThanOrEqual(0.5);
        // A floor alone invites the opposite defect. The arcade fighter is quick, not twitchy, and there is
        // an existing verified assertion that two-ship collisions and dodge windows behave at this scale.
        expect(move.moved / move.fieldWidth, 'the fighter is quick but not twitchy').toBeLessThanOrEqual(1.2);
    });

    test('a shot reaches the top of the field quickly @p8', async ({ page }) => {
        await boot(page);

        const travel = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            // Nothing to intercept the shot: this measures travel, not a collision.
            game.state.enemies.length = 0;
            game.state.player.x = game.lane.canvas.width / 2 - game.state.player.width / 2;
            game.state.bullets.length = 0;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
            game.test.step(1);
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp' }));
            const fired = game.state.bullets.length;
            let frames = 0;
            while (game.state.bullets.length > 0 && frames < 300) {
                game.test.step(1);
                frames += 1;
            }
            return { fired, frames, seconds: frames / 60 };
        });

        expect(travel.fired, 'pressing fire produces a shot').toBeGreaterThan(0);
        // 1.16 s measured against the widened field; 0.97 s before it. A shot that dawdles across the
        // screen is what makes firing feel unresponsive, and the 2-shot limit then caps the fire rate.
        expect(travel.seconds, 'a shot clears the field in under 0.8 s').toBeLessThanOrEqual(0.8);
        // And not instant: a shot that hits the top in a couple of frames removes the lead a diving enemy
        // is supposed to have, which is a gameplay change dressed up as responsiveness.
        expect(travel.seconds, 'a shot is fast but not instant').toBeGreaterThanOrEqual(0.25);
    });

    test('holding fire sustains an arcade rate @p8', async ({ page }) => {
        await boot(page);

        const rate = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            game.state.player.x = game.lane.canvas.width / 2 - game.state.player.width / 2;
            game.state.bullets.length = 0;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
            let spawned = 0;
            for (let frame = 0; frame < 60; frame += 1) {
                const before = game.state.bullets.length;
                game.test.step(1);
                const after = game.state.bullets.length;
                if (after > before) spawned += after - before;
            }
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp' }));
            return { spawned, live: game.state.bullets.length };
        });

        // 2/s measured. The arcade allows two shots in flight at once, so the honest fix is faster shots
        // rather than a bigger allowance: at 0.8 s of travel the same limit sustains 2.5/s.
        expect(rate.spawned, 'one second of held fire launches at least 2.5 shots').toBeGreaterThanOrEqual(2.5);
    });

    test('fire responds on the next frame @p8', async ({ page }) => {
        await boot(page);

        const latency = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            game.state.player.x = game.lane.canvas.width / 2 - game.state.player.width / 2;
            game.state.bullets.length = 0;
            const before = game.state.bullets.length;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
            game.test.step(1);
            const after = game.state.bullets.length;
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp' }));
            return { firedOnFirstFrame: after - before };
        });

        // A guard, not a repair: latency was already one frame. It stays asserted so a future input
        // refactor cannot quietly introduce a key-repeat or key-up dependency.
        expect(latency.firedOnFirstFrame, 'the first shot is fired on the frame after the keypress').toBeGreaterThan(0);
    });

    test('a charge is earned and announced @p10', async ({ page }) => {
        await boot(page);

        const earned = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.state.enemies.length = 0;
            game.test.step(240); // let earlier banners expire before the baseline is taken
            const canvas = game.lane.canvas;
            const band = { x: 0, y: canvas.height * 0.38, w: canvas.width, h: canvas.height * 0.24 };
            const before = (window as any).__px.stats(band.x, band.y, band.w, band.h).bright;
            const chargesBefore = game.state.powerup?.charges ?? null;
            game.addScore(10000);
            game.test.step(6);
            const after = (window as any).__px.stats(band.x, band.y, band.w, band.h).bright;
            return {
                chargesBefore,
                chargesAfter: game.state.powerup?.charges ?? null,
                nextAt: game.state.powerup?.nextAt ?? null,
                before,
                after,
            };
        });

        expect(earned.chargesBefore, 'the game exposes how many charges are held').not.toBeNull();
        expect(earned.chargesAfter, 'a charge is earned at the threshold').toBe(Math.min(3, earned.chargesBefore! + 1));
        expect(earned.nextAt, 'the next threshold is known').toBeGreaterThan(10000);
        // The extra ship was once granted with 12 -> 12 rendered pixels. A reward nobody can see is one
        // nobody will use, so the announcement is asserted, not just the counter.
        expect(earned.after, 'earning a charge is announced on screen').toBeGreaterThan(earned.before + 30);
    });

    test('S spends one charge, and does nothing without one @p10', async ({ page }) => {
        await boot(page);

        const press = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            if (!game.state.powerup) return null;
            game.test.spawnWave(1);
            game.test.step(120);

            // No charge: S must do nothing at all.
            game.state.powerup.charges = 0;
            game.state.powerup.active = false;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }));
            game.test.step(2);
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 's' }));
            const empty = { charges: game.state.powerup.charges, active: game.state.powerup.active };

            // Two charges: one press spends exactly one.
            game.state.powerup.charges = 2;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }));
            game.test.step(2);
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 's' }));
            return {
                empty,
                spent: game.state.powerup.charges,
                active: game.state.powerup.active,
                remaining: game.state.powerup.remaining,
            };
        });

        expect(press, 'the game exposes a powerup surface').not.toBeNull();
        expect(press!.empty.charges, 'S does nothing without a charge').toBe(0);
        expect(press!.empty.active, 'S without a charge does not open a window').toBe(false);
        expect(press!.spent, 'S spends one charge').toBe(1);
        expect(press!.active, 'S opens the lance window').toBe(true);
        expect(press!.remaining, 'the window is bounded, not permanent').toBeGreaterThan(0);
    });

    test('the lance destroys an enemy, then expires @p10', async ({ page }) => {
        await boot(page);

        const lance = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            if (!game.state.powerup) return null;
            game.test.spawnWave(2);
            game.test.step(180);
            game.state.powerup.charges = 1;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 's' }));
            game.test.step(1);
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 's' }));

            const target = game.state.enemies.find((e: any) => e.alive && !e.diving && !e.entering);
            const before = game.state.enemies.filter((e: any) => e.alive).length;
            // Line the fighter up under a live enemy and fire into the lance.
            game.state.player.x = target.x + target.width / 2 - game.state.player.width / 2;
            target.x = game.state.player.x;
            target.y = game.state.player.y - 140;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
            for (let frame = 0; frame < 40; frame += 1) game.test.step(1);
            const killed = game.state.enemies.filter((e: any) => e.alive).length < before;
            // The arcade two-shot rule is verified fidelity and must survive the power-up.
            const bullets = game.state.bullets.length;
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp' }));

            let frames = 0;
            while (game.state.powerup.active && frames < 60 * 30) {
                game.test.step(1);
                frames += 1;
            }
            return {
                killed,
                bullets,
                expiredActive: game.state.powerup.active,
                expiredRemaining: game.state.powerup.remaining,
                charges: game.state.powerup.charges,
            };
        });

        expect(lance, 'the game exposes a powerup surface').not.toBeNull();
        expect(lance!.killed, 'the lance destroys an enemy it touches').toBe(true);
        expect(lance!.bullets, 'the lance is not a bullet: two shots in flight still holds').toBeLessThanOrEqual(2);
        expect(lance!.expiredActive, 'the lance expires on its own').toBe(false);
        expect(lance!.expiredRemaining, 'an expired window has no time left').toBe(0);
        expect(lance!.charges, 'an expired window does not refund the charge').toBe(0);
    });

    test('the HUD shows the lance charges @p10', async ({ page }) => {
        await boot(page);

        const hud = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            if (!game.state.powerup) return null;
            game.state.powerup.charges = 2;
            game.test.step(2);
            const node = document.querySelector('[data-star-swarm="lance"]') as HTMLElement | null;
            return {
                present: Boolean(node),
                text: node ? (node.textContent || '') : '',
                attribute: node ? String(node.getAttribute('data-charges') ?? '') : '',
                charges: game.state.powerup.charges,
            };
        });

        expect(hud, 'the game exposes a powerup surface').not.toBeNull();
        expect(hud!.present, 'the HUD exposes a lance element').toBe(true);
        expect(
            `${hud!.text} ${hud!.attribute}`,
            'the HUD shows the lance charges',
        ).toContain(String(hud!.charges));
    });

    test('the bonus round has a countdown @p11', async ({ page }) => {
        await boot(page);

        const clock = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(3); // stage 3 is the bonus round
            const atStart = {
                active: game.state.bonus?.active === true,
                window: game.state.bonus?.window ?? 0,
                remaining: game.state.bonus?.remaining ?? -1,
                challenging: game.state.challenging === true,
            };
            const canvas = game.lane.canvas;
            const band = { x: 0, y: 0, w: canvas.width, h: canvas.height * 0.16 };
            game.test.step(2);
            const drawn = (window as any).__px.stats(band.x, band.y, band.w, band.h).bright;
            let minSeen = atStart.remaining;
            for (let frame = 0; frame < 180; frame += 1) {
                game.test.step(1);
                minSeen = Math.min(minSeen, game.state.bonus?.remaining ?? 0);
            }
            return {
                atStart,
                drawn,
                minSeen,
                laterRemaining: game.state.bonus?.remaining ?? -1,
            };
        });

        expect(clock.atStart.challenging, 'stage 3 is the bonus round').toBe(true);
        expect(clock.atStart.active, 'the bonus round starts a clock').toBe(true);
        expect(clock.atStart.window, 'the window is at least 15 seconds').toBeGreaterThanOrEqual(15);
        expect(clock.laterRemaining, 'the countdown runs down').toBeLessThan(clock.atStart.remaining);
        expect(clock.minSeen, 'the countdown never goes negative').toBeGreaterThanOrEqual(0);
        // A time limit nobody can see is not a time limit, it is an unexplained end.
        expect(clock.drawn, 'the countdown is rendered on the play field').toBeGreaterThan(50);
    });

    test('every bonus enemy is killable and clears for PERFECT @p11', async ({ page }) => {
        await boot(page);

        const cleared = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(3);
            game.test.step(60);
            const before = game.state.enemies.filter((e: any) => e.alive).length;
            const perfectBefore = game.state.perfectBonus;
            let guard = 0;
            while (game.state.enemies.some((e: any) => e.alive) && guard < 400) {
                game.test.kill(game.state.enemies.findIndex((e: any) => e.alive));
                game.test.step(1);
                guard += 1;
            }
            return {
                before,
                left: game.state.enemies.filter((e: any) => e.alive).length,
                perfectBefore,
                perfectAfter: game.state.perfectBonus,
                cleared: game.state.bonus?.cleared ?? null,
                remainingAtEnd: game.state.bonus?.remaining ?? -1,
            };
        });

        expect(cleared.before, 'the bonus round fields the forty').toBeGreaterThanOrEqual(40);
        expect(cleared.left, 'every bonus enemy is reachable when the clock starts').toBe(0);
        expect(cleared.remainingAtEnd, 'they can be cleared inside the clock').toBeGreaterThan(0);
        expect(cleared.perfectAfter, 'clearing them all awards the perfect bonus').toBeGreaterThan(cleared.perfectBefore);
    });

    test('the round ends cleanly when the clock runs out @p11', async ({ page }) => {
        await boot(page);

        const expiry = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            if (!game.state.bonus) return null;
            game.test.spawnWave(3);
            game.test.step(60);
            const before = {
                enemies: game.state.enemies.filter((e: any) => e.alive).length,
                perfect: game.state.perfectBonus,
                stage: game.state.stage,
            };
            let frames = 0;
            while (game.state.bonus.active && frames < 60 * 60) {
                game.test.step(1);
                frames += 1;
            }
            const after = {
                active: game.state.bonus.active,
                remaining: game.state.bonus.remaining,
                perfect: game.state.perfectBonus,
                running: game.state.running,
                gameOver: game.state.gameOver,
            };
            // The game must move on rather than sit on an expired round.
            for (let later = 0; later < 360; later += 1) game.test.step(1);
            return { before, after, stageLater: game.state.stage, seconds: Math.round(frames / 60) };
        });

        expect(expiry, 'the game exposes a bonus surface').not.toBeNull();
        expect(expiry!.after.active, 'the round closes when the clock runs out').toBe(false);
        expect(expiry!.after.remaining, 'an expired round has no time left').toBe(0);
        expect(expiry!.after.perfect, 'the clock running out awards no perfect bonus').toBe(expiry!.before.perfect);
        expect(expiry!.after.gameOver, 'an expired round is not a game over').toBe(false);
        expect(expiry!.after.running, 'play continues after the round').toBe(true);
        expect(expiry!.stageLater, 'the game moves on instead of stalling on an expired round').toBeGreaterThan(expiry!.before.stage);
    });

    test('the HUD shows the bonus clock and the enemies left @p11', async ({ page }) => {
        await boot(page);

        const hud = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(3);
            game.test.step(30);
            for (let killed = 0; killed < 3; killed += 1) {
                game.test.kill(game.state.enemies.findIndex((e: any) => e.alive));
            }
            game.test.step(4);
            const node = document.querySelector('[data-star-swarm="bonus"]') as HTMLElement | null;
            return {
                present: Boolean(node),
                text: node ? (node.textContent || '') : '',
                left: game.state.bonus?.enemiesLeft ?? null,
                remaining: game.state.bonus?.remaining ?? null,
            };
        });

        expect(hud.present, 'the HUD exposes a bonus element').toBe(true);
        expect(hud.left, 'the game exposes how many enemies are left').not.toBeNull();
        expect(hud.text, 'the HUD shows the bonus clock and the enemies left').toContain(String(hud.left));
    });

    test('nothing fires for the whole bonus round @p11', async ({ page }) => {
        await boot(page);

        const firing = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            const measure = (stage: number, frames: number) => {
                game.test.spawnWave(stage);
                game.test.step(120);
                let fired = 0;
                for (let frame = 0; frame < frames; frame += 1) {
                    const before = game.state.enemyBullets.length;
                    game.test.step(1);
                    const after = game.state.enemyBullets.length;
                    if (after > before) fired += after - before;
                }
                return fired;
            };
            // The control runs in the SAME session: a "no bullets" reading is worthless unless the same
            // probe can see bullets when they exist.
            const controlFired = measure(2, 900);
            const bonusFired = measure(3, 900);
            return { controlFired, bonusFired };
        });

        expect(firing.controlFired, 'the control stage fires, so the probe can see firing').toBeGreaterThan(0);
        expect(firing.bonusFired, 'nothing fires for the whole bonus round').toBe(0);
    });

    test('the round announces itself as BONUS ROUND @p11', async ({ page }) => {
        await boot(page);

        const title = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            const canvas = game.lane.canvas;
            const band = { x: 0, y: canvas.height * 0.38, w: canvas.width, h: canvas.height * 0.24 };
            // A quiet standard stage first, so the baseline is not some earlier banner.
            game.test.spawnWave(2);
            game.test.step(300);
            const before = (window as any).__px.stats(band.x, band.y, band.w, band.h).bright;
            game.test.spawnWave(3);
            game.test.step(6);
            const after = (window as any).__px.stats(band.x, band.y, band.w, band.h).bright;
            return { text: game.state.bonus?.title ?? null, before, after };
        });

        expect(title.text, 'the round carries its name in state').toBe('BONUS ROUND');
        // A string in state is exactly the claim this project was burned by when the extra ship was
        // granted with zero rendered pixels. The ink proves the words are actually on the screen.
        expect(title.after, 'the round announces itself as BONUS ROUND').toBeGreaterThan(title.before + 30);
    });

    // ---------------------------------------------------------------------------------------------
    // @p12 -- the carrier, the drop and the grab.
    //
    // The director asked the question phase 10 did not answer: "when can a player get the beam?" The
    // threshold rule answers "at 10,000 points", which is not a decision the player makes. This is:
    // one enemy carries the lance, kill it, and collect what drops before it is gone.
    //
    // The marker probe is a DIFFERENTIAL -- the same box measured with the flag on that enemy and
    // with the flag moved to another one. The sprite, its caste, its wing frame and any neighbour
    // whose sprite overlaps the box are identical in both readings and cancel out. An absolute
    // reading could not tell a marked carrier from a sprite that happens to be drawn larger.
    // ---------------------------------------------------------------------------------------------
    test('exactly one carrier is in play and it is marked on the field @p12', async ({ page }) => {
        await boot(page);

        const carrier = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);

            // The surface this item is about. It is absent today, and it must fail as a NAMED
            // requirement rather than as a TypeError, or the lane reads a crash instead of the
            // requirement it is being asked to satisfy.
            if (!game.state.carrier) return null;

            const live = game.state.enemies
                .map((enemy: any, index: number) => ({ enemy, index }))
                .filter((entry: any) => entry.enemy.alive);
            if (live.length < 2) return null;

            const named = game.state.carrier?.enemyIndex ?? null;
            const carrierAlive = game.state.carrier?.alive ?? null;

            const box = (enemy: any) => [enemy.x - 6, enemy.y - 6, enemy.width + 12, enemy.height + 12];
            const readCarrierLook = (enemy: any, flagOn: number) => {
                game.state.carrier.enemyIndex = flagOn;
                const [x, y, w, h] = box(enemy);
                return (window as any).__px.ink(x, y, w, h);
            };

            return {
                named,
                carrierAlive,
                aliveCount: live.length,
                aMarked: readCarrierLook(live[0].enemy, live[0].index),
                aPlain: readCarrierLook(live[0].enemy, live[1].index),
                bMarked: readCarrierLook(live[1].enemy, live[1].index),
                bPlain: readCarrierLook(live[1].enemy, live[0].index),
            };
        });

        expect(carrier, 'exactly one carrier is in play and it is marked on the field').not.toBeNull();
        expect(carrier!.named, 'the game names the enemy carrying the lance').toBeGreaterThanOrEqual(0);
        expect(carrier!.carrierAlive, 'the carrier is a living enemy, not a placeholder').toBe(true);
        expect(carrier!.aMarked, 'exactly one carrier is in play and it is marked on the field').toBeGreaterThan(
            carrier!.aPlain,
        );
        // And the mark follows the carrier rather than being a fixed decoration on one sprite.
        expect(carrier!.bMarked, 'the mark moves with the carrier').toBeGreaterThan(carrier!.bPlain);
    });

    test('killing the carrier drops a collectable that drifts @p12', async ({ page }) => {
        await boot(page);

        const drop = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            if (!Array.isArray(game.state.pickups) || !game.state.carrier) return null;
            game.state.pickups.length = 0;

            const index = game.state.carrier.enemyIndex;
            const carrier = game.state.enemies[index];
            const origin = { x: carrier.x, y: carrier.y };
            game.test.kill(index); // the real destruction path, not a forced removal from the array
            game.test.step(1);

            const first = game.state.pickups[0] ?? null;
            const settled = first
                ? { x: first.x, y: first.y, remaining: first.remaining, lifetime: first.lifetime, kind: first.kind }
                : null;

            game.test.step(120);
            const later = game.state.pickups[0] ?? null;
            return {
                origin,
                count: settled ? game.state.pickups.length : 0,
                settled,
                after: later ? { y: later.y, remaining: later.remaining } : null,
            };
        });

        expect(drop, 'killing the carrier drops a collectable that drifts').not.toBeNull();
        expect(drop!.settled, 'the carrier leaves a collectable behind').not.toBeNull();
        expect(drop!.count, 'killing one carrier drops exactly one collectable').toBe(1);
        expect(drop!.settled!.lifetime, 'the drop is collectable for at least five seconds').toBeGreaterThanOrEqual(5);
        expect(drop!.settled!.remaining, 'the lifetime starts positive').toBeGreaterThan(0);
        expect(drop!.settled!.remaining, 'the lifetime never exceeds its own bound').toBeLessThanOrEqual(
            drop!.settled!.lifetime,
        );
        expect(Math.abs(drop!.settled!.x - drop!.origin.x), 'the drop appears at the carrier').toBeLessThan(24);
        expect(drop!.after, 'the drop is still collectable a moment later').not.toBeNull();
        expect(drop!.after!.y, 'the drop drifts downward').toBeGreaterThan(drop!.settled!.y);
        expect(drop!.after!.remaining, 'the lifetime decreases in game time').toBeLessThan(drop!.settled!.remaining);
    });

    test('collecting the drop grants exactly one charge and announces it @p12', async ({ page }) => {
        await boot(page);

        const collected = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            // A quiet field: no sprite ink and no earlier banner to be mistaken for this one.
            game.state.enemies.length = 0;
            game.state.enemyBullets.length = 0;
            game.state.explosions.length = 0;
            game.state.scorePopups.length = 0;
            if (!Array.isArray(game.state.pickups)) return null;
            game.state.pickups.length = 0;
            game.test.step(240);

            game.state.powerup.charges = 0;
            const player = game.state.player;
            const canvas = game.lane.canvas;
            const band = { x: 0, y: canvas.height * 0.38, w: canvas.width, h: canvas.height * 0.24 };
            const before = (window as any).__px.stats(band.x, band.y, band.w, band.h).bright;

            // Placed on the player, and then collected by the game's own collision path.
            game.state.pickups.push({
                x: player.x + player.width / 2,
                y: player.y + player.height / 2,
                remaining: 4,
                lifetime: 5,
                kind: 'lance',
            });
            game.test.step(2);

            return {
                charges: game.state.powerup.charges,
                pickupsLeft: game.state.pickups.length,
                before,
                after: (window as any).__px.stats(band.x, band.y, band.w, band.h).bright,
            };
        });

        expect(collected, 'collecting the drop grants exactly one charge and announces it').not.toBeNull();
        expect(collected!.charges, 'a collected drop grants exactly one charge').toBe(1);
        expect(collected!.pickupsLeft, 'a collected drop leaves the field').toBe(0);
        expect(collected!.after, 'the earned charge is announced on screen').toBeGreaterThan(collected!.before + 30);
    });

    test('an uncollected drop expires with nothing granted @p12', async ({ page }) => {
        await boot(page);

        const expired = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            if (!Array.isArray(game.state.pickups)) return null;
            game.state.pickups.length = 0;
            game.state.powerup.charges = 0;

            const canvas = game.lane.canvas;
            // Far from the player, so only the clock can end this one.
            game.state.pickups.push({
                x: canvas.width * 0.2,
                y: canvas.height * 0.3,
                remaining: 5,
                lifetime: 5,
                kind: 'lance',
            });
            game.test.step(30);
            const mid = game.state.pickups.length;
            const midRemaining = game.state.pickups[0]?.remaining ?? null;
            game.test.step(6 * 60);
            return {
                mid,
                midRemaining,
                left: game.state.pickups.length,
                charges: game.state.powerup.charges,
                remaining: game.state.pickups[0]?.remaining ?? null,
            };
        });

        expect(expired, 'an uncollected drop expires with nothing granted').not.toBeNull();
        expect(expired!.mid, 'the drop is still waiting after half a second').toBe(1);
        expect(expired!.midRemaining, 'and its clock is running down').toBeLessThan(5);
        expect(expired!.left, 'an expired drop leaves the field').toBe(0);
        expect(expired!.charges, 'an expired drop grants no charge').toBe(0);
        expect(expired!.remaining, 'an expired drop leaves nothing behind').toBeNull();
    });

    test('the drop is legible as it expires @p12', async ({ page }) => {
        await boot(page);

        const flash = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            game.state.enemyBullets.length = 0;
            if (!Array.isArray(game.state.pickups)) return null;
            game.state.pickups.length = 0;

            const canvas = game.lane.canvas;
            game.state.pickups.push({
                x: canvas.width * 0.2,
                y: canvas.height * 0.3,
                remaining: 6,
                lifetime: 6,
                kind: 'lance',
            });

            // Early: the mark must be there, and must be steady, or "it changes later" means nothing.
            const early: number[] = [];
            for (let frame = 0; frame < 4; frame += 1) {
                game.test.step(6);
                const drop = game.state.pickups[0];
                if (!drop) break;
                early.push((window as any).__px.ink(drop.x - 12, drop.y - 12, 24, 24));
            }

            // Then walk the last two seconds, sampling the same relative box as the drop drifts.
            game.test.step(Math.max(0, Math.round((game.state.pickups[0]?.remaining ?? 0) * 60) - 120));
            const late: number[] = [];
            for (let frame = 0; frame < 16; frame += 1) {
                game.test.step(6);
                const drop = game.state.pickups[0];
                if (!drop) break;
                late.push((window as any).__px.ink(drop.x - 12, drop.y - 12, 24, 24));
            }

            const spread = (values: number[]) =>
                values.length ? Math.max(...values) - Math.min(...values) : 0;
            const mean = (values: number[]) =>
                values.length ? values.reduce((sum, v) => sum + v, 0) / values.length : 0;

            return { early, late, earlySpread: spread(early), lateSpread: spread(late), earlyMean: mean(early) };
        });

        expect(flash, 'the drop is legible as it expires').not.toBeNull();
        expect(flash!.early.length, 'the drop is on the field long enough to be measured').toBeGreaterThan(2);
        // The location check. Without it, "the ink changed" is satisfied by ink that was never there.
        expect(flash!.earlyMean, 'the drop is drawn, and the probe is looking at it').toBeGreaterThan(4);
        expect(flash!.earlySpread, 'and it is steady while there is time to spare').toBeLessThanOrEqual(
            flash!.earlyMean * 0.4,
        );
        expect(flash!.late.length, 'the drop survives into the final seconds').toBeGreaterThan(3);
        // A flash is a visible change. The deadline must be something the player can see, not discover.
        expect(flash!.lateSpread, 'the drop is legible as it expires').toBeGreaterThan(flash!.earlyMean * 0.4);
    });

    test('the score threshold remains a guarantee @p12', async ({ page }) => {
        await boot(page);

        const guarantee = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            // The starved case: no carrier collected, no drop waiting, nothing held. Clearing the
            // drops must NOT be a prerequisite for measuring the guarantee -- the backstop exists
            // precisely for a player who never meets a carrier, so it is measured without one.
            if (Array.isArray(game.state.pickups)) game.state.pickups.length = 0;
            game.state.powerup.charges = 0;
            game.state.powerup.active = false;
            const before = game.state.powerup.charges;
            game.addScore(10000);
            game.test.step(4);
            return { before, after: game.state.powerup.charges };
        });

        expect(guarantee, 'the score threshold remains a guarantee').not.toBeNull();
        expect(guarantee!.before, 'the guarantee is measured from empty').toBe(0);
        expect(guarantee!.after, 'the score threshold remains a guarantee').toBe(1);
    });

    // ---------------------------------------------------------------------------------------------
    // @p13 -- presentation: the score row on black, and the moon.
    //
    // The score row is DOM, not canvas, so this is the first probe in this project that measures
    // DOM PIXELS. It screenshots the HUD element and decodes that PNG inside the browser, because a
    // probe that reads getComputedStyle would report the fix whether or not anything on screen moved.
    // ---------------------------------------------------------------------------------------------
    test('the score row renders on black and stays legible @p13', async ({ page }) => {
        await boot(page);

        const hud = page.locator('.star-swarm__hud');
        await expect(hud).toBeVisible();
        const shot = await hud.screenshot();

        const pixels = await page.evaluate(async (base64: string) => {
            const image = new Image();
            await new Promise<void>((resolve, reject) => {
                image.onload = () => resolve();
                image.onerror = () => reject(new Error('the HUD screenshot did not decode'));
                image.src = `data:image/png;base64,${base64}`;
            });
            const surface = document.createElement('canvas');
            surface.width = image.width;
            surface.height = image.height;
            const context = surface.getContext('2d');
            if (!context) return null;
            context.drawImage(image, 0, 0);
            const data = context.getImageData(0, 0, surface.width, surface.height).data;
            let total = 0;
            let dark = 0;
            let bright = 0;
            for (let i = 0; i < data.length; i += 4) {
                const high = Math.max(data[i], data[i + 1], data[i + 2]);
                const low = Math.min(data[i], data[i + 1], data[i + 2]);
                total += 1;
                // Near-black and near-neutral: a saturated dark blue would not be black space.
                if (high <= 60 && high - low <= 24) dark += 1;
                if (high >= 160) bright += 1;
            }
            return {
                total,
                dark,
                bright,
                darkShare: total ? dark / total : 0,
                brightShare: total ? bright / total : 0,
            };
        }, shot.toString('base64'));

        const contrast = await page.evaluate(() => {
            const row = document.querySelector('.star-swarm__hud') as HTMLElement | null;
            const value = row?.querySelector('[data-star-swarm="score"]') as HTMLElement | null;
            if (!row || !value) return null;
            const parse = (colour: string) => {
                const match = colour.match(/rgba?\(([^)]+)\)/);
                if (!match) return null;
                const parts = match[1].split(',').map((part) => parseFloat(part));
                return { rgb: parts.slice(0, 3), alpha: parts.length > 3 ? parts[3] : 1 };
            };
            const luminance = (rgb: number[]) => {
                const [red, green, blue] = rgb.map((value2) => {
                    const channel = value2 / 255;
                    return channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
            };
            const background = parse(getComputedStyle(row).backgroundColor);
            const foreground = parse(getComputedStyle(value).color);
            if (!background || !foreground || background.alpha < 1) return null;
            const one = luminance(foreground.rgb);
            const two = luminance(background.rgb);
            return {
                ratio: (Math.max(one, two) + 0.05) / (Math.min(one, two) + 0.05),
                background: background.rgb,
                foreground: foreground.rgb,
            };
        });

        expect(pixels, 'the score row renders and its own pixels can be read').not.toBeNull();
        expect(pixels!.darkShare, 'the score row renders on black').toBeGreaterThan(0.6);
        expect(contrast, 'the score row has an opaque background to measure against').not.toBeNull();
        expect(contrast!.ratio, 'the score row stays legible').toBeGreaterThanOrEqual(7);
        expect(pixels!.brightShare, 'the values are still bright ink on that black').toBeGreaterThan(0.004);
    });

    test('the nursery body reads as a moon, with no ring @p13', async ({ page }) => {
        await boot(page);

        const body = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            game.state.bullets.length = 0;
            game.state.enemyBullets.length = 0;
            game.state.explosions.length = 0;
            game.state.scorePopups.length = 0;

            const nursery = game.state.nursery;
            const radius = nursery.radius;
            const ink = (x: number, y: number, w: number, h: number) => (window as any).__px.ink(x, y, w, h);

            // Inscribed box: inside the disc, so the black corners of a bounding box cannot be
            // counted as a "shade" of the body.
            const inner = radius * 0.5;
            const shades = (window as any).__px.shades(
                nursery.x - inner,
                nursery.y - inner,
                inner * 2,
                inner * 2,
            );
            const stats = (window as any).__px.stats(
                nursery.x - inner,
                nursery.y - inner,
                inner * 2,
                inner * 2,
            );

            // A ring extends past the disc, so an empty band just outside the radius IS the
            // measurement of its absence. Four sides, and the disc itself as the control.
            const outside = {
                left: ink(nursery.x - radius - 24, nursery.y - 8, 16, 16),
                right: ink(nursery.x + radius + 8, nursery.y - 8, 16, 16),
                top: ink(nursery.x - 8, nursery.y - radius - 24, 16, 16),
                bottom: ink(nursery.x - 8, nursery.y + radius + 8, 16, 16),
            };
            const disc = ink(nursery.x - radius * 0.7, nursery.y - radius * 0.7, radius * 1.4, radius * 1.4);

            const dominant = stats.dominant;
            const chroma = Math.max(dominant.r, dominant.g, dominant.b)
                - Math.min(dominant.r, dominant.g, dominant.b);
            const luma = Math.round(0.2126 * dominant.r + 0.7152 * dominant.g + 0.0722 * dominant.b);
            // Only shades that are a real part of the body count; a stray edge is not a crater.
            const real = shades.shares.filter((shade: any) => shade.share >= 0.03);
            const levels = real.map((shade: any) => shade.level);

            return {
                outside,
                disc,
                dominant,
                chroma,
                blueRed: dominant.b - dominant.r,
                luma,
                shadeCount: real.length,
                shadeRange: levels.length ? Math.max(...levels) - Math.min(...levels) : 0,
            };
        });

        // The control first: if the body is not where the probe looks, nothing else here means anything.
        expect(body.disc, 'the probe is looking at the body, which is drawn').toBeGreaterThan(200);

        // A channel spread of 30 is NOT enough to call a body grey: measured before this item was
        // written, the blue planet's dominant ink is [232, 248, 248], a spread of 16 that passed a
        // spread-only test while sitting there plainly cyan. So neutrality is asserted as a spread
        // AND as a blue-red gap, and the moon is additionally required to be a mid grey tone rather
        // than the near-white (luminance 245) disc that is there now.
        expect(body.chroma, 'the body reads as a moon').toBeLessThanOrEqual(24);
        expect(body.blueRed, 'and it is neutral, not the pale cyan it was').toBeLessThanOrEqual(12);
        expect(body.luma, 'the moon is a grey tone, not a near-white disc').toBeLessThanOrEqual(200);
        expect(body.luma, 'and it is not a black hole either').toBeGreaterThanOrEqual(60);
        // Craters are tonal structure. Two shades 16 apart is a two-stop gradient across a flat disc,
        // which is what is there now; craters need a third shade and real separation.
        expect(body.shadeCount, 'the moon is shaded by craters, not a flat disc').toBeGreaterThanOrEqual(3);
        expect(body.shadeRange, 'and that shading has real tonal range').toBeGreaterThanOrEqual(48);

        for (const [side, ink] of Object.entries(body.outside)) {
            expect(ink, `the ring is gone (${side} of the body is black space)`).toBeLessThanOrEqual(12);
        }
    });

    test('the field still reads black with the moon in it @p13', async ({ page }) => {
        await boot(page);

        const field = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            game.state.bullets.length = 0;
            game.state.enemyBullets.length = 0;
            game.state.explosions.length = 0;
            game.state.scorePopups.length = 0;

            const canvas = game.lane.canvas;
            const nursery = game.state.nursery;
            const whole = (window as any).__px.stats(0, 0, canvas.width, canvas.height);
            const moon = (window as any).__px.ink(
                nursery.x - nursery.radius * 0.7,
                nursery.y - nursery.radius * 0.7,
                nursery.radius * 1.4,
                nursery.radius * 1.4,
            );
            return { blackShare: whole.blackShare, moon };
        });

        // Both halves, so the claim cannot be satisfied by not drawing the moon at all.
        expect(field.moon, 'the moon is drawn in the frame being measured').toBeGreaterThan(200);
        expect(field.blackShare, 'the field still reads black with the moon in it').toBeGreaterThan(0.9);
    });

    // ---------------------------------------------------------------------------------------------
    // @p14 -- the stage-clear beat.
    //
    // The level arc exists (a loop is eight stages) but nothing ACKNOWLEDGED a stage being cleared:
    // the next wave simply began. A player who cannot tell a stage ended cannot tell the game has a
    // shape, and the question "what happens after every level" had no answer on screen.
    // ---------------------------------------------------------------------------------------------
    test('clearing a stage announces itself with a beat @p14', async ({ page }) => {
        await boot(page);

        const clear = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(90);

            // Clear the field through the REAL destruction path, then let the game notice.
            for (let guard = 0; guard < 80; guard += 1) {
                const index = game.state.enemies.findIndex((enemy: any) => enemy.alive);
                if (index < 0) break;
                game.test.kill(index);
            }
            game.test.step(120);

            const canvas = game.lane.canvas;
            const band = { x: 0, y: canvas.height * 0.36, w: canvas.width, h: canvas.height * 0.28 };
            const px = (window as any).__px;

            // Ink in the beat's band for a given pair of counters, with the beat held open and the
            // simulation NOT advanced. Rendering the same frame twice is the point: stepping between
            // measurements moves the starfield, and starfield noise is larger than the few glyphs a
            // tally adds -- a differential built on step() passed against a drawn line that ignored
            // the counters entirely. Holding the frame still is what makes the comparison mean anything.
            const measure = (destroyed: number, tally: number) => {
                game.state.stageClear.destroyed = destroyed;
                game.state.stageClear.tally = tally;
                game.state.stageClear.life = 2.4;
                game.render();
                return px.stats(band.x, band.y, band.w, band.h).bright;
            };

            return {
                stage: game.state.stage,
                life: game.state.stageClear ? game.state.stageClear.life : null,
                text: game.state.stageClear ? game.state.stageClear.text : null,
                tally: game.state.stageClear ? game.state.stageClear.tally : null,
                destroyed: game.state.stageClear ? game.state.stageClear.destroyed : null,
                // Ink for a small tally versus a large one, measured on the same field.
                inkSmall: measure(12, 340),
                inkLarge: measure(99999, 999999),
            };
        });

        expect(clear.life, 'the game exposes a stage-clear beat').not.toBeNull();
        expect(String(clear.text), 'the stage clear announces itself').toMatch(/STAGE|LOOP/i);
        // The tally must be the stage's own, and it must be real: a beat that reported zero destroyed
        // after a stage was cleared would be reporting the wrong thing, not the absence of a feature.
        expect(clear.tally, 'the beat carries the points this stage earned').toBeGreaterThan(0);
        expect(clear.destroyed, 'and the number of enemies destroyed').toBeGreaterThan(0);

        // The two halves of the claim, measured separately and BOTH required.
        //
        // `state.stageClear.tally` proves the number exists. It does not prove anyone can see it, and
        // this project has already shipped a value that lived in state and never reached the screen.
        //
        // An ink threshold alone does not close that gap either: the beat already drew "STAGE 1 OF 8
        // CLEAR" before any tally existed, so a threshold passes whether or not the numbers are drawn.
        // Verified by falsification -- stripping the counters from the drawn string while leaving them
        // in state still cleared an ink assertion. What closes it is a DIFFERENTIAL: the same field
        // drawn with a small tally and with a large one must differ, because the numbers are longer.
        // If the drawn line ignores the counters, both measurements are identical and this fails.
        expect(clear.inkSmall, 'the beat draws something, so the field is not blank').toBeGreaterThan(20);
        expect(
            clear.inkLarge,
            'and the drawn ink tracks the counters -- the numbers are on the screen, not only in state',
        ).toBeGreaterThan(clear.inkSmall);
    });

    // ---------------------------------------------------------------------------------------------
    // @p15 -- the drop has to arrive.
    //
    // Reported from play: "the drop item does not reach the bottom for the player to collect".
    // Measured cause: the pickup falls at 18px/s and expires after 7s, so it travels 126px in a field
    // 720px tall and dies about a quarter of the way down -- the player cannot reach it even by
    // standing directly underneath. A reward that can never be collected is a taunt, not a reward.
    // ---------------------------------------------------------------------------------------------
    test('the carrier drop reaches the player instead of dying in mid-air @p15', async ({ page }) => {
        await boot(page);

        const drop = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);

            const carrierIndex = game.state.enemies.findIndex(
                (enemy: any) =>
                    enemy.alive && game.state.carrier && enemy.enemyIndex === game.state.carrier.enemyIndex,
            );
            if (carrierIndex < 0) return { dropped: false, reason: 'no live carrier in the wave' };

            game.test.kill(carrierIndex);
            if (game.state.pickups.length === 0) return { dropped: false, reason: 'the carrier dropped nothing' };

            const startY = game.state.pickups[0].y;
            const playerY = game.state.player.y;
            let lowest = startY;
            let collected = false;

            // Step until it is taken or gone. 1200 steps is well past any plausible lifetime, so a
            // pickup still in the air at the end is reported rather than silently truncating the run.
            let steps = 0;
            for (; steps < 1200; steps += 1) {
                game.test.step(1);
                if (game.state.pickups.length === 0) {
                    // Gone: either the player took it (reward reaches them) or it expired (defect).
                    collected = lowest >= playerY - 40;
                    break;
                }
                lowest = game.state.pickups[0].y;
            }

            return { dropped: true, startY, lowest, playerY, collected, steps };
        });

        expect(drop.dropped, String((drop as any).reason ?? 'the carrier drops a pickup when destroyed')).toBe(true);
        // The assertion that carries the defect: the lowest point the drop reached must be at the
        // player's row, not short of it. Anything above that line is unreachable by design.
        expect(
            drop.lowest,
            'the drop descends to the player\'s row before it expires',
        ).toBeGreaterThanOrEqual(drop.playerY! - 40);
    });

    // ---------------------------------------------------------------------------------------------
    // @p16 -- one weapon per stage, and the loop has eight of them.
    //
    // The director asked for "new weapons per level". That is a design decision, so it is grounded in
    // three cabinets rather than taste:
    //   * Galaga (1981) for the stage STRUCTURE -- an endless loop with a Challenging Stage every
    //     fourth stage starting at 3, which is why 3 and 7 of an eight-stage loop are the challenging
    //     ones, and why the two-shot ceiling (PLAYER_SHOT_LIMIT) is a reward and not a weapon.
    //   * Raiden (1990) for WEAPON IDENTITY -- pickups are colour-coded and deterministic, so a player
    //     learns which stage carries what instead of gambling on a random drop.
    //   * Gradius (1985) for ESCALATION -- the arsenal grows with the run rather than being handed over
    //     at the start, so each stage introduces something the player has not yet had.
    // ---------------------------------------------------------------------------------------------
    test('every stage of the loop carries a weapon of its own @p16', async ({ page }) => {
        await boot(page);

        const arsenal = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            const stagesPerLoop = game.state.stagesPerLoop;
            const perStage: string[] = [];

            for (let stage = 1; stage <= stagesPerLoop; stage += 1) {
                game.test.spawnWave(stage);
                game.test.step(120);
                const carrierIndex = game.state.enemies.findIndex(
                    (enemy: any) =>
                        enemy.alive && game.state.carrier && enemy.enemyIndex === game.state.carrier.enemyIndex,
                );
                if (carrierIndex < 0) {
                    perStage.push('CHALLENGING');
                    continue;
                }
                game.test.kill(carrierIndex);
                perStage.push(game.state.pickups.length ? String(game.state.pickups[0].kind) : 'NONE');
            }

            return { perStage, stagesPerLoop };
        });

        expect(arsenal.stagesPerLoop, 'the loop is the eight stages the roster describes').toBe(8);
        expect(arsenal.perStage, 'no stage fails to name its weapon').not.toContain('NONE');

        const carried = arsenal.perStage.filter((kind: string) => kind !== 'CHALLENGING');
        expect(
            new Set(carried).size,
            'each stage that carries a weapon carries a different one: ' + carried.join(', '),
        ).toBe(carried.length);

        // Galaga's rule, which is what makes the challenging stages predictable rather than arbitrary.
        const challenging = arsenal.perStage
            .map((kind: string, index: number) => (kind === 'CHALLENGING' ? index + 1 : 0))
            .filter((stage: number) => stage > 0);
        expect(challenging, 'the challenging stages are Galaga\'s every fourth, starting at 3')
            .toEqual([3, 7]);
    });
});
