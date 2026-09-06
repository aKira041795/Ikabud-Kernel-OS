<?php

/** Functional kernel audit capability and DiSyL renderer regression test. */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
};

$app = app();
$admin = $app->db()->query(
    "SELECT id, username, role FROM users WHERE role IN ('admin', 'superadmin') ORDER BY id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$admin = is_array($admin) ? $admin : ['id' => 0, 'username' => 'System', 'role' => 'admin'];
$app->setUser([
    'id' => (int)$admin['id'],
    'username' => (string)$admin['username'],
    'role' => (string)$admin['role'],
    'source' => 'kernel',
]);

$entityType = 'stabilization_gate1_' . bin2hex(random_bytes(6));
$entityId = 'roundtrip-' . bin2hex(random_bytes(6));
$action = 'stabilization.audit.roundtrip';

try {
    $recorded = $app->cap()->call('kernel.audit.record@1', [
        'module' => '_kernel',
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'new_data' => ['marker' => $entityId],
    ]);
    $assert('kernel.audit.record writes the test row', ($recorded['ok'] ?? false) === true, json_encode($recorded));

    $listed = $app->cap()->call('kernel.audit.list@1', [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'limit' => 5,
    ]);
    $rows = is_array($listed['rows'] ?? null) ? $listed['rows'] : [];
    $row = $rows[0] ?? [];

    $assert('kernel.audit.list returns the matching row', count($rows) === 1, json_encode($listed, JSON_UNESCAPED_SLASHES));
    $assert(
        'audit row round-trips concrete fields and JSON data',
        ($row['entity_type'] ?? null) === $entityType
            && ($row['entity_id'] ?? null) === $entityId
            && ($row['action'] ?? null) === $action
            && ($row['new_data']['marker'] ?? null) === $entityId
            && !empty($row['created_at'])
            && ($row['actor'] ?? null) === (string)$admin['username'],
        json_encode($row, JSON_UNESCAPED_SLASHES)
    );

    $html = $app->templates()->renderString(
        '{ikb_audit_log source="' . $entityType . '" entity_id="' . $entityId . '" limit="5" /}',
        []
    );
    $assert(
        'ComponentRenderer audit_log path renders the real capability row',
        str_contains($html, 'ikb-audit-entry')
            && str_contains($html, htmlspecialchars($action, ENT_QUOTES, 'UTF-8'))
            && !str_contains($html, 'No audit entries found'),
        $html
    );
} catch (Throwable $e) {
    $assert('functional audit path completes without exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    try {
        $cleanup = $app->db()->prepare(
            'DELETE FROM audit_logs WHERE entity_type = :entity_type AND entity_id = :entity_id'
        );
        $cleanup->execute([':entity_type' => $entityType, ':entity_id' => $entityId]);
    } catch (Throwable $e) {
        $assert('functional audit row cleanup succeeds', false, $e->getMessage());
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
