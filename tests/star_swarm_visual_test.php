<?php

declare(strict_types=1);

/** Deterministic structure contract for Star Swarm visual iterations.
 *
 * RETIRED ASSERTIONS, 2026-09-19 (chair decision CD-87). Four checks here pinned the iteration-3 LOOK
 * rather than a behaviour: a gradient nebula backdrop with a radial dust wash, a ringed planet drawn with
 * ctx.ellipse, glow shots via shadowBlur, and a fighter hull built from quadraticCurveTo. The director
 * reviewed the rendered game against the arcade original and rejected that look ("components are off"),
 * so those four are retired here and their replacements live in the CHAIR-OWNED pixel spec,
 * tests/browser/star-swarm-pixels.spec.ts, as requirements V3/V4/V5 in
 * tools/harpp2/projects/star-swarm-galaga.json.
 *
 * They were removed rather than re-pointed at the new look on purpose: a red assertion here would fail
 * `composer test` for every unrelated objective until the rebuild lands, which is collateral damage, not
 * rigour. The successor asserts on RENDERED PIXELS, which is stricter than the substring checks it
 * replaces -- a green substring check is exactly how the game shipped looking wrong.
 *
 * Everything that remains is behaviour or discipline: parallax starfield state, the space token family,
 * the four colony moods, entrance and dive motion, feedback state, the deterministic test surface, and the
 * rule that no draw routine introduces a literal colour outside a token fallback.
 */

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/star-swarm/star-swarm.js');
$html = (string) file_get_contents($root . '/public/star-swarm/index.html');
require_once $root . '/modules/star-swarm/helpers.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
};

$containsAll = static function (string $source, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            return false;
        }
    }
    return true;
};

echo "=== cosmic theatre ===\n";
$check($containsAll($js, ['STARFIELD_DEPTHS', "name: 'far'", "name: 'middle'", "name: 'near'", 'twinkle']), 'starfield exposes three named parallax depths and twinkle state');
$check($containsAll($js, ["'--ss-space-bg'", "'--ss-space-deep'", "'--ss-star-dim'", "'--ss-star-bright'"]), 'canvas uses a dedicated space token family rather than themed chrome surfaces');
// RETIRED, and now actually removed: 'background uses gradient and dust glow rendering'
// (createLinearGradient / createRadialGradient) and 'a nearby shaded ringed planet is structured and
// drawn' (ring: true / ctx.ellipse). Successor: V3 'the field is black space with pixel stars' in the
// chair-owned pixel spec.
//
// The retirement was written here on 2026-09-19 but the $check() call was left in place, so the assertion
// kept running for a day. It passed only by accident: the PLANET was drawn with a radial gradient, so the
// needle matched a gradient that had nothing to do with the background. Removing the planet's gradient for
// the moon (phase 13) removed the last one and the check failed -- which is what a stale assertion looks
// like from the outside: an unrelated change breaking a test about something else. V3 is verified and
// chair-owned (field blackShare > 0.9 with pixel stars), so the intent this check encoded is covered, and
// a gradient background is now a REGRESSION, not a requirement.

echo "\n=== swarm ===\n";
$check($containsAll($js, ["name: 'undulate'", "name: 'probe'", "name: 'dive'", "name: 'frenzy'", 'applyMood', 'currentMoodRule']), 'all four moods are executable named rules');
$check($containsAll($js, ['mood.swayRate', 'mood.swayWidth', 'mood.breathe', 'mood.lean']), 'moods alter oscillation and body motion rather than only speed');
$check($containsAll($js, ["'commander'", "'fighter'", "'scout'", "'harvester'", 'drawEnemy']), 'formation has four named enemy silhouettes, including the tall harvester caste');
$check($containsAll($js, ['entranceDelay', 'entranceTime', 'enemy.entering']), 'enemies carry staggered entrance state');
$check($containsAll($js, ['diveTime', 'diveOriginX', 'Math.sin(enemy.diveTime']), 'dive attacks use a curved flight path');
$check($containsAll($js, ['enemy.flash', 'createExplosion', 'scorePopups', 'drawEffects']), 'hits expose flash, debris explosion, and score popup feedback');
// RETIRED: 'player glow shots and enemy diamond shots are visually distinct' (shadowBlur).
// Successor: V4/V5 in the chair-owned pixel spec -- hard-edged pixel marks, no soft glow.

