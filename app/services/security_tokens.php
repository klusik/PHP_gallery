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

/**
 * Seal a bounded secret with authenticated, purpose/owner-bound encryption.
 * The caller owns key derivation; raw keys must contain exactly 32 bytes.
 *
 * @param string $secret Plaintext secret to encrypt for storage.
 * @param string $key Exactly 32 bytes of caller-owned encryption key material.
 * @param string $context Purpose and owner binding authenticated alongside the ciphertext.
 * @return string Canonical identifier, digest or secret as described above.
 */
function security_secret_seal(string $secret, string $key, string $context): string
{
    if (strlen($key) !== 32 || $context === '' || strlen($context) > 512
        || $secret === '' || strlen($secret) > 4096 || !function_exists('openssl_encrypt')) {
        throw new \RuntimeException('Secret encryption is unavailable.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
    if ($cipher === false || strlen($tag) !== 16) {
        throw new \RuntimeException('Secret encryption failed.');
    }
    return 'gcm1:' . base64_encode($iv . $tag . $cipher);
}

/** Open an authenticated secret, refusing malformed envelopes or another owner/context.
 *
 * @param string $envelope Versioned authenticated ciphertext envelope.
 * @param string $key Exactly 32 bytes of caller-owned encryption key material.
 * @param string $context Purpose and owner binding authenticated alongside the ciphertext.
 * @return ?string Validated plaintext or null when authentication fails.
 */
function security_secret_open(string $envelope, string $key, string $context): ?string
{
    if (strlen($key) !== 32 || $context === '' || strlen($context) > 512
        || strlen($envelope) > 5505 || !str_starts_with($envelope, 'gcm1:')
        || !function_exists('openssl_decrypt')) {
        return null;
    }
    $payload = base64_decode(substr($envelope, 5), true);
    if ($payload === false || strlen($payload) < 29 || strlen($payload) > 4124) {
        return null;
    }
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($payload, 0, 12), substr($payload, 12, 16), $context);
    return $plain === false ? null : $plain;
}