<?php

declare(strict_types=1);

const CAM_MEDIA_INVALIDATION = 'entity.list.media';
const CAM_MEDIA_MAX_BYTES = 5242880;

/** @return array<string, string> */
function cms_akira_media_capability_handlers(): array
{
    return [
        'akira.media.library@1' => 'cam_cap_akira_media_library_1',
        'akira.media.get@1' => 'cam_cap_akira_media_get_1',
        'akira.media.resolve@1' => 'cam_cap_akira_media_resolve_1',
        'akira.media.upload@1' => 'cam_cap_akira_media_upload_1',
        'akira.media.update@1' => 'cam_cap_akira_media_update_1',
        'akira.media.delete.request@1' => 'cam_cap_akira_media_delete_request_1',
        'akira.media.delete.cancel@1' => 'cam_cap_akira_media_delete_cancel_1',
        'akira.media.delete@1' => 'cam_cap_akira_media_delete_1',
    ];
}

/**
 * Ordered, byte-stable role CSV for media contribution. Workflow owns the
 * definition when its helpers are loaded; the fallback preserves module load
 * independence without adding a cms-akira-workflow dependency.
 */
function camMediaContributionRoleCsv(): string
{
    $canonical = ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin'];
    $derived = function_exists('cawPostLifecycleParticipantRoles')
        ? cawPostLifecycleParticipantRoles()
        : $canonical;
    $roles = [];
    foreach ($derived as $role) {
        $role = trim($role);
        if ($role !== '') {
            $roles[$role] = true;
        }
    }
    if ($roles === []) {
        $roles = array_fill_keys($canonical, true);
    }
    $ordered = array_values(array_filter($canonical, static fn (string $role): bool => isset($roles[$role])));
    $additional = array_values(array_diff(array_keys($roles), $canonical));
    sort($additional, SORT_STRING);
    return implode(',', array_merge($ordered, $additional));
}

/** @return list<array<string, mixed>> */
function camMediaMutationPolicyRows(int $policyVersion = 1): array
{
    $rows = [];
    foreach ([
        'akira.media.upload@1' => camMediaContributionRoleCsv(),
        'akira.media.update@1' => camMediaContributionRoleCsv(),
        'akira.media.delete.request@1' => camMediaContributionRoleCsv(),
        'akira.media.delete.cancel@1' => camMediaContributionRoleCsv(),
        // Finalisation is restricted to the administrator tier: contribution roles
        // may only request. This matches akiraShellIsAdmin() and CAC_AKIRA_ADMIN_ROLES
        // so the presentation gate and the policy row cannot disagree.
        'akira.media.delete@1' => 'admin,administrator,superadmin',
    ] as $capabilityId => $allowedRoles) {
        $rows[] = [
            'policy_version' => $policyVersion,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-media',
            'caller_module' => null,
            'allowed_roles' => $allowedRoles,
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    return $rows;
}

/** Resolve the active declaration version so newly enabled media routes are not inert. */
function camMediaActivePolicyVersion(): int
{
    $resolver = \Ikabud\Kernel\Capabilities\AuthorityScopeResolver::forApplication();
    $scope = $resolver->resolve(\Ikabud\Kernel\Capabilities\AuthorityScopeResolver::WEB, [
        'actor' => app()->user(),
    ]);
    $registry = new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(
        null,
        $scope,
        $resolver,
        $resolver->failureReason() ?? 'missing_tenant_authority_scope'
    );
    $activeRows = $registry->activePolicyRows();
    return $activeRows === [] ? 1 : (int)($activeRows[0]['policy_version'] ?? 1);
}

/** Seed media mutations with contribution authority; delete remains admin-only. */
function camSeedMediaMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope(
        camMediaMutationPolicyRows(camMediaActivePolicyVersion())
    );
}

camSeedMediaMutationPolicies();

/**
 * Seed contributor-facing media reads into the active policy version. The permissions
 * surface can clone version 1, so a fixed-version seed would leave declared
 * media routes without authority in an already-running tenant.
 */
/** @return list<array<string, mixed>> */
function camMediaReadPolicyRows(int $policyVersion): array
{
    $rows = [];
    // An uploader who cannot list or inspect the library cannot manage what
    // they uploaded, so contribution authority necessarily includes reads.
    foreach (['akira.media.library@1', 'akira.media.get@1'] as $capabilityId) {
        $rows[] = [
            'policy_version' => $policyVersion,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-media',
            'caller_module' => null,
            'allowed_roles' => camMediaContributionRoleCsv(),
            'provider_activation_required' => true,
            'requires_protocol' => 'v1',
            'is_active' => true,
        ];
    }
    return $rows;
}

function camSeedMediaReadPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }

    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope(
        camMediaReadPolicyRows(camMediaActivePolicyVersion())
    );
}

