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
    $resolved = app()->entityViews()->resolve('post', 'list', [
        'filters' => ['include_unpublished' => true], 'limit' => 5, 'offset' => 0,
        'sort_field' => 'created_at', 'sort_direction' => 'desc',
    ]);
    $publishedResult = app()->entityViews()->resolve('post', 'list', [
        'filters' => ['include_unpublished' => true, 'status' => 'published'], 'limit' => 1,
    ]);
    $draftResult = app()->entityViews()->resolve('post', 'list', [
        'filters' => ['include_unpublished' => true, 'status' => 'draft'], 'limit' => 1,
    ]);
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $published = (int)($publishedResult['total'] ?? 0);
    $drafts = (int)($draftResult['total'] ?? 0);
    $cards = '';
    foreach ([['Published', $published, 'emerald'], ['Drafts', $drafts, 'amber'], ['Total posts', (int)($resolved['total'] ?? ($published + $drafts)), 'akira']] as [$label, $value, $color]) {
        $cards .= '<div class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><span class="inline-flex rounded-full bg-' . $color . '-100 px-2.5 py-1 text-xs font-semibold text-' . $color . '-700">' . $label . '</span><p class="mt-4 text-4xl font-bold text-slate-950">' . $value . '</p></div>';
    }
    $recent = array_slice($rows, 0, 5);
    $body = '<section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><p class="max-w-2xl text-sm text-slate-500">Here is what is live, what is in progress, and what your team touched recently.</p><div class="flex gap-2"><a href="/cms-akira-shell/posts" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold">View all posts</a><a href="/cms-akira-shell/posts/new" class="rounded-2xl bg-akira-600 px-4 py-2.5 text-sm font-semibold text-white">+ Quick create</a></div></section>'
        . '<section class="grid gap-4 sm:grid-cols-3">' . $cards . '</section>'
        . '<section class="mt-6 grid gap-4 lg:grid-cols-[1.2fr_.8fr]"><div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 px-6 py-4"><h2 class="font-bold text-slate-950">Recent posts</h2></div>' . akiraShellRecentPosts($recent) . '</div>'
        . '<div class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="font-bold text-slate-950">Quick actions</h2><div class="mt-4 grid gap-3"><a class="rounded-2xl bg-akira-50 p-4 font-semibold text-akira-700" href="/cms-akira-shell/posts/new">Write a new post →</a><a class="rounded-2xl bg-slate-50 p-4 font-semibold text-slate-700" href="/cms-akira-shell/compositions">Open compositions →</a></div></div></section>';
    echo akiraShellPage('CMS Akira Dashboard', $body, ['active' => 'dashboard']);
}

/** @param array<string,mixed> $params */
function akiraShellPostList(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $query = akiraShellQuery();
    $page = max(1, (int)($query['page'] ?? 1));
    $limit = 10;
    $search = trim((string)($query['q'] ?? ''));
    $status = in_array(($query['status'] ?? ''), ['draft', 'published'], true) ? (string)$query['status'] : '';
    $resolved = app()->entityViews()->resolve('post', 'list', [
        'filters' => ['include_unpublished' => true, 'search' => $search, 'status' => $status],
        'limit' => $limit, 'offset' => ($page - 1) * $limit,
        'sort_field' => 'created_at', 'sort_direction' => 'desc',
    ]);
    $total = (int)($resolved['total'] ?? count($resolved['rows'] ?? []));
    $body = '<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><span class="inline-flex rounded-full bg-akira-100 px-3 py-1 text-xs font-semibold text-akira-700">' . $total . ' post' . ($total === 1 ? '' : 's') . '</span><p class="mt-2 text-sm text-slate-500">Manage the editorial library through the governed Kernel entity-view pipeline.</p></div><a href="/cms-akira-shell/posts/new" class="rounded-2xl bg-akira-600 px-4 py-2.5 text-center text-sm font-semibold text-white">+ Create post</a></div>'
        . akiraShellNotice()
        . '<form method="get" class="mb-5 grid gap-3 rounded-[26px] border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-[1fr_180px_auto]"><input type="search" name="q" value="' . akiraShellEscape($search) . '" placeholder="Search title, slug, or body…" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-akira-500 focus:outline-none"><select name="status" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">All statuses</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Published</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Draft</option></select><button class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Apply</button></form>'
        . '<section data-akira-entity-view="post-list" class="akira-entity-list overflow-hidden rounded-[26px] border border-slate-200 bg-white p-2 shadow-sm">' . akiraShellEntityList($resolved) . '</section>'
        . akiraShellPagination($page, $limit, $total, $search, $status);
    echo akiraShellPage('Posts', $body, ['active' => 'posts']);
}

