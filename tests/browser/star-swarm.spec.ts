// Star Swarm — Tier B: a browser must prove it PLAYS.
//
// A 200 with a canvas and a dead game is a false completion. This spec drives
// the live page and asserts on the exposed window.StarSwarm state: boot, input,
// the running loop, and a clean teardown. It reads state, never pixels.
//
// Run:
//   TENANT_URL=http://akiracms.test npx playwright test tests/browser/star-swarm.spec.ts

import { test, expect, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';

const TENANT = process.env.TENANT_URL ?? 'http://akiracms.test';
const GAME_URL = `${TENANT}/star-swarm/`;

function watch(page: Page) {
    const consoleErrors: string[] = [];
    const pageErrors: string[] = [];
    page.on('console', (m) => {
        if (m.type() === 'error') consoleErrors.push(m.text());
    });
    page.on('pageerror', (e) => pageErrors.push(String(e)));
    return { consoleErrors, pageErrors };
}

test.describe('star swarm', () => {
    test('boots, responds to input, runs the loop, and tears down cleanly', async ({ page }) => {
        const { consoleErrors, pageErrors } = watch(page);

        const response = await page.goto(GAME_URL, { waitUntil: 'domcontentloaded' });
        expect(response?.status(), 'game URL must answer 200').toBe(200);

        const canvas = page.locator('#star-swarm-canvas');
        await expect(canvas).toBeVisible();
        const box = await canvas.boundingBox();
        expect(box, 'canvas has a layout box').not.toBeNull();
        expect(box!.width, 'canvas width is non-zero').toBeGreaterThan(0);
        expect(box!.height, 'canvas height is non-zero').toBeGreaterThan(0);

        // The game object must exist before anything else can be asserted.
        await expect
            .poll(async () => page.evaluate(() => typeof window.StarSwarm === 'object'))
            .toBe(true);

        // Start screen first: not running until the player starts.
        expect(await page.evaluate(() => window.StarSwarm.state.running)).toBe(false);
        await page.locator('[data-star-swarm="action"]').click();

        await expect
            .poll(async () => page.evaluate(() => window.StarSwarm.state.running))
            .toBe(true);
        const boot = await page.evaluate(() => ({
            score: window.StarSwarm.state.score,
            lives: window.StarSwarm.state.lives,
            wave: window.StarSwarm.state.wave,
        }));
        expect(typeof boot.score).toBe('number');
        expect(boot.lives).toBeGreaterThan(0);
        expect(boot.wave).toBeGreaterThanOrEqual(1);

        // The colony enters successive waves with a different named mind and
        // a different oscillation cadence. Restore wave one for legacy probes.
        const moods = await page.evaluate(() => {
            const game = window.StarSwarm;
            const sample = () => ({
                wave: game.state.wave,
                mood: game.state.mood,
                cadence: game.state.moodCadence,
            });
            game.test.spawnWave(1);
            const first = sample();
            game.test.spawnWave(2);
            const second = sample();
            game.test.spawnWave(1);
            return [first, second];
        });
        expect(moods.map((sample) => sample.wave)).toEqual([1, 2]);
        expect(new Set(moods.map((sample) => sample.mood)).size, 'mood changes across waves').toBe(2);
        expect(moods[0].cadence, 'oscillation cadence differs between waves').not.toBe(moods[1].cadence);
        expect(moods.every((sample) => ['undulate', 'probe', 'dive', 'frenzy'].includes(sample.mood))).toBe(true);

        // Depth is gameplay state: every wave hatches at the visible nursery
        // planet, and three collision-sized scale bands coexist from frame one.
        const depth = await page.evaluate(() => {
            const game = window.StarSwarm;
            game.test.spawnWave(3);
            const nursery = game.state.nursery;
            const enemies = game.state.enemies.map((_: unknown, index: number) => game.test.snapshotEnemy(index)!);
            const result = {
                scaleBands: [...new Set(enemies.map((enemy: { scale: number }) => enemy.scale))],
                nurseryOrigin: Math.max(...enemies.map((enemy: { x: number; y: number }) =>
                    Math.hypot(enemy.x - nursery.x, enemy.y - nursery.y))),
                recordsComplete: enemies.every((enemy: { class: string; role: string; scale: number }) =>
                    typeof enemy.class === 'string' && typeof enemy.role === 'string' && enemy.scale > 0),
            };
            game.test.spawnWave(1);
            return result;
        });
        expect(depth.scaleBands, 'three distinct enemy depth scales are live together').toHaveLength(3);
        expect(depth.nurseryOrigin, 'new-wave bodies originate inside the nursery planet').toBeLessThan(30);
        expect(depth.recordsComplete, 'enemy observability includes scale, class, and role').toBe(true);

        // P1 is physics, not formation choreography. Settle a wave, advance the
        // real update path at fixed intervals, then strike one body through the
        // same function used by projectile collision.
        const swarm = await page.evaluate(() => {
            const game = window.StarSwarm;
            game.test.spawnWave(1);
            const state = game.state;
            const saved = {
                elapsed: state.elapsed,
                score: state.score,
                lives: state.lives,
                bullets: state.bullets,
                enemyBullets: state.enemyBullets,
                explosions: state.explosions,
                scorePopups: state.scorePopups,
                stars: state.starLayers.map((layer: any) => layer.stars.map((star: any) => ({ x: star.x, y: star.y }))),
            };
            state.formation.diveCooldown = 999;
            state.enemies.forEach((enemy: {
                entering: boolean; diving: boolean; x: number; y: number;
                baseX: number; baseY: number; alive: boolean;
            }) => {
                enemy.entering = false;
                enemy.diving = false;
                enemy.x = enemy.baseX;
                enemy.y = enemy.baseY;
            });

            let pairwiseMinDistance = Number.POSITIVE_INFINITY;
            const centroidSamples: Array<{ x: number; y: number }> = [];
            for (let sample = 0; sample < 10; sample += 1) {
                game.test.step(15);
                state.formation.diveCooldown = 999;
                const live = state.enemies.filter((enemy: { alive: boolean; diving: boolean }) =>
                    enemy.alive && !enemy.diving);
                for (let i = 0; i < live.length; i += 1) {
                    for (let j = i + 1; j < live.length; j += 1) {
                        const a = live[i]; const b = live[j];
                        const gapX = Math.abs((a.x + a.width / 2) - (b.x + b.width / 2))
                            - (a.width + b.width) / 2;
                        const gapY = Math.abs((a.y + a.height / 2) - (b.y + b.height / 2))
                            - (a.height + b.height) / 2;
                        // Rectangles are disjoint when at least one axis has a
                        // non-negative edge gap.
                        pairwiseMinDistance = Math.min(pairwiseMinDistance, Math.max(gapX, gapY));
                    }
                }
                centroidSamples.push({
                    x: live.reduce((sum: number, enemy: { x: number; width: number }) => sum + enemy.x + enemy.width / 2, 0) / live.length,
                    y: live.reduce((sum: number, enemy: { y: number; height: number }) => sum + enemy.y + enemy.height / 2, 0) / live.length,
                });
            }

            const first = centroidSamples[0];
            const last = centroidSamples[centroidSamples.length - 1];
            const pathDx = last.x - first.x;
            const pathDy = last.y - first.y;
            const pathLength = Math.max(1, Math.hypot(pathDx, pathDy));
            const centroidPath = Math.max(...centroidSamples.slice(1, -1).map((point) =>
                Math.abs(pathDy * point.x - pathDx * point.y + last.x * first.y - last.y * first.x) / pathLength));

            const live = state.enemies.filter((enemy: { alive: boolean; diving: boolean }) =>
                enemy.alive && !enemy.diving);
            const victim = live.reduce((closest: any, enemy: any) => {
                const distance = Math.hypot(enemy.x + enemy.width / 2 - 400, enemy.y + enemy.height / 2 - 170);
                return !closest || distance < closest.distance ? { enemy, distance } : closest;
            }, null).enemy;
            const victimX = victim.x + victim.width / 2;
            const victimY = victim.y + victim.height / 2;
            const neighbours = live.filter((enemy: any) => enemy !== victim)
                .map((enemy: any) => ({
                    enemy,
                    before: Math.hypot(enemy.x + enemy.width / 2 - victimX, enemy.y + enemy.height / 2 - victimY),
                }))
                .filter((sample: { before: number }) => sample.before < 150);
            const victimIndex = state.enemies.indexOf(victim);
            game.test.kill(victimIndex);
            for (let step = 0; step < 3; step += 1) {
                game.test.step(5);
                state.formation.diveCooldown = 999;
            }
            const dispersal = neighbours.reduce((sum: number, sample: any) => sum
                + Math.hypot(sample.enemy.x + sample.enemy.width / 2 - victimX,
                    sample.enemy.y + sample.enemy.height / 2 - victimY) - sample.before, 0) / neighbours.length;

            game.test.spawnWave(1);
            state.elapsed = saved.elapsed;
            state.score = saved.score;
            state.lives = saved.lives;
            state.bullets = saved.bullets;
            state.enemyBullets = saved.enemyBullets;
            state.explosions = saved.explosions;
            state.scorePopups = saved.scorePopups;
            state.starLayers.forEach((layer: any, layerIndex: number) => layer.stars.forEach((star: any, starIndex: number) => {
                star.x = saved.stars[layerIndex][starIndex].x;
                star.y = saved.stars[layerIndex][starIndex].y;
            }));
            window.StarSwarm.render();
            return { pairwiseMinDistance, centroidPath, dispersal, neighbourCount: neighbours.length };
        });
        expect(swarm.pairwiseMinDistance, 'no pair of settled live enemy bodies overlaps across ten samples')
            .toBeGreaterThanOrEqual(1.5);
        expect(swarm.centroidPath, 'centroid path bends measurably away from its start-to-end chord')
            .toBeGreaterThan(0.5);
        expect(swarm.neighbourCount, 'the struck body has enough neighbours to measure colony response')
            .toBeGreaterThanOrEqual(3);
        expect(swarm.dispersal, 'a kill pushes neighbouring bodies radially away from the wound')
            .toBeGreaterThan(4);

        // Shape, rather than colour, identifies caste. Render each class at the
        // same scale and location, then measure only pixels changed by its ink.
        const silhouetteClasses = await page.evaluate(() => {
            const game = window.StarSwarm;
            game.test.spawnWave(1);
            game.test.step(1);
            const canvas = document.querySelector<HTMLCanvasElement>('#star-swarm-canvas')!;
            const ctx = canvas.getContext('2d')!;
            const saved = game.state.enemies;
            const classes = ['commander', 'fighter', 'scout', 'harvester'];
            const sample = (enemyClass?: string) => {
                game.state.enemies = enemyClass ? [{
                    x: 360, y: 290, width: 34, height: 34, scale: 1,
                    class: enemyClass, type: enemyClass, role: 'threat', alive: true,
                    diving: false, entering: false, flash: 0,
                }] : [];
                game.render();
                return ctx.getImageData(350, 280, 54, 54).data.slice();
            };
            const background = sample();
            const metrics = classes.map((enemyClass) => {
                const pixels = sample(enemyClass);
                let ink = 0;
                let minX = 54; let maxX = -1; let minY = 54; let maxY = -1;
                for (let p = 0; p < 54 * 54; p += 1) {
                    const i = p * 4;
                    const changed = Math.abs(pixels[i] - background[i])
                        + Math.abs(pixels[i + 1] - background[i + 1])
                        + Math.abs(pixels[i + 2] - background[i + 2]) > 24;
                    if (!changed) continue;
                    const x = p % 54; const y = Math.floor(p / 54);
                    ink += 1; minX = Math.min(minX, x); maxX = Math.max(maxX, x);
                    minY = Math.min(minY, y); maxY = Math.max(maxY, y);
                }
                const width = maxX - minX + 1;
                const height = maxY - minY + 1;
                return { enemyClass, ink, aspect: width / height, coverage: ink / (width * height) };
            });
            game.state.enemies = saved;
            game.render();
            return metrics;
        });
        expect(silhouetteClasses.map((sample) => sample.enemyClass), 'all four silhouette classes render from live game state')
            .toEqual(['commander', 'fighter', 'scout', 'harvester']);
        expect(silhouetteClasses.every((sample, index, all) => all.every((other, otherIndex) =>
            index === otherIndex
            || Math.abs(sample.aspect - other.aspect) > 0.12
            || Math.abs(sample.coverage - other.coverage) > 0.08)),
            `each caste is separable from every other caste by canvas ink coverage or aspect ratio: ${JSON.stringify(silhouetteClasses)}`).toBe(true);

        // Semantic meaning is themeable independently of actor hue. Each role
        // resolves from its own CSS token through the same fallback-safe reader.
        const roleCoverage = await page.evaluate(() => {
            const root = document.querySelector<HTMLElement>('#star-swarm')!;
            const styles = getComputedStyle(root);
            const roles = window.StarSwarm.readTheme(root).roles;
            const tokenByRole = {
                threat: '--ss-role-threat',
                ally: '--ss-role-ally',
                reward: '--ss-role-reward',
                hazard: '--ss-role-hazard',
            } as const;
            return {
                keys: Object.keys(roles).sort(),
                matchesThemeTokens: Object.entries(tokenByRole).every(([role, token]) =>
                    roles[role as keyof typeof roles] === styles.getPropertyValue(token).trim()),
            };
        });
        expect(roleCoverage.keys, 'all semantic roles have named theme-token resolutions')
            .toEqual(['ally', 'hazard', 'reward', 'threat']);
        expect(roleCoverage.matchesThemeTokens, 'role colours resolve through CSS theme tokens').toBe(true);

        // Pixel evidence: probe the live backing canvas, not CSS or game state.
        // This quiet lower-left region contains space and stars but no play objects.
        const starfield = await page.evaluate(() => {
            const game = window.StarSwarm;
            const canvas = document.querySelector<HTMLCanvasElement>('#star-swarm-canvas')!;
            const sample = () => {
                const pixels = canvas.getContext('2d')!.getImageData(10, 330, 170, 150).data;
                let luminance = 0;
                let bright = 0;
                for (let i = 0; i < pixels.length; i += 4) {
                    const lum = 0.2126 * pixels[i] + 0.7152 * pixels[i + 1] + 0.0722 * pixels[i + 2];
                    luminance += lum;
                    if (lum > 85) bright += 1;
                }
                return { mean: luminance / (pixels.length / 4), bright };
            };
            game.test.spawnWave(1);
            game.test.step(1);
            const first = sample();
            game.test.step(60);
            return { first, after: sample() };
        });
        expect(starfield.first.mean, 'object-free space has dark mean luminance').toBeLessThan(35);
        expect(starfield.first.bright, 'starfield has bright pixels above the dark background').toBeGreaterThan(4);
        expect(starfield.after.bright, 'star bright-pixel count changes as layers drift and twinkle')
            .not.toBe(starfield.first.bright);

        // Flood-fill bright pixels around the ringed planet. A real shaded body
        // produces one contiguous cluster far larger than isolated stars.
        const planetCluster = await page.evaluate(() => {
            window.StarSwarm.test.step(1);
            const canvas = document.querySelector<HTMLCanvasElement>('#star-swarm-canvas')!;
            const width = 200;
            const height = 210;
            const data = canvas.getContext('2d')!.getImageData(590, 5, width, height).data;
            const solid = new Uint8Array(width * height);
            for (let p = 0; p < solid.length; p += 1) {
                const i = p * 4;
                const lum = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
                solid[p] = lum > 38 ? 1 : 0;
            }
            let largest = 0;
            let largestSpan = 0;
            const stack: number[] = [];
            for (let start = 0; start < solid.length; start += 1) {
                if (!solid[start]) continue;
                solid[start] = 0;
                stack.push(start);
                let count = 0;
                let minX = width;
                let maxX = 0;
                let minY = height;
                let maxY = 0;
                while (stack.length) {
                    const at = stack.pop()!;
                    const x = at % width;
                    const y = Math.floor(at / width);
                    count += 1;
                    minX = Math.min(minX, x); maxX = Math.max(maxX, x);
                    minY = Math.min(minY, y); maxY = Math.max(maxY, y);
                    for (const next of [at - 1, at + 1, at - width, at + width]) {
                        if (next < 0 || next >= solid.length || !solid[next]) continue;
                        const nx = next % width;
                        if (Math.abs(nx - x) > 1) continue;
                        solid[next] = 0;
                        stack.push(next);
                    }
                }
                if (count > largest) {
                    largest = count;
                    largestSpan = Math.min(maxX - minX, maxY - minY);
                }
            }
            return { pixels: largest, radius: largestSpan / 2 };
        });
        expect(planetCluster.pixels, 'planet is a contiguous non-background cluster').toBeGreaterThan(1500);
        expect(planetCluster.radius, 'planet cluster exceeds the minimum radius').toBeGreaterThan(35);

        // Drive staggered entrance flights to a fixed frame before probing.
        const actors = await page.evaluate(() => {
            window.StarSwarm.test.spawnWave(1);
            window.StarSwarm.test.step(120);
            const canvas = document.querySelector<HTMLCanvasElement>('#star-swarm-canvas')!;
            const ctx = canvas.getContext('2d')!;
            const player = window.StarSwarm.state.player;
            const shipData = ctx.getImageData(Math.floor(player.x) - 8, Math.floor(player.y) - 8, player.width + 16, player.height + 30).data;
            let shipPixels = 0;
            for (let i = 0; i < shipData.length; i += 4) {
                const lum = 0.2126 * shipData[i] + 0.7152 * shipData[i + 1] + 0.0722 * shipData[i + 2];
                if (lum > 55) shipPixels += 1;
            }

            const bandWidth = canvas.width;
            const bandHeight = 310;
            const band = ctx.getImageData(0, 0, bandWidth, bandHeight).data;
            const solid = new Uint8Array(bandWidth * bandHeight);
            for (let p = 0; p < solid.length; p += 1) {
                const i = p * 4;
                const lum = 0.2126 * band[i] + 0.7152 * band[i + 1] + 0.0722 * band[i + 2];
                solid[p] = lum > 48 ? 1 : 0;
            }
            let enemySizedClusters = 0;
            const stack: number[] = [];
            for (let start = 0; start < solid.length; start += 1) {
                if (!solid[start]) continue;
                solid[start] = 0;
                stack.push(start);
                let count = 0;
                while (stack.length) {
                    const at = stack.pop()!;
                    const x = at % bandWidth;
                    count += 1;
                    for (const next of [at - 1, at + 1, at - bandWidth, at + bandWidth]) {
                        if (next < 0 || next >= solid.length || !solid[next]) continue;
                        if (Math.abs((next % bandWidth) - x) > 1) continue;
                        solid[next] = 0;
                        stack.push(next);
                    }
                }
                if (count >= 30 && count <= 1200) enemySizedClusters += 1;
            }
            return { shipPixels, enemySizedClusters };
        });
        expect(actors.shipPixels, 'ship has non-background pixels in the player region').toBeGreaterThan(100);
        expect(actors.enemySizedClusters, 'formation band contains multiple enemy-sized clusters').toBeGreaterThan(5);

        // Input: ArrowRight must move the ship's x.
        const xBefore = await page.evaluate(() => window.StarSwarm.state.player.x);
        await page.keyboard.down('ArrowRight');
        const xAfter = await page.evaluate(() => {
            window.StarSwarm.test.step(15);
            return window.StarSwarm.state.player.x;
        });
        await page.keyboard.up('ArrowRight');
        expect(xAfter, 'ArrowRight increases the ship x').toBeGreaterThan(xBefore);

        // Input: fire must create a projectile, and it must be DRAWN as light.
        const bulletsBefore = await page.evaluate(() => window.StarSwarm.state.bullets.length);
        await page.keyboard.press('Space');
        const shot = await page.evaluate(() => {
            const game = window.StarSwarm;
            game.test.step(1);
            const canvas = document.querySelector<HTMLCanvasElement>('#star-swarm-canvas')!;
            const ctx = canvas.getContext('2d')!;
            const player = game.state.player;
            const x = Math.max(0, Math.floor(player.x + player.width / 2 - 15));
            const y = Math.max(0, Math.floor(player.y - 130));
            const data = ctx.getImageData(x, y, 30, 130).data;
            let bright = 0;
            for (let i = 0; i < data.length; i += 4) {
                const lum = 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
                if (lum > 130) bright += 1;
            }
            return { bulletCount: game.state.bullets.length, bright };
        });
        expect(shot.bulletCount, 'fire input creates a projectile').toBeGreaterThan(bulletsBefore);
        expect(shot.bright, 'a fired shot creates bright pixels above the ship').toBeGreaterThan(8);

        // The real loop path advances for a fixed frame count without further input.
        const enemiesBefore = await page.evaluate(() =>
            window.StarSwarm.state.enemies
                .filter((e: { alive: boolean }) => e.alive)
                .map((e: { x: number; y: number }) => [e.x, e.y]),
        );
        const enemiesAfter = await page.evaluate(() => {
            window.StarSwarm.test.step(60);
            return window.StarSwarm.state.enemies
                .filter((e: { alive: boolean }) => e.alive)
                .map((e: { x: number; y: number }) => [e.x, e.y]);
        });
        expect(enemiesAfter.length).toBeGreaterThan(0);
        const moved = enemiesAfter.some((pos, i) => {
            const before = enemiesBefore[i];
            return !before || pos[0] !== before[0] || pos[1] !== before[1];
        });
        expect(moved, 'enemies advance between two samples one second apart').toBe(true);

        const screenshotPath = 'test-results/star-swarm.png';
        await page.screenshot({ path: screenshotPath, fullPage: true });
        expect(existsSync(screenshotPath), 'running-game screenshot artifact exists').toBe(true);

        // Clean teardown: cancelling the rAF loop leaves no runaway timer.
        await page.evaluate(() => window.StarSwarm.destroy());
        const teardown = await page.evaluate(() => ({
            destroyed: window.StarSwarm.lane.destroyed,
            frame: window.StarSwarm.lane.frame,
        }));
        expect(teardown.destroyed).toBe(true);
        expect(teardown.frame).toBe(0);

        expect(pageErrors, 'no unhandled page errors').toEqual([]);
        expect(consoleErrors, 'no console errors on boot or during play').toEqual([]);
    });

    test('deterministic surface drives the real fixed-step game path', async ({ page }) => {
        const { consoleErrors, pageErrors } = watch(page);
        const response = await page.goto(GAME_URL, { waitUntil: 'domcontentloaded' });
        expect(response?.status(), 'game URL must answer 200').toBe(200);
        await page.locator('[data-star-swarm="action"]').click();

        const result = await page.evaluate(() => {
            const game = window.StarSwarm;
            const sampleRun = () => {
                game.test.spawnWave(3);
                const origin = game.test.snapshotEnemy(0);
                const frame = game.test.step(12);
                const advanced = game.test.snapshotEnemy(0);
                return { origin, advanced, frame };
            };
            const first = sampleRun();
            const second = sampleRun();
            const scoreBefore = game.state.score;
            const killed = game.test.kill(0);
            const victim = game.test.snapshotEnemy(0);
            return {
                first,
                second,
                killed,
                victimAlive: victim?.alive,
                scoreDelta: game.state.score - scoreBefore,
                animationFrameHandle: game.lane.frame,
            };
        });

        expect(result.first, 'replaying the same wave and fixed frames yields identical enemy records')
            .toEqual(result.second);
        expect(result.first.frame, 'step advances exactly the requested fixed frame count').toBe(12);
        expect(result.first.origin, 'snapshot exposes the complete enemy record without a live reference').toMatchObject({
            scale: expect.any(Number),
            class: expect.any(String),
            role: 'threat',
            alive: true,
        });
        expect(result.first.advanced?.entranceTime, 'fixed stepping advances the real formation update').toBeGreaterThan(0);
        expect(result.killed, 'kill accepts an enemy index through the real kill path').toBe(true);
        expect(result.victimAlive, 'kill marks that indexed enemy dead').toBe(false);
        expect(result.scoreDelta, 'kill retains the real score side effect').toBe(100);
        expect(result.animationFrameHandle, 'test mode owns the clock instead of racing requestAnimationFrame').toBe(0);
        expect(pageErrors, 'no unhandled page errors').toEqual([]);
        expect(consoleErrors, 'no console errors').toEqual([]);
    });
});
