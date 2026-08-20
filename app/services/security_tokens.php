<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/security_tokens.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides small cryptographic primitives for future opaque authority-bearing tokens.
 *
 * Responsibilities:
 *   - Generate high-entropy opaque tokens with PHP native randomness
 *   - Encode tokens safely for URLs without reducing entropy
 *   - Hash authority-bearing token material before database persistence
 *   - Compare presented tokens without timing-sensitive string comparison
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
 *   - Plaintext authority tokens belong only in the caller/browser delivery path.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;

/**
 * Generate a URL-safe opaque token with a bounded amount of cryptographic entropy.
 *
 * @param int $entropyBytes Number of random bytes before encoding.
 * @return string URL-safe token without padding.
 */
function security_opaque_token_generate(int $entropyBytes = 32): string
{
    if ($entropyBytes < 16 || $entropyBytes > 64) {
        throw new InvalidArgumentException('Opaque token entropy must be between 16 and 64 bytes.');
    }

    return rtrim(strtr(base64_encode(random_bytes($entropyBytes)), '+/', '-_'), '=');
}

/**
 * Generate a public selector suitable for selector/verifier persistent-token designs.
 *
 * @param int $entropyBytes Number of selector bytes before hexadecimal encoding.
 * @return string Lowercase hexadecimal selector.
 */
function security_token_selector_generate(int $entropyBytes = 18): string
{
    if ($entropyBytes < 12 || $entropyBytes > 32) {
        throw new InvalidArgumentException('Token selector entropy must be between 12 and 32 bytes.');
    }

    return bin2hex(random_bytes($entropyBytes));
}

/**
 * Hash one authority-bearing opaque token for database persistence or lookup.
 *
 * High-entropy random tokens do not require password-style slow hashing. SHA-256
 * provides a deterministic one-way lookup key while keeping plaintext authority
 * material out of the database.
 *
 * @param string $token Plaintext token presented by its holder.
 * @return string Lowercase SHA-256 digest.
 */
function security_authority_token_hash(string $token): string
{
    if ($token === '') {
        throw new InvalidArgumentException('Authority token must not be empty.');
    }

    return hash('sha256', $token);
}

/**
 * Verify a plaintext authority token against a stored SHA-256 digest.
 *
 * @param string $storedHash Stored lowercase SHA-256 digest.
 * @param string $token Plaintext token presented by its holder.
 * @return bool True only when the token matches the stored digest.
 */
function security_authority_token_verify(string $storedHash, string $token): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1 || $token === '') {
        return false;
    }

    return hash_equals($storedHash, hash('sha256', $token));
}
