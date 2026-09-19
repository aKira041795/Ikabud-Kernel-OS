<?php
/** T1 G3 verification harness — catThemeValidate rejects .php in a theme tree. */
declare(strict_types=1);

$root = dirname(__DIR__); // repo root
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/helpers.php';
require_once $root . '/modules/cms-akira/cms-akira-theme/handlers.php';

// identity (admin, synthetic tenant) so capability-free helpers work
app()->tenant()->setTenantId(994701);
app()->setUser(['id' => 999701, 'role' => 'admin']);

$base = \catThemesPath();
$slug = 't1-hostile-' . bin2hex(random_bytes(3));
$dir = $base . '/' . $slug;

// build a hostile theme = copy of akira-ark + a .php payload
$src = $base . '/akira-ark';
if (!is_dir($src)) { fwrite(STDERR, "akira-ark theme not found at {$src}\n"); exit(2); }
$mkdir = static function (string $d) use (&$mkdir): void { if (!is_dir($d)) { mkdir($d, 0775, true); } };
$copy = static function (string $from, string $to) use (&$mkdir): void {
    if (is_dir($from)) {
        $mkdir($to);
        foreach (scandir($from) as $e) { if ($e === '.' || $e === '..') continue; $copy($from . '/' . $e, $to . '/' . $e); }
    } else { copy($from, $to); }
};
$copy($src, $dir);
file_put_contents($dir . '/public/payload.php', '<?php echo "unsafe";');
file_put_contents($dir . '/nested/evil.phtml', '<?php echo "x";'); // ensure recursive + non-.php caught
$mkdir($dir . '/nested');

$result = catThemeValidate($slug);
$errors = implode("\n    ", $result['errors'] ?? []);
echo "valid=" . var_export($result['valid'] ?? null, true) . "\n";
echo "declarative_content check=" . var_export($result['checks']['declarative_content'] ?? null, true) . "\n";
echo "errors:\n    " . $errors . "\n";
echo ($result['valid'] ?? true) === false && str_contains($errors, 'payload.php') && str_contains($errors, 'evil.phtml')
    ? "G3_PASS\n" : "G3_FAIL\n";

// cleanup runtime artifact
$rm = static function (string $d) use (&$rm): void { if (is_dir($d)) { foreach (scandir($d) as $e) { if ($e === '.' || $e === '..') continue; $p = $d . '/' . $e; is_dir($p) ? $rm($p) : unlink($p); } rmdir($d); } };
$rm($dir);
