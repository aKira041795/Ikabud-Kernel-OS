<?php

declare(strict_types=1);

const CAS_SEO_INVALIDATION = 'entity.list.seo-metadata';

/** @return array<string, string> */
function cms_akira_seo_capability_handlers(): array
{
    return [
        'akira.seo.get@1' => 'cas_cap_akira_seo_get_1',
        'akira.seo.meta.build@1' => 'cas_cap_akira_seo_meta_build_1',
        'akira.seo.content_health@1' => 'cas_cap_akira_seo_content_health_1',
        'akira.seo.upsert@1' => 'cas_cap_akira_seo_upsert_1',
        'akira.seo.delete@1' => 'cas_cap_akira_seo_delete_1',
    ];
}

/** Seed mutation policies and the administrator-only dashboard read. */
function casSeedSeoMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach ([
        'akira.seo.content_health@1',
        'akira.seo.upsert@1',
        'akira.seo.delete@1',
    ] as $capabilityId) {
        $mutation = in_array($capabilityId, ['akira.seo.upsert@1', 'akira.seo.delete@1'], true);
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $capabilityId,
            'capability_version' => '1',
            'provider' => 'cms-akira-seo',
            'caller_module' => null,
            'allowed_roles' => 'admin',
            'provider_activation_required' => true,
            'requires_protocol' => $mutation ? 'v2' : null,
            'is_active' => true,
        ];
    }
    \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry::seedPolicyForCurrentScope($rows);
}

casSeedSeoMutationPolicies();

function casCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-seo');
    if (!$ctx) {
        throw new RuntimeException('CMS Akira SEO module context unavailable.');
    }
    return $ctx;
}

function casDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $db */
    $db = casCtx()->db();
    return $db;
}

function casSeoTenantId(): int
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new RuntimeException('A trusted tenant context is required.');
    }
    return $tenantId;
}

final class CasSeoMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

function casSeoEntityType(mixed $value): string
{
    $type = is_string($value) ? trim($value) : '';
    if ($type === '' || strlen($type) > 64 || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type) !== 1) {
        throw new CasSeoMutationException('entity_type is invalid.');
    }
    return $type;
}

function casSeoEntityKey(mixed $value): string
{
    $key = is_string($value) ? trim($value) : '';
    if ($key === '' || strlen($key) > 190 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) !== 1) {
        throw new CasSeoMutationException('entity_key is invalid.');
    }
    return $key;
}

function casSeoText(mixed $value, string $field, int $max): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new CasSeoMutationException("{$field} must be a string.");
    }
    $text = trim($value);
    if (strlen($text) > $max) {
        throw new CasSeoMutationException("{$field} is invalid.");
    }
    return $text;
}

function casSeoUrl(mixed $value, string $field): ?string
{
    $url = casSeoText($value, $field, 2048);
    if ($url === null) {
        return null;
    }
    if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '\\')) {
        throw new CasSeoMutationException("{$field} contains unsafe characters.");
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new CasSeoMutationException("{$field} must be a local, HTTP, or HTTPS URL.");
    }
    return $url;
}

function casSeoRobots(mixed $value): ?string
{
    $robots = casSeoText($value, 'robots', 255);
    if ($robots === null) {
        return null;
    }
    $allow = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate'];
    $tokens = array_map('trim', explode(',', $robots));
    $normalized = [];
    foreach ($tokens as $token) {
        $lower = strtolower($token);
        if (!in_array($lower, $allow, true)) {
            throw new CasSeoMutationException('robots contains a disallowed directive.');
        }
        $normalized[] = $lower;
    }
    return implode(', ', $normalized);
}

