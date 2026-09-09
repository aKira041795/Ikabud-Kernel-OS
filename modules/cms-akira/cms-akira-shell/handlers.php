<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string,mixed> $params */
function akiraPublicHome(array $params = []): void
{
    akiraPublicRenderPostList(true);
}

/** @param array<string,mixed> $params */
function akiraPublicPostList(array $params = []): void
{
    akiraPublicRenderPostList(false);
}

function akiraPublicRenderPostList(bool $home): void
{
    $query = akiraShellQuery();
    $page = max(1, (int) ($query['page'] ?? 1));
    $limit = $home ? 9 : 12;
    // Deliberately omit include_unpublished: the entity capability then applies
    // its tenant-scoped status='published' boundary for every caller.
    $resolved = app()->entityViews()->resolve('post', 'list', [
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
        'sort_field' => 'published_at',
        'sort_direction' => 'desc',
    ]);
    $posts = is_array($resolved['rows'] ?? null) ? array_values(array_filter($resolved['rows'], 'is_array')) : [];
    $total = (int) ($resolved['total'] ?? count($posts));
    $context = akiraPublicContext($home ? 'Latest stories' : 'All posts', $home ? '/' : '/posts');
    $context += [
        'posts' => $posts,
        'total' => $total,
        'page' => $page,
        'total_pages' => max(1, (int) ceil($total / $limit)),
        'previous_page' => max(1, $page - 1),
        'next_page' => $page + 1,
        'archive_url' => '/posts',
    ];
    $themed = akiraPublicThemeRender('entity.list.post', $context);
    echo $themed ?? app()->render(
        $home ? 'modules/cms-akira-shell/public/home.disyl' : 'modules/cms-akira-shell/public/posts.disyl',
        $context
    );
}

/** @param array<string,mixed> $params */
function akiraPublicPostSingle(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
        akiraPublicNotFound();
        return;
    }
    // resolveDetail calls entity.get.post@1 without include_unpublished. Drafts
    // and deleted records therefore collapse to the same public 404.
    $resolved = app()->entityViews()->resolveDetail('post', $slug, 'detail');
    $post = is_array($resolved['entity'] ?? null) ? $resolved['entity'] : null;
    if ($post === null) {
        akiraPublicNotFound();
        return;
    }
    $title = trim((string) ($post['title'] ?? 'Post'));
    $context = akiraPublicContext($title, '/posts/' . rawurlencode($slug), (string) ($post['subtitle'] ?? ''));
    $context['post'] = $post;
    $themed = akiraPublicThemeRender('entity.detail.post', $context);
    echo $themed ?? app()->render('modules/cms-akira-shell/public/single.disyl', $context);
}

function akiraPublicNotFound(): void
{
    http_response_code(404);
    echo app()->render('modules/cms-akira-shell/public/404.disyl', akiraPublicContext('Post not found', ''));
}

function akiraShellAuthorize(): bool
{
    $user = akiraShellParticipant();
    if ($user === null) {
        akiraShellRedirect('/login');
        return false;
    }
    if ($user === []) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>Your Kernel role does not participate in the Akira editorial workflow.</p><p><a href="/">Return home</a></p>');
        return false;
    }
    return true;
}

function akiraShellAuthorizeAdmin(): bool
{
    if (!akiraShellAuthorize()) {
        return false;
    }
    if (!akiraShellIsAdmin()) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>This surface is reserved for Akira administrators.</p>');
        return false;
    }
    return true;
}

