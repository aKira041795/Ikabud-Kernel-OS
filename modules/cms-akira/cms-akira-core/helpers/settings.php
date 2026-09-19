<?php

declare(strict_types=1);

const CAC_SITE_SETTINGS_MODULE = 'cms-akira-core';
const CAC_SITE_SETTING_PUBLIC_ARCHIVE = 'public_posts_archive';
const CAC_SITE_SETTING_PUBLIC_SINGLE = 'public_post_single';
const CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE = 'public_archive_page_size';
const CAC_SITE_SETTING_ARCHIVE_SORT = 'public_archive_sort';

final class CacSiteSettingsException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

/**
 * Single source of truth for Akira site behaviour settings. Every key here is
 * read at render time by a named consumer; a key whose only reader is the
 * settings page is a defect, not a setting.
 *
 * @return array<string,string>
 */
function cacSiteSettingsDefaults(): array
{
    return [
        CAC_SITE_SETTING_PUBLIC_ARCHIVE => 'enabled',
        CAC_SITE_SETTING_PUBLIC_SINGLE => 'enabled',
        CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE => '12',
        CAC_SITE_SETTING_ARCHIVE_SORT => 'newest',
    ];
}

/**
 * Closed value sets for enum settings. Keys absent here are validated by
 * cacSiteSettingNormalize() with their own rule.
 *
 * @return array<string,list<string>>
 */
function cacSiteSettingEnums(): array
{
    return [
        CAC_SITE_SETTING_PUBLIC_ARCHIVE => ['enabled', 'disabled'],
        CAC_SITE_SETTING_PUBLIC_SINGLE => ['enabled', 'disabled'],
        CAC_SITE_SETTING_ARCHIVE_SORT => ['newest', 'oldest'],
    ];
}

/** Human-readable value contract for one key, used in fail-closed errors. */
function cacSiteSettingDescription(string $key): string
{
    return [
        CAC_SITE_SETTING_PUBLIC_ARCHIVE => 'enabled or disabled',
        CAC_SITE_SETTING_PUBLIC_SINGLE => 'enabled or disabled',
        CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE => 'an integer between 1 and 50',
        CAC_SITE_SETTING_ARCHIVE_SORT => 'newest or oldest',
    ][$key] ?? 'a valid value';
}

/**
 * Normalise one raw setting value. Returns null for any value outside the
 * key's closed set so callers can fail closed (write) or fall back to the
 * documented default (read). Blank is always null and is never persisted.
 */
function cacSiteSettingNormalize(string $key, mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $value = trim($raw);
    if ($value === '') {
        return null;
    }
    if ($key === CAC_SITE_SETTING_ARCHIVE_PAGE_SIZE) {
        if (ctype_digit($value) === false) {
            return null;
        }
        $pageSize = (int) $value;
        return $pageSize >= 1 && $pageSize <= 50 ? (string) $pageSize : null;
    }
    $enums = cacSiteSettingEnums();
    return isset($enums[$key]) && in_array($value, $enums[$key], true) ? $value : null;
}

/**
 * Resolve only the settings owned by this surface. Invalid legacy storage is
 * ignored in favour of the documented default; unrecognised storage keys are
 * never projected.
 *
 * @return array<string,string>
 */
function cacSiteSettingsCurrent(?int $tenantId = null, ?PDO $db = null): array
{
    $tenantId ??= (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CacSiteSettingsException('A trusted tenant context is required.', 500);
    }
    if (!function_exists('_readTenantModuleSettingsSingle')) {
        throw new CacSiteSettingsException('Tenant module settings are unavailable.', 503);
    }
    $stored = _readTenantModuleSettingsSingle(CAC_SITE_SETTINGS_MODULE, $tenantId, $db ?? app()->db());
    $current = [];
    foreach (cacSiteSettingsDefaults() as $key => $default) {
        // A stored value outside the documented set fails closed to the default.
        $current[$key] = cacSiteSettingNormalize($key, $stored[$key] ?? null) ?? $default;
    }
    return $current;
}

/** @param array<string,mixed> $submitted
 * @return array<string,string>
 */
function cacSiteSettingsValidate(array $submitted): array
{
    $defaults = cacSiteSettingsDefaults();
    $unknown = array_diff(array_keys($submitted), array_keys($defaults));
    if ($unknown !== []) {
        throw new CacSiteSettingsException('Unknown site setting: ' . (string) reset($unknown) . '.');
    }
    $validated = [];
    foreach ($defaults as $key => $default) {
        // Missing or blank form values mean the documented default; they are
        // never persisted as empty strings.
        if (!array_key_exists($key, $submitted)
            || (is_string($submitted[$key]) && trim($submitted[$key]) === '')) {
            $validated[$key] = $default;
            continue;
        }
        if (!is_string($submitted[$key])) {
            throw new CacSiteSettingsException($key . ' must be ' . cacSiteSettingDescription($key) . '.');
        }
        $normalized = cacSiteSettingNormalize($key, $submitted[$key]);
        if ($normalized === null) {
            throw new CacSiteSettingsException($key . ' must be ' . cacSiteSettingDescription($key) . '.');
        }
        $validated[$key] = $normalized;
    }
    return $validated;
}