function casSeoExpectedVersion(mixed $value): string
{
    if (!is_string($value)) {
        throw new CasSeoMutationException('expected_updated_at is required.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CasSeoMutationException('expected_updated_at must use Y-m-d H:i:s.');
    }
    return $value;
}

function casSeoEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function casSeoProject(array $row, bool $detail): array
{
    $projection = [
        'entity_type' => (string) $row['entity_type'],
        'entity_key' => (string) $row['entity_key'],
        'title' => $row['title'] !== null ? (string) $row['title'] : null,
        'meta_description' => $row['meta_description'] !== null ? (string) $row['meta_description'] : null,
        'canonical_url' => $row['canonical_url'] !== null ? (string) $row['canonical_url'] : null,
        'robots' => $row['robots'] !== null ? (string) $row['robots'] : null,
        'og_title' => $row['og_title'] !== null ? (string) $row['og_title'] : null,
        'og_description' => $row['og_description'] !== null ? (string) $row['og_description'] : null,
        'og_image' => $row['og_image'] !== null ? (string) $row['og_image'] : null,
    ];
    if ($detail) {
        $projection['created_at'] = (string) $row['created_at'];
        $projection['updated_at'] = (string) $row['updated_at'];
    }
    return $projection;
}

/** @return array<string, mixed>|null */
function casSeoFind(string $entityType, string $entityKey, bool $forUpdate = false): ?array
{
    $sql = 'SELECT entity_type, entity_key, title, meta_description, canonical_url, robots, og_title, og_description, og_image, created_at, updated_at '
        . 'FROM cms_akira_seo_metadata WHERE tenant_id = :tenant AND entity_type = :type AND entity_key = :key LIMIT 1'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = casDb()->prepare($sql);
    $stmt->execute([':tenant' => casSeoTenantId(), ':type' => $entityType, ':key' => $entityKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed> */
function cas_cap_akira_seo_get_1(mixed $payload, string $capabilityId = 'akira.seo.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context entity type and key are required'];
    }
    try {
        $type = casSeoEntityType($payload['entity_type'] ?? null);
        $key = casSeoEntityKey($payload['entity_key'] ?? $payload['key'] ?? null);
        $row = casSeoFind($type, $key);
        return $row !== null
            ? ['ok' => true, 'data' => casSeoProject($row, true)]
            : ['ok' => false, 'error' => 'SEO metadata not found'];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'SEO metadata not found'];
    }
}

/** @return array<string, mixed> */
function cas_cap_akira_seo_content_health_1(mixed $payload, string $capabilityId = 'akira.seo.content_health@1', string $caller = 'unknown'): array
{
    try {
        $slugs = [];
        $offset = 0;
        do {
            $result = app()->cap()->call('akira.post.admin.list@1', [
                'filters' => ['include_unpublished' => true],
                'limit' => 100,
                'offset' => $offset,
            ], ['caller' => ['module' => 'cms-akira-seo', 'user' => app()->user()], 'mode' => 'first']);
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                return ['ok' => false, 'error' => 'Post inventory unavailable'];
            }
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            foreach ($rows as $row) {
                if (is_array($row) && trim((string) ($row['slug'] ?? '')) !== '') {
                    $slugs[(string) $row['slug']] = true;
                }
            }
            $offset += count($rows);
            $total = (int) ($result['total'] ?? count($slugs));
        } while ($rows !== [] && $offset < $total);

        $statement = casDb()->prepare(
            "SELECT entity_key FROM cms_akira_seo_metadata WHERE tenant_id = :tenant AND entity_type = 'post'"
        );
        $statement->execute([':tenant' => casSeoTenantId()]);
        $with = 0;
        while (($key = $statement->fetchColumn()) !== false) {
            $with += isset($slugs[(string) $key]) ? 1 : 0;
        }
        $without = max(0, count($slugs) - $with);
        $html = '<div class="grid grid-cols-2 gap-4"><div><strong class="text-3xl text-emerald-700">'
            . casSeoEscape((string) $with) . '</strong><p class="text-sm text-slate-500">With SEO metadata</p></div>'
            . '<div><strong class="text-3xl text-amber-700">' . casSeoEscape((string) $without)
            . '</strong><p class="text-sm text-slate-500">Without SEO metadata</p></div></div>';

        return ['ok' => true, 'html' => $html];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Content health unavailable'];
    }
}

function casSeoBuildField(mixed $storedValue, mixed $defaultValue, string $field, int $max): ?string
{
    foreach ([$storedValue, $defaultValue] as $value) {
        try {
            $clean = casSeoText($value, $field, $max);
        } catch (Throwable) {
            $clean = null;
        }
        if ($clean !== null) {
            return $clean;
        }
    }
    return null;
}

