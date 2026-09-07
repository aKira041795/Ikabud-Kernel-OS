<?php

/** Executable dedicated-database selection and isolation parity gate. */

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleDB;

$root = dirname(__DIR__, 4);
define('IKABUD_CLI', true);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/src/helpers/module-migrations.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$connections = app()->controlDb()->query(
    'SELECT tenant_id FROM kernel_tenant_db_connections ORDER BY tenant_id ASC LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN);
if (count($connections) < 2) {
    echo "CMS Akira media dedicated: BLOCKED — two configured dedicated tenant databases are required\n";
    exit(2);
}

$tenantA = (int) $connections[0];
$tenantB = (int) $connections[1];
$pdoA = app()->dbForTenant($tenantA);
$pdoB = app()->dbForTenant($tenantB);
$baseName = (string) app()->db()->query('SELECT DATABASE()')->fetchColumn();
$nameA = $pdoA instanceof PDO ? (string) $pdoA->query('SELECT DATABASE()')->fetchColumn() : '';
$nameB = $pdoB instanceof PDO ? (string) $pdoB->query('SELECT DATABASE()')->fetchColumn() : '';
$check($pdoA instanceof PDO && $pdoB instanceof PDO, 'Kernel selects both configured tenant connections');
$check($nameA !== '' && $nameB !== '' && $nameA !== $baseName && $nameB !== $baseName, 'live connected identities reject the Kernel/base database');
$check($nameA !== $nameB, 'dedicated tenants resolve to distinct databases');

if (!$pdoA instanceof PDO || !$pdoB instanceof PDO) {
    echo "\nCMS Akira media dedicated: {$passed} passed, " . (++$failed) . " failed\n";
    exit(1);
}

$manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
$manifest['_path'] = dirname(__DIR__);
tenantSyncModuleMigrations($pdoA, 'cms-akira-media', $manifest);
tenantSyncModuleMigrations($pdoB, 'cms-akira-media', $manifest);
$owns = ['cms_akira_media'];
$dbA = new ModuleDB($pdoA, 'cms-akira-media', $owns, $owns);
$dbB = new ModuleDB($pdoB, 'cms-akira-media', $owns, $owns);
$mediaKey = bin2hex(random_bytes(16));

try {
    foreach ([[$dbA, $tenantA, 'Dedicated A'], [$dbB, $tenantB, 'Dedicated B']] as [$db, $tenant, $filename]) {
        $db->prepare('DELETE FROM cms_akira_media WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare(
            'INSERT INTO cms_akira_media (tenant_id, media_key, filename, mime_type, size_bytes, storage_path) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$tenant, $mediaKey, $filename, 'image/png', 64, 'tenant_' . $tenant . '/' . $mediaKey . '.png']);
    }

    $readA = $dbA->prepare(
        'SELECT filename FROM cms_akira_media WHERE tenant_id = :tenant AND media_key = :key LIMIT 1'
    );
    $readA->execute([':tenant' => $tenantA, ':key' => $mediaKey]);
    $readB = $dbB->prepare(
        'SELECT filename FROM cms_akira_media WHERE tenant_id = :tenant AND media_key = :key LIMIT 1'
    );
    $readB->execute([':tenant' => $tenantB, ':key' => $mediaKey]);
    $check($readA->fetchColumn() === 'Dedicated A' && $readB->fetchColumn() === 'Dedicated B', 'the same media keys remain isolated across dedicated databases');

    $wrongTenant = $dbA->prepare('SELECT COUNT(*) FROM cms_akira_media WHERE tenant_id = ? AND media_key = ?');
    $wrongTenant->execute([$tenantB, $mediaKey]);
    $check((int) $wrongTenant->fetchColumn() === 0, 'tenant B predicate cannot expose a row on tenant A connection');

    $tablesA = $pdoA->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name = 'cms_akira_media' AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'"
    );
    $tablesB = $pdoB->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name = 'cms_akira_media' AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'"
    );
    $check((int) $tablesA->fetchColumn() === 1 && (int) $tablesB->fetchColumn() === 1, 'both dedicated databases run the same MySQL-5.7 storage closure');
} catch (Throwable $error) {
    $check(false, 'dedicated media scenario completes', $error->getMessage());
} finally {
    foreach ([[$dbA, $tenantA], [$dbB, $tenantB]] as [$db, $tenant]) {
        $db->prepare('DELETE FROM cms_akira_media WHERE tenant_id = ?')->execute([$tenant]);
    }
}

echo "\nCMS Akira media dedicated: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
