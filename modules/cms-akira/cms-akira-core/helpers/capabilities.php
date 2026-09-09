<?php

declare(strict_types=1);

/**
 * The P1 read path remains published-only. P2 adds two governed mutations.
 * EntityViewResolver reaches the bridges through its kernel @1 fallback.
 * Bridges must traverse CapabilityBus; they never query storage.
 */
/**
 * @return array<string, string>
 */
function cms_akira_core_capability_handlers(): array
{
    return [
        'akira.post.get@1' => 'cac_cap_akira_post_get_1',
        'akira.post.list@1' => 'cac_cap_akira_post_list_1',
        'akira.post.create@1' => 'cac_cap_akira_post_create_1',
        'akira.post.update@1' => 'cac_cap_akira_post_update_1',
        'akira.post.publish@1' => 'cac_cap_akira_post_publish_1',
        'akira.post.unpublish@1' => 'cac_cap_akira_post_unpublish_1',
        'akira.post.delete@1' => 'cac_cap_akira_post_delete_1',
        'akira.post.set_taxonomies@1' => 'cac_cap_akira_post_set_taxonomies_1',
        'akira.taxonomy.get@1' => 'cac_cap_akira_taxonomy_get_1',
        'akira.taxonomy.list@1' => 'cac_cap_akira_taxonomy_list_1',
        'akira.taxonomy.create@1' => 'cac_cap_akira_taxonomy_create_1',
        'akira.taxonomy.update@1' => 'cac_cap_akira_taxonomy_update_1',
        'akira.taxonomy.delete@1' => 'cac_cap_akira_taxonomy_delete_1',
        'akira.content_type.get@1' => 'cac_cap_akira_content_type_get_1',
        'akira.content_type.list@1' => 'cac_cap_akira_content_type_list_1',
        'akira.content_type.create@1' => 'cac_cap_akira_content_type_create_1',
        'akira.content_type.update@1' => 'cac_cap_akira_content_type_update_1',
        'akira.content_type.delete@1' => 'cac_cap_akira_content_type_delete_1',
        'entity.list.post@1' => 'cac_cap_entity_list_post_1',
        'entity.get.post@1' => 'cac_cap_entity_get_post_1',
    ];
}

function cacPostTenantId(): int
{
    $tenantId = (int)app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new RuntimeException('A trusted tenant context is required.');
    }
    return $tenantId;
}

function cacPostValidSlug(mixed $value): ?string
{
    $slug = is_string($value) ? trim($value) : '';
    if ($slug === '' || strlen($slug) > 191) {
        return null;
    }

    // One canonical form: lowercase ASCII words separated by one hyphen.
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1 ? $slug : null;
}

function cacPostCanonicalUrl(string $slug): string
{
    $canonical = cacPostValidSlug($slug);
    if ($canonical === null) {
        throw new InvalidArgumentException('Unsafe or non-canonical Post slug.');
    }
    return '/posts/' . rawurlencode($canonical);
}

function cacPostSafeImage(mixed $value): string
{
    $url = is_string($value) ? trim($value) : '';
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        return '';
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) {
        return $url;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
}

/**
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function cacPostProject(array $post, bool $detail): array
{
    $slug = cacPostValidSlug($post['slug'] ?? null);
    if ($slug === null) {
        throw new InvalidArgumentException('Domain Post has an unsafe or non-canonical slug.');
    }

    // Fresh DTO: never mutate/pass through the domain row.
    $dto = [
        'title' => (string)($post['title'] ?? ''),
        'subtitle' => (string)($post['subtitle'] ?? ''),
        'image' => cacPostSafeImage($post['image'] ?? null),
        'metadata' => (string)($post['published_at'] ?? ''),
        'actions' => ['view'],
        'url' => cacPostCanonicalUrl($slug),
    ];
    if ($detail) {
        $dto = [
            'title' => $dto['title'],
            'subtitle' => $dto['subtitle'],
            'image' => $dto['image'],
            'body' => (string)($post['content'] ?? ''),
            'metadata' => $dto['metadata'],
            'actions' => $dto['actions'],
            'url' => $dto['url'],
        ];
    }
    return $dto;
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_post_get_1(mixed $payload, string $capabilityId = 'akira.post.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $slug = cacPostValidSlug($payload['slug'] ?? $payload['id'] ?? null);
    if ($slug === null) {
        return ['ok' => false, 'error' => 'A canonical slug is required'];
    }
    $adminRead = ($payload['include_unpublished'] ?? false) === true && cacPostEditorialParticipant();

    try {
        $statusClause = $adminRead ? '' : " AND status = 'published'";
        $stmt = cacDb()->prepare(
            "SELECT id, tenant_id, slug, title, subtitle, content, image, status, published_at, created_at, updated_at
             FROM cms_akira_posts
             WHERE tenant_id = :tenant_id AND slug = :slug{$statusClause} AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':tenant_id' => cacPostTenantId(), ':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Post not found'];
        }
        // Additive read surface (P1 increment 3): taxonomy assignments ride
        // along on the existing row DTO. Existing members keep their exact
        // shape; taxonomy_ids + categories are new keys only.
        $row += cacPostTaxonomyAssignmentsForSlug($slug);
        return ['ok' => true, 'data' => $row];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Post storage unavailable'];
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_post_list_1(mixed $payload, string $capabilityId = 'akira.post.list@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $payload = is_array($payload) ? $payload : [];
    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
    $sort = is_array($payload['sort'] ?? null) ? $payload['sort'] : [];
    $field = (string)($sort['field'] ?? $payload['sort_field'] ?? 'published_at');
    $direction = strtolower((string)($sort['direction'] ?? $payload['sort_direction'] ?? 'desc'));
    $field = in_array($field, ['published_at', 'created_at', 'title'], true) ? $field : 'published_at';
    $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';
    $limit = max(1, min(50, (int)($payload['limit'] ?? 25)));
    $offset = max(0, (int)($payload['offset'] ?? 0));
    $adminRead = ($payload['include_unpublished'] ?? $filters['include_unpublished'] ?? false) === true
        && cacPostEditorialParticipant();
    $requestedStatus = $payload['status'] ?? $filters['status'] ?? '';
    $status = $adminRead && in_array($requestedStatus, ['draft', 'published'], true)
        ? (string)$requestedStatus : '';
    $search = $adminRead ? trim((string)($payload['search'] ?? $filters['search'] ?? '')) : '';
    $taxonomyId = max(0, (int)($payload['taxonomy_id'] ?? $filters['taxonomy_id'] ?? 0));

    try {
        $tenantId = cacPostTenantId();
        $where = 'tenant_id = :tenant_id AND deleted_at IS NULL';
        $bindings = [':tenant_id' => $tenantId];
        if (!$adminRead) {
            $where .= " AND status = 'published'";
        } elseif ($status !== '') {
            $where .= ' AND status = :status';
            $bindings[':status'] = $status;
        }
        if ($search !== '') {
            // Unique named placeholders: the module DB runs native prepares where
            // one name may not repeat across the clause (title/subtitle/content/slug).
            $where .= ' AND (title LIKE :search_title OR subtitle LIKE :search_subtitle OR content LIKE :search_content OR slug LIKE :search_slug)';
            $like = '%' . $search . '%';
            $bindings[':search_title'] = $like;
            $bindings[':search_subtitle'] = $like;
            $bindings[':search_content'] = $like;
            $bindings[':search_slug'] = $like;
        }
        if ($taxonomyId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM cms_akira_post_taxonomies pt '
                . 'WHERE pt.tenant_id = cms_akira_posts.tenant_id AND pt.post_slug = cms_akira_posts.slug '
                . 'AND pt.taxonomy_id = :taxonomy_id)';
            $bindings[':taxonomy_id'] = $taxonomyId;
        }
        $count = cacDb()->prepare("SELECT COUNT(*) FROM cms_akira_posts WHERE {$where}");
        $count->execute($bindings);
        $sql = "SELECT id, tenant_id, slug, title, subtitle, content, image, status, published_at, created_at, updated_at
                FROM cms_akira_posts WHERE {$where}
                ORDER BY {$field} {$direction}, id {$direction}
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = cacDb()->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Additive read surface (P1 increment 3): decorate each page row with
        // its taxonomy assignments so lists can render category labels/chips.
        if ($rows !== []) {
            $slugs = array_values(array_unique(array_filter(array_map(
                static fn (array $row): string => (string)($row['slug'] ?? ''),
                $rows
            ), static fn (string $slug): bool => $slug !== '')));
            $projected = $slugs !== [] ? cacPostTaxonomyProjection($slugs, $tenantId) : [];
            foreach ($rows as &$row) {
                if (is_array($row)) {
                    $row += $projected[(string)($row['slug'] ?? '')] ?? cacEmptyPostTaxonomyAssignments();
                }
            }
            unset($row);
        }
        return ['ok' => true, 'rows' => $rows, 'total' => (int)$count->fetchColumn()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Post storage unavailable'];
    }
}

final class CacPostMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

function cacPostEditorialParticipant(): bool
{
    $actor = app()->user();
    $roles = function_exists('cawPostLifecycleParticipantRoles')
        ? cawPostLifecycleParticipantRoles()
        : ['admin', 'administrator', 'superadmin'];
    return is_array($actor) && in_array((string) ($actor['role'] ?? ''), $roles, true);
}

/** @return array{id: int, role: string, source?: string} */
function cacPostMutationActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int)($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacPostMutationException('Authentication required.', 401);
    }
    return $actor;
}

