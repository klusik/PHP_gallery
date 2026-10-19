<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/php_source_contract_test.php
 * Module Type: Regression Test
 * Purpose: Protect formatter-tolerant PHP source assertions from false matches.
 * Responsibilities: Accept layout/trailing commas while rejecting changed
 *   arguments, literals, operators and expression grouping.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/support/php_source.php';

$source = <<<'PHP'
<?php
require_access(
    $gallery,
    ['public' => true,],
);
$limit = ($admin || $owner) && $enabled;
$message = 'keep  two spaces';
PHP;

if (!php_source_contains($source, 'require_access($gallery, [\'public\' => true])')
    || !php_source_contains('check(/* layout */ $gallery,);', 'check($gallery)')
    || !php_source_contains('<div class="card">', 'class="card"')) {
    throw new RuntimeException('PHP formatting and existing raw markup must match.');
}

// These vectors change one behavior-bearing token or literal at a time.
foreach ([
    'require_access($other, [\'public\' => true])',
    'require_access($gallery, [\'public\' => false])',
    'require_access([\'public\' => true], $gallery)',
    'require_access($gallery, [\'public\' => true], $admin)',
    '$limit = $admin || ($owner && $enabled);',
    '$limit = ($admin && $owner) && $enabled;',
    '$message = \'keep two spaces\';',
] as $forbidden) {
    if (php_source_contains($source, $forbidden)) {
        throw new RuntimeException('Changed PHP contract matched: ' . $forbidden);
    }
}

echo "PHP source contract matching tests passed.\n";
