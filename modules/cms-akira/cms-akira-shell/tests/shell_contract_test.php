<?php

declare(strict_types=1);

final class AkiraShellRenderTestApp
{
    /** @return array{id:int, role:string} */
    public function user(): array
    {
        return ['id' => 900001, 'role' => 'admin'];
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="rendered-token">';
    }

    public function cap(): object
    {
        return new class () {
            /** @param array<string,mixed> $payload
             * @param array<string,mixed> $options
             * @return array<string,mixed>
             */
            public function call(string $capability, array $payload, array $options): array
            {
                return ['ok' => true, 'data' => ['status' => 'draft', 'allowed_actions' => []]];
            }
        };
    }
}

function app(): mixed
{
    static $app;
    return $app ??= new AkiraShellRenderTestApp();
}

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/module.json'), true);
$routes = require $root . '/routes.php';
$handlers = (string)file_get_contents($root . '/handlers.php');
$helpers = (string)file_get_contents($root . '/helpers.php');
$login = (string)file_get_contents(dirname($root, 3) . '/templates/modules/cms-akira-shell/pages/login.disyl');
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
};

$check(($manifest['id'] ?? '') === 'cms-akira-shell', 'unique shell id');
$check(($manifest['entry_module'] ?? false) === true, 'entry module declared');
$check(!isset($manifest['auth_owned']) && !isset($manifest['authentication_provider']), 'Kernel owns authentication');
$check(($manifest['owns_tables'] ?? null) === [] && ($manifest['reads_tables'] ?? null) === [], 'shell is table-free');
$check(($routes['GET']['/'] ?? '') === 'cms-akira-shell:akiraPublicHome' && ($routes['GET']['/posts'] ?? '') === 'cms-akira-shell:akiraPublicPostList' && ($routes['GET']['/posts/{slug}'] ?? '') === 'cms-akira-shell:akiraPublicPostSingle', 'public home, archive and single routes');
$check(($routes['GET']['/cms-akira-shell'] ?? '') === 'cms-akira-shell:akiraShellDashboard', 'dashboard route');
$check(isset($routes['GET']['/cms-akira-shell/login']), 'presentation login route');
$check(isset($routes['GET']['/cms-akira-shell/health']), 'health route');
$check(isset($routes['GET']['/cms-akira-shell/forbidden']), 'authorization failure route');
$check(str_contains($handlers, "akiraShellRedirect('/login')"), 'anonymous login redirects to Kernel');
$check(str_contains($handlers, "akiraShellRedirect('/cms-akira-shell')"), 'authenticated login redirects to dashboard');
$check(substr_count($handlers, "entityViews()->resolve('post', 'list'") === 2, 'public pages and published-only selector consume Entity Views');
$check(substr_count($handlers, 'akiraShellAdminPostList(') === 4 && str_contains($helpers, "akira.post.admin.list@1"), 'dashboard and admin list use the governed administration list capability');
$check(str_contains($handlers, "resolveDetail('post', \$slug, 'detail')") && !str_contains(explode('function akiraShellAuthorize', $handlers, 2)[0] ?? '', "'include_unpublished' => true"), 'public detail uses the published-only entity projection');
$check(
    str_contains($helpers, "akira.theme.resolve@1")
    && str_contains($helpers, 'ThemeCustomizerOrchestrator::renderProviderRegion')
    && str_contains($helpers, 'arkRenderers()->render')
    && str_contains($handlers, '$themed ?? app()->render'),
    'public pages use active-theme ARK entity and Kernel region seams with P5-1 fallback'
);
foreach (['create', 'update', 'delete'] as $operation) {
    $check(str_contains($handlers . $helpers, "akira.post.{$operation}@1"), "{$operation} uses canonical capability");
}
$check(str_contains($helpers, "akira.workflow.evaluate@1") && str_contains($helpers, "akira.workflow.transition@1"), 'publication evaluates and transitions through workflow capabilities');
$check(!str_contains($handlers . $helpers, 'akira.post.publish@1') && !str_contains($handlers . $helpers, 'akira.post.unpublish@1'), 'shell has no direct publish or unpublish capability path');
$check(str_contains($helpers, 'function akiraShellParticipant()') && str_contains($helpers, "function_exists('cawPostLifecycleParticipantRoles')"), 'editorial entry derives participants from the workflow definition');
$check(str_contains($handlers, 'akiraShellAuthorizeAdmin()') && str_contains($helpers, "['admin', 'administrator', 'superadmin']"), 'compositions and health retain their presentation administrator gate');
$mutationBody = explode('function akiraShellMutation', $helpers, 2)[1] ?? '';
$mutationBody = explode("\n}", $mutationBody, 2)[0] ?? '';
$check(str_contains($mutationBody, 'akiraShellAuthorize()') && !str_contains($mutationBody, 'akiraShellAdmin'), 'delete delegates role authority to its governed policy row');
$check(str_contains($helpers, 'app()->csrfEnforce()'), 'Kernel CSRF enforcement');
$check(!preg_match('/(?:cmsRender|cmsRequireCap|cmsActiveTheme|cms_akira_posts|require.+modules\\/cms\\/)/', $handlers . $helpers), 'no forbidden content/auth/database shortcut');
$check(isset($routes['GET']['/cms-akira-shell/compositions']) && isset($routes['GET']['/cms-akira-shell/compositions/{key}/edit']), 'builder admin list and editor routes mounted under the shell guard');
$check(str_contains($handlers, 'akiraShellBuilderAdmin'), 'shell handlers mount the builder admin bundle');
$check(str_contains($helpers, 'cms-akira-builder-root') && str_contains($helpers, '/admin/assets/cms-akira-builder'), 'shell serves the CSP-safe builder bundle container');
$check(count($manifest['nav'] ?? []) === 8, 'dashboard, editorial, permissions, users and health navigation');
$check(isset($routes['GET']['/cms-akira-shell/permissions'], $routes['POST']['/cms-akira-shell/permissions'])
    && isset($routes['GET']['/cms-akira-shell/users'], $routes['POST']['/cms-akira-shell/users/{id}/role'], $routes['POST']['/cms-akira-shell/users/{id}/active']), 'permissions and users governance routes mounted');
