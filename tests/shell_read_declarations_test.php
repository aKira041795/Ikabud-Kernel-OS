<?php

declare(strict_types=1);

require_once __DIR__ . '/../kernel/Workbench/Governance/GovernanceCensus.php';

use Ikabud\Kernel\Workbench\Governance\GovernanceCensus;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$manifestPath = $root . '/modules/cms-akira/cms-akira-shell/module.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$report = (new GovernanceCensus($root))->scan('cms-akira-shell');
$summary = $report['summary'][0] ?? [];

$check((int) ($summary['read_undeclared'] ?? -1) === 0, 'cms-akira-shell must have no undeclared reads');
$check(
    (int) ($summary['read_dispatch_enforced'] ?? -1) + (int) ($summary['read_exempt'] ?? -1)
        === (int) ($summary['read_total'] ?? -2),
    'read_dispatch_enforced + read_exempt must equal read_total'
);

$declarations = is_array($manifest['capabilities']['routes'] ?? null)
    ? $manifest['capabilities']['routes']
    : [];
$exemptionRows = is_array($manifest['governance']['exemptions'] ?? null)
    ? $manifest['governance']['exemptions']
    : [];
$exemptions = [];
foreach ($exemptionRows as $index => $exemption) {
    $check(is_array($exemption), "governance exemption #{$index} must be an object");
    if (!is_array($exemption)) {
        continue;
    }
    $reason = trim((string) ($exemption['reason'] ?? ''));
    $method = strtoupper(trim((string) ($exemption['method'] ?? '')));
    $route = trim((string) ($exemption['route'] ?? ''));
    $check($reason !== '', "governance exemption #{$index} must carry a non-empty reason");
    $check($method !== '' && $route !== '', "governance exemption #{$index} must identify a method and route");
    if ($method !== '' && $route !== '') {
        $key = $method . ' ' . $route;
        $check(!isset($exemptions[$key]), "duplicate governance exemption: {$key}");
        $exemptions[$key] = $reason;
    }
}

foreach ($declarations as $routeKey => $_capabilityId) {
    $check(!isset($exemptions[(string) $routeKey]), "route is both declared and exempt: {$routeKey}");
}

$knownCapabilities = [];
$manifestFiles = array_merge(
    glob($root . '/modules/*/module.json') ?: [],
    glob($root . '/modules/*/*/module.json') ?: []
);
foreach ($manifestFiles as $candidatePath) {
    $candidate = json_decode((string) file_get_contents($candidatePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ((array) ($candidate['capabilities']['exposes'] ?? []) as $expose) {
        $id = is_string($expose) ? $expose : (is_array($expose) ? (string) ($expose['id'] ?? '') : '');
        if ($id !== '') {
            $knownCapabilities[$id] = true;
        }
    }
}

$kernelSource = (string) file_get_contents($root . '/kernel/App.php');
preg_match_all("/\\\$caps->register\\(\\s*['\"]([^'\"]+)['\"]/", $kernelSource, $kernelMatches);
foreach ($kernelMatches[1] ?? [] as $id) {
    $knownCapabilities[(string) $id] = true;
}
foreach ($declarations as $routeKey => $capabilityId) {
    $check(
        is_string($capabilityId) && isset($knownCapabilities[$capabilityId]),
        "{$routeKey} maps to missing capability " . var_export($capabilityId, true)
    );
}

$expectedPublicExemptions = [
    'GET /',
    'GET /posts',
    'GET /posts/{slug}',
    'GET /sitemap.xml',
    'GET /robots.txt',
    'GET /cms-akira-shell/forbidden',
];
foreach ($expectedPublicExemptions as $routeKey) {
    $check(isset($exemptions[$routeKey]), "public route must carry a reasoned exemption: {$routeKey}");
}

$expectedProtectedDeclarations = [
    'GET /cms-akira-shell/redirects' => 'akira.redirect.list@1',
    'GET /cms-akira-shell/compositions' => 'akira.shell.admin_page@1',
    'GET /cms-akira-shell/compositions/{key}/edit' => 'akira.shell.admin_page@1',
    'GET /cms-akira-shell/health' => 'akira.shell.admin_page@1',
];
foreach ($expectedProtectedDeclarations as $routeKey => $capabilityId) {
    $check(
        ($declarations[$routeKey] ?? null) === $capabilityId,
        "protected route must be dispatch-declared as {$capabilityId}: {$routeKey}"
    );
}

echo "cms-akira-shell census:\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "PASS: all shell reads are dispatch-enforced or reason-exempt; declarations resolve and exemptions are reasoned\n";