function casSeoBuildUrlField(mixed $storedValue, mixed $defaultValue, string $field): ?string
{
    foreach ([$storedValue, $defaultValue] as $value) {
        try {
            $clean = casSeoUrl($value, $field);
        } catch (Throwable) {
            $clean = null;
        }
        if ($clean !== null) {
            return $clean;
        }
    }
    return null;
}

function casSeoBuildRobotsField(mixed $storedValue, mixed $defaultValue): ?string
{
    foreach ([$storedValue, $defaultValue] as $value) {
        try {
            $clean = casSeoRobots($value);
        } catch (Throwable) {
            $clean = null;
        }
        if ($clean !== null) {
            return $clean;
        }
    }
    return null;
}

/** @return array<string, mixed> */
function cas_cap_akira_seo_meta_build_1(mixed $payload, string $capabilityId = 'akira.seo.meta.build@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_key_exists('tenant_id', $payload)) {
        return ['ok' => false, 'error' => 'A tenant-context entity type and key are required'];
    }
    try {
        $type = casSeoEntityType($payload['entity_type'] ?? null);
        $key = casSeoEntityKey($payload['entity_key'] ?? $payload['key'] ?? null);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'A tenant-context entity type and key are required'];
    }
    $defaults = is_array($payload['defaults'] ?? null) ? $payload['defaults'] : [];
    if (array_key_exists('tenant_id', $defaults)) {
        return ['ok' => false, 'error' => 'tenant_id is supplied by kernel context'];
    }

    $stored = null;
    try {
        $stored = casSeoFind($type, $key);
    } catch (Throwable) {
        $stored = null;
    }

    $title = casSeoBuildField($stored['title'] ?? null, $defaults['title'] ?? null, 'title', 255);
    $metaDescription = casSeoBuildField($stored['meta_description'] ?? null, $defaults['meta_description'] ?? null, 'meta_description', 500);
    $canonical = casSeoBuildUrlField($stored['canonical_url'] ?? null, $defaults['canonical_url'] ?? null, 'canonical_url');
    $robots = casSeoBuildRobotsField($stored['robots'] ?? null, $defaults['robots'] ?? null);
    $ogTitle = casSeoBuildField($stored['og_title'] ?? null, $defaults['og_title'] ?? null, 'og_title', 255);
    $ogDescription = casSeoBuildField($stored['og_description'] ?? null, $defaults['og_description'] ?? null, 'og_description', 500);
    $ogImage = casSeoBuildUrlField($stored['og_image'] ?? null, $defaults['og_image'] ?? null, 'og_image');

    return [
        'ok' => true,
        'data' => [
            'title' => $title !== null ? casSeoEscape($title) : null,
            'meta_description' => $metaDescription !== null ? casSeoEscape($metaDescription) : null,
            'canonical_url' => $canonical !== null ? casSeoEscape($canonical) : null,
            'robots' => $robots !== null ? casSeoEscape($robots) : null,
            'og_title' => $ogTitle !== null ? casSeoEscape($ogTitle) : null,
            'og_description' => $ogDescription !== null ? casSeoEscape($ogDescription) : null,
            'og_image' => $ogImage !== null ? casSeoEscape($ogImage) : null,
        ],
        'stored' => $stored !== null,
        'resolved_from' => $stored !== null ? 'stored' : 'default',
    ];
}

/** @return array{id: int, role: string, source?: string} */
function casSeoActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CasSeoMutationException('Authentication required.', 401);
    }
    if ((string) ($actor['role'] ?? '') !== 'admin') {
        throw new CasSeoMutationException('Administrator role required.', 403);
    }
    return $actor;
}