foreach (['akira.policy.list@1', 'akira.policy.set_roles@1', 'akira.user.list@1', 'akira.user.update_role@1', 'akira.user.set_active@1'] as $capability) {
    $check(in_array($capability, $manifest['capabilities']['depends'] ?? [], true), "shell declares {$capability} dependency");
}
$check(str_contains($handlers, "akiraShellCall('akira.policy.set_roles@1'")
    && str_contains($handlers, "akiraShellCall('akira.user.list@1'")
    && str_contains($handlers, 'akiraShellUserMutation('), 'governance forms use core capabilities only');
$check(str_contains($handlers, 'akiraShellAuthorizeAdmin()') && str_contains($handlers, 'app()->csrfEnforce()'), 'governance surfaces retain administrator and Kernel CSRF gates');
$check(($manifest['nav'][2]['url'] ?? '') === '/cms-akira-shell/categories' && ($manifest['nav'][2]['label'] ?? '') === 'Categories', 'categories nav entry present in shell module.json');
$check(($manifest['nav'][3]['url'] ?? '') === '/cms-akira-shell/content-types' && ($manifest['nav'][3]['label'] ?? '') === 'Content types', 'content types nav entry present in shell module.json');
$check(($manifest['nav'][4]['url'] ?? '') === '/cms-akira-shell/compositions', 'compositions nav entry present in shell module.json');
$check(isset($routes['POST']['/cms-akira-shell/posts/{slug}/delete']), 'delete route');
$check(
    isset($routes['GET']['/cms-akira-shell/categories']) && isset($routes['POST']['/cms-akira-shell/categories'])
    && isset($routes['POST']['/cms-akira-shell/categories/{id}']) && isset($routes['POST']['/cms-akira-shell/categories/{id}/delete']),
    'categories list, create, rename and delete routes mounted'
);
foreach (['list', 'get', 'create', 'update', 'delete'] as $operation) {
    $check(in_array("akira.taxonomy.{$operation}@1", $manifest['capabilities']['depends'] ?? [], true), "shell declares akira.taxonomy.{$operation}@1 dependency");
}
$check(str_contains($handlers, 'akiraShellAuthorizeTaxonomyManager()') && str_contains($handlers, 'akiraShellCategoryList') && str_contains($handlers, 'akiraShellCategoryCreate') && str_contains($handlers, 'akiraShellCategoryUpdate') && str_contains($handlers, 'akiraShellCategoryDelete'), 'categories handlers mounted with their own editorial gate');
$check(str_contains($helpers, 'function akiraShellIsTaxonomyManager()') && str_contains($helpers, "'admin', 'editor', 'administrator', 'superadmin'"), 'taxonomy manage presentation mirrors the seeded policy roles');
$check(
    str_contains($handlers, "akiraShellCall('akira.taxonomy.list@1'") && str_contains($handlers, "akiraShellCall('akira.taxonomy.create@1'")
    && str_contains($handlers, "akiraShellCall('akira.taxonomy.update@1'") && str_contains($handlers, "akiraShellCall('akira.taxonomy.delete@1'"),
    'categories page reads and mutates exclusively through governed taxonomy capabilities'
);
$check(str_contains($handlers, 'akiraShellCsrfField()') && !str_contains($handlers . $helpers, 'name="_csrf_token"'), 'categories forms render the canonical Kernel CSRF field only');
$check(str_contains($handlers, 'expected_updated_at') && str_contains($handlers, 'return confirm('), 'renames preserve optimistic concurrency and deletes are confirmed');
$check(
    isset($routes['GET']['/cms-akira-shell/content-types']) && isset($routes['POST']['/cms-akira-shell/content-types'])
    && isset($routes['POST']['/cms-akira-shell/content-types/{id}']) && isset($routes['POST']['/cms-akira-shell/content-types/{id}/delete']),
    'content types list, create, edit and delete routes mounted'
);
foreach (['list', 'get', 'create', 'update', 'delete'] as $operation) {
    $check(in_array("akira.content_type.{$operation}@1", $manifest['capabilities']['depends'] ?? [], true), "shell declares akira.content_type.{$operation}@1 dependency");
}
$check(str_contains($handlers, 'akiraShellAuthorizeContentTypeManager()') && str_contains($handlers, 'akiraShellContentTypeList') && str_contains($handlers, 'akiraShellContentTypeCreate') && str_contains($handlers, 'akiraShellContentTypeUpdate') && str_contains($handlers, 'akiraShellContentTypeDelete'), 'content types handlers mounted with their own editorial gate');
$check(str_contains($helpers, 'function akiraShellIsContentTypeManager()') && str_contains($helpers, "'admin', 'editor', 'administrator', 'superadmin'"), 'content type manage presentation mirrors the seeded policy roles');
$check(
    str_contains($handlers, "akiraShellCall('akira.content_type.list@1'") && str_contains($handlers, "akiraShellCall('akira.content_type.create@1'")
    && str_contains($handlers, "akiraShellCall('akira.content_type.update@1'") && str_contains($handlers, "akiraShellCall('akira.content_type.delete@1'"),
    'content types page reads and mutates exclusively through governed content type capabilities'
);
$check(str_contains($handlers, 'name="field_schema"') && str_contains($handlers, 'pattern="[a-z0-9]+(?:-[a-z0-9]+)*"'), 'content type forms declare a canonical slug pattern and field_schema JSON');
$check(in_array('akira.post.set_taxonomies@1', $manifest['capabilities']['depends'] ?? [], true), 'shell declares akira.post.set_taxonomies@1 dependency');
$check(isset($routes['POST']['/cms-akira-shell/posts/{slug}/taxonomies']), 'post taxonomy assignment route mounted');
$check(str_contains($handlers, 'function akiraShellPostSetTaxonomies') && str_contains($handlers, 'function akiraShellAuthorizePostTaxonomyManager'), 'category assignment handlers mounted with an editorial gate');
$check(str_contains($handlers, "akiraShellCall('akira.post.set_taxonomies@1'")
    && str_contains($helpers, "akiraShellCall('akira.post.set_taxonomies@1'"), 'editor saves categories through the governed assignment capability after the post write');
