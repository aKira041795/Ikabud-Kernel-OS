<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer configuration — Ikabud Kernel OS.
 *
 * Scoped to kernel/, src/ and modules/ (the application OS core). Run:
 *   composer lint        (dry-run, used by CI)
 *   composer lint:fix    (apply fixes locally)
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/kernel', __DIR__ . '/src', __DIR__ . '/modules'])
    ->notPath(['vendor', 'node_modules', 'builder-ui']);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'no_unused_imports' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'blank_line_after_opening_tag' => true,
        'single_blank_line_at_eof' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ]);
