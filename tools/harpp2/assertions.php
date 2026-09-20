<?php

declare(strict_types=1);

/**
 * Assertion-set comparison — telling DELETING an assertion apart from MOVING it.
 *
 * WHY THIS EXISTS (lesson L8, 2026-09-17). The original guard diffed LINES: any line containing
 * assert/expect/fail/throw that vanished from the new text counted as "test assertion removed or weakened".
 * Reindenting a line, reordering assertions, or splitting one test into two therefore looked exactly like
 * deleting a check — and it refused a restructure that took the Star Swarm spec from **28 to 52 assertions**
 * with every threshold intact. A guard that blocks the work which strengthens the suite is a defect, not a
 * finding: it teaches a reader to distrust the guard.
 *
 * What is compared now:
 *   COUNT  — assertions may grow, never shrink (implied by the set check).
 *   SET    — every assertion EXPRESSION present before must still be present, with whitespace normalised and
 *            quoted strings masked, so reindenting or rewording a message is NOT a removal.
 *   BOUNDS — where the same assertion appears in both, a numeric bound may not become weaker
 *            (toBeGreaterThan(8) -> toBeGreaterThan(2) is a weakening and is still caught).
 *
 * Deliberate trade-off: masking quoted strings means swapping a selector (`getByText("Save")` ->
 * `getByText("Delete")`) is not flagged. That is a behaviour change, not a weakening; the count and shape
 * checks still apply, and treating selector edits as violations would re-introduce the false positives.
 *
 * usage (self-test): php tools/harpp2/assertions.php --self-test
 */

/** Canonical form of an assertion line: whitespace normalised, quoted strings masked. */
function canonicalAssertion(string $line): ?string
{
    if (preg_match('/\b(?:assert|expect|fail|throw)\b/i', $line) !== 1) {
        return null;
    }
    $masked = preg_replace(['/"(?:[^"\\\\]|\\\\.)*"/', "/'(?:[^'\\\\]|\\\\.)*'/"], ['"S"', '"S"'], $line);
    $canonical = preg_replace('/\s+/', ' ', trim((string) $masked));

    return ($canonical === null || $canonical === '') ? null : $canonical;
}

/** @return array<int,string> canonical assertions, in file order */
function assertionSet(string $source): array
{
    $out = [];
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $canonical = canonicalAssertion($line);
        if ($canonical !== null) {
            $out[] = $canonical;
        }
    }

    return $out;
}

/** @return array<int,array{0:string,1:float}> operator and bound for each numeric comparison on the line */
function assertionBounds(string $canonical): array
{
    preg_match_all(
        '/to(?:Be)?(GreaterThanOrEqual|LessThanOrEqual|GreaterThan|LessThan)\(\s*([0-9]+(?:\.[0-9]+)?)\s*\)/',
        $canonical,
        $matches,
        PREG_SET_ORDER,
    );

    return array_map(static fn (array $m): array => [$m[1], (float) $m[2]], $matches);
}

/** The same assertion with its numbers masked, so the same check at a different bound groups together. */
function assertionShape(string $canonical): string
{
    return preg_replace(
        '/to(?:Be)?(GreaterThanOrEqual|LessThanOrEqual|GreaterThan|LessThan)\(\s*[0-9]+(?:\.[0-9]+)?\s*\)/',
        'to$1(#)',
        $canonical,
    ) ?? $canonical;
}

/** Is the new bound weaker than the old one for this operator? */
function boundLoosened(string $operator, float $old, float $new): bool
{
    return match ($operator) {
        'GreaterThan', 'GreaterThanOrEqual' => $new < $old,
        'LessThan', 'LessThanOrEqual' => $new > $old,
        default => false,
    };
}

/**
 * @return array{ok:bool,removed:array<int,string>,loosened:array<int,string>,cosmetic:array<int,string>,counts:array{old:int,new:int}}
 */
