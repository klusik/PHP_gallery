<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/autoload.php
 * Module Type: Core Module
 * Purpose: Autoload the project's small runtime kernel without a package manager.
 * Responsibilities: Resolve only one validated Core class name inside the runtime directory.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Gallery\\Core\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
        return;
    }
    $path = __DIR__ . '/' . $name . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
