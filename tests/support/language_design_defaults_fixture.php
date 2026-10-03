<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/language_design_defaults_fixture.php
 * Module Type: Test Fixture
 * Purpose: Export canonical pure language design defaults without installation bootstrap.
 * Responsibilities: Isolate the actual translation service from rendering fixture stubs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require dirname(__DIR__, 2).'/app/services/translations.php';
echo json_encode(['defaults'=>\Gallery\Services\translation_public_language_selector_design_defaults(), 'bounds'=>\Gallery\Services\translation_public_language_selector_design_numeric_bounds()], JSON_THROW_ON_ERROR);
