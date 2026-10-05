<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/RouteRegistry.php
 * Module Type: Core Module
 * Purpose: Own validated route definitions and the existing not-found fallback.
 * Responsibilities: Reject duplicate route definitions and resolve canonical names without includes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Owns the route inventory without including controller implementations. */
final class RouteRegistry
{
    private array $routes = [];

    /**
     * Index definitions and reject ambiguous duplicate routes.
     *
     * @param list<RouteDefinition> $definitions Application route definitions.
     * @param RouteDefinition $fallback Existing not-found controller definition.
     * @return void Initializes the registry.
     */
    public function __construct(array $definitions, private readonly RouteDefinition $fallback)
    {
        foreach ($definitions as $definition) {
            if (!$definition instanceof RouteDefinition || isset($this->routes[$definition->name])) {
                throw new \InvalidArgumentException('Invalid or duplicate runtime route.');
            }
            $this->routes[$definition->name] = $definition;
        }
    }

    /**
     * Find one canonical definition without changing unknown-request behavior.
     *
     * @param string $name Requested page identifier.
     * @return ?RouteDefinition The registered definition, or null.
     */
    public function find(string $name): ?RouteDefinition
    {
        return $this->routes[$name] ?? null;
    }

    /**
     * Resolve a page to its definition or the established not-found handler.
     *
     * @param string $name Requested page identifier.
     * @return RouteDefinition Registered route or fallback metadata.
     */
    public function resolve(string $name): RouteDefinition
    {
        return $this->find($name) ?? $this->fallback;
    }

    /**
     * Expose the canonical route inventory for contracts and diagnostics.
     *
     * @return array<string,RouteDefinition> Definitions indexed by page name.
     */
    public function all(): array
    {
        return $this->routes;
    }
}
