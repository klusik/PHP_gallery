<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/index.php
 * Module Type: Public Entrypoint
 *
 * Purpose:
 *   Routes public web requests into the PHP Gallery application.
 *
 * Responsibilities:
 *   - Initialize the public request pipeline
 *   - Load project bootstrap code
 *   - Keep direct entrypoint logic minimal
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

cms_run();

