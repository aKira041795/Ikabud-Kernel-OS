<?php

declare(strict_types=1);

/**
 * Tier 2 renderer.
 *
 * Renders the DiSyL source template through the kernel into the static
 * public entry, so the game is playable at /star-swarm/ with zero database
 * involvement. The DiSyL template remains the only source of the markup;
 * this script never hand-writes a second copy of the page.
 *
 * Usage:
 *   php modules/star-swarm/cli/render.php
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__) . '/helpers.php';

$target = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)) . '/public/star-swarm/index.html';
$directory = dirname($target);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Could not create {$directory}\n");
    exit(1);
}

$html = starSwarmHtml();
if ($html === '') {
    fwrite(STDERR, "DiSyL render returned empty output\n");
    exit(1);
}

if (file_put_contents($target, $html) === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}

echo "Rendered {$target} (" . strlen($html) . " bytes)\n";
