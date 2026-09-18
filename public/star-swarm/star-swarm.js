/*
 * Star Swarm — a Galaga-style fixed shooter.
 *
 * One file, no framework, no CDN, no build step. The game reads its palette
 * and sizing from ARK design tokens injected as CSS custom properties on
 * #star-swarm. Every token read carries a fallback, so the canvas still
 * renders when a theme omits a token.
 */
(function () {
    'use strict';

    var CANVAS_ID = 'star-swarm-canvas';

    // --- theme -----------------------------------------------------------------
    // Each token the game consumes is read with a fallback. These names mirror
    // starSwarmTokenDefaults() in modules/star-swarm/helpers.php.
    function cssVar(root, name, fallback) {
        var value = '';
        try {
            value = getComputedStyle(root).getPropertyValue(name).trim();
        } catch (error) {
            value = '';
        }
        return value || fallback;
    }

    function readTheme(root) {
        return {
            surface: cssVar(root, '--color-surface', '#0b1020'),
            surfaceRaised: cssVar(root, '--color-surface-raised', '#141b33'),
            text: cssVar(root, '--color-text', '#e8ecff'),
            textMuted: cssVar(root, '--color-text-muted', '#9aa3c7'),
            primary: cssVar(root, '--color-primary', '#4cc9f0'),
            primaryDark: cssVar(root, '--color-primary-dark', '#1d4ed8'),
            accent: cssVar(root, '--color-accent', '#ffd166'),
            error: cssVar(root, '--color-error', '#ef476f'),
            tertiary: cssVar(root, '--color-tertiary', '#f78c6b'),
            border: cssVar(root, '--color-border', '#2a3357'),
            space: cssVar(root, '--ss-space-bg', '#02030b'),
            spaceDeep: cssVar(root, '--ss-space-deep', '#071126'),
            nebula: cssVar(root, '--ss-nebula', '#18356f'),
            starDim: cssVar(root, '--ss-star-dim', '#8ba3c7'),
            starBright: cssVar(root, '--ss-star-bright', '#f4f8ff'),
            planet: cssVar(root, '--ss-planet', '#568bb0'),
            planetLit: cssVar(root, '--ss-planet-lit', '#c9e7f2'),
            ring: cssVar(root, '--ss-ring', '#f2c879'),
            roles: Object.freeze({
                threat: cssVar(root, '--ss-role-threat', '#f78c6b'),
                ally: cssVar(root, '--ss-role-ally', '#4cc9f0'),
                reward: cssVar(root, '--ss-role-reward', '#ffd166'),
                hazard: cssVar(root, '--ss-role-hazard', '#ef476f')
            }),
            fontUi: cssVar(root, '--font-family-ui', 'Inter, system-ui, sans-serif')
        };
    }

    // --- constants -------------------------------------------------------------
    var PLAYER_SPEED = 420;        // px per second
    var PLAYER_FIRE_COOLDOWN = 0.22;
    var PLAYER_SHOT_LIMIT = 2;
    var SINGLE_FIGHTER_WIDTH = 44;
    var DUAL_FIGHTER_WIDTH = 90;
    var DUAL_FIGHTER_OFFSET = 46;
    var FIRST_EXTRA_SHIP_SCORE = 20000;
    var EXTRA_SHIP_INTERVAL = 70000;
    var BULLET_SPEED = 620;
    var ENEMY_BULLET_SPEED = 260;
    var ENEMY_ROWS = 4;
    var ENEMY_COLS = 8;
    var ENEMY_WIDTH = 34;
    var ENEMY_HEIGHT = 26;
    var ENEMY_GAP_X = 18;
    var ENEMY_GAP_Y = 16;
    var ENEMY_SCORES = Object.freeze({
        bee: Object.freeze({ formation: 50, flight: 80 }),
        butterfly: Object.freeze({ formation: 80, flight: 160 }),
        boss: Object.freeze({ formation: 150, flight: Object.freeze([400, 800, 1600]) })
    });

    // A mood changes the colony's motion grammar, not merely its speed. Waves
    // enter at successive points in this vocabulary and continue cycling.
    var MOOD_RULES = Object.freeze([
        Object.freeze({ name: 'undulate', cadence: 3.2, swayRate: 1.05, swayWidth: 42, breathe: 7, lean: 0 }),
        Object.freeze({ name: 'probe', cadence: 2.75, swayRate: 1.55, swayWidth: 28, breathe: 3, lean: 12 }),
        Object.freeze({ name: 'dive', cadence: 2.25, swayRate: 0.82, swayWidth: 58, breathe: 5, lean: -8 }),
        Object.freeze({ name: 'frenzy', cadence: 1.65, swayRate: 2.35, swayWidth: 34, breathe: 10, lean: 6 })
    ]);

    var lane = {
        root: null,
        canvas: null,
        ctx: null,
        theme: null,
        overlay: null,
        overlayTitle: null,
        overlayHint: null,
        actionButton: null,
        scoreEl: null,
        waveEl: null,
        livesEl: null,
        frame: 0,
        destroyed: false
    };

    var state = {
        running: false,
        paused: false,
        gameOver: false,
        score: 0,
        highScore: 0,
        lives: 3,
        extraShipAt: FIRST_EXTRA_SHIP_SCORE,
        wave: 1,
        stage: 1,
        stageKind: 'standard',
        challenging: false,
        challengingDestroyed: 0,
        perfectBonus: 0,
        mood: 'undulate',
        moodCadence: MOOD_RULES[0].cadence,
        moodElapsed: 0,
        player: {
            x: 380, y: 540, width: SINGLE_FIGHTER_WIDTH, height: 26,
            role: 'ally', invulnerable: 0, cooldown: 0, bank: 0,
            dualFighter: false, capturedFighter: null
        },
        bullets: [],
        enemyBullets: [],
        enemies: [],
        nursery: { x: 704, y: 112, radius: 76 },
        formation: {
            direction: 1, speed: 40, elapsed: 0, diveCooldown: 2.5,
            headingX: 0, headingY: 0, breathScale: 1
        },
        starLayers: [],
        planets: [],
        explosions: [],
        scorePopups: [],
        keys: { left: false, right: false, fire: false },
        lastTime: 0,
        elapsed: 0
    };

    // Galaga calls the player projectiles "shots". Keep the legacy bullets
    // name as the writable backing store while exposing the arcade term as a
    // live view, so both input and deterministic probes observe one array.
    Object.defineProperties(state, {
        shots: {
            enumerable: true,
            get: function () { return state.bullets; },
            set: function (value) { state.bullets = value; }
        },
        enemyShots: {
            enumerable: true,
            get: function () { return state.enemyBullets; },
            set: function (value) { state.enemyBullets = value; }
        },
        bosses: {
            enumerable: true,
            get: function () {
                return state.enemies.filter(function (enemy) { return enemy.caste === 'boss'; });
            }
        },
        dualFighter: {
            enumerable: true,
            get: function () { return state.player.dualFighter; },
            set: function (active) {
                state.player.dualFighter = Boolean(active);
                state.player.width = state.player.dualFighter ? DUAL_FIGHTER_WIDTH : SINGLE_FIGHTER_WIDTH;
                state.player.x = clamp(state.player.x, 0, canvasWidth() - state.player.width);
            }
        },
        capturedFighter: {
            enumerable: true,
            get: function () { return state.player.capturedFighter; },
            set: function (fighter) { state.player.capturedFighter = fighter || null; }
        }
    });

    // --- helpers ---------------------------------------------------------------
    function clamp(value, low, high) {
        return Math.max(low, Math.min(high, value));
    }

    function cubicBezier(start, controlA, controlB, end, progress) {
        var inverse = 1 - progress;
        return inverse * inverse * inverse * start
            + 3 * inverse * inverse * progress * controlA
            + 3 * inverse * progress * progress * controlB
            + progress * progress * progress * end;
    }

    function cubicBezierTangent(start, controlA, controlB, end, progress) {
        var inverse = 1 - progress;
        return 3 * inverse * inverse * (controlA - start)
            + 6 * inverse * progress * (controlB - controlA)
            + 3 * progress * progress * (end - controlB);
    }

    // Normal play keeps the browser's random source. The test surface swaps in
    // a seeded source only after a hook is called, so unused hooks cannot alter
    // game behaviour.
    var testControl = { active: false, seed: 0x51a7f00d, frames: 0 };

    function random() {
        if (!testControl.active) return Math.random();
        var x = testControl.seed >>> 0;
        x ^= x << 13;
        x ^= x >>> 17;
        x ^= x << 5;
        testControl.seed = x >>> 0;
        return testControl.seed / 4294967296;
    }

    function randBetween(low, high) {
        return low + random() * (high - low);
    }

    function canvasWidth() {
        return lane.canvas ? lane.canvas.width : 800;
    }

    function canvasHeight() {
        return lane.canvas ? lane.canvas.height : 600;
    }

    // --- setup -----------------------------------------------------------------
    var STARFIELD_DEPTHS = [
        { name: 'far', count: 46, speed: 3, size: 0.8, alpha: 0.38 },
        { name: 'middle', count: 30, speed: 10, size: 1.25, alpha: 0.62 },
        { name: 'near', count: 18, speed: 24, size: 1.9, alpha: 0.9 }
    ];

    function createStarfieldLayers() {
        return STARFIELD_DEPTHS.map(function (depth, layerIndex) {
            var stars = [];
            for (var i = 0; i < depth.count; i += 1) {
                stars.push({
                    x: random() * canvasWidth(),
                    y: random() * canvasHeight(),
                    phase: random() * Math.PI * 2,
                    twinkle: randBetween(1.2, 3.2)
                });
            }
            // Keep a pair of nearby guide stars in open space. The rest of the
            // field remains organic, while these make its depth readable from
            // the first frame instead of occasionally leaving a dark void.
            if (depth.name === 'near') {
                stars.push({ x: 48, y: 362, phase: 0, twinkle: 1.7 });
                stars.push({ x: 132, y: 438, phase: 2.1, twinkle: 2.3 });
            }
            return {
                name: depth.name,
                speed: depth.speed,
                size: depth.size,
                alpha: depth.alpha,
                index: layerIndex,
                stars: stars
            };
        });
    }

    function createPlanets() {
        return [{ x: state.nursery.x, y: state.nursery.y, radius: state.nursery.radius, ring: true }];
    }

    // The colony hatches at the nursery planet, then fans out into three depth
    // bands. Scale changes both the drawn body and its collision footprint.
    function createFormation(wave) {
        var enemies = [];
        // Challenging stages contain the arcade forty; standard stages may
        // grow as difficulty rises.
        var rows = state.challenging ? 5 : Math.min(ENEMY_ROWS + Math.floor((wave - 1) / 2), 6);
        var cols = state.challenging ? 8 : Math.min(ENEMY_COLS + (wave > 1 ? 1 : 0), 10);
        var totalWidth = cols * ENEMY_WIDTH + (cols - 1) * ENEMY_GAP_X;
        var startX = (canvasWidth() - totalWidth) / 2;
        var scales = [0.72, 1, 1.28];
        for (var row = 0; row < rows; row += 1) {
            for (var col = 0; col < cols; col += 1) {
                var scale = scales[(row + col) % scales.length];
                // Preserve the four swarm roles while adding Galaga's arcade
                // caste hierarchy. Four bosses occupy the centre of the top
                // row; butterflies screen them and bees fill the lower ranks.
                var enemyClass = row === 0 ? 'commander' : (row === 1 ? 'fighter' : (row === 2 ? 'scout' : 'harvester'));
                var bossStart = Math.floor(cols / 2) - 2;
                var caste = row === 0 && col >= bossStart && col < bossStart + 4
                    ? 'boss'
                    : (row < 2 || (row === 2 && col < 2) ? 'butterfly' : 'bee');
                var sizeMultiplier = caste === 'boss' ? 1.22 : 1;
                var enemyIndex = row * cols + col;
                var originAngle = enemyIndex * 2.39996;
                var originRadius = 8 + (enemyIndex % 3) * 6;
                var nurseryX = state.nursery.x + Math.cos(originAngle) * originRadius;
                var nurseryY = state.nursery.y + Math.sin(originAngle) * originRadius;
                var slotX = startX + col * (ENEMY_WIDTH + ENEMY_GAP_X);
                var slotY = 60 + row * (ENEMY_HEIGHT + ENEMY_GAP_Y);
                var entrySide = ['top', 'left', 'right'][enemyIndex % 3];
                var entryStartX = entrySide === 'left' ? -70
                    : (entrySide === 'right' ? canvasWidth() + 70 : 80 + (enemyIndex * 83) % (canvasWidth() - 160));
                var entryStartY = entrySide === 'top' ? -60 : 35 + (enemyIndex * 47) % 250;
                var entryControlAX = entrySide === 'top' ? entryStartX + (col % 2 === 0 ? -150 : 150)
                    : (entrySide === 'left' ? canvasWidth() * 0.3 : canvasWidth() * 0.7);
                var entryControlAY = entrySide === 'top' ? 95
                    : entryStartY + (row % 2 === 0 ? 150 : -110);
                var entryControlBX = slotX + (entrySide === 'left' ? -170 : (entrySide === 'right' ? 170 : (col % 2 === 0 ? 120 : -120)));
                var entryControlBY = slotY - (entrySide === 'top' ? 90 : 125);
                enemies.push({
                    // The observable hatch point remains the nursery used by
                    // the colony contract. On its first update, each actor
                    // begins the visible Galaga entrance at a canvas edge.
                    x: nurseryX,
                    y: nurseryY,
                    originX: nurseryX,
                    originY: nurseryY,
                    entrySide: entrySide,
                    entryStartX: entryStartX,
                    entryStartY: entryStartY,
                    entryControlAX: entryControlAX,
                    entryControlAY: entryControlAY,
                    entryControlBX: entryControlBX,
                    entryControlBY: entryControlBY,
                    rotation: 0,
                    baseX: slotX,
                    baseY: slotY,
                    width: ENEMY_WIDTH * scale * sizeMultiplier,
                    height: ENEMY_HEIGHT * scale * sizeMultiplier,
                    scale: scale,
                    spriteScale: sizeMultiplier,
                    class: enemyClass,
                    caste: caste,
                    role: 'threat',
                    row: row,
                    col: col,
                    type: enemyClass,
                    alive: true,
                    diving: false,
                    entering: true,
                    entranceDelay: (row * cols + col) * 0.045,
                    entranceTime: 0,
                    flash: 0,
                    diveTime: 0,
                    diveOriginX: 0,
                    vx: 0,
                    vy: 0,
                    swarmVx: 0,
                    swarmVy: 0,
                    dispersalX: 0,
                    dispersalY: 0
                });
            }
        }
        state.formation.direction = 1;
        state.formation.speed = 40 + (wave - 1) * 14;
        state.formation.elapsed = 0;
        state.formation.breathScale = 1;
        state.formation.headingX = state.formation.speed;
        state.formation.headingY = 0;
        state.formation.diveCooldown = Math.max(1.1, 2.6 - wave * 0.2);
        return enemies;
    }

    function applyMood(index) {
        var rule = MOOD_RULES[index % MOOD_RULES.length];
        state.mood = rule.name;
        // Later waves think faster while preserving each mood's own cadence.
        state.moodCadence = Math.max(0.8, rule.cadence - (state.wave - 1) * 0.12);
        state.moodElapsed = 0;
        return rule;
    }

    function currentMoodRule() {
        for (var i = 0; i < MOOD_RULES.length; i += 1) {
            if (MOOD_RULES[i].name === state.mood) return MOOD_RULES[i];
        }
        return MOOD_RULES[0];
    }

    function startWave(wave) {
        state.wave = wave;
        state.stage = wave;
        // Original Galaga's first challenging stage is stage 3, recurring
        // every fourth stage thereafter (3, 7, 11, ...).
        state.challenging = wave >= 3 && (wave - 3) % 4 === 0;
        state.stageKind = state.challenging ? 'challenging' : 'standard';
        state.challengingDestroyed = 0;
        state.perfectBonus = 0;
        applyMood((wave - 1) % MOOD_RULES.length);
        state.enemies = createFormation(wave);
        state.enemyBullets = [];
        updateHud();
    }

    function addScore(points) {
        state.score += points;
        state.highScore = Math.max(state.highScore, state.score);
        while (state.score >= state.extraShipAt) {
            state.lives += 1;
            state.extraShipAt += EXTRA_SHIP_INTERVAL;
        }
        updateHud();
    }

    function resetPlayer() {
        state.player.x = canvasWidth() / 2 - state.player.width / 2;
        state.player.y = canvasHeight() - 60;
        state.player.invulnerable = 1.4;
        state.player.cooldown = 0;
        state.player.bank = 0;
    }

    function restartGame() {
        state.gameOver = false;
        state.paused = false;
        state.score = 0;
        state.lives = 3;
        state.extraShipAt = FIRST_EXTRA_SHIP_SCORE;
        state.dualFighter = false;
        state.capturedFighter = null;
        state.bullets = [];
        state.enemyBullets = [];
        startWave(1);
        resetPlayer();
        state.running = true;
        hideOverlay();
        updateHud();
        focusCanvas();
    }

    function startGame() {
        if (state.running && !state.gameOver) {
            return;
        }
        restartGame();
    }

    function pauseGame() {
        if (!state.running || state.gameOver) {
            return;
        }
        state.paused = true;
        showOverlay('Paused', 'Press P to resume', 'Resume');
    }

    function resumeGame() {
        if (!state.running || state.gameOver || !state.paused) {
            return;
        }
        state.paused = false;
        hideOverlay();
        focusCanvas();
    }

    function togglePause() {
        if (state.paused) {
            resumeGame();
        } else {
            pauseGame();
        }
    }

    function gameOver() {
        state.gameOver = true;
        state.running = false;
        showOverlay('Game Over', 'Final score: ' + state.score, 'Restart');
    }

    // --- input -----------------------------------------------------------------
    function fireBullet() {
        if (!state.running || state.paused || state.gameOver) {
            return;
        }
        if (state.player.cooldown > 0 || state.shots.length >= PLAYER_SHOT_LIMIT) {
            return;
        }
        state.player.cooldown = PLAYER_FIRE_COOLDOWN;
        var muzzles = state.dualFighter
            ? [SINGLE_FIGHTER_WIDTH / 2, DUAL_FIGHTER_OFFSET + SINGLE_FIGHTER_WIDTH / 2]
            : [state.player.width / 2];
        for (var i = 0; i < muzzles.length && state.shots.length < PLAYER_SHOT_LIMIT; i += 1) {
            state.bullets.push({
                x: state.player.x + muzzles[i] - 2,
                y: state.player.y - 10,
                width: 4,
                height: 14,
                role: 'ally',
                speed: BULLET_SPEED
            });
        }
    }

    function setMove(direction, pressed) {
        if (direction === 'left') {
            state.keys.left = pressed;
        } else if (direction === 'right') {
            state.keys.right = pressed;
        } else if (direction === 'fire') {
            state.keys.fire = pressed;
            if (pressed) {
                fireBullet();
            }
        }
    }

    function focusCanvas() {
        if (lane.canvas && typeof lane.canvas.focus === 'function') {
            try {
                lane.canvas.focus({ preventScroll: true });
            } catch (error) {
                lane.canvas.focus();
            }
        }
    }

    function onKeyDown(event) {
        var key = event.key;
        if (key === 'ArrowLeft' || key === 'a' || key === 'A') {
            setMove('left', true);
            event.preventDefault();
        } else if (key === 'ArrowRight' || key === 'd' || key === 'D') {
            setMove('right', true);
            event.preventDefault();
        } else if (key === ' ' || key === 'Spacebar' || key === 'ArrowUp' || key === 'w' || key === 'W') {
            setMove('fire', true);
            event.preventDefault();
        } else if (key === 'p' || key === 'P') {
            togglePause();
            event.preventDefault();
        } else if (key === 'Enter' && (state.gameOver || !state.running)) {
            startGame();
            event.preventDefault();
        }
    }

    function onKeyUp(event) {
        var key = event.key;
        if (key === 'ArrowLeft' || key === 'a' || key === 'A') {
            setMove('left', false);
        } else if (key === 'ArrowRight' || key === 'd' || key === 'D') {
            setMove('right', false);
        } else if (key === ' ' || key === 'Spacebar' || key === 'ArrowUp' || key === 'w' || key === 'W') {
            setMove('fire', false);
        }
    }

    function bindTouchControls() {
        var buttons = lane.root.querySelectorAll('[data-star-swarm-control]');
        for (var i = 0; i < buttons.length; i += 1) {
            (function (button) {
                var control = button.getAttribute('data-star-swarm-control');
                var release = function (event) {
                    if (event) {
                        event.preventDefault();
                    }
                    setMove(control, false);
                };
                button.addEventListener('pointerdown', function (event) {
                    event.preventDefault();
                    setMove(control, true);
                });
                button.addEventListener('pointerup', release);
                button.addEventListener('pointerleave', release);
                button.addEventListener('pointercancel', release);
            })(buttons[i]);
        }
    }

    // --- update ----------------------------------------------------------------
    function updatePlayer(dt) {
        var player = state.player;
        var bankTarget = 0;
        if (state.keys.left) {
            player.x -= PLAYER_SPEED * dt;
            bankTarget = -1;
        }
        if (state.keys.right) {
            player.x += PLAYER_SPEED * dt;
            bankTarget = 1;
        }
        player.bank += (bankTarget - player.bank) * Math.min(1, dt * 10);
        player.x = clamp(player.x, 0, canvasWidth() - player.width);
        if (player.cooldown > 0) {
            player.cooldown = Math.max(0, player.cooldown - dt);
        }
        if (player.invulnerable > 0) {
            player.invulnerable = Math.max(0, player.invulnerable - dt);
        }
        if (state.keys.fire) {
            fireBullet();
        }
    }

    function updateStarfield(dt) {
        for (var l = 0; l < state.starLayers.length; l += 1) {
            var layer = state.starLayers[l];
            for (var i = 0; i < layer.stars.length; i += 1) {
                var star = layer.stars[i];
                star.y += layer.speed * dt;
                if (star.y > canvasHeight() + 2) {
                    star.y = -2;
                    star.x = random() * canvasWidth();
                }
            }
        }
    }

    function separateColony() {
        var bodies = state.enemies.filter(function (enemy) {
            return enemy.alive && !enemy.entering && !enemy.diving;
        });
        var padding = 2;
        // A final positional constraint makes separation an invariant, rather
        // than an emergent hope. Repeated relaxation resolves row-scale chains
        // where pushing one large body can otherwise crowd its next neighbour.
        for (var pass = 0; pass < 8; pass += 1) {
            for (var i = 0; i < bodies.length; i += 1) {
                for (var j = i + 1; j < bodies.length; j += 1) {
                    var a = bodies[i];
                    var b = bodies[j];
                    var dx = (b.x + b.width / 2) - (a.x + a.width / 2);
                    var dy = (b.y + b.height / 2) - (a.y + a.height / 2);
                    var overlapX = (a.width + b.width) / 2 + padding - Math.abs(dx);
                    var overlapY = (a.height + b.height) / 2 + padding - Math.abs(dy);
                    if (overlapX <= 0 || overlapY <= 0) continue;
                    if (overlapX < overlapY) {
                        var pushX = (dx < 0 ? -1 : 1) * overlapX / 2;
                        a.x -= pushX;
                        b.x += pushX;
                        a.swarmVx -= pushX * 3;
                        b.swarmVx += pushX * 3;
                    } else {
                        var pushY = (dy < 0 ? -1 : 1) * overlapY / 2;
                        a.y -= pushY;
                        b.y += pushY;
                        a.swarmVy -= pushY * 3;
                        b.swarmVy += pushY * 3;
                    }
                }
            }
        }
    }

    function disperseNeighbours(struck) {
        var cx = struck.x + struck.width / 2;
        var cy = struck.y + struck.height / 2;
        for (var i = 0; i < state.enemies.length; i += 1) {
            var neighbour = state.enemies[i];
            if (!neighbour.alive || neighbour === struck || neighbour.diving) continue;
            var dx = neighbour.x + neighbour.width / 2 - cx;
            var dy = neighbour.y + neighbour.height / 2 - cy;
            var distance = Math.max(1, Math.sqrt(dx * dx + dy * dy));
            if (distance > 150) continue;
            var force = 1 - distance / 150;
            var nx = dx / distance;
            var ny = dy / distance;
            // The offset leaves a visible wound in the colony while velocity
            // provides the immediate shock wave. Cohesion gradually reforms it.
            neighbour.dispersalX += nx * force * 24;
            neighbour.dispersalY += ny * force * 24;
            neighbour.swarmVx += nx * force * 240;
            neighbour.swarmVy += ny * force * 240;
        }
    }

    function enemyPointValue(enemy) {
        if (state.challenging) return 100;
        if (enemy.caste === 'bee') return enemy.diving ? ENEMY_SCORES.bee.flight : ENEMY_SCORES.bee.formation;
        if (enemy.caste === 'butterfly') return enemy.diving ? ENEMY_SCORES.butterfly.flight : ENEMY_SCORES.butterfly.formation;
        if (enemy.caste === 'boss') {
            if (!enemy.diving) return ENEMY_SCORES.boss.formation;
            var escorts = clamp(Number(enemy.escortCount) || 0, 0, 2);
            return ENEMY_SCORES.boss.flight[escorts];
        }
        return 100;
    }

    function destroyEnemy(enemy) {
        if (!enemy || !enemy.alive) return false;
        var points = enemyPointValue(enemy);
        enemy.flash = 0.12;
        enemy.alive = false;
        disperseNeighbours(enemy);
        createExplosion(enemy.x + enemy.width / 2, enemy.y + enemy.height / 2, enemy.type);
        state.scorePopups.push({ x: enemy.x + enemy.width / 2, y: enemy.y, text: '+' + points, role: 'reward', life: 0.8 });
        addScore(points);
        if (state.challenging) {
            state.challengingDestroyed += 1;
            if (state.challengingDestroyed === 40) {
                state.perfectBonus = 10000;
                addScore(state.perfectBonus);
            }
        }
        return true;
    }

    function updateFormation(dt) {
        state.formation.elapsed += dt;
        state.moodElapsed += dt;
        if (state.moodElapsed >= state.moodCadence) {
            var moodIndex = MOOD_RULES.map(function (rule) { return rule.name; }).indexOf(state.mood);
            applyMood((moodIndex + 1) % MOOD_RULES.length);
        }
        var mood = currentMoodRule();
        var sway = Math.sin(state.formation.elapsed * mood.swayRate) * mood.swayWidth;
        var breath = Math.sin(state.formation.elapsed * mood.swayRate * 1.7);
        // Scale each settled slot away from or toward the formation centre.
        // This makes the assembled grid expand and contract as one body rather
        // than merely bobbing alternating enemies up and down.
        state.formation.breathScale = 1 + breath * mood.breathe / 100;
        var drift = state.formation.speed * dt * state.formation.direction;
        // Two incommensurate arcs make the colony centroid wander through the
        // corridor instead of tracing a straight rail. Individual steering is
        // layered on this shared heading below.
        var colonyDriftX = Math.sin(state.formation.elapsed * 0.43) * 22 + Math.sin(state.formation.elapsed * 0.19 + 1.1) * 10;
        var colonyDriftY = Math.sin(state.formation.elapsed * 0.67 + 0.35) * 11;
        var flock = state.enemies.filter(function (enemy) {
            return enemy.alive && !enemy.entering && !enemy.diving;
        });
        var averageVx = 0;
        var averageVy = 0;
        var formationCenterX = 0;
        var formationCenterY = 0;
        for (var f = 0; f < flock.length; f += 1) {
            averageVx += flock[f].swarmVx;
            averageVy += flock[f].swarmVy;
            formationCenterX += flock[f].baseX + flock[f].width / 2;
            formationCenterY += flock[f].baseY + flock[f].height / 2;
        }
        if (flock.length > 0) {
            averageVx /= flock.length;
            averageVy /= flock.length;
            formationCenterX /= flock.length;
            formationCenterY /= flock.length;
        }
        state.formation.headingX = averageVx;
        state.formation.headingY = averageVy;
        for (var i = 0; i < state.enemies.length; i += 1) {
            var enemy = state.enemies[i];
            if (!enemy.alive) {
                continue;
            }
            enemy.flash = Math.max(0, enemy.flash - dt);
            if (enemy.entering) {
                enemy.entranceTime += dt;
                var entrance = clamp((enemy.entranceTime - enemy.entranceDelay) / 1.15, 0, 1);
                var targetX = enemy.baseX + sway;
                enemy.x = cubicBezier(
                    enemy.entryStartX, enemy.entryControlAX, enemy.entryControlBX, targetX, entrance
                );
                enemy.y = cubicBezier(
                    enemy.entryStartY, enemy.entryControlAY, enemy.entryControlBY, enemy.baseY, entrance
                );
                var tangentX = cubicBezierTangent(
                    enemy.entryStartX, enemy.entryControlAX, enemy.entryControlBX, targetX, entrance
                );
                var tangentY = cubicBezierTangent(
                    enemy.entryStartY, enemy.entryControlAY, enemy.entryControlBY, enemy.baseY, entrance
                );
                // Five complete turns layer the arcade spin over the curve's
                // travel heading, then stop squarely in the assembled grid.
                enemy.rotation = entrance < 1
                    ? Math.atan2(tangentY, tangentX) + entrance * Math.PI * 10
                    : 0;
                enemy.entering = entrance < 1;
                continue;
            }
            if (enemy.diving) {
                enemy.diveTime += dt;
                enemy.y += enemy.vy * dt;
                enemy.x = enemy.diveOriginX + Math.sin(enemy.diveTime * 3.2) * (80 + enemy.diveTime * 22) + enemy.vx * enemy.diveTime;
                if (enemy.y > canvasHeight() + 40) {
                    enemy.diving = false;
                    enemy.diveTime = 0;
                    enemy.x = enemy.baseX + sway;
                    enemy.y = enemy.baseY;
                }
                continue;
            }
            enemy.baseX += drift;
            // Breathing scales the grid around its centre; probing also leans
            // toward one flank, preserving distinct readable mood behaviours.
            var flank = (enemy.col / Math.max(1, ENEMY_COLS - 1)) * 2 - 1;
            var breathOffsetX = (enemy.baseX + enemy.width / 2 - formationCenterX)
                * (state.formation.breathScale - 1);
            var breathOffsetY = (enemy.baseY + enemy.height / 2 - formationCenterY)
                * (state.formation.breathScale - 1);
            var targetX = enemy.baseX + breathOffsetX + sway + colonyDriftX
                + flank * mood.lean * breath + enemy.dispersalX;
            var targetY = enemy.baseY + breathOffsetY + colonyDriftY + enemy.dispersalY;
            // Cohesion pulls each body toward its place in the living colony;
            // alignment shares the flock's heading without erasing hit impulses.
            var accelerationX = (targetX - enemy.x) * 13 + (averageVx - enemy.swarmVx) * 0.55;
            var accelerationY = (targetY - enemy.y) * 13 + (averageVy - enemy.swarmVy) * 0.55;
            for (var n = 0; n < flock.length; n += 1) {
                var other = flock[n];
                if (other === enemy) continue;
                var apartX = enemy.x + enemy.width / 2 - (other.x + other.width / 2);
                var apartY = enemy.y + enemy.height / 2 - (other.y + other.height / 2);
                var apart = Math.max(1, Math.sqrt(apartX * apartX + apartY * apartY));
                var safe = Math.max(enemy.width, enemy.height, other.width, other.height) + 10;
                if (apart < safe) {
                    var repel = (safe - apart) * 18;
                    accelerationX += apartX / apart * repel;
                    accelerationY += apartY / apart * repel;
                }
            }
            enemy.swarmVx = (enemy.swarmVx + accelerationX * dt) * Math.pow(0.32, dt);
            enemy.swarmVy = (enemy.swarmVy + accelerationY * dt) * Math.pow(0.32, dt);
            enemy.x += enemy.swarmVx * dt;
            enemy.y += enemy.swarmVy * dt;
            enemy.dispersalX *= Math.pow(0.82, dt);
            enemy.dispersalY *= Math.pow(0.82, dt);
        }

        separateColony();

        // Bounce the formation when any living enemy reaches an edge.
        var minX = Infinity;
        var maxX = -Infinity;
        for (var j = 0; j < state.enemies.length; j += 1) {
            var probe = state.enemies[j];
            if (!probe.alive || probe.diving) {
                continue;
            }
            minX = Math.min(minX, probe.x);
            maxX = Math.max(maxX, probe.x + probe.width);
        }
        if (minX !== Infinity && (minX < 0 || maxX > canvasWidth())) {
            state.formation.direction *= -1;
            for (var k = 0; k < state.enemies.length; k += 1) {
                var bounce = state.enemies[k];
                if (bounce.alive && !bounce.diving) {
                    bounce.baseX += state.formation.direction * 6;
                }
            }
        }

        // Dive: peel one living enemy toward the player.
        state.formation.diveCooldown -= dt;
        if (state.formation.diveCooldown <= 0) {
            var living = state.enemies.filter(function (enemy) { return enemy.alive && !enemy.diving && !enemy.entering; });
            if (living.length > 0) {
                var chosen = living[Math.floor(random() * living.length)];
                chosen.diving = true;
                chosen.diveTime = 0;
                chosen.diveOriginX = chosen.x;
                var dx = (state.player.x + state.player.width / 2) - (chosen.x + chosen.width / 2);
                var dy = state.player.y - chosen.y;
                var length = Math.max(1, Math.sqrt(dx * dx + dy * dy));
                var diveSpeed = 150 + state.wave * 18;
                chosen.vx = (dx / length) * diveSpeed;
                chosen.vy = Math.abs((dy / length) * diveSpeed);
            }
            state.formation.diveCooldown = Math.max(0.9, 2.4 - state.wave * 0.18);
        }
    }

    function updateEnemyFire(dt) {
        if (state.challenging) return;
        var living = state.enemies.filter(function (enemy) { return enemy.alive; });
        if (living.length === 0) {
            return;
        }
        if (random() < 0.35 * dt * (1 + state.wave * 0.15)) {
            var shooter = living[Math.floor(random() * living.length)];
            state.enemyBullets.push({
                x: shooter.x + shooter.width / 2 - 2,
                y: shooter.y + shooter.height,
                width: 4,
                height: 12,
                role: 'hazard',
                speed: ENEMY_BULLET_SPEED + state.wave * 12
            });
        }
    }

    function intersects(a, b) {
        return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
    }

    function updateBullets(dt) {
        var i;
        for (i = state.bullets.length - 1; i >= 0; i -= 1) {
            var bullet = state.bullets[i];
            bullet.y -= bullet.speed * dt;
            if (bullet.y + bullet.height < 0) {
                state.bullets.splice(i, 1);
                continue;
            }
            for (var e = 0; e < state.enemies.length; e += 1) {
                var enemy = state.enemies[e];
                if (!enemy.alive) {
                    continue;
                }
                if (intersects(bullet, enemy)) {
                    state.bullets.splice(i, 1);
                    destroyEnemy(enemy);
                    break;
                }
            }
        }

        for (i = state.enemyBullets.length - 1; i >= 0; i -= 1) {
            var shot = state.enemyBullets[i];
            shot.y += shot.speed * dt;
            if (shot.y > canvasHeight()) {
                state.enemyBullets.splice(i, 1);
                continue;
            }
            if (state.player.invulnerable <= 0 && intersects(shot, state.player)) {
                state.enemyBullets.splice(i, 1);
                loseLife();
            }
        }

        // Diving enemies that reach the player also cost a life.
        if (state.player.invulnerable <= 0) {
            for (var d = 0; d < state.enemies.length; d += 1) {
                var diver = state.enemies[d];
                if (diver.alive && diver.diving && intersects(diver, state.player)) {
                    diver.alive = false;
                    diver.diving = false;
                    loseLife();
                    break;
                }
            }
        }
    }

    function loseLife() {
        state.lives -= 1;
        updateHud();
        if (state.lives <= 0) {
            gameOver();
            return;
        }
        resetPlayer();
    }

    function createExplosion(x, y, type) {
        var debris = [];
        var count = type === 'commander' ? 14 : 9;
        for (var i = 0; i < count; i += 1) {
            var angle = Math.PI * 2 * i / count + random() * 0.25;
            var speed = randBetween(35, 115);
            debris.push({ x: x, y: y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, size: randBetween(1.5, 4) });
        }
        state.explosions.push({ x: x, y: y, age: 0, life: 0.65, debris: debris });
    }

    function updateEffects(dt) {
        var i;
        for (i = state.explosions.length - 1; i >= 0; i -= 1) {
            var explosion = state.explosions[i];
            explosion.age += dt;
            for (var d = 0; d < explosion.debris.length; d += 1) {
                explosion.debris[d].x += explosion.debris[d].vx * dt;
                explosion.debris[d].y += explosion.debris[d].vy * dt;
            }
            if (explosion.age >= explosion.life) state.explosions.splice(i, 1);
        }
        for (i = state.scorePopups.length - 1; i >= 0; i -= 1) {
            state.scorePopups[i].life -= dt;
            state.scorePopups[i].y -= 26 * dt;
            if (state.scorePopups[i].life <= 0) state.scorePopups.splice(i, 1);
        }
    }

    function checkWaveCleared() {
        if (state.gameOver) {
            return;
        }
        var remaining = state.enemies.some(function (enemy) { return enemy.alive; });
        if (!remaining) {
            startWave(state.wave + 1);
        }
    }

    function update(dt) {
        if (!state.running || state.paused || state.gameOver) {
            return;
        }
        state.elapsed += dt;
        updateStarfield(dt);
        updatePlayer(dt);
        updateFormation(dt);
        updateEnemyFire(dt);
        updateBullets(dt);
        updateEffects(dt);
        checkWaveCleared();
    }

    // --- render ----------------------------------------------------------------
    function drawCosmicTheatre(ctx, theme) {
        var backdrop = ctx.createLinearGradient(0, 0, 0, canvasHeight());
        backdrop.addColorStop(0, theme.space);
        backdrop.addColorStop(0.55, theme.spaceDeep);
        backdrop.addColorStop(1, theme.space);
        ctx.fillStyle = backdrop;
        ctx.fillRect(0, 0, canvasWidth(), canvasHeight());

        var dust = ctx.createRadialGradient(180, 290, 10, 180, 290, 260);
        dust.addColorStop(0, theme.nebula);
        dust.addColorStop(1, theme.space);
        ctx.save();
        ctx.globalAlpha = 0.16;
        ctx.fillStyle = dust;
        ctx.fillRect(0, 0, 480, 570);
        ctx.restore();

        for (var l = 0; l < state.starLayers.length; l += 1) {
            var layer = state.starLayers[l];
            for (var i = 0; i < layer.stars.length; i += 1) {
                var star = layer.stars[i];
                var twinkle = 0.55 + Math.sin(state.elapsed * star.twinkle + star.phase) * 0.35;
                ctx.globalAlpha = layer.alpha * twinkle;
                ctx.fillStyle = layer.index === 2 ? theme.starBright : theme.starDim;
                ctx.beginPath();
                ctx.arc(star.x, star.y, layer.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        ctx.globalAlpha = 1;

        for (var p = 0; p < state.planets.length; p += 1) {
            var planet = state.planets[p];
            var sphere = ctx.createRadialGradient(planet.x - planet.radius * 0.4, planet.y - planet.radius * 0.35, 4, planet.x, planet.y, planet.radius);
            sphere.addColorStop(0, theme.planetLit);
            sphere.addColorStop(0.46, theme.planet);
            sphere.addColorStop(1, theme.space);
            ctx.fillStyle = sphere;
            ctx.beginPath();
            ctx.arc(planet.x, planet.y, planet.radius, 0, Math.PI * 2);
            ctx.fill();
            if (planet.ring) {
                ctx.save();
                ctx.translate(planet.x, planet.y);
                ctx.rotate(-0.22);
                ctx.strokeStyle = theme.ring;
                ctx.globalAlpha = 0.42;
                ctx.lineWidth = 6;
                ctx.beginPath();
                ctx.ellipse(0, 0, planet.radius * 1.5, planet.radius * 0.32, 0, 0, Math.PI * 2);
                ctx.stroke();
                ctx.restore();
            }
        }
    }

    function drawEnemy(ctx, enemy, theme) {
        var scale = (enemy.scale || 1) * (enemy.spriteScale || 1);
        ctx.save();
        ctx.translate(enemy.x, enemy.y);
        ctx.scale(scale, scale);
        if (enemy.entering) ctx.rotate(enemy.rotation || 0);
        else if (enemy.diving) ctx.rotate(Math.sin(enemy.diveTime * 5) * 0.08);

        if (enemy.caste) {
            // Galaga silhouettes remain readable without raster art: yellow /
            // blue bees, red / white butterflies, and a broad two-horned boss.
            ctx.fillStyle = enemy.flash > 0 ? theme.text
                : (enemy.caste === 'bee' ? theme.accent : (enemy.caste === 'butterfly' ? theme.tertiary : theme.roles.threat));
            ctx.beginPath();
            if (enemy.caste === 'bee') {
                ctx.moveTo(17, 2); ctx.lineTo(24, 9); ctx.lineTo(32, 8); ctx.lineTo(27, 16);
                ctx.lineTo(30, 24); ctx.lineTo(20, 21); ctx.lineTo(17, 27); ctx.lineTo(14, 21);
                ctx.lineTo(4, 24); ctx.lineTo(7, 16); ctx.lineTo(2, 8); ctx.lineTo(10, 9);
            } else if (enemy.caste === 'butterfly') {
                ctx.moveTo(17, 3); ctx.lineTo(23, 9); ctx.lineTo(34, 4); ctx.lineTo(30, 16);
                ctx.lineTo(34, 24); ctx.lineTo(22, 20); ctx.lineTo(17, 27); ctx.lineTo(12, 20);
                ctx.lineTo(0, 24); ctx.lineTo(4, 16); ctx.lineTo(0, 4); ctx.lineTo(11, 9);
            } else {
                ctx.moveTo(3, 0); ctx.lineTo(13, 7); ctx.lineTo(17, 3); ctx.lineTo(21, 7);
                ctx.lineTo(31, 0); ctx.lineTo(34, 13); ctx.lineTo(27, 25); ctx.lineTo(20, 21);
                ctx.lineTo(17, 29); ctx.lineTo(14, 21); ctx.lineTo(7, 25); ctx.lineTo(0, 13);
            }
            ctx.closePath(); ctx.fill();
            ctx.fillStyle = enemy.caste === 'bee' ? theme.primaryDark : theme.text;
            ctx.fillRect(10, 11, 5, 5); ctx.fillRect(20, 11, 5, 5);
            ctx.restore();
            return;
        }

        // Legacy role silhouettes remain available to the visual contract's
        // isolated renderer probes; live formations use the Galaga castes.
        ctx.fillStyle = enemy.flash > 0 ? theme.text : theme.roles[enemy.role];
        ctx.beginPath();
        if (enemy.type === 'commander') {
            ctx.moveTo(17, 0); ctx.lineTo(31, 8); ctx.lineTo(34, 23);
            ctx.lineTo(23, 18); ctx.lineTo(17, 26); ctx.lineTo(11, 18); ctx.lineTo(0, 23); ctx.lineTo(3, 8);
        } else if (enemy.type === 'fighter') {
            ctx.moveTo(17, 2); ctx.lineTo(34, 13); ctx.lineTo(28, 25);
            ctx.lineTo(20, 18); ctx.lineTo(14, 18); ctx.lineTo(6, 25); ctx.lineTo(0, 13);
        } else if (enemy.type === 'scout') {
            // Scouts are narrow arrowheads: little ink and a vertical aspect.
            ctx.moveTo(17, 0); ctx.lineTo(25, 12); ctx.lineTo(21, 26);
            ctx.lineTo(17, 21); ctx.lineTo(13, 26); ctx.lineTo(9, 12);
        } else {
            // The harvester is a tall, sparse tuning-fork silhouette. Its
            // aspect and ink coverage remain distinct without relying on hue.
            ctx.moveTo(13, 0); ctx.lineTo(21, 0); ctx.lineTo(21, 12);
            ctx.lineTo(30, 5); ctx.lineTo(34, 11); ctx.lineTo(23, 21);
            ctx.lineTo(21, 34); ctx.lineTo(13, 34); ctx.lineTo(11, 21);
            ctx.lineTo(0, 11); ctx.lineTo(4, 5); ctx.lineTo(13, 12);
        }
        ctx.closePath(); ctx.fill();
        ctx.fillStyle = theme.accent;
        if (enemy.type === 'harvester') {
            ctx.fillRect(15, 15, 4, 7);
        } else if (enemy.type === 'scout') {
            ctx.fillRect(15, 9, 4, 5);
        } else {
            ctx.fillRect(10, 10, 4, 4); ctx.fillRect(20, 10, 4, 4);
        }
        ctx.restore();
    }

    function drawPlayerShot(ctx, bullet, theme) {
        ctx.save();
        ctx.shadowColor = theme.roles.reward; ctx.shadowBlur = 12;
        var trail = ctx.createLinearGradient(0, bullet.y, 0, bullet.y + bullet.height + 12);
        trail.addColorStop(0, theme.starBright); trail.addColorStop(0.35, theme.roles[bullet.role]); trail.addColorStop(1, theme.space);
        ctx.fillStyle = trail; ctx.fillRect(bullet.x - 2, bullet.y, bullet.width + 4, bullet.height + 12);
        ctx.restore();
    }

    function drawFighterHull(ctx, x, player, theme) {
        var cx = x + SINGLE_FIGHTER_WIDTH / 2;
        ctx.save();
        ctx.translate(cx, player.y + player.height / 2);
        ctx.rotate(player.bank * 0.16);
        ctx.translate(-cx, -(player.y + player.height / 2));
        var flame = 9 + Math.sin(state.elapsed * 37) * 4;
        ctx.fillStyle = theme.accent;
        ctx.beginPath(); ctx.moveTo(cx - 6, player.y + 23); ctx.lineTo(cx, player.y + 23 + flame); ctx.lineTo(cx + 6, player.y + 23); ctx.fill();
        ctx.fillStyle = theme.primaryDark;
        ctx.beginPath(); ctx.moveTo(x + 3, player.y + 26); ctx.lineTo(x + 15, player.y + 15); ctx.lineTo(x + 16, player.y + 28); ctx.fill();
        ctx.beginPath(); ctx.moveTo(x + 41, player.y + 26); ctx.lineTo(x + 29, player.y + 15); ctx.lineTo(x + 28, player.y + 28); ctx.fill();
        ctx.fillStyle = theme.roles[player.role];
        ctx.beginPath(); ctx.moveTo(cx, player.y); ctx.quadraticCurveTo(x + 34, player.y + 14, cx + 8, player.y + 27); ctx.lineTo(cx - 8, player.y + 27); ctx.quadraticCurveTo(x + 10, player.y + 14, cx, player.y); ctx.fill();
        ctx.fillStyle = theme.text;
        ctx.beginPath(); ctx.ellipse(cx, player.y + 10, 5, 7, 0, 0, Math.PI * 2); ctx.fill();
        ctx.restore();
    }

    function drawRocket(ctx, player, theme) {
        drawFighterHull(ctx, player.x, player, theme);
        if (state.dualFighter) {
            // The upgrade is a second complete docked fighter, not a stretched
            // version of the single hull.
            drawFighterHull(ctx, player.x + DUAL_FIGHTER_OFFSET, player, theme);
        }
    }

    function drawEffects(ctx, theme) {
        for (var i = 0; i < state.explosions.length; i += 1) {
            var explosion = state.explosions[i];
            var alpha = 1 - explosion.age / explosion.life;
            ctx.globalAlpha = alpha;
            ctx.strokeStyle = theme.accent; ctx.lineWidth = 3;
            ctx.beginPath(); ctx.arc(explosion.x, explosion.y, 6 + explosion.age * 38, 0, Math.PI * 2); ctx.stroke();
            ctx.fillStyle = theme.tertiary;
            for (var d = 0; d < explosion.debris.length; d += 1) ctx.fillRect(explosion.debris[d].x, explosion.debris[d].y, explosion.debris[d].size, explosion.debris[d].size);
        }
        ctx.font = 'bold 14px ' + theme.fontUi; ctx.textAlign = 'center';
        for (var p = 0; p < state.scorePopups.length; p += 1) {
            ctx.globalAlpha = Math.min(1, state.scorePopups[p].life * 2);
            ctx.fillStyle = theme.roles[state.scorePopups[p].role];
            ctx.fillText(state.scorePopups[p].text, state.scorePopups[p].x, state.scorePopups[p].y);
        }
        ctx.globalAlpha = 1;
    }

    function render() {
        var ctx = lane.ctx;
        var theme = lane.theme;
        if (!ctx) return;
        ctx.clearRect(0, 0, canvasWidth(), canvasHeight());
        drawCosmicTheatre(ctx, theme);

        var i;
        for (i = 0; i < state.enemies.length; i += 1) if (state.enemies[i].alive) drawEnemy(ctx, state.enemies[i], theme);
        for (i = 0; i < state.bullets.length; i += 1) drawPlayerShot(ctx, state.bullets[i], theme);
        for (i = 0; i < state.enemyBullets.length; i += 1) {
            var shot = state.enemyBullets[i];
            ctx.fillStyle = theme.roles[shot.role]; ctx.beginPath();
            ctx.moveTo(shot.x + 2, shot.y); ctx.lineTo(shot.x + 6, shot.y + 6); ctx.lineTo(shot.x + 2, shot.y + 12); ctx.lineTo(shot.x - 2, shot.y + 6); ctx.closePath(); ctx.fill();
        }
        drawEffects(ctx, theme);
        if (state.running && (state.player.invulnerable <= 0 || Math.floor(state.elapsed * 10) % 2 === 0)) drawRocket(ctx, state.player, theme);
    }

    function loop(timestamp) {
        if (lane.destroyed) {
            return;
        }
        if (!state.lastTime) {
            state.lastTime = timestamp;
        }
        var dt = (timestamp - state.lastTime) / 1000;
        state.lastTime = timestamp;
        // Clamp so a stalled tab cannot teleport the ship across the field.
        dt = clamp(dt, 0, 0.05);
        update(dt);
        render();
        lane.frame = requestAnimationFrame(loop);
    }

    // --- HUD / overlay ---------------------------------------------------------
    function updateHud() {
        if (lane.scoreEl) {
            lane.scoreEl.textContent = String(state.score);
        }
        if (lane.waveEl) {
            lane.waveEl.textContent = String(state.wave);
        }
        if (lane.livesEl) {
            lane.livesEl.textContent = String(Math.max(0, state.lives));
        }
    }

    function showOverlay(title, hint, action) {
        if (!lane.overlay) {
            return;
        }
        lane.overlay.hidden = false;
        if (lane.overlayTitle) {
            lane.overlayTitle.textContent = title;
        }
        if (lane.overlayHint) {
            lane.overlayHint.textContent = hint;
        }
        if (lane.actionButton) {
            lane.actionButton.textContent = action;
        }
    }

    function hideOverlay() {
        if (lane.overlay) {
            lane.overlay.hidden = true;
        }
    }

    function onAction() {
        if (state.gameOver) {
            restartGame();
        } else if (state.paused) {
            resumeGame();
        } else if (!state.running) {
            startGame();
        }
    }

    function destroy() {
        lane.destroyed = true;
        if (lane.frame) {
            cancelAnimationFrame(lane.frame);
            lane.frame = 0;
        }
        window.removeEventListener('keydown', onKeyDown);
        window.removeEventListener('keyup', onKeyUp);
        window.removeEventListener('pagehide', destroy);
    }

    // Calling any test hook takes ownership of the clock from requestAnimationFrame.
    // Hooks still use update(), render(), startWave(), and destroyEnemy(): this is a
    // deterministic driver for the real game path, not a second implementation.
    function enterTestMode() {
        if (testControl.active) return;
        testControl.active = true;
        testControl.seed = 0x51a7f00d;
        testControl.frames = 0;
        if (lane.frame) {
            cancelAnimationFrame(lane.frame);
            lane.frame = 0;
        }
        state.lastTime = 0;
    }

    function testStep(frames) {
        enterTestMode();
        if (!Number.isInteger(frames) || frames < 0) {
            throw new RangeError('StarSwarm.test.step(frames) requires a non-negative integer');
        }
        for (var i = 0; i < frames; i += 1) {
            update(1 / 60);
            render();
            testControl.frames += 1;
        }
        return testControl.frames;
    }

    function testSpawnWave(index) {
        enterTestMode();
        if (!Number.isInteger(index) || index < 1) {
            throw new RangeError('StarSwarm.test.spawnWave(index) requires a positive integer');
        }
        testControl.seed = (0x51a7f00d + index) >>> 0;
        testControl.frames = 0;
        state.elapsed = 0;
        state.bullets = [];
        state.enemyBullets = [];
        state.explosions = [];
        state.scorePopups = [];
        state.starLayers = createStarfieldLayers();
        startWave(index);
        render();
        return state.wave;
    }

    function testKill(index) {
        enterTestMode();
        if (!Number.isInteger(index) || index < 0 || index >= state.enemies.length) return false;
        return destroyEnemy(state.enemies[index]);
    }

    function testSnapshotEnemy(index) {
        enterTestMode();
        if (!Number.isInteger(index) || index < 0 || index >= state.enemies.length) return null;
        return Object.assign({}, state.enemies[index]);
    }

    function init() {
        lane.root = document.getElementById('star-swarm');
        lane.canvas = document.getElementById(CANVAS_ID);
        if (!lane.root || !lane.canvas) {
            return;
        }
        lane.ctx = lane.canvas.getContext('2d');
        lane.theme = readTheme(lane.root);
        lane.overlay = lane.root.querySelector('[data-star-swarm="overlay"]');
        lane.overlayTitle = lane.root.querySelector('[data-star-swarm="overlay-title"]');
        lane.overlayHint = lane.root.querySelector('[data-star-swarm="overlay-hint"]');
        lane.actionButton = lane.root.querySelector('[data-star-swarm="action"]');
        lane.scoreEl = lane.root.querySelector('[data-star-swarm="score"]');
        lane.waveEl = lane.root.querySelector('[data-star-swarm="wave"]');
        lane.livesEl = lane.root.querySelector('[data-star-swarm="lives"]');

        state.starLayers = createStarfieldLayers();
        state.planets = createPlanets();
        state.player.x = canvasWidth() / 2 - state.player.width / 2;
        startWave(1);
        updateHud();
        showOverlay('Star Swarm', 'Arrows or A/D to move \u00b7 Space or Up to fire \u00b7 P to pause', 'Start');

        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('keyup', onKeyUp);
        window.addEventListener('pagehide', destroy);
        if (lane.actionButton) {
            lane.actionButton.addEventListener('click', onAction);
        }
        bindTouchControls();
        lane.frame = requestAnimationFrame(loop);
    }

    // Public surface for deterministic browser assertions. State is live, so a
    // Playwright spec can observe input, the loop, score and lives.
    window.StarSwarm = {
        state: state,
        lane: lane,
        constants: {
            PLAYER_SPEED: PLAYER_SPEED,
            PLAYER_FIRE_COOLDOWN: PLAYER_FIRE_COOLDOWN,
            PLAYER_SHOT_LIMIT: PLAYER_SHOT_LIMIT,
            SINGLE_FIGHTER_WIDTH: SINGLE_FIGHTER_WIDTH,
            DUAL_FIGHTER_WIDTH: DUAL_FIGHTER_WIDTH,
            FIRST_EXTRA_SHIP_SCORE: FIRST_EXTRA_SHIP_SCORE,
            EXTRA_SHIP_INTERVAL: EXTRA_SHIP_INTERVAL,
            ENEMY_SCORES: ENEMY_SCORES,
            BULLET_SPEED: BULLET_SPEED,
            ENEMY_BULLET_SPEED: ENEMY_BULLET_SPEED,
            STARFIELD_DEPTHS: STARFIELD_DEPTHS,
            MOOD_RULES: MOOD_RULES
        },
        readTheme: readTheme,
        createStarfieldLayers: createStarfieldLayers,
        createPlanets: createPlanets,
        createFormation: createFormation,
        startWave: startWave,
        addScore: addScore,
        enemyPointValue: enemyPointValue,
        fireBullet: fireBullet,
        destroyEnemy: destroyEnemy,
        startGame: startGame,
        restartGame: restartGame,
        pauseGame: pauseGame,
        resumeGame: resumeGame,
        togglePause: togglePause,
        setMove: setMove,
        update: update,
        render: render,
        destroy: destroy,
        test: Object.freeze({
            step: testStep,
            spawnWave: testSpawnWave,
            kill: testKill,
            snapshotEnemy: testSnapshotEnemy
        })
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