function cacPostMutationString(mixed $value, string $field, int $max, bool $required = false): string
{
    if (!is_string($value)) {
        if (!$required && $value === null) {
            return '';
        }
        throw new CacPostMutationException("{$field} must be a string.");
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) {
        throw new CacPostMutationException("{$field} is invalid.");
    }
    return $value;
}

function cacPostMutationPublishedAt(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new CacPostMutationException('published_at must be a datetime string.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CacPostMutationException('published_at must use Y-m-d H:i:s.');
    }
    return $value;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function cacPostMutationFields(array $payload, ?array $existing = null): array
{
    $creating = $existing === null;
    $title = array_key_exists('title', $payload)
        ? cacPostMutationString($payload['title'], 'title', 255, true)
        : (string)($existing['title'] ?? '');
    $content = array_key_exists('content', $payload)
        ? cacPostMutationString($payload['content'], 'content', 65535, true)
        : (string)($existing['content'] ?? '');
    if ($creating && ($title === '' || $content === '')) {
        throw new CacPostMutationException('title and content are required.');
    }

    if (array_key_exists('status', $payload) || array_key_exists('published_at', $payload)) {
        throw new CacPostMutationException('Use the publish or unpublish lifecycle capability to change status.');
    }
    $status = (string)($existing['status'] ?? 'draft');
    $publishedAt = $existing['published_at'] ?? null;

    return [
        'title' => $title,
        'subtitle' => array_key_exists('subtitle', $payload)
            ? cacPostMutationString($payload['subtitle'], 'subtitle', 255)
            : (string)($existing['subtitle'] ?? ''),
        'content' => $content,
        'image' => array_key_exists('image', $payload)
            ? cacPostMutationImage($payload['image'])
            : ($existing['image'] ?? null),
        'status' => $status,
        'published_at' => $publishedAt,
    ];
}

function cacPostMutationImage(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $safe = cacPostSafeImage($value);
    if ($safe === '') {
        throw new CacPostMutationException('image must be an absolute-path, HTTP, or HTTPS URL.');
    }
    return $safe;
}

function cacPostMutationCorrelationId(): string
{
    $fromContext = function_exists('kernel_request_context_get')
        ? trim((string)kernel_request_context_get('correlation_id', ''))
        : '';
    return $fromContext !== '' ? $fromContext : bin2hex(random_bytes(16));
}

/**
 * Execute one mutation on the application PDO. The kernel idempotency and audit
 * capabilities escalate narrowly onto this exact caller-managed transaction.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cacPostMutate(string $operation, array $payload): array
{
    $actor = cacPostMutationActor();
    $tenantId = cacPostTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CacPostMutationException('tenant_id is supplied by kernel context.', 422);
    }
    if (in_array($operation, ['create', 'update'], true)
        && (array_key_exists('status', $payload) || array_key_exists('published_at', $payload))) {
        throw new CacPostMutationException('Use the publish or unpublish lifecycle capability to change status.', 422);
    }
    if (!app()->entityAuthority()->isAuthoritative('post', 'cms-akira-core')) {
        throw new CacPostMutationException('Post authority is not active.', 503);
    }

    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacPostMutationException('A valid idempotency_key is required.', 422);
    }
    $slug = cacPostValidSlug($payload['slug'] ?? null);
    if ($slug === null) {
        throw new CacPostMutationException('A canonical lowercase Post slug is required.', 422);
    }

    // Identity/JWT-shaped payload members are intentionally neither read nor hashed.
    $mutationInput = array_intersect_key($payload, array_flip([
        'slug', 'title', 'subtitle', 'content', 'image', 'expected_updated_at',
    ]));
    $envelope = ['operation' => $operation, 'post' => $mutationInput];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-core', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CacPostMutationException('Idempotency hashing unavailable.', 503);
    }

    $db = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        $claimStatus = is_array($claim) ? (string)($claim['status'] ?? '') : '';
        if ($claimStatus === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($claimStatus === 'conflict') {
            $db->rollBack();
            throw new CacPostMutationException('Idempotency key payload conflict.', 409);
        }
        if ($claimStatus === 'in_progress') {
            $db->rollBack();
            throw new CacPostMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($claimStatus !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $old = null;
        if ($operation !== 'create') {
            $find = $db->prepare(
                'SELECT id, slug, title, subtitle, content, image, status, published_at, updated_at, deleted_at '
                . 'FROM cms_akira_posts WHERE tenant_id = :tenant AND slug = :slug AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $find->execute([':tenant' => $tenantId, ':slug' => $slug]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($old)) {
                throw new CacPostMutationException('Post not found.', 404);
            }
            $expected = cacTaxonomyMutationExpectedAt($mutationInput['expected_updated_at'] ?? null);
            if ($expected === null || $expected !== (string)$old['updated_at']) {
                throw new CacPostMutationException('Post was modified; refresh and retry.', 409);
            }
        }
        $fields = cacPostMutationFields($mutationInput, is_array($old) ? $old : null);

        if ($operation === 'create') {
            $write = $db->prepare(
                'INSERT INTO cms_akira_posts '
                . '(tenant_id, slug, title, subtitle, content, image, status, published_at) '
                . 'VALUES (:tenant, :slug, :title, :subtitle, :content, :image, :status, :published_at)'
            );
            $write->execute(array_merge([':tenant' => $tenantId, ':slug' => $slug], cacPostSqlFields($fields)));
            $postId = (int)$db->lastInsertId();
        } elseif ($operation === 'update') {
            $write = $db->prepare(
                'UPDATE cms_akira_posts SET title = :title, subtitle = :subtitle, content = :content, '
                . 'image = :image WHERE tenant_id = :tenant AND slug = :slug AND updated_at = :expected'
            );
            $write->execute([
                ':tenant' => $tenantId, ':slug' => $slug, ':expected' => $old['updated_at'],
                ':title' => $fields['title'], ':subtitle' => $fields['subtitle'],
                ':content' => $fields['content'], ':image' => $fields['image'],
            ]);
            $postId = (int)$old['id'];
        } else {
            $requiredStatus = $operation === 'publish' ? 'draft' : 'published';
            if ($operation !== 'delete' && (string)$old['status'] !== $requiredStatus) {
                throw new CacPostMutationException("Post cannot {$operation} from its current status.", 409);
            }
            if ($operation === 'delete') {
                $write = $db->prepare(
                    'UPDATE cms_akira_posts SET deleted_at = CURRENT_TIMESTAMP '
                    . 'WHERE tenant_id = :tenant AND slug = :slug AND updated_at = :expected AND deleted_at IS NULL'
                );
                $fields['deleted_at'] = date('Y-m-d H:i:s');
            } else {
                $fields['status'] = $operation === 'publish' ? 'published' : 'draft';
                $fields['published_at'] = $operation === 'publish' ? date('Y-m-d H:i:s') : null;
                $write = $db->prepare(
                    'UPDATE cms_akira_posts SET status = :status, published_at = :published_at '
                    . 'WHERE tenant_id = :tenant AND slug = :slug AND updated_at = :expected AND deleted_at IS NULL'
                );
                $write->bindValue(':status', $fields['status']);
                $write->bindValue(':published_at', $fields['published_at']);
            }
            $write->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
            $write->bindValue(':slug', $slug);
            $write->bindValue(':expected', $old['updated_at']);
            $write->execute();
            $postId = (int)$old['id'];
        }
        $correlationId = cacPostMutationCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-core',
            'action' => 'akira.post.' . $operation,
            'entity_type' => 'post',
            'entity_id' => (string)$postId,
            'old_data' => $old,
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId, 'slug' => $slug] + $fields,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable Post audit failed.');
        }

        $outcome = [
            'ok' => true,
            'operation' => $operation,
            'post' => ['id' => $postId, 'slug' => $slug, 'status' => $fields['status']],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }

        // From this point a commit error is uncertain: never release/re-execute.
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release remains processing and therefore fails closed.
            }
        }
        throw $e;
    }
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function cacPostSqlFields(array $fields): array
{
    return [
        ':title' => $fields['title'], ':subtitle' => $fields['subtitle'], ':content' => $fields['content'],
        ':image' => $fields['image'], ':status' => $fields['status'], ':published_at' => $fields['published_at'],
    ];
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_post_create_1(mixed $payload, string $capabilityId = 'akira.post.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('create', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_post_update_1(mixed $payload, string $capabilityId = 'akira.post.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('update', $payload);
}

/** @return array<string, mixed> */
function cac_cap_akira_post_publish_1(mixed $payload, string $capabilityId = 'akira.post.publish@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('publish', $payload);
}

/** @return array<string, mixed> */
function cac_cap_akira_post_unpublish_1(mixed $payload, string $capabilityId = 'akira.post.unpublish@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('unpublish', $payload);
}

/** @return array<string, mixed> */
function cac_cap_akira_post_delete_1(mixed $payload, string $capabilityId = 'akira.post.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('delete', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_entity_list_post_1(mixed $payload, string $capabilityId = 'entity.list.post@1', string $caller = 'unknown'): array
{
    $args = is_array($payload) ? $payload : [];
    $filters = is_array($args['filters'] ?? null) ? $args['filters'] : [];
    if (($filters['include_unpublished'] ?? false) === true) {
        $args['include_unpublished'] = true;
        $args['status'] = $filters['status'] ?? '';
        $args['search'] = $filters['search'] ?? '';
    }
    $result = app()->cap()->call('akira.post.list@1', $args, [
        'caller' => ['module' => 'cms-akira-core'],
        'mode' => 'first',
    ]);
    if (!is_array($result) || ($result['ok'] ?? true) !== true || !is_array($result['rows'] ?? null)) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => (string)($result['error'] ?? 'Post list unavailable')];
    }

    $adminRead = ($args['include_unpublished'] ?? false) === true && cacPostEditorialParticipant();
    $rows = [];
    foreach ($result['rows'] as $post) {
        if (is_array($post)) {
            try {
                if ($adminRead) {
                    $slug = cacPostValidSlug($post['slug'] ?? null);
                    if ($slug === null) {
                        continue;
                    }
                    $rows[] = [
                        'slug' => $slug,
                        'title' => (string)($post['title'] ?? ''),
                        'status' => (string)($post['status'] ?? 'draft'),
                        'updated_at' => (string)($post['updated_at'] ?? ''),
                        'taxonomy_ids' => is_array($post['taxonomy_ids'] ?? null) ? $post['taxonomy_ids'] : [],
                        'categories' => is_array($post['categories'] ?? null) ? $post['categories'] : [],
                        'actions' => ['edit', 'delete'],
                    ];
                } else {
                    $rows[] = cacPostProject($post, false);
                }
            } catch (InvalidArgumentException $e) {
                // Unsafe stored slugs are not presentation-safe records.
            }
        }
    }
    return ['ok' => true, 'rows' => $rows, 'total' => (int)($result['total'] ?? count($rows))];
}

