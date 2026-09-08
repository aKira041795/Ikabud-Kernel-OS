<?php

declare(strict_types=1);

const CAS_SEARCH_MODULE_ID = 'cms-akira-search';
const CAS_SEARCH_INVALIDATION = 'entity.list.search-document';
const CAS_SEARCH_ENTITY_TYPE = 'post';

/** @return array<string, string> */
function cms_akira_search_capability_handlers(): array
{
    return [
        'akira.search.document.build@1' => 'cas_search_cap_document_build_1',
        'akira.search.upsert@1' => 'cas_search_cap_upsert_1',
        'akira.search.delete@1' => 'cas_search_cap_delete_1',
        'akira.search.query@1' => 'cas_search_cap_query_1',
        'akira.search.rebuild@1' => 'cas_search_cap_rebuild_1',
    ];
}

function casSearchSeedMutationPolicies(): void
{
    if (!function_exists('app')) {
        return;
    }
    $rows = [];
    foreach (['akira.search.upsert@1', 'akira.search.delete@1', 'akira.search.rebuild@1'] as $id) {
        $rows[] = [
            'policy_version' => 1,
            'capability_id' => $id,
            'capability_version' => '1',
            'provider' => CAS_SEARCH_MODULE_ID,
            'caller_module' => null,
            'allowed_roles' => 'admin',
            'provider_activation_required' => true,
            'requires_protocol' => 'v2',
            'is_active' => true,
        ];
    }
    (new \Ikabud\Kernel\Capabilities\CapabilityAuthorizationRegistry(app()->db()))->seedPolicy($rows);
}

function casSearchCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $context = module(CAS_SEARCH_MODULE_ID);
    if (!$context) {
        throw new RuntimeException('CMS Akira Search module context unavailable.');
    }
    return $context;
}

function casSearchDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    /** @var \Ikabud\Kernel\Contracts\ModuleDB $database */
    $database = casSearchCtx()->db();
    return $database;
}

function casSearchTenantId(): int
{
    $tenantId = (int) app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new CasSearchException('A trusted tenant context is required.', 500);
    }
    return $tenantId;
}

final class CasSearchException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/**
 * @param array<string, mixed> $value
 * @param list<string> $allowed
 */
function casSearchExactKeys(array $value, array $allowed, string $subject): void
{
    $unknown = array_diff(array_keys($value), $allowed);
    if ($unknown !== []) {
        throw new CasSearchException($subject . ' contains unknown fields: ' . implode(', ', $unknown) . '.');
    }
}

function casSearchEntityType(mixed $value): string
{
    $type = is_string($value) ? trim($value) : '';
    if ($type !== CAS_SEARCH_ENTITY_TYPE) {
        throw new CasSearchException('Only the Akira Post entity type is supported.');
    }
    return $type;
}

function casSearchDocumentKey(mixed $value): string
{
    $key = is_string($value) ? trim($value) : '';
    if ($key === '' || strlen($key) > 190 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) !== 1) {
        throw new CasSearchException('document_key must be a canonical Akira Post slug.');
    }
    return $key;
}

function casSearchText(mixed $value, string $field, int $max, bool $required = false): string
{
    if (!is_string($value)) {
        throw new CasSearchException("{$field} must be a string.");
    }
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    if (($required && $text === '') || strlen($text) > $max) {
        throw new CasSearchException("{$field} is invalid.");
    }
    return $text;
}

