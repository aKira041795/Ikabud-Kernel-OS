<?php

/** Executable dedicated-database selection and Builder isolation parity gate. */

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleDB;

$root = dirname(__DIR__, 4);
define('IKABUD_CLI', true);
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/src/helpers/module-migrations.php';
require_once $root . '/tests/_support/env_guard.php';

requireTwoDistinctDedicatedTenantDatabases();

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    $ok ? ++$passed : ++$failed;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};
$connections = app()->controlDb()->query('SELECT tenant_id FROM kernel_tenant_db_connections ORDER BY tenant_id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($connections) < 2) {
    echo "CMS Akira Builder dedicated: BLOCKED — two configured dedicated tenant databases are required\n";
    exit(2);
}
$tenantA = (int) $connections[0];
$tenantB = (int) $connections[1];
$pdoA = app()->dbForTenant($tenantA);
$pdoB = app()->dbForTenant($tenantB);
$base = (string) app()->db()->query('SELECT DATABASE()')->fetchColumn();
$nameA = $pdoA instanceof PDO ? (string) $pdoA->query('SELECT DATABASE()')->fetchColumn() : '';
$nameB = $pdoB instanceof PDO ? (string) $pdoB->query('SELECT DATABASE()')->fetchColumn() : '';
$check($pdoA instanceof PDO && $pdoB instanceof PDO, 'Kernel selects both configured tenant connections');
$check($nameA !== '' && $nameB !== '' && $nameA !== $base && $nameB !== $base && $nameA !== $nameB, 'dedicated connections are distinct and reject the base database');
if (!$pdoA instanceof PDO || !$pdoB instanceof PDO) {
    exit(1);
}
$manifest = kernelReadJsonFile(dirname(__DIR__) . '/module.json');
$manifest['_path'] = dirname(__DIR__);
tenantSyncModuleMigrations($pdoA, 'cms-akira-builder', $manifest);
tenantSyncModuleMigrations($pdoB, 'cms-akira-builder', $manifest);
$owns = ['cms_akira_compositions', 'cms_akira_composition_revisions'];
$dbA = new ModuleDB($pdoA, 'cms-akira-builder', $owns, $owns);
$dbB = new ModuleDB($pdoB, 'cms-akira-builder', $owns, $owns);
$key = 'dedicated-' . bin2hex(random_bytes(6));
$tree = '{"version":1,"blocks":[]}';
try {
    foreach ([[$dbA, $tenantA, 'A'], [$dbB, $tenantB, 'B']] as [$db, $tenant, $title]) {
        $db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare("INSERT INTO cms_akira_compositions (tenant_id, entity_type, entity_key, title, tree, status, version) VALUES (?, 'post', ?, ?, ?, 'draft', 1)")->execute([$tenant, $key, 'Dedicated ' . $title, $tree]);
        $composition = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO cms_akira_composition_revisions (tenant_id, composition_id, tree, base, author_id, change_note) VALUES (?, ?, ?, NULL, 1, ?)')->execute([$tenant, $composition, $tree, $title]);
    }
    $readA = $dbA->prepare('SELECT title FROM cms_akira_compositions WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $readA->execute([$tenantA, 'post', $key]);
    $readB = $dbB->prepare('SELECT title FROM cms_akira_compositions WHERE tenant_id = ? AND entity_type = ? AND entity_key = ?');
    $readB->execute([$tenantB, 'post', $key]);
    $check($readA->fetchColumn() === 'Dedicated A' && $readB->fetchColumn() === 'Dedicated B', 'same entity keys remain isolated across dedicated databases');
    $wrong = $dbA->prepare('SELECT COUNT(*) FROM cms_akira_compositions WHERE tenant_id = ? AND entity_key = ?');
    $wrong->execute([$tenantB, $key]);
    $check((int) $wrong->fetchColumn() === 0, 'tenant B predicate exposes no row on tenant A connection');
    $tableA = $pdoA->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cms_akira_compositions','cms_akira_composition_revisions') AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'");
    $tableB = $pdoB->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cms_akira_compositions','cms_akira_composition_revisions') AND engine = 'InnoDB' AND table_collation = 'utf8mb4_unicode_ci'");
    $check((int) $tableA->fetchColumn() === 2 && (int) $tableB->fetchColumn() === 2, 'both dedicated databases run the same MySQL-5.7 storage closure');
    $indexA = $pdoA->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cms_akira_compositions' AND index_name = 'uq_builder_tenant_entity'")->fetchColumn();
    $indexB = $pdoB->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cms_akira_compositions' AND index_name = 'uq_builder_tenant_entity'")->fetchColumn();
    $check($indexA === 'tenant_id,entity_type,entity_key' && $indexB === $indexA, 'both dedicated databases enforce tenant/type/entity uniqueness');
} catch (Throwable $error) {
    $check(false, 'dedicated Builder scenario completes', $error->getMessage());
} finally {
    foreach ([[$dbA, $tenantA], [$dbB, $tenantB]] as [$db, $tenant]) {
        $db->prepare('DELETE FROM cms_akira_composition_revisions WHERE tenant_id = ?')->execute([$tenant]);
        $db->prepare('DELETE FROM cms_akira_compositions WHERE tenant_id = ?')->execute([$tenant]);
    }
}
echo "\nCMS Akira Builder dedicated: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
