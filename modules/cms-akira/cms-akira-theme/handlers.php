<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** @param array<string, string> $params */
function catThemeHealth(array $params = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'module' => 'cms-akira-theme',
        'version' => '1.0.0',
        'authority' => 'native',
    ], JSON_UNESCAPED_SLASHES);
}

/** @param Throwable $error */
function catThemeJsonError(Throwable $error): void
{
    $status = 500;
    $message = 'Theme operation failed.';
    $retryAfter = null;
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CatThemeException) {
            $status = $cursor->httpStatus;
            $message = $cursor->getMessage();
            $retryAfter = $cursor->retryAfter;
            break;
        }
    }
    if ($retryAfter !== null) {
        header('Retry-After: ' . $retryAfter);
    }
    app()->json(['ok' => false, 'error' => $message], $status);
}

/** @param array<string, string> $params */
function catThemeResolveJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    app()->json(cat_cap_akira_theme_resolve_1([]));
}

/** @param array<string, string> $params */
function catThemeRegistryJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    app()->json(cat_cap_akira_theme_registry_1([]));
}

/** @param array<string, string> $params */
function catThemeValidateJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    $slug = (string) ($params['slug'] ?? '');
    try {
        $slug = catThemeSlug($slug);
    } catch (Throwable $error) {
        app()->json(['ok' => false, 'error' => $error->getMessage()], 422);
        return;
    }
    app()->json(cat_cap_akira_theme_validate_1(['theme_slug' => $slug]));
}

