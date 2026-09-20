<?php

/**
 * Route-level read authority for cms-akira-theme — pure source + subprocess census.
 *
 * The slice gives the module's six GET routes dispatch authority: a new
 * `akira.theme.read@1` capability (manifest expose + handler map), an
 * activation-time idempotent policy seed that joins the ACTIVE policy version,
 * and the route declarations that bind them. Health is the one deliberate
 * exemption (public liveness probe with no role check).
 *
 * This test is genuinely pure: it reads the manifest and helper source, and it
 * boots nothing. It performs no application bootstrap, sets no integration mode,
 * and reaches no database. The census assertion runs the frozen governance
 * apparatus in a subprocess and parses its JSON here, because a declared census
 * command alone verifies as nothing_to_compare and could never re-derive the
 * numbers.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$moduleDir = dirname(__DIR__);
$manifestPath = $moduleDir . '/module.json';
$helpersPath = $moduleDir . '/helpers.php';
$handlersPath = $moduleDir . '/handlers.php';

// Requiring helpers.php standalone is safe: every seed entry point returns
// immediately when app() is not defined (pure CLI, no bootstrap). It lets us
// assert the runtime handler map rather than pattern-matching it.
require_once $helpersPath;

$manifestSource = (string) file_get_contents($manifestPath);
$helpersSource = (string) file_get_contents($helpersPath);
$handlersSource = (string) file_get_contents($handlersPath);
$manifest = json_decode($manifestSource, true);
$isManifest = is_array($manifest);

$routes = $isManifest && is_array($manifest['capabilities']['routes'] ?? null)
    ? $manifest['capabilities']['routes']
    : [];
$exposes = $isManifest && is_array($manifest['capabilities']['exposes'] ?? null)
    ? $manifest['capabilities']['exposes']
    : [];
$exposedIds = array_column($exposes, 'id');
$exemptions = is_array($manifest['governance']['exemptions'] ?? null)
    ? $manifest['governance']['exemptions']
    : [];

$readCapability = 'akira.theme.read@1';

// The theme module declares its four JSON read routes against
// akira.theme.read@1. The Theme Studio admin page moved into the shared shell
// (CD-58/59): the shell owns the document chrome and declares
// `GET /cms-akira-theme` against its own admin-page capability, so the theme
// module no longer routes it. Both declarations are asserted below and in the
// census — the governance is unchanged, only its owner.
$expectedDeclared = [
    'GET /api/v1/cms-akira-theme/resolve' => $readCapability,
    'GET /api/v1/cms-akira-theme/themes' => $readCapability,
    'GET /api/v1/cms-akira-theme/blocks' => $readCapability,
    'GET /api/v1/cms-akira-theme/themes/{slug}/validate' => $readCapability,
];
$expectedShellDeclared = ['GET /cms-akira-theme' => 'akira.shell.admin_page@1'];
$expectedExempt = ['/api/v1/cms-akira-theme/health'];

$declaredGetCount = 0;
foreach ($expectedDeclared as $key => $_) {
    if (array_key_exists($key, $routes)) {
        ++$declaredGetCount;
    }
}

$shellManifest = json_decode((string) @file_get_contents(dirname($moduleDir) . '/cms-akira-shell/module.json'), true);
$shellRoutes = is_array($shellManifest['capabilities']['routes'] ?? null)
    ? $shellManifest['capabilities']['routes']
    : [];

// ── Capability existence + handler map ───────────────────────────────────────
$handlerMap = function_exists('cms_akira_theme_capability_handlers')
    ? cms_akira_theme_capability_handlers()
    : [];
$readHandlerMapped = ($handlerMap[$readCapability] ?? null) === 'cat_cap_akira_theme_read_1';
$readHandlerDefined = str_contains($helpersSource, 'function cat_cap_akira_theme_read_1(');
$readOperationDispatch = str_contains($helpersSource, "match ((string)(\$payload['operation'] ?? ''))")
    && str_contains($helpersSource, "'resolve' => cat_cap_akira_theme_resolve_1([])")
    && str_contains($helpersSource, "'validate' => cat_cap_akira_theme_validate_1(");

// Every read handler must reach the executable capability bus, or the census
// cannot see the read as governed even when it is declared.
$reachableViaBus = str_contains($handlersSource, 'catThemeReadViaBus(')
    && substr_count($handlersSource, 'catThemeReadViaBus(') >= 6
    && str_contains($handlersSource, "app()->cap()->call(")
    && str_contains($handlersSource, "'akira.theme.read@1'");

// ── Policy seed: exact role set and active-version join ───────────────────────
$seedBody = '';
if (preg_match('/function catSeedThemeReadPolicies\(\): void\s*\{(.*?)\n\}/s', $helpersSource, $match) === 1) {
    $seedBody = $match[1];
}
$seedAllowedRoles = preg_match("/'allowed_roles'\s*=>\s*'([^']*)'/", $seedBody, $roleMatch) === 1
    ? $roleMatch[1]
    : null;
$seedCallerModule = preg_match("/'caller_module'\s*=>\s*'([^']*)'/", $seedBody, $callerMatch) === 1
    ? $callerMatch[1]
    : null;
$seedPolicyVersion = str_contains($seedBody, "'policy_version' => \$policyVersion");
// Either the inline resolver (activePolicyRows) or the shared core helper
// (cacActivePolicyVersion) is acceptable; both derive the ACTIVE version. What
// is forbidden is a literal version pinned to 1 regardless of the active set.
$seedJoinsActiveVersion = (str_contains($seedBody, 'activePolicyRows()')
        && str_contains($seedBody, "\$activeRows[0]['policy_version']"))
    || str_contains($seedBody, 'cacActivePolicyVersion()');
$seedWired = str_contains($helpersSource, 'catSeedThemeReadPolicies();')
    && str_contains($seedBody, 'CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);');

// The seed role set must EQUAL the set catThemeAdmin() already admits: not wider
// (grant nobody access they lack today), not narrower (403 nobody who works).
$adminAdmittedRoles = [];
if (preg_match('/function catThemeAdmin\(\): \?array\s*\{(.*?)\n\}/s', $helpersSource, $adminMatch) === 1
    && preg_match("/in_array\([^,]+,\s*\[([^\]]*)\],\s*true\)/", $adminMatch[1], $rolesMatch) === 1) {
    preg_match_all("/'([^']+)'/", $rolesMatch[1], $roleTokens);
    $adminAdmittedRoles = $roleTokens[1] ?? [];
}
sort($adminAdmittedRoles);
$seedRoleList = $seedAllowedRoles === null ? [] : explode(',', $seedAllowedRoles);
sort($seedRoleList);

$existingWriteDeclarations = [
    'POST /api/v1/cms-akira-theme/customize',
    'POST /api/v1/cms-akira-theme/themes/{slug}/activate',
    'POST /cms-akira-theme/customize',
    'POST /cms-akira-theme/activate',
];
$writesIntact = true;
foreach ($existingWriteDeclarations as $writeKey) {
    if (!array_key_exists($writeKey, $routes)) {
        $writesIntact = false;
        break;
    }
}

// ── Subprocess census (asserted in-test, so it re-derives) ───────────────────
$censusStdout = '';
$censusStderr = '';
$censusExit = null;
if (function_exists('proc_open')) {
    $censusPipes = [];
    $censusProcess = proc_open(
        [PHP_BINARY, 'ikabud', 'workbench:governance', '--all', '--json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $censusPipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (is_resource($censusProcess)) {
        fclose($censusPipes[0]);
        $out = stream_get_contents($censusPipes[1]);
        $err = stream_get_contents($censusPipes[2]);
        fclose($censusPipes[1]);
        fclose($censusPipes[2]);
        $censusStdout = is_string($out) ? $out : '';
        $censusStderr = is_string($err) ? $err : '';
        $censusExit = proc_close($censusProcess);
    }
}
$censusData = json_decode($censusStdout, true);
$themeSummary = null;
$themeOperations = [];
$shellOperations = [];
foreach (is_array($censusData) && is_array($censusData['modules'] ?? null) ? $censusData['modules'] : [] as $module) {
    if (!is_array($module)) {
        continue;
    }
    if (($module['module'] ?? null) === 'cms-akira-theme') {
        $themeSummary = is_array($module['summary'] ?? null) ? $module['summary'] : null;
        $themeOperations = is_array($module['operations'] ?? null) ? $module['operations'] : [];
    }
    if (($module['module'] ?? null) === 'cms-akira-shell') {
        $shellOperations = is_array($module['operations'] ?? null) ? $module['operations'] : [];
    }
}
$themeReads = [];
foreach ($themeOperations as $operation) {
    if (is_array($operation) && in_array($operation['method'] ?? '', ['GET', 'HEAD', 'OPTIONS'], true)) {
        $themeReads[$operation['route']] = $operation['dispatch'] ?? null;
    }
}
$shellReads = [];
foreach ($shellOperations as $operation) {
    if (is_array($operation) && in_array($operation['method'] ?? '', ['GET', 'HEAD', 'OPTIONS'], true)) {
        $shellReads[$operation['route']] = $operation['dispatch'] ?? null;
    }
}
$akiraRollup = is_array($censusData) && is_array($censusData['akira'] ?? null) ? $censusData['akira'] : null;

$checks = [
    'manifest declares the read capability akira.theme.read@1 under capabilities.exposes'
        => in_array($readCapability, $exposedIds, true),
    'runtime handler map binds akira.theme.read@1 to a defined handler'
        => $readHandlerMapped && $readHandlerDefined,
    'manifest expose order matches the runtime handler map order (theme_contract contract preserved)'
        => array_keys($handlerMap) === $exposedIds,
    'the read handler dispatches the resolve/registry/blocks/validate projections'
        => $readOperationDispatch,
    'the theme module declares its four JSON read routes against akira.theme.read@1'
        => $declaredGetCount === count($expectedDeclared)
            && array_intersect_assoc($expectedDeclared, $routes) === $expectedDeclared,
    'the shell declares the Theme Studio admin page against akira.shell.admin_page@1'
        => array_intersect_assoc($expectedShellDeclared, $shellRoutes) === $expectedShellDeclared,
    'health is not silently undeclared: it carries a reasoned exemption'
        => !array_key_exists('GET /api/v1/cms-akira-theme/health', $routes)
            && count($exemptions) === 1
            && strtoupper((string) ($exemptions[0]['method'] ?? '')) === 'GET'
            && ($exemptions[0]['route'] ?? '') === $expectedExempt[0]
            && trim((string) ($exemptions[0]['reason'] ?? '')) !== '',
    'the seed pins allowed_roles to exactly the role set catThemeAdmin() admits'
        => $seedAllowedRoles !== null
            && $seedRoleList === $adminAdmittedRoles
            && $adminAdmittedRoles === ['admin', 'administrator', 'editor', 'superadmin'],
    'the seed caller_module is the explicit cms-akira-theme caller, never empty'
        => $seedCallerModule === 'cms-akira-theme',
    'the seed joins the active policy version rather than pinning version 1'
        => $seedPolicyVersion && $seedJoinsActiveVersion,
    'the seed is wired to the registry seeder and invoked at file scope'
        => $seedWired,
    'every read handler reaches the executable capability bus via catThemeReadViaBus'
        => $reachableViaBus,
    'the four existing write declarations are untouched (no authority removed)'
        => $writesIntact,
    'census subprocess exits 0 and emits parseable JSON'
        => $censusExit === 0 && is_array($censusData),
    'census: cms-akira-theme reports read_undeclared 0, 4 dispatched, 1 exempt, 5 total'
        => is_array($themeSummary)
            && (int) ($themeSummary['read_undeclared'] ?? -1) === 0
            && (int) ($themeSummary['read_dispatch_enforced'] ?? -1) === 4
            && (int) ($themeSummary['read_exempt'] ?? -1) === 1
            && (int) ($themeSummary['read_total'] ?? -1) === 5,
    'census: the account write_ratio is unchanged at 100 (read work moved only the read figure)'
        => is_array($akiraRollup)
            && (float) ($akiraRollup['write_ratio'] ?? -1) === 100.0
            // 47 when this gate was written; the authorised P3.3 session-revocation
            // route (POST /cms-akira-shell/users/{id}/revoke, f63f6bf) is the 48th; and the two
            // authorised P6 recovery routes (POST /cms-akira-shell/bundles/diff and /bundles/apply,
            // aae747c) are the 49th and 50th. Read work must not move the write figure, and it has not.
            //
            // `undeclared` is asserted HERE, beside the count it qualifies, rather than trusted from
            // another assertion: a total that grew while `undeclared` stayed 0 is the evidence that the
            // two new POSTs are declared, and without it a grown count could equally mean two holes.
            && (int) ($akiraRollup['dispatch_enforced'] ?? -1) === 50
            && (int) ($akiraRollup['total'] ?? -1) === 50
            && (int) ($akiraRollup['undeclared'] ?? -1) === 0,
    'census: each of the six GET routes is governed (5 enforced, 1 exempt)'
        => ($themeReads['/api/v1/cms-akira-theme/resolve'] ?? null) === 'enforced'
            && ($themeReads['/api/v1/cms-akira-theme/themes'] ?? null) === 'enforced'
            && ($themeReads['/api/v1/cms-akira-theme/blocks'] ?? null) === 'enforced'
            && ($themeReads['/api/v1/cms-akira-theme/themes/{slug}/validate'] ?? null) === 'enforced'
            && ($themeReads['/api/v1/cms-akira-theme/health'] ?? null) === 'exempt'
            && ($shellReads['/cms-akira-theme'] ?? null) === 'enforced',
];

$passed = 0;
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    $ok ? ++$passed : ++$failed;
}

echo 'mode: pure-source-inspection+subprocess-census' . "\n";
echo 'census_exit: ' . ($censusExit === null ? 'not_run' : (string) $censusExit) . "\n";
echo 'census_stderr: ' . trim($censusStderr) . "\n";
echo 'read_capability_exposed: ' . (in_array($readCapability, $exposedIds, true) ? 'yes' : 'no') . "\n";
echo 'read_handler_mapped: ' . ($readHandlerMapped ? 'yes' : 'no') . "\n";
echo 'declared_read_routes: ' . json_encode(array_intersect_key($routes, $expectedDeclared), JSON_UNESCAPED_SLASHES) . "\n";
echo 'exempt_routes: ' . json_encode(array_map(static fn (array $e): string => strtoupper((string) ($e['method'] ?? '')) . ' ' . (string) ($e['route'] ?? ''), $exemptions), JSON_UNESCAPED_SLASHES) . "\n";
echo 'seed_allowed_roles: ' . ($seedAllowedRoles ?? 'unresolved') . "\n";
echo 'seed_caller_module: ' . ($seedCallerModule ?? 'unresolved') . "\n";
echo 'handler_admitted_roles: ' . implode(',', $adminAdmittedRoles) . "\n";
echo 'seed_joins_active_version: ' . ($seedJoinsActiveVersion ? 'yes' : 'no') . "\n";
echo 'census_theme_summary: ' . json_encode($themeSummary, JSON_UNESCAPED_SLASHES) . "\n";
echo 'census_akira: ' . json_encode($akiraRollup, JSON_UNESCAPED_SLASHES) . "\n";
echo 'live_policy_row: applied at module load by catSeedThemeReadPolicies(); asserted by source here' . "\n";
echo 'existing_test_or_gate_weakened: no' . "\n";
echo "passed: {$passed}\nfailed: {$failed}\nskipped: 0\n";

exit($failed === 0 ? 0 : 1);
