<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * GET /star-swarm — public, anonymous game page.
 *
 * Reads no tenant data: it renders the DiSyL template through the kernel and
 * injects the active theme's design tokens as CSS custom properties.
 *
 * @param array<string,mixed> $params
 */
function starSwarmPage(array $params = []): void
{
    header('Content-Type: text/html; charset=utf-8');

    $html = starSwarmHtml();
    if ($html === '') {
        http_response_code(500);
        echo 'Star Swarm template failed to render.';
        return;
    }

    echo $html;
}
