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

// A templated declaration must resolve for a concrete URI, not just the literal
// pattern — the edit form is a {slug} route, so the declaration is only useful
// if the resolver matches a real request against it.
$editKeys = ['GET /cms-akira-shell/posts/{slug}/edit' => 'akira.post.admin.get@1'];
t(
    'a templated GET declaration resolves for a concrete URI',
    moduleRouteAuthorityResolveKey(
        'GET',
        '/cms-akira-shell/posts/{slug}/edit',
        '/cms-akira-shell/posts/some-post/edit',
        $editKeys
    ) === 'GET /cms-akira-shell/posts/{slug}/edit'
);
t(
    'the templated declaration does not resolve for the list route',
    moduleRouteAuthorityResolveKey(
        'GET',
        null,
        '/cms-akira-shell/posts',
        $editKeys
    ) === null
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

$expectedReads = [
    'GET /cms-akira-shell' => 'akira.post.admin.list@1',
    'GET /cms-akira-shell/posts' => 'akira.post.admin.list@1',
    'GET /cms-akira-shell/posts/new' => 'akira.taxonomy.list@1',
    'GET /cms-akira-shell/posts/{slug}/edit' => 'akira.post.admin.get@1',
    'GET /cms-akira-shell/categories' => 'akira.taxonomy.list@1',
    'GET /cms-akira-shell/content-types' => 'akira.content_type.list@1',
    'GET /cms-akira-shell/permissions' => 'akira.policy.list@1',
    'GET /cms-akira-shell/users' => 'akira.user.list@1',
];
$actualReads = array_filter(
    (array) $shellDeclared,
    static fn ($k) => str_starts_with((string) $k, 'GET'),
    ARRAY_FILTER_USE_KEY
);
t(
    'cms-akira-shell declares exactly the reads backed by matching handler gates and policy rows',
    $actualReads === $expectedReads,
    json_encode($actualReads)
);

// EntityViewResolver, not the compositions handler, is the actual capability
// caller; the composition editor and health handlers call no read capability.
// Entry, denial, and public surfaces remain undeclared for safety.
$excludedReads = [
    'GET /cms-akira-shell/compositions',
    'GET /cms-akira-shell/compositions/{key}/edit',
    'GET /cms-akira-shell/login',
    'GET /cms-akira-shell/forbidden',
    'GET /cms-akira-shell/health',
    'GET /',
    'GET /posts',
    'GET /posts/{slug}',
];
$declaredExcluded = array_values(array_intersect(array_keys($shellDeclared), $excludedReads));
t(
    'non-declarable and protected entry/denial/public surfaces are NOT declared',
    $declaredExcluded === [],
    json_encode($declaredExcluded)
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

// The edit form is the second declared read. Its capability
// (akira.post.admin.get@1) has a policy row, so the guard can actually check
// it, and an unauthorised actor must be refused at dispatch exactly like the
// list. A declared route that is NOT enforced would make the whole slice a
// false positive.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/posts/some-post/edit';
file_put_contents($appLog, '');
ob_start();
$editAllowed = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/posts/{slug}/edit',
    '/cms-akira-shell/posts/some-post/edit',
    null
);
$editBody = (string) ob_get_clean();
$editLogs = (string) @file_get_contents($appLog);

t(
    'a DECLARED edit read with no authority is refused at dispatch',
    $editAllowed === false,
    'guard returned ' . var_export($editAllowed, true)
);
t(
    'the edit refusal is the guard denial (HTTP 403), not an allowed response',
    str_contains($editBody, '403') && $editAllowed === false,
    substr($editBody, 0, 240)
);
t(
    'the edit denial names the required capability in the authority log',
    str_contains($editLogs, 'akira.post.admin.get@1'),
    substr($editLogs, 0, 400)
);
t(
    'the edit refusal is recorded as a denial, not as an observation',
    str_contains($editLogs, 'route.authority.denied') && !str_contains($editLogs, 'route.authority.undeclared'),
    substr($editLogs, 0, 300)
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
