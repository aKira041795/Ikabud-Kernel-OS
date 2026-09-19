<?php

declare(strict_types=1);

/**
 * Guard for the Galaga gate: does it actually FAIL when it should?
 *
 * WHY THIS EXISTS (2026-09-19)
 * tools/harpp2/projects/star-swarm-galaga.json claims in its own _why that
 * "tests/star_swarm_galaga_gate_test.php fails when one is missing or when its probe is not asserted
 * inside an expect(...)". That file did not exist. The gate for the phase 1-3 rebuild therefore had NO
 * test proving it could fail, and a gate that cannot fail is not an instrument: it went fully green while
 * the components did not look like Galaga at all. This test is that missing guard.
 *
 * HOW IT AVOIDS DAMAGING THE REPOSITORY
 * The gate derives its root from its own location (dirname(__DIR__, 3)), so this test builds a SANDBOX
 * tree, copies the gate, the contract and both specs into it, and mutates the COPIES. Nothing under the
 * real repository is written, and no restore step can be forgotten. The sandbox is removed on exit.
 *
 * WHAT IT PROVES
 * Not that the gate is strict enough — that is the chair's judgement and the probes' job. It proves the
 * gate is LOAD-BEARING: each control removes one input it claims to check, and the gate must go red.
 *
 * Run: php tests/star_swarm_galaga_gate_test.php
 */

$root = dirname(__DIR__);
$sources = [
    'tools/harpp2/gates/star_swarm_galaga_gate.php' => $root . '/tools/harpp2/gates/star_swarm_galaga_gate.php',
    'tools/harpp2/projects/star-swarm-galaga.json' => $root . '/tools/harpp2/projects/star-swarm-galaga.json',
    'tests/browser/star-swarm.spec.ts' => $root . '/tests/browser/star-swarm.spec.ts',
    'tests/browser/star-swarm-pixels.spec.ts' => $root . '/tests/browser/star-swarm-pixels.spec.ts',
    // Every chair-owned instrument must be in the sandbox, or the baseline control fails for the harness's
    // own reason instead of on the gate (which is what happened when star-swarm.js was missing).
    'tests/browser/star-swarm-audio.spec.ts' => $root . '/tests/browser/star-swarm-audio.spec.ts',
    // The gate refuses to run without the game source, so the sandbox must carry it too. Omitting it made
    // the baseline control fail on the sandbox rather than on the gate (measured 2026-09-19): a control
    // that fails for the harness's own reason is worse than no control.
    'public/star-swarm/star-swarm.js' => $root . '/public/star-swarm/star-swarm.js',
];

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
};

foreach ($sources as $label => $path) {
    if (!is_file($path)) {
        echo "  FAIL required input missing: {$label}\n\n=== summary ===\n  0 passed, 1 failed\n";
        exit(1);
    }
}

$sandboxRoot = sys_get_temp_dir() . '/star-swarm-gate-guard-' . getmypid();
$removeSandbox = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};
register_shutdown_function(static fn() => $removeSandbox($sandboxRoot));

/** Build a fresh sandbox from the real files, applying $mutate to the copied contents. */
$sandbox = static function (callable $mutate = null) use ($sources, $sandboxRoot): string {
    $removeSandbox = static function (string $path): void {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    };
    $removeSandbox($sandboxRoot);
    foreach ($sources as $relative => $real) {
        $target = $sandboxRoot . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, (string) file_get_contents($real));
    }
    if (!is_dir($sandboxRoot . '/public/star-swarm')) {
        mkdir($sandboxRoot . '/public/star-swarm', 0777, true);
    }
    if ($mutate !== null) {
        $mutate($sandboxRoot);
    }
    return $sandboxRoot;
};

/** Run the sandboxed gate and return [exit code, output]. */
$runGate = static function (string $sandboxRoot, string $arguments = ''): array {
    $output = [];
    $code = 0;
    exec(
        'php ' . escapeshellarg($sandboxRoot . '/tools/harpp2/gates/star_swarm_galaga_gate.php')
        . ($arguments !== '' ? ' ' . $arguments : '') . ' 2>&1',
        $output,
        $code
    );
    return [$code, implode("\n", $output)];
};

echo "=== the gate passes on an unmodified copy (baseline) ===\n";
$sandboxRoot = $sandbox();
[$code, $output] = $runGate($sandboxRoot);
$check($code === 0, 'the gate exits 0 when every requirement, field and invariant is satisfied');
if ($code !== 0) {
    // The failing check names itself, so a broken sandbox is never mistaken for a strict gate.
    echo "---- sandboxed gate output ----\n{$output}\n------------------------------\n";
}

