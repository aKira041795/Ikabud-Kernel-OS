<?php

declare(strict_types=1);

/**
 * Tenant kernel-artifact regression test.
 *
 * Guards the "module as tenant" provisioning contract:
 *   - a tenant module gets its OWN database (never the kernel DB);
 *   - provisioning must ensure the kernel base tables the module migrations
 *     depend on exist on that tenant DB (users, audit_logs, rate_limits,
 *     refresh_tokens, workflow_*, tenant_module_settings);
 *   - the tenant kernel-artifact list must therefore include the runtime /
 *     module-settings artifacts, not just the users table.
 *
 * Regression: daily-ledger provisioning failed with
 *   "1146 Table '...audit_logs' doesn't exist"
 * because only `users` was ensured on the tenant DB while the module's
 * 019_audit_logs_actor_columns migration ALTERed audit_logs.
 *
 * Run from repo root: php tests/tenant_kernel_artifacts_test.php
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Tenant Kernel Artifacts Test ===\n";

$artifacts = tenantSafeKernelMigrationArtifacts(null);

// 1. Core kernel migrations are present.
foreach ([
    '001_kernel_events_and_triggers.sql',
    '006_kernel_job_queue.sql',
    '010_integration_bridge.sql',
] as $name) {
    t("artifact present: {$name}", isset($artifacts[$name]), 'tenant kernel artifact list must include ' . $name);
}

// 2. The kernel base/runtime tables the module migrations depend on MUST be
//    included so the tenant DB gets audit_logs/rate_limits/refresh_tokens.
t(
    'runtime tables artifact (audit_logs/rate_limits/refresh_tokens)',
    isset($artifacts['007_kernel_runtime_tables.sql']),
    '007_kernel_runtime_tables.sql must be a tenant kernel artifact'
);
t(
    'workflow tables artifact',
    isset($artifacts['006_kernel_workflow_tables.sql']),
    '006_kernel_workflow_tables.sql must be a tenant kernel artifact'
);

// 3. tenant_module_settings must be provisioned on the tenant DB (regression:
//    the barebones kernel omitted it, so tenant:verify flagged it missing).
t(
    'tenant_module_settings artifact',
    isset($artifacts['007_tenant_module_settings.sql']),
    '007_tenant_module_settings.sql must be a tenant kernel artifact'
);

// 4. All declared artifacts must exist on disk (a missing file silently skips
//    the table, recreating the 1146/42S22 class of failures).
$missing = [];
foreach ($artifacts as $name => $path) {
    if (!is_file($path)) {
        $missing[] = $name;
    }
}
t('all tenant kernel artifacts exist on disk', $missing === [], implode(', ', $missing));

// 5. The module-settings table is what the module settings system reads.
t(
    'tenant_module_settings.sql creates the table',
    is_file((string)($artifacts['007_tenant_module_settings.sql'] ?? ''))
    && stripos((string) file_get_contents((string)($artifacts['007_tenant_module_settings.sql'] ?? '')), 'CREATE TABLE IF NOT EXISTS tenant_module_settings') !== false,
    'tenant_module_settings.sql must CREATE TABLE tenant_module_settings'
);

// 6. Auth-owned spec resolver contract (seed path): must handle modules that
//    declare auth_owned without erroring when absent.
if (function_exists('kernelAuthOwnedSpecForModule')) {
    $spec = kernelAuthOwnedSpecForModule('__no_such_module__');
    t('auth-owner spec resolver returns null for unknown module', $spec === null);
} else {
    t('auth-owner spec resolver exists', false, 'kernelAuthOwnedSpecForModule missing');
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
