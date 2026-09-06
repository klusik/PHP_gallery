<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/exception.php
 * Module Type: Service
 *
 * Purpose:
 *   Declares the structured validation exception raised by browser upload code.
 *
 * Responsibilities:
 *   - Carry a human-readable failure message for the browser client
 *   - Carry structured diagnostic context for Admin logs and JSON errors
 *   - Stay free of database, filesystem, and request access
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
 *   - Loaded by app/services/browser_uploads.php; do not require this file directly.
 *   - Loaded first so every later part file can throw this type.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Runtime exception with structured context for browser upload validation.
 */
class BrowserUploadValidationException extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $details;

    /**
     * Store a validation message and diagnostic context for admin logs.
     *
     * @param string $message Human-readable failure message.
     * @param array<string,mixed> $details Structured diagnostic context.
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /**
     * Return structured diagnostic context for admin logs and JSON errors.
     *
     * @return array<string,mixed> Diagnostic context.
     */
    public function details(): array
    {
        return $this->details;
    }
}
