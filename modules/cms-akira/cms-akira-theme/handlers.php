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
    $previewTokens = [];
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
            if ($type === 'color' && is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $previewTokens['--' . str_replace('_', '-', (string)$fieldId)] = strtolower($value);
            }
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
                $input = '<input class="' . $inputClass . '" type="' . catThemeEscape($inputType) . '" name="' . $name . '" value="' . catThemeEscape($value) . '"' . $numericAttributes;
                if ($inputType === 'color') {
                    $field = '<span style="display:grid;grid-template-columns:minmax(8rem,1fr) 6rem;gap:.75rem;align-items:center">'
                        . $input . ' data-theme-color-input style="appearance:none;-webkit-appearance:none;height:2.75rem;padding:.25rem;background-color:' . catThemeEscape($value) . ';cursor:pointer">'
                        . '<output data-theme-color-value style="font-family:ui-monospace,monospace;font-weight:700;text-transform:uppercase">' . catThemeEscape($value) . '</output></span>';
                } else {
                    $field = $input . '>';
                }
            }
            $fields .= '<label class="block"><span class="mb-1 block text-sm font-semibold text-slate-700">' . catThemeEscape($control['label'] ?? $fieldId) . '</span>' . $field . '</label>';
        }
        $studio .= '<fieldset class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><legend class="px-2 text-lg font-bold text-slate-900">' . catThemeEscape($section['label'] ?? $sectionId) . '</legend><div class="grid gap-4">' . $fields . '</div></fieldset>';
    }
    $previewStyle = '';
    foreach ($previewTokens as $token => $value) {
        $previewStyle .= $token . ':' . $value . ';';
    }
    $preview = '<section data-theme-preview aria-label="Theme preview" style="' . catThemeEscape($previewStyle)
        . 'min-height:320px;padding:32px;border-radius:18px;background:var(--color-background,#f8fafc);color:var(--color-text,#0f172a);border:1px solid var(--color-border,#cbd5e1)">'
        . '<div style="max-width:720px;margin:0 auto">'
        . '<p style="margin:0 0 10px;color:var(--color-primary,#0f766e);font-weight:700;letter-spacing:.08em;text-transform:uppercase">Live theme preview</p>'
        . '<h3 style="margin:0 0 14px;font-size:32px;line-height:1.15;color:var(--color-heading,var(--color-text,#0f172a))">See your site before you save</h3>'
        . '<p style="margin:0 0 24px;font-size:17px;line-height:1.65;color:var(--color-muted,#475569)">Colours from the active theme are applied to this representative page area.</p>'
        . '<a href="#theme-customizer-controls" style="display:inline-block;border-radius:10px;padding:11px 18px;background:var(--color-primary,#0f766e);color:var(--color-on-primary,#fff);font-weight:700;text-decoration:none">Explore the theme</a>'
        . '</div></section>';
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
        . $preview
        . '<form id="theme-customizer-controls" method="post" action="/cms-akira-theme/customize" class="mt-6 grid gap-5">' . catThemeCsrfField()
        . '<input type="hidden" name="theme_slug" value="' . catThemeEscape($active) . '"><input type="hidden" name="idempotency_key" value="customize-' . bin2hex(random_bytes(12)) . '">'
        . $studio . '<div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center"><button class="rounded-xl bg-akira-600 px-5 py-3 font-bold text-white hover:bg-akira-700" type="submit">Save customization</button>'
        . '<button class="rounded-xl border border-slate-300 bg-white px-5 py-3 font-bold text-slate-700 hover:bg-slate-50" type="button" data-theme-discard>Discard changes</button>'
        . '<span data-theme-edit-status aria-live="polite" style="font-weight:600;color:#475569"></span></div></form></section>'
        . '<section class="mt-10"><h2 class="text-xl font-bold">Installed themes</h2><ul class="mt-3 space-y-2">' . $items . '</ul>' . $rollback . '</section>'
        . '<p class="mt-6"><a href="/api/v1/cms-akira-theme/themes">Themes JSON</a> · <a href="/api/v1/cms-akira-theme/resolve">Resolve JSON</a></p>'
        . '<script defer src="/assets/js/akira-theme-studio.js"></script>';
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
