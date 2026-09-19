<?php

/**
 * php-cs-fixer configuration.
 *
 * PSR-12 plus a few rules this codebase follows. Run it with `composer cs-fix`;
 * it rewrites files, so read the diff.
 *
 * Once golden fixtures exist, tests/Fixtures/expected must be excluded here:
 * it is generated output compared byte for byte, and reformatting it would
 * break the very thing it pins.
 */

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/build'])
    ->exclude(['Fixtures/expected'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12'                      => true,
        'array_syntax'                => ['syntax' => 'short'],
        'binary_operator_spaces'      => [
            'default'   => 'single_space',
            'operators' => ['=>' => 'align_single_space_minimal', '=' => 'align_single_space_minimal'],
        ],
        'declare_strict_types'        => true,
        'no_unused_imports'           => true,
        'ordered_imports'             => ['sort_algorithm' => 'alpha'],
        'single_quote'                => true,
        'trailing_comma_in_multiline' => true,
        'no_trailing_whitespace'      => true,
        'single_line_empty_body'      => false,
    ])
    ->setFinder($finder);