camSeedMediaReadPolicies();

function camCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-media');
    if (!$ctx) {
        throw new RuntimeException('CMS Akira Media module context unavailable.');
    }
    return $ctx;
}

function camDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = camCtx()->db();
    return $db;
}

function camMediaTenantId(): int
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new RuntimeException('A trusted tenant context is required.');
    }
    return $tenantId;
}

final class CamMediaMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/** @return array<string, array<int, string>> */
function camMediaMimeAllowlist(): array
{
    return [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];
}

function camMediaExtensionForMime(string $mime): string
{
    $extensions = camMediaMimeAllowlist()[$mime] ?? [];
    return $extensions[0] ?? throw new CamMediaMutationException('mime_type has no canonical storage extension.', 422);
}

function camMediaMime(mixed $value): string
{
    $mime = is_string($value) ? strtolower(trim($value)) : '';
    if ($mime === '' || !isset(camMediaMimeAllowlist()[$mime])) {
        throw new CamMediaMutationException('mime_type is not allowed.', 422);
    }
    return $mime;
}

function camMediaFilename(mixed $value): string
{
    if (!is_string($value)) {
        throw new CamMediaMutationException('filename must be a string.', 422);
    }
    $name = trim($value);
    if ($name === '' || strlen($name) > 255) {
        throw new CamMediaMutationException('filename is invalid.', 422);
    }
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")
        || $name === '.' || $name === '..' || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
        throw new CamMediaMutationException('filename is unsafe.', 422);
    }
    return $name;
}

function camMediaKey(mixed $value, string $field = 'media_key'): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
        throw new CamMediaMutationException("{$field} is invalid.", 422);
    }
    return $value;
}

function camMediaAlt(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new CamMediaMutationException('alt must be a string.', 422);
    }
    $alt = trim($value);
    if (strlen($alt) > 255) {
        throw new CamMediaMutationException('alt is invalid.', 422);
    }
    return $alt;
}

function camMediaDeleteRequestReason(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new CamMediaMutationException('delete_request_reason must be a string.', 422);
    }
    $reason = trim($value);
    if (strlen($reason) > 255) {
        throw new CamMediaMutationException('delete_request_reason is invalid.', 422);
    }
    return $reason !== '' ? $reason : null;
}

function camMediaDimension(mixed $value, string $field): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value)) {
        $dimension = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
        $dimension = (int) $value;
    } else {
        throw new CamMediaMutationException("{$field} must be a non-negative integer.", 422);
    }
    if ($dimension < 0 || $dimension > 100000) {
        throw new CamMediaMutationException("{$field} is outside the supported range.", 422);
    }
    return $dimension;
}

function camMediaExpectedVersion(mixed $value): string
{
    if (!is_string($value)) {
        throw new CamMediaMutationException('expected_updated_at is required.', 422);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CamMediaMutationException('expected_updated_at must use Y-m-d H:i:s.', 422);
    }
    return $value;
}

function camMediaContent(mixed $value): string
{
    if (!is_string($value) || $value === '') {
        throw new CamMediaMutationException('content is required.', 422);
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || $decoded === '') {
        throw new CamMediaMutationException('content must be non-empty base64.', 422);
    }
    if (strlen($decoded) > CAM_MEDIA_MAX_BYTES) {
        throw new CamMediaMutationException('content exceeds the allowed size.', 422);
    }
    return $decoded;
}

function camMediaSniffMime(string $bytes): ?string
{
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
        return 'image/gif';
    }
    if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (str_starts_with($bytes, '%PDF-')) {
        return 'application/pdf';
    }
    return null;
}