echo "\n=== falsification controls (each removes one input the gate claims to check) ===\n";

// 1. A chair-owned (pixel) probe loses its assertion. The lane cannot fix this by editing the state spec:
//    fidelity probes are only accepted from the chair-owned pixel spec.
$sandboxRoot = $sandbox(static function (string $root): void {
    $path = $root . '/tests/browser/star-swarm-pixels.spec.ts';
    file_put_contents(
        $path,
        str_replace('the boss renders green', 'the boss renders GREEN', (string) file_get_contents($path))
    );
});
[$code, $output] = $runGate($sandboxRoot, '--phase=4');
$check($code === 1 && str_contains($output, 'V2'), 'a fidelity probe asserted only in the chair-owned spec is load-bearing (V2 fails)');

// 2. The same probe asserted in the LANE-OWNED state spec must NOT satisfy a chair-owned requirement.
$sandboxRoot = $sandbox(static function (string $root): void {
    $pixel = $root . '/tests/browser/star-swarm-pixels.spec.ts';
    file_put_contents($pixel, str_replace('the boss renders green', 'the boss renders GREEN', (string) file_get_contents($pixel)));
    $state = $root . '/tests/browser/star-swarm.spec.ts';
    file_put_contents($state, (string) file_get_contents($state) . "\n// expect(the boss renders green)\n");
});
[$code, $output] = $runGate($sandboxRoot, '--phase=4');
$check($code === 1, 'the lane-owned spec cannot satisfy a chair-owned requirement by asserting its probe');

// 3. The contract drops a requirement's probe entirely.
$sandboxRoot = $sandbox(static function (string $root): void {
    $path = $root . '/tools/harpp2/projects/star-swarm-galaga.json';
    file_put_contents($path, str_replace('"probe": "the boss renders green"', '"probe": ""', (string) file_get_contents($path)));
});
[$code, $output] = $runGate($sandboxRoot, '--phase=4');
$check($code === 1 && str_contains($output, 'V2'), 'a requirement with no probe declared fails (V2)');

// 4. The pixel instrument is shrunk: fewer assertions than its floor.
$sandboxRoot = $sandbox(static function (string $root): void {
    $path = $root . '/tests/browser/star-swarm-pixels.spec.ts';
    file_put_contents($path, "// stubbed instrument\nexpect(a);\nexpect(b);\nexpect(c);\n");
});
[$code, $output] = $runGate($sandboxRoot, '--phase=4');
$check($code === 1 && str_contains($output, 'pixel spec asserts at least'), 'the pixel instrument cannot be shrunk below its floor');

// 5. A raster asset appears under public/star-swarm, which the pixel-art invariant forbids.
$sandboxRoot = $sandbox(static function (string $root): void {
    file_put_contents($root . '/public/star-swarm/control.png', 'not really a png');
});
[$code, $output] = $runGate($sandboxRoot);
$check($code === 1 && str_contains($output, 'no raster assets'), 'a raster asset fails the pixel-art invariant');

// 6. A state-side (lane-owned) probe loses its assertion: phase 1 must go red too.
$sandboxRoot = $sandbox(static function (string $root): void {
    $path = $root . '/tests/browser/star-swarm.spec.ts';
    file_put_contents($path, preg_replace('/\bexpect\s*\(/', 'void (', (string) file_get_contents($path)));
});
[$code, $output] = $runGate($sandboxRoot, '--phase=1');
$check($code === 1, 'the state-side requirements fail when their assertions are removed (phase 1)');

echo "\n=== state-side controls ===\n";
// The gate must also refuse an objective that has silently lost its acceptance structure.
$sandboxRoot = $sandbox(static function (string $root): void {
    file_put_contents($root . '/tools/harpp2/projects/star-swarm-galaga.json', '{}');
});
[$code, $output] = $runGate($sandboxRoot, '--phase=1');
$check($code === 1, 'an empty contract fails rather than passing vacuously');

$sandboxRoot = $sandbox(static function (string $root): void {
    unlink($root . '/tests/browser/star-swarm-pixels.spec.ts');
});
[$code, $output] = $runGate($sandboxRoot);
$check($code === 1 && str_contains($output, 'pixel spec is present'), 'a missing chair-owned pixel spec fails the gate');

echo "\n=== summary ===\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
