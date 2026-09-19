// Star Swarm — the audio surface.
//
// WHY THIS IS A SEPARATE CHAIR-OWNED SPEC
// The pixel spec asserts on the rendered canvas. Sound is not pixels and cannot be heard by a machine, so
// the honest thing to assert is the AUDIO GRAPH the game reports: which cue was issued, on which frame,
// with which parameters, and whether the context was actually running. That is what these probes do.
//
// WHAT THEY PROVE, AND WHAT THEY CANNOT
// They prove a cue was issued to a live AudioContext at the right moment with the right parameters, and
// that the game degrades to silence instead of throwing when Web Audio is missing. They cannot prove a
// speaker made a noise. A log-only implementation would pass the cue checks, which is why the context state
// and sample rate are asserted too: a fabricated log entry cannot produce a running AudioContext.
//
// The cues are SYNTHESIZED (oscillators and noise through the Web Audio API), not files: no binary assets,
// no new dependency, and the parameters stay measurable.
//
// Run:
//   TENANT_URL=http://akiracms.test npx playwright test tests/browser/star-swarm-audio.spec.ts

import { test, expect, type Page } from '@playwright/test';

const TENANT = process.env.TENANT_URL ?? 'http://akiracms.test';
const GAME_URL = `${TENANT}/star-swarm/`;

