<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/session_context.php
 * Module Type: Core HTTP Adapter
 * Purpose: Provide narrow access to individual PHP session values for application services.
 * Responsibilities: Preserve flat session-key compatibility and expose active-session state without starting a session.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

/**
 * Read one caller-owned value from the current PHP session bag.
 *
 * The adapter preserves historical flat keys and returns null for both an
 * absent value and an explicitly stored null, matching direct `?? null` reads.
 * It remains usable by isolated CLI/service fixtures when PHP has not started
 * a persistent session.
 *
 * @param string $key Flat session key owned by the calling feature.
 * @return array<array-key,mixed>|bool|float|int|object|string|null Stored session-compatible value, or null when absent or explicitly null.
 */
function session_context_get(string $key): mixed
{
    return $_SESSION[$key] ?? null;
}

/**
 * Store one caller-owned value in the current PHP session bag.
 *
 * The operation updates only the named top-level key and does not require an
 * active PHP session, preserving isolated CLI callers that seed `$_SESSION`.
 *
 * @param string $key Flat session key owned by the calling feature.
 * @param array<array-key,mixed>|bool|float|int|object|string|null $value Session-compatible value to store at the owned key.
 * @return void Does not return a value.
 */
function session_context_set(string $key, mixed $value): void
{
    $_SESSION[$key] = $value;
}

/**
 * Remove only the caller-owned top-level session values named by the caller.
 *
 * @param string ...$keys Flat session keys owned by the calling feature.
 * @return void Does not return a value.
 */
function session_context_remove(string ...$keys): void
{
    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

/**
 * Report whether PHP currently has an active session for persistent storage.
 *
 * This check does not start a session or mutate request state.
 *
 * @return bool True when PHP_SESSION_ACTIVE is the current session status.
 */
function session_context_active(): bool
{
    return session_status() === PHP_SESSION_ACTIVE;
}
