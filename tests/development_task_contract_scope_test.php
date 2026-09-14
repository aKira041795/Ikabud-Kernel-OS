<?php

declare(strict_types=1);

/**
 * Scope-path semantics: the kernel contract parser must represent every
 * `## Forbidden changes` bullet in exactly one honest bucket, and a glob must
 * stay a glob rather than widening to its parent directory.
 *
 * These cases pin the three verified facts that motivated the fix:
 *   1. a non-path bullet is retained as a rule (never silently dropped);
 *   2. `.ai/*.contract.md` is kind `glob`, not directory `.ai`;
 *   3. a trailing-slash directory is kind `directory`, never prose.
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Development/DevelopmentTaskContract.php';

use Ikabud\Kernel\Workbench\Development\DevelopmentTaskContract;

$h = new TestHarness('development-task-contract-scope', TestHarness::MODE_PURE);
$h->fingerprint('kernel/Workbench/Development/DevelopmentTaskContract.php');

/**
 * Build a parseable contract carrying the seven required headings.
 *
 * @param list<string> $forbidden
 * @param list<string> $allowed
 */
function scopeFixture(array $forbidden, array $allowed = ['- `tools/` — safe work']): string
{
    return "# CONTRACT — scope fixture\n"
        . "## Objective\nScope parser fixture.\n"
        . "## Architectural constraints\n- none\n"
        . "## Files likely affected\n" . implode("\n", $allowed) . "\n"
        . "## Acceptance criteria\n- ok\n"
        . "## Required tests\n- `php -l kernel/Workbench/Development/DevelopmentTaskContract.php`\n"
        . "## Risks\n- none\n"
        . "## Forbidden changes\n" . implode("\n", $forbidden) . "\n";
}

/** Count non-empty, non-fence bullet lines in the raw `## Forbidden changes` section. */
function forbiddenBulletCount(string $markdown): int
{
    $count = 0;
    $in = false;
    foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
        if (preg_match('/^#{1,3}\s+Forbidden changes\s*$/i', (string) $line) === 1) {
            $in = true;
            continue;
        }
        if ($in && preg_match('/^#{1,3}\s+/', (string) $line) === 1) {
            break;
        }
        if (!$in) {
            continue;
        }
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '```')) {
            continue;
        }
        $count++;
    }

    return $count;
}

/** @param list<array{path:string,kind:string}> $entries */
function scopeHasKind(array $entries, string $path, string $kind): bool
{
    foreach ($entries as $entry) {
        if ($entry['path'] === $path && $entry['kind'] === $kind) {
            return true;
        }
    }

    return false;
}

$h->section('P1 — every forbidden bullet lands in exactly one bucket (fact 1)');

// The pre-fix fact: 8 bullets, the parser returned only 7 scope entries and the
// `git add` bullet vanished with no error, warning or trace.
$factOneForbidden = [
    '- `phpstan-baseline.neon` — no quality-gate baseline edit',
    '- `phpstan.neon` — no gate configuration edit',
    '- `composer.json` — no new PHP dependency',
    '- `package.json` — no new Node dependency',
    '- `.github/workflows/` — no CI workflow edit',
    '- `kernel/` — no kernel change',
    '- `.ai/decisions/` — no decision artifact edit',
    '- `git add` — no staging, commit, push or branch switching',
];
$factOneMarkdown = scopeFixture($factOneForbidden);
$factOne = DevelopmentTaskContract::parseCurrentTaskMarkdown($factOneMarkdown);
$factOneScope = count($factOne['forbidden_scope']);
$factOneRules = count($factOne['forbidden_rules']);

$h->test(
    '1. bullets-in == paths + rules (8 == 7 + 1) with no silent loss',
    forbiddenBulletCount($factOneMarkdown) === 8 && $factOneScope === 7 && $factOneRules === 1,
    'bullets=' . forbiddenBulletCount($factOneMarkdown) . ' scope=' . $factOneScope . ' rules=' . $factOneRules
);
$h->test(
    '2. the non-path `git add` bullet is represented as a rule, not dropped',
    count($factOne['forbidden_rules']) === 1
        && str_contains($factOne['forbidden_rules'][0], 'git add')
        && !scopeHasKind($factOne['forbidden_scope'], 'git add', 'file'),
    'rules=' . json_encode($factOne['forbidden_rules'])
);
$h->test(
    '3. the rule carries the verbatim bullet text',
    str_contains($factOne['forbidden_rules'][0], 'no staging'),
    'rule=' . ($factOne['forbidden_rules'][0] ?? '<none>')
);