/** @return array{id:int,role:string} */
function cacSiteSettingsActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacSiteSettingsException('Authentication required.', 401);
    }
    return ['id' => (int) ($actor['id'] ?? $actor['sub']), 'role' => (string) ($actor['role'] ?? '')];
}

/** @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function cacSiteSettingsMutate(array $payload, ?int $tenantId = null, ?PDO $db = null): array
{
    $actor = cacSiteSettingsActor();
    $allowedTopLevel = ['settings', 'idempotency_key'];
    $unknownTopLevel = array_diff(array_keys($payload), $allowedTopLevel);
    if ($unknownTopLevel !== []) {
        throw new CacSiteSettingsException('Unknown request field: ' . (string) reset($unknownTopLevel) . '.');
    }
    if (!is_array($payload['settings'] ?? null)) {
        throw new CacSiteSettingsException('settings must be an object.');
    }
    $settings = cacSiteSettingsValidate($payload['settings']);
    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacSiteSettingsException('A valid idempotency_key is required.');
    }
    $tenantId ??= (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CacSiteSettingsException('A trusted tenant context is required.', 500);
    }
    $hash = app()->cap()->call('kernel.idempotency.hash@1', [
        'payload' => ['operation' => 'site.settings.update', 'settings' => $settings],
    ], ['caller' => ['module' => CAC_SITE_SETTINGS_MODULE, 'user' => $actor], 'mode' => 'first']);
    if (!is_string($hash)) {
        throw new CacSiteSettingsException('Idempotency hashing unavailable.', 503);
    }

    $db ??= app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $db,
        ], ['caller' => ['module' => CAC_SITE_SETTINGS_MODULE, 'user' => $actor], 'mode' => 'first']);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $db->rollBack();
            throw new CacSiteSettingsException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $db->rollBack();
            throw new CacSiteSettingsException('Idempotent mutation is still processing.', 425);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;
        $old = cacSiteSettingsCurrent($tenantId, $db);
        // Every validated key is written in the same transaction; the whole
        // mutation fails if any single setting write fails.
        foreach ($settings as $settingKey => $settingValue) {
            if (!function_exists('tenantWriteModuleSetting') || !tenantWriteModuleSetting(
                $db,
                $tenantId,
                CAC_SITE_SETTINGS_MODULE,
                $settingKey,
                $settingValue
            )) {
                throw new CacSiteSettingsException('Site setting write failed.', 503);
            }
        }
        $correlationId = function_exists('kernel_request_context_get')
            ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
        $correlationId = $correlationId !== '' ? $correlationId : bin2hex(random_bytes(16));
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAC_SITE_SETTINGS_MODULE,
            'action' => 'akira.site.settings.update',
            'entity_type' => 'site_settings',
            'entity_id' => (string) $tenantId,
            'old_data' => $old,
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId] + $settings,
        ], ['caller' => ['module' => CAC_SITE_SETTINGS_MODULE, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable site settings audit failed.');
        }
        $outcome = ['ok' => true, 'settings' => $settings, 'correlation_id' => $correlationId];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $db,
        ], ['caller' => ['module' => CAC_SITE_SETTINGS_MODULE, 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule('cms-akira-shell');
        }
        return $outcome;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], ['caller' => ['module' => CAC_SITE_SETTINGS_MODULE, 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release remains in progress and therefore fails closed.
            }
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function cac_cap_akira_site_settings_get_1(mixed $payload, string $capabilityId = '', string $caller = ''): array
{
    if ($payload !== null && $payload !== []) {
        throw new CacSiteSettingsException('The settings read accepts no fields.');
    }
    return ['ok' => true, 'settings' => cacSiteSettingsCurrent()];
}

/** Public render-safe projection; it contains no administration metadata. @return array<string,mixed> */
function cac_cap_akira_site_settings_public_1(mixed $payload, string $capabilityId = '', string $caller = ''): array
{
    if ($payload !== null && $payload !== []) {
        throw new CacSiteSettingsException('The public settings read accepts no fields.');
    }
    return ['ok' => true, 'settings' => cacSiteSettingsCurrent()];
}

/** @return array<string,mixed> */
function cac_cap_akira_site_settings_update_1(mixed $payload, string $capabilityId = '', string $caller = ''): array
{
    if (!is_array($payload)) {
        throw new CacSiteSettingsException('payload must be an object.');
    }
    return cacSiteSettingsMutate($payload);
}