/** @return array<string, scalar|null> */
function casSearchMeta(mixed $value): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value)) {
        throw new CasSearchException('meta must be an object.');
    }
    casSearchExactKeys($value, ['url', 'image', 'published_at'], 'meta');
    $meta = [];
    foreach (['url', 'image', 'published_at'] as $key) {
        if (!array_key_exists($key, $value)) {
            continue;
        }
        if (!is_string($value[$key]) && $value[$key] !== null) {
            throw new CasSearchException("meta.{$key} must be a string or null.");
        }
        $text = $value[$key] === null ? null : trim($value[$key]);
        if (is_string($text) && (strlen($text) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $text) === 1)) {
            throw new CasSearchException("meta.{$key} is invalid.");
        }
        $meta[$key] = $text;
    }
    ksort($meta, SORT_STRING);
    return $meta;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function casSearchBuild(array $payload): array
{
    casSearchExactKeys($payload, ['entity_type', 'document_key', 'fields'], 'payload');
    if (array_key_exists('tenant_id', $payload)) {
        throw new CasSearchException('tenant_id is supplied by Kernel context.');
    }
    $type = casSearchEntityType($payload['entity_type'] ?? null);
    $key = casSearchDocumentKey($payload['document_key'] ?? null);
    $fields = $payload['fields'] ?? null;
    if (!is_array($fields)) {
        throw new CasSearchException('fields must be an object.');
    }
    casSearchExactKeys($fields, ['title', 'body', 'summary', 'status', 'meta'], 'fields');
    $status = is_string($fields['status'] ?? null) ? trim($fields['status']) : '';
    if ($status !== 'published') {
        throw new CasSearchException('Only published Akira Posts can be indexed.');
    }
    $body = casSearchText($fields['body'] ?? null, 'body', 16777215, true);
    $summary = array_key_exists('summary', $fields)
        ? casSearchText($fields['summary'], 'summary', 2000)
        : mb_substr($body, 0, 300);

    return [
        'entity_type' => $type,
        'document_key' => $key,
        'title' => casSearchText($fields['title'] ?? null, 'title', 255, true),
        'body' => $body,
        'summary' => $summary,
        'status' => 'published',
        'meta' => casSearchMeta($fields['meta'] ?? null),
    ];
}

/** @return array{id: int, role: string, source?: string} */
function casSearchActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int) ($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CasSearchException('Authentication required.', 401);
    }
    if ((string) ($actor['role'] ?? '') !== 'admin') {
        throw new CasSearchException('Administrator role required.', 403);
    }
    return $actor;
}

