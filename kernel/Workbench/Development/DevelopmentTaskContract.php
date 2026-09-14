<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Architecture task contract: strict .ai/current-task.md import adapter and
 * normalized scope/contract building for the Development Control Plane.
 *
 * The canonical persisted representation is the versioned normalized record.
 * Missing required sections are rejected rather than guessed. Scope matching
 * operates on normalized repository-relative paths; ambiguous patterns fail closed.
 */
final class DevelopmentTaskContract
{
    public const REQUIRED_HEADINGS = [
        'Objective',
        'Architectural constraints',
        'Files likely affected',
        'Acceptance criteria',
        'Required tests',
        'Risks',
        'Forbidden changes',
    ];

    /** Headings whose content is captured verbatim as normative contract text. */
    public const TEXT_HEADINGS = [
        'Objective' => 'objective',
        'Architectural constraints' => 'constraints',
        'Acceptance criteria' => 'acceptance',
        'Required tests' => 'required_tests',
        'Risks' => 'risks',
        'Forbidden changes' => 'forbidden_rules',
    ];

    /** @var list<string> */
    private const SECRET_TOKEN_PATTERNS = [
        // The leading negative lookbehind stops "sk-" from matching inside a
        // larger word/path (e.g. the "sk" in "task-2026..."), which would mangle
        // structural paths during envelope redaction.
        '/(?<![A-Za-z0-9_-])(sk|pk|ghp|gho|ghu|github_pat)[-_][A-Za-z0-9_\-]{12,}/',
        '/(api[_-]?key|secret|token|password|passwd|authorization|cookie|session|csrf|credential)\s*[:=]\s*["\']?[A-Za-z0-9_\-\.\/\+]{8,}/i',
    ];

    /**
     * Parse .ai/current-task.md fixed headings into a normalized parsed contract.
     *
     * @throws \InvalidArgumentException when a required heading is missing.
     * @return array<string,mixed>
     */
    public static function parseCurrentTaskMarkdown(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            throw new \InvalidArgumentException('Architecture source is empty');
        }

        $sections = self::extractSections($markdown);
        $missing = [];
        foreach (self::REQUIRED_HEADINGS as $heading) {
            if (!isset($sections[$heading]) || trim($sections[$heading]) === '') {
                $missing[] = $heading;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Architecture import rejected: missing required section(s): ' . implode(', ', $missing)
            );
        }

        $allowed = self::parseBullets($sections['Files likely affected'], 'allowed')['scope'];
        $forbiddenResult = self::parseBullets($sections['Forbidden changes'], 'forbidden');
        $forbidden = $forbiddenResult['scope'];
        $forbiddenRules = $forbiddenResult['rules'];

        // Optional "Baseline" heading: explicitly declared pre-existing working-tree
        // changes that must remain as-is and are NOT task scope (e.g. dirty .github
        // files that predate the task). When absent, the ingestor captures the
        // baseline from Git at import time.
        $baseline = [];
        if (isset($sections['Baseline']) && trim($sections['Baseline']) !== '') {
            $baseline = self::parseBullets($sections['Baseline'], 'baseline')['scope'];
        }

        $parsed = [
            'objective' => trim($sections['Objective']),
            'constraints' => self::bulletLines($sections['Architectural constraints']),
            'acceptance' => self::bulletLines($sections['Acceptance criteria']),
            'required_tests' => self::bulletLines($sections['Required tests']),
            'risks' => self::bulletLines($sections['Risks']),
            'forbidden_rules' => $forbiddenRules,
            'allowed_scope' => $allowed,
            'forbidden_scope' => $forbidden,
            'baseline_scope' => $baseline,
            'source_hash' => hash('sha256', $markdown),
            'files_affected' => self::bulletLines($sections['Files likely affected']),
            'exceptions' => self::parseExceptions($markdown),
        ];