echo "\n=== deterministic test surface ===\n";
$check($containsAll($js, ['testStep', 'update(1 / 60)', 'render()', 'cancelAnimationFrame']), 'fixed-step hook owns the clock and drives the real update and render path');
$check($containsAll($js, ['testSpawnWave', 'startWave(index)', 'testKill', 'destroyEnemy(state.enemies[index])']), 'wave and kill hooks delegate to the real game paths');
$check($containsAll($js, ['testSnapshotEnemy', 'Object.assign({}, state.enemies[index])', 'Object.freeze']), 'enemy snapshots are detached records on a frozen test surface');

echo "\n=== rocket ===\n";
$check($containsAll($js, ['drawRocket', 'flame', 'player.bank']), 'the fighter draws with a banked flame and a distinct dual-fighter body');
// RETIRED: 'rocket exposes ... quadraticCurveTo, ctx.ellipse' -- the hull is now a pixel matrix (V1).
// The cockpit spy in tests/browser/star-swarm.spec.ts may keep a small ellipse cockpit, so no existing
// assertion has to be weakened to satisfy this.

echo "\n=== token contract ===\n";
preg_match_all("/cssVar\\(root,\\s*'(--[a-z0-9-]+)'\\s*,\\s*'([^']+)'\\)/", $js, $matches, PREG_SET_ORDER);
$consumed = [];
foreach ($matches as $match) {
    $consumed[$match[1]] = $match[2];
}
$defaults = starSwarmTokenDefaults();
$check($consumed !== [], 'canvas theme reads named CSS tokens');
$check(array_diff(array_keys($consumed), array_keys($defaults)) === [], 'every JS colour token is declared by the PHP token contract');
$check(array_filter($consumed, static fn (string $fallback): bool => trim($fallback) === '') === [], 'every JS token read has a fallback');
$darkFallbacks = array_intersect_key($consumed, array_flip(['--ss-space-bg', '--ss-space-deep']));
$check(count($darkFallbacks) === 2 && $darkFallbacks['--ss-space-bg'] === '#02030b' && $darkFallbacks['--ss-space-deep'] === '#071126', 'space background fallbacks are deterministically near-black');
$missing = [];
foreach (array_keys($consumed) as $token) {
    if (!str_contains($html, $token . ':')) {
        $missing[] = $token;
    }
}
$check($missing === [], 'the rendered page injects every JS token: ' . implode(', ', $missing));
$check(!preg_match('/#[0-9a-f]{3,8}|rgba?\(/i', preg_replace("/cssVar\\([^\n]+/", '', $js) ?? ''), 'draw routines do not introduce literal colours outside token fallbacks');

// ── The static entry's asset versions must track the live files ──────────────────────────────────────
// MEASURED 2026-09-19. `public/star-swarm/index.html` is the only working entry for /star-swarm/ (the
// kernel route answers 403), and it freezes its asset versions in the markup. Nothing regenerates it, so
// when the stylesheet changed the page still served ?v=1789789870 while the file's mtime was 1789817777.
// A browser that already held that URL kept rendering the OLD stylesheet, so the black score row was
// committed, verified, pushed -- and the director still saw a white bar. Every probe passed, because a
// fresh browser fetches the current bytes under a stale query string. That is the whole failure: the
// check was cold-cache and the user was warm-cache.
//
// So the version is now asserted against the file it names. A frozen version fails here instead of
// surviving in someone's browser.
$assetVersionIsCurrent = static function (string $htmlPath, string $assetPath, string $assetName): ?string {
    $html = @file_get_contents($htmlPath);
    if ($html === false) {
        return "cannot read {$htmlPath}";
    }
    if (preg_match('/' . preg_quote($assetName, '/') . '\?v=(\d+)/', $html, $match) !== 1) {
        return "{$htmlPath} does not version {$assetName}";
    }
    $actual = (int) $match[1];
    $expected = @filemtime($assetPath);
    if ($expected === false) {
        return "cannot stat {$assetPath}";
    }
    return $actual === $expected
        ? null
        : "{$htmlPath} serves {$assetName}?v={$actual} but the file's mtime is {$expected} — "
            . 'a warm browser keeps the stale asset and the fix looks like it never landed';
};

echo "\n=== asset versioning ===\n";
$root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
$entry = $root . '/public/star-swarm/index.html';
foreach (['star-swarm.css', 'star-swarm.js'] as $assetName) {
    $problem = $assetVersionIsCurrent($entry, $root . '/public/star-swarm/' . $assetName, $assetName);
    $check($problem === null, $problem ?? "{$assetName} is versioned from the live file mtime");
}

echo "\n=== summary ===\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