/**
 * @return array<string, mixed>
 */
function cac_cap_entity_get_post_1(mixed $payload, string $capabilityId = 'entity.get.post@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $slug = cacPostValidSlug($payload['id'] ?? $payload['slug'] ?? null);
    if ($slug === null) {
        return ['ok' => false, 'error' => 'A canonical slug is required'];
    }

    $result = app()->cap()->call('akira.post.get@1', ['slug' => $slug], [
        'caller' => ['module' => 'cms-akira-core'],
        'mode' => 'first',
    ]);
    $post = is_array($result) && is_array($result['data'] ?? null) ? $result['data'] : null;
    if (($result['ok'] ?? false) !== true || $post === null) {
        return ['ok' => false, 'error' => (string)($result['error'] ?? 'Post not found')];
    }

    try {
        return ['ok' => true, 'data' => cacPostProject($post, true)];
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'error' => 'Post not found'];
    }
}

// ── Governed Taxonomy (P1 content model increment 1) ────────────────────
// Content taxonomy terms (categories/tags) are governed CRUD on the same
// single-tenant PDO transaction as posts: kernel idempotency claim/commit/
// release + kernel.audit.record in one tx, tenant from kernel context, and
// optimistic concurrency on update/delete. No workflow machine and no direct
// status columns: taxonomy is simple governed content model for this bounded
// increment. Reads (list/get) remain ungoverned until R5 governs reads.

final class CacTaxonomyMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/** @return list<string> */
function cacTaxonomyTypes(): array
{
    return ['category', 'tag'];
}

function cacTaxonomyValidType(mixed $value): ?string
{
    $type = is_string($value) ? trim($value) : '';
    return in_array($type, cacTaxonomyTypes(), true) ? $type : null;
}

function cacTaxonomyTenantId(): int
{
    $tenantId = (int)app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new RuntimeException('A trusted tenant context is required.');
    }
    return $tenantId;
}

function cacTaxonomyValidSlug(mixed $value): ?string
{
    $slug = is_string($value) ? trim($value) : '';
    if ($slug === '' || strlen($slug) > 191) {
        return null;
    }

    // Same canonical form as Post slugs: lowercase ASCII words, one hyphen.
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1 ? $slug : null;
}

/** @return array{id: int, role: string, source?: string} */
function cacTaxonomyMutationActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int)($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacTaxonomyMutationException('Authentication required.', 401);
    }
    return $actor;
}

function cacTaxonomyMutationString(mixed $value, string $field, int $max, bool $required = false): string
{
    if (!is_string($value)) {
        if (!$required && $value === null) {
            return '';
        }
        throw new CacTaxonomyMutationException("{$field} must be a string.");
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) {
        throw new CacTaxonomyMutationException("{$field} is invalid.");
    }
    return $value;
}

function cacTaxonomyMutationParentId(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value) && (int)$value > 0) {
        return (int)$value;
    }
    throw new CacTaxonomyMutationException('parent_id must be a positive taxonomy id or empty.');
}

function cacTaxonomyMutationExpectedAt(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new CacTaxonomyMutationException('expected_updated_at must be a datetime string.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CacTaxonomyMutationException('expected_updated_at must use Y-m-d H:i:s.');
    }
    return $value;
}

function cacTaxonomyMutationCorrelationId(): string
{
    $fromContext = function_exists('kernel_request_context_get')
        ? trim((string)kernel_request_context_get('correlation_id', ''))
        : '';
    return $fromContext !== '' ? $fromContext : bin2hex(random_bytes(16));
}

/**
 * Resolve the term fields for a governed write. Type is immutable once a term
 * exists, so updates inherit the stored type and only name/slug/parent_id move.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed>|null $existing
 * @return array{type: string, name: string, slug: string, parent_id: int|null}
 */
function cacTaxonomyMutationFields(array $payload, ?array $existing = null): array
{
    $creating = $existing === null;
    $type = $creating
        ? cacTaxonomyValidType($payload['type'] ?? null)
        : cacTaxonomyValidType($existing['type'] ?? null);
    if ($type === null) {
        throw new CacTaxonomyMutationException('type must be category or tag.');
    }
    $name = $creating
        ? cacTaxonomyMutationString($payload['name'] ?? null, 'name', 191, true)
        : (array_key_exists('name', $payload)
            ? cacTaxonomyMutationString($payload['name'], 'name', 191, true)
            : (string)($existing['name'] ?? ''));
    $slug = $creating
        ? cacTaxonomyValidSlug($payload['slug'] ?? null)
        : cacTaxonomyValidSlug(array_key_exists('slug', $payload) ? $payload['slug'] : ($existing['slug'] ?? null));
    if ($slug === null) {
        throw new CacTaxonomyMutationException('A canonical lowercase taxonomy slug is required.', 422);
    }
    $parentId = null;
    if (array_key_exists('parent_id', $payload)) {
        $parentId = cacTaxonomyMutationParentId($payload['parent_id']);
    } elseif ($existing !== null && ($existing['parent_id'] ?? null) !== null) {
        $parentId = (int)$existing['parent_id'];
    }
    return ['type' => $type, 'name' => $name, 'slug' => $slug, 'parent_id' => $parentId];
}

