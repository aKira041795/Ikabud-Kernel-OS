<?php

declare(strict_types=1);

function testEnvironmentSkip(string $reason): never
{
    echo 'SKIP: ' . $reason . "\n";
    exit(0);
}

function requireWritableCompiledCache(): void
{
    $cacheDir = dirname(__DIR__, 2) . '/storage/cache/compiled';
    if (!is_dir($cacheDir)) {
        testEnvironmentSkip('compiled template cache directory is missing');
    }

    $probe = tempnam($cacheDir, 'write-probe-');
    if ($probe === false || dirname($probe) !== $cacheDir) {
        if ($probe !== false) {
            @unlink($probe);
        }
        testEnvironmentSkip('compiled template cache is not writable by the current process');
    }

    @unlink($probe);
}

function requireTenantFixture(int $tenantId): void
{
    if (!(bool) app()->config('app.multi_tenant.enabled', false)) {
        return;
    }

    try {
        $database = app()->dbForTenant($tenantId);
    } catch (Throwable) {
        $database = null;
    }

    if ($database === null) {
        testEnvironmentSkip("tenant {$tenantId} has no resolvable database configuration");
    }
}
