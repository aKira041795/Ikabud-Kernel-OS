<?php
declare(strict_types=1);

/*
 * HARPP v2 — "was a destructive statement INTRODUCED by this run?"
 *
 * Extracted from harpp2.php's boundaryViolations() so the check can be driven by both controls in
 * `boundaries_self_test.php`. Same reason assertions.php exists: a guard that cannot be exercised in
 * isolation cannot be trusted.
 *
 * REPAIR (lesson L8, 2026-09-18). The check used to preg_match the WHOLE new file:
 *
 *     if (is_string($new) && preg_match('/\b(?:DROP\s+(?:TABLE|DATABASE)|...)/i', $new)) {
 *         $violations[] = "destructive operation introduced in {$path}";
 *     }
 *
 * so any file the lane merely *touched* was accused of introducing a destructive statement it already had.
 * Measured on Akira item D.1: the lane changed
 * `modules/cms-akira/cms-akira-shell/tests/redirect_console_test.php` by 5 lines, shifting
 * `DROP TABLE IF EXISTS cms_akira_redirects` (present in HEAD at line 257) to line 255 — and the driver
 * escalated "destructive operation introduced in ...", refusing a run that had introduced nothing.
 *
 * Now delta-aware, like its siblings (array_diff_key for dependencies, array_diff for removed security
 * lines, classifyAssertionChange for tests): only a destructive statement whose line this run ADDS counts.
 * A new file still counts, because its old content is the empty string. A destructive operation the executor
 * *reports* is still a violation, handled separately in boundaryViolations() — genuinely destructive work is
 * not excused by this repair.
 */

/** Statements that destroy stored data if a run introduces them. */
const HARPP2_DESTRUCTIVE_PATTERN = '/\b(?:DROP\s+(?:TABLE|DATABASE)|TRUNCATE\s+TABLE|ALTER\s+TABLE\b[^;]*\bDROP\b)/i';

/**
 * Returns the violation message when $new ADDS a destructive statement relative to $old, else null.
 *
 * @param string|null $old Baseline content (empty string for a file that did not exist before the run).
 * @param string|null $new Current content.
 */
function destructiveIntroduction(string $path, ?string $old, ?string $new): ?string
{
    if (!is_string($old) || !is_string($new)) {
        return null;
    }
    $addedLines = array_diff(
        preg_split('/\R/', $new) ?: [],
        preg_split('/\R/', $old) ?: [],
    );
    if ($addedLines === []) {
        return null;
    }
    return preg_grep(HARPP2_DESTRUCTIVE_PATTERN, $addedLines) === []
        ? null
        : "destructive operation introduced in {$path}";
}