/**
 * A parent term must exist with the same tenant and type. The walked ancestry
 * guard keeps the term tree acyclic on update (bounded walk, fails closed).
 */
function cacTaxonomyAssertValidParent(PDO $db, int $tenantId, string $type, ?int $parentId, ?int $selfId = null): void
{
    if ($parentId === null) {
        return;
    }
    if ($selfId !== null && $parentId === $selfId) {
        throw new CacTaxonomyMutationException('A taxonomy term cannot be its own parent.', 422);
    }
    $stmt = $db->prepare(
        'SELECT id, parent_id FROM cms_akira_taxonomies WHERE tenant_id = :tenant AND type = :type AND id = :id LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':tenant' => $tenantId, ':type' => $type, ':id' => $parentId]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($parent)) {
        throw new CacTaxonomyMutationException('parent_id must reference a term with the same type and tenant.', 422);
    }

    $cursor = (int)$parent['id'];
    $hops = 0;
    while ($cursor !== null && $hops < 100) {
        if ($selfId !== null && $cursor === $selfId) {
            throw new CacTaxonomyMutationException('parent_id would create a cycle in the term tree.', 422);
        }
        $walk = $db->prepare('SELECT parent_id FROM cms_akira_taxonomies WHERE tenant_id = :tenant AND id = :id LIMIT 1');
        $walk->execute([':tenant' => $tenantId, ':id' => $cursor]);
        $next = $walk->fetchColumn();
        $cursor = $next === false || $next === null ? null : (int)$next;
        $hops++;
    }
}

/**
 * Execute one governed taxonomy mutation on the application PDO. Kernel
 * idempotency and audit capabilities escalate narrowly onto this exact
 * caller-managed single-tenant transaction (mirrors cacPostMutate).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cacTaxonomyMutate(string $operation, array $payload): array
{
    $actor = cacTaxonomyMutationActor();
    $tenantId = cacTaxonomyTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CacTaxonomyMutationException('tenant_id is supplied by kernel context.', 422);
    }
    if (!app()->entityAuthority()->isAuthoritative('taxonomy', 'cms-akira-core')) {
        throw new CacTaxonomyMutationException('Taxonomy authority is not active.', 503);
    }

    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacTaxonomyMutationException('A valid idempotency_key is required.', 422);
    }

    if ($operation === 'create') {
        if (cacTaxonomyValidType($payload['type'] ?? null) === null) {
            throw new CacTaxonomyMutationException('type must be category or tag.', 422);
        }
        if (cacTaxonomyValidSlug($payload['slug'] ?? null) === null) {
            throw new CacTaxonomyMutationException('A canonical lowercase taxonomy slug is required.', 422);
        }
    } else {
        $termId = is_numeric($payload['id'] ?? null) ? (int)$payload['id'] : 0;
        if ($termId <= 0) {
            throw new CacTaxonomyMutationException('A taxonomy id is required.', 422);
        }
    }

    // Identity/JWT-shaped payload members are intentionally neither read nor hashed.
    $mutationInput = array_intersect_key($payload, array_flip([
        'id', 'type', 'name', 'slug', 'parent_id', 'expected_updated_at',
    ]));
    $envelope = ['operation' => $operation, 'taxonomy' => $mutationInput];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-core', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CacTaxonomyMutationException('Idempotency hashing unavailable.', 503);
    }

    $db = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        $claimStatus = is_array($claim) ? (string)($claim['status'] ?? '') : '';
        if ($claimStatus === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($claimStatus === 'conflict') {
            $db->rollBack();
            throw new CacTaxonomyMutationException('Idempotency key payload conflict.', 409);
        }
        if ($claimStatus === 'in_progress') {
            $db->rollBack();
            throw new CacTaxonomyMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($claimStatus !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $old = null;
        if ($operation !== 'create') {
            $termId = (int)$payload['id'];
            $find = $db->prepare(
                'SELECT id, tenant_id, type, name, slug, parent_id, created_at, updated_at '
                . 'FROM cms_akira_taxonomies WHERE tenant_id = :tenant AND id = :id LIMIT 1 FOR UPDATE'
            );
            $find->execute([':tenant' => $tenantId, ':id' => $termId]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($old)) {
                throw new CacTaxonomyMutationException('Taxonomy term not found.', 404);
            }
            $expected = cacTaxonomyMutationExpectedAt($mutationInput['expected_updated_at'] ?? null);
            if ($expected === null || $expected !== (string)$old['updated_at']) {
                throw new CacTaxonomyMutationException('Taxonomy term was modified; refresh and retry.', 409);
            }
        }

        try {
            if ($operation === 'create') {
                $fields = cacTaxonomyMutationFields($payload, null);
                cacTaxonomyAssertValidParent($db, $tenantId, $fields['type'], $fields['parent_id']);
                $write = $db->prepare(
                    'INSERT INTO cms_akira_taxonomies (tenant_id, type, name, slug, parent_id) '
                    . 'VALUES (:tenant, :type, :name, :slug, :parent)'
                );
                $write->execute([
                    ':tenant' => $tenantId,
                    ':type' => $fields['type'],
                    ':name' => $fields['name'],
                    ':slug' => $fields['slug'],
                    ':parent' => $fields['parent_id'],
                ]);
                $termId = (int)$db->lastInsertId();
            } elseif ($operation === 'update') {
                $fields = cacTaxonomyMutationFields($payload, $old);
                cacTaxonomyAssertValidParent($db, $tenantId, $fields['type'], $fields['parent_id'], (int)$old['id']);
                $write = $db->prepare(
                    'UPDATE cms_akira_taxonomies SET name = :name, slug = :slug, parent_id = :parent '
                    . 'WHERE tenant_id = :tenant AND id = :id AND updated_at = :expected'
                );
                $write->execute([
                    ':tenant' => $tenantId,
                    ':id' => (int)$old['id'],
                    ':expected' => $old['updated_at'],
                    ':name' => $fields['name'],
                    ':slug' => $fields['slug'],
                    ':parent' => $fields['parent_id'],
                ]);
            } else {
                // Governed delete: children are orphaned to the root (mirrors the
                // reference taxonomy's SET NULL intent), atomically with the row,
                // idempotency claim, and audit inside this same transaction.
                $child = $db->prepare(
                    'UPDATE cms_akira_taxonomies SET parent_id = NULL '
                    . 'WHERE tenant_id = :tenant AND parent_id = :id'
                );
                $child->execute([':tenant' => $tenantId, ':id' => (int)$old['id']]);
                $write = $db->prepare(
                    'DELETE FROM cms_akira_taxonomies WHERE tenant_id = :tenant AND id = :id AND updated_at = :expected'
                );
                $write->execute([
                    ':tenant' => $tenantId,
                    ':id' => (int)$old['id'],
                    ':expected' => $old['updated_at'],
                ]);
            }
        } catch (PDOException $e) {
            $errorInfo = is_array($e->errorInfo) ? $e->errorInfo : [];
            $mysqlCode = isset($errorInfo[1]) ? (int)$errorInfo[1] : 0;
            if ($mysqlCode === 1062 || $e->getCode() === '23000') {
                throw new CacTaxonomyMutationException('A taxonomy term with this type and slug already exists.', 422);
            }
            throw $e;
        }

        $correlationId = cacTaxonomyMutationCorrelationId();
        $type = (string)($old['type'] ?? $fields['type'] ?? '');
        $slug = (string)($fields['slug'] ?? $old['slug'] ?? '');
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-core',
            'action' => 'akira.taxonomy.' . $operation,
            'entity_type' => 'taxonomy',
            'entity_id' => (string)$termId,
            'old_data' => $old,
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId, 'type' => $type, 'slug' => $slug]
                + ($old === null ? ['name' => $fields['name'] ?? '', 'parent_id' => $fields['parent_id'] ?? null] : ['id' => (int)$old['id']]),
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable Taxonomy audit failed.');
        }

        $outcome = [
            'ok' => true,
            'operation' => $operation,
            'taxonomy' => ['id' => $termId, 'type' => $type, 'slug' => $slug],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }

        // From this point a commit error is uncertain: never release/re-execute.
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release remains processing and therefore fails closed.
            }
        }
        throw $e;
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_taxonomy_list_1(mixed $payload, string $capabilityId = 'akira.taxonomy.list@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $payload = is_array($payload) ? $payload : [];
    $type = cacTaxonomyValidType($payload['type'] ?? null);
    $sort = is_array($payload['sort'] ?? null) ? $payload['sort'] : [];
    $field = (string)($sort['field'] ?? $payload['sort_field'] ?? 'name');
    $direction = strtolower((string)($sort['direction'] ?? $payload['sort_direction'] ?? 'asc'));
    $field = in_array($field, ['type', 'name', 'slug', 'created_at', 'updated_at'], true) ? $field : 'name';
    $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';
    $limit = max(1, min(500, (int)($payload['limit'] ?? 500)));
    $offset = max(0, (int)($payload['offset'] ?? 0));

    try {
        $tenantId = cacTaxonomyTenantId();
        $where = 'tenant_id = :tenant_id';
        $bindings = [':tenant_id' => $tenantId];
        if ($type !== null) {
            $where .= ' AND type = :type';
            $bindings[':type'] = $type;
        }
        $count = cacDb()->prepare("SELECT COUNT(*) FROM cms_akira_taxonomies WHERE {$where}");
        $count->execute($bindings);
        $sql = "SELECT id, tenant_id, type, name, slug, parent_id, created_at, updated_at "
            . "FROM cms_akira_taxonomies WHERE {$where} "
            . "ORDER BY {$field} {$direction}, id {$direction} "
            . "LIMIT {$limit} OFFSET {$offset}";
        $stmt = cacDb()->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['ok' => true, 'rows' => $rows, 'total' => (int)$count->fetchColumn()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Taxonomy storage unavailable'];
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_taxonomy_get_1(mixed $payload, string $capabilityId = 'akira.taxonomy.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $type = cacTaxonomyValidType($payload['type'] ?? null);
    if ($type === null) {
        return ['ok' => false, 'error' => 'type must be category or tag'];
    }
    $slug = cacTaxonomyValidSlug($payload['slug'] ?? null);
    if ($slug === null) {
        return ['ok' => false, 'error' => 'A canonical slug is required'];
    }
    try {
        $stmt = cacDb()->prepare(
            "SELECT id, tenant_id, type, name, slug, parent_id, created_at, updated_at
             FROM cms_akira_taxonomies
             WHERE tenant_id = :tenant_id AND type = :type AND slug = :slug
             LIMIT 1"
        );
        $stmt->execute([':tenant_id' => cacTaxonomyTenantId(), ':type' => $type, ':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['ok' => true, 'data' => $row]
            : ['ok' => false, 'error' => 'Taxonomy term not found'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Taxonomy storage unavailable'];
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_taxonomy_create_1(mixed $payload, string $capabilityId = 'akira.taxonomy.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacTaxonomyMutationException('payload must be an object.');
    }
    return cacTaxonomyMutate('create', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_taxonomy_update_1(mixed $payload, string $capabilityId = 'akira.taxonomy.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacTaxonomyMutationException('payload must be an object.');
    }
    return cacTaxonomyMutate('update', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_taxonomy_delete_1(mixed $payload, string $capabilityId = 'akira.taxonomy.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacTaxonomyMutationException('payload must be an object.');
    }
    return cacTaxonomyMutate('delete', $payload);
}

// ── Governed Content Types (P1 content model increment 2) ──────────────
// A tenant-scoped registry of DECLARED content models. An editor/administrator
// declares a slug, a human label, and a field_schema (a JSON object of the
// canonical shape { fields: { <name>: { type, required, label } } }); the
// schema is validated on every write and stored in canonical form, so no
// schema-less meta payload can reach the tenant DB. Writes run on the same
// single-tenant PDO transaction as posts/taxonomy: kernel idempotency
// claim/commit/release + kernel.audit.record in one tx, tenant from kernel
// context (payload tenant_id rejected), canonical slug, and optimistic
// concurrency via expected_updated_at. The table is deliberately FK-less:
// nothing references content types yet, so delete is always allowed and a
// later increment can add the reference seam without a migration redesign.
// Reads (list/get) remain ungoverned until R5 governs reads.

final class CacContentTypeMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/** @return list<string> */
function cacContentTypeFieldTypes(): array
{
    return ['text', 'textarea', 'number', 'boolean', 'date', 'select', 'image'];
}