function camMediaUrl(string $mediaKey): string
{
    return '/api/v1/cms-akira-media/stream/' . rawurlencode($mediaKey);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function camMediaProject(array $row, bool $detail, bool $includeDeleteRequest = false): array
{
    $projection = [
        'key' => (string) $row['media_key'],
        'filename' => (string) $row['filename'],
        'mime_type' => (string) $row['mime_type'],
        'size_bytes' => (int) $row['size_bytes'],
        'alt' => $row['alt'] !== null ? (string) $row['alt'] : null,
        'width' => $row['width'] !== null ? (int) $row['width'] : null,
        'height' => $row['height'] !== null ? (int) $row['height'] : null,
        'url' => camMediaUrl((string) $row['media_key']),
    ];
    if ($includeDeleteRequest) {
        $projection['delete_requested_at'] = ($row['delete_requested_at'] ?? null) !== null
            ? (string) $row['delete_requested_at'] : null;
        $projection['delete_requested_by'] = ($row['delete_requested_by'] ?? null) !== null
            ? (int) $row['delete_requested_by'] : null;
        $projection['delete_request_reason'] = ($row['delete_request_reason'] ?? null) !== null
            ? (string) $row['delete_request_reason'] : null;
    }
    if ($detail) {
        $projection['created_at'] = (string) $row['created_at'];
        $projection['updated_at'] = (string) $row['updated_at'];
    }
    return $projection;
}

function camMediaStorageRoot(int $tenantId): string
{
    $storage = defined('STORAGE_PATH')
        ? (string) STORAGE_PATH
        : (defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__, 3) . '/storage');

    return rtrim($storage, '/') . '/private/cms-akira-media/tenant_' . $tenantId;
}

/**
 * Server-derived, tenant-scoped relative storage location. The extension comes
 * from the validated MIME allowlist, never from the client-supplied filename.
 */
function camMediaRelativeStoragePath(int $tenantId, string $mediaKey, string $extension): string
{
    // Relative to the tenant media root (storage/private/cms-akira-media/tenant_{id}).
    return $mediaKey . '.' . $extension;
}

/** Write bytes to the tenant media root and return the absolute path. */
function camMediaWriteFile(int $tenantId, string $mediaKey, string $extension, string $bytes): string
{
    $root = camMediaStorageRoot($tenantId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new CamMediaMutationException('Media storage unavailable.', 503);
    }
    $realRoot = realpath($root);
    if ($realRoot === false) {
        throw new CamMediaMutationException('Media storage root is invalid.', 503);
    }
    $target = $realRoot . '/' . $mediaKey . '.' . $extension;
    $temp = $realRoot . '/.' . $mediaKey . '.tmp';
    if (@file_put_contents($temp, $bytes, LOCK_EX) === false) {
        throw new CamMediaMutationException('Media write failed.', 503);
    }
    if (!@rename($temp, $target)) {
        @unlink($temp);
        throw new CamMediaMutationException('Media write failed.', 503);
    }
    return $target;
}

/** Resolve a stored relative path to a verified absolute path inside the tenant root. */
function camMediaAbsolutePath(int $tenantId, string $storagePath): string
{
    if ($storagePath === '' || str_contains($storagePath, "\0") || str_contains($storagePath, '\\')) {
        throw new CamMediaMutationException('Media storage path is invalid.', 422);
    }
    $root = camMediaStorageRoot($tenantId);
    $realRoot = realpath($root);
    $candidate = realpath($root . '/' . ltrim($storagePath, '/'));
    if ($realRoot === false || $candidate === false || $candidate !== $realRoot
        && !str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR)) {
        throw new CamMediaMutationException('Media file not found.', 404);
    }
    return $candidate;
}

/** Best-effort file removal; missing files do not block a DB row delete. */
function camMediaRemoveFile(int $tenantId, string $storagePath): void
{
    try {
        $path = camMediaAbsolutePath($tenantId, $storagePath);
        if (is_file($path)) {
            @unlink($path);
        }
    } catch (CamMediaMutationException) {
        // File already missing: the row removal still proceeds and no orphan remains.
    }
}

