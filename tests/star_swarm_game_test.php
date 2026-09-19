<?php

/**
 * Star Swarm — deterministic game contract.
 *
 * Tier A of the objective. It proves, with no database access:
 *   - the module manifest is valid: id, no migrations, no tables, declared route;
 *   - every DiSyL template in the module passes the DiSyL linter;
 *   - the route maps to a handler that actually exists;
 *   - the served markup contains the canvas and both asset references;
 *   - the game JS contains the mechanics it claims (named functions/constants);
 *   - the token contract: every token the JS consumes is injected by the page
 *     and read with a non-empty fallback.
 *
 * The page is delivered as Tier 2 (static public entry rendered from the DiSyL
 * template through the kernel), so the "served markup" check is a live HTTP
 * GET of http://127.0.0.1/star-swarm/ with the tenant Host header. Override
 * with STAR_SWARM_URL for a non-default host.
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/modules/star-swarm';
$templateDir = dirname(__DIR__) . '/templates/modules/star-swarm';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
};

echo "=== module manifest ===\n";
$manifest = json_decode((string) file_get_contents($root . '/module.json'), true);
$check(is_array($manifest), 'module.json is valid JSON');
$check(($manifest['id'] ?? '') === 'star-swarm', 'module id is star-swarm');
$check(($manifest['migrations'] ?? null) === [], 'module declares no migrations');
$check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [], 'module declares no tables');
$check(!isset($manifest['capabilities']['routes']['GET /star-swarm']), 'public route is not capability-declared (dispatch refuses anonymous empty-role callers)');
$exemptions = is_array($manifest['governance']['exemptions'] ?? null) ? $manifest['governance']['exemptions'] : [];
$exemptReasons = [];
foreach ($exemptions as $item) {
    if (is_array($item)) {
        $exemptReasons[strtoupper((string) ($item['method'] ?? '')) . ' ' . (string) ($item['route'] ?? '')] = trim((string) ($item['reason'] ?? ''));
    }
}
$check(($exemptReasons['GET /star-swarm'] ?? '') !== '', 'GET /star-swarm carries a reasoned governance exemption');
$check(($exemptReasons['GET /star-swarm/'] ?? '') !== '', 'GET /star-swarm/ carries a reasoned governance exemption');

echo "\n=== routes and handler ===\n";
$routes = require $root . '/routes.php';
$routeHandler = (string) ($routes['GET']['/star-swarm'] ?? '');
$check($routeHandler === 'star-swarm:starSwarmPage', 'GET /star-swarm maps to star-swarm:starSwarmPage');
$check(($routes['GET']['/star-swarm/'] ?? '') === 'star-swarm:starSwarmPage', 'GET /star-swarm/ maps to the same handler');
require_once $root . '/handlers.php';
$handlerName = str_contains($routeHandler, ':') ? explode(':', $routeHandler, 2)[1] : $routeHandler;
$check(function_exists($handlerName), "handler function {$handlerName}() exists");
$check(function_exists('starSwarmHtml') && function_exists('starSwarmTokenCss') && function_exists('starSwarmTokenDefaults'), 'render and token helpers exist');

echo "\n=== DiSyL templates lint ===\n";
// The linter resolves --path against its own project root, so pass the
// project-relative directory rather than an absolute one.
$lint = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/_lint_disyl.php') . ' --path ' . escapeshellarg('templates/modules/star-swarm') . ' 2>&1');
$check(is_string($lint) && str_contains($lint, '1 file(s) valid'), 'every .disyl in the module directory passes _lint_disyl.php');
$templates = glob($templateDir . '/*.disyl') ?: [];
$check(count($templates) === 1 && basename($templates[0]) === 'star-swarm.disyl', 'the page source is star-swarm.disyl');

echo "\n=== served markup ===\n";
$url = getenv('STAR_SWARM_URL');
if (!is_string($url) || trim($url) === '') {
    $url = 'http://127.0.0.1/star-swarm/';
}
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Host: akiracms.test\r\nUser-Agent: star-swarm-contract-test\r\n",
        'timeout' => 5,
        'ignore_errors' => true,
    ],
]);
$body = @file_get_contents($url, false, $context);
$statusLine = $http_response_header[0] ?? '';
$check(str_contains($statusLine, '200'), "GET {$url} returns HTTP 200 ({$statusLine})");
$check(is_string($body) && str_contains($body, '<canvas id="star-swarm-canvas"'), 'served markup contains the canvas');
$check(is_string($body) && str_contains($body, '/star-swarm/star-swarm.css'), 'served markup references the CSS asset');
$check(is_string($body) && str_contains($body, '/star-swarm/star-swarm.js'), 'served markup references the JS asset');

$staticEntry = dirname(__DIR__) . '/public/star-swarm/index.html';
$check(is_file($staticEntry), 'Tier 2 static entry public/star-swarm/index.html exists');

echo "\n=== game mechanics ===\n";
$js = (string) file_get_contents(dirname(__DIR__) . '/public/star-swarm/star-swarm.js');
foreach ([
    'createFormation' => 'enemy formation is built',
    'updateFormation' => 'formation sways and dives',
    'fireBullet' => 'player fires projectiles',
    'updateEnemyFire' => 'enemies fire back',
    'startWave' => 'cleared formations advance to the next wave',
    'addScore' => 'score is awarded',
    'loseLife' => 'lives are lost on collision',
    'restartGame' => 'game over restarts without a reload',
    'requestAnimationFrame(loop)' => 'the loop is requestAnimationFrame-driven',
    'dt = clamp(dt' => 'delta time is clamped',
    'intersects' => 'collision detection is present',
] as $needle => $label) {
    $check(str_contains($js, $needle), $label . " ({$needle})");
}

echo "\n=== token contract ===\n";
$defaults = starSwarmTokenDefaults();
preg_match_all("/cssVar\\(root,\\s*'--([a-z0-9-]+)'\\s*,\\s*'([^']*)'\\)/", $js, $matches, PREG_SET_ORDER);
$jsTokens = [];
foreach ($matches as $match) {
    $jsTokens[$match[1]] = $match[2];
}
$check($jsTokens !== [], 'the game JS reads design tokens with cssVar(...)');
$missingInPage = [];
$missingFallback = [];
foreach (array_keys($jsTokens) as $token) {
    $name = '--' . $token;
    if (!str_contains((string) $body, $name . ':')) {
        $missingInPage[] = $name;
    }
    if (trim((string) $jsTokens[$token]) === '') {
        $missingFallback[] = $name;
    }
}
$check($missingInPage === [], 'every token the JS consumes is injected by the page: ' . implode(', ', $missingInPage));
$check($missingFallback === [], 'every token the JS consumes has a non-empty fallback: ' . implode(', ', $missingFallback));
$phpTokenNames = array_map(static fn (string $name): string => ltrim($name, '-'), array_keys($defaults));
$notInjected = [];
foreach ($phpTokenNames as $phpToken) {
    if (!str_contains((string) $body, '--' . $phpToken . ':')) {
        $notInjected[] = '--' . $phpToken;
    }
}
$check($notInjected === [], 'the page injects the full PHP token contract: ' . implode(', ', $notInjected));
$jsOnly = array_diff(array_keys($jsTokens), $phpTokenNames);
$check($jsOnly === [], 'every JS-consumed token is part of the PHP token contract: ' . implode(', ', $jsOnly));

echo "\n=== summary ===\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
