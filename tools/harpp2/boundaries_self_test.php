#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Self-test for the destructive-introduction boundary check (lesson L8, repaired 2026-09-18).
 *
 * Both controls are required:
 *   - a run that merely TOUCHES a file already containing DROP TABLE must not be accused (the false
 *     positive that escalated Akira item D.1 on 2026-09-18);
 *   - a run that ADDS a destructive statement must still be refused.
 *
 * A guard that cries wolf is worse than none (CD-78); a guard that has been silenced is worse still.
 *
 * usage: php tools/harpp2/boundaries_self_test.php
 * exit:  0 when every case passes, 1 otherwise.
 */

require_once __DIR__ . '/destructive-introduction.php';

$pass = 0;
$fail = 0;

function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        printf("  [PASS] %s\n", $label);
        return;
    }
    $fail++;
    printf("  [FAIL] %s\n         expected: %s\n         actual:   %s\n", $label, var_export($expected, true), var_export($actual, true));
}

$aPath = 'modules/cms-akira/cms-akira-shell/tests/redirect_console_test.php';

// ── The real defect, as measured ─────────────────────────────────────────────────────────────────────
// The lane edited the file by 5 lines (7 removed, 5 added). The destructive line itself is byte-identical
// to HEAD (verified: `diff <(git show HEAD:$F | grep 'DROP TABLE') <(grep 'DROP TABLE' $F)` → no
// difference; it simply moved from line 257 to line 255). So the fixture keeps that line IDENTICAL and
// changes a neighbouring line, which is what the real delta does.
$dropLine = "                \$db->exec('DROP TABLE IF EXISTS cms_akira_redirects');";
$before = implode("\n", [
    '                $delete = $db->prepare(\'DELETE FROM cms_akira_redirects WHERE tenant_id = ?\');',
    '            $db->prepare("DELETE FROM audit_logs WHERE module = \'cms-akira-core\'")->execute([$tenantId]);',
    $dropLine,
]);
$afterTouchedOnly = implode("\n", [
    '                $delete = $db->prepare(\'DELETE FROM cms_akira_redirects WHERE tenant_id = ?\');',
    '            $db->prepare("DELETE FROM audit_logs WHERE module = \'cms-akira-core\'")->execute();',
    $dropLine,
]);

printf("contradiction controls:\n");
check(
    'a pre-existing DROP TABLE survives a touch (the D.1 false positive)',
    null,
    destructiveIntroduction($aPath, $before, $afterTouchedOnly)
);
// Non-vacuous: prove the OLD whole-file regex WOULD have fired on exactly this data, so this control
// fails when the repair is reverted.
check(
    'the pre-repair whole-file regex would have fired on that same data (control is non-vacuous)',
    1,
    preg_match('/\b(?:DROP\s+(?:TABLE|DATABASE)|TRUNCATE\s+TABLE|ALTER\s+TABLE\b[^;]*\bDROP\b)/i', $afterTouchedOnly)
);

// ── Genuinely introduced statements must still be refused ────────────────────────────────────────────
printf("\nintroduced statements:\n");
check(
    'an ADDED DROP TABLE is a violation',
    "destructive operation introduced in {$aPath}",
    destructiveIntroduction($aPath, "SELECT 1;\n", "SELECT 1;\nDROP TABLE IF EXISTS cms_akira_redirects;\n")
);
check(
    'an ADDED TRUNCATE TABLE is a violation',
    "destructive operation introduced in {$aPath}",
    destructiveIntroduction($aPath, "SELECT 1;\n", "SELECT 1;\nTRUNCATE TABLE cms_akira_redirects;\n")
);
check(
    'an ADDED ALTER TABLE ... DROP COLUMN is a violation',
    "destructive operation introduced in {$aPath}",
    destructiveIntroduction($aPath, "SELECT 1;\n", "SELECT 1;\nALTER TABLE cms_akira_redirects DROP COLUMN legacy;\n")
);
check(
    'a NEW file containing DROP TABLE is a violation',
    "destructive operation introduced in {$aPath}",
    destructiveIntroduction($aPath, '', "DROP TABLE IF EXISTS cms_akira_redirects;\n")
);

// ── Explicit non-goals, asserted so the pattern's limits are documented rather than assumed ────────────
printf("\nrecorded non-goals (the check does not claim to catch these):\n");
check(
    'an ADDED DELETE FROM is NOT flagged (scope of this check)',
    null,
    destructiveIntroduction($aPath, "SELECT 1;\n", "SELECT 1;\nDELETE FROM cms_akira_redirects WHERE tenant_id = 1;\n")
);
check(
    'unchanged content is never a violation',
    null,
    destructiveIntroduction($aPath, "DROP TABLE x;\n", "DROP TABLE x;\n")
);
check(
    'oversized content (null snapshot) does not crash or accuse',
    null,
    destructiveIntroduction($aPath, null, null)
);

// ── The command policy's destructive patterns, both directions ────────────────────────────────────────
// REPAIR 2026-09-19 (lesson L8, the second copy). `commandAllowed()` matched a bare `\bDROP\b` against the
// whole command string, so the Star Swarm p12 lane could not run the test for its own requirement --
// `--grep "the drop is legible as it expires"` was refused as "destroy data" and the item escalated twice
// for 46 minutes. A guard that cries wolf is worse than none; a guard that has been silenced is worse still.
// So BOTH directions are asserted: the false positives are gone AND every real destructive statement stays
// refused. Driven through the shared patterns, which are what `commandAllowed()` now consumes.
printf("\ncommand policy -- the false positives that were confirmed dead:\n");
$sqlVerdict = static fn (string $cmd): ?string =>
    preg_match(HARPP2_DESTRUCTIVE_PATTERN, $cmd) === 1 || preg_match(HARPP2_DESTRUCTIVE_CONTEXT_PATTERN, $cmd) === 1
        ? 'destroy data'
        : null;

check(
    'a test title containing "the drop is legible as it expires" is NOT destructive',
    null,
    $sqlVerdict('npx playwright test tests/browser/star-swarm-pixels.spec.ts --grep "the drop is legible as it expires @p12"')
);
check(
    'a read-only search for DROP is NOT destructive',
    null,
    $sqlVerdict('grep -rn "DROP" migrations/')
);

printf("command policy -- still refused, because these really do destroy data:\n");
check('DROP TABLE is still refused', 'destroy data', $sqlVerdict('mysql -e "DROP TABLE users"'));
check('DROP DATABASE is still refused', 'destroy data', $sqlVerdict('mysql -e "DROP DATABASE app"'));
check('TRUNCATE TABLE is still refused', 'destroy data', $sqlVerdict('mysql -e "TRUNCATE TABLE x"'));
check('ALTER TABLE ... DROP COLUMN is still refused', 'destroy data', $sqlVerdict('mysql -e "ALTER TABLE t DROP COLUMN c"'));
check('mysql TRUNCATE with TABLE omitted is still refused', 'destroy data', $sqlVerdict('mysql -e "TRUNCATE users"'));
check('an interpreter running TRUNCATE is still refused', 'destroy data', $sqlVerdict('php -r "$db->exec(\'TRUNCATE users\');"'));

printf("\n  => %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