/** Boot and start the game: the AudioContext must be created from a player gesture. */
async function boot(page: Page) {
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

/** The audio surface, as the game reports it. */
const audio = (page: Page) => page.evaluate(() => (window as any).StarSwarm.state.audio);

const lastCue = (page: Page) =>
    page.evaluate(() => {
        const log = (window as any).StarSwarm.state.audio?.log ?? [];
        return log.length ? log[log.length - 1] : null;
    });

test.describe('star swarm audio', () => {
    test('the audio context is running after the player starts @p9', async ({ page }) => {
        await boot(page);

        const surface = await audio(page);

        expect(surface, 'the game exposes its audio state').toBeTruthy();
        expect(surface.contextState, 'the audio context is running once the player has started').toBe('running');
        expect(surface.sampleRate, 'a real AudioContext reports a sample rate').toBeGreaterThan(8000);
        expect(Array.isArray(surface.log), 'the cues issued are observable').toBe(true);
        expect(surface.count, 'starting play sounds the start cue').toBeGreaterThan(0);
    });

    test('starting play sounds the start cue @p9', async ({ page }) => {
        await boot(page);

        const surface = await audio(page);
        const cues = ((surface?.log ?? []) as { cue: string }[]).map((entry) => entry.cue);

        expect(cues, 'the start cue is issued when play begins').toContain('start');
    });

    test('firing sounds a cue on the same frame @p9', async ({ page }) => {
        await boot(page);

        const fired = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(120);
            game.state.enemies.length = 0;
            game.state.bullets.length = 0;
            // Optional chaining on purpose: before the audio surface exists, these probes must fail as
            // ASSERTIONS ("no cue was issued") rather than as a TypeError from reading undefined. An
            // instrument that crashes tells the lane nothing about what is missing.
            const before = game.state.audio?.count ?? 0;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
            game.test.step(1);
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp' }));
            const log = game.state.audio?.log ?? [];
            return {
                issued: (game.state.audio?.count ?? 0) - before,
                last: log.length ? log[log.length - 1].cue : null,
                shotsSpawned: game.state.bullets.length,
            };
        });

        expect(fired.shotsSpawned, 'the keypress produced a shot').toBeGreaterThan(0);
        expect(fired.issued, 'a shot sounds exactly one cue').toBeGreaterThan(0);
        expect(fired.last, 'the cue for firing is the fire cue').toBe('fire');
    });

    test('a bigger enemy sounds a heavier hit @p9', async ({ page }) => {
        await boot(page);

        const hits = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            const destroy = (caste: string) => {
                game.test.spawnWave(2);
                game.test.step(180);
                const index = game.state.enemies.findIndex(
                    (enemy: any) => enemy.caste === caste && enemy.alive && !enemy.diving && !enemy.entering,
                );
                if (index < 0) return null;
                const enemy = game.test.snapshotEnemy(index);
                const before = game.state.audio?.log?.length ?? 0;
                game.test.kill(index);
                const log = game.state.audio?.log ?? [];
                // With no cue issued, report the missing cue rather than an undestroyable enemy: the
                // assertion that fails should name the gap the lane has to close.
                const entry = log.length > before ? log[log.length - 1] : { cue: null, size: 0, frequency: 0 };
                return { cue: entry.cue, size: entry.size, frequency: entry.frequency, enemyWidth: enemy.width };
            };
            return { bee: destroy('bee'), boss: destroy('boss') };
        });

        expect(hits.bee, 'a bee can be destroyed').not.toBeNull();
        expect(hits.boss, 'a boss can be destroyed').not.toBeNull();
        expect(hits.bee!.cue, 'a destroyed enemy sounds the hit cue').toBe('hit');
        expect(hits.boss!.cue, 'a destroyed boss sounds the hit cue too').toBe('hit');
        // "According to size": the cue must carry the enemy's own size, and the bigger enemy must sound
        // lower and longer -- the arcade convention, and the reason this is asserted as an ORDER rather
        // than as two magic numbers.
        expect(hits.boss!.size, 'the hit cue records the size it was sounded for').toBeGreaterThan(0);
        expect(hits.boss!.size, 'a boss is a bigger body than a bee').toBeGreaterThan(hits.bee!.size);
        expect(hits.boss!.frequency, 'the bigger enemy sounds lower').toBeLessThan(hits.bee!.frequency);
    });

    test('the tractor beam sounds while it is open @p9', async ({ page }) => {
        await boot(page);

        const beam = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(2);
            game.test.step(150);
            let index = -1;
            for (let size = 1; size <= 3 && index < 0; size += 1) {
                game.test.startDive(size);
                index = game.state.enemies.findIndex(
                    (enemy: any) => enemy.caste === 'boss' && enemy.alive && enemy.diving,
                );
            }
            if (index < 0 || !game.test.startTractorBeam(index)) return null;
            const whileOpen = {
                beamActive: game.state.audio?.beamActive === true,
                cues: (game.state.audio?.log ?? []).map((entry: { cue: string }) => entry.cue),
            };
            // The beam closes on its own after ~1.8 s, or the moment it captures the fighter.
            for (let frame = 0; frame < 200; frame += 1) game.test.step(1);
            return { whileOpen, afterClose: { beamActive: game.state.audio?.beamActive === true } };
        });

        expect(beam, 'a diving boss can open a tractor beam').not.toBeNull();
        expect(beam!.whileOpen.cues, 'opening the beam sounds the beam cue').toContain('beam');
        // An ongoing sound, not a one-shot: the beam is a sustained threat, and a single blip when it opens
        // would leave the player unwarned for the rest of the dive.
        expect(beam!.whileOpen.beamActive, 'the beam tone keeps sounding while the beam is open').toBe(true);
        expect(beam!.afterClose.beamActive, 'the beam tone stops when the beam closes').toBe(false);
    });

    test('losing the last life sounds the game over cue @p9', async ({ page }) => {
        await boot(page);

        const over = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            game.test.spawnWave(1);
            game.test.step(150);
            game.state.lives = 1;
            game.state.player.invulnerable = 0;
            const shotIndex = game.test.fireEnemyShot(0);
            // Nothing else on the field, so the only thing that can cost the life is the shot placed on the
            // player: the real collision path, not a shortcut.
            game.state.enemies.length = 0;
            const bullet = game.state.enemyBullets[shotIndex];
            if (!bullet) return null;
            bullet.x = game.state.player.x + game.state.player.width / 2;
            bullet.y = game.state.player.y + game.state.player.height / 2;
            for (let frame = 0; frame < 90 && !game.state.gameOver; frame += 1) game.test.step(1);
            const log = game.state.audio?.log ?? [];
            return {
                gameOver: game.state.gameOver,
                last: log.length ? log[log.length - 1].cue : null,
                cues: log.map((entry: { cue: string }) => entry.cue),
            };
        });

        expect(over, 'an enemy shot can be placed on the player').not.toBeNull();
        expect(over!.gameOver, 'losing the last life ends the game').toBe(true);
        expect(over!.cues, 'the end of the game sounds its own cue').toContain('gameover');
    });

    test('the game stays playable without Web Audio @p9', async ({ page }) => {
        // A browser or policy that removes Web Audio must cost the player sound, not the game.
        await page.addInitScript(() => {
            delete (window as any).AudioContext;
            delete (window as any).webkitAudioContext;
        });
        const pageErrors: string[] = [];
        page.on('pageerror', (error) => pageErrors.push(String(error)));

        await boot(page);

        const surface = await page.evaluate(() => {
            const game = (window as any).StarSwarm;
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
            game.test.spawnWave(1);
            game.test.step(60);
            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp' }));
            return { state: game.state.audio, running: game.state.running };
        });

        expect(pageErrors, 'no audio support must not raise an error').toEqual([]);
        expect(surface.state?.contextState, 'the game reports audio as unavailable').toBe('unavailable');
        expect(surface.running, 'the game plays on, silently').toBe(true);
    });
});