function casSearchCorrelationId(): string
{
    $id = function_exists('kernel_request_context_get')
        ? trim((string) kernel_request_context_get('correlation_id', ''))
        : '';
    return $id !== '' ? $id : bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $document
 * @return array<string, mixed>
 */
function casSearchProjection(array $document): array
{
    return [
        'entity_type' => (string) $document['entity_type'],
        'document_key' => (string) $document['document_key'],
        'title' => (string) $document['title'],
        'summary' => (string) $document['summary'],
        'status' => (string) $document['status'],
        'indexed_at' => (string) ($document['indexed_at'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function casSearchApplyChange(string $operation, array $input, int $tenantId): array
{
    if ($operation === 'upsert') {
        $documentPayload = $input['document'] ?? null;
        if (!is_array($documentPayload)) {
            throw new CasSearchException('document must be an object.');
        }
        $document = casSearchBuild($documentPayload);
        $meta = json_encode($document['meta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = casSearchDb()->prepare(
            'INSERT INTO cms_akira_search_documents '
            . '(tenant_id, entity_type, document_key, title, body, summary, status, meta, indexed_at) '
            . 'VALUES (:tenant, :type, :key, :title, :body, :summary, :status, :meta, NOW()) '
            . 'ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), summary = VALUES(summary), '
            . 'status = VALUES(status), meta = VALUES(meta), indexed_at = VALUES(indexed_at)'
        );
        $stmt->execute([
            ':tenant' => $tenantId, ':type' => $document['entity_type'], ':key' => $document['document_key'],
            ':title' => $document['title'], ':body' => $document['body'], ':summary' => $document['summary'],
            ':status' => $document['status'], ':meta' => $meta,
        ]);
        $document['indexed_at'] = date('Y-m-d H:i:s');
        return ['key' => $document['entity_type'] . ':' . $document['document_key'], 'projection' => casSearchProjection($document), 'new' => $document];
    }

    if ($operation === 'delete') {
        $type = casSearchEntityType($input['entity_type'] ?? null);
        $key = casSearchDocumentKey($input['document_key'] ?? null);
        $stmt = casSearchDb()->prepare(
            'DELETE FROM cms_akira_search_documents WHERE tenant_id = :tenant AND entity_type = :type AND document_key = :key'
        );
        $stmt->execute([':tenant' => $tenantId, ':type' => $type, ':key' => $key]);
        return ['key' => $type . ':' . $key, 'projection' => ['entity_type' => $type, 'document_key' => $key, 'deleted' => $stmt->rowCount() > 0], 'new' => ['deleted' => true]];
    }

    if ($operation === 'rebuild') {
        $documents = casSearchPublishedPostDocuments();
        casSearchDb()->prepare('DELETE FROM cms_akira_search_documents WHERE tenant_id = :tenant AND entity_type = :type')
            ->execute([':tenant' => $tenantId, ':type' => CAS_SEARCH_ENTITY_TYPE]);
        foreach ($documents as $document) {
            casSearchApplyChange('upsert', ['document' => $document], $tenantId);
        }
        return ['key' => 'post:*', 'projection' => ['entity_type' => CAS_SEARCH_ENTITY_TYPE, 'indexed' => count($documents), 'removed_stale' => true], 'new' => ['indexed' => count($documents)]];
    }

    throw new InvalidArgumentException('Unsupported search mutation.');
}

/** @return list<array<string, mixed>> */
function casSearchPublishedPostDocuments(): array
{
    $documents = [];
    $offset = 0;
    do {
        $result = app()->cap()->call('akira.post.list@1', [
            'limit' => 50, 'offset' => $offset, 'sort_field' => 'created_at', 'sort_direction' => 'asc',
        ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => app()->user()], 'mode' => 'first']);
        if (!is_array($result) || ($result['ok'] ?? false) !== true || !is_array($result['rows'] ?? null)) {
            throw new CasSearchException('Published Post projection is unavailable.', 503);
        }
        $rows = $result['rows'];
        foreach ($rows as $post) {
            if (!is_array($post)) {
                throw new CasSearchException('Published Post projection is malformed.', 503);
            }
            $documents[] = [
                'entity_type' => CAS_SEARCH_ENTITY_TYPE,
                'document_key' => $post['slug'] ?? null,
                'fields' => [
                    'title' => $post['title'] ?? null,
                    'body' => $post['content'] ?? null,
                    'summary' => $post['subtitle'] ?? '',
                    'status' => $post['status'] ?? null,
                    'meta' => ['image' => $post['image'] ?? null, 'published_at' => $post['published_at'] ?? null, 'url' => isset($post['slug']) ? '/posts/' . $post['slug'] : null],
                ],
            ];
        }
        $offset += count($rows);
    } while (count($rows) === 50);

    usort($documents, static fn (array $a, array $b): int => strcmp((string) $a['document_key'], (string) $b['document_key']));
    return $documents;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function casSearchMutate(string $operation, array $payload): array
{
    $actor = casSearchActor();
    $tenantId = casSearchTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CasSearchException('tenant_id is supplied by Kernel context.');
    }
    casSearchExactKeys($payload, match ($operation) {
        'upsert' => ['idempotency_key', 'document'],
        'delete' => ['idempotency_key', 'entity_type', 'document_key'],
        'rebuild' => ['idempotency_key'],
        default => [],
    }, 'payload');
    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CasSearchException('A valid idempotency_key is required.');
    }
    $input = array_diff_key($payload, ['idempotency_key' => true]);
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => ['operation' => 'search.' . $operation, 'input' => $input]], [
        'caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => $actor], 'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CasSearchException('Idempotency hashing unavailable.', 503);
    }

    $pdo = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $pdo->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'payload_hash' => $hash, 'db' => $pdo,
        ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        $status = is_array($claim) ? (string) ($claim['status'] ?? '') : '';
        if ($status === 'duplicate') {
            $pdo->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($status === 'conflict') {
            $pdo->rollBack();
            throw new CasSearchException('Idempotency key payload conflict.', 409);
        }
        if ($status === 'in_progress') {
            $pdo->rollBack();
            throw new CasSearchException('Idempotent search mutation is still processing.', 425, 2);
        }
        if ($status !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $change = casSearchApplyChange($operation, $input, $tenantId);
        $correlationId = casSearchCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => CAS_SEARCH_MODULE_ID,
            'action' => 'akira.search.' . $operation,
            'entity_type' => 'search-document',
            'entity_id' => (string) $change['key'],
            'old_data' => null,
            'new_data' => ['tenant_id' => $tenantId, 'correlation_id' => $correlationId] + $change['new'],
        ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable search audit failed.');
        }
        $outcome = ['ok' => true, 'operation' => 'search.' . $operation, 'data' => $change['projection'], 'correlation_id' => $correlationId];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key, 'tenant_id' => $tenantId, 'outcome' => $outcome, 'db' => $pdo,
        ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => $actor], 'mode' => 'first']);
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
                app()->cap()->call('kernel.idempotency.release@1', ['key' => $key, 'tenant_id' => $tenantId, 'db' => $pdo], [
                    'caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => $actor], 'mode' => 'first',
                ]);
            } catch (Throwable) {
                // A failed release remains processing and therefore fails closed.
            }
        }
        throw $error;
    }
}

