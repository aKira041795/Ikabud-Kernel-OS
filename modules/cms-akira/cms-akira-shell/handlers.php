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
    echo akiraShellPage('CMS Akira Dashboard', '<p>Kernel-authenticated content administration.</p><section><h2>Published posts</h2><p>' . $count . '</p></section>');
}

/** @param array<string,mixed> $params */
function akiraShellPostList(array $params = []): void
{
    if (!akiraShellAuthorize()) {
        return;
    }
    $resolved = app()->entityViews()->resolve('post', 'list', ['limit' => 100, 'offset' => 0]);
    $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
    $body = '<p><a href="/cms-akira-shell/posts/new">Create post</a></p><table><thead><tr><th>Title</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = akiraShellEscape($row['slug'] ?? '');
        $body .= '<tr><td>' . akiraShellEscape($row['title'] ?? '') . '</td><td>' . akiraShellEscape($row['status'] ?? 'published') . '</td>'
            . '<td><a href="/cms-akira-shell/posts/' . $slug . '/edit">Edit</a></td></tr>';
    }
    $body .= '</tbody></table>';
    echo akiraShellPage('Posts', $body);
}

/** @param array<string,mixed> $post */
function akiraShellPostForm(array $post = []): string
{
    $slug = trim((string)($post['slug'] ?? ''));
    $action = $slug === '' ? '/cms-akira-shell/posts' : '/cms-akira-shell/posts/' . rawurlencode($slug);
    return '<form method="post" action="' . akiraShellEscape($action) . '">' . akiraShellCsrfField()
        . '<label>Slug <input name="slug" value="' . akiraShellEscape($slug) . '" required></label>'
        . '<label>Title <input name="title" value="' . akiraShellEscape($post['title'] ?? '') . '" required></label>'
        . '<label>Excerpt <textarea name="excerpt">' . akiraShellEscape($post['excerpt'] ?? '') . '</textarea></label>'
        . '<label>Content <textarea name="content" required>' . akiraShellEscape($post['content'] ?? '') . '</textarea></label>'
        . '<input type="hidden" name="expected_version" value="' . akiraShellEscape($post['version'] ?? 0) . '">'
        . '<input type="hidden" name="idempotency_key" value="shell-' . bin2hex(random_bytes(12)) . '">'
        . '<button type="submit">Save</button></form>';
}

/** @param array<string,mixed> $params */
function akiraShellPostCreateForm(array $params = []): void
{
    if (akiraShellAuthorize()) {
        echo akiraShellPage('Create post', akiraShellPostForm());
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
        echo akiraShellPage('Edit post', akiraShellPostForm($post) . '<section><h2>Lifecycle</h2>' . $actions . '</section>');
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
        $items .= '<li>' . akiraShellEscape($capability) . ': ' . ($present ? 'available' : 'missing') . '</li>';
    }
    if (!$ok) {
        http_response_code(503);
    }
    echo akiraShellPage('Module health', '<p>Overall: ' . ($ok ? 'healthy' : 'degraded') . '</p><ul>' . $items . '</ul>');
}

/** @param array<string,mixed> $params */
function akiraShellForbidden(array $params = []): void
{
    http_response_code(403);
    echo akiraShellPage('Access denied', '<p>Your Kernel role cannot administer CMS Akira.</p>');
}
