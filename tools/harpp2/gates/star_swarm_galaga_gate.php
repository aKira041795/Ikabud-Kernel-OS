<?php

declare(strict_types=1);

/**
 * Galaga-fidelity gate for Star Swarm — chair-authored, and RED by construction until the rebuild lands.
 *
 * WHY IT DOES NOT LIVE IN tests/
 * A red gate inside tests/ breaks `composer test`, which other objectives use as a regression guard (item
 * D.1's guard is exactly that suite). Making an unrelated objective fail would be collateral damage, not
 * rigour, so the gate lives here and the rebuild objective names it in its own acceptance list. It is not
 * hidden: it is the first command in that list.
 *
 * WHY IT EXISTS
 * tools/harpp2/projects/star-swarm-galaga.json states the requirements for a Galaga-faithful rebuild as
 * DATA, because the previous iteration was reported as "all acceptance gates passed" while half its brief
 * was unbuilt — the requirements were prose, so nothing could fail on them. This gate is what makes the
 * data load-bearing: every requirement must have a probe asserted inside an expect(...) in the browser
 * spec, the game must expose every required observability field, and the invariants must hold.
 *
 * It checks EXISTENCE, which is mechanical. Whether a probe is strict enough to fail on a bad game is the
 * chair's judgement; the requirement to say so is in the objective.
 *
 * usage: php tools/harpp2/gates/star_swarm_galaga_gate.php
 * exit:  0 when every requirement, field and invariant is satisfied, 1 otherwise.
 */

$root = dirname(__DIR__, 3); // tools/harpp2/gates -> tools/harpp2 -> tools -> repository root
$contractPath = $root . '/tools/harpp2/projects/star-swarm-galaga.json';

/*
 * PHASE MODE — why it exists.
 *
 * Measured 2026-09-18: the whole-rebuild acceptance is a single all-or-nothing gate, so it cannot turn green
 * until the LAST requirement lands. The driver's progress heuristic then reads every real chunk as
 * `no_progress` — three chunks that added 201 lines of product code and moved this gate 12 -> 27 were
 * recorded as no progress and the item escalated. That is an objective/instrument defect, not a product one.
 *
 * With --phase=N the gate checks one satisfiable slice at a time, so each chunk can flip its own acceptance
 * and the loop keeps to the doctrine: small objectives, verifiable progress, one verdict at a time.
 *
 *   1 — the state surface (all observability fields) + the `components` requirements
 *   2 — `animation` + `points`
 *   3 — `gameplay`
 *
 * Invariants are always checked. No flag means the complete rebuild, exactly as before.
 */
$phaseMap = [1 => ['components'], 2 => ['animation', 'points'], 3 => ['gameplay']];
$phase = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--phase=([0-9]+)$/', $argument, $m) === 1) {
        $phase = (int) $m[1];
    }
    if (str_starts_with($argument, '--help')) {
        echo "usage: php tools/harpp2/gates/star_swarm_galaga_gate.php [--phase=1|2|3]\n";
        exit(0);
    }
}
if ($phase !== 0 && !isset($phaseMap[$phase])) {
    fwrite(STDERR, "unknown --phase={$phase}; expected 1, 2 or 3\n");
    exit(2);
}
$activeGroups = $phase === 0 ? null : $phaseMap[$phase];

$specPath = $root . '/tests/browser/star-swarm.spec.ts';
$jsPath = $root . '/public/star-swarm/star-swarm.js';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
};

if (!is_file($contractPath)) {
    echo "  FAIL contract missing: tools/harpp2/projects/star-swarm-galaga.json\n\n=== summary ===\n  0 passed, 1 failed\n";
    exit(1);
}
$contract = json_decode((string) file_get_contents($contractPath), true);
if (!is_array($contract)) {
    echo "  FAIL contract is not valid JSON\n\n=== summary ===\n  0 passed, 1 failed\n";
    exit(1);
}

$spec = is_file($specPath) ? (string) file_get_contents($specPath) : '';
$js = is_file($jsPath) ? (string) file_get_contents($jsPath) : '';

