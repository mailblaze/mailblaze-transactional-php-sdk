<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/examples'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        // PER Coding Style 3. php-cs-fixer exposes the current 3.x rules, including PER-CS 3.1, as @PER-CS3x0.
        '@PER-CS3x0' => true,
    ])
    ->setFinder($finder);