function casSeoCorrelationId(): string
{
    $context = function_exists('kernel_request_context_get') ? trim((string) kernel_request_context_get('correlation_id', '')) : '';
    return $context !== '' ? $context : bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function casSeoMutate(string $operation, array $payload): array
{
    $actor = casSeoActor();
    $tenantId = casSeoTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CasSeoMutationException('tenant_id is supplied by kernel context.', 422);
    }
    $idempotencyKey = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
        throw new CasSeoMutationException('A valid idempotency_key is required.', 422);
    }

    $allowed = ['entity_type', 'entity_key', 'title', 'meta_description', 'canonical_url', 'robots', 'og_title', 'og_description', 'og_image', 'expected_updated_at'];
    $input = array_intersect_key($payload, array_flip($allowed));
    $envelope = ['operation' => "seo.{$operation}", 'seo' => $input];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-seo', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CasSeoMutationException('Idempotency hashing unavailable.', 503);
    }

    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $pdo,
        ], ['caller' => ['module' => 'cms-akira-seo', 'user' => $actor], 'mode' => 'first']);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $pdo->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $pdo->rollBack();
            throw new CasSeoMutationException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $pdo->rollBack();
            throw new CasSeoMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $change = casSeoMutateChange($operation, $input, $tenantId);
        $correlationId = casSeoCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-seo',
            'action' => 'akira.seo.' . $operation,
            'entity_type' => 'seo-metadata',
            'entity_id' => (string) $change['key'],
            'old_data' => $change['old'],
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId] + $change['new'],
        ], ['caller' => ['module' => 'cms-akira-seo', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable SEO audit failed.');
        }
        $outcome = [
            'ok' => true,
            'operation' => "seo.{$operation}",
            'seo' => $change['projection'],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $idempotencyKey,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $pdo,
        ], ['caller' => ['module' => 'cms-akira-seo', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }
        $publicationUncertain = true;
        $pdo->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $idempotencyKey,
                    'tenant_id' => $tenantId,
                    'db' => $pdo,
                ], ['caller' => ['module' => 'cms-akira-seo', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release stays processing and therefore fails closed.
            }
        }
        throw $error;
    }
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function casSeoMutateChange(string $operation, array $input, int $tenantId): array
{
    return match ($operation) {
        'upsert' => casSeoMutateUpsert($input, $tenantId),
        'delete' => casSeoMutateDelete($input, $tenantId),
        default => throw new InvalidArgumentException('Unsupported SEO mutation.'),
    };
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function casSeoMutateUpsert(array $input, int $tenantId): array
{
    $type = casSeoEntityType($input['entity_type'] ?? null);
    $key = casSeoEntityKey($input['entity_key'] ?? $input['key'] ?? null);
    $old = casSeoFind($type, $key, true);
    if ($old !== null) {
        $expected = casSeoExpectedVersion($input['expected_updated_at'] ?? null);
        if ($expected !== (string) $old['updated_at']) {
            throw new CasSeoMutationException('SEO metadata was modified; refresh and retry.', 409);
        }
    }

    $title = array_key_exists('title', $input) ? casSeoText($input['title'], 'title', 255) : ($old !== null && $old['title'] !== null ? (string) $old['title'] : null);
    $metaDescription = array_key_exists('meta_description', $input) ? casSeoText($input['meta_description'], 'meta_description', 500) : ($old !== null && $old['meta_description'] !== null ? (string) $old['meta_description'] : null);
    $canonical = array_key_exists('canonical_url', $input) ? casSeoUrl($input['canonical_url'], 'canonical_url') : ($old !== null && $old['canonical_url'] !== null ? (string) $old['canonical_url'] : null);
    $robots = array_key_exists('robots', $input) ? casSeoRobots($input['robots']) : ($old !== null && $old['robots'] !== null ? (string) $old['robots'] : null);
    $ogTitle = array_key_exists('og_title', $input) ? casSeoText($input['og_title'], 'og_title', 255) : ($old !== null && $old['og_title'] !== null ? (string) $old['og_title'] : null);
    $ogDescription = array_key_exists('og_description', $input) ? casSeoText($input['og_description'], 'og_description', 500) : ($old !== null && $old['og_description'] !== null ? (string) $old['og_description'] : null);
    $ogImage = array_key_exists('og_image', $input) ? casSeoUrl($input['og_image'], 'og_image') : ($old !== null && $old['og_image'] !== null ? (string) $old['og_image'] : null);

    if ($old === null) {
        $stmt = casDb()->prepare(
            'INSERT INTO cms_akira_seo_metadata '
            . '(tenant_id, entity_type, entity_key, title, meta_description, canonical_url, robots, og_title, og_description, og_image) '
            . 'VALUES (:tenant, :type, :key, :title, :meta_description, :canonical_url, :robots, :og_title, :og_description, :og_image)'
        );
        try {
            $stmt->execute([
                ':tenant' => $tenantId,
                ':type' => $type,
                ':key' => $key,
                ':title' => $title,
                ':meta_description' => $metaDescription,
                ':canonical_url' => $canonical,
                ':robots' => $robots,
                ':og_title' => $ogTitle,
                ':og_description' => $ogDescription,
                ':og_image' => $ogImage,
            ]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) == 1062) {
                throw new CasSeoMutationException('SEO metadata already exists for this entity.', 409);
            }
            throw $error;
        }
    } else {
        $stmt = casDb()->prepare(
            'UPDATE cms_akira_seo_metadata SET title = :title, meta_description = :meta_description, '
            . 'canonical_url = :canonical_url, robots = :robots, og_title = :og_title, '
            . 'og_description = :og_description, og_image = :og_image '
            . 'WHERE tenant_id = :tenant AND entity_type = :type AND entity_key = :key AND updated_at = :expected'
        );
        $stmt->execute([
            ':title' => $title,
            ':meta_description' => $metaDescription,
            ':canonical_url' => $canonical,
            ':robots' => $robots,
            ':og_title' => $ogTitle,
            ':og_description' => $ogDescription,
            ':og_image' => $ogImage,
            ':tenant' => $tenantId,
            ':type' => $type,
            ':key' => $key,
            ':expected' => $old['updated_at'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new CasSeoMutationException('SEO metadata was not changed or is stale.', 409);
        }
    }

    $row = [
        'entity_type' => $type,
        'entity_key' => $key,
        'title' => $title,
        'meta_description' => $metaDescription,
        'canonical_url' => $canonical,
        'robots' => $robots,
        'og_title' => $ogTitle,
        'og_description' => $ogDescription,
        'og_image' => $ogImage,
    ];

    return [
        'key' => $type . ':' . $key,
        'old' => $old,
        'new' => $row,
        'projection' => casSeoProject($row, false),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{key: string, old: mixed, new: array<string, mixed>, projection: array<string, mixed>}
 */
function casSeoMutateDelete(array $input, int $tenantId): array
{
    $type = casSeoEntityType($input['entity_type'] ?? null);
    $key = casSeoEntityKey($input['entity_key'] ?? $input['key'] ?? null);
    $old = casSeoFind($type, $key, true);
    if ($old === null) {
        throw new CasSeoMutationException('SEO metadata not found.', 404);
    }
    $expected = casSeoExpectedVersion($input['expected_updated_at'] ?? null);
    if ($expected !== (string) $old['updated_at']) {
        throw new CasSeoMutationException('SEO metadata was modified; refresh and retry.', 409);
    }
    $stmt = casDb()->prepare(
        'DELETE FROM cms_akira_seo_metadata WHERE tenant_id = :tenant AND entity_type = :type AND entity_key = :key AND updated_at = :expected'
    );
    $stmt->execute([':tenant' => $tenantId, ':type' => $type, ':key' => $key, ':expected' => $expected]);
    if ($stmt->rowCount() !== 1) {
        throw new CasSeoMutationException('SEO metadata was modified; refresh and retry.', 409);
    }

    return [
        'key' => $type . ':' . $key,
        'old' => $old,
        'new' => ['deleted' => true],
        'projection' => casSeoProject($old, false),
    ];
}

/** @return array<string, mixed> */
function cas_cap_akira_seo_upsert_1(mixed $payload, string $capabilityId = 'akira.seo.upsert@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CasSeoMutationException('payload must be an object.');
    }
    return casSeoMutate('upsert', $payload);
}

/** @return array<string, mixed> */
function cas_cap_akira_seo_delete_1(mixed $payload, string $capabilityId = 'akira.seo.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CasSeoMutationException('payload must be an object.');
    }
    return casSeoMutate('delete', $payload);
}