/** @return array<string, mixed>|null */
function camMediaFind(string $mediaKey, bool $forUpdate = false): ?array
{
    $sql = 'SELECT media_key, filename, mime_type, size_bytes, storage_path, alt, width, height, '
        . 'delete_requested_at, delete_requested_by, delete_request_reason, created_at, updated_at '
        . 'FROM cms_akira_media WHERE tenant_id = :tenant AND media_key = :key LIMIT 1'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = camDb()->prepare($sql);
    $stmt->execute([':tenant' => camMediaTenantId(), ':key' => $mediaKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed> */
function cam_cap_akira_media_library_1(mixed $payload, string $capabilityId = 'akira.media.library@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    if (is_array($payload) && array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }
    $limit = max(1, min(100, (int) ($payload['limit'] ?? 50)));
    $offset = max(0, (int) ($payload['offset'] ?? 0));
    try {
        $stmt = camDb()->prepare(
            "SELECT media_key, filename, mime_type, size_bytes, alt, width, height,
                    delete_requested_at, delete_requested_by, delete_request_reason, created_at, updated_at
             FROM cms_akira_media
             WHERE tenant_id = :tenant
             ORDER BY created_at DESC, media_key ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([':tenant' => camMediaTenantId()]);
        $rows = array_map(static fn (array $row): array => camMediaProject($row, false, true), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['ok' => true, 'rows' => $rows, 'total' => count($rows)];
    } catch (Throwable) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'Media storage unavailable'];
    }
}

/** @return array<string, mixed> */
function cam_cap_akira_media_get_1(mixed $payload, string $capabilityId = 'akira.media.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context media key is required'];
    }
    try {
        $mediaKey = camMediaKey($payload['media_key'] ?? $payload['key'] ?? null);
        $row = camMediaFind($mediaKey);
        return $row !== null
            ? ['ok' => true, 'data' => camMediaProject($row, true, true)]
            : ['ok' => false, 'error' => 'Media not found'];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Media not found'];
    }
}

/** @return array<string, mixed> */
function cam_cap_akira_media_resolve_1(mixed $payload, string $capabilityId = 'akira.media.resolve@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context media key is required'];
    }
    try {
        $mediaKey = camMediaKey($payload['media_key'] ?? $payload['key'] ?? null);
        $row = camMediaFind($mediaKey);
        return $row !== null
            ? ['ok' => true, 'data' => camMediaProject($row, false)]
            : ['ok' => false, 'error' => 'Media not found'];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Media not found'];
    }
}

/** @return array{id: int, role: string, source?: string} */
function camMediaActor(string $operation): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CamMediaMutationException('Authentication required.', 401);
    }
    // Finalisation stays with the administrator tier; contribution roles may only request.
    $allowedRoles = $operation === 'delete'
        ? ['admin', 'administrator', 'superadmin']
        : explode(',', camMediaContributionRoleCsv());
    if (!in_array((string) ($actor['role'] ?? ''), $allowedRoles, true)) {
        throw new CamMediaMutationException(
            $operation === 'delete' ? 'Administrator role required.' : 'Media contributor role required.',
            403
        );
    }
    return $actor;
}

