<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_attachment_usage_legacy_schema_fixture.php
 * Module Type: Regression Fixture
 * Purpose: Supply a truthful confirmed-legacy schema response to isolated source tests.
 * Responsibilities:
 *   - Load the real schema inspection and presentation policy owners
 *   - Report every optional usage table/column absent through the test adapter
 *   - Preserve production unknown-schema fail-closed behavior
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/schema_inspection.php';
require_once dirname(__DIR__, 2) . '/app/services/presentation_schema_policy.php';

\Gallery\Services\schema_inspection_set_query_executor_for_tests(
    static function (
        string $objectType,
        string $table,
        string $object,
    ): bool {
        return false;
    },
);