/** @return array<string, mixed> */
function cas_search_cap_document_build_1(mixed $payload, string $capabilityId = 'akira.search.document.build@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    try {
        return ['ok' => true, 'data' => ['document' => casSearchBuild($payload)]];
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => $error instanceof CasSearchException ? $error->getMessage() : 'Search document build failed.'];
    }
}

/** @return array<string, mixed> */
function cas_search_cap_upsert_1(mixed $payload, string $capabilityId = 'akira.search.upsert@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CasSearchException('payload must be an object.');
    }
    return casSearchMutate('upsert', $payload);
}

/** @return array<string, mixed> */
function cas_search_cap_delete_1(mixed $payload, string $capabilityId = 'akira.search.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CasSearchException('payload must be an object.');
    }
    return casSearchMutate('delete', $payload);
}

/** @return array<string, mixed> */
function cas_search_cap_rebuild_1(mixed $payload, string $capabilityId = 'akira.search.rebuild@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CasSearchException('payload must be an object.');
    }
    return casSearchMutate('rebuild', $payload);
}

/** @return array<string, mixed> */
function cas_search_cap_query_1(mixed $payload, string $capabilityId = 'akira.search.query@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => 'payload must be an object'];
    }
    try {
        casSearchExactKeys($payload, ['term', 'entity_type', 'page', 'limit'], 'payload');
        if (array_key_exists('tenant_id', $payload)) {
            throw new CasSearchException('tenant_id is supplied by Kernel context.');
        }
        $term = is_string($payload['term'] ?? null) ? trim($payload['term']) : '';
        if ($term === '' || mb_strlen($term) > 200) {
            throw new CasSearchException('term must be a non-empty string of at most 200 characters.');
        }
        $type = array_key_exists('entity_type', $payload) ? casSearchEntityType($payload['entity_type']) : null;
        $page = filter_var($payload['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $limit = filter_var($payload['limit'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]) ?: 25;
        $offset = ($page - 1) * $limit;
        $pattern = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $term) . '%';
        $where = 'tenant_id = :tenant AND status = \'published\' AND (title LIKE :term_title ESCAPE \'=\' OR body LIKE :term_body ESCAPE \'=\' OR summary LIKE :term_summary ESCAPE \'=\')';
        $params = [':tenant' => casSearchTenantId(), ':term_title' => $pattern, ':term_body' => $pattern, ':term_summary' => $pattern];
        if ($type !== null) {
            $where .= ' AND entity_type = :type';
            $params[':type'] = $type;
        }
        $count = casSearchDb()->prepare('SELECT COUNT(*) FROM cms_akira_search_documents WHERE ' . $where);
        $count->execute($params);
        $stmt = casSearchDb()->prepare(
            'SELECT entity_type, document_key, title, summary, status, indexed_at FROM cms_akira_search_documents WHERE '
            . $where . " ORDER BY indexed_at DESC, document_key ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = array_map('casSearchProjection', $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['ok' => true, 'rows' => $rows, 'total' => (int) $count->fetchColumn(), 'page' => $page, 'limit' => $limit];
    } catch (Throwable $error) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => $error instanceof CasSearchException ? $error->getMessage() : 'Search query unavailable.'];
    }
}

