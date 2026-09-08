<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function akiraShellAuthorize(): bool
{
    $user = akiraShellAdmin();
    if ($user === null) {
        akiraShellRedirect('/login');
        return false;
    }
    if ($user === []) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>Your Kernel role cannot administer CMS Akira.</p><p><a href="/">Return home</a></p>');
        return false;
    }
    return true;
}

/**
 * Presentation only: authentication remains at the stable Kernel endpoint.
 * @param array<string,mixed> $params
 */
function akiraShellLogin(array $params = []): void
{
    if (is_array(app()->user())) {
        akiraShellRedirect('/cms-akira-shell');
        return;
    }
    akiraShellRedirect('/login');
}

/** @param array<string,mixed> $params */
function akiraShellDashboard(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $list = app()->entityViews()->resolve('post', 'list', ['limit' => 5, 'offset' => 0]);
    $count = is_array($list['rows'] ?? null) ? count($list['rows']) : 0;
    $body = '<p class="mb-6 text-slate-500">Kernel-authenticated content administration powered by the Akira capability layer.</p>'
        . '<section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Published posts</p><p class="mt-2 text-4xl font-bold text-slate-950">' . $count . '</p></div>'
        . '<a href="/cms-akira-shell/posts/new" class="rounded-2xl border border-akira-100 bg-akira-50 p-6 shadow-sm hover:border-akira-500"><p class="text-sm font-medium text-akira-700">Quick action</p><p class="mt-2 text-lg font-bold text-slate-950">Create a post →</p></a>'
        . '<a href="/cms-akira-shell/compositions" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm hover:border-akira-300"><p class="text-sm font-medium text-slate-500">Visual content</p><p class="mt-2 text-lg font-bold text-slate-950">Open compositions →</p></a></section>';
    echo akiraShellPage('CMS Akira Dashboard', $body, ['active' => 'dashboard']);
}

/** @param array<string,mixed> $params */
function akiraShellPostList(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $resolved = app()->entityViews()->resolve('post', 'list', ['limit' => 100, 'offset' => 0]);
    $count = is_array($resolved['rows'] ?? null) ? count($resolved['rows']) : 0;
    $body = '<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><span class="inline-flex rounded-full bg-akira-100 px-3 py-1 text-xs font-semibold text-akira-700">' . $count . ' post' . ($count === 1 ? '' : 's') . '</span><p class="mt-2 text-sm text-slate-500">Rendered through the registered Kernel entity-view contract.</p></div>'
        . '<a href="/cms-akira-shell/posts/new" class="inline-flex items-center justify-center rounded-xl bg-akira-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-akira-700">+ Create post</a></div>'
        . '<section data-akira-entity-view="post-list" class="akira-entity-list overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">' . akiraShellEntityList($resolved) . '</section>';
    echo akiraShellPage('Posts', $body, ['active' => 'posts']);
}

/** @param array<string,mixed> $post */
function akiraShellPostForm(array $post = []): string
{
    $slug = trim((string)($post['slug'] ?? ''));
    $action = $slug === '' ? '/cms-akira-shell/posts' : '/cms-akira-shell/posts/' . rawurlencode($slug);
    $field = 'mb-1 block text-sm font-semibold text-slate-700';
    $control = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 focus:border-akira-500 focus:outline-none focus:ring-2 focus:ring-akira-500/20';
    return '<form class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" method="post" action="' . akiraShellEscape($action) . '">' . akiraShellCsrfField()
        . '<div><label class="' . $field . '">Slug</label><input class="' . $control . '" name="slug" value="' . akiraShellEscape($slug) . '" required></div>'
        . '<div><label class="' . $field . '">Title</label><input class="' . $control . '" name="title" value="' . akiraShellEscape($post['title'] ?? '') . '" required></div>'
        . '<div><label class="' . $field . '">Excerpt</label><textarea class="' . $control . '" rows="3" name="excerpt">' . akiraShellEscape($post['excerpt'] ?? '') . '</textarea></div>'
        . '<div><label class="' . $field . '">Content</label><textarea class="' . $control . '" rows="14" name="content" required>' . akiraShellEscape($post['content'] ?? '') . '</textarea></div>'
        . '<input type="hidden" name="expected_version" value="' . akiraShellEscape($post['version'] ?? 0) . '"><input type="hidden" name="idempotency_key" value="shell-' . bin2hex(random_bytes(12)) . '">'
        . '<button class="rounded-xl bg-akira-600 px-5 py-3 font-semibold text-white hover:bg-akira-700" type="submit">Save post</button></form>';
}

/** @param array<string,mixed> $params */
function akiraShellPostCreateForm(array $params = []): void
{
    if (akiraShellAuthorize()) {
        echo akiraShellPage('Create post', akiraShellPostForm(), ['active' => 'posts']);
    }
}

