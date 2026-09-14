<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/fixtures/runtime_json_exception.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
declare(strict_types=1);

$_GET['page'] = 'gallery_lightbox_data';
require dirname(__DIR__, 2) . '/app/early_runtime.php';

\Gallery\EarlyRuntime\register_emergency_handler();
throw new RuntimeException('JSON fixture secret SQL SELECT * FROM passwords');