$check(str_contains($helpers, 'name="taxonomy_ids[]"') && str_contains($helpers, 'function akiraShellCategoryPanel') && str_contains($helpers, "akiraShellCall('akira.taxonomy.list@1'")
    && str_contains($helpers, "'type' => 'category'"), 'editor category panel reads governed categories and posts taxonomy_ids checkboxes');
$check(str_contains($handlers, 'akiraShellPostTable(') && str_contains($helpers, 'function akiraShellPostTable') && str_contains($helpers, 'function akiraShellCategoryChips') && str_contains($helpers, 'function akiraShellPostRow'), 'posts list renders category chips per row');
$check(str_contains($handlers, '<select name="category"') && str_contains($handlers, "'taxonomy_id' => ") && str_contains($helpers, 'function akiraShellCategories'), 'posts list gains a governed category filter');
$check(!str_contains($handlers . $helpers, 'name="_csrf_token"'), 'category surfaces keep the canonical Kernel CSRF field only');
$check(str_contains($helpers, 'function akiraShellIsTaxonomyManager()') && str_contains($helpers, "'admin', 'editor', 'administrator', 'superadmin'"), 'category assignment presentation mirrors the seeded policy roles');
$check(str_contains($handlers, 'akiraShellPostSetTaxonomyPayload(') || str_contains($helpers, 'function akiraShellPostSetTaxonomyPayload'), 'assignment payload builder normalizes posted taxonomy ids');
$check(str_contains($login, 'https://cdn.tailwindcss.com') && str_contains($login, 'alpinejs@3.14.3'), 'login ingests reference Tailwind and Alpine assets');
$check(str_contains($login, 'tailwind.config') && str_contains($login, 'CMS Akira') && !str_contains($login, '<style>'), 'login uses branded palette without bespoke CSS');
$check(str_contains($helpers, 'app()->entityRenderers()->renderList') && str_contains($handlers, 'data-akira-entity-view="post-list"'), 'posts render through styled Kernel entity-view container');
$check(str_contains($helpers, 'https://cdn.tailwindcss.com') && str_contains($helpers, 'aria-label="Akira administration"'), 'admin shell ingests design system and accessible navigation');
$check(str_contains($handlers, "'filters' => ['include_unpublished' => true") && str_contains($handlers, 'akiraShellPagination'), 'admin list preserves draft-inclusive filtering and pagination');
$check(in_array('akira.post.admin.get@1', $manifest['capabilities']['depends'] ?? [], true)
    && in_array('akira.post.admin.list@1', $manifest['capabilities']['depends'] ?? [], true)
    && !in_array('akira.post.get@1', $manifest['capabilities']['depends'] ?? [], true), 'shell declares only admin-scoped raw Post read dependencies');
