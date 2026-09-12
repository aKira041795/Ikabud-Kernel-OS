<?php

/**
 * Read authority — does the declaration shape express READ enforcement at all?
 *
 * Measured 2026-09-12: across all 17 module manifests there were 26 declared
 * routes and **zero** GET declarations. The declaration map is keyed
 * "<METHOD> <path>", so the shape permits reads — but nothing had ever declared
 * one, which means read authority was untested rather than merely unused.
 *
 * The claim under test: a route that declares its required authority is checked
 * on ANY method (the guard's own stated contract), so reads are enforceable in
 * exactly the way writes already are.
 *
 * Falsifiers, in order of importance:
 *   1. If the declaration validator drops GET, reads can never be declared.
 *   2. If the guard proceeds for a declared GET with no authority, the guard's
 *      "checked on any method" contract is false.
 *   3. If the guard proceeds for a declared GET *and* for the same route
 *      undeclared, the declaration is not load-bearing and the test proves
 *      nothing — so the negative control below is what makes the positive one
 *      meaningful. Do not remove it.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
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

$appLog = STORAGE_PATH . '/logs/app.log';

echo "\n=== 1. DECLARATION SHAPE ACCEPTS GET ===\n";

// The validator must keep a GET declaration whose capability the module owns.
// If it silently drops it, every later assertion is vacuous.
$manifest = [
    'capabilities' => [
        'exposes' => [['id' => 'demo.thing.read@1']],
        'depends' => [],
        'routes' => [
            'GET /a/list' => 'demo.thing.read@1',
            'get /a/lowercase' => 'demo.thing.read@1',
            'GET /a/unversioned' => 'demo.thing.read',
            'GET /a/not-owned' => 'demo.thing.other@1',
        ],
    ],
];

$declared = moduleRouteAuthorityManifestBlockFromManifest($manifest, 'routes', 'demo');

t(
    'a GET declaration survives the validator',
    isset($declared['GET /a/list']) && $declared['GET /a/list'] === 'demo.thing.read@1',
    json_encode($declared)
);
t(
    'GET method casing is normalised like every other method',
    isset($declared['GET /a/lowercase']),
    json_encode($declared)
);
t(
    'a GET declaration with an unversioned capability is still rejected',
    !isset($declared['GET /a/unversioned']),
    json_encode($declared)
);
t(
    'a GET declaration naming a capability the module does not own is still rejected',
    !isset($declared['GET /a/not-owned']),
    json_encode($declared)
);

echo "\n=== 2. A GET RESOLVES BY KEY ===\n";

$keys = ['GET /cms-akira-shell/posts' => 'akira.post.admin.list@1'];
t(
    'the router pattern resolves for GET',
    moduleRouteAuthorityResolveKey('GET', '/cms-akira-shell/posts', '/cms-akira-shell/posts', $keys)
        === 'GET /cms-akira-shell/posts'
);
t(
    'the wrong method does not resolve against a GET declaration',
    moduleRouteAuthorityResolveKey('POST', '/cms-akira-shell/posts', '/cms-akira-shell/posts', $keys) === null
);

echo "\n=== 3. THE REAL MANIFEST DECLARES THE READ ===\n";

$shellDeclared = moduleRouteAuthorityDeclarations('cms-akira-shell');
t(
    'cms-akira-shell declares authority for its admin post list',
    ($shellDeclared['GET /cms-akira-shell/posts'] ?? null) === 'akira.post.admin.list@1',
    json_encode(array_filter(
        (array) $shellDeclared,
        static fn ($k) => str_starts_with((string) $k, 'GET'),
        ARRAY_FILTER_USE_KEY
    ))
);

echo "\n=== 4. THE GUARD ENFORCES IT (the actual question) ===\n";

$originalServer = $_SERVER;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/posts';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

/**
 * @var list<string> $readLogs
 * @var bool $readAllowed
 */
file_put_contents($appLog, '');
ob_start();
$readAllowed = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/posts',
    '/cms-akira-shell/posts',
    null
);
$readBody = (string) ob_get_clean();
$readLogs = (string) @file_get_contents($appLog);

t(
    'a DECLARED read with no authority is refused at dispatch',
    $readAllowed === false,
    'guard returned ' . var_export($readAllowed, true)
);
t(
    'the refusal is the guard payload, not a handler response',
    str_contains($readBody, 'route_authority_denied') || str_contains($readBody, '403'),
    substr($readBody, 0, 200)
);
t(
    'the refusal is recorded as a denial, not as an observation',
    str_contains($readLogs, 'route.authority.denied') && !str_contains($readLogs, 'route.authority.undeclared'),
    substr($readLogs, 0, 300)
);

echo "\n=== 5. NEGATIVE CONTROL — is the declaration load-bearing? ===\n";

// Same method, same shape, but a route that declares nothing. If this were also
// refused, the positive result above would prove only that the test harness
// refuses things — not that the declaration caused the enforcement.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/health';
file_put_contents($appLog, '');
ob_start();
$undeclaredRead = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/health',
    '/cms-akira-shell/health',
    null
);
ob_end_clean();
$undeclaredLogs = (string) @file_get_contents($appLog);

t(
    'an UNDECLARED read still proceeds (compatibility, not breakage)',
    $undeclaredRead === true,
    'guard returned ' . var_export($undeclaredRead, true)
);
t(
    'the undeclared read is not logged as a denial',
    !str_contains($undeclaredLogs, 'route.authority.denied'),
    substr($undeclaredLogs, 0, 300)
);

// The pair is the actual evidence: identical method and actor, different
// declaration, opposite outcome. That difference is the declaration.
t(
    'the declaration alone decides the outcome (load-bearing)',
    $readAllowed === false && $undeclaredRead === true
);

$_SERVER = $originalServer;

echo "\n=== 6. LOG CHECK ===\n";

$errLog = (string) @file_get_contents(STORAGE_PATH . '/logs/error.log');
t(
    'no PHP fatal/recoverable errors were logged',
    !preg_match('/Fatal error|Uncaught (Error|Exception)|PHP Fatal/i', $errLog),
    substr($errLog, 0, 300)
);

echo "\n=== RESULT ===\n";
echo "  passed: {$pass}\n";
echo "  failed: {$fail}\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

if (ob_get_level() > 0) {
    ob_end_flush();
}

exit($fail === 0 ? 0 : 1);