function cacContentTypeTenantId(): int
{
    $tenantId = (int)app()->tenant()->current();
    if ($tenantId <= 0) {
        throw new RuntimeException('A trusted tenant context is required.');
    }
    return $tenantId;
}

function cacContentTypeValidSlug(mixed $value): ?string
{
    $slug = is_string($value) ? trim($value) : '';
    if ($slug === '' || strlen($slug) > 191) {
        return null;
    }

    // Same canonical form as Post and Taxonomy slugs: lowercase ASCII words,
    // one hyphen between words. This slug is the future coupling key for posts.
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1 ? $slug : null;
}

/** @return array{id: int, role: string, source?: string} */
function cacContentTypeMutationActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int)($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacContentTypeMutationException('Authentication required.', 401);
    }
    return $actor;
}

function cacContentTypeMutationString(mixed $value, string $field, int $max, bool $required = false): string
{
    if (!is_string($value)) {
        if (!$required && $value === null) {
            return '';
        }
        throw new CacContentTypeMutationException("{$field} must be a string.");
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) {
        throw new CacContentTypeMutationException("{$field} is invalid.");
    }
    return $value;
}

function cacContentTypeMutationExpectedAt(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new CacContentTypeMutationException('expected_updated_at must be a datetime string.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CacContentTypeMutationException('expected_updated_at must use Y-m-d H:i:s.');
    }
    return $value;
}

function cacContentTypeMutationCorrelationId(): string
{
    $fromContext = function_exists('kernel_request_context_get')
        ? trim((string)kernel_request_context_get('correlation_id', ''))
        : '';
    return $fromContext !== '' ? $fromContext : bin2hex(random_bytes(16));
}

/**
 * Lex a validated JSON document into a flat token stream. Punctuation becomes
 * scalar strings; every other token becomes ['t' => 'str'|'scalar', 'v' => value].
 * Only ever invoked after json_decode already accepted the document, so this
 * scan cannot trip on malformed input.
 *
 * @return list<string|array{t: string, v: mixed}>
 */
function cacContentTypeJsonTokens(string $json): array
{
    $tokens = [];
    $len = strlen($json);
    $i = 0;
    while ($i < $len) {
        $ch = $json[$i];
        if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
            $i++;
            continue;
        }
        if (str_contains('{}[],:', $ch)) {
            $tokens[] = $ch;
            $i++;
            continue;
        }
        if ($ch === '"') {
            $j = $i + 1;
            while ($j < $len) {
                if ($json[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($json[$j] === '"') {
                    break;
                }
                $j++;
            }
            $tokens[] = ['t' => 'str', 'v' => (string)json_decode(substr($json, $i, $j - $i + 1))];
            $i = $j + 1;
            continue;
        }
        // Number or literal (true/false/null) — tokens that are never keys.
        $j = $i;
        while ($j < $len && (ctype_alnum($json[$j]) || str_contains('-+.eE', $json[$j]))) {
            $j++;
        }
        $tokens[] = ['t' => 'scalar', 'v' => substr($json, $i, $j - $i)];
        $i = $j;
    }
    return $tokens;
}

/**
 * Field names are JSON object keys inside the "fields" member, so duplicates
 * collapse during decode and must be detected on the raw document. Walk the
 * token stream: object-member keys are string tokens directly followed by ':'
 * at nesting depth 2 (root object -> fields object).
 *
 * @return list<string>
 */
function cacContentTypeRawFieldNames(string $json): array
{
    $tokens = cacContentTypeJsonTokens($json);
    $depth = 0;
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token === '{' || $token === '[') {
            $depth++;
            continue;
        }
        if ($token === '}' || $token === ']') {
            $depth--;
            continue;
        }
        if ($token === ':' || $token === ',') {
            continue;
        }
        if (is_array($token) && $token['t'] === 'str' && $depth === 2
            && ($tokens[$i + 1] ?? null) === ':') {
            $names[] = (string)$token['v'];
        }
    }
    return $names;
}

/**
 * Validate and canonically normalize one declared field_schema. Enforced shape
 * (the only shape a declared content model may take):
 *
 *   { "fields": { "<name>": { "type": text|textarea|number|boolean|date|select|image,
 *                             "required": bool (optional), "label": string (optional) } } }
 *
 * Unknown members, unsupported field types, and duplicate field names are
 * rejected with a clear 422 through the mutation exception. Returns the compact
 * canonical JSON that is stored.
 */