$check(str_contains($helpers, "akiraShellCall('akira.post.admin.get@1'") && !str_contains($helpers, "akiraShellCall('akira.post.get@1'"), 'editor fetch uses the governed administration get capability');
$check(str_contains($helpers, "akiraShellIsAdmin() ? ['edit', 'delete'] : ['edit']") && str_contains($helpers, "'delete' => 'POST'") && str_contains($helpers, 'Delete this post?'), 'participants can edit while administrator rows also expose confirmed delete');
$check(in_array('akira.post.revisions.list@1', $manifest['capabilities']['depends'] ?? [], true)
    && in_array('akira.post.revision.revert@1', $manifest['capabilities']['depends'] ?? [], true), 'shell declares revision list and revert capability dependencies');
$check(isset($routes['POST']['/cms-akira-shell/posts/{slug}/revisions/{revision_no}/revert']), 'post revision revert route mounted');
$check(str_contains($handlers, 'function akiraShellPostRevisionRevert')
    && str_contains($handlers, 'function akiraShellAuthorize()')
    && str_contains($helpers, 'function akiraShellIsPostRevisionManager()')
    && str_contains($helpers, 'function akiraShellRevisionPanel') && str_contains($helpers, 'function akiraShellRevisionTable') && str_contains($helpers, 'function akiraShellRevisionRows'), 'revision revert handler and editor revision panel helpers mounted');