/** @param array<string,mixed> $params */
function akiraShellPostEditForm(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    try {
        $post = akiraShellCall('akira.post.get@1', ['slug' => (string)($params['slug'] ?? ''), 'include_unpublished' => true]);
        $post = is_array($post['post'] ?? null) ? $post['post'] : (is_array($post) ? $post : []);
        $slug = rawurlencode((string)($post['slug'] ?? ($params['slug'] ?? '')));
        $version = akiraShellEscape($post['version'] ?? 0);
        $actions = '';
        foreach (['publish' => 'Publish', 'unpublish' => 'Unpublish', 'delete' => 'Delete'] as $operation => $label) {
            $actions .= '<form method="post" action="/cms-akira-shell/posts/' . $slug . '/' . $operation . '">'
                . akiraShellCsrfField()
                . '<input type="hidden" name="expected_version" value="' . $version . '">'
                . '<input type="hidden" name="idempotency_key" value="shell-' . bin2hex(random_bytes(12)) . '">'
                . '<button type="submit">' . $label . '</button></form>';
        }
        echo akiraShellPage('Edit post', akiraShellPostForm($post) . '<section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6"><h2 class="mb-4 text-lg font-bold">Lifecycle</h2><div class="flex flex-wrap gap-3">' . $actions . '</div></section>', ['active' => 'posts']);
    } catch (Throwable $e) {
        http_response_code(404);
        echo akiraShellPage('Post not found', '<p>The requested post is unavailable.</p>');
    }
}

/** @param array<string,mixed> $params */
function akiraShellPostCreate(array $params = []): void
{
    akiraShellMutation('akira.post.create@1');
}
/** @param array<string,mixed> $params */
function akiraShellPostUpdate(array $params = []): void
{
    akiraShellMutation('akira.post.update@1', (string)($params['slug'] ?? ''));
}
/** @param array<string,mixed> $params */
function akiraShellPostPublish(array $params = []): void
{
    akiraShellMutation('akira.post.publish@1', (string)($params['slug'] ?? ''));
}
/** @param array<string,mixed> $params */
function akiraShellPostUnpublish(array $params = []): void
{
    akiraShellMutation('akira.post.unpublish@1', (string)($params['slug'] ?? ''));
}
/** @param array<string,mixed> $params */
function akiraShellPostDelete(array $params = []): void
{
    akiraShellMutation('akira.post.delete@1', (string)($params['slug'] ?? ''));
}

/**
 * Compositions admin — mounts the Akira Builder React app (list + create).
 * @param array<string,mixed> $params
 */
function akiraShellCompositions(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $resolved = app()->entityViews()->resolve('post', 'list', ['limit' => 200, 'offset' => 0]);
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $posts = array_values(array_filter(array_map(static fn (mixed $row): array => [
        'entity_type' => 'post',
        'entity_key' => (string) ($row['slug'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
    ], $rows), static fn (array $post): bool => $post['entity_key'] !== ''));
    echo akiraShellPage('Compositions', '<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">' . akiraShellBuilderAdmin([
        'mode' => 'list',
        'apiBase' => '/api/v1/cms-akira/builder',
        'posts' => $posts,
    ]) . '</div>', ['active' => 'compositions']);
}

/**
 * Composition editor page — mounts the Akira Builder React app for one entity.
 * @param array<string,mixed> $params
 */
function akiraShellCompositionEdit(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $key = trim((string) ($params['key'] ?? ''));
    if ($key === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) !== 1) {
        http_response_code(422);
        echo akiraShellPage('Invalid composition', '<p>The composition key is invalid.</p>');
        return;
    }
    echo akiraShellPage('Edit composition', '<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">' . akiraShellBuilderAdmin([
        'mode' => 'edit',
        'apiBase' => '/api/v1/cms-akira/builder',
        'entity_key' => $key,
        'posts' => [],
    ]) . '</div>', ['active' => 'compositions']);
}

/** @param array<string,mixed> $params */
function akiraShellModuleHealth(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $required = ['akira.post.get@1', 'akira.post.list@1', 'akira.post.create@1', 'akira.post.update@1', 'akira.post.publish@1', 'akira.post.unpublish@1', 'akira.post.delete@1', 'entity.list.post@1', 'entity.get.post@1'];
    $items = '';
    $ok = true;
    foreach ($required as $capability) {
        $present = app()->capabilities()->has($capability);
        $ok = $ok && $present;
        $items .= '<li class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-3 last:border-0"><code class="text-sm text-slate-700">' . akiraShellEscape($capability) . '</code><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . ($present ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700') . '">' . ($present ? 'Available' : 'Missing') . '</span></li>';
    }
    if (!$ok) {
        http_response_code(503);
    }
    echo akiraShellPage('Module health', '<div class="mb-5 inline-flex rounded-full px-3 py-1 text-sm font-semibold ' . ($ok ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700') . '">Overall: ' . ($ok ? 'Healthy' : 'Degraded') . '</div><ul class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">' . $items . '</ul>', ['active' => 'health']);
}

/** @param array<string,mixed> $params */
function akiraShellForbidden(array $params = []): void
{
    http_response_code(403);
    echo akiraShellPage('Access denied', '<p>Your Kernel role cannot administer CMS Akira.</p>');
}
