<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/bootstrap_full.php
 * Module Type: Compatibility Entrypoint
 * Purpose: Explicitly load the full procedural application for legacy CLI/test consumers.
 * Responsibilities: Preserve umbrella load order outside the ordinary web request path.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/integrity.php';
require_once __DIR__ . '/controllers.php';
