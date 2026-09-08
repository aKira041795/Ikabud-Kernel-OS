<?php

/** CMS Akira AI — deterministic, table-free suggestion capability. */

declare(strict_types=1);

const CAA_AI_MODULE_ID = 'cms-akira-ai';
const CAA_AI_LOCAL_SETTING = 'local_suggestions_enabled';

/** @return array<string, string> */
function cms_akira_ai_capability_handlers(): array
{
    return [
        'akira.ai.summary.suggest@1' => 'caa_cap_akira_ai_summary_suggest_1',
    ];
}

/**
 * Local mode is on by default. A tenant may explicitly turn it off without
 * adding a provider, a module table, or a payload-controlled configuration.
 */
function caaAiLocalSuggestionsEnabled(): bool
{
    if (!function_exists('app') || !function_exists('_readTenantModuleSettingsSingle')) {
        return true;
    }

    try {
        $tenantId = (int) app()->tenant()->current();
        if ($tenantId <= 0) {
            return true;
        }
        $settings = _readTenantModuleSettingsSingle(CAA_AI_MODULE_ID, $tenantId, app()->db());
        $value = $settings[CAA_AI_LOCAL_SETTING] ?? null;
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (trim((string) $value) === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    } catch (Throwable) {
        return false;
    }
}

/** @return array{status: 'unavailable', reason: string} */
function caaAiUnavailable(string $reason): array
{
    return ['status' => 'unavailable', 'reason' => $reason];
}

function caaAiEntityKey(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);

    return $value !== ''
        && strlen($value) <= 191
        && preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9._~-]*[a-zA-Z0-9])?\z/D', $value) === 1
            ? $value
            : null;
}

function caaAiPlainText(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';

    return trim($value);
}

/**
 * Accept only the public Post detail projection. This second allowlist is
 * deliberate defense in depth if an upstream projection ever broadens.
 *
 * @param array<string, mixed> $projection
 * @return array{title: string, subtitle: string, body: string}|null
 */
function caaAiProjectionText(array $projection): ?array
{
    $allowed = ['title', 'subtitle', 'image', 'body', 'metadata', 'actions', 'url'];
    if (array_diff(array_keys($projection), $allowed) !== []) {
        return null;
    }
    foreach (['title', 'subtitle', 'body'] as $field) {
        if (!is_string($projection[$field] ?? null)) {
            return null;
        }
    }

    return [
        'title' => caaAiPlainText($projection['title']),
        'subtitle' => caaAiPlainText($projection['subtitle']),
        'body' => caaAiPlainText($projection['body']),
    ];
}

function caaAiExtractiveSummary(string $body, string $subtitle, string $title, int $limit = 240): string
{
    $source = $body !== '' ? $body : ($subtitle !== '' ? $subtitle : $title);
    if (mb_strlen($source) <= $limit) {
        return $source;
    }

    $sentences = preg_split('/(?<=[.!?])\s+/u', $source) ?: [$source];
    $summary = '';
    foreach ($sentences as $sentence) {
        $candidate = $summary === '' ? $sentence : $summary . ' ' . $sentence;
        if (mb_strlen($candidate) > $limit) {
            break;
        }
        $summary = $candidate;
    }
    if ($summary !== '') {
        return $summary;
    }

    $cut = mb_substr($source, 0, $limit + 1);
    $cut = preg_replace('/\s+\S*$/u', '', $cut) ?? '';

    return rtrim($cut !== '' ? $cut : mb_substr($source, 0, $limit));
}

/** @return list<string> */
function caaAiKeywords(string $text, int $limit = 8): array
{
    $stop = array_flip([
        'and', 'are', 'but', 'for', 'from', 'has', 'have', 'into', 'its', 'not', 'that',
        'the', 'their', 'this', 'was', 'were', 'with', 'you', 'your', 'yang', 'dan', 'dari',
        'dengan', 'ini', 'itu', 'pada', 'untuk', 'atau', 'adalah',
    ]);
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}-]*/u', mb_strtolower($text), $matches);
    $rank = [];
    foreach ($matches[0] as $position => $token) {
        $token = trim($token, '-');
        if (mb_strlen($token) < 3 || isset($stop[$token])) {
            continue;
        }
        if (!isset($rank[$token])) {
            $rank[$token] = ['count' => 0, 'first' => $position];
        }
        ++$rank[$token]['count'];
    }
    uksort($rank, static function (string $left, string $right) use ($rank): int {
        $count = $rank[$right]['count'] <=> $rank[$left]['count'];
        if ($count !== 0) {
            return $count;
        }
        $first = $rank[$left]['first'] <=> $rank[$right]['first'];

        return $first !== 0 ? $first : strcmp($left, $right);
    });

    return array_slice(array_keys($rank), 0, $limit);
}

/**
 * @param array<string, mixed> $projection
 * @return array{status: 'ok', summary: string, keywords: list<string>}|array{status: 'unavailable', reason: string}
 */
function caaAiSuggestFromProjection(array $projection): array
{
    $text = caaAiProjectionText($projection);
    if ($text === null) {
        return caaAiUnavailable('projection_unavailable');
    }

    return [
        'status' => 'ok',
        'summary' => caaAiExtractiveSummary($text['body'], $text['subtitle'], $text['title']),
        'keywords' => caaAiKeywords(implode(' ', [$text['title'], $text['subtitle'], $text['body']])),
    ];
}

/** @return array{ok: true, data: array<string, mixed>} */
function caa_cap_akira_ai_summary_suggest_1(mixed $payload, string $capabilityId = 'akira.ai.summary.suggest@1', string $caller = 'unknown'): array
{
    if (!is_array($payload) || array_diff(array_keys($payload), ['id', 'slug']) !== []) {
        return ['ok' => true, 'data' => caaAiUnavailable('invalid_entity_reference')];
    }
    $key = caaAiEntityKey($payload['id'] ?? $payload['slug'] ?? null);
    if ($key === null) {
        return ['ok' => true, 'data' => caaAiUnavailable('invalid_entity_reference')];
    }
    if (!caaAiLocalSuggestionsEnabled()) {
        return ['ok' => true, 'data' => caaAiUnavailable('local_mode_disabled')];
    }
    if (!function_exists('app')) {
        return ['ok' => true, 'data' => caaAiUnavailable('entity_unavailable')];
    }

    try {
        $result = app()->cap()->call('entity.get.post@1', ['id' => $key], [
            'caller' => ['module' => CAA_AI_MODULE_ID],
            'mode' => 'first',
        ]);
        $projection = is_array($result) && ($result['ok'] ?? false) === true
            ? ($result['data'] ?? null)
            : null;
        if (!is_array($projection)) {
            return ['ok' => true, 'data' => caaAiUnavailable('entity_unavailable')];
        }

        return ['ok' => true, 'data' => caaAiSuggestFromProjection($projection)];
    } catch (Throwable) {
        return ['ok' => true, 'data' => caaAiUnavailable('entity_unavailable')];
    }
}
