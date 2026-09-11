<?php
declare(strict_types=1);

/**
 * APCu key scoping guard.
 *
 * APCu's shared-memory segment belongs to the PHP-FPM pool, not to the virtual
 * host, so any constant key is visible to every Ikabud installation served by
 * that pool. A co-hosted checkout once filled this installation's module-scan
 * key with its own module tree (70 manifests, including modules absent here),
 * which surfaced as `missing_capability_providers` and a 503 on every tenant
 * page — while error.log stayed empty and clearing APCu "fixed" it only until
 * the sibling re-cached.
 *
 * CLI has no APCu segment (`apc.enable_cli=Off`), so this guard is structural:
 * it asserts every APCu key is namespaced, and that the two pre-bootstrap files
 * derive the same namespace without loading a kernel class.
 */
require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  OK $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

$root = realpath(__DIR__ . '/..');
$prefix = \Ikabud\Kernel\Cache::scopedKey('');

echo "=== APCu key scoping ===\n";

t('prefix has the ikabud:<12 hex>: shape', (bool) preg_match('/^ikabud:[0-9a-f]{12}:$/', $prefix), $prefix);
t(
    'prefix derives from the installation root',
    $prefix === 'ikabud:' . substr(sha1((string) realpath(BASE_PATH)), 0, 12) . ':',
    $prefix
);
t('a different root yields a different namespace', $prefix !== 'ikabud:' . substr(sha1($root . '/elsewhere'), 0, 12) . ':');
t('logical keys stay distinct', \Ikabud\Kernel\Cache::scopedKey('a') !== \Ikabud\Kernel\Cache::scopedKey('b'));
t('every key carries the prefix', str_starts_with(\Ikabud\Kernel\Cache::scopedKey('kernel.x'), $prefix));

// The pre-bootstrap files cannot load Cache (no autoloader yet), so they derive
// the namespace locally. Those two derivations must stay identical, otherwise
// the fast path stops sharing keys with the kernel (page-cache version) or
// starts serving under a foreign namespace (health payload).
$helpersDir = realpath(__DIR__ . '/../src/helpers');
$localPrefix = 'ikabud:' . substr(sha1((string) realpath(dirname((string) $helpersDir, 2))), 0, 12) . ':';
t('pre-bootstrap derivation matches the kernel helper', $localPrefix === $prefix, $localPrefix . ' vs ' . $prefix);

// The blocker: the module-scan key must never be used bare again.
$managerSrc = (string) file_get_contents(__DIR__ . '/../src/helpers/module-manager.php');
t(
    'module scan cache key is namespaced',
    str_contains($managerSrc, "scopedKey('kernel.discovered_modules_scan_v1')")
);

// Structural sweep: no literal APCu key anywhere in kernel/ or src/.
// A literal first argument means the key skipped scopedKey().
$offenders = [];
foreach (['kernel', 'src'] as $dir) {
    $base = $root . '/' . $dir;
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $f): bool => !$f->isDir()
        )
    );
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (preg_match('/apcu_(?:fetch|store|delete|add|inc|cas|exists)\s*\(\s*[\'"]/', $src)) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
t('no unscoped literal APCu key remains', $offenders === [], implode(', ', $offenders));

// Pre-bootstrap files must stay free of kernel classes (loading one fatals).
foreach (['src/helpers/fast-path-cache.php', 'src/helpers/fast-path-health.php'] as $relative) {
    $src = (string) file_get_contents($root . '/' . $relative);
    t("$relative does not use the kernel Cache class", !str_contains($src, 'Ikabud\Kernel\Cache'));
    t("$relative derives its own namespace", str_contains($src, 'realpath(dirname(__DIR__, 2))'));
}

printf("\nPASS: %d  FAIL: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
