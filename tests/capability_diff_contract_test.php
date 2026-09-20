<?php

/**
 * Kernel extension trust · the capability diff behind P4.3 (C6).
 *
 * CHAIR-OWNED ACCEPTANCE, written BEFORE the feature exists, so a red baseline means the feature is
 * absent rather than that the test is missing.
 *
 * The question this answers is the one an operator asks BEFORE admitting an extension: *what authority
 * would this install grant that the tenant does not already have, and which of it writes?* That is a
 * report, not a permission decision -- the approval and trust basis are P4.1 and belong to the director --
 * which is exactly why it is in scope for the chair: it grants nothing.
 *
 * Pure-logic on purpose. No bootstrap, no tenant, no database: the diff is a function of two declaration
 * maps, so this suite has no skip path and cannot pass vacuously.
 *
 * Interface fixed here, and the lane implements it:
 *
 *   Ikabud\Kernel\Workbench\Governance\CapabilityDiff::diff(array $before, array $after): array
 *
 *     $before / $after : map of capability id => declaration array
 *                        (as read from a module.json `capabilities.exposes` entry)
 *     returns          : ['added' => [...], 'removed' => [...], 'unchanged' => [...], 'widening' => [...]]
 *
 *       added      ids present only in $after,  sorted
 *       removed    ids present only in $before, sorted
 *       unchanged  ids present in both,         sorted
 *       widening   the subset of `added` that WRITES, sorted -- a capability is a write when its
 *                  declaration carries `requires_protocol: v2` or a non-empty `effects.invalidates`
 */

declare(strict_types=1);

$class = 'Ikabud\\Kernel\\Workbench\\Governance\\CapabilityDiff';
$file = dirname(__DIR__) . '/kernel/Workbench/Governance/CapabilityDiff.php';
if (is_file($file)) {
    require_once $file;
}

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

echo "=== the diff exists and is pure ===\n";

if (!class_exists($class)) {
    $check('CapabilityDiff exists', false, 'not implemented yet — this is the red baseline');
    echo "\n=== summary ===\n";
    printf("  %d passed, %d failed\n", $passed, $failed);
    exit(1);
}

$check($class . ' is loadable', true);
$check('and exposes a static diff()', is_callable([$class, 'diff']));

$read = ['id' => 'x.read@1', 'description' => 'a read'];
$write = ['id' => 'x.write@1', 'requires_protocol' => 'v2', 'description' => 'a write'];
$invalidating = ['id' => 'x.touch@1', 'effects' => ['invalidates' => ['entity.list.post']]];

$before = ['a.read@1' => $read, 'a.write@1' => $write];
$after = ['a.read@1' => $read, 'a.write@1' => $write, 'b.read@1' => $read, 'b.write@1' => $write, 'b.touch@1' => $invalidating];

$diff = $class::diff($before, $after);

echo "\n=== classification ===\n";
$check('a capability in both is unchanged', $diff['unchanged'] === ['a.read@1', 'a.write@1'], json_encode($diff['unchanged']));
$check(
    'a capability only in the candidate is added',
    $diff['added'] === ['b.read@1', 'b.touch@1', 'b.write@1'],
    json_encode($diff['added'])
);
$check(
    'a capability the candidate drops is removed, and named rather than ignored',
    $class::diff($before, ['a.read@1' => $read])['removed'] === ['a.write@1']
);

echo "\n=== and which of the new authority WRITES ===\n";
// The whole point of the report: an update that adds a read is routine, one that adds a delete is not.
$check(
    'a newly granted write (requires_protocol v2) is flagged as widening',
    in_array('b.write@1', $diff['widening'], true),
    json_encode($diff['widening'])
);
$check(
    'a newly granted capability that invalidates caches is flagged too',
    in_array('b.touch@1', $diff['widening'], true)
);
$check(
    'a newly granted READ is not called widening',
    !in_array('b.read@1', $diff['widening'], true),
    'calling reads widening would make the flag noise, and a noisy flag gets ignored'
);
$check(
    'widening is a subset of added, never of unchanged or removed',
    array_diff($diff['widening'], $diff['added']) === []
);

echo "\n=== determinism and purity ===\n";
$check('the same inputs give an identical result', $class::diff($before, $after) === $diff);
$check(
    'the inputs are unchanged by the call',
    $before === ['a.read@1' => $read, 'a.write@1' => $write]
    && $after === ['a.read@1' => $read, 'a.write@1' => $write, 'b.read@1' => $read, 'b.write@1' => $write, 'b.touch@1' => $invalidating]
);
$check(
    'an unchanged set reports nothing at all',
    $class::diff($before, $before) === ['added' => [], 'removed' => [], 'unchanged' => ['a.read@1', 'a.write@1'], 'widening' => []]
);

echo "\n=== summary ===\n";
printf("  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