function cacContentTypeCanonicalSchema(mixed $value): string
{
    if (!is_string($value)) {
        throw new CacContentTypeMutationException('field_schema must be a JSON string.', 422);
    }
    if (strlen($value) > 65535) {
        throw new CacContentTypeMutationException('field_schema is too large (max 65535 characters).', 422);
    }
    $decoded = json_decode($value, true);
    if ($decoded === null && trim($value) !== 'null') {
        $reason = (string)json_last_error_msg();
        throw new CacContentTypeMutationException("field_schema is not valid JSON ({$reason}).", 422);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new CacContentTypeMutationException('field_schema must be a JSON object.', 422);
    }
    foreach (array_keys($decoded) as $key) {
        if ($key !== 'fields') {
            throw new CacContentTypeMutationException('field_schema supports only the "fields" member.', 422);
        }
    }
    $fields = $decoded['fields'] ?? null;
    if (!is_array($fields) || array_is_list($fields)) {
        // An empty JSON object also decodes to [], so this single check rejects
        // both `"fields": []` and an empty `"fields": {}` — a declared content
        // model must declare at least one field.
        throw new CacContentTypeMutationException('field_schema must declare a non-empty "fields" object.', 422);
    }
    $duplicates = [];
    foreach (cacContentTypeRawFieldNames($value) as $name) {
        $duplicates[$name] = ($duplicates[$name] ?? 0) + 1;
    }
    foreach ($duplicates as $name => $count) {
        if ($count > 1) {
            throw new CacContentTypeMutationException("field_schema declares duplicate field name \"{$name}\".", 422);
        }
    }

    $types = cacContentTypeFieldTypes();
    $normalized = [];
    foreach ($fields as $name => $definition) {
        if (!is_string($name) || $name === '') {
            throw new CacContentTypeMutationException('field_schema field names must be non-empty strings.', 422);
        }
        if (!is_array($definition) || array_is_list($definition)) {
            throw new CacContentTypeMutationException("field_schema field \"{$name}\" must be an object.", 422);
        }
        foreach (array_keys($definition) as $member) {
            if (!in_array($member, ['type', 'required', 'label'], true)) {
                throw new CacContentTypeMutationException(
                    "field_schema field \"{$name}\" has unsupported member \"{$member}\".",
                    422
                );
            }
        }
        $type = $definition['type'] ?? null;
        if (!is_string($type) || !in_array($type, $types, true)) {
            throw new CacContentTypeMutationException(
                "field_schema field \"{$name}\": type must be one of " . implode(', ', $types) . '.',
                422
            );
        }
        $field = ['type' => $type];
        if (array_key_exists('required', $definition)) {
            if (!is_bool($definition['required'])) {
                throw new CacContentTypeMutationException("field_schema field \"{$name}\": required must be a boolean.", 422);
            }
            if ($definition['required'] === true) {
                $field['required'] = true;
            }
        }
        if (array_key_exists('label', $definition)) {
            if (!is_string($definition['label'])) {
                throw new CacContentTypeMutationException("field_schema field \"{$name}\": label must be a string.", 422);
            }
            $label = trim($definition['label']);
            if ($label !== '') {
                $field['label'] = $label;
            }
        }
        $normalized[$name] = $field;
    }

    $canonical = json_encode(['fields' => $normalized], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($canonical)) {
        throw new CacContentTypeMutationException('field_schema could not be normalized.', 422);
    }
    return $canonical;
}

/**
 * Resolve the declared-model fields for a governed write. Slug, label, and the
 * canonical field_schema all move on update (nothing references a type yet).
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed>|null $existing
 * @return array{slug: string, label: string, field_schema: string}
 */
function cacContentTypeMutationFields(array $payload, ?array $existing = null): array
{
    $creating = $existing === null;
    $slug = $creating
        ? cacContentTypeValidSlug($payload['slug'] ?? null)
        : cacContentTypeValidSlug(array_key_exists('slug', $payload) ? $payload['slug'] : ($existing['slug'] ?? null));
    if ($slug === null) {
        throw new CacContentTypeMutationException('A canonical lowercase content type slug is required.', 422);
    }
    $label = $creating
        ? cacContentTypeMutationString($payload['label'] ?? null, 'label', 191, true)
        : (array_key_exists('label', $payload)
            ? cacContentTypeMutationString($payload['label'], 'label', 191, true)
            : (string)($existing['label'] ?? ''));
    $schema = $creating
        ? cacContentTypeCanonicalSchema($payload['field_schema'] ?? null)
        : (array_key_exists('field_schema', $payload)
            ? cacContentTypeCanonicalSchema($payload['field_schema'])
            : (string)($existing['field_schema'] ?? '{"fields":{}}'));
    return ['slug' => $slug, 'label' => $label, 'field_schema' => $schema];
}