function akiraShellAuthorizeTaxonomyManager(): bool
{
    if (!akiraShellAuthorize()) {
        return false;
    }
    if (!akiraShellIsTaxonomyManager()) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>This surface is reserved for Akira editors and administrators.</p>');
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
    $resolved = akiraShellAdminPostList([
        'filters' => ['include_unpublished' => true], 'limit' => 5, 'offset' => 0,
        'sort_field' => 'created_at', 'sort_direction' => 'desc',
    ]);
    if (akiraShellAdminReadDenied($resolved)) {
        return;
    }
    $publishedResult = akiraShellAdminPostList([
        'filters' => ['include_unpublished' => true, 'status' => 'published'], 'limit' => 1,
    ]);
    $draftResult = akiraShellAdminPostList([
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
    $adminQuickAction = akiraShellIsAdmin() ? '<a class="rounded-2xl bg-slate-50 p-4 font-semibold text-slate-700" href="/cms-akira-shell/compositions">Open compositions →</a>' : '';
    $body = '<section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><p class="max-w-2xl text-sm text-slate-500">Here is what is live, what is in progress, and what your team touched recently.</p><div class="flex gap-2"><a href="/cms-akira-shell/posts" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold">View all posts</a><a href="/cms-akira-shell/posts/new" class="rounded-2xl bg-akira-600 px-4 py-2.5 text-sm font-semibold text-white">+ Quick create</a></div></section>'
        . '<section class="grid gap-4 sm:grid-cols-3">' . $cards . '</section>'
        . '<section class="mt-6 grid gap-4 lg:grid-cols-[1.2fr_.8fr]"><div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 px-6 py-4"><h2 class="font-bold text-slate-950">Recent posts</h2></div>' . akiraShellRecentPosts($recent) . '</div>'
        . '<div class="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm"><h2 class="font-bold text-slate-950">Quick actions</h2><div class="mt-4 grid gap-3"><a class="rounded-2xl bg-akira-50 p-4 font-semibold text-akira-700" href="/cms-akira-shell/posts/new">Write a new post →</a>' . $adminQuickAction . '</div></div></section>';
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
    $category = max(0, (int)($query['category'] ?? 0));
    $resolved = akiraShellAdminPostList([
        'filters' => ['include_unpublished' => true, 'search' => $search, 'status' => $status, 'taxonomy_id' => $category > 0 ? $category : null],
        'limit' => $limit, 'offset' => ($page - 1) * $limit,
        'sort_field' => 'created_at', 'sort_direction' => 'desc',
    ]);
    if (akiraShellAdminReadDenied($resolved)) {
        return;
    }
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $total = (int)($resolved['total'] ?? count($rows));
    $categoryOptions = '';
    foreach (akiraShellCategories() as $term) {
        $termId = (int)($term['id'] ?? 0);
        if ($termId <= 0) {
            continue;
        }
        $categoryOptions .= '<option value="' . $termId . '"' . ($termId === $category ? ' selected' : '') . '>' . akiraShellEscape((string)($term['name'] ?? '')) . '</option>';
    }
    $categoryFilterSummary = $category > 0 ? '<p class="mt-2 text-xs text-slate-500">Showing posts filed under one category — clear the filter to see the whole library.</p>' : '';
    $body = '<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><span class="inline-flex rounded-full bg-akira-100 px-3 py-1 text-xs font-semibold text-akira-700">' . $total . ' post' . ($total === 1 ? '' : 's') . '</span><p class="mt-2 text-sm text-slate-500">Manage the editorial library through the governed Kernel entity-view pipeline.</p></div><a href="/cms-akira-shell/posts/new" class="rounded-2xl bg-akira-600 px-4 py-2.5 text-center text-sm font-semibold text-white">+ Create post</a></div>'
        . akiraShellNotice()
        . '<form method="get" class="mb-5 grid gap-3 rounded-[26px] border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-[1fr_150px_180px_auto]"><input type="search" name="q" value="' . akiraShellEscape($search) . '" placeholder="Search title, slug, or body…" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-akira-500 focus:outline-none"><select name="category" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">All categories</option>' . $categoryOptions . '</select><select name="status" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="">All statuses</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>Published</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>Draft</option></select><button class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white">Apply</button></form>'
        . $categoryFilterSummary
        . '<section data-akira-entity-view="post-list" class="akira-entity-list overflow-hidden rounded-[26px] border border-slate-200 bg-white p-2 shadow-sm">' . akiraShellPostTable($rows) . '</section>'
        . akiraShellPagination($page, $limit, $total, $search, $status, $category);
    echo akiraShellPage('Posts', $body, ['active' => 'posts']);
}

/** @param array<string,mixed> $post */
function akiraShellPostForm(array $post = [], string $error = ''): string
{
    $slug = trim((string)($post['slug'] ?? ''));
    $editing = $slug !== '' && isset($post['updated_at']);
    $action = $editing ? '/cms-akira-shell/posts/' . rawurlencode($slug) : '/cms-akira-shell/posts';
    $workflow = $editing ? akiraShellWorkflow($slug) : ['status' => 'draft', 'allowed_actions' => []];
    $workflowStatus = (string)($workflow['status'] ?? 'draft');
    $workflowActions = is_array($workflow['allowed_actions'] ?? null) ? $workflow['allowed_actions'] : [];
    $errorHtml = $error === '' ? '' : '<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><strong>Could not save:</strong> ' . akiraShellEscape($error) . '</div>';
    $control = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 focus:border-akira-500 focus:outline-none focus:ring-2 focus:ring-akira-500/20';
    // Categories panel (P1 increment 3): only editorial roles manage a post's
    // categories; the panel lists governed categories and its checkboxes POST
    // taxonomy_ids[] with the save. The workflow block below stays untouched.
    $categoryPanel = '';
    if (function_exists('app') && is_object(app()) && method_exists(app(), 'user') && akiraShellIsTaxonomyManager()) {
        $categoryPanel = akiraShellCategoryPanel(akiraShellCategories(), akiraShellAssignedTaxonomyIds($post));
    }
    // Revisions panel (P1 increment 4): additive section rendered BELOW the
    // editor form so the workflow block and allowed-actions markup above stay
    // byte-for-byte unchanged. Only saved posts have history to show.
    $revisionPanel = $editing ? akiraShellRevisionPanel($slug, (string)($post['updated_at'] ?? '')) : '';
    return '<form x-data="akiraContentEditor()" class="grid gap-5 lg:grid-cols-[1fr_280px]" method="post" action="' . akiraShellEscape($action) . '">' . akiraShellCsrfField() . '<div class="space-y-5">' . $errorHtml
        . '<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><label class="mb-2 block text-sm font-semibold">Title</label><input class="' . $control . ' text-xl font-bold" name="title" value="' . akiraShellEscape($post['title'] ?? '') . '" required><label class="mb-2 mt-5 block text-sm font-semibold">Slug</label><input class="' . $control . ' font-mono" name="slug" value="' . akiraShellEscape($slug) . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></div>'
        . '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><strong>Content</strong><button type="button" @click="preview=!preview" class="text-sm font-semibold text-akira-700" x-text="preview ? \'Edit\' : \'Preview\'"></button></div><textarea x-show="!preview" x-model="body" class="min-h-[480px] w-full resize-y border-0 p-5 font-mono text-sm focus:outline-none" name="content" required></textarea><div x-show="preview" x-cloak class="min-h-[480px] whitespace-pre-wrap p-5 text-sm leading-7" x-text="body"></div></div></div>'
        . '<aside><div class="sticky top-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">' . $categoryPanel . '<h2 class="font-bold">Workflow</h2><p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Current state</p><p data-akira-workflow-state class="mt-1 rounded-xl bg-slate-100 px-3 py-2 font-semibold text-slate-800">' . akiraShellEscape(ucwords(str_replace('_', ' ', $workflowStatus))) . '</p><input type="hidden" name="expected_updated_at" value="' . akiraShellEscape($post['updated_at'] ?? '') . '"><input type="hidden" name="expected_status" value="' . akiraShellEscape($workflowStatus) . '"><input type="hidden" name="idempotency_key" value="shell-' . bin2hex(random_bytes(12)) . '"><button class="mt-5 w-full rounded-xl bg-akira-600 px-5 py-3 font-semibold text-white hover:bg-akira-700" type="submit">Save post</button>' . akiraShellWorkflowActions($slug, $workflowActions) . '<a href="/cms-akira-shell/posts" class="mt-3 block text-center text-sm text-slate-500">Cancel</a></div></aside></form>' . $revisionPanel . '<script>function akiraContentEditor(){return{body:' . akiraShellJsString((string)($post['content'] ?? '')) . ',preview:false}}</script>';
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
function akiraShellPostWorkflowTransition(array $params = []): void
{
    akiraShellWorkflowTransition((string)($params['slug'] ?? ''));
}
/** @param array<string,mixed> $params */
function akiraShellPostDelete(array $params = []): void
{
    akiraShellMutation('akira.post.delete@1', (string)($params['slug'] ?? ''));
}

function akiraShellAuthorizePostTaxonomyManager(): bool
{
    if (!akiraShellAuthorize()) {
        return false;
    }
    if (!akiraShellIsTaxonomyManager()) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>This surface is reserved for Akira editors and administrators.</p>');
        return false;
    }
    return true;
}

/**
 * Direct post category assignment route. Every write flows through the
 * governed akira.post.set_taxonomies@1 capability (the seeded policy rows are
 * the authority for the admin/editor/administrator/superadmin role gate); this
 * handler only mirrors that allowlist for presentation. The post content row
 * is never touched here — assignment is a separate governed write.
 *
 * @param array<string,mixed> $params
 */
function akiraShellPostSetTaxonomies(array $params = []): void
{
    if (!akiraShellAuthorizePostTaxonomyManager()) {
        return;
    }
    app()->csrfEnforce();
    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
        http_response_code(422);
        echo akiraShellPage('Edit post', '<p>The post slug is invalid.</p>', ['active' => 'posts']);
        return;
    }
    $input = akiraShellInput();
    try {
        akiraShellCall('akira.post.set_taxonomies@1', akiraShellPostSetTaxonomyPayload($slug, $input));
        akiraShellInvalidatePublicCache($slug);
        akiraShellRedirect('/cms-akira-shell/posts/' . rawurlencode($slug) . '/edit?saved=categories');
    } catch (Throwable $e) {
        http_response_code(422);
        $post = akiraShellFetchPost($slug) ?? ['slug' => $slug];
        echo akiraShellPage('Edit post', akiraShellPostForm($post, $e->getMessage()), ['active' => 'posts']);
    }
}

/**
 * Post revision revert route. Every revert flows through the governed
 * akira.post.revision.revert@1 capability (its seeded policy rows are the
 * authority for the admin/editor/administrator/superadmin role gate); this
 * handler only mirrors that allowlist for presentation and renders a clean
 * 403 for authors/contributors who are not revision managers.
 *
 * @param array<string,mixed> $params
 */
function akiraShellPostRevisionRevert(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    if (!akiraShellIsPostRevisionManager()) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>Reverting post revisions is reserved for Akira editors and administrators.</p>');
        return;
    }
    app()->csrfEnforce();
    $slug = trim((string)($params['slug'] ?? ''));
    $revisionNo = is_numeric($params['revision_no'] ?? null) ? (int)$params['revision_no'] : 0;
    if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || $revisionNo <= 0) {
        http_response_code(422);
        echo akiraShellPage('Edit post', '<p>The post slug or revision is invalid.</p>', ['active' => 'posts']);
        return;
    }
    $input = akiraShellInput();
    $payload = [
        'slug' => $slug,
        'revision_no' => $revisionNo,
        'expected_updated_at' => trim((string)($input['expected_updated_at'] ?? '')),
        'idempotency_key' => trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? ''))),
    ];
    if ($payload['idempotency_key'] === '') {
        $payload['idempotency_key'] = 'post-revision-revert-' . bin2hex(random_bytes(10));
    }
    try {
        akiraShellCall('akira.post.revision.revert@1', $payload);
        akiraShellInvalidatePublicCache($slug);
        akiraShellRedirect('/cms-akira-shell/posts/' . rawurlencode($slug) . '/edit?saved=revision');
    } catch (Throwable $e) {
        if (str_contains(akiraShellRootErrorMessage($e), 'authorization denied')) {
            http_response_code(403);
            echo akiraShellPage('Access denied', '<p>Reverting post revisions is reserved for Akira editors and administrators.</p>');
            return;
        }
        http_response_code(422);
        $post = akiraShellFetchPost($slug) ?? ['slug' => $slug];
        echo akiraShellPage('Edit post', akiraShellPostForm($post, akiraShellRootErrorMessage($e)), ['active' => 'posts']);
    }
}