/** @param array<string, string> $params */
function catThemeActivateJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    catThemeEnforceMutationCsrf();
    $payload = catThemeInput();
    $payload['theme_slug'] = $params['slug'] ?? ($payload['theme_slug'] ?? null);
    $payload['idempotency_key'] = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '')));
    try {
        $result = app()->cap()->call('akira.theme.activate@1', $payload, [
            'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        app()->json($result, 200);
    } catch (Throwable $error) {
        catThemeJsonError($error);
    }
}

/** @param array<string, string> $params */
function catThemeAdminPage(array $params = []): void
{
    if (catThemeAdmin() === null) {
        header('Location: /login', true, 303);
        return;
    }
    if (catThemeAdmin() === []) {
        http_response_code(403);
        echo catThemePage('Access denied', '<p>Your Kernel role cannot administer CMS Akira themes.</p>');
        return;
    }

    $themes = catThemeRegistryRows();
    $resolved = catThemeResolveActive();
    $active = $resolved['ok'] ? (string) $resolved['theme_slug'] : CAT_THEME_FALLBACK;
    $previous = catThemePreviousSetting();

    $items = '';
    foreach ($themes as $theme) {
        $slug = (string) $theme['slug'];
        $label = catThemeEscape($theme['label'] ?? $slug);
        $state = ($theme['validated'] ?? false) ? 'valid' : 'invalid';
        $activeMark = ($theme['active'] ?? false) ? ' (active)' : '';
        $items .= '<li>' . $label . ' <code>' . catThemeEscape($slug) . '</code> — ' . $state . $activeMark
            . ' <form method="post" action="/cms-akira-theme/activate" style="display:inline">'
            . catThemeCsrfField()
            . '<input type="hidden" name="theme_slug" value="' . catThemeEscape($slug) . '">'
            . '<input type="hidden" name="idempotency_key" value="theme-' . bin2hex(random_bytes(12)) . '">'
            . '<button type="submit">Activate</button></form></li>';
    }

    $schemaResult = cat_cap_akira_theme_customizer_schema_1([]);
    $valuesResult = cat_cap_akira_theme_customizer_values_1([]);
    $schema = is_array($schemaResult['data'] ?? null) ? $schemaResult['data'] : [];
    $savedValues = is_array($valuesResult['values'] ?? null) ? $valuesResult['values'] : [];
    $studio = '';
    foreach (($schema['sections'] ?? []) as $sectionId => $section) {
        if (!is_array($section)) {
            continue;
        }
        $fields = '';
        foreach (($section['controls'] ?? []) as $fieldId => $control) {
            if (!is_array($control)) {
                continue;
            }
            $value = $savedValues[$sectionId][$fieldId] ?? ($control['default'] ?? '');
            $type = (string)($control['type'] ?? 'text');
            $inputType = in_array($type, ['color', 'number'], true) ? $type : 'text';
            $fields .= '<label class="block"><span class="mb-1 block text-sm font-semibold text-slate-700">' . catThemeEscape($control['label'] ?? $fieldId) . '</span>'
                . '<input class="w-full rounded-xl border border-slate-300 px-3 py-2 focus:border-akira-500 focus:ring-akira-500" type="' . catThemeEscape($inputType) . '" name="values[' . catThemeEscape($sectionId) . '][' . catThemeEscape($fieldId) . ']" value="' . catThemeEscape($value) . '"></label>';
        }
        $studio .= '<fieldset class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><legend class="px-2 text-lg font-bold text-slate-900">' . catThemeEscape($section['label'] ?? $sectionId) . '</legend><div class="grid gap-4">' . $fields . '</div></fieldset>';
    }
    $notice = (string)($_GET['saved'] ?? '') === '1'
        ? '<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 font-semibold text-emerald-700">Theme customization saved. Public cache invalidated.</div>' : '';
    $rollback = $previous !== null && $previous !== $active
        ? '<form class="mt-4" method="post" action="/cms-akira-theme/activate">' . catThemeCsrfField()
            . '<input type="hidden" name="theme_slug" value="' . catThemeEscape($previous) . '">'
            . '<input type="hidden" name="rollback" value="1">'
            . '<input type="hidden" name="idempotency_key" value="rollback-' . bin2hex(random_bytes(12)) . '">'
            . '<button class="rounded-xl bg-amber-500 px-4 py-2 font-bold text-slate-950 hover:bg-amber-400" type="submit">Rollback to previous</button></form>'
        : '';
    $body = '<section class="mb-8 rounded-2xl bg-slate-950 p-6 text-white"><p>Active theme</p><code class="text-violet-300">' . catThemeEscape($active) . '</code></section>'
        . $notice . '<section data-theme-studio><h2 class="mb-2 text-2xl font-bold">Theme Studio</h2><p class="mb-5 text-slate-500">Customize the active theme through its kernel-owned declarative schema.</p>'
        . '<form method="post" action="/cms-akira-theme/customize" class="grid gap-5">' . catThemeCsrfField()
        . '<input type="hidden" name="theme_slug" value="' . catThemeEscape($active) . '"><input type="hidden" name="idempotency_key" value="customize-' . bin2hex(random_bytes(12)) . '">'
        . $studio . '<button class="rounded-xl bg-akira-600 px-5 py-3 font-bold text-white hover:bg-akira-700" type="submit">Save customization</button></form></section>'
        . '<section class="mt-10"><h2 class="text-xl font-bold">Installed themes</h2><ul class="mt-3 space-y-2">' . $items . '</ul>' . $rollback . '</section>'
        . '<p class="mt-6"><a href="/api/v1/cms-akira-theme/themes">Themes JSON</a> · <a href="/api/v1/cms-akira-theme/resolve">Resolve JSON</a></p>';
    echo catThemePage('CMS Akira Theme Studio', $body);
}

/** @param array<string, string> $params */
function catThemeCustomizeForm(array $params = []): void
{
    if (catThemeAdmin() === null) {
        header('Location: /login', true, 303);
        return;
    }
    if (catThemeAdmin() === []) {
        http_response_code(403);
        echo catThemePage('Access denied', '<p>Your Kernel role cannot customize CMS Akira themes.</p>');
        return;
    }
    app()->csrfEnforce();
    $payload = catThemeInput();
    try {
        app()->cap()->call('akira.theme.customize@1', $payload, [
            'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()], 'mode' => 'first',
        ]);
        header('Location: /cms-akira-theme?saved=1', true, 303);
    } catch (Throwable $error) {
        $root = $error;
        while ($root->getPrevious() instanceof Throwable) {
            $root = $root->getPrevious();
        }
        $status = $root instanceof CatThemeException ? $root->httpStatus : 422;
        http_response_code($status);
        echo catThemePage('Theme customization failed', '<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">' . catThemeEscape($root->getMessage()) . '</div><p class="mt-4"><a href="/cms-akira-theme">Return to Theme Studio</a></p>');
    }
}

/** @param array<string,string> $params */
function catThemeCustomizeJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Theme customization role required.'], 403);
        return;
    }
    catThemeEnforceMutationCsrf();
    $payload = catThemeInput();
    $payload['idempotency_key'] = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '')));
    try {
        $result = app()->cap()->call('akira.theme.customize@1', $payload, [
            'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()], 'mode' => 'first',
        ]);
        app()->json($result, 200);
    } catch (Throwable $error) {
        catThemeJsonError($error);
    }
}

/** @param array<string, string> $params */
function catThemeActivateForm(array $params = []): void
{
    if (catThemeAdmin() === null) {
        header('Location: /login', true, 303);
        return;
    }
    if (catThemeAdmin() === []) {
        http_response_code(403);
        echo catThemePage('Access denied', '<p>Your Kernel role cannot administer CMS Akira themes.</p>');
        return;
    }
    app()->csrfEnforce();
    $payload = catThemeInput();
    $payload['idempotency_key'] = trim((string) ($payload['idempotency_key'] ?? ''));
    try {
        app()->cap()->call('akira.theme.activate@1', $payload, [
            'caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()],
            'mode' => 'first',
        ]);
        header('Location: /cms-akira-theme', true, 303);
    } catch (Throwable $error) {
        http_response_code(422);
        echo catThemePage('Theme activation failed', '<p>' . catThemeEscape($error->getMessage()) . '</p>');
    }
}
