<?php

declare(strict_types=1);

/**
 * Concept contract gate for Star Swarm.
 *
 * WHY THIS EXISTS (2026-09-16): iteration 3 was reported as "all objective acceptance gates passed" while
 * a large part of its brief was never built — depth, dispersal, a fourth caste, semantic roles and the
 * probes for all of them. The brief listed those requirements as PROSE, so nothing could fail on them and
 * the driver honestly reported a pass. This test turns the requirement list into a command: it reads
 * tools/harpp2/projects/star-swarm-concept.json and fails when a required observability field, probe or
 * invariant is missing. It checks EXISTENCE, which is mechanical; the probes' strictness is the chair's.
 */

$root = dirname(__DIR__);
$manifestPath = $root . '/tools/harpp2/projects/star-swarm-concept.json';
$specPath = $root . '/tests/browser/star-swarm.spec.ts';
$jsPath = $root . '/public/star-swarm/star-swarm.js';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
};

if (!is_file($manifestPath)) {
    echo "  FAIL concept contract missing: tools/harpp2/projects/star-swarm-concept.json\n\n=== summary ===\n  0 passed, 1 failed\n";
    exit(1);
}

$contract = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($contract)) {
    echo "  FAIL concept contract is not valid JSON\n\n=== summary ===\n  0 passed, 1 failed\n";
    exit(1);
}

$js = is_file($jsPath) ? (string) file_get_contents($jsPath) : '';
$spec = is_file($specPath) ? (string) file_get_contents($specPath) : '';

echo "=== concept contract ===\n";
$check($js !== '', 'the game source is present');
$check($spec !== '', 'the browser spec is present');

echo "\n=== observability (a swarm you cannot observe cannot be probed) ===\n";
foreach ((array) ($contract['observability']['required_state_fields'] ?? []) as $field) {
    $check(
        (bool) preg_match('/\b' . preg_quote((string) $field, '/') . '\b/', $js),
        "state exposes '{$field}'"
    );
}
foreach ((array) ($contract['observability']['required_enemy_fields'] ?? []) as $field) {
    $check(
        (bool) preg_match('/\b' . preg_quote((string) $field, '/') . '\b/', $js),
        "an enemy record carries '{$field}'"
    );
}

echo "\n=== spec probes (each must live inside an expect(...) statement) ===\n";
// Split on expect( so a probe name only counts when it is part of an assertion.
$statements = preg_split('/expect\(/', $spec) ?: [];
$assertionBody = implode("\n", array_slice($statements, 1));
foreach ((array) ($contract['spec_probes'] ?? []) as $probe => $meaning) {
    if (str_starts_with((string) $probe, '_')) {
        continue;
    }
    // A probe key may offer alternatives, separated by | — the behaviour is required, the spelling is not.
    $names = array_filter(array_map('trim', explode('|', (string) $probe)));
    $found = null;
    foreach ($names as $name) {
        if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $assertionBody)) {
            $found = $name;
            break;
        }
    }
    $check($found !== null, "probe '" . implode('|', $names) . "' asserts — {$meaning}");
}

echo "\n=== invariants ===\n";
$raster = preg_match_all('/drawImage|createImageBitmap|new Image\(/', $js);
$check((int) $raster === (int) ($contract['invariants']['raster_assets'] ?? 0), 'no raster assets: the game is drawn, not loaded (0 found)');

foreach ((array) ($contract['invariants']['dependency_manifests_unchanged'] ?? []) as $manifest) {
    $path = $root . '/' . $manifest;
    if (!is_file($path)) {
        $check(false, "dependency manifest missing: {$manifest}");
        continue;
    }
    $head = [];
    $rc = 0;
    exec('git -C ' . escapeshellarg($root) . ' show HEAD:' . escapeshellarg((string) $manifest) . ' 2>/dev/null', $head, $rc);
    $changed = $rc !== 0 ? false : (implode("\n", $head) !== rtrim((string) file_get_contents($path), "\n"));
    $check(!$changed, "no new dependency: {$manifest} is unchanged from HEAD");
}

echo "\n=== summary ===\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
