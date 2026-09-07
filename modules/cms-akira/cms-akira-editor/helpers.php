<?php

declare(strict_types=1);

/** @return array<string, string> */
function cms_akira_editor_capability_handlers(): array
{
    return [
        'akira.editor.render@1' => 'cae_cap_akira_editor_render_1',
        'akira.editor.normalize@1' => 'cae_cap_akira_editor_normalize_1',
        'akira.editor.sanitize@1' => 'cae_cap_akira_editor_sanitize_1',
        'akira.editor.validate@1' => 'cae_cap_akira_editor_validate_1',
        'akira.editor.assets@1' => 'cae_cap_akira_editor_assets_1',
    ];
}

/**
 * Resolve only the public Post detail projection. Callers may supply a
 * projection already obtained from the entity view, or a canonical slug for
 * lookup through the capability boundary. Domain rows are never accepted.
 *
 * @param array<string, mixed> $payload
 * @return array{ok: bool, post?: array<string, mixed>, error?: string}
 */
function caeEditorPostProjection(array $payload): array
{
    $post = $payload['post'] ?? null;
    if ($post === null && is_string($payload['slug'] ?? null) && function_exists('app')) {
        $result = app()->cap()->call('entity.get.post@1', ['id' => trim($payload['slug'])], [
            'caller' => ['module' => 'cms-akira-editor'],
            'mode' => 'first',
        ]);
        $post = is_array($result) && ($result['ok'] ?? false) === true ? ($result['data'] ?? null) : null;
    }

    if ($post === null && array_key_exists('content', $payload)) {
        $post = ['body' => $payload['content']];
    }
    if (!is_array($post)) {
        return ['ok' => false, 'error' => 'an Akira Post detail projection is required'];
    }

    $allowed = ['title', 'subtitle', 'image', 'body', 'metadata', 'actions', 'url'];
    if (array_diff(array_keys($post), $allowed) !== []) {
        return ['ok' => false, 'error' => 'post contains fields outside the public Akira projection'];
    }
    if (!is_string($post['body'] ?? null)) {
        return ['ok' => false, 'error' => 'post.body must be a string'];
    }

    return ['ok' => true, 'post' => array_intersect_key($post, array_flip($allowed))];
}

function caeEditorSafeUrl(string $url): bool
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        return false;
    }
    if ($url[0] === '#' || ($url[0] === '/' && !str_starts_with($url, '//') && !str_contains($url, '\\'))) {
        return true;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https', 'mailto'], true);
}

/**
 * Produce one canonical safe fragment using a fixed, local policy.
 */
function caeEditorCanonicalHtml(string $html): string
{
    $html = str_replace(["\r\n", "\r"], "\n", trim($html));
    if ($html === '') {
        return '';
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML(
        '<?xml encoding="UTF-8"><div data-akira-editor-root="1">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $document->getElementsByTagName('div')->item(0);
    if (!$root instanceof DOMElement) {
        return '';
    }

    $allowedTags = array_flip([
        'a', 'b', 'blockquote', 'br', 'code', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'i', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong', 'u', 'ul',
    ]);
    $elements = [];
    foreach ($root->getElementsByTagName('*') as $element) {
        $elements[] = $element;
    }
    foreach (array_reverse($elements) as $element) {
        if (!$element instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($element->tagName);
        if (!isset($allowedTags[$tag])) {
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }
            $parent = $element->parentNode;
            if ($parent !== null) {
                while ($element->firstChild !== null) {
                    $parent->insertBefore($element->firstChild, $element);
                }
                $parent->removeChild($element);
            }
            continue;
        }

        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }
        foreach ($attributes as $attribute) {
            $keep = $tag === 'a' && in_array(strtolower($attribute), ['href', 'title', 'target', 'rel'], true);
            if (!$keep) {
                $element->removeAttribute($attribute);
            }
        }
        if ($tag === 'a') {
            $href = $element->getAttribute('href');
            if ($href !== '' && !caeEditorSafeUrl($href)) {
                $element->removeAttribute('href');
            }
            if ($element->getAttribute('target') !== '_blank') {
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
            } else {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= (string) $document->saveHTML($child);
    }
    $output = preg_replace('/>\s+</u', '><', $output) ?? $output;

    return trim($output);
}

/** @return array<string, mixed> */
function caeEditorProjectionError(mixed $payload): array
{
    return is_array($payload)
        ? caeEditorPostProjection($payload)
        : ['ok' => false, 'error' => 'payload must be an object'];
}

/** @return array<string, mixed> */
function cae_cap_akira_editor_render_1(mixed $payload, string $capabilityId = 'akira.editor.render@1', string $caller = 'unknown'): array
{
    $resolved = caeEditorProjectionError($payload);
    if (($resolved['ok'] ?? false) !== true) {
        return $resolved;
    }

    return ['ok' => true, 'data' => [
        'html' => caeEditorCanonicalHtml((string) $resolved['post']['body']),
        'provider' => 'cms-akira-editor',
        'source' => 'akira.post.projection',
    ]];
}

/** @return array<string, mixed> */
function cae_cap_akira_editor_normalize_1(mixed $payload, string $capabilityId = 'akira.editor.normalize@1', string $caller = 'unknown'): array
{
    $resolved = caeEditorProjectionError($payload);
    if (($resolved['ok'] ?? false) !== true) {
        return $resolved;
    }

    return ['ok' => true, 'data' => [
        'content' => caeEditorCanonicalHtml((string) $resolved['post']['body']),
        'provider' => 'cms-akira-editor',
        'source' => 'akira.post.projection',
    ]];
}

/** @return array<string, mixed> */
function cae_cap_akira_editor_sanitize_1(mixed $payload, string $capabilityId = 'akira.editor.sanitize@1', string $caller = 'unknown'): array
{
    return cae_cap_akira_editor_normalize_1($payload, $capabilityId, $caller);
}

/** @return array<string, mixed> */
function cae_cap_akira_editor_validate_1(mixed $payload, string $capabilityId = 'akira.editor.validate@1', string $caller = 'unknown'): array
{
    $resolved = caeEditorProjectionError($payload);
    if (($resolved['ok'] ?? false) !== true) {
        return $resolved;
    }

    $body = (string) $resolved['post']['body'];
    $errors = [];
    if (trim($body) === '') {
        $errors[] = 'post.body is required';
    }
    if (mb_strlen($body) > 65535) {
        $errors[] = 'post.body exceeds 65535 characters';
    }

    return ['ok' => true, 'data' => [
        'valid' => $errors === [],
        'errors' => $errors,
        'provider' => 'cms-akira-editor',
        'source' => 'akira.post.projection',
    ]];
}

/** @return array<string, mixed> */
function cae_cap_akira_editor_assets_1(mixed $payload, string $capabilityId = 'akira.editor.assets@1', string $caller = 'unknown'): array
{
    if ($payload !== null && !is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    return ['ok' => true, 'data' => [
        'version' => '1.0.0',
        'js' => ['/assets/modules/cms-akira-editor/editor.js'],
        'css' => ['/assets/modules/cms-akira-editor/editor.css'],
        'provider' => 'cms-akira-editor',
        'external' => false,
    ]];
}
