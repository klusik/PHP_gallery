<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage13_loader_dependency_boundary_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the Stage 13 deterministic loader order and prevents upward MVC
 *   namespace dependencies from returning.
 *
 * Responsibilities:
 *   - Require Models before Services, Views before Controllers, then routing
 *   - Reject Model upward dependencies
 *   - Reject Service dependencies on Views or Controllers
 *   - Reject View dependencies on Models and Controller dependencies on Models
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/scripts/check_mvc_boundaries.php';

$violations = [];
$blockedRules = [
    'models.upward_dependency',
    'services.upward_dependency',
    'views.model_dependency',
    'controllers.model_dependency',
];
foreach (\PhpGallery\MvcBoundary\scan_project($root) as $violation) {
    if (!in_array((string) ($violation['rule'] ?? ''), $blockedRules, true)) {
        continue;
    }
    $violations[] = sprintf(
        '%s:%d %s',
        (string) ($violation['path'] ?? 'unknown'),
        (int) ($violation['line'] ?? 0),
        (string) ($violation['rule'] ?? 'unknown')
    );
}

$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
$servicesPos = strpos($bootstrap, "require __DIR__ . '/services.php'");
$viewsPos = strpos($bootstrap, "require __DIR__ . '/views.php'");
$controllersPos = strpos($bootstrap, "require __DIR__ . '/controllers.php'");
$routingPos = strpos($bootstrap, "require __DIR__ . '/bootstrap/routing.php'");
if ($servicesPos === false || $viewsPos === false || $controllersPos === false || $routingPos === false
    || !($servicesPos < $viewsPos && $viewsPos < $controllersPos && $controllersPos < $routingPos)) {
    $violations[] = 'Bootstrap layer order must be Services -> Views -> Controllers -> routing';
}

$servicesLoader = (string) file_get_contents($root . '/app/services.php');
$modelsPos = strpos($servicesLoader, "require_once __DIR__ . '/models.php'");
$firstServicePos = strpos($servicesLoader, "require_once __DIR__ . '/services/");
if ($modelsPos === false || $firstServicePos === false || $modelsPos > $firstServicePos) {
    $violations[] = 'app/services.php must load app/models.php before service modules';
}

foreach ([
    'app/models.php' => '/models/',
    'app/views.php' => '/views/',
    'app/controllers.php' => '/controllers/',
] as $relativePath => $expectedSegment) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    if (preg_match_all('/require_once\s+__DIR__\s*\.\s*[\'\"]([^\'\"]+)[\'\"]\s*;/', $source, $matches) === false) {
        $violations[] = 'Unable to inspect loader ' . $relativePath;
        continue;
    }
    foreach ($matches[1] ?? [] as $loadedPath) {
        if (!str_contains((string) $loadedPath, $expectedSegment)) {
            $violations[] = $relativePath . ' loads outside its layer: ' . $loadedPath;
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException("Stage 13 loader/dependency violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 13 loader/dependency boundary checks passed.\n");
