<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/download_capabilities.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Issues and validates stateless short-lived capabilities for public downloads.
 *
 * Responsibilities:
 *   - Bind one signed token to a resource type, resource ID, scope, issue time, and expiry
 *   - Validate tokens without database rows or filesystem traversal
 *   - Derive a purpose-specific HMAC key from stable application configuration
 *   - Reject malformed and oversized tokens before JSON or resource processing
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
 *   - Capability possession never replaces the existing gallery/source authorization checks.
 *   - Stage 3 requires the progressive scope on manifest/source routes while legacy remains compatible.
 *
 * Last Updated:
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_runtime_limit;

const DOWNLOAD_CAPABILITY_FORMAT_VERSION = 1;
const DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE = 'progressive';
const DOWNLOAD_CAPABILITY_SCOPE_LEGACY = 'legacy';
const DOWNLOAD_CAPABILITY_RESOURCE_GALLERY = 'gallery';
const DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY = 'smart_gallery';

/** Stable capability-validation failure with a machine-readable reason. */
final class DownloadCapabilityException extends RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message = 'Invalid download capability.')
    {
        parent::__construct($message);
        $this->reason = preg_match('/^[a-z_]{1,48}$/D', $reason) === 1 ? $reason : 'invalid_capability';
    }

    /** Return the stable validation reason. */
    public function reason(): string
    {
        return $this->reason;
    }
}

/**
 * Encode binary or text data as unpadded base64url.
 *
 * @param string $value Binary or text value.
 * @return string Unpadded base64url value.
 */
function download_capability_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Decode strict unpadded base64url input or return null.
 *
 * @param string $value Unpadded base64url input.
 * @return string|null Decoded bytes or null for malformed input.
 */
function download_capability_base64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        return null;
    }
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
    return is_string($decoded) ? $decoded : null;
}

/** Return the HMAC key dedicated to public download capabilities. */
function download_capability_signing_key(): string
{
    $config = cms_config();
    $dedicated = trim((string) (($config['download_security']['capability_secret'] ?? '')));
    if ($dedicated !== '') {
        return hash_hmac('sha256', 'php-gallery:download-capability:dedicated:v1', $dedicated, true);
    }

    $root = trim((string) ($config['visitor_vote_secret'] ?? ''));
    if ($root === '') {
        $root = trim((string) ($config['setup_key'] ?? ''));
    }
    if ($root === '') {
        throw new RuntimeException('No stable application secret is available for download capabilities.');
    }

    // Domain separation prevents capability HMAC material from reusing the root secret directly.
    return hash_hmac('sha256', 'php-gallery:download-capability:key:v1', $root, true);
}

/**
 * Validate stable resource/scope identifiers before issuing or verifying a token.
 *
 * @param string $resourceType Stable resource type.
 * @param int $resourceId Positive resource identifier.
 * @param string $scope Stable capability scope.
 */
function download_capability_assert_contract(string $resourceType, int $resourceId, string $scope): void
{
    if (!in_array($resourceType, [DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY], true)) {
        throw new DownloadCapabilityException('wrong_resource_type');
    }
    if ($resourceId <= 0) {
        throw new DownloadCapabilityException('wrong_resource_id');
    }
    if (!in_array($scope, [DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE, DOWNLOAD_CAPABILITY_SCOPE_LEGACY], true)) {
        throw new DownloadCapabilityException('wrong_scope');
    }
}

/**
 * Issue one short-lived stateless capability.
 *
 * The progressive scope intentionally covers both manifest retrieval and the
 * manifest-authorized source-file requests. Source membership is still checked
 * independently by the existing source authorization service.
 *
 * @param string $resourceType Stable resource type.
 * @param int $resourceId Positive resource identifier.
 * @param string $scope Stable capability scope.
 * @param int|null $now Optional deterministic issue timestamp for tests.
 * @return string Signed capability token.
 */