        return self::normalizeParsed($parsed);
    }

    /**
     * Parse the optional top-level `exceptions:` block (CD-22/CD-44).
     *
     * Shape:
     *   exceptions:
     *     - what:         <the change being authorised>
     *       why:          <the reason>
     *       scope:        <path[, path...]>
     *       decided_when: <timestamp; must precede the run>
     *       authority:    <the decision reference>
     *
     * Additive: a contract with no block yields an empty list, and the contract revision id is
     * unchanged. All five fields are required; a defective entry fails the import closed so a
     * malformed exception is refused rather than silently ignored. The trust-surface and
     * forbidden_scope guards are policy checks applied by the autonomy/run tools (which own the
     * trust-surface list); this parser enforces shape and path grammar only.
     *
     * @return list<array{what:string,why:string,scope:list<array{path:string,kind:string}>,decided_when:string,authority:string}>
     */
    private static function parseExceptions(string $markdown): array
    {
        $entries = [];
        $current = null;
        $inBlock = false;
        $flush = static function () use (&$entries, &$current): void {
            if ($current !== null) { $entries[] = $current; $current = null; }
        };
        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^exceptions[ \t]*:[ \t]*$/', $line) === 1) {
                $flush();
                $inBlock = true;
                continue;
            }
            if (!$inBlock) { continue; }
            if (trim($line) === '') { continue; }
            // Entry starts are recognised before the block terminator, so both `  - what:` and a
            // non-indented `- what:` begin an entry.
            if (preg_match('/^\s*-\s*([A-Za-z_]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                $flush();
                $current = ['what' => '', 'why' => '', 'scope' => [], 'decided_when' => '', 'authority' => ''];
                self::setExceptionField($current, $m[1], $m[2]);
                continue;
            }
            // Any other non-indented line ends the block (a heading, or the next top-level field).
            if (preg_match('/^\S/', $line) === 1) {
                $inBlock = false;
                $flush();
                continue;
            }
            if (preg_match('/^\s+([A-Za-z_]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                if ($current === null) {
                    throw new \InvalidArgumentException("Architecture import rejected: exceptions block field '{$m[1]}' appears before any entry");
                }
                self::setExceptionField($current, $m[1], $m[2]);
                continue;
            }
        }
        $flush();

        foreach ($entries as $index => $entry) {
            $position = $index + 1;
            $label = $entry['what'] !== '' ? $entry['what'] : "entry {$position}";
            foreach (['what', 'why', 'scope', 'decided_when', 'authority'] as $field) {
                $empty = $field === 'scope' ? $entry['scope'] === [] : $entry[$field] === '';
                if ($empty) {
                    throw new \InvalidArgumentException("Architecture import rejected: exception '{$label}' is missing required field '{$field}'");
                }
            }
            if (strtotime($entry['decided_when']) === false) {
                throw new \InvalidArgumentException("Architecture import rejected: exception '{$label}' field 'decided_when' is not a parseable timestamp: '{$entry['decided_when']}'");
            }
        }

        return array_values($entries);
    }

    /** Assign one key/value from an `exceptions:` entry. Unknown keys are ignored, so a missing
     * required field is still reported by name rather than being masked by a typo. Unknown keys are
     * deliberately not refused: CD-24 adds fields (`decided_phase`, `authority_source`) and an
     * implementation of CD-22 must not pre-empt them.
     *
     * @param array<string,mixed> $entry
     */
    private static function setExceptionField(array &$entry, string $key, string $raw): void
    {
        if (!array_key_exists($key, $entry)) { return; }
        if ($key === 'scope') {
            foreach (self::parseExceptionScope($raw) as $scope) { $entry['scope'][] = $scope; }
            return;
        }
        $entry[$key] = trim(trim($raw), "`'\"");
    }

    /**
     * Parse one `scope:` value into normalised path entries. Commas separate a path list. Absolute
     * and traversal paths are refused here, before any policy guard sees them.
     *
     * @return list<array{path:string,kind:string}>
     */
    private static function parseExceptionScope(string $raw): array
    {
        $scope = [];
        foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $token) {
            $token = trim(trim($token), " \t`'\"");
            if ($token === '') { continue; }
            $parsed = self::parseScopeEntry($token, 'allowed');
            if (!$parsed['ok']) {
                throw new \InvalidArgumentException("Architecture import rejected: exception scope '{$token}' is invalid: {$parsed['reason']}");
            }
            $scope[] = ['path' => $parsed['path'], 'kind' => $parsed['kind']];
        }
        return $scope;
    }

    /**
     * Normalize and validate a parsed contract. Fail closed on ambiguous patterns.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public static function normalizeParsed(array $parsed): array
    {
        $parsed['objective'] = trim((string) ($parsed['objective'] ?? ''));
        if ($parsed['objective'] === '') {
            throw new \InvalidArgumentException('Architecture import rejected: objective is empty');
        }

        $parsed['allowed_scope'] = self::validateScopeEntries(
            (array) ($parsed['allowed_scope'] ?? []),
            'allowed'
        );
        $parsed['forbidden_scope'] = self::validateScopeEntries(
            (array) ($parsed['forbidden_scope'] ?? []),
            'forbidden'
        );
        $parsed['baseline_scope'] = self::validateScopeEntries(
            (array) ($parsed['baseline_scope'] ?? []),
            'baseline'
        );

        $parsed['source_hash'] = (string) ($parsed['source_hash'] ?? hash('sha256', json_encode($parsed)));

        return $parsed;
    }

    /** Immutable revision id derived from the canonical normalized content. */
    public static function revisionId(array $normalized): string
    {
        $canonical = [
            'objective' => $normalized['objective'] ?? '',
            'constraints' => $normalized['constraints'] ?? [],
            'acceptance' => $normalized['acceptance'] ?? [],
            'required_tests' => $normalized['required_tests'] ?? [],
            'risks' => $normalized['risks'] ?? [],
            'forbidden_rules' => $normalized['forbidden_rules'] ?? [],
            'allowed_scope' => $normalized['allowed_scope'] ?? [],
            'forbidden_scope' => $normalized['forbidden_scope'] ?? [],
            'baseline_scope' => $normalized['baseline_scope'] ?? [],
        ];

        // Declared exceptions are part of the contracted envelope. Including them (only when
        // present) binds them to the revision id, so an exception added after dispatch moves the
        // revision and is therefore detectable — pre-declaration is enforced, not assumed. A
        // contract with no `exceptions:` block hashes exactly as before (additive format).
        if (($normalized['exceptions'] ?? []) !== []) {
            $canonical['exceptions'] = $normalized['exceptions'];
        }

        return substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * Normalize a repository-relative path. Rejects traversal and absolute
     * paths; glob metacharacters are preserved for the caller to classify as
     * `kind: glob` rather than being widened to a parent directory.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B`\"'");
        $path = preg_replace('#^\./+#', '', $path) ?? $path;
        $path = rtrim($path, '/');

        if ($path === '' || $path === '.') {
            throw new \InvalidArgumentException('Invalid scope path: empty');
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            throw new \InvalidArgumentException("Invalid absolute scope path: {$path}");
        }
        if (in_array($path, ['.', '..'], true) || str_contains($path, '../') || str_contains($path, '/../')
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new \InvalidArgumentException("Invalid traversal scope path: {$path}");
        }

        return $path;
    }

    /**
     * Parse one bullet into a scope entry or, for `forbidden` bullets only, a
     * verbatim rule.
     *
     * A bullet whose first token is path-like becomes a scope entry with kind
     * `file`, `directory` or `glob`. A glob is kept verbatim as a `glob` entry —
     * it is never widened to its parent directory, so a file-pattern prohibition
     * cannot forbid a whole tree. A forbidden bullet whose first token is not
     * path-like — or whose unmarked first word carries no path signal while the
     * bullet continues as prose — is returned as kind `rule` carrying the raw
     * text; the caller retains it so no bullet is ever dropped.
     *
     * @return array{ok:bool,reason:string,path:string,kind:string}
     */
    public static function parseScopeEntry(string $raw, string $scopeKind): array
    {
        // Extract the first backtick-quoted token, else the first whitespace token.
        // How the token was marked matters. An author who wraps a token in backticks is
        // naming a path even when the word alone looks like prose (`kernel/`,
        // `phpstan.neon`). An unmarked first word is a path only when it carries a path
        // signal or stands alone; otherwise it is prose whose first word merely happens
        // to be a bare word ("never stage anything without asking"), and binding that to
        // path `never` would enforce nothing while looking enforced.
        $backticked = false;
        $token = '';
        if (preg_match('/`([^`]+)`/', $raw, $m) === 1) {
            $token = $m[1];
            $backticked = true;
        } else {
            $token = preg_split('/\s+/', trim($raw))[0] ?? '';
        }
        $token = trim($token, " \t\n\r\0\x0B,;:-");

        if ($token === '') {
            return ['ok' => false, 'reason' => 'no path token', 'path' => '', 'kind' => 'file'];
        }

        $wasDirectory = str_ends_with($token, '/');
        $isGlob = preg_match('/[*?\[\]{}]/', $token) === 1;

        // Prose is not a path. A forbidden bullet that is not path-like is retained
        // verbatim as a rule; an allowed scope entry must be a path or the import fails
        // closed. Glob metacharacters are part of the path grammar so a glob token
        // survives here and is classified below.
        $tokenIsPathShaped = preg_match('#^[A-Za-z0-9_./*?\[\]{}\-]+$#', $token) === 1;
        $hasPathSignal = $isGlob
            || str_contains($token, '/')
            || str_contains($token, '.');
        $bulletHasTrailingWords = trim((string) preg_replace('/^\S+/', '', trim($raw))) !== '';
        $looksLikeProse = !$tokenIsPathShaped
            || (!$backticked && $bulletHasTrailingWords && !$hasPathSignal);

        if ($looksLikeProse) {
            if ($scopeKind === 'forbidden') {
                return ['ok' => false, 'reason' => 'non-path forbidden statement', 'path' => $token, 'kind' => 'rule'];
            }
            return ['ok' => false, 'reason' => 'allowed scope entry is not a path', 'path' => $token, 'kind' => $isGlob ? 'glob' : 'file'];
        }

        try {
            $token = self::normalizePath($token);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'path' => $token, 'kind' => $isGlob ? 'glob' : 'file'];
        }

        if ($isGlob) {
            return ['ok' => true, 'reason' => '', 'path' => $token, 'kind' => 'glob'];
        }

        return ['ok' => true, 'reason' => '', 'path' => $token, 'kind' => $wasDirectory ? 'directory' : 'file'];
    }

    /**
     * Partition a section's bullets into path scope entries and, for the
     * forbidden section only, non-path rules. Every bullet lands in exactly one
     * bucket, so bullets-in == paths + rules; nothing is dropped silently.
     *
     * @return array{scope:list<array{path:string,kind:string}>,rules:list<string>}
     */
    private static function parseBullets(string $section, string $scopeKind): array
    {
        $scope = [];
        $rules = [];
        foreach (self::bulletLines($section) as $line) {
            $parsed = self::parseScopeEntry($line, $scopeKind);
            if (!$parsed['ok']) {
                // Prose forbidden rules are retained, never dropped. A non-path
                // allowed line still fails the import closed.
                if ($scopeKind === 'forbidden' && $parsed['kind'] === 'rule') {
                    $rules[] = $line;
                    continue;
                }
                throw new \InvalidArgumentException(
                    "Architecture import rejected: invalid {$scopeKind} scope entry '{$line}': {$parsed['reason']}"
                );
            }
            $scope[] = ['path' => $parsed['path'], 'kind' => $parsed['kind']];
        }

        return ['scope' => $scope, 'rules' => $rules];
    }

    /**
     * Validate pre-built scope entries (used after parsing and before revision hash).
     *
     * @param list<array{path:string,kind:string}> $entries
     * @return list<array{path:string,kind:string}>
     */
    private static function validateScopeEntries(array $entries, string $scopeKind): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $kind = (string) ($entry['kind'] ?? 'file');
            if (!in_array($kind, ['file', 'directory', 'glob', 'rule'], true)) {
                throw new \InvalidArgumentException("Invalid {$scopeKind} scope kind: {$kind}");
            }
            $out[] = ['path' => $path, 'kind' => $kind];
        }

        return $out;
    }

    /** @return list<string> */
    private static function bulletLines(string $section): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '```')) {
                continue;
            }
            $lines[] = preg_replace('/^[-*]\s+/', '', $line) ?? $line;
        }

        return $lines;
    }

    /** @return array<string,string> */
    private static function extractSections(string $markdown): array
    {
        $sections = [];
        $lines = preg_split('/\r?\n/', $markdown) ?: [];
        $current = null;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                if ($current !== null) {
                    $sections[$current] = trim(implode("\n", $buffer));
                }
                $current = trim($m[1]);
                $buffer = [];
                continue;
            }
            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        if ($current !== null) {
            $sections[$current] = trim(implode("\n", $buffer));
        }

        return $sections;
    }

    /** Best-effort removal of secrets from arbitrary scalar text (mirrors redaction). */
    public static function redactScalar(string $value): string
    {
        foreach (self::SECRET_TOKEN_PATTERNS as $pattern) {
            $value = (string) preg_replace($pattern, '[REDACTED]', $value);
        }

        return $value;
    }
}