function classifyAssertionChange(string $oldSource, string $newSource): array
{
    $old = assertionSet($oldSource);
    $new = assertionSet($newSource);

    $newByShape = [];
    foreach ($new as $assertion) {
        $newByShape[assertionShape($assertion)][] = $assertion;
    }

    $removed = [];
    $loosened = [];
    $cosmetic = [];
    $consumed = [];

    foreach ($old as $assertion) {
        $shape = assertionShape($assertion);
        $index = $consumed[$shape] ?? 0;

        if (!isset($newByShape[$shape][$index])) {
            $removed[] = $assertion;
            continue;
        }

        $candidate = $newByShape[$shape][$index];
        $consumed[$shape] = $index + 1;

        $oldBounds = assertionBounds($assertion);
        $newBounds = assertionBounds($candidate);
        foreach ($oldBounds as $position => [$operator, $value]) {
            if (isset($newBounds[$position]) && boundLoosened($operator, $value, $newBounds[$position][1])) {
                $loosened[] = "{$shape} — {$operator}({$value}) became {$operator}({$newBounds[$position][1]})";
            }
        }

        if ($candidate !== $assertion && $oldBounds === $newBounds) {
            $cosmetic[] = $assertion;
        }
    }

    return [
        'ok' => $removed === [] && $loosened === [],
        'removed' => $removed,
        'loosened' => $loosened,
        'cosmetic' => $cosmetic,
        'counts' => ['old' => count($old), 'new' => count($new)],
    ];
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--self-test') {
    $pass = 0;
    $fail = 0;
    $check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
        $ok ? ++$pass : ++$fail;
        echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
    };

    $base = "        expect(spaceProbe.mean, 'object-free space has dark mean luminance').toBeLessThan(35);\n"
        . "        expect(shot, 'a fired shot creates bright pixels').toBeGreaterThan(8);\n"
        . "        expect(pairwise, 'no two bodies overlap').toBeGreaterThanOrEqual(1.5);\n";

    // 1. Identical.
    $check(classifyAssertionChange($base, $base)['ok'], 'identical sources are accepted');

    // 2. Reindented + reordered + rewrapped message: the real false positive that blocked iteration 3b's retry.
    $moved = "  expect(shot, 'bright pixels above the ship').toBeGreaterThan(8);\n"
        . "  expect(spaceProbe.mean, 'space is dark').toBeLessThan(35);\n"
        . "  expect(pairwise, 'no overlap').toBeGreaterThanOrEqual(1.5);\n"
        . "  expect(newProbe, 'added by this change').toBeGreaterThan(0);\n";
    $movedResult = classifyAssertionChange($base, $moved);
    $check($movedResult['ok'], 'reindent + reorder + rewording + a new assertion is NOT a weakening');
    $check($movedResult['counts'] === ['old' => 3, 'new' => 4], 'counts are reported (3 -> 4)');

    // 3. A genuinely deleted assertion.
    $deleted = "        expect(shot, 'a fired shot creates bright pixels').toBeGreaterThan(8);\n"
        . "        expect(pairwise, 'no two bodies overlap').toBeGreaterThanOrEqual(1.5);\n";
    $deletedResult = classifyAssertionChange($base, $deleted);
    $check(!$deletedResult['ok'], 'a deleted assertion is refused');
    $check(count($deletedResult['removed']) === 1, 'exactly one assertion reported removed');

    // 4. A loosened bound with everything else intact.
    $loosened = str_replace('toBeGreaterThan(8)', 'toBeGreaterThan(2)', $base);
    $loosenedResult = classifyAssertionChange($base, $loosened);
    $check(!$loosenedResult['ok'], 'a loosened numeric bound is refused');
    $check(count($loosenedResult['loosened']) === 1, 'exactly one loosened bound reported');

    // 5. A tightened bound.
    $tightened = str_replace('toBeLessThan(35)', 'toBeLessThan(20)', $base);
    $tightenedResult = classifyAssertionChange($base, $tightened);
    $check($tightenedResult['ok'], 'a tightened bound is accepted');

    echo "  => {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
