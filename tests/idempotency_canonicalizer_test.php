<?php

declare(strict_types=1);

require_once __DIR__ . '/../kernel/Http/Idempotency.php';

use Ikabud\Kernel\Http\Idempotency;

$pass = 0;
$fail = 0;

function ict(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? '✓' : '✗') . " {$label}\n";
}

echo "=== IDEMPOTENCY CANONICALIZER ===\n";

ict(
    'associative reordering hashes identically',
    Idempotency::canonicalPayloadHash(['b' => 2, 'a' => ['z' => 3, 'y' => 1]])
        === Idempotency::canonicalPayloadHash(['a' => ['y' => 1, 'z' => 3], 'b' => 2]),
);
ict(
    'list order remains significant',
    Idempotency::canonicalPayloadHash(['a', 'b']) !== Idempotency::canonicalPayloadHash(['b', 'a']),
);
ict(
    'integer one and float one remain distinct',
    Idempotency::canonicalPayloadHash(1) !== Idempotency::canonicalPayloadHash(1.0),
);
$left = new stdClass();
$left->b = 2;
$left->a = 1;
$right = new stdClass();
$right->a = 1;
$right->b = 2;
ict(
    'objects normalize through sorted public properties',
    Idempotency::canonicalPayloadHash($left) === Idempotency::canonicalPayloadHash($right),
);
$resourceRejected = false;
$resource = fopen('php://memory', 'r');
try {
    Idempotency::canonicalPayloadHash(['resource' => $resource]);
} catch (InvalidArgumentException $e) {
    $resourceRejected = true;
} finally {
    if (is_resource($resource)) {
        fclose($resource);
    }
}
ict('resources are rejected', $resourceRejected);

echo "\n{$pass} passed, {$fail} failed\n";
// PHPStan cannot infer mutations made through the procedural assertion helper.
// @phpstan-ignore-next-line
exit($fail === 0 ? 0 : 1);
