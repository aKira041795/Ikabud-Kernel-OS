<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function akiraSeoHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-seo',
        'version' => '1.0.0',
        'authority' => 'native',
    ], JSON_UNESCAPED_SLASHES);
}

/** @return array<string, mixed>|null */
function akiraSeoAdmin(): ?array
{
    $user = app()->user();
    if (!is_array($user) || (int) ($user['id'] ?? $user['sub'] ?? 0) <= 0) {
        return null;
    }
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'administrator', 'superadmin'], true)) {
        return [];
    }
    if ($role === 'superadmin' && (string) ($user['source'] ?? '') !== 'kernel') {
        return [];
    }
    return $user;
}

/** @return array<string, mixed> */
function akiraSeoInput(): array
{
    $input = casCtx()->input();
    return is_array($input) ? $input : [];
}

/** @return array{status: int, message: string} */
function akiraSeoFailureDetail(Throwable $error): array
{
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CasSeoMutationException) {
            return ['status' => $cursor->httpStatus, 'message' => $cursor->getMessage()];
        }
    }
    return ['status' => 500, 'message' => 'SEO metadata operation failed.'];
}

/** @param array<string, mixed> $context */
function akiraSeoRender(array $context = []): void
{
    echo casCtx()->render(__DIR__ . '/templates/admin.disyl', $context + [
        'page_title' => 'SEO metadata',
        'record' => [],
        'entity_type' => '',
        'entity_key' => '',
        'csrf_field' => app()->csrfField(),
        'upsert_idempotency_key' => 'seo-upsert-' . bin2hex(random_bytes(16)),
        'delete_idempotency_key' => 'seo-delete-' . bin2hex(random_bytes(16)),
        'saved' => false,
        'deleted' => false,
        'error' => '',
    ]);
}

/** @param array<string, string> $params */
function akiraSeoAdminPage(array $params = []): void
{
    $admin = akiraSeoAdmin();
    if ($admin === null) {
        header('Location: /login', true, 303);
        return;
    }
    if ($admin === []) {
        http_response_code(403);
        akiraSeoRender(['error' => 'Your Kernel role cannot administer SEO metadata.']);
        return;
    }

    $entityType = trim((string) ($_GET['entity_type'] ?? ''));
    $entityKey = trim((string) ($_GET['entity_key'] ?? ''));
    $context = [
        'entity_type' => $entityType,
        'entity_key' => $entityKey,
        'saved' => (string) ($_GET['saved'] ?? '') === '1',
        'deleted' => (string) ($_GET['deleted'] ?? '') === '1',
    ];
    if ($entityType === '' && $entityKey === '') {
        akiraSeoRender($context);
        return;
    }

    try {
        $result = app()->cap()->call('akira.seo.get@1', [
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
        ], [
            'caller' => ['module' => 'cms-akira-seo', 'user' => $admin],
            'mode' => 'first',
        ]);
        if (is_array($result) && ($result['ok'] ?? false) === true && is_array($result['data'] ?? null)) {
            $context['record'] = $result['data'];
        } elseif (!(is_array($result) && ($result['error'] ?? '') === 'SEO metadata not found')) {
            throw new RuntimeException(is_array($result) ? (string) ($result['error'] ?? 'SEO metadata read failed.') : 'SEO metadata read failed.');
        }
        akiraSeoRender($context);
    } catch (Throwable $error) {
        http_response_code(503);
        akiraSeoRender($context + ['error' => $error->getMessage()]);
    }
}

/** @param array<string, string> $params */
function akiraSeoUpsertForm(array $params = []): void
{
    $admin = akiraSeoAdmin();
    if ($admin === null) {
        header('Location: /login', true, 303);
        return;
    }
    if ($admin === []) {
        http_response_code(403);
        akiraSeoRender(['error' => 'Your Kernel role cannot administer SEO metadata.']);
        return;
    }

    app()->csrfEnforce();
    $payload = akiraSeoInput();
    try {
        app()->cap()->call('akira.seo.upsert@1', $payload, [
            'caller' => ['module' => 'cms-akira-seo', 'user' => $admin],
            'mode' => 'first',
        ]);
        $query = http_build_query([
            'entity_type' => (string) ($payload['entity_type'] ?? ''),
            'entity_key' => (string) ($payload['entity_key'] ?? ''),
            'saved' => '1',
        ]);
        header('Location: /cms-akira-seo?' . $query, true, 303);
    } catch (Throwable $error) {
        $detail = akiraSeoFailureDetail($error);
        http_response_code($detail['status']);
        akiraSeoRender([
            'entity_type' => (string) ($payload['entity_type'] ?? ''),
            'entity_key' => (string) ($payload['entity_key'] ?? ''),
            'record' => $payload,
            'error' => $detail['message'],
        ]);
    }
}

/** @param array<string, string> $params */
function akiraSeoDeleteForm(array $params = []): void
{
    $admin = akiraSeoAdmin();
    if ($admin === null) {
        header('Location: /login', true, 303);
        return;
    }
    if ($admin === []) {
        http_response_code(403);
        akiraSeoRender(['error' => 'Your Kernel role cannot administer SEO metadata.']);
        return;
    }

    app()->csrfEnforce();
    $payload = akiraSeoInput();
    try {
        app()->cap()->call('akira.seo.delete@1', $payload, [
            'caller' => ['module' => 'cms-akira-seo', 'user' => $admin],
            'mode' => 'first',
        ]);
        $query = http_build_query([
            'entity_type' => (string) ($payload['entity_type'] ?? ''),
            'entity_key' => (string) ($payload['entity_key'] ?? ''),
            'deleted' => '1',
        ]);
        header('Location: /cms-akira-seo?' . $query, true, 303);
    } catch (Throwable $error) {
        $detail = akiraSeoFailureDetail($error);
        http_response_code($detail['status']);
        akiraSeoRender([
            'entity_type' => (string) ($payload['entity_type'] ?? ''),
            'entity_key' => (string) ($payload['entity_key'] ?? ''),
            'error' => $detail['message'],
        ]);
    }
}
