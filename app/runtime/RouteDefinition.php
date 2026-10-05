<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/RouteDefinition.php
 * Module Type: Core Module
 * Purpose: Describe a stable route and its logical loading requirements.
 * Responsibilities: Validate canonical route, handler and logical module identifiers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Immutable route metadata; procedural handlers become callable after module loading. */
final class RouteDefinition
{
    /**
     * Validate a canonical route, handler name and logical module identifiers.
     *
     * @param string $name Existing canonical route identifier.
     * @param string $handler Fully qualified procedural controller function.
     * @param list<string> $modules Ordered logical dependencies, without filesystem paths.
     * @return void Initializes the validated route metadata.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $handler,
        public readonly array $modules
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1
            || preg_match('/^\\\\?Gallery\\\\Controllers\\\\[A-Za-z_][A-Za-z0-9_]*$/D', $handler) !== 1
            || $modules === [] || !array_is_list($modules)) {
            throw new \InvalidArgumentException('Invalid runtime route definition.');
        }
        foreach ($modules as $module) {
            if (!is_string($module) || preg_match('/^[a-z][a-z0-9-]*$/D', $module) !== 1) {
                throw new \InvalidArgumentException('Invalid route module identifier.');
            }
        }
        if (count(array_unique($modules)) !== count($modules)) {
            throw new \InvalidArgumentException('Duplicate route module identifier.');
        }
    }
}
