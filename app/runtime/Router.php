<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/Router.php
 * Module Type: Core Module
 * Purpose: Resolve immutable request snapshots against the canonical route registry.
 * Responsibilities: Select registered route metadata while preserving not-found fallback behavior.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Owns route selection; clean-URL parsing remains in its existing transport boundary. */
final class Router
{
    /**
     * Bind the application's route inventory.
     *
     * @param RouteRegistry $registry Validated canonical route definitions.
     * @return void Initializes route ownership.
     */
    public function __construct(private readonly RouteRegistry $registry)
    {
    }

    /**
     * Match the normalized requested page before loading its domain implementation.
     *
     * @param Request $request One normalized request snapshot.
     * @return RouteDefinition Selected metadata, including the not-found fallback.
     */
    public function resolve(Request $request): RouteDefinition
    {
        return $this->registry->resolve($request->page);
    }
}