/**
 * Categories page — governed content taxonomy (categories + tags) CRUD.
 * The list itself is a participant read surface; manage actions render only
 * for editor/administrator roles and every POST flows through the governed
 * akira.taxonomy.* capabilities.
 *
 * @param array<string,mixed> $params
 */
function akiraShellCategoryList(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    akiraShellCategoryPage();
}

/** @param array<string,mixed> $params */
function akiraShellCategoryCreate(array $params = []): void
{
    if (!akiraShellAuthorizeTaxonomyManager()) {
        return;
    }
    app()->csrfEnforce();
    $input = akiraShellTaxonomyPayload();
    unset($input['_token']);
    try {
        akiraShellCall('akira.taxonomy.create@1', $input);
        akiraShellRedirect('/cms-akira-shell/categories?saved=create');
    } catch (Throwable $e) {
        http_response_code(422);
        akiraShellCategoryPage($e->getMessage(), ['name' => (string)($input['name'] ?? ''), 'slug' => (string)($input['slug'] ?? ''), 'type' => (string)($input['type'] ?? 'category')]);
    }
}

/** @param array<string,mixed> $params */
function akiraShellCategoryUpdate(array $params = []): void
{
    if (!akiraShellAuthorizeTaxonomyManager()) {
        return;
    }
    app()->csrfEnforce();
    $id = is_numeric($params['id'] ?? null) ? (int)$params['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        akiraShellCategoryPage('A taxonomy term id is required.');
        return;
    }
    $input = akiraShellTaxonomyPayload($id);
    unset($input['_token']);
    try {
        akiraShellCall('akira.taxonomy.update@1', $input);
        akiraShellRedirect('/cms-akira-shell/categories?saved=update');
    } catch (Throwable $e) {
        http_response_code(422);
        akiraShellCategoryPage($e->getMessage(), ['name' => (string)($input['name'] ?? ''), 'slug' => (string)($input['slug'] ?? '')]);
    }
}

