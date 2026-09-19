<?php

declare(strict_types=1);

/**
 * Gate classification — the piece that stops an unstable instrument from impersonating a failing product.
 *
 * WHY THIS EXISTS (CD-74 / lesson L4, 2026-09-16). Iteration 3b was recorded as
 * `verified: 0, no_progress: 4` and escalated with condition `irreversibility` — while the tree passed every
 * acceptance gate minutes later, and the one real defect was a probe that raced the projectile it measured
 * (single sample of a shot crossing a 30x130 window). The driver ran the acceptance once and believed it.
 *
 * The rule this file encodes: *before a failed check is allowed to mean "the product is broken", it is re-run
 * once — and a pass-on-retry is its own outcome (`flaky`), never no-progress and never a product failure.*
 * Retrying to hide instability is wrong; retrying to CLASSIFY it is essential.
 *
 * Kept in its own file so the decision is unit-testable without invoking the whole driver.
 */

/**
 * @param array{passed: bool} $first  the acceptance result the driver first observed
 * @param array{passed: bool} $retry  the same acceptance, re-run once
 * @return string 'passed' | 'flaky' | 'failed'
 */
function classifyGateOutcome(array $first, array $retry): string
{
    if (!empty($first['passed'])) {
        return 'passed';
    }

    return !empty($retry['passed']) ? 'flaky' : 'failed';
}
