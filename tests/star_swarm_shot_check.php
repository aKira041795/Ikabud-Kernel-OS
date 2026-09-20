<?php

declare(strict_types=1);

/** Enforce the live visual evidence artifact required by iteration 2b. */

$root = dirname(__DIR__);
$spec = $root . '/tests/browser/star-swarm.spec.ts';
$shot = $root . '/test-results/star-swarm.png';
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? ++$pass : ++$fail;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
};

echo "=== running-game screenshot ===\n";
$check(is_file($shot), 'test-results/star-swarm.png exists');
$check(is_file($shot) && (int) filesize($shot) > 20 * 1024, 'screenshot is larger than 20 KB');
$check(is_file($shot) && is_file($spec) && (int) filemtime($shot) >= (int) filemtime($spec), 'screenshot is newer than the browser spec');
$signature = is_file($shot) ? (string) file_get_contents($shot, false, null, 0, 8) : '';
$check($signature === "\x89PNG\r\n\x1a\n", 'artifact has a PNG signature');

echo "\n=== summary ===\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