echo "=== prerequisites ===\n";
if ($phase !== 0) {
    echo "  phase {$phase}: groups " . implode(', ', $activeGroups) . "\n";
}
$check($spec !== '', 'the browser spec is present');
$check($js !== '', 'the game source is present');

// ── every requirement has a probe asserted inside an expect(...) ───────────────────────────────
$allRequirements = is_array($contract['requirements'] ?? null) ? $contract['requirements'] : [];
$requirements = $activeGroups === null
    ? $allRequirements
    : array_values(array_filter($allRequirements, static fn(array $r): bool => in_array((string) ($r['group'] ?? ''), $activeGroups, true)));
echo "\n=== requirements (" . count($requirements) . ")" . ($phase !== 0 ? " in this phase" : '') . " ===\n";
$check($allRequirements !== [], 'the contract declares requirements');
$check($requirements !== [], 'this phase selects at least one requirement');
foreach ($requirements as $requirement) {
    $id = (string) ($requirement['id'] ?? '?');
    $group = (string) ($requirement['group'] ?? '?');
    $probe = (string) ($requirement['probe'] ?? '');
    $aliases = array_values(array_filter(array_map('trim', explode('|', $probe)), static fn(string $a): bool => $a !== ''));
    $asserted = false;
    foreach ($aliases as $alias) {
        // The probe must appear inside a real assertion, not merely somewhere in the file: the assertion
        // itself is what a red game would fail.
        if (preg_match('/expect\s*\([^;]*' . preg_quote($alias, '/') . '/s', $spec) === 1) {
            $asserted = true;
            break;
        }
    }
    $check($asserted, "{$id} ({$group}) probe is asserted in the spec: " . ($aliases[0] ?? '(no probe declared)'));
}

// ── the game exposes every observability field ──────────────────────────────────────────────────────
// The state surface is the deliverable of phase 1 (and of the complete rebuild). Phases 2 and 3 read
// it, so re-checking it there would test phase 1 again rather than the phase's own work.
$fields = $contract['observability']['required_state_fields'] ?? [];
if ($phase === 0 || $phase === 1) {
    echo "\n=== observability (" . count($fields) . " fields) ===\n";
    foreach ($fields as $field) {
        $check(str_contains($js, (string) $field), "state field is exposed: {$field}");
    }
}

// ── invariants ──────────────────────────────────────────────────────────────────────────────────────
echo "\n=== invariants ===\n";
$raster = [];
foreach (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'] as $extension) {
    foreach (glob($root . '/public/star-swarm/**/*.' . $extension) ?: [] as $file) {
        $raster[] = $file;
    }
    foreach (glob($root . '/public/star-swarm/*.' . $extension) ?: [] as $file) {
        $raster[] = $file;
    }
}
$raster = array_values(array_unique($raster));
$check(($contract['invariants']['raster_assets'] ?? 0) === 0 && $raster === [], 'no raster assets under public/star-swarm');

$manifestClean = true;
foreach ($contract['invariants']['dependency_manifests_unchanged'] ?? [] as $manifest) {
    $path = $root . '/' . $manifest;
    if (is_file($path) && str_contains((string) file_get_contents($path), 'star-swarm')) {
        $manifestClean = false;
    }
}
$check($manifestClean, 'no dependency manifest references star-swarm');

$check(!preg_match('/waitForTimeout\s*\(/', $spec), 'the spec contains no waitForTimeout');

// In a phase the rule is the non-shrink baseline; the floor belongs to the finished rebuild.
$floor = $phase === 0
    ? (int) ($contract['invariants']['browser_spec_assertion_floor'] ?? 0)
    : (int) ($contract['invariants']['browser_spec_assertion_baseline'] ?? 0);
$assertions = preg_match_all('/\bexpect\s*\(/', $spec);
$check($assertions >= $floor, "the spec asserts at least {$floor} times (found {$assertions})");

printf("\n=== summary ===\n  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