$h->section('P1b — unmarked prose with trailing words is a rule, not a bare-word path');
$proseContract = DevelopmentTaskContract::parseCurrentTaskMarkdown(scopeFixture([
    '- `forbidden/` — never touch',
    '- No weakening, skipping or deleting an existing test to reach a pass.',
    '- seven files listed above.',
    '- this repair.',
]));
$proseRules = $proseContract['forbidden_rules'];
$h->test(
    '3a. the three unmarked prose bullets are rules and no bare word became a path',
    count($proseContract['forbidden_scope']) === 1
        && count($proseRules) === 3
        && !scopeHasKind($proseContract['forbidden_scope'], 'No', 'file')
        && !scopeHasKind($proseContract['forbidden_scope'], 'seven', 'file')
        && !scopeHasKind($proseContract['forbidden_scope'], 'this', 'file'),
    'scope=' . json_encode($proseContract['forbidden_scope']) . ' rules=' . json_encode($proseRules)
);
$h->test(
    '3b. the bullets-in == paths + rules invariant still holds (4 == 1 + 3)',
    forbiddenBulletCount(scopeFixture([
        '- `forbidden/` — never touch',
        '- No weakening, skipping or deleting an existing test to reach a pass.',
        '- seven files listed above.',
        '- this repair.',
    ])) === 4 && count($proseContract['forbidden_scope']) + count($proseRules) === 4,
    'scope=' . count($proseContract['forbidden_scope']) . ' rules=' . count($proseRules)
);

$h->section('P2 — a glob is a glob, not its parent directory (fact 2)');
$globForbidden = ['- `.ai/*.contract.md` — never edit another contract'];
$globAllowed = ['- `.ai/ai-autonomy-harness.contract.md` — the referenced standing contract'];
$globContract = DevelopmentTaskContract::parseCurrentTaskMarkdown(scopeFixture($globForbidden, $globAllowed));
$h->test(
    '4. `.ai/*.contract.md` is kind `glob` carrying the pattern',
    scopeHasKind($globContract['forbidden_scope'], '.ai/*.contract.md', 'glob'),
    'forbidden=' . json_encode($globContract['forbidden_scope'])
);
$h->test(
    '5. the glob did NOT widen to directory `.ai`',
    !scopeHasKind($globContract['forbidden_scope'], '.ai', 'directory'),
    'forbidden=' . json_encode($globContract['forbidden_scope'])
);
$h->test(
    '6. an allowed-scope glob stays a glob too (no fail-open widening)',
    scopeHasKind(
        DevelopmentTaskContract::parseCurrentTaskMarkdown(scopeFixture(['- `forbidden/` — never touch'], ['- `tests/browser/*.spec.ts` — browser specs']))['allowed_scope'],
        'tests/browser/*.spec.ts',
        'glob'
    ),
    'allowed fixture did not yield a glob entry'
);

$h->section('P3 — a trailing-slash directory is never prose (fact 3)');
$directoryContract = DevelopmentTaskContract::parseCurrentTaskMarkdown(scopeFixture([
    '- `kernel/` — no kernel change',
    '- `tests/` — no test change',
]));
$h->test(
    '7. `kernel/` is kind `directory` with the trailing slash stripped',
    scopeHasKind($directoryContract['forbidden_scope'], 'kernel', 'directory'),
    'forbidden=' . json_encode($directoryContract['forbidden_scope'])
);
$h->test(
    '8. `tests/` is kind `directory` with the trailing slash stripped',
    scopeHasKind($directoryContract['forbidden_scope'], 'tests', 'directory'),
    'forbidden=' . json_encode($directoryContract['forbidden_scope'])
);

$h->section('Invariant holds across the real corpus');
$root = dirname(__DIR__);
$corpusViolations = [];
foreach (glob($root . '/.ai/*.contract.md') ?: [] as $file) {
    $markdown = (string) file_get_contents($file);
    try {
        $parsed = DevelopmentTaskContract::parseCurrentTaskMarkdown($markdown);
    } catch (\InvalidArgumentException) {
        continue; // Rejected contracts have no envelope; the lint reports them.
    }
    $expected = forbiddenBulletCount($markdown);
    $actual = count($parsed['forbidden_scope']) + count($parsed['forbidden_rules']);
    if ($expected !== $actual) {
        $corpusViolations[] = basename($file) . " bullets={$expected} represented={$actual}";
    }
}
$h->test(
    '9. every parseable corpus contract represents every forbidden bullet',
    $corpusViolations === [],
    'violations=' . json_encode($corpusViolations)
);

$h->done();
