<?php

declare(strict_types=1);

/**
 * Star Swarm helpers.
 *
 * The game is deliberately database-free. The only external input is the
 * ARK/akira-editorial design-token file (tokens.json) that the public layout
 * already renders into CSS custom properties. We read the same file with the
 * kernel's own ThemeDefinitionLoader so there is exactly one token convention,
 * and we fall back to baked-in values when a token is missing. Every colour
 * and size the game draws comes from one of these tokens.
 */

/**
 * The tokens the game consumes, with a fallback that keeps the canvas
 * renderable when a theme omits the token. The map is also the token
 * contract the deterministic test asserts against.
 *
 * @return array<string, array{fallback: string, purpose: string}>
 */
function starSwarmTokenDefaults(): array
{
    return [
        '--color-surface' => ['fallback' => '#0b1020', 'purpose' => 'page and canvas background'],
        '--color-surface-raised' => ['fallback' => '#141b33', 'purpose' => 'HUD panel surface'],
        '--color-text' => ['fallback' => '#e8ecff', 'purpose' => 'HUD text'],
        '--color-text-muted' => ['fallback' => '#9aa3c7', 'purpose' => 'secondary HUD text'],
        '--color-primary' => ['fallback' => '#4cc9f0', 'purpose' => 'player ship'],
        '--color-primary-dark' => ['fallback' => '#1d4ed8', 'purpose' => 'player ship accent'],
        '--color-accent' => ['fallback' => '#ffd166', 'purpose' => 'player bullets'],
        '--color-error' => ['fallback' => '#ef476f', 'purpose' => 'enemy bullets and life pips'],
        '--color-tertiary' => ['fallback' => '#f78c6b', 'purpose' => 'enemy formation'],
        '--color-border' => ['fallback' => '#2a3357', 'purpose' => 'canvas frame'],
        '--ss-space-bg' => ['fallback' => '#02030b', 'purpose' => 'near-black play-field background'],
        '--ss-space-deep' => ['fallback' => '#071126', 'purpose' => 'deep-space gradient depth'],
        '--ss-nebula' => ['fallback' => '#18356f', 'purpose' => 'low-contrast dust glow'],
        '--ss-star-dim' => ['fallback' => '#8ba3c7', 'purpose' => 'far and middle stars'],
        '--ss-star-bright' => ['fallback' => '#f4f8ff', 'purpose' => 'near stars and projectile core'],
        '--ss-planet' => ['fallback' => '#568bb0', 'purpose' => 'nearby planet body'],
        '--ss-planet-lit' => ['fallback' => '#c9e7f2', 'purpose' => 'planet lit limb'],
        '--ss-ring' => ['fallback' => '#f2c879', 'purpose' => 'planet ring'],
        '--ss-role-threat' => ['fallback' => '#f78c6b', 'purpose' => 'hostile colony bodies'],
        '--ss-role-butterfly' => ['fallback' => '#ff6464', 'purpose' => 'Butterfly caste'],
        '--ss-role-magnet' => ['fallback' => '#39ff5a', 'purpose' => 'Boss Galaga magnet ship'],
        '--ss-role-ally' => ['fallback' => '#4cc9f0', 'purpose' => 'player and friendly fire'],
        '--ss-role-reward' => ['fallback' => '#ffd166', 'purpose' => 'score and reward feedback'],
        '--ss-role-hazard' => ['fallback' => '#ef476f', 'purpose' => 'enemy projectiles and hazards'],
        '--font-family-ui' => ['fallback' => 'Inter, system-ui, sans-serif', 'purpose' => 'HUD font'],
        '--radius' => ['fallback' => '0.5rem', 'purpose' => 'HUD corner radius'],
        '--spacing-page' => ['fallback' => '1.25rem', 'purpose' => 'page gutter around the canvas'],
    ];
}

/**
 * Resolve the theme slug whose tokens.json drives the game. The public
 * layout uses the same file-based mechanism; an explicit STAR_SWARM_THEME
 * environment value lets a different installed theme repaint the game with
 * no code change.
 */
function starSwarmThemeSlug(): string
{
    $slug = getenv('STAR_SWARM_THEME');
    if (is_string($slug) && trim($slug) !== '') {
        return trim($slug);
    }

    return 'akira-editorial';
}

/**
 * Read the active theme's tokens.json. Falls back to an empty array when the
 * file is absent so callers always get the baked-in fallbacks.
 *
 * @return array<string, string>
 */
function starSwarmThemeTokens(): array
{
    $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    $slug = starSwarmThemeSlug();
    if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
        return [];
    }

    $themePath = rtrim($root, '/') . '/storage/cms-themes/' . $slug;
    $tokensFile = $themePath . '/tokens.json';

    // Prefer the kernel loader (the shared ARK mechanism). It is only present
    // after bootstrap; the raw JSON read keeps the helper usable standalone.
    if (class_exists(\Ikabud\Kernel\Services\ThemeDefinitionLoader::class)) {
        $definition = \Ikabud\Kernel\Services\ThemeDefinitionLoader::load($slug, $themePath);
        if ($definition !== null) {
            $tokens = [];
            foreach ($definition->tokens as $name => $token) {
                if (is_array($token) && array_key_exists('default', $token)) {
                    $tokens[$name] = (string) $token['default'];
                }
            }
            if ($tokens !== []) {
                return $tokens;
            }
        }
    }

    if (!is_file($tokensFile) || !is_readable($tokensFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($tokensFile), true);
    if (!is_array($decoded)) {
        return [];
    }

    $tokens = [];
    foreach ($decoded as $name => $value) {
        if (is_string($name) && (is_string($value) || is_int($value) || is_float($value))) {
            $tokens[$name] = (string) $value;
        }
    }

    return $tokens;
}

/**
 * Build the token CSS injected on the game root. Each declaration is the
 * theme value when present, otherwise the fallback. Only the tokens the game
 * consumes are emitted, and every one of them is emitted — so the token
 * contract is complete by construction.
 */
function starSwarmTokenCss(): string
{
    $themeTokens = starSwarmThemeTokens();
    $declarations = [];
    foreach (starSwarmTokenDefaults() as $name => $spec) {
        $value = $themeTokens[$name] ?? $spec['fallback'];
        $declarations[] = $name . ':' . $value;
    }

    return '#star-swarm{' . implode(';', $declarations) . '}';
}

/**
 * Build the game page markup by rendering the DiSyL template through the
 * kernel. This is the single source of the markup for both the Tier 1 route
 * handler and the Tier 2 CLI renderer.
 */
function starSwarmHtml(): string
{
    $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    $assetUrl = static function (string $path) use ($root): string {
        $file = rtrim($root, '/') . '/public' . $path;
        $mtime = is_file($file) ? filemtime($file) : false;
        if ($mtime === false) {
            throw new RuntimeException("Cannot version unreadable Star Swarm asset: {$file}");
        }

        return $path . '?v=' . $mtime;
    };

    $context = [
        'page_title' => 'Star Swarm',
        'token_css' => starSwarmTokenCss(),
        'game_css_url' => $assetUrl('/star-swarm/star-swarm.css'),
        'game_js_url' => $assetUrl('/star-swarm/star-swarm.js'),
    ];

    if (function_exists('app')) {
        return app()->render('modules/star-swarm/star-swarm.disyl', $context);
    }

    return '';
}
