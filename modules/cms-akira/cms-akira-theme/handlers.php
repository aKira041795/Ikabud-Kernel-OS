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
/**
 * Unwrap a capability failure down to the theme exception that actually explains it.
 *
 * The capability bus wraps anything a handler throws in a generic
 * CapabilityCallException ("Capability call failed"), which hides the cause. Every
 * admin surface must report the inner reason: the theme studio previously showed only
 * the wrapper, so a rejected theme looked like an unexplained failure.
 *
 * @return array{status: int, message: string, retryAfter: int|null}
 */
function catThemeFailureDetail(Throwable $error): array
{
    for ($cursor = $error; $cursor instanceof Throwable; $cursor = $cursor->getPrevious()) {
        if ($cursor instanceof CatThemeException) {
            return [
                'status' => $cursor->httpStatus,
                'message' => $cursor->getMessage(),
                'retryAfter' => $cursor->retryAfter,
            ];
        }
    }
    return ['status' => 500, 'message' => 'Theme operation failed.', 'retryAfter' => null];
}

function catThemeJsonError(Throwable $error): void
{
    $detail = catThemeFailureDetail($error);
    if ($detail['retryAfter'] !== null) {
        header('Retry-After: ' . $detail['retryAfter']);
    }
    app()->json(['ok' => false, 'error' => $detail['message']], $detail['status']);
}

/**
 * Route a theme read through the governed `akira.theme.read@1` capability.
 *
 * The JSON handlers below and the Theme Studio page already admit the same role
 * set (catThemeAdmin(): admin, editor, administrator, superadmin). Dispatching
 * through the bus makes that same decision the recorded one the census and the
 * route guard measure; it does not change who is admitted. A failure is returned
 * as a readable payload rather than escaping as an uncaught capability error.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function catThemeReadViaBus(string $operation, array $payload = []): array
{
    try {
        $result = app()->cap()->call(
            'akira.theme.read@1',
            $payload + ['operation' => $operation],
            ['caller' => ['module' => 'cms-akira-theme', 'user' => app()->user()], 'mode' => 'first']
        );
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => catThemeFailureDetail($error)['message']];
    }
    return is_array($result) ? $result : ['ok' => false, 'error' => 'Theme read failed'];
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
    app()->json(catThemeReadViaBus('resolve'));
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
    app()->json(catThemeReadViaBus('registry'));
}

/** @param array<string, string> $params */
function catThemeBlocksJson(array $params = []): void
{
    if (catThemeAdmin() === null) {
        app()->json(['ok' => false, 'error' => 'Authentication required.'], 401);
        return;
    }
    if (catThemeAdmin() === []) {
        app()->json(['ok' => false, 'error' => 'Administrator role required.'], 403);
        return;
    }
    app()->json(catThemeReadViaBus('blocks'));
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
    app()->json(catThemeReadViaBus('validate', ['theme_slug' => $slug]));
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

/**
 * Build only the Theme Studio-owned content fragment.
 *
 * The shell owns the GET route and document chrome; keeping this builder in the
 * theme module preserves ownership of all theme controls and data access.
 *
 * @return array{title: string, body: string}
 */
function catThemeAdminPageContent(): array
{
    $themesResult = catThemeReadViaBus('registry');
    $themes = is_array($themesResult['themes'] ?? null) ? $themesResult['themes'] : [];
    $resolved = catThemeReadViaBus('resolve');
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
            $name = 'values[' . catThemeEscape($sectionId) . '][' . catThemeEscape($fieldId) . ']';
            $inputClass = 'w-full rounded-xl border border-slate-300 px-3 py-2 focus:border-akira-500 focus:ring-akira-500';
            $field = '';
            if ($type === 'select') {
                $options = '';
                foreach (is_array($control['options'] ?? null) ? $control['options'] : [] as $option) {
                    if (!is_scalar($option)) {
                        continue;
                    }
                    $option = (string)$option;
                    $selected = (string)$value === $option ? ' selected' : '';
                    $options .= '<option value="' . catThemeEscape($option) . '"' . $selected . '>' . catThemeEscape($option) . '</option>';
                }
                $field = '<select class="' . $inputClass . '" name="' . $name . '">' . $options . '</select>';
            } else {
                $inputType = in_array($type, ['color', 'number'], true) ? $type : 'text';
                $constraints = is_array($control['constraints'] ?? null) ? $control['constraints'] : [];
                $numericAttributes = '';
                if ($inputType === 'number') {
                    foreach (['min', 'max', 'step'] as $attribute) {
                        if (isset($constraints[$attribute]) && is_numeric($constraints[$attribute])) {
                            $numericAttributes .= ' ' . $attribute . '="' . catThemeEscape($constraints[$attribute]) . '"';
                        }
                    }
                }
                $field = '<input class="' . $inputClass . '" type="' . catThemeEscape($inputType) . '" name="' . $name . '" value="' . catThemeEscape($value) . '"' . $numericAttributes . '>';
            }
            $fields .= '<label class="block"><span class="mb-1 block text-sm font-semibold text-slate-700">' . catThemeEscape($control['label'] ?? $fieldId) . '</span>' . $field . '</label>';
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
    return ['title' => 'CMS Akira Theme Studio', 'body' => $body];
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
        $detail = catThemeFailureDetail($error);
        http_response_code($detail['status']);
        echo catThemePage('Theme activation failed', '<p>' . catThemeEscape($detail['message']) . '</p>');
    }
}