/** @param array<string,mixed> $params */
function akiraShellCategoryDelete(array $params = []): void
{
    if (!akiraShellAuthorizeTaxonomyManager()) {
        return;
    }
    app()->csrfEnforce();
    $id = is_numeric($params['id'] ?? null) ? (int)$params['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        akiraShellCategoryPage('A taxonomy term id is required.');
        return;
    }
    $input = akiraShellTaxonomyPayload($id);
    unset($input['_token']);
    try {
        akiraShellCall('akira.taxonomy.delete@1', $input);
        akiraShellRedirect('/cms-akira-shell/categories?saved=delete');
    } catch (Throwable $e) {
        http_response_code(422);
        akiraShellCategoryPage($e->getMessage());
    }
}

/**
 * Categories page body: filter tabs with per-type counts, an inline create
 * form (name + slug + type) for managers, and the term table with confirmed
 * delete for managers.
 *
 * @param array<string,mixed> $preserve
 */
function akiraShellCategoryPage(string $error = '', array $preserve = []): void
{
    $query = akiraShellQuery();
    $typeFilter = in_array(($query['type'] ?? ''), ['category', 'tag'], true) ? (string)$query['type'] : '';
    $resolved = akiraShellCall('akira.taxonomy.list@1', ['limit' => 500, 'offset' => 0]);
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $categoryCount = 0;
    $tagCount = 0;
    $visible = [];
    foreach ($rows as $term) {
        if (!is_array($term)) {
            continue;
        }
        $type = (string)($term['type'] ?? '');
        $categoryCount += $type === 'category' ? 1 : 0;
        $tagCount += $type === 'tag' ? 1 : 0;
        if ($typeFilter === '' || $type === $typeFilter) {
            $visible[] = $term;
        }
    }
    $total = count($visible);
    $manager = akiraShellIsTaxonomyManager();
    $byId = [];
    foreach ($rows as $term) {
        if (is_array($term)) {
            $byId[(int)($term['id'] ?? 0)] = $term;
        }
    }
    $body = '<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><span class="inline-flex rounded-full bg-akira-100 px-3 py-1 text-xs font-semibold text-akira-700">' . $total . ' term' . ($total === 1 ? '' : 's') . '</span><p class="mt-2 text-sm text-slate-500">Content taxonomy is governed, tenant-scoped, and idempotently audited on every write.</p></div></div>'
        . akiraShellTaxonomyNotice()
        . akiraShellCategoryTabs($typeFilter, $total, $categoryCount, $tagCount)
        . ($manager ? akiraShellCategoryCreateForm($preserve, $error) : '')
        . ($error !== '' ? '<div role="alert" class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><strong>Could not save:</strong> ' . akiraShellEscape($error) . '</div>' : '')
        . akiraShellCategoryTable($visible, $byId, $manager);
    echo akiraShellPage('Categories', $body, ['active' => 'categories']);
}