/**
 * Replay-safe consumer for the documented committed Post lifecycle seam.
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function casSearchConsumePostLifecycle(array $event, string $eventName = 'akira.post.lifecycle.committed'): array
{
    casSearchExactKeys($event, ['operation', 'entity_type', 'document_key', 'correlation_id', 'tenant_id'], 'event');
    $tenantId = casSearchTenantId();
    if (array_key_exists('tenant_id', $event) && (int) $event['tenant_id'] !== $tenantId) {
        throw new CasSearchException('Event tenant does not match Kernel context.');
    }
    $operation = is_string($event['operation'] ?? null) ? trim($event['operation']) : '';
    if (!in_array($operation, ['publish', 'unpublish', 'delete'], true)) {
        throw new CasSearchException('Unsupported Post lifecycle operation.');
    }
    casSearchEntityType($event['entity_type'] ?? null);
    $key = casSearchDocumentKey($event['document_key'] ?? null);
    $correlation = is_string($event['correlation_id'] ?? null) ? trim($event['correlation_id']) : '';
    if ($correlation === '' || strlen($correlation) > 128 || preg_match('/^[\x21-\x7e]+$/D', $correlation) !== 1) {
        throw new CasSearchException('A trusted lifecycle correlation id is required.');
    }
    $idempotencyKey = 'search-lifecycle-' . hash('sha256', $tenantId . '|' . $operation . '|' . $key . '|' . $correlation);
    if ($operation !== 'publish') {
        $result = app()->cap()->call('akira.search.delete@1', [
            'idempotency_key' => $idempotencyKey,
            'entity_type' => CAS_SEARCH_ENTITY_TYPE,
            'document_key' => $key,
        ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => app()->user()], 'mode' => 'first']);
        if (!is_array($result)) {
            throw new CasSearchException('Lifecycle delete mutation is unavailable.', 503);
        }
        return $result;
    }

    $post = app()->cap()->call('akira.post.get@1', ['slug' => $key], [
        'caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => app()->user()], 'mode' => 'first',
    ]);
    $row = is_array($post) && ($post['ok'] ?? false) === true && is_array($post['data'] ?? null) ? $post['data'] : null;
    if ($row === null) {
        throw new CasSearchException('Published Post projection is unavailable.', 503);
    }
    $result = app()->cap()->call('akira.search.upsert@1', [
        'idempotency_key' => $idempotencyKey,
        'document' => [
            'entity_type' => CAS_SEARCH_ENTITY_TYPE,
            'document_key' => $key,
            'fields' => [
                'title' => $row['title'] ?? null,
                'body' => $row['content'] ?? null,
                'summary' => $row['subtitle'] ?? '',
                'status' => $row['status'] ?? null,
                'meta' => ['image' => $row['image'] ?? null, 'published_at' => $row['published_at'] ?? null, 'url' => '/posts/' . $key],
            ],
        ],
    ], ['caller' => ['module' => CAS_SEARCH_MODULE_ID, 'user' => app()->user()], 'mode' => 'first']);
    if (!is_array($result)) {
        throw new CasSearchException('Lifecycle upsert mutation is unavailable.', 503);
    }
    return $result;
}

function casSearchRegisterLifecycleConsumer(): void
{
    if (!function_exists('app')) {
        return;
    }
    app()->events()->listen('akira.post.lifecycle.committed', static function (array $payload, string $event): void {
        casSearchConsumePostLifecycle($payload, $event);
    }, 10, CAS_SEARCH_MODULE_ID);
}

casSearchSeedMutationPolicies();
casSearchRegisterLifecycleConsumer();
