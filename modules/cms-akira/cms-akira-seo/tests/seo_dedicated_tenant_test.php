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
    echo "CMS Akira SEO dedicated: BLOCKED — two configured dedicated tenant databases are required\n";
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
    echo "\nCMS Akira SEO dedicated: {$passed} passed, " . (++$failed) . " failed\n";
    exit(1);
}

$manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
$manifest['_path'] = dirname(__DIR__);
tenantSyncModuleMigrations($pdoA, 'cms-akira-seo', $manifest);
tenantSyncModuleMigrations($pdoB, 'cms-akira-seo', $manifest);
$owns = ['cms_akira_seo_metadata'];
$dbA = new ModuleDB($pdoA, 'cms-akira-seo', $owns, $owns);
$dbB = new ModuleDB($pdoB, 'cms-akira-seo', $owns, $owns);
$entityType = 'post';
$entityKey = bin2hex(random_bytes(16));

try {
    foreach ([[$dbA, $tenantA, 'Dedicated A'], [$dbB, $tenantB, 'Dedicated B']] as [$db, $tenant, $title]) {
        $db->prepare('DELETE FROM cms_akira_seo_metadata WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare(
            'INSERT INTO cms_akira_seo_metadata (tenant_id, entity_type, entity_key, title, robots) VALUES (?, ?, ?, ?, ?)'
        )->execute([$tenant, $entityType, $entityKey, $title, 'index, follow']);
    }

    $readA = $dbA->prepare(
        'SELECT title FROM cms_akira_seo_metadata WHERE tenant_id = :tenant AND entity_type = :type AND entity_key = :key LIMIT 1'
    );
    $readA->execute([':tenant' => $tenantA, ':type' => $entityType, ':key' => $entityKey]);
    $readB = $dbB->prepare(
        'SELECT title FROM cms_akira_seo_metadata WHERE tenant_id = :tenant AND entity_type = :type AND entity_key = :key LIMIT 1'
    );
    $readB->execute([':tenant' => $tenantB, ':type' => $entityType, ':key' => $entityKey]);
    $check($readA->fetchColumn() === 'Dedicated A' && $readB->fetchColumn() === 'Dedicated B', 'the same SEO entity keys remain isolated across dedicated databases');

    $wrongTenant = $dbA->prepare('SELECT COUNT(*) FROM cms_akira_seo_metadata WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $wrongTenant->execute([$tenantB, $entityType, $entityKey]);
    $check((int) $wrongTenant->fetchColumn() === 0, 'tenant B predicate cannot expose a row on tenant A connection');

    $tablesA = $pdoA->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name = 'cms_akira_seo_metadata' AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'"
    );
    $tablesB = $pdoB->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name = 'cms_akira_seo_metadata' AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'"
    );
    $check((int) $tablesA->fetchColumn() === 1 && (int) $tablesB->fetchColumn() === 1, 'both dedicated databases run the same MySQL-5.7 storage closure');
} catch (Throwable $error) {
    $check(false, 'dedicated SEO scenario completes', $error->getMessage());
} finally {
    foreach ([[$dbA, $tenantA], [$dbB, $tenantB]] as [$db, $tenant]) {
        $db->prepare('DELETE FROM cms_akira_seo_metadata WHERE tenant_id = ?')->execute([$tenant]);
    }
}

echo "\nCMS Akira SEO dedicated: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