function camMediaCorrelationId(): string
{
    $context = function_exists('kernel_request_context_get') ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
    return $context !== '' ? $context : bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function camMediaMutate(string $operation, array $payload): array
{
    $actor = camMediaActor($operation);
    $tenantId = camMediaTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CamMediaMutationException('tenant_id is supplied by kernel context.', 422);
    }
    $idempotencyKey = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
        throw new CamMediaMutationException('A valid idempotency_key is required.', 422);
    }

    $allowed = $operation === 'upload'
        ? ['filename', 'mime_type', 'content', 'alt', 'width', 'height']
        : ($operation === 'update'
            ? ['media_key', 'alt', 'width', 'height', 'expected_updated_at']
            : ($operation === 'delete.request'
                ? ['media_key', 'delete_request_reason', 'expected_updated_at']
                : ['media_key', 'expected_updated_at']));
    $input = array_intersect_key($payload, array_flip($allowed));
    $envelope = ['operation' => "media.{$operation}", 'media' => $input];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-media', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CamMediaMutationException('Idempotency hashing unavailable.', 503);
    }

    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    $writtenPath = null;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $pdo,
        ], ['caller' => ['module' => 'cms-akira-media', 'user' => $actor], 'mode' => 'first']);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $pdo->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $pdo->rollBack();
            throw new CamMediaMutationException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $pdo->rollBack();
            throw new CamMediaMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $change = camMediaMutateChange($operation, $input, $tenantId, $actor);
        $writtenPath = $change['written_path'] ?? null;
        $correlationId = camMediaCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-media',
            'action' => 'akira.media.' . $operation,
            'entity_type' => 'media',
            'entity_id' => (string) $change['key'],
            'old_data' => $change['old'],
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId] + $change['new'],
        ], ['caller' => ['module' => 'cms-akira-media', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable media audit failed.');
        }
        $outcome = [
            'ok' => true,
            'operation' => "media.{$operation}",
            'media' => $change['projection'],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $pdo,
        ], ['caller' => ['module' => 'cms-akira-media', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $pdo->commit();
        $publicationUncertain = false;
        if ($operation === 'delete' && isset($change['storage_path'])) {
            camMediaRemoveFile($tenantId, (string) $change['storage_path']);
        }
        return $outcome;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($writtenPath !== null && !$publicationUncertain) {
            @unlink($writtenPath);
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $idempotencyKey,
                    'tenant_id' => $tenantId,
                    'db' => $pdo,
                ], ['caller' => ['module' => 'cms-akira-media', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release stays processing and therefore fails closed.
            }
        }
        throw $error;
    }
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $actor
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>, written_path?: ?string, storage_path?: string}
 */
function camMediaMutateChange(string $operation, array $input, int $tenantId, array $actor): array
{
    return match ($operation) {
        'upload' => camMediaMutateUpload($input, $tenantId),
        'update' => camMediaMutateUpdate($input, $tenantId),
        'delete.request' => camMediaMutateDeleteRequest($input, $tenantId, $actor),
        'delete.cancel' => camMediaMutateDeleteCancel($input, $tenantId, $actor),
        'delete' => camMediaMutateDelete($input, $tenantId),
        default => throw new InvalidArgumentException('Unsupported media mutation.'),
    };
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>, written_path: string}
 */
function camMediaMutateUpload(array $input, int $tenantId): array
{
    $filename = camMediaFilename($input['filename'] ?? null);
    $mime = camMediaMime($input['mime_type'] ?? null);
    $extension = camMediaExtensionForMime($mime);
    $filenameExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($filenameExtension !== '' && !in_array($filenameExtension, camMediaMimeAllowlist()[$mime], true)) {
        throw new CamMediaMutationException('filename extension does not match mime_type.', 422);
    }
    $content = camMediaContent($input['content'] ?? null);
    if (camMediaSniffMime($content) !== $mime) {
        throw new CamMediaMutationException('file content does not match mime_type.', 422);
    }
    $alt = camMediaAlt($input['alt'] ?? null);
    $width = camMediaDimension($input['width'] ?? null, 'width');
    $height = camMediaDimension($input['height'] ?? null, 'height');

    $mediaKey = bin2hex(random_bytes(16));
    $relative = camMediaRelativeStoragePath($tenantId, $mediaKey, $extension);
    $absolute = camMediaWriteFile($tenantId, $mediaKey, $extension, $content);

    $stmt = camDb()->prepare(
        'INSERT INTO cms_akira_media '
        . '(tenant_id, media_key, filename, mime_type, size_bytes, storage_path, alt, width, height) '
        . 'VALUES (:tenant, :media_key, :filename, :mime_type, :size_bytes, :storage_path, :alt, :width, :height)'
    );
    $stmt->execute([
        ':tenant' => $tenantId,
        ':media_key' => $mediaKey,
        ':filename' => $filename,
        ':mime_type' => $mime,
        ':size_bytes' => strlen($content),
        ':storage_path' => $relative,
        ':alt' => $alt,
        ':width' => $width,
        ':height' => $height,
    ]);

    $row = [
        'media_key' => $mediaKey,
        'filename' => $filename,
        'mime_type' => $mime,
        'size_bytes' => strlen($content),
        'storage_path' => $relative,
        'alt' => $alt,
        'width' => $width,
        'height' => $height,
    ];

    return [
        'key' => $mediaKey,
        'old' => null,
        'new' => $row,
        'projection' => camMediaProject($row, false),
        'written_path' => $absolute,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function camMediaMutateUpdate(array $input, int $tenantId): array
{
    $mediaKey = camMediaKey($input['media_key'] ?? null);
    $old = camMediaFind($mediaKey, true);
    if ($old === null) {
        throw new CamMediaMutationException('Media not found.', 404);
    }
    $expected = camMediaExpectedVersion($input['expected_updated_at'] ?? null);
    if ($expected !== (string) $old['updated_at']) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }
    $alt = array_key_exists('alt', $input) ? camMediaAlt($input['alt']) : ($old['alt'] !== null ? (string) $old['alt'] : null);
    $width = array_key_exists('width', $input) ? camMediaDimension($input['width'], 'width') : ($old['width'] !== null ? (int) $old['width'] : null);
    $height = array_key_exists('height', $input) ? camMediaDimension($input['height'], 'height') : ($old['height'] !== null ? (int) $old['height'] : null);

    $stmt = camDb()->prepare(
        'UPDATE cms_akira_media SET alt = :alt, width = :width, height = :height '
        . 'WHERE tenant_id = :tenant AND media_key = :media AND updated_at = :expected'
    );
    $stmt->execute([
        ':alt' => $alt,
        ':width' => $width,
        ':height' => $height,
        ':tenant' => $tenantId,
        ':media' => $mediaKey,
        ':expected' => $expected,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new CamMediaMutationException('Media was not changed or is stale.', 409);
    }

    $row = [
        'media_key' => $mediaKey,
        'filename' => (string) $old['filename'],
        'mime_type' => (string) $old['mime_type'],
        'size_bytes' => (int) $old['size_bytes'],
        'storage_path' => (string) $old['storage_path'],
        'alt' => $alt,
        'width' => $width,
        'height' => $height,
    ];

    return [
        'key' => $mediaKey,
        'old' => $old,
        'new' => $row,
        'projection' => camMediaProject($row, false),
    ];
}

/**
 * Record a reversible request only. No row or file removal is reachable here.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $actor
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function camMediaMutateDeleteRequest(array $input, int $tenantId, array $actor): array
{
    $mediaKey = camMediaKey($input['media_key'] ?? null);
    $old = camMediaFind($mediaKey, true);
    if ($old === null) {
        throw new CamMediaMutationException('Media not found.', 404);
    }
    $expected = camMediaExpectedVersion($input['expected_updated_at'] ?? null);
    if ($old['delete_requested_at'] !== null) {
        return [
            'key' => $mediaKey,
            'old' => $old,
            'new' => [
                'delete_requested_at' => (string) $old['delete_requested_at'],
                'delete_requested_by' => (int) $old['delete_requested_by'],
                'delete_request_reason' => $old['delete_request_reason'],
            ],
            'projection' => camMediaProject($old, false, true),
        ];
    }
    if ($expected !== (string) $old['updated_at']) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }
    $reason = camMediaDeleteRequestReason($input['delete_request_reason'] ?? null);
    $stmt = camDb()->prepare(
        'UPDATE cms_akira_media SET delete_requested_at = CURRENT_TIMESTAMP, '
        . 'delete_requested_by = :actor, delete_request_reason = :reason '
        . 'WHERE tenant_id = :tenant AND media_key = :media AND updated_at = :expected '
        . 'AND delete_requested_at IS NULL'
    );
    $stmt->execute([
        ':actor' => (int) ($actor['id'] ?? $actor['sub'] ?? 0),
        ':reason' => $reason,
        ':tenant' => $tenantId,
        ':media' => $mediaKey,
        ':expected' => $expected,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }
    $row = camMediaFind($mediaKey, true);
    if ($row === null) {
        throw new RuntimeException('Requested media disappeared during mutation.');
    }
    return [
        'key' => $mediaKey,
        'old' => $old,
        'new' => [
            'delete_requested_at' => (string) $row['delete_requested_at'],
            'delete_requested_by' => (int) $row['delete_requested_by'],
            'delete_request_reason' => $row['delete_request_reason'],
        ],
        'projection' => camMediaProject($row, false, true),
    ];
}

/**
 * Clear a request only when called by its original requester or an administrator.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $actor
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function camMediaMutateDeleteCancel(array $input, int $tenantId, array $actor): array
{
    $mediaKey = camMediaKey($input['media_key'] ?? null);
    $old = camMediaFind($mediaKey, true);
    if ($old === null) {
        throw new CamMediaMutationException('Media not found.', 404);
    }
    $expected = camMediaExpectedVersion($input['expected_updated_at'] ?? null);
    if ($old['delete_requested_at'] === null) {
        return [
            'key' => $mediaKey,
            'old' => $old,
            'new' => ['delete_requested_at' => null, 'delete_requested_by' => null, 'delete_request_reason' => null],
            'projection' => camMediaProject($old, false, true),
        ];
    }
    $role = (string) ($actor['role'] ?? '');
    $isAdmin = in_array($role, ['admin', 'administrator', 'superadmin'], true);
    if (!$isAdmin && (int) $old['delete_requested_by'] !== (int) ($actor['id'] ?? $actor['sub'] ?? 0)) {
        throw new CamMediaMutationException('Only the original requester or an administrator may cancel this request.', 403);
    }
    if ($expected !== (string) $old['updated_at']) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }
    $stmt = camDb()->prepare(
        'UPDATE cms_akira_media SET delete_requested_at = NULL, delete_requested_by = NULL, '
        . 'delete_request_reason = NULL WHERE tenant_id = :tenant AND media_key = :media '
        . 'AND updated_at = :expected AND delete_requested_at IS NOT NULL'
    );
    $stmt->execute([':tenant' => $tenantId, ':media' => $mediaKey, ':expected' => $expected]);
    if ($stmt->rowCount() !== 1) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }
    $row = camMediaFind($mediaKey, true);
    if ($row === null) {
        throw new RuntimeException('Cancelled media disappeared during mutation.');
    }
    return [
        'key' => $mediaKey,
        'old' => $old,
        'new' => ['delete_requested_at' => null, 'delete_requested_by' => null, 'delete_request_reason' => null],
        'projection' => camMediaProject($row, false, true),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>, storage_path: string}
 */
function camMediaMutateDelete(array $input, int $tenantId): array
{
    $mediaKey = camMediaKey($input['media_key'] ?? null);
    $old = camMediaFind($mediaKey, true);
    if ($old === null) {
        throw new CamMediaMutationException('Media not found.', 404);
    }
    $expected = camMediaExpectedVersion($input['expected_updated_at'] ?? null);
    if ($expected !== (string) $old['updated_at']) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }
    $stmt = camDb()->prepare(
        'DELETE FROM cms_akira_media WHERE tenant_id = :tenant AND media_key = :media AND updated_at = :expected'
    );
    $stmt->execute([':tenant' => $tenantId, ':media' => $mediaKey, ':expected' => $expected]);
    if ($stmt->rowCount() !== 1) {
        throw new CamMediaMutationException('Media was modified; refresh and retry.', 409);
    }

    return [
        'key' => $mediaKey,
        'old' => $old,
        'new' => ['deleted' => true],
        'projection' => camMediaProject($old, false),
        'storage_path' => (string) $old['storage_path'],
    ];
}

/** @return array<string, mixed> */
function cam_cap_akira_media_upload_1(mixed $payload, string $capabilityId = 'akira.media.upload@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CamMediaMutationException('payload must be an object.');
    }
    return camMediaMutate('upload', $payload);
}

/** @return array<string, mixed> */
function cam_cap_akira_media_update_1(mixed $payload, string $capabilityId = 'akira.media.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CamMediaMutationException('payload must be an object.');
    }
    return camMediaMutate('update', $payload);
}

/** @return array<string, mixed> */
function cam_cap_akira_media_delete_request_1(mixed $payload, string $capabilityId = 'akira.media.delete.request@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CamMediaMutationException('payload must be an object.');
    }
    return camMediaMutate('delete.request', $payload);
}

/** @return array<string, mixed> */
function cam_cap_akira_media_delete_cancel_1(mixed $payload, string $capabilityId = 'akira.media.delete.cancel@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CamMediaMutationException('payload must be an object.');
    }
    return camMediaMutate('delete.cancel', $payload);
}

/** @return array<string, mixed> */
function cam_cap_akira_media_delete_1(mixed $payload, string $capabilityId = 'akira.media.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CamMediaMutationException('payload must be an object.');
    }
    return camMediaMutate('delete', $payload);
}
