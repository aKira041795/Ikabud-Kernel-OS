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
    $fragment = casCtx()->render(__DIR__ . '/templates/admin.disyl', $context + [
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

    echo akiraSeoShellChrome('SEO metadata', $fragment, 'seo');
}

/**
 * Wrap the rendered SEO content fragment in the shared shell chrome through the
 * capability bus. The shell owns the only page builder, so this never rebuilds
 * the sidebar, copies the navigation list, or recreates the old three-link
 * header. If the capability is unavailable or denied, the request degrades to a
 * chrome-free document that still carries the fragment, so a shell failure can
 * never blank the page.
 */
function akiraSeoShellChrome(string $title, string $fragment, string $active): string
{
    try {
        $result = app()->cap()->call('akira.shell.admin_page@1', [
            'title' => $title,
            'body' => $fragment,
            'active' => $active,
        ], [
            'caller' => ['module' => 'cms-akira-seo', 'user' => app()->user()],
            'mode' => 'first',
        ]);
    } catch (Throwable $error) {
        return akiraSeoShellFallback($fragment);
    }

    $html = is_array($result) ? ($result['html'] ?? null) : null;
    return is_string($html) && $html !== '' ? $html : akiraSeoShellFallback($fragment);
}

function akiraSeoShellFallback(string $fragment): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>SEO metadata — CMS Akira</title></head>'
        . '<body style="margin:2rem auto;max-width:64rem;font-family:system-ui,sans-serif;line-height:1.5;color:#1e293b">'
        . '<p role="alert"><strong>The shared Akira administration shell could not be rendered.</strong> '
        . 'This page is shown without the sidebar.</p><hr>' . $fragment
        . '<p><a href="/cms-akira-shell">Return to the Akira dashboard</a></p></body></html>';
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
