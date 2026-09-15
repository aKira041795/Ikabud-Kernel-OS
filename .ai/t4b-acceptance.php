<?php

declare(strict_types=1);

/** Independent T4b acceptance probe: every catalogue block must validate with its defaults. */

$jar = '/tmp/ck4.txt';
$host = 'akiracms.test';
$base = 'http://127.0.0.1';

$get = static function (string $path) use ($jar, $host, $base): string {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $host, 'Accept: application/json'],
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};

$post = static function (string $path, array $payload) use ($jar, $host, $base): string {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $host, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};

$catalogue = json_decode($get('/api/v1/cms-akira-theme/blocks'), true);
if (!is_array($catalogue) || ($catalogue['ok'] ?? false) !== true) {
    echo "CATALOGUE FETCH FAILED\n";
    exit(1);
}
$blocks = $catalogue['blocks'] ?? [];
echo 'theme_slug=' . ($catalogue['theme_slug'] ?? '?') . ' blocks=' . count($blocks) . PHP_EOL;

$failures = 0;
foreach ($blocks as $definition) {
    $id = (string) ($definition['id'] ?? '');
    $tree = ['version' => 1, 'blocks' => [[
        'block' => $id,
        'props' => is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [],
        'children' => [],
    ]]];
    $raw = $post('/api/v1/cms-akira/builder/validate', [
        'idempotency_key' => 't4b-accept-' . $id . '-' . bin2hex(random_bytes(4)),
        'tree' => $tree,
    ]);
    $decoded = json_decode($raw, true);
    $valid = ($decoded['ok'] ?? false) === true && ($decoded['data']['valid'] ?? false) === true;
    if (!$valid) {
        ++$failures;
    }
    echo str_pad($id, 12) . ($valid ? 'PASS' : 'FAIL') . ' ' . substr(str_replace(["\n", '  '], '', $raw), 0, 120) . PHP_EOL;
}

// Preview render must still work for the existing composition.
$render = json_decode($get('/api/v1/cms-akira/builder/compositions/t4a-page/render?source=preview'), true);
echo 'preview render ok=' . var_export(($render['ok'] ?? false) === true, true) . PHP_EOL;

echo $failures === 0 ? "ACCEPTANCE: PASS\n" : "ACCEPTANCE: FAIL ({$failures})\n";
exit($failures === 0 ? 0 : 1);
