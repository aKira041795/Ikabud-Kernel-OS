<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

if (!defined('BASE_PATH')) { define('BASE_PATH', $basePath); }
if (!defined('KERNEL_PATH')) { define('KERNEL_PATH', $basePath . '/kernel'); }
if (!defined('STORAGE_PATH')) { define('STORAGE_PATH', $basePath . '/storage'); }

// The renderer emits a POST form only when it can obtain a CSRF token, and it fails closed
// otherwise (asserted separately below). This test therefore needs a real application context
// so `app()->csrfField()` is available; without it no form is rendered at all.
require_once $basePath . '/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "── DefaultEntityRenderer POST Row Action Test ──\n\n";

$renderer = new DefaultEntityRenderer();

$rows = [
    [
        'id' => 7,
        'name' => 'Alpha',
        'status' => 'draft',
        'quantity' => 3,
        'tenant_id' => 'TENANT-SECRET-7',
        'meta' => ['skip' => true],
    ],
    [
        'id' => 8,
        'name' => 'Beta',
        'status' => 'published',
        'quantity' => 5,
        'tenant_id' => 'TENANT-SECRET-8',
        'meta' => ['skip' => true],
    ],
];

$view = [
    'fields' => ['name', 'status'],
    'view' => 'table',
    'actions' => ['archive'],
    'action_methods' => ['archive' => 'POST'],
    'action_labels' => ['archive' => 'Archive Row'],
    'action_confirm' => ['archive' => 'Archive this row?'],
    // The row-action POST payload is now governed by the declared list; only
    // these fields may become hidden inputs. The row also carries `tenant_id`
    // and `meta`, which must NOT become hidden inputs.
    'action_payload_fields' => ['id', 'name', 'status', 'quantity'],
    // A.2b: row-click and action-URL interpolation is governed by url_key_fields.
    // Declaring `id` keeps the row-click target resolvable under the new contract.
    'url_key_fields' => ['id'],
    'renderers' => [
        'name' => 'string',
        'status' => 'string',
    ],
];

$html = $renderer->renderList($rows, $view, ['source' => 'test_entity.all', 'view' => 'table', 'row-click' => '/rows/{id}']);

t('renderList returns HTML', $html !== '');
t('renders table view', str_contains($html, '<table class="w-full text-sm">'));
t('renders POST form', str_contains($html, '<form method="post"'));
t('renders one POST form per row', substr_count($html, '<form method="post"') === 2, 'count=' . substr_count($html, '<form method="post"'));
t('normalizes uppercase POST action method', !str_contains($html, '<a href="?id=7&amp;action=archive'));
t('row click ignores interactive controls', str_contains($html, 'event.target.closest') && str_contains($html, 'button'));
t('renders hidden id input for first row', str_contains($html, '<input type="hidden" name="id" value="7">'));
t('renders hidden id input for second row', str_contains($html, '<input type="hidden" name="id" value="8">'));
t('renders hidden scalar row input: name', str_contains($html, '<input type="hidden" name="name" value="Alpha">'));
t('renders hidden scalar row input: status', str_contains($html, '<input type="hidden" name="status" value="draft">'));
t('renders hidden scalar row input: quantity', str_contains($html, '<input type="hidden" name="quantity" value="3">'));
t('renders hidden scalar row inputs for second row context', str_contains($html, '<input type="hidden" name="name" value="Beta">') && str_contains($html, '<input type="hidden" name="quantity" value="5">'));
t('does not render non-scalar row data as hidden input', !str_contains($html, 'name="meta"'));
t('does not render an undeclared scalar row member as hidden input', !str_contains($html, 'name="tenant_id"') && !str_contains($html, 'TENANT-SECRET'), 'undeclared tenant_id leaked into the POST payload');
t('renders submit button label', str_contains($html, '<button type="submit"') && str_contains($html, 'Archive Row</button>'));
t('renders POST confirmation handler', str_contains($html, 'onsubmit="return confirm(') && str_contains($html, 'Archive this row?'));

// ── CSRF regression guard ────────────────────────────────────────────────────────────────
// The renderer previously guarded token emission on `csrf_token()` and `entity_csrf_token()`.
// Neither function exists anywhere in this repository (the real helper is camelCase
// `csrfToken()`), so both guards were permanently false and EVERY POST form shipped without a
// token -- either unprotected, or unusable with a 419. These assertions fail for that code.
t('POST form carries a CSRF token', str_contains($html, 'name="_token"'), 'no _token in rendered form');
t(
    'every POST form carries exactly one token',
    substr_count($html, '<form method="post"') === substr_count($html, 'name="_token"'),
    'forms=' . substr_count($html, '<form method="post"') . ' tokens=' . substr_count($html, 'name="_token"'),
);

t('the token value is non-empty', (bool) preg_match('/name="_token" value="[^"]+"/', $html), 'empty token value');

$rendererSource = (string) @file_get_contents(KERNEL_PATH . '/EntityContext/DefaultEntityRenderer.php');
t('renderer no longer guards on non-existent csrf_token()', !str_contains($rendererSource, "function_exists('csrf_token')"));
t('renderer no longer guards on non-existent entity_csrf_token()', !str_contains($rendererSource, "function_exists('entity_csrf_token')"));

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