function download_capability_issue(string $resourceType, int $resourceId, string $scope, ?int $now = null): string
{
    download_capability_assert_contract($resourceType, $resourceId, $scope);
    $issuedAt = $now ?? time();
    $ttl = max(60, (int) cms_runtime_limit('download.capability_ttl_seconds'));
    $nonceBytes = max(8, min(32, (int) cms_runtime_limit('download.capability_nonce_bytes')));
    $claims = [
        'v' => DOWNLOAD_CAPABILITY_FORMAT_VERSION,
        'rt' => $resourceType,
        'rid' => $resourceId,
        'sc' => $scope,
        'iat' => $issuedAt,
        'exp' => $issuedAt + $ttl,
        'n' => download_capability_base64url_encode(random_bytes($nonceBytes)),
    ];
    $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $payload = download_capability_base64url_encode($json);
    $prefix = 'v1.' . $payload;
    $signature = download_capability_base64url_encode(hash_hmac('sha256', $prefix, download_capability_signing_key(), true));
    $token = $prefix . '.' . $signature;

    if (strlen($token) > max(128, (int) cms_runtime_limit('download.capability_max_token_length'))) {
        throw new RuntimeException('Generated download capability exceeds configured token length.');
    }
    return $token;
}

/**
 * Validate one capability and return its normalized claims.
 *
 * @param string $token Presented capability token.
 * @param string $resourceType Expected resource type.
 * @param int $resourceId Expected positive resource identifier.
 * @param string $scope Expected capability scope.
 * @param int|null $now Optional deterministic validation timestamp for tests.
 * @return array{v:int,rt:string,rid:int,sc:string,iat:int,exp:int,n:string}
 */
function download_capability_validate(string $token, string $resourceType, int $resourceId, string $scope, ?int $now = null): array
{
    download_capability_assert_contract($resourceType, $resourceId, $scope);
    $maxLength = max(128, (int) cms_runtime_limit('download.capability_max_token_length'));
    if ($token === '' || strlen($token) > $maxLength) {
        throw new DownloadCapabilityException('malformed');
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3 || $parts[0] !== 'v1') {
        throw new DownloadCapabilityException('malformed');
    }
    [$format, $payloadPart, $signaturePart] = $parts;
    $payloadJson = download_capability_base64url_decode($payloadPart);
    $signature = download_capability_base64url_decode($signaturePart);
    if ($payloadJson === null || $signature === null || strlen($signature) !== 32) {
        throw new DownloadCapabilityException('malformed');
    }

    $expected = hash_hmac('sha256', $format . '.' . $payloadPart, download_capability_signing_key(), true);
    if (!hash_equals($expected, $signature)) {
        throw new DownloadCapabilityException('signature');
    }

    try {
        $claims = json_decode($payloadJson, true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new DownloadCapabilityException('malformed');
    }
    if (!is_array($claims)
        || (int) ($claims['v'] ?? 0) !== DOWNLOAD_CAPABILITY_FORMAT_VERSION
        || !is_string($claims['rt'] ?? null)
        || !is_string($claims['sc'] ?? null)
        || !is_string($claims['n'] ?? null)
        || !is_int($claims['rid'] ?? null)
        || !is_int($claims['iat'] ?? null)
        || !is_int($claims['exp'] ?? null)) {
        throw new DownloadCapabilityException('malformed');
    }

    $nonce = download_capability_base64url_decode($claims['n']);
    if ($nonce === null || strlen($nonce) < 8 || strlen($nonce) > 32) {
        throw new DownloadCapabilityException('malformed');
    }

    if ($claims['rt'] !== $resourceType) {
        throw new DownloadCapabilityException('wrong_resource_type');
    }
    if ($claims['rid'] !== $resourceId) {
        throw new DownloadCapabilityException('wrong_resource_id');
    }
    if ($claims['sc'] !== $scope) {
        throw new DownloadCapabilityException('wrong_scope');
    }

    $current = $now ?? time();
    $skew = max(0, min(300, (int) cms_runtime_limit('download.capability_clock_skew_seconds')));
    if ($claims['iat'] > $current + $skew) {
        throw new DownloadCapabilityException('issued_in_future');
    }
    if ($claims['exp'] < $claims['iat'] || $claims['exp'] < $current - $skew) {
        throw new DownloadCapabilityException('expired');
    }

    return [
        'v' => (int) $claims['v'],
        'rt' => (string) $claims['rt'],
        'rid' => (int) $claims['rid'],
        'sc' => (string) $claims['sc'],
        'iat' => (int) $claims['iat'],
        'exp' => (int) $claims['exp'],
        'n' => (string) $claims['n'],
    ];
}