/** @param array<string,mixed> $post */
function akiraShellPostForm(array $post = [], string $error = ''): string
{
    $slug = trim((string)($post['slug'] ?? ''));
    $editing = $slug !== '' && isset($post['updated_at']);
    $action = $editing ? '/cms-akira-shell/posts/' . rawurlencode($slug) : '/cms-akira-shell/posts';
    $status = (string)($post['status'] ?? 'draft');
    $errorHtml = $error === '' ? '' : '<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><strong>Could not save:</strong> ' . akiraShellEscape($error) . '</div>';
    $control = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 focus:border-akira-500 focus:outline-none focus:ring-2 focus:ring-akira-500/20';
    return '<form x-data="akiraContentEditor()" class="grid gap-5 lg:grid-cols-[1fr_280px]" method="post" action="' . akiraShellEscape($action) . '">' . akiraShellCsrfField() . '<div class="space-y-5">' . $errorHtml
        . '<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><label class="mb-2 block text-sm font-semibold">Title</label><input class="' . $control . ' text-xl font-bold" name="title" value="' . akiraShellEscape($post['title'] ?? '') . '" required><label class="mb-2 mt-5 block text-sm font-semibold">Slug</label><input class="' . $control . ' font-mono" name="slug" value="' . akiraShellEscape($slug) . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></div>'
        . '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><strong>Content</strong><button type="button" @click="preview=!preview" class="text-sm font-semibold text-akira-700" x-text="preview ? \'Edit\' : \'Preview\'"></button></div><textarea x-show="!preview" x-model="body" class="min-h-[480px] w-full resize-y border-0 p-5 font-mono text-sm focus:outline-none" name="content" required></textarea><div x-show="preview" x-cloak class="min-h-[480px] whitespace-pre-wrap p-5 text-sm leading-7" x-text="body"></div></div></div>'
        . '<aside><div class="sticky top-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-bold">Publish</h2><label class="mb-2 mt-4 block text-sm font-semibold">Status</label><select name="status" class="' . $control . '"><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Draft</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Published</option></select><input type="hidden" name="expected_updated_at" value="' . akiraShellEscape($post['updated_at'] ?? '') . '"><input type="hidden" name="idempotency_key" value="shell-' . bin2hex(random_bytes(12)) . '"><button class="mt-5 w-full rounded-xl bg-akira-600 px-5 py-3 font-semibold text-white hover:bg-akira-700" type="submit">Save post</button><a href="/cms-akira-shell/posts" class="mt-3 block text-center text-sm text-slate-500">Cancel</a></div></aside></form><script>function akiraContentEditor(){return{body:' . akiraShellJsString((string)($post['content'] ?? '')) . ',preview:false}}</script>';
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
    $post = akiraShellFetchPost((string)($params['slug'] ?? ''));
    if ($post === null) {
        http_response_code(404);
        echo akiraShellPage('Post not found', '<p>The requested post is unavailable.</p>', ['active' => 'posts']);
        return;
    }
    echo akiraShellPage('Edit post', akiraShellPostForm($post), ['active' => 'posts']);
}

/** @param array<string,mixed> $params */
function akiraShellPostCreate(array $params = []): void
{
    akiraShellSavePost(null);
}
/** @param array<string,mixed> $params */
function akiraShellPostUpdate(array $params = []): void
{
    akiraShellSavePost((string)($params['slug'] ?? ''));
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
