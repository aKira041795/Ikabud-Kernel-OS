<?php

declare(strict_types=1);

/**
 * Primitive-level HTTP idempotency adoption contract.
 * No router, handler, or module seam is wired by this test.
 */

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Http\Idempotency;

$pass = 0;
$fail = 0;
/** @var list<string> $errors */
$errors = [];

function ihat(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function httpAdoptionConnection(): PDO
{
    global $config;
    $db = $config['database'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['database'],
        $db['charset'] ?? 'utf8mb4',
    );
    return new PDO($dsn, $db['username'], $db['password'], $db['options']);
}

function httpAdoptionFingerprint(string $method, string $target, mixed $parsedBody): string
{
    $path = parse_url($target, PHP_URL_PATH);
    if (!is_string($path)) {
        throw new InvalidArgumentException('HTTP request target must contain a path');
    }
    return Idempotency::canonicalPayloadHash([
        'method' => strtoupper($method),
        'path' => $path,
        'body' => $parsedBody,
    ]);
}

function httpAdoptionJsonBody(string $raw): mixed
{
    return trim($raw) === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $claim */
function httpAdoptionResponse(array $claim): array
{
    if (($claim['status'] ?? null) === 'duplicate') {
        return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : [];
    }
    if (($claim['status'] ?? null) === 'conflict') {
        return ['status' => 409, 'body' => 'idempotency_payload_conflict', 'headers' => []];
    }
    if (($claim['status'] ?? null) === 'in_progress') {
        return ['status' => 425, 'body' => 'idempotency_in_progress', 'headers' => ['Retry-After' => '2']];
    }
    return [];
}

$logDirectory = __DIR__ . '/../storage/logs';
file_put_contents($logDirectory . '/app.log', '');
file_put_contents($logDirectory . '/error.log', '');

echo "\n=== IDEMPOTENCY HTTP ADOPTION ===\n";

$db = app()->db();
$prefix = 'test.http-adoption.' . getmypid();
$tenantA = 64001;
$tenantB = 64002;
$keys = [];

try {
    $body = httpAdoptionJsonBody('{"amount":1.0,"items":["a","b"]}');
    $fingerprint = httpAdoptionFingerprint('post', '/mobile/ledger?sync=1', $body);
    $key = $prefix . '.replay';
    $keys[] = $key;
    $executions = 0;

    $first = Idempotency::claim($key, $tenantA, $fingerprint, $db, 2);
    ihat('first HTTP request receives a new claim', $first['status'] === 'new');
    if ($first['status'] === 'new') {
        $executions++;
    }
    $outcome = [
        'status' => 201,
        'body' => '{"ok":true,"id":17}',
        'headers' => ['Content-Type' => 'application/json', 'Location' => '/mobile/ledger/17'],
    ];
    ihat('first HTTP outcome commits on the claiming connection', Idempotency::commit($key, $tenantA, $outcome, $db));

    $duplicate = Idempotency::claim($key, $tenantA, $fingerprint, $db, 2);
    if ($duplicate['status'] === 'new') {
        $executions++;
    }
    ihat('duplicate replays without handler execution', $duplicate['status'] === 'duplicate' && $executions === 1, json_encode($duplicate));
    ihat('duplicate preserves status, original body, and allowlisted headers', httpAdoptionResponse($duplicate) === $outcome, json_encode($duplicate));
    ihat('HTTP outcome has no redundant nested version', !array_key_exists('version', $duplicate['outcome'] ?? []));

    $storedStmt = $db->prepare(
        'SELECT response_json FROM kernel_idempotency_keys WHERE idempotency_key_hash = :hash AND tenant_id = :tenant'
    );
    $storedStmt->execute([':hash' => hash('sha256', $key), ':tenant' => $tenantA]);
    $stored = json_decode((string)$storedStmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    ihat(
        'storage envelope alone carries the version',
        ($stored['_kernel_idempotency']['version'] ?? null) === 1
            && !array_key_exists('version', $stored['outcome'] ?? []),
        json_encode($stored),
    );

    $changed = httpAdoptionFingerprint('POST', '/mobile/ledger', httpAdoptionJsonBody('{"amount":2.0,"items":["a","b"]}'));
    $conflict = Idempotency::claim($key, $tenantA, $changed, $db, 2);
    $conflictResponse = httpAdoptionResponse($conflict);
    ihat(
        'same key with a changed payload maps to conflict/409 without execution',
        $conflict['status'] === 'conflict'
            && $executions === 1
            && $conflictResponse === ['status' => 409, 'body' => 'idempotency_payload_conflict', 'headers' => []],
    );

    $tenantClaim = Idempotency::claim($key, $tenantB, $fingerprint, $db, 2);
    ihat('the same client key is isolated by tenant', $tenantClaim['status'] === 'new');
    ihat('tenant-isolated processing claim can be released', Idempotency::release($key, $tenantB, $db));

    $boundedKey = $prefix . '.bounded';
    $keys[] = $boundedKey;
    $boundedHash = httpAdoptionFingerprint('PUT', '/mobile/ledger/17', ['state' => 'closed']);
    $ownerDb = httpAdoptionConnection();
    $contenderDb = httpAdoptionConnection();
    $owner = Idempotency::claim($boundedKey, $tenantA, $boundedHash, $ownerDb);
    ihat('independent owner establishes the contended processing claim', $owner['status'] === 'new');
    $boundedExecutions = 0;
    $started = microtime(true);
    $bounded = Idempotency::claim($boundedKey, $tenantA, $boundedHash, $contenderDb, 2);
    $elapsed = microtime(true) - $started;
    if ($bounded['status'] === 'new') {
        $boundedExecutions++;
    }
    ihat(
        'two-second bound returns in_progress/425 after one lock-wait window',
        $bounded['status'] === 'in_progress' && $elapsed >= 1.5 && $elapsed < 3.5,
        sprintf('status=%s elapsed=%.3f', $bounded['status'], $elapsed),
    );
    ihat('bounded contention does not grant execution', $boundedExecutions === 0);
    ihat(
        'bounded adopter mapping specifies 425 and Retry-After: 2',
        httpAdoptionResponse($bounded) === [
            'status' => 425,
            'body' => 'idempotency_in_progress',
            'headers' => ['Retry-After' => '2'],
        ],
    );
    ihat('claim owner releases after the bounded contention test', Idempotency::release($boundedKey, $tenantA, $ownerDb));

    $claimMethod = new ReflectionMethod(Idempotency::class, 'claim');
    $waitParameter = $claimMethod->getParameters()[4] ?? null;
    ihat(
        'claim wait parameter is appended, nullable, and defaults to null',
        $waitParameter instanceof ReflectionParameter
            && $waitParameter->getName() === 'waitCapSeconds'
            && $waitParameter->allowsNull()
            && $waitParameter->isDefaultValueAvailable()
            && $waitParameter->getDefaultValue() === null,
    );
    $idempotencyClass = new ReflectionClass(Idempotency::class);
    ihat('null wait preserves the 300-second default', $idempotencyClass->getConstant('WAIT_CAP_SECONDS') === 300);
    $workflowSource = (string)file_get_contents(__DIR__ . '/../kernel/WorkflowEngine.php');
    $eventBusSource = (string)file_get_contents(__DIR__ . '/../kernel/EventBus.php');
    ihat(
        'WorkflowEngine and EventBus remain source-compatible four-argument callers',
        preg_match('/Idempotency::claim\([^;]+\$db\)/sU', $workflowSource) === 1
            && preg_match('/Idempotency::claim\([^;]+\$pdo\)/sU', $eventBusSource) === 1,
    );

    ihat('method case is normalized to uppercase', httpAdoptionFingerprint('post', '/x', null) === httpAdoptionFingerprint('POST', '/x', null));
    ihat('HTTP methods remain fingerprint-distinct', httpAdoptionFingerprint('POST', '/x', null) !== httpAdoptionFingerprint('PUT', '/x', null));
    ihat('query is excluded while paths remain significant', httpAdoptionFingerprint('POST', '/x?a=1', null) === httpAdoptionFingerprint('POST', '/x?a=2', null) && httpAdoptionFingerprint('POST', '/x', null) !== httpAdoptionFingerprint('POST', '/y', null));
    ihat('body values remain fingerprint-distinct', httpAdoptionFingerprint('POST', '/x', ['v' => 1]) !== httpAdoptionFingerprint('POST', '/x', ['v' => 2]));
    ihat('JSON integer and float body types remain distinct', httpAdoptionFingerprint('POST', '/x', httpAdoptionJsonBody('1')) !== httpAdoptionFingerprint('POST', '/x', httpAdoptionJsonBody('1.0')));
    ihat('JSON list order remains significant', httpAdoptionFingerprint('POST', '/x', httpAdoptionJsonBody('[1,2]')) !== httpAdoptionFingerprint('POST', '/x', httpAdoptionJsonBody('[2,1]')));
    ihat('whitespace-only JSON fingerprints as null', httpAdoptionJsonBody(" \n\t") === null && httpAdoptionFingerprint('POST', '/x', httpAdoptionJsonBody(' ')) === httpAdoptionFingerprint('POST', '/x', null));
    ihat('JSON is decoded associatively with nested types retained', httpAdoptionJsonBody('{"object":{"enabled":true},"scalar":"1","nothing":null}') === ['object' => ['enabled' => true], 'scalar' => '1', 'nothing' => null]);
    parse_str('quantity=1&enabled=false', $formBody);
    ihat('form-urlencoded body remains a string-valued map', $formBody === ['quantity' => '1', 'enabled' => 'false']);

    $legacyKey = $prefix . '.legacy';
    $keys[] = $legacyKey;
    ihat('legacy check still returns null while creating its marker', Idempotency::check($legacyKey, $tenantA) === null);
    $legacyOutcome = ['status' => 202, 'body' => 'legacy'];
    Idempotency::store($legacyKey, $tenantA, $legacyOutcome);
    ihat('legacy store/check behavior remains unchanged', Idempotency::check($legacyKey, $tenantA) === $legacyOutcome);

    $keylessBefore = (int)$db->query("SELECT COUNT(*) FROM kernel_idempotency_keys WHERE idempotency_key_hash = " . $db->quote(hash('sha256', $prefix . '.keyless')))->fetchColumn();
    $keylessExecutions = 0;
    $keylessExecutions++;
    $keylessAfter = (int)$db->query("SELECT COUNT(*) FROM kernel_idempotency_keys WHERE idempotency_key_hash = " . $db->quote(hash('sha256', $prefix . '.keyless')))->fetchColumn();
    ihat('keyless adopter path executes normally without primitive storage', $keylessExecutions === 1 && $keylessAfter === $keylessBefore);
} finally {
    foreach (array_unique($keys) as $cleanupKey) {
        $db->prepare('DELETE FROM kernel_idempotency_keys WHERE idempotency_key_hash = :hash')->execute([
            ':hash' => hash('sha256', $cleanupKey),
        ]);
    }
}

$appLog = @file_get_contents($logDirectory . '/app.log') ?: '';
$errorLog = @file_get_contents($logDirectory . '/error.log') ?: '';
ihat('app.log remains clean', trim($appLog) === '', trim($appLog));
ihat('error.log remains clean', trim($errorLog) === '', trim($errorLog));

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}
// PHPStan cannot infer mutations made through the procedural assertion helper.
// @phpstan-ignore-next-line
exit($fail > 0 ? 1 : 0);
