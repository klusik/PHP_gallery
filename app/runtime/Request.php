<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/Request.php
 * Module Type: Core Module
 * Purpose: Keep one request's normalized route and parameters together.
 * Responsibilities: Adapt existing transport normalization into an immutable route snapshot.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/** Immutable route snapshot produced by the existing request adapter and URL parser. */
final class Request
{
    /**
     * Retain the resolved request without rewriting legacy controller inputs.
     *
     * @param string $page Requested canonical page, including unknown page identifiers.
     * @param array<string,mixed> $parameters Normalized clean-URL parameters.
     * @return void Initializes the request snapshot.
     */
    public function __construct(public readonly string $page, public readonly array $parameters = [])
    {
    }

    /**
     * Convert the established URL parser's result into a request snapshot.
     *
     * @param array{page:string,params:array<string,mixed>} $route Existing parser result.
     * @return Request Immutable request route snapshot.
     */
    public static function fromRoute(array $route): self
    {
        return new self($route['page'], $route['params']);
    }

    /**
     * Supply the established bootstrap transport boundary with normalized inputs.
     *
     * @return array{page:string,params:array<string,mixed>} Legacy-compatible route data.
     */
    public function routeData(): array
    {
        return ['page' => $this->page, 'params' => $this->parameters];
    }
}