/**
 * Execute one governed content type mutation on the application PDO. Kernel
 * idempotency and audit capabilities escalate narrowly onto this exact
 * caller-managed single-tenant transaction (mirrors cacTaxonomyMutate).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cacContentTypeMutate(string $operation, array $payload): array
{
    $actor = cacContentTypeMutationActor();
    $tenantId = cacContentTypeTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CacContentTypeMutationException('tenant_id is supplied by kernel context.', 422);
    }
    if (!app()->entityAuthority()->isAuthoritative('content_type', 'cms-akira-core')) {
        throw new CacContentTypeMutationException('Content type authority is not active.', 503);
    }

    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacContentTypeMutationException('A valid idempotency_key is required.', 422);
    }

    if ($operation === 'create') {
        if (cacContentTypeValidSlug($payload['slug'] ?? null) === null) {
            throw new CacContentTypeMutationException('A canonical lowercase content type slug is required.', 422);
        }
    } else {
        $typeId = is_numeric($payload['id'] ?? null) ? (int)$payload['id'] : 0;
        if ($typeId <= 0) {
            throw new CacContentTypeMutationException('A content type id is required.', 422);
        }
    }

    // Identity/JWT-shaped payload members are intentionally neither read nor hashed.
    $mutationInput = array_intersect_key($payload, array_flip([
        'id', 'slug', 'label', 'field_schema', 'expected_updated_at',
    ]));
    $envelope = ['operation' => $operation, 'content_type' => $mutationInput];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-core', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CacContentTypeMutationException('Idempotency hashing unavailable.', 503);
    }

    $db = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        $claimStatus = is_array($claim) ? (string)($claim['status'] ?? '') : '';
        if ($claimStatus === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($claimStatus === 'conflict') {
            $db->rollBack();
            throw new CacContentTypeMutationException('Idempotency key payload conflict.', 409);
        }
        if ($claimStatus === 'in_progress') {
            $db->rollBack();
            throw new CacContentTypeMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($claimStatus !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        $old = null;
        if ($operation !== 'create') {
            $typeId = (int)$payload['id'];
            $find = $db->prepare(
                'SELECT id, tenant_id, slug, label, field_schema, created_at, updated_at '
                . 'FROM cms_akira_content_types WHERE tenant_id = :tenant AND id = :id LIMIT 1 FOR UPDATE'
            );
            $find->execute([':tenant' => $tenantId, ':id' => $typeId]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($old)) {
                throw new CacContentTypeMutationException('Content type not found.', 404);
            }
            $expected = cacContentTypeMutationExpectedAt($mutationInput['expected_updated_at'] ?? null);
            if ($expected === null || $expected !== (string)$old['updated_at']) {
                throw new CacContentTypeMutationException('Content type was modified; refresh and retry.', 409);
            }
        }

        try {
            if ($operation === 'create') {
                $fields = cacContentTypeMutationFields($payload, null);
                $write = $db->prepare(
                    'INSERT INTO cms_akira_content_types (tenant_id, slug, label, field_schema) '
                    . 'VALUES (:tenant, :slug, :label, :field_schema)'
                );
                $write->execute([
                    ':tenant' => $tenantId,
                    ':slug' => $fields['slug'],
                    ':label' => $fields['label'],
                    ':field_schema' => $fields['field_schema'],
                ]);
                $typeId = (int)$db->lastInsertId();
            } elseif ($operation === 'update') {
                $fields = cacContentTypeMutationFields($payload, $old);
                $write = $db->prepare(
                    'UPDATE cms_akira_content_types SET slug = :slug, label = :label, field_schema = :field_schema '
                    . 'WHERE tenant_id = :tenant AND id = :id AND updated_at = :expected'
                );
                $write->execute([
                    ':tenant' => $tenantId,
                    ':id' => (int)$old['id'],
                    ':expected' => $old['updated_at'],
                    ':slug' => $fields['slug'],
                    ':label' => $fields['label'],
                    ':field_schema' => $fields['field_schema'],
                ]);
            } else {
                // Governed delete: no posts reference content types yet (this
                // bounded increment adds no linkage), so delete is always
                // allowed. The schema keeps an FK-less design so a later
                // increment can add references without a migration redesign.
                $write = $db->prepare(
                    'DELETE FROM cms_akira_content_types WHERE tenant_id = :tenant AND id = :id AND updated_at = :expected'
                );
                $write->execute([
                    ':tenant' => $tenantId,
                    ':id' => (int)$old['id'],
                    ':expected' => $old['updated_at'],
                ]);
            }
        } catch (PDOException $e) {
            $errorInfo = is_array($e->errorInfo) ? $e->errorInfo : [];
            $mysqlCode = isset($errorInfo[1]) ? (int)$errorInfo[1] : 0;
            if ($mysqlCode === 1062 || $e->getCode() === '23000') {
                throw new CacContentTypeMutationException('A content type with this slug already exists.', 422);
            }
            throw $e;
        }

        $correlationId = cacContentTypeMutationCorrelationId();
        $slug = (string)($fields['slug'] ?? $old['slug'] ?? '');
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-core',
            'action' => 'akira.content_type.' . $operation,
            'entity_type' => 'content_type',
            'entity_id' => (string)$typeId,
            'old_data' => $old,
            'new_data' => ['correlation_id' => $correlationId, 'tenant_id' => $tenantId, 'slug' => $slug]
                + ($old === null
                    ? ['label' => $fields['label'] ?? '', 'field_schema' => $fields['field_schema'] ?? '']
                    : ['id' => (int)$old['id']]),
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable Content type audit failed.');
        }

        $outcome = [
            'ok' => true,
            'operation' => $operation,
            'content_type' => ['id' => $typeId, 'slug' => $slug],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }

        // From this point a commit error is uncertain: never release/re-execute.
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release remains processing and therefore fails closed.
            }
        }
        throw $e;
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_content_type_list_1(mixed $payload, string $capabilityId = 'akira.content_type.list@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $payload = is_array($payload) ? $payload : [];
    $sort = is_array($payload['sort'] ?? null) ? $payload['sort'] : [];
    $field = (string)($sort['field'] ?? $payload['sort_field'] ?? 'label');
    $direction = strtolower((string)($sort['direction'] ?? $payload['sort_direction'] ?? 'asc'));
    $field = in_array($field, ['label', 'slug', 'created_at', 'updated_at'], true) ? $field : 'label';
    $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';
    $limit = max(1, min(500, (int)($payload['limit'] ?? 500)));
    $offset = max(0, (int)($payload['offset'] ?? 0));

    try {
        $tenantId = cacContentTypeTenantId();
        $where = 'tenant_id = :tenant_id';
        $bindings = [':tenant_id' => $tenantId];
        $count = cacDb()->prepare("SELECT COUNT(*) FROM cms_akira_content_types WHERE {$where}");
        $count->execute($bindings);
        $sql = "SELECT id, tenant_id, slug, label, field_schema, created_at, updated_at "
            . "FROM cms_akira_content_types WHERE {$where} "
            . "ORDER BY {$field} {$direction}, id {$direction} "
            . "LIMIT {$limit} OFFSET {$offset}";
        $stmt = cacDb()->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['ok' => true, 'rows' => $rows, 'total' => (int)$count->fetchColumn()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Content type storage unavailable'];
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_content_type_get_1(mixed $payload, string $capabilityId = 'akira.content_type.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $slug = cacContentTypeValidSlug($payload['slug'] ?? null);
    if ($slug === null) {
        return ['ok' => false, 'error' => 'A canonical slug is required'];
    }
    try {
        $stmt = cacDb()->prepare(
            "SELECT id, tenant_id, slug, label, field_schema, created_at, updated_at
             FROM cms_akira_content_types
             WHERE tenant_id = :tenant_id AND slug = :slug
             LIMIT 1"
        );
        $stmt->execute([':tenant_id' => cacContentTypeTenantId(), ':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['ok' => true, 'data' => $row]
            : ['ok' => false, 'error' => 'Content type not found'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Content type storage unavailable'];
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_content_type_create_1(mixed $payload, string $capabilityId = 'akira.content_type.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacContentTypeMutationException('payload must be an object.');
    }
    return cacContentTypeMutate('create', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_content_type_update_1(mixed $payload, string $capabilityId = 'akira.content_type.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacContentTypeMutationException('payload must be an object.');
    }
    return cacContentTypeMutate('update', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_content_type_delete_1(mixed $payload, string $capabilityId = 'akira.content_type.delete@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacContentTypeMutationException('payload must be an object.');
    }
    return cacContentTypeMutate('delete', $payload);
}

// ── Post ↔ Taxonomy assignment (P1 content model increment 3) ────────────
// A post's taxonomy links (categories) are an additive, tenant-scoped link set
// owned by cms-akira-core. akira.post.set_taxonomies@1 replaces one post's
// whole assignment set atomically inside the same single-tenant transaction as
// every other governed write (kernel idempotency claim/commit/release +
// kernel.audit.record) but NEVER writes the post row: it only runs an
// optimistic-concurrency read of cms_akira_posts.updated_at so a concurrent
// content edit surfaces as a clean 409. Read surfaces stay additive — the
// post row DTO gains taxonomy_ids + categories keys and the list accepts an
// optional filters.taxonomy_id — while existing read members keep their shape
// and public/unpublished semantics are untouched.

final class CacPostTaxonomyMutationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

/** @return array{taxonomy_ids: list<int>, categories: list<array{id: int, name: string, slug: string, type: string}>} */
function cacEmptyPostTaxonomyAssignments(): array
{
    return ['taxonomy_ids' => [], 'categories' => []];
}

/**
 * @param list<string> $slugs
 * @return array<string, array{taxonomy_ids: list<int>, categories: list<array{id: int, name: string, slug: string, type: string}>}>
 */