function akiraShellCategoryTabs(string $active, int $total, int $categoryCount, int $tagCount): string
{
    $tab = static function (string $label, string $url, int $count, bool $isActive): string {
        $classes = $isActive ? 'bg-akira-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50';
        return '<a href="' . $url . '" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold ' . $classes . '">' . akiraShellEscape($label) . '<span class="rounded-full px-2 py-0.5 text-xs ' . ($isActive ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500') . '">' . $count . '</span></a>';
    };
    return '<div class="mb-5 flex flex-wrap gap-2">'
        . $tab('All', '/cms-akira-shell/categories', $total, $active === '')
        . $tab('Categories', '/cms-akira-shell/categories?type=category', $categoryCount, $active === 'category')
        . $tab('Tags', '/cms-akira-shell/categories?type=tag', $tagCount, $active === 'tag')
        . '</div>';
}

/**
 * @param array<string,mixed> $preserve
 */
function akiraShellCategoryCreateForm(array $preserve = [], string $error = ''): string
{
    $control = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 focus:border-akira-500 focus:outline-none focus:ring-2 focus:ring-akira-500/20';
    $name = akiraShellEscape((string)($preserve['name'] ?? ''));
    $slug = akiraShellEscape((string)($preserve['slug'] ?? ''));
    $type = in_array(($preserve['type'] ?? ''), ['category', 'tag'], true) ? (string)$preserve['type'] : 'category';
    return '<form method="post" action="/cms-akira-shell/categories" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
        . '<h2 class="font-bold text-slate-950">Create a taxonomy term</h2><p class="mt-1 text-sm text-slate-500">Categories can nest; tags are flat. Slugs stay canonical lowercase with hyphens.</p>'
        . '<div class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_170px_auto]"><input class="' . $control . '" name="name" value="' . $name . '" placeholder="Term name (e.g. Product News)" required><input class="' . $control . ' font-mono" name="slug" value="' . $slug . '" placeholder="Slug (e.g. product-news)" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required>'
        . '<select class="' . $control . '" name="type"><option value="category"' . ($type === 'category' ? ' selected' : '') . '>Category</option><option value="tag"' . ($type === 'tag' ? ' selected' : '') . '>Tag</option></select>'
        . '<button class="rounded-2xl bg-akira-600 px-5 py-3 text-sm font-semibold text-white hover:bg-akira-700" type="submit">Add term</button></div></form>';
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array<int,array<string,mixed>> $byId
 */
function akiraShellCategoryTable(array $rows, array $byId, bool $manager): string
{
    if ($rows === []) {
        return '<div class="rounded-[26px] border border-slate-200 bg-white p-12 text-center text-sm text-slate-400 shadow-sm">No taxonomy terms yet. Create your first category or tag.</div>';
    }
    $body = '';
    foreach ($rows as $term) {
        $body .= akiraShellCategoryRow($term, $byId, $manager);
    }
    return '<div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="grid grid-cols-[140px_1fr_1fr_200px_auto] items-center gap-4 border-b border-slate-100 bg-slate-50/60 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>Type</span><span>Term</span><span>Parent</span><span>Updated</span><span class="text-right">Actions</span></div>' . $body . '</div>';
}

/**
 * @param array<string,mixed> $term
 * @param array<int,array<string,mixed>> $byId
 */
function akiraShellCategoryRow(array $term, array $byId, bool $manager): string
{
    $id = (int)($term['id'] ?? 0);
    $type = (string)($term['type'] ?? 'category');
    $name = akiraShellEscape((string)($term['name'] ?? ''));
    $slug = akiraShellEscape((string)($term['slug'] ?? ''));
    $updated = akiraShellEscape((string)($term['updated_at'] ?? ''));
    $badge = $type === 'tag'
        ? 'bg-sky-100 text-sky-700'
        : 'bg-akira-100 text-akira-700';
    $parentId = (int)($term['parent_id'] ?? 0);
    $parentName = $parentId > 0 && isset($byId[$parentId]) ? akiraShellEscape((string)($byId[$parentId]['name'] ?? '')) : '';
    $parent = $parentName !== '' ? $parentName : '<span class="text-slate-300">—</span>';

    $control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-akira-500 focus:outline-none';
    $actions = '';
    if ($manager) {
        $actions = '<div class="flex justify-end gap-2">'
            . '<details class="relative"><summary class="cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Rename</summary>'
            . '<form method="post" action="/cms-akira-shell/categories/' . $id . '" class="absolute right-0 z-10 mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">' . akiraShellCsrfField()
            . '<div class="grid gap-2"><input class="' . $control . '" name="name" value="' . $name . '" required><input class="' . $control . ' font-mono" name="slug" value="' . $slug . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></div>'
            . '<input type="hidden" name="expected_updated_at" value="' . akiraShellEscape((string)($term['updated_at'] ?? '')) . '">'
            . '<input type="hidden" name="idempotency_key" value="taxonomy-' . bin2hex(random_bytes(10)) . '">'
            . '<button class="mt-3 w-full rounded-xl bg-akira-600 px-3 py-2 text-sm font-semibold text-white hover:bg-akira-700" type="submit">Save rename</button></form></details>'
            . '<form method="post" action="/cms-akira-shell/categories/' . $id . '/delete" onsubmit="return confirm(\'Delete this ' . akiraShellEscape($type) . '? Children move to the root; this cannot be undone.\')">' . akiraShellCsrfField()
            . '<input type="hidden" name="expected_updated_at" value="' . akiraShellEscape((string)($term['updated_at'] ?? '')) . '">'
            . '<input type="hidden" name="idempotency_key" value="taxonomy-' . bin2hex(random_bytes(10)) . '">'
            . '<button class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100" type="submit">Delete</button></form></div>';
    } else {
        $actions = '<div class="flex justify-end text-xs text-slate-300">Read only</div>';
    }
    return '<div class="grid grid-cols-[140px_1fr_1fr_200px_auto] items-center gap-4 border-b border-slate-100 px-6 py-4 last:border-0 hover:bg-slate-50/50">'
        . '<span><span class="rounded-full px-2.5 py-1 text-xs font-semibold ' . $badge . '">' . akiraShellEscape($type) . '</span></span>'
        . '<span><strong class="block text-sm text-slate-900">' . $name . '</strong><code class="text-xs text-slate-400">' . $slug . '</code></span>'
        . '<span class="text-sm text-slate-600">' . $parent . '</span>'
        . '<span class="text-xs text-slate-400">' . $updated . '</span>'
        . $actions . '</div>';
}

/**
 * Compositions admin — mounts the Akira Builder React app (list + create).
 * @param array<string,mixed> $params
 */
function akiraShellCompositions(array $params = []): void
{
    if (!akiraShellAuthorizeAdmin()) {
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
    if (!akiraShellAuthorizeAdmin()) {
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
    if (!akiraShellAuthorizeAdmin()) {
        return;
    }
    $required = ['akira.post.admin.get@1', 'akira.post.admin.list@1', 'akira.post.create@1', 'akira.post.update@1', 'akira.post.delete@1', 'akira.workflow.evaluate@1', 'akira.workflow.transition@1', 'entity.list.post@1', 'entity.get.post@1'];
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

function akiraShellAuthorizeContentTypeManager(): bool
{
    if (!akiraShellAuthorize()) {
        return false;
    }
    if (!akiraShellIsContentTypeManager()) {
        http_response_code(403);
        echo akiraShellPage('Access denied', '<p>This surface is reserved for Akira editors and administrators.</p>');
        return false;
    }
    return true;
}

/**
 * Content types page — governed declared content-model registry.
 * The list itself is a participant read surface; manage actions render only for
 * editor/administrator roles and every POST flows through the governed
 * akira.content_type.* capabilities.
 *
 * @param array<string,mixed> $params
 */
function akiraShellContentTypeList(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    akiraShellContentTypePage();
}

/** @param array<string,mixed> $params */
function akiraShellContentTypeCreate(array $params = []): void
{
    if (!akiraShellAuthorizeContentTypeManager()) {
        return;
    }
    app()->csrfEnforce();
    $input = akiraShellContentTypePayload();
    unset($input['_token']);
    try {
        akiraShellCall('akira.content_type.create@1', $input);
        akiraShellRedirect('/cms-akira-shell/content-types?saved=create');
    } catch (Throwable $e) {
        http_response_code(422);
        akiraShellContentTypePage(akiraShellContentTypePayloadError($e), [
            'label' => (string)($input['label'] ?? ''),
            'slug' => (string)($input['slug'] ?? ''),
            'field_schema' => (string)($input['field_schema'] ?? ''),
        ]);
    }
}

/** @param array<string,mixed> $params */
function akiraShellContentTypeUpdate(array $params = []): void
{
    if (!akiraShellAuthorizeContentTypeManager()) {
        return;
    }
    app()->csrfEnforce();
    $id = is_numeric($params['id'] ?? null) ? (int)$params['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        akiraShellContentTypePage('A content type id is required.');
        return;
    }
    $input = akiraShellContentTypePayload($id);
    unset($input['_token']);
    try {
        akiraShellCall('akira.content_type.update@1', $input);
        akiraShellRedirect('/cms-akira-shell/content-types?saved=update');
    } catch (Throwable $e) {
        http_response_code(422);
        akiraShellContentTypePage(akiraShellContentTypePayloadError($e), [
            'label' => (string)($input['label'] ?? ''),
            'slug' => (string)($input['slug'] ?? ''),
            'field_schema' => (string)($input['field_schema'] ?? ''),
        ]);
    }
}

/** @param array<string,mixed> $params */
function akiraShellContentTypeDelete(array $params = []): void
{
    if (!akiraShellAuthorizeContentTypeManager()) {
        return;
    }
    app()->csrfEnforce();
    $id = is_numeric($params['id'] ?? null) ? (int)$params['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        akiraShellContentTypePage('A content type id is required.');
        return;
    }
    $input = akiraShellContentTypePayload($id);
    unset($input['_token']);
    try {
        akiraShellCall('akira.content_type.delete@1', $input);
        akiraShellRedirect('/cms-akira-shell/content-types?saved=delete');
    } catch (Throwable $e) {
        http_response_code(422);
        akiraShellContentTypePage(akiraShellContentTypePayloadError($e));
    }
}

/**
 * Content types page body: count, an inline create form (label + slug +
 * field_schema JSON textarea) for managers, and the declared-model table with
 * inline edit and confirmed delete for managers.
 *
 * @param array<string,mixed> $preserve
 */
function akiraShellContentTypePage(string $error = '', array $preserve = []): void
{
    $resolved = akiraShellCall('akira.content_type.list@1', ['limit' => 500, 'offset' => 0]);
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $total = count($rows);
    $manager = akiraShellIsContentTypeManager();
    $body = '<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><span class="inline-flex rounded-full bg-akira-100 px-3 py-1 text-xs font-semibold text-akira-700">' . $total . ' content type' . ($total === 1 ? '' : 's') . '</span><p class="mt-2 text-sm text-slate-500">Declared content models are governed, tenant-scoped, and validated on write. Every field_schema is stored as a canonical JSON object.</p></div></div>'
        . akiraShellContentTypeNotice()
        . ($manager ? akiraShellContentTypeCreateForm($preserve, $error) : '')
        . ($error !== '' ? '<div role="alert" class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><strong>Could not save:</strong> ' . akiraShellEscape($error) . '</div>' : '')
        . akiraShellContentTypeTable($rows, $manager);
    echo akiraShellPage('Content types', $body, ['active' => 'content-types']);
}

/** @param array<string,mixed> $preserve */
function akiraShellContentTypeCreateForm(array $preserve = [], string $error = ''): string
{
    $control = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 focus:border-akira-500 focus:outline-none focus:ring-2 focus:ring-akira-500/20';
    $label = akiraShellEscape((string)($preserve['label'] ?? ''));
    $slug = akiraShellEscape((string)($preserve['slug'] ?? ''));
    $schema = akiraShellEscape(akiraShellContentTypePrettySchema((string)($preserve['field_schema'] ?? '{"fields":{"title":{"type":"text","required":true}}}')));
    return '<form method="post" action="/cms-akira-shell/content-types" class="mb-5 rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">' . akiraShellCsrfField()
        . '<h2 class="font-bold text-slate-950">Declare a content type</h2><p class="mt-1 text-sm text-slate-500">Give the model a label and canonical slug, then declare its fields as JSON: type must be text, textarea, number, boolean, date, select, or image.</p>'
        . '<div class="mt-4 grid gap-3 lg:grid-cols-[1fr_1fr]"><div><label class="mb-1 block text-xs font-semibold text-slate-500">Label</label><input class="' . $control . '" name="label" value="' . $label . '" placeholder="e.g. News article" required></div><div><label class="mb-1 block text-xs font-semibold text-slate-500">Slug</label><input class="' . $control . ' font-mono" name="slug" value="' . $slug . '" placeholder="e.g. news" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></div></div>'
        . '<label class="mb-1 mt-3 block text-xs font-semibold text-slate-500">Field schema (JSON)</label>'
        . '<textarea class="' . $control . ' min-h-[220px] resize-y font-mono text-xs" name="field_schema" required spellcheck="false">' . $schema . '</textarea>'
        . '<div class="mt-4 flex justify-end"><button class="rounded-2xl bg-akira-600 px-5 py-3 text-sm font-semibold text-white hover:bg-akira-700" type="submit">Create content type</button></div></form>';
}

/**
 * @param list<array<string,mixed>> $rows
 */
function akiraShellContentTypeTable(array $rows, bool $manager): string
{
    if ($rows === []) {
        return '<div class="rounded-[26px] border border-slate-200 bg-white p-12 text-center text-sm text-slate-400 shadow-sm">No declared content types yet. Declare your first model above.</div>';
    }
    $body = '';
    foreach ($rows as $row) {
        $body .= akiraShellContentTypeRow($row, $manager);
    }
    return '<div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm"><div class="grid grid-cols-[1.2fr_1fr_100px_200px_auto] items-center gap-4 border-b border-slate-100 bg-slate-50/60 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400"><span>Type</span><span>Slug</span><span>Fields</span><span>Updated</span><span class="text-right">Actions</span></div>' . $body . '</div>';
}

/** @param array<string,mixed> $row */
function akiraShellContentTypeRow(array $row, bool $manager): string
{
    $id = (int)($row['id'] ?? 0);
    $label = akiraShellEscape((string)($row['label'] ?? ''));
    $slug = akiraShellEscape((string)($row['slug'] ?? ''));
    $schema = (string)($row['field_schema'] ?? '');
    $fieldCount = akiraShellContentTypeFieldCount($schema);
    $updated = akiraShellEscape((string)($row['updated_at'] ?? ''));

    $control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-akira-500 focus:outline-none';
    $actions = '';
    if ($manager) {
        $schemaField = akiraShellEscape(akiraShellContentTypePrettySchema($schema));
        $actions = '<div class="flex justify-end gap-2">'
            . '<details class="relative"><summary class="cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Edit model</summary>'
            . '<form method="post" action="/cms-akira-shell/content-types/' . $id . '" class="absolute right-0 z-10 mt-2 w-[480px] rounded-2xl border border-slate-200 bg-white p-4 shadow-xl">' . akiraShellCsrfField()
            . '<div class="grid gap-2 sm:grid-cols-2"><div><label class="mb-1 block text-xs font-semibold text-slate-500">Label</label><input class="' . $control . '" name="label" value="' . $label . '" required></div><div><label class="mb-1 block text-xs font-semibold text-slate-500">Slug</label><input class="' . $control . ' font-mono" name="slug" value="' . $slug . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required></div></div>'
            . '<label class="mb-1 mt-2 block text-xs font-semibold text-slate-500">Field schema (JSON)</label>'
            . '<textarea class="' . $control . ' min-h-[180px] resize-y font-mono text-xs" name="field_schema" required spellcheck="false">' . $schemaField . '</textarea>'
            . '<input type="hidden" name="expected_updated_at" value="' . akiraShellEscape((string)($row['updated_at'] ?? '')) . '">'
            . '<input type="hidden" name="idempotency_key" value="content-type-' . bin2hex(random_bytes(10)) . '">'
            . '<button class="mt-3 w-full rounded-xl bg-akira-600 px-3 py-2 text-sm font-semibold text-white hover:bg-akira-700" type="submit">Save model</button></form></details>'
            . '<form method="post" action="/cms-akira-shell/content-types/' . $id . '/delete" onsubmit="return confirm(\'Delete this content type? Nothing references it yet, but this cannot be undone.\')">' . akiraShellCsrfField()
            . '<input type="hidden" name="expected_updated_at" value="' . akiraShellEscape((string)($row['updated_at'] ?? '')) . '">'
            . '<input type="hidden" name="idempotency_key" value="content-type-' . bin2hex(random_bytes(10)) . '">'
            . '<button class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100" type="submit">Delete</button></form></div>';
    } else {
        $actions = '<div class="flex justify-end text-xs text-slate-300">Read only</div>';
    }
    return '<div class="grid grid-cols-[1.2fr_1fr_100px_200px_auto] items-center gap-4 border-b border-slate-100 px-6 py-4 last:border-0 hover:bg-slate-50/50">'
        . '<span><strong class="block text-sm text-slate-900">' . $label . '</strong><span class="text-xs text-slate-400">Declared content model</span></span>'
        . '<code class="text-xs text-slate-400">' . $slug . '</code>'
        . '<span class="text-sm text-slate-600">' . $fieldCount . '</span>'
        . '<span class="text-xs text-slate-400">' . $updated . '</span>'
        . $actions . '</div>';
}


/** @param array<string,mixed> $params */
function akiraShellPermissions(array $params = []): void
{
    if (!akiraShellAuthorizeAdmin()) {
        return;
    }
    $result = akiraShellCall('akira.policy.list@1');
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $roles = ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin', 'manager', 'viewer'];
    $body = akiraShellGovernanceNotice('permissions');
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $allowed = array_filter(array_map('trim', explode(',', (string)($row['allowed_roles'] ?? ''))));
        $checks = '';
        foreach ($roles as $role) {
            $checks .= '<label class="flex items-center gap-2 text-xs"><input class="accent-akira-600" type="checkbox" name="allowed_roles[]" value="' . $role . '"' . (in_array($role, $allowed, true) ? ' checked' : '') . '>' . akiraShellEscape($role) . '</label>';
        }
        $body .= '<form method="post" action="/cms-akira-shell/permissions" class="mb-3 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[minmax(260px,1fr)_1fr_auto]">' . akiraShellCsrfField()
            . '<div><code class="text-sm font-semibold text-akira-700">' . akiraShellEscape($row['capability_id'] ?? '') . '</code><p class="mt-1 text-xs text-slate-400">' . akiraShellEscape($row['provider'] ?? '') . ' · caller ' . akiraShellEscape($row['caller_module'] ?? '') . '</p></div>'
            . '<div class="grid grid-cols-2 gap-2 sm:grid-cols-4">' . $checks . '</div>'
            . '<input type="hidden" name="capability_id" value="' . akiraShellEscape($row['capability_id'] ?? '') . '"><input type="hidden" name="capability_version" value="' . akiraShellEscape($row['capability_version'] ?? '') . '"><input type="hidden" name="provider" value="' . akiraShellEscape($row['provider'] ?? '') . '"><input type="hidden" name="caller_module" value="' . akiraShellEscape($row['caller_module'] ?? '') . '"><input type="hidden" name="idempotency_key" value="policy-' . bin2hex(random_bytes(10)) . '"><button class="self-center rounded-xl bg-akira-600 px-4 py-2 text-sm font-semibold text-white">Save</button></form>';
    }
    echo akiraShellPage('Permissions', '<p class="mb-5 text-sm text-slate-500">Each save clones the complete active policy into a new version and changes only the selected Akira capability.</p>' . $body, ['active' => 'permissions']);
}

/** @param array<string,mixed> $params */
function akiraShellPermissionUpdate(array $params = []): void
{
    if (!akiraShellAuthorizeAdmin()) {
        return;
    }
    app()->csrfEnforce();
    $input = akiraShellInput();
    try {
        akiraShellCall('akira.policy.set_roles@1', $input);
        akiraShellRedirect('/cms-akira-shell/permissions?saved=1');
    } catch (Throwable $error) {
        http_response_code(str_contains(akiraShellRootErrorMessage($error), 'authorization denied') ? 403 : 422);
        echo akiraShellPage('Permissions', '<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700">' . akiraShellEscape(akiraShellRootErrorMessage($error)) . '</div>', ['active' => 'permissions']);
    }
}

/** @param array<string,mixed> $params */
function akiraShellUsers(array $params = []): void
{
    if (!akiraShellAuthorizeAdmin()) {
        return;
    }
    $result = akiraShellCall('akira.user.list@1');
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $roles = ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin', 'manager', 'viewer'];
    $body = akiraShellGovernanceNotice('users');
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int)($row['id'] ?? 0);
        $options = '';
        foreach ($roles as $role) {
            $options .= '<option value="' . $role . '"' . (($row['role'] ?? '') === $role ? ' selected' : '') . '>' . $role . '</option>';
        }
        $active = (int)($row['is_active'] ?? 0) === 1;
        $body .= '<div class="grid gap-4 border-b border-slate-100 px-5 py-4 last:border-0 lg:grid-cols-[1.2fr_1.2fr_1fr_auto_auto] lg:items-center"><div><strong>' . akiraShellEscape($row['full_name'] ?? '') . '</strong><code class="block text-xs text-slate-400">#' . $id . ' ' . akiraShellEscape($row['username'] ?? '') . '</code></div><span class="text-sm text-slate-500">' . akiraShellEscape($row['email'] ?? '') . '</span><span class="text-xs text-slate-400">' . akiraShellEscape($row['created_at'] ?? '') . '</span>'
            . '<form method="post" action="/cms-akira-shell/users/' . $id . '/role" class="flex gap-2">' . akiraShellCsrfField() . '<input type="hidden" name="idempotency_key" value="user-role-' . bin2hex(random_bytes(8)) . '"><select name="role" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">' . $options . '</select><button class="rounded-xl bg-akira-600 px-3 py-2 text-xs font-semibold text-white">Save role</button></form>'
            . '<form method="post" action="/cms-akira-shell/users/' . $id . '/active">' . akiraShellCsrfField() . '<input type="hidden" name="idempotency_key" value="user-active-' . bin2hex(random_bytes(8)) . '"><input type="hidden" name="is_active" value="' . ($active ? '0' : '1') . '"><button class="rounded-xl border px-3 py-2 text-xs font-semibold ' . ($active ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700') . '">' . ($active ? 'Deactivate' : 'Activate') . '</button></form></div>';
    }
    echo akiraShellPage('Users', '<p class="mb-5 text-sm text-slate-500">Manage existing tenant identities. Role and activity changes revoke current sessions by incrementing token_version.</p><div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white shadow-sm">' . $body . '</div>', ['active' => 'users']);
}

/** @param array<string,mixed> $params */
function akiraShellUserUpdateRole(array $params = []): void
{
    akiraShellUserMutation('akira.user.update_role@1', $params);
}
/** @param array<string,mixed> $params */
function akiraShellUserSetActive(array $params = []): void
{
    akiraShellUserMutation('akira.user.set_active@1', $params);
}

/** @param array<string,mixed> $params */
function akiraShellUserMutation(string $capability, array $params): void
{
    if (!akiraShellAuthorizeAdmin()) {
        return;
    }
    app()->csrfEnforce();
    $input = akiraShellInput();
    $input['user_id'] = (int)($params['id'] ?? 0);
    try {
        akiraShellCall($capability, $input);
        akiraShellRedirect('/cms-akira-shell/users?saved=1');
    } catch (Throwable $error) {
        http_response_code(str_contains(akiraShellRootErrorMessage($error), 'authorization denied') ? 403 : 422);
        echo akiraShellPage('Users', '<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700">' . akiraShellEscape(akiraShellRootErrorMessage($error)) . '</div>', ['active' => 'users']);
    }
}

function akiraShellGovernanceNotice(string $surface): string
{
    return (akiraShellQuery()['saved'] ?? '') === '1' ? '<div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">' . ucfirst($surface) . ' updated.</div>' : '';
}
