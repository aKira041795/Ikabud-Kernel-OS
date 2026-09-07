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
        'cms.post.get@1' => 'cac_cap_cms_post_get_1',
        'cms.post.list@1' => 'cac_cap_cms_post_list_1',
        'cms.post.create@1' => 'cac_cap_cms_post_create_1',
        'cms.post.update@1' => 'cac_cap_cms_post_update_1',
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
function cac_cap_cms_post_get_1(mixed $payload, string $capabilityId = 'cms.post.get@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $slug = cacPostValidSlug($payload['slug'] ?? $payload['id'] ?? null);
    if ($slug === null) {
        return ['ok' => false, 'error' => 'A canonical slug is required'];
    }

    try {
        $stmt = cacDb()->prepare(
            "SELECT id, tenant_id, slug, title, subtitle, content, image, status, published_at, created_at, updated_at
             FROM cms_akira_posts
             WHERE tenant_id = :tenant_id AND slug = :slug AND status = 'published'
             LIMIT 1"
        );
        $stmt->execute([':tenant_id' => cacPostTenantId(), ':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['ok' => true, 'data' => $row]
            : ['ok' => false, 'error' => 'Post not found'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Post storage unavailable'];
    }
}

/**
 * @return array<string, mixed>
 */
function cac_cap_cms_post_list_1(mixed $payload, string $capabilityId = 'cms.post.list@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }
    $payload = is_array($payload) ? $payload : [];
    $sort = is_array($payload['sort'] ?? null) ? $payload['sort'] : [];
    $field = (string)($sort['field'] ?? $payload['sort_field'] ?? 'published_at');
    $direction = strtolower((string)($sort['direction'] ?? $payload['sort_direction'] ?? 'desc'));
    $field = in_array($field, ['published_at', 'created_at', 'title'], true) ? $field : 'published_at';
    $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';
    $limit = max(1, min(50, (int)($payload['limit'] ?? 25)));
    $offset = max(0, (int)($payload['offset'] ?? 0));

    try {
        $tenantId = cacPostTenantId();
        $sql = "SELECT id, tenant_id, slug, title, subtitle, content, image, status, published_at, created_at, updated_at
                FROM cms_akira_posts
                WHERE tenant_id = :tenant_id AND status = 'published'
                ORDER BY {$field} {$direction}, id {$direction}
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = cacDb()->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['ok' => true, 'rows' => $rows, 'total' => count($rows)];
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

/** @return array{id: int, role: string, source?: string} */
function cacPostMutationActor(): array
{
    $actor = app()->user();
    if (!is_array($actor) || (int)($actor['id'] ?? $actor['sub'] ?? 0) <= 0) {
        throw new CacPostMutationException('Authentication required.', 401);
    }
    if ((string)($actor['role'] ?? '') !== 'admin') {
        throw new CacPostMutationException('Administrator role required.', 403);
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

    if (array_key_exists('status', $payload) && !is_string($payload['status'])) {
        throw new CacPostMutationException('status must be draft or published.');
    }
    $status = array_key_exists('status', $payload)
        ? $payload['status']
        : (string)($existing['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'published'], true)) {
        throw new CacPostMutationException('status must be draft or published.');
    }

    $publishedAt = array_key_exists('published_at', $payload)
        ? cacPostMutationPublishedAt($payload['published_at'])
        : ($existing['published_at'] ?? null);
    if ($status === 'draft') {
        $publishedAt = null;
    } elseif ($publishedAt === null) {
        $publishedAt = date('Y-m-d H:i:s');
    }

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
        'slug', 'title', 'subtitle', 'content', 'image', 'status', 'published_at',
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
        if ($operation === 'update') {
            $find = $db->prepare(
                'SELECT id, slug, title, subtitle, content, image, status, published_at '
                . 'FROM cms_akira_posts WHERE tenant_id = :tenant AND slug = :slug LIMIT 1 FOR UPDATE'
            );
            $find->execute([':tenant' => $tenantId, ':slug' => $slug]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($old)) {
                throw new CacPostMutationException('Post not found.', 404);
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
        } else {
            $write = $db->prepare(
                'UPDATE cms_akira_posts SET title = :title, subtitle = :subtitle, content = :content, '
                . 'image = :image, status = :status, published_at = :published_at '
                . 'WHERE tenant_id = :tenant AND slug = :slug'
            );
            $write->execute(array_merge([':tenant' => $tenantId, ':slug' => $slug], cacPostSqlFields($fields)));
            $postId = (int)$old['id'];
        }

        $correlationId = cacPostMutationCorrelationId();
        $audit = app()->cap()->call('kernel.audit.record@1', [
            'module' => 'cms-akira-core',
            'action' => 'cms.post.' . $operation,
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
function cac_cap_cms_post_create_1(mixed $payload, string $capabilityId = 'cms.post.create@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('create', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_cms_post_update_1(mixed $payload, string $capabilityId = 'cms.post.update@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        throw new CacPostMutationException('payload must be an object.');
    }
    return cacPostMutate('update', $payload);
}

/**
 * @return array<string, mixed>
 */
function cac_cap_entity_list_post_1(mixed $payload, string $capabilityId = 'entity.list.post@1', string $caller = 'unknown'): array
{
    $args = is_array($payload) ? $payload : [];
    $result = app()->cap()->call('cms.post.list@1', $args, [
        'caller' => ['module' => 'cms-akira-core'],
        'mode' => 'first',
    ]);
    if (!is_array($result) || ($result['ok'] ?? true) !== true || !is_array($result['rows'] ?? null)) {
        return ['ok' => false, 'rows' => [], 'total' => 0, 'error' => (string)($result['error'] ?? 'Post list unavailable')];
    }

    $rows = [];
    foreach ($result['rows'] as $post) {
        if (is_array($post)) {
            try {
                $rows[] = cacPostProject($post, false);
            } catch (InvalidArgumentException $e) {
                // Unsafe stored slugs are not presentation-safe records.
            }
        }
    }
    return ['ok' => true, 'rows' => $rows, 'total' => count($rows)];
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

    $result = app()->cap()->call('cms.post.get@1', ['slug' => $slug], [
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