function cacPostTaxonomyProjection(array $slugs, int $tenantId): array
{
    $projection = [];
    foreach ($slugs as $slug) {
        $projection[$slug] = cacEmptyPostTaxonomyAssignments();
    }
    if ($slugs === []) {
        return $projection;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = cacDb()->prepare(
            'SELECT pt.post_slug, t.id, t.type, t.name, t.slug '
            . 'FROM cms_akira_post_taxonomies pt '
            . 'JOIN cms_akira_taxonomies t ON t.id = pt.taxonomy_id AND t.tenant_id = pt.tenant_id '
            . "WHERE pt.tenant_id = ? AND pt.post_slug IN ({$placeholders}) "
            . 'ORDER BY pt.post_slug, t.name, t.id'
        );
        $stmt->execute(array_merge([$tenantId], $slugs));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $postSlug = (string)($row['post_slug'] ?? '');
            if (!isset($projection[$postSlug])) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            $projection[$postSlug]['taxonomy_ids'][] = $id;
            $projection[$postSlug]['categories'][] = [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'slug' => (string)($row['slug'] ?? ''),
                'type' => (string)($row['type'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // The additive read surface must never take base post reads down: when
        // the link storage is unavailable every post simply projects no links.
        return [];
    }
    return $projection;
}

/** @return array{taxonomy_ids: list<int>, categories: list<array{id: int, name: string, slug: string, type: string}>} */
function cacPostTaxonomyAssignmentsForSlug(string $slug): array
{
    $projection = cacPostTaxonomyProjection([$slug], cacPostTenantId());
    return $projection[$slug] ?? cacEmptyPostTaxonomyAssignments();
}

/**
 * taxonomy_ids is a flat list of positive taxonomy ids. Duplicate ids collapse
 * to one assignment (the link table is UNIQUE on the triple), empty entries are
 * tolerated (unchecked checkbox fields), and anything else is a clean 422.
 *
 * @return list<int>
 */
function cacPostTaxonomyIds(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        throw new CacPostTaxonomyMutationException('taxonomy_ids must be an array of taxonomy ids.', 422);
    }
    $ids = [];
    foreach ($value as $entry) {
        if ($entry === null || $entry === '') {
            continue;
        }
        if (is_int($entry) && $entry > 0) {
            $ids[] = $entry;
            continue;
        }
        if (is_string($entry) && ctype_digit($entry) && (int)$entry > 0) {
            $ids[] = (int)$entry;
            continue;
        }
        throw new CacPostTaxonomyMutationException('taxonomy_ids must contain positive taxonomy ids.', 422);
    }
    $unique = array_values(array_unique($ids));
    if (count($unique) > 100) {
        throw new CacPostTaxonomyMutationException('taxonomy_ids supports at most 100 terms per post.', 422);
    }
    return $unique;
}

function cacPostTaxonomyMutationExpectedAt(mixed $value): string
{
    if (!is_string($value)) {
        throw new CacPostTaxonomyMutationException('expected_updated_at is required for optimistic concurrency.', 422);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value) {
        throw new CacPostTaxonomyMutationException('expected_updated_at must use Y-m-d H:i:s.', 422);
    }
    return $value;
}

/**
 * Replace one post's taxonomy assignment set. The whole payload (slug, ids,
 * expected_updated_at) hashes into the idempotency envelope; the post row is
 * only ever read (FOR UPDATE + version compare) so the R1/R2 lifecycle and
 * cacPostMutate semantics are untouched.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function cacPostSetTaxonomies(array $payload): array
{
    $actor = cacPostMutationActor();
    $tenantId = cacPostTenantId();
    if (array_key_exists('tenant_id', $payload)) {
        throw new CacPostTaxonomyMutationException('tenant_id is supplied by kernel context.', 422);
    }
    if (!app()->entityAuthority()->isAuthoritative('post', 'cms-akira-core')) {
        throw new CacPostTaxonomyMutationException('Post authority is not active.', 503);
    }

    $key = is_string($payload['idempotency_key'] ?? null) ? trim($payload['idempotency_key']) : '';
    if ($key === '' || strlen($key) > 255) {
        throw new CacPostTaxonomyMutationException('A valid idempotency_key is required.', 422);
    }
    $slug = cacPostValidSlug($payload['slug'] ?? null);
    if ($slug === null) {
        throw new CacPostTaxonomyMutationException('A canonical lowercase Post slug is required.', 422);
    }
    $taxonomyIds = cacPostTaxonomyIds($payload['taxonomy_ids'] ?? null);

    // Identity/JWT-shaped payload members are intentionally neither read nor hashed.
    $mutationInput = array_intersect_key($payload, array_flip([
        'slug', 'taxonomy_ids', 'expected_updated_at',
    ]));
    $envelope = ['operation' => 'set_taxonomies', 'post' => $mutationInput];
    $hash = app()->cap()->call('kernel.idempotency.hash@1', ['payload' => $envelope], [
        'caller' => ['module' => 'cms-akira-core', 'user' => $actor],
        'mode' => 'first',
    ]);
    if (!is_string($hash)) {
        throw new CacPostTaxonomyMutationException('Idempotency hashing unavailable.', 503);
    }

    $db = app()->db();
    $claimed = false;
    $publicationUncertain = false;
    try {
        $db->beginTransaction();
        $claim = app()->cap()->call('kernel.idempotency.claim@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'payload_hash' => $hash,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        $claimStatus = is_array($claim) ? (string)($claim['status'] ?? '') : '';
        if ($claimStatus === 'duplicate') {
            $db->rollBack();
            return is_array($claim['outcome'] ?? null) ? $claim['outcome'] : ['ok' => true, 'replayed' => true];
        }
        if ($claimStatus === 'conflict') {
            $db->rollBack();
            throw new CacPostTaxonomyMutationException('Idempotency key payload conflict.', 409);
        }
        if ($claimStatus === 'in_progress') {
            $db->rollBack();
            throw new CacPostTaxonomyMutationException('Idempotent mutation is still processing.', 425, 2);
        }
        if ($claimStatus !== 'new') {
            throw new RuntimeException('Unexpected idempotency claim result.');
        }
        $claimed = true;

        // Optimistic concurrency is a read-only check against the post row:
        // the row is locked to serialize with cacPostMutate but never written.
        $find = $db->prepare(
            'SELECT id, updated_at FROM cms_akira_posts '
            . 'WHERE tenant_id = :tenant AND slug = :slug AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $find->execute([':tenant' => $tenantId, ':slug' => $slug]);
        $post = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($post)) {
            throw new CacPostTaxonomyMutationException('Post not found.', 404);
        }
        $expected = cacPostTaxonomyMutationExpectedAt($mutationInput['expected_updated_at'] ?? null);
        if ($expected !== (string)$post['updated_at']) {
            throw new CacPostTaxonomyMutationException('Post was modified; refresh and retry.', 409);
        }
        $postId = (int)$post['id'];

        // Every requested taxonomy term must exist in the same tenant.
        if ($taxonomyIds !== []) {
            $placeholders = implode(',', array_fill(0, count($taxonomyIds), '?'));
            $verify = $db->prepare(
                "SELECT id FROM cms_akira_taxonomies WHERE tenant_id = ? AND id IN ({$placeholders})"
            );
            $verify->execute(array_merge([$tenantId], $taxonomyIds));
            $found = array_map('intval', array_map('strval', $verify->fetchAll(PDO::FETCH_COLUMN)));
            if (count($found) !== count($taxonomyIds)) {
                throw new CacPostTaxonomyMutationException(
                    'Every taxonomy_id must reference an existing term in the same tenant.',
                    422
                );
            }
        }

        $previous = $db->prepare(
            'SELECT taxonomy_id FROM cms_akira_post_taxonomies '
            . 'WHERE tenant_id = :tenant AND post_slug = :slug ORDER BY taxonomy_id'
        );
        $previous->execute([':tenant' => $tenantId, ':slug' => $slug]);
        $previousIds = array_map('intval', array_map('strval', $previous->fetchAll(PDO::FETCH_COLUMN)));

        // Atomic replacement of the whole assignment set inside this tx.
        $delete = $db->prepare(
            'DELETE FROM cms_akira_post_taxonomies WHERE tenant_id = :tenant AND post_slug = :slug'
        );
        $delete->execute([':tenant' => $tenantId, ':slug' => $slug]);
        if ($taxonomyIds !== []) {
            $insert = $db->prepare(
                'INSERT INTO cms_akira_post_taxonomies (tenant_id, post_slug, taxonomy_id) VALUES (?, ?, ?)'
            );
            foreach ($taxonomyIds as $taxonomyId) {
                $insert->execute([$tenantId, $slug, $taxonomyId]);
            }
        }

        $correlationId = cacPostMutationCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-core',
            'action' => 'akira.post.set_taxonomies',
            'entity_type' => 'post',
            'entity_id' => (string)$postId,
            'old_data' => ['slug' => $slug, 'taxonomy_ids' => $previousIds],
            'new_data' => [
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'slug' => $slug,
                'taxonomy_ids' => $taxonomyIds,
            ],
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if (!is_array($audit) || ($audit['ok'] ?? false) !== true) {
            throw new RuntimeException('Durable Post taxonomy audit failed.');
        }

        $outcome = [
            'ok' => true,
            'operation' => 'set_taxonomies',
            'post' => ['id' => $postId, 'slug' => $slug, 'taxonomy_ids' => $taxonomyIds],
            'correlation_id' => $correlationId,
        ];
        $committed = app()->cap()->call('kernel.idempotency.commit@1', [
            'key' => $key,
            'tenant_id' => $tenantId,
            'outcome' => $outcome,
            'db' => $db,
        ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
        if ($committed !== true) {
            throw new RuntimeException('Idempotency outcome commit failed.');
        }

        // From this point a commit error is uncertain: never release/re-execute.
        $publicationUncertain = true;
        $db->commit();
        $publicationUncertain = false;
        return $outcome;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($claimed && !$publicationUncertain) {
            try {
                app()->cap()->call('kernel.idempotency.release@1', [
                    'key' => $key, 'tenant_id' => $tenantId, 'db' => $db,
                ], ['caller' => ['module' => 'cms-akira-core', 'user' => $actor], 'mode' => 'first']);
            } catch (Throwable) {
                // A failed release remains processing and therefore fails closed.
            }
        }
        throw $e;
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_akira_post_set_taxonomies_1(mixed $payload, string $capabilityId = 'akira.post.set_taxonomies@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostTaxonomyMutationException('payload must be an object.');
    }
    return cacPostSetTaxonomies($payload);
}