$check(str_contains($handlers, "akiraShellCall('akira.post.revision.revert@1'")
    && str_contains($helpers, "akiraShellCall('akira.post.revisions.list@1'"), 'editor lists history and reverts exclusively through governed revision capabilities');
$check(str_contains($helpers, 'function akiraShellIsPostRevisionManager()') && str_contains($helpers, "'admin', 'editor', 'administrator', 'superadmin'"), 'revision revert presentation mirrors the seeded policy roles');
$check(str_contains($handlers, 'Reverting post revisions is reserved for Akira editors and administrators.'), 'revision revert handler renders a clean editor-only 403 gate');
$check(str_contains($handlers, "'/edit?saved=revision'") && str_contains($handlers, 'function akiraShellPostRevisionRevert'), 'revision revert handler redirects 303 after a governed revert');
$check(str_contains($handlers . $helpers, 'Revert to this revision') && str_contains($handlers . $helpers, "'post-revision-revert-'"), 'revision rows carry confirmed revert forms with fresh idempotency keys');
$check(str_contains($handlers, 'akiraShellRevisionPanel($slug, (string)($post[\'updated_at\'] ?? \'\'))'), 'editor form mounts the revisions card only for saved posts');
$participantRoles = $manifest['nav'][0]['roles'] ?? [];
$check($participantRoles === ['contributor', 'author', 'editor', 'admin', 'administrator', 'superadmin'] && ($manifest['nav'][1]['roles'] ?? []) === $participantRoles, 'dashboard and posts navigation admits every seeded workflow participant');
$check(!str_contains($handlers, '<select name="status" class="\' . $control') && str_contains($handlers, 'data-akira-workflow-state') && str_contains($helpers, 'data-akira-workflow-actions') && str_contains($handlers, 'x-text="body"'), 'editor exposes workflow state and allowed actions instead of a binary status input');
require_once $root . '/handlers.php';
$renderedForm = akiraShellPostForm(['slug' => 'render-contract', 'title' => 'Rendered contract', 'content' => 'Body', 'updated_at' => '2026-01-01 00:00:00']);
$check(str_contains($renderedForm, 'name="_token" value="rendered-token"') && !str_contains($renderedForm, 'name="_csrf_token"'), 'rendered editor uses the canonical Kernel CSRF field');
$check(str_contains($renderedForm, 'data-akira-post-revisions') && str_contains($renderedForm, 'No revisions recorded yet.'), 'rendered editor mounts the additive revisions card below the form');
$check(str_contains($renderedForm, 'x-data="akiraContentEditor()"') && str_contains($renderedForm, 'function akiraContentEditor()') && !str_contains($renderedForm, 'x-data="{body:'), 'rendered editor uses a named Alpine component safe for DiSyL parsing');
$check(str_contains($helpers, 'akiraShellCall($capability, $input)') && str_contains($helpers, "'expected_updated_at'") && str_contains($helpers, "'expected_status'"), 'saves and workflow transitions preserve optimistic concurrency');
$check(str_contains($handlers, "['Published', \$published") && str_contains($handlers, 'akiraShellRecentPosts'), 'dashboard presents governed counts and recent posts');

echo "shell contract: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
