<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/downloads.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *
 * Last Updated:
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Controllers\cms_not_found;
use function Gallery\Controllers\picture_manager_image_ids_from_post;
use function Gallery\Controllers\picture_manager_require_logged_in_user;
use function Gallery\Controllers\picture_manager_source_gallery_from_post;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\require_admin;
use function Gallery\Core\request_method;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\url_for;
use function Gallery\Core\slugify;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\find_gallery;
use function Gallery\Services\t;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\build_all_zip;
use function Gallery\Services\build_legacy_gallery_zip;
use function Gallery\Services\gallery_download_authorized_source;
use function Gallery\Services\gallery_download_legacy_manifest_is_safe;
use function Gallery\Services\download_capability_issue;
use function Gallery\Services\download_capability_validate;
use function Gallery\Services\download_manifest_profile_begin;
use function Gallery\Services\download_manifest_profile_finish;
use function Gallery\Services\download_manifest_profile_emit_headers;
use function Gallery\Services\download_manifest_cache_invalidate_source_mismatch;
use function Gallery\Services\gallery_zip_failure_reason;
use function Gallery\Services\gallery_download_manifest;
use function Gallery\Services\smart_gallery_download_authorized_source;
use function Gallery\Services\smart_gallery_download_manifest;
use Gallery\Services\GalleryDownloadManifestException;
use Gallery\Services\DownloadCapabilityException;
use Gallery\Services\GalleryZipBuildException;
use Gallery\Services\LegacyDownloadBuildBusyException;
use Gallery\Services\LegacyDownloadBuildCapacityException;
use Gallery\Services\LegacyDownloadBuildException;
use Gallery\Services\SmartGalleryZipBuildException;
use function Gallery\Services\build_legacy_smart_gallery_zip;
use function Gallery\Services\build_selected_images_zip;
use function Gallery\Services\send_download;
use function Gallery\Services\send_legacy_download_artifact;
use function Gallery\Services\smart_gallery_effective_presentation;
use function Gallery\Services\smart_gallery_find_public_by_id;
use function Gallery\Services\smart_gallery_zip_failure_reason;
use function Gallery\Services\request_client_ip;
use function Gallery\Services\telemetry_request_id;
use function Gallery\Services\viewer_security_fingerprint;
use const Gallery\Services\DOWNLOAD_CAPABILITY_RESOURCE_GALLERY;
use const Gallery\Services\DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY;
use const Gallery\Services\DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE;
use const Gallery\Services\DOWNLOAD_CAPABILITY_SCOPE_LEGACY;

/**
 * Public download controller model.
 *
 * This module contains the routes that produce gallery, selected-photo, and site-wide ZIP
 * downloads. It depends on the download service functions and keeps all
 * request handling for archive downloads away from public gallery rendering.
 *
 * Route names, permissions, HTTP responses, and redirect behaviour are kept
 * identical to the previous app/controllers.php implementation.
 */


/**
 * Return a valid UTF-8 byte-bounded diagnostic string.
 *
 * The project does not require mbstring. Trimming at most the incomplete tail
 * keeps bounded attacker-controlled text JSON-safe without adding an extension.
 */
function cms_download_failure_bounded_text(string $value, int $maxLength): string
{
    $maxLength = max(0, $maxLength);
    $bounded = substr($value, 0, $maxLength);
    while ($bounded !== '' && preg_match('//u', $bounded) !== 1) {
        $bounded = substr($bounded, 0, -1);
    }
    return $bounded;
}

/**
 * Return one bounded request-header value for download failure diagnostics.
 *
 * Control characters are flattened so log exports cannot be structurally
 * confused by attacker-controlled header values.
 */
function cms_download_failure_bounded_header(string $serverKey, int $maxLength): string
{
    $value = (string) ($_SERVER[$serverKey] ?? '');
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    return cms_download_failure_bounded_text(trim($value), $maxLength);
}

/**
 * Return a bounded Referer without query, fragment, or user-info components.
 *
 * Download/share tokens may legitimately appear in gallery URLs. Persisting
 * the raw Referer would therefore turn an operational log into a credential
 * sink. Relative same-origin paths are retained only without query data.
 */
function cms_download_failure_safe_referer(): string
{
    $referer = cms_download_failure_bounded_header('HTTP_REFERER', max(1, (int) cms_runtime_limit('download.failure_referer_input_max_length')));
    if ($referer === '') {
        return '';
    }

    $parts = @parse_url($referer);
    if (!is_array($parts)) {
        $pathOnly = explode('?', explode('#', $referer, 2)[0], 2)[0];
        return cms_download_failure_bounded_text($pathOnly, max(1, (int) cms_runtime_limit('download.failure_referer_max_length')));
    }

    $path = (string) ($parts['path'] ?? '');
    if (isset($parts['scheme'], $parts['host'])) {
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $safe = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'] . $port . $path;
    } else {
        $safe = $path;
    }

    return cms_download_failure_bounded_text($safe, max(1, (int) cms_runtime_limit('download.failure_referer_max_length')));
}

/**
 * Return a privacy-safer stable client-address fingerprint for diagnostics.
 *
 * The raw address is never stored. The existing trusted-proxy resolver and
 * viewer-security keyed fingerprint primitive define the privacy boundary.
 */
function cms_download_failure_client_ip_fingerprint(): string
{
    try {
        $clientIp = request_client_ip();
        return $clientIp === '' ? '' : viewer_security_fingerprint('download-failure-ip', $clientIp);
    } catch (Throwable) {
        return '';
    }
}

/**
 * Return a bounded exception message only for application exceptions whose
 * messages are deliberately visitor-safe. Unknown exception text is redacted
 * because PDO/filesystem/runtime messages may contain SQL, paths, or secrets.
 */
function cms_download_failure_exception_message(Throwable $exception): string
{
    if (!($exception instanceof GalleryDownloadManifestException)
        && !($exception instanceof GalleryZipBuildException)
        && !($exception instanceof SmartGalleryZipBuildException)
        && !($exception instanceof LegacyDownloadBuildException)) {
        return '[redacted unclassified exception message]';
    }

    $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $exception->getMessage()) ?? '';
    return cms_download_failure_bounded_text(trim($message), max(1, (int) cms_runtime_limit('download.failure_exception_message_max_length')));
}

/**
 * Build the bounded request context shared by legacy download failure events.
 *
 * @return array<string,int|string> Credential-free diagnostic context.
 */
function cms_download_failure_request_context(string $route, string $failureStage, string $reason, Throwable $exception): array
{
    $requestId = function_exists('Gallery\\Services\\telemetry_request_id') ? telemetry_request_id() : '';
    return [
        'exception_class' => get_class($exception),
        'exception_message' => cms_download_failure_exception_message($exception),
        'request_method' => substr(request_method(), 0, 16),
        'route' => substr($route, 0, 80),
        'request_id' => substr((string) $requestId, 0, 64),
        'download_mode' => 'legacy',
        'failure_stage' => substr($failureStage, 0, 48),
        'reason' => substr($reason, 0, 64),
        'user_agent' => cms_download_failure_bounded_header('HTTP_USER_AGENT', max(1, (int) cms_runtime_limit('download.failure_user_agent_max_length'))),
        'referer' => cms_download_failure_safe_referer(),
        'client_ip_fingerprint' => cms_download_failure_client_ip_fingerprint(),
    ];
}

/**
 * Return a controlled retryable response when legacy ZIP build capacity is busy.
 *
 * @param LegacyDownloadBuildBusyException $exception Admission refusal with bounded retry delay.
 */
function cms_download_legacy_busy_response(LegacyDownloadBuildBusyException $exception): void
{
    http_response_code(503);
    header('Retry-After: ' . $exception->retryAfterSeconds());
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $exception->getMessage();
}

/**
 * Return a controlled capacity response when the managed legacy artifact cache
 * cannot safely reserve configured storage or filesystem free-space headroom.
 */
function cms_download_legacy_capacity_response(LegacyDownloadBuildCapacityException $exception): void
{
    http_response_code(507);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $exception->getMessage();
}

/**
 * Validate the mandatory capability submitted by the Stage 4 legacy POST form.
 *
 * Legacy ZIP construction no longer accepts query-string bearer material. The
 * capability is read only from the POST body, is length-bounded before parsing,
 * and must be scoped to the exact resource and legacy action.
 *
 * @param string $resourceType Capability resource type.
 * @param int $resourceId Exact gallery or Smart Gallery identifier.
 * @return string Validated legacy capability token.
 */
function cms_download_required_legacy_capability(string $resourceType, int $resourceId): string
{
    cms_download_progressive_capability_headers();

    $rawToken = $_POST['capability'] ?? '';
    $token = is_scalar($rawToken) ? trim((string) $rawToken) : '';
    $maxLength = max(128, (int) cms_runtime_limit('download.capability_max_token_length'));

    try {
        if ($token === '' || strlen($token) > $maxLength) {
            throw new DownloadCapabilityException('malformed');
        }
        download_capability_validate($token, $resourceType, $resourceId, DOWNLOAD_CAPABILITY_SCOPE_LEGACY);
    } catch (DownloadCapabilityException) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid or expired download capability.';
        exit;
    }

    return $token;
}

/** Emit headers used by all capability-bearing progressive download responses. */
function cms_download_progressive_capability_headers(): void
{
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}


/**
 * Parse one strictly bounded positive decimal request identifier.
 *
 * Arrays, signs, whitespace, decimal notation, and integer overflow are rejected
 * before any database/resource work. Leading zeroes remain accepted for historic
 * query-string compatibility.
 *
 * @param array<string,mixed> $source Request parameter source.
 * @param string $key Parameter key containing the candidate identifier.
 * @return ?int Positive identifier, or null for malformed input.
 */
function cms_download_positive_request_id(array $source, string $key): ?int
{
    $raw = $source[$key] ?? null;
    if (!is_scalar($raw)) {
        return null;
    }
    $text = (string) $raw;
    if ($text === '' || strlen($text) > strlen((string) PHP_INT_MAX) || preg_match('/^[0-9]+$/D', $text) !== 1) {
        return null;
    }
    $normalized = ltrim($text, '0');
    if ($normalized === '') {
        return null;
    }
    $max = (string) PHP_INT_MAX;
    if (strlen($normalized) > strlen($max)
        || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
        return null;
    }
    $value = (int) $normalized;
    return $value > 0 ? $value : null;
}

/**
 * Return a cheap bounded 400 response for malformed download parameters.
 *
 * @param bool $jsonResponse Whether to emit the stable JSON error envelope.
 */
function cms_download_bad_request(bool $jsonResponse = false): void
{
    cms_download_progressive_capability_headers();
    http_response_code(400);
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid download request.',
            'error_code' => 'download_request_invalid',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid download request.';
}

/**
 * Refuse methods that cannot participate in progressive manifest/source reads.
 *
 * HEAD is intentionally rejected rather than allowed to initialize expensive
 * manifest/source resolution without transferring the requested payload.
 *
 * @param bool $jsonResponse Whether to emit the stable JSON error envelope.
 * @return bool True only for an allowed GET request.
 */
function cms_download_progressive_require_get(bool $jsonResponse = false): bool
{
    if (request_method() === 'GET') {
        return true;
    }
    cms_download_progressive_capability_headers();
    http_response_code(405);
    header('Allow: GET');
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Download request requires GET.',
            'error_code' => 'download_get_required',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Download request requires GET.';
    }
    return false;
}

/**
 * Validate the optional immutable source-version parameter before source lookup.
 *
 * @param array<string,mixed> $source Request parameter source.
 * @return bool True when the version is absent or a valid 16-character lowercase hex value.
 */
function cms_download_source_version_is_valid(array $source): bool
{
    $raw = $source['v'] ?? '';
    if (!is_scalar($raw)) {
        return false;
    }
    $version = trim((string) $raw);
    return $version === '' || preg_match('/^[a-f0-9]{16}$/D', $version) === 1;
}

/**
 * Parse the optional manifest revision/size snapshot carried by generated source URLs.
 *
 * Historic source URLs may omit both fields. If either new field is present, both
 * fields and the immutable source version must be strictly valid before any DB work.
 *
 * @param array<string,mixed> $source Request parameter source.
 * @return array{present:bool,valid:bool,revision:string,size:int} Parsed snapshot state.
 */
function cms_download_source_snapshot_parameters(array $source): array
{
    $hasRevision = array_key_exists('mr', $source);
    $hasSize = array_key_exists('s', $source);
    if (!$hasRevision && !$hasSize) {
        return ['present' => false, 'valid' => true, 'revision' => '', 'size' => 0];
    }
    if (!$hasRevision || !$hasSize || !is_scalar($source['mr']) || !is_scalar($source['s'])) {
        return ['present' => true, 'valid' => false, 'revision' => '', 'size' => 0];
    }

    $revision = (string) $source['mr'];
    $sizeText = (string) $source['s'];
    $version = is_scalar($source['v'] ?? null) ? trim((string) $source['v']) : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1
        || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1
        || $sizeText === ''
        || strlen($sizeText) > strlen((string) PHP_INT_MAX)
        || preg_match('/^[0-9]+$/D', $sizeText) !== 1) {
        return ['present' => true, 'valid' => false, 'revision' => '', 'size' => 0];
    }

    $normalized = ltrim($sizeText, '0');
    if ($normalized === '') {
        $normalized = '0';
    }
    $max = (string) PHP_INT_MAX;
    if (strlen($normalized) > strlen($max)
        || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
        return ['present' => true, 'valid' => false, 'revision' => '', 'size' => 0];
    }

    return [
        'present' => true,
        'valid' => true,
        'revision' => $revision,
        'size' => (int) $normalized,
    ];
}

/** Return a controlled source-changed response without starting payload transfer. */
function cms_download_source_changed_response(): void
{
    http_response_code(409);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.');
}

/**
 * Validate the mandatory Stage 3 progressive-download capability.
 *
 * The normal browser client sends the token in a same-origin custom header so
 * bearer material does not become part of the requested URL or ordinary access
 * logs. Query transport remains accepted as a bounded transition path for Stage
 * 2 callers and manual compatibility testing. If both transports are supplied,
 * they must contain the exact same token.
 *
 * @return array{token:string,transport:string} Validated token and selected transport.
 */
function cms_download_required_capability(string $resourceType, int $resourceId, string $scope, bool $jsonResponse = false): array
{
    cms_download_progressive_capability_headers();

    $headerValue = $_SERVER['HTTP_X_PHP_GALLERY_DOWNLOAD_CAPABILITY'] ?? '';
    $queryValue = $_GET['capability'] ?? '';
    $headerToken = is_scalar($headerValue) ? trim((string) $headerValue) : '';
    $queryToken = is_scalar($queryValue) ? trim((string) $queryValue) : '';
    $maxLength = max(128, (int) cms_runtime_limit('download.capability_max_token_length'));

    $invalidTransport = ($headerToken !== '' && strlen($headerToken) > $maxLength)
        || ($queryToken !== '' && strlen($queryToken) > $maxLength)
        || ($headerToken !== '' && $queryToken !== '' && !hash_equals($headerToken, $queryToken));
    $token = $headerToken !== '' ? $headerToken : $queryToken;

    try {
        if ($invalidTransport || $token === '') {
            throw new DownloadCapabilityException('malformed');
        }
        download_capability_validate($token, $resourceType, $resourceId, $scope);
    } catch (DownloadCapabilityException) {
        http_response_code(403);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Invalid or expired download capability.',
                'error_code' => 'download_capability_invalid',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid or expired download capability.';
        }
        exit;
    }

    return [
        'token' => $token,
        'transport' => $headerToken !== '' ? 'header' : 'query',
    ];
}

/**
 * Return the Stage 3 progressive initialization response for one authorized resource.
 *
 * Stage 4 deliberately stops minting legacy capabilities through this JSON
 * handshake. Legacy fallback authority is embedded only in explicit server-
 * rendered POST forms, while this endpoint remains dedicated to browser ZIPs.
 *
 * @param string $resourceType Capability resource type.
 * @param int $resourceId Exact gallery or Smart Gallery identifier.
 * @param string $manifestRoute Progressive manifest route name.
 */
function cms_download_start_response(string $resourceType, int $resourceId, string $manifestRoute): void
{
    $issuedAt = time();
    $progressive = download_capability_issue($resourceType, $resourceId, DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE, $issuedAt);
    $ttl = max(60, (int) cms_runtime_limit('download.capability_ttl_seconds'));
    $idKey = $resourceType === DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY ? 'smart_gallery_id' : 'gallery_id';

    cms_download_progressive_capability_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'capability' => $progressive,
        'issued_at' => $issuedAt,
        'expires_at' => $issuedAt + $ttl,
        'manifest_url' => url_for($manifestRoute, ['id' => $resourceId]),
        'capability_transport' => [
            'type' => 'header',
            'header' => 'X-PHP-Gallery-Download-Capability',
        ],
        'source_scope' => [
            'parameter' => $idKey,
            'capability' => $progressive,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Refuse non-POST requests to the cheap progressive capability initializer.
 *
 * @return bool True when execution may continue with a POST request.
 */
function cms_download_start_require_post(): bool
{
    if (request_method() === 'POST') {
        return true;
    }

    cms_download_progressive_capability_headers();
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Download initialization requires POST.',
        'error_code' => 'download_start_post_required',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return false;
}

/**
 * Render the cheap compatibility page reached by a historic legacy GET URL.
 *
 * The page issues only a short-lived legacy capability and an explicit POST
 * form. It never traverses a download manifest or invokes ZIP construction.
 *
 * @param string $resourceType Capability resource type.
 * @param int $resourceId Exact gallery or Smart Gallery identifier.
 * @param string $legacyRoute Legacy POST route name.
 * @param string $label Localized action label.
 */
function cms_download_render_legacy_confirmation(string $resourceType, int $resourceId, string $legacyRoute, string $label): void
{
    $capability = download_capability_issue($resourceType, $resourceId, DOWNLOAD_CAPABILITY_SCOPE_LEGACY);

    cms_download_progressive_capability_headers();
    render_header($label);
    echo '<section class="hero"><div><h1>' . e($label) . '</h1></div><div class="hero-actions">';
    echo '<form method="post" action="' . e(url_for($legacyRoute)) . '">';
    echo '<input type="hidden" name="id" value="' . $resourceId . '">';
    echo '<input type="hidden" name="capability" value="' . e($capability) . '">';
    echo '<button type="submit" class="button">' . e($label) . '</button>';
    echo '</form>';
    echo '</div></section>';
    render_footer();
}

/**
 * Issue capabilities for one currently authorized physical gallery.
 *
 * This endpoint performs no manifest traversal, source hashing, or ZIP work.
 */
function cms_download_gallery_start(): void
{
    if (!cms_download_start_require_post()) {
        return;
    }

    $galleryId = cms_download_positive_request_id($_GET, 'id');
    if ($galleryId === null) {
        cms_download_bad_request(true);
        return;
    }
    $gallery = find_gallery($galleryId);
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    cms_download_start_response(DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, $galleryId, 'download_gallery_manifest');
}

/**
 * Issue capabilities for one public downloadable Smart Gallery.
 *
 * The existing Smart Gallery visibility/presentation policy remains authoritative.
 */
function cms_download_smart_gallery_start(): void
{
    if (!cms_download_start_require_post()) {
        return;
    }

    $galleryId = cms_download_positive_request_id($_GET, 'id');
    if ($galleryId === null) {
        cms_download_bad_request(true);
        return;
    }
    $gallery = smart_gallery_find_public_by_id($galleryId);
    if (!$gallery || empty(smart_gallery_effective_presentation($gallery)['download_enabled'])) {
        cms_not_found();
        return;
    }
    cms_download_start_response(DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY, $galleryId, 'download_smart_gallery_manifest');
}

/**
 * Download a public ZIP for one gallery.
 */
function cms_download_gallery(): void
{
    $method = request_method();
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        cms_download_progressive_capability_headers();
        http_response_code(405);
        header('Allow: GET, HEAD, POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method not allowed.';
        return;
    }

    $galleryId = cms_download_positive_request_id($method === 'POST' ? $_POST : $_GET, 'id');
    if ($galleryId === null) {
        cms_download_bad_request();
        return;
    }
    if ($method === 'POST') {
        cms_download_required_legacy_capability(DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, $galleryId);
    }

    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }

    if ($method !== 'POST') {
        cms_download_render_legacy_confirmation(
            DOWNLOAD_CAPABILITY_RESOURCE_GALLERY,
            $galleryId,
            'download_gallery',
            t('gallery.download', 'Download gallery')
        );
        return;
    }

    $failureStage = 'manifest';
    try {
        // Direct/no-JavaScript requests retain a deliberately bounded legacy path.
        $manifest = gallery_download_manifest($gallery);
        if (!gallery_download_legacy_manifest_is_safe($manifest)) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.');
            return;
        }
        $failureStage = 'archive_build';
        $zip = build_legacy_gallery_zip((int) $gallery['id'], $manifest);
        $failureStage = 'archive_send';
        send_legacy_download_artifact($zip, slugify((string) $gallery['title']) . '.zip');
    } catch (GalleryDownloadManifestException $exception) {
        $context = ['gallery_id' => (int) $gallery['id']]
            + cms_download_failure_request_context('download_gallery', 'manifest', $exception->reason(), $exception);
        admin_log_event('warning', 'gallery.download_legacy_failed', 'Legacy gallery ZIP preparation was refused during manifest preparation.', $context, [
            'category' => 'security',
            'severity' => 'warning',
            'request_id' => (string) $context['request_id'],
            'route_name' => 'download_gallery',
            'subject_type' => 'gallery',
            'subject_id' => (int) $gallery['id'],
        ]);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo $exception->getMessage();
    } catch (LegacyDownloadBuildBusyException $exception) {
        cms_download_legacy_busy_response($exception);
    } catch (LegacyDownloadBuildCapacityException $exception) {
        cms_download_legacy_capacity_response($exception);
    } catch (Throwable $exception) {
        $reason = $failureStage === 'manifest'
            ? 'manifest_unexpected_failure'
            : ($failureStage === 'archive_send' ? 'archive_send_failed' : gallery_zip_failure_reason($exception));
        $context = ['gallery_id' => (int) $gallery['id']]
            + cms_download_failure_request_context('download_gallery', $failureStage, $reason, $exception);
        admin_log_event('error', 'gallery.download_legacy_failed', 'Legacy gallery ZIP preparation failed.', $context, [
            'category' => 'security',
            'severity' => 'error',
            'request_id' => (string) $context['request_id'],
            'route_name' => 'download_gallery',
            'subject_type' => 'gallery',
            'subject_id' => (int) $gallery['id'],
        ]);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.');
    }
}

/**
 * Return browser-safe metadata for a progressive gallery download.
 */
function cms_download_gallery_manifest(): void
{
    if (!cms_download_progressive_require_get(true)) {
        return;
    }
    $galleryId = cms_download_positive_request_id($_GET, 'id');
    if ($galleryId === null) {
        cms_download_bad_request(true);
        return;
    }
    $capability = cms_download_required_capability(DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, $galleryId, DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE, true);
    $gallery = find_gallery($galleryId);
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    download_manifest_profile_begin(DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, $galleryId);
    try {
        $sourceCapability = $capability['transport'] === 'query' ? $capability['token'] : null;
        $payload = gallery_download_manifest($gallery, $sourceCapability);
    } catch (GalleryDownloadManifestException $exception) {
        http_response_code(422);
        $payload = ['ok' => false, 'error' => $exception->getMessage()];
    } finally {
        download_manifest_profile_finish();
        download_manifest_profile_emit_headers();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Stream one independently authorized original source file for browser ZIP assembly.
 */
function cms_download_gallery_file(): void
{
    if (!cms_download_progressive_require_get()) {
        return;
    }
    $galleryId = cms_download_positive_request_id($_GET, 'gallery_id');
    $imageId = cms_download_positive_request_id($_GET, 'image_id');
    $snapshot = cms_download_source_snapshot_parameters($_GET);
    if ($galleryId === null || $imageId === null || !cms_download_source_version_is_valid($_GET) || !$snapshot['valid']) {
        cms_download_bad_request();
        return;
    }
    cms_download_required_capability(DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, $galleryId, DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE);
    $resolved = gallery_download_authorized_source($galleryId, $imageId);
    if ($resolved === null) {
        cms_not_found();
        return;
    }

    cms_stream_progressive_download_source($resolved, DOWNLOAD_CAPABILITY_RESOURCE_GALLERY, $galleryId, $imageId, $snapshot);
}

/**
 * Stream one already-authorized original for a progressive browser ZIP.
 *
 * @param array{path:string,filename:string,size:int,version:string} $resolved Authorized source descriptor.
 * @param string $resourceType Progressive capability/cache resource type.
 * @param int $resourceId Authorized gallery or Smart Gallery identifier.
 * @param int $imageId Authorized source image identifier.
 * @param array{present:bool,valid:bool,revision:string,size:int} $snapshot Optional manifest snapshot parsed before authorization.
 */
function cms_stream_progressive_download_source(array $resolved, string $resourceType, int $resourceId, int $imageId, array $snapshot): void
{
    $requestedVersion = trim((string) ($_GET['v'] ?? ''));
    if ($requestedVersion !== '' && !hash_equals((string) $resolved['version'], $requestedVersion)) {
        cms_download_source_changed_response();
        return;
    }

    if ($snapshot['present'] && (int) $resolved['size'] !== (int) $snapshot['size']) {
        download_manifest_cache_invalidate_source_mismatch(
            $resourceType,
            $resourceId,
            (string) $snapshot['revision'],
            $imageId,
            $requestedVersion,
            (int) $snapshot['size'],
            (int) $resolved['size']
        );
        cms_download_source_changed_response();
        return;
    }

    if (function_exists(__NAMESPACE__ . '\cms_release_public_media_session_lock')) {
        cms_release_public_media_session_lock();
    }
    $safeName = preg_replace('/[\x00-\x1F\x7F]/u', '_', (string) $resolved['filename']) ?? 'photo';
    $safeName = str_replace(['"', '\\'], '_', $safeName);
    if ($safeName === '') {
        $safeName = 'photo';
    }
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-transform');
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode((string) $resolved['filename']));
    header('Content-Length: ' . (int) $resolved['size']);
    readfile((string) $resolved['path']);
}


/**
 * Download the current visitor-authorized Smart Gallery result set as a ZIP.
 */
function cms_download_smart_gallery(): void
{
    $method = request_method();
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        cms_download_progressive_capability_headers();
        http_response_code(405);
        header('Allow: GET, HEAD, POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method not allowed.';
        return;
    }

    $galleryId = cms_download_positive_request_id($method === 'POST' ? $_POST : $_GET, 'id');
    if ($galleryId === null) {
        cms_download_bad_request();
        return;
    }
    if ($method === 'POST') {
        cms_download_required_legacy_capability(DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY, $galleryId);
    }

    $gallery = smart_gallery_find_public_by_id($galleryId);
    if (!$gallery || empty(smart_gallery_effective_presentation($gallery)['download_enabled'])) {
        cms_not_found();
        return;
    }
    if ($method !== 'POST') {
        cms_download_render_legacy_confirmation(
            DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY,
            $galleryId,
            'download_smart_gallery',
            t('smart_gallery.download', 'Download Smart Gallery')
        );
        return;
    }

    $failureStage = 'manifest';
    try {
        // Direct/no-JavaScript requests retain only the same bounded legacy ZIP path
        // as physical galleries. Normal Smart Gallery clicks use the browser manifest.
        $manifest = smart_gallery_download_manifest($gallery);
        if (!gallery_download_legacy_manifest_is_safe($manifest)) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.');
            return;
        }
        $failureStage = 'archive_build';
        $zip = build_legacy_smart_gallery_zip($gallery, $manifest);
        $failureStage = 'archive_send';
        send_legacy_download_artifact($zip, slugify((string) $gallery['title']) . '.zip');
    } catch (GalleryDownloadManifestException $exception) {
        $context = ['smart_gallery_id' => (int) $gallery['id']]
            + cms_download_failure_request_context('download_smart_gallery', 'manifest', $exception->reason(), $exception);
        admin_log_event('warning', 'smart_gallery.download_failed', 'Legacy Smart Gallery ZIP preparation was refused during manifest preparation.', $context, [
            'category' => 'security',
            'severity' => 'warning',
            'request_id' => (string) $context['request_id'],
            'route_name' => 'download_smart_gallery',
            'subject_type' => 'smart_gallery',
            'subject_id' => (int) $gallery['id'],
        ]);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.');
    } catch (LegacyDownloadBuildBusyException $exception) {
        cms_download_legacy_busy_response($exception);
    } catch (LegacyDownloadBuildCapacityException $exception) {
        cms_download_legacy_capacity_response($exception);
    } catch (Throwable $exception) {
        $reason = $failureStage === 'manifest'
            ? 'manifest_unexpected_failure'
            : ($failureStage === 'archive_send' ? 'archive_send_failed' : smart_gallery_zip_failure_reason($exception));
        $context = ['smart_gallery_id' => (int) $gallery['id']]
            + cms_download_failure_request_context('download_smart_gallery', $failureStage, $reason, $exception);
        admin_log_event('error', 'smart_gallery.download_failed', 'Smart Gallery ZIP preparation failed.', $context, [
            'category' => 'security',
            'severity' => 'error',
            'request_id' => (string) $context['request_id'],
            'route_name' => 'download_smart_gallery',
            'subject_type' => 'smart_gallery',
            'subject_id' => (int) $gallery['id'],
        ]);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.');
    }
}

/**
 * Return browser-safe metadata for a progressive Smart Gallery download.
 */
function cms_download_smart_gallery_manifest(): void
{
    if (!cms_download_progressive_require_get(true)) {
        return;
    }
    $galleryId = cms_download_positive_request_id($_GET, 'id');
    if ($galleryId === null) {
        cms_download_bad_request(true);
        return;
    }
    $capability = cms_download_required_capability(DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY, $galleryId, DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE, true);
    $gallery = smart_gallery_find_public_by_id($galleryId);
    if (!$gallery || empty(smart_gallery_effective_presentation($gallery)['download_enabled'])) {
        cms_not_found();
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    download_manifest_profile_begin(DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY, $galleryId);
    try {
        $sourceCapability = $capability['transport'] === 'query' ? $capability['token'] : null;
        $payload = smart_gallery_download_manifest($gallery, $sourceCapability);
    } catch (GalleryDownloadManifestException $exception) {
        http_response_code(422);
        $payload = ['ok' => false, 'error' => $exception->getMessage()];
    } finally {
        download_manifest_profile_finish();
        download_manifest_profile_emit_headers();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Stream one independently authorized Smart Gallery source for browser ZIP assembly.
 */
function cms_download_smart_gallery_file(): void
{
    if (!cms_download_progressive_require_get()) {
        return;
    }
    $smartGalleryId = cms_download_positive_request_id($_GET, 'smart_gallery_id');
    $imageId = cms_download_positive_request_id($_GET, 'image_id');
    $snapshot = cms_download_source_snapshot_parameters($_GET);
    if ($smartGalleryId === null || $imageId === null || !cms_download_source_version_is_valid($_GET) || !$snapshot['valid']) {
        cms_download_bad_request();
        return;
    }
    cms_download_required_capability(DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY, $smartGalleryId, DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE);
    $resolved = smart_gallery_download_authorized_source($smartGalleryId, $imageId);
    if ($resolved === null) {
        cms_not_found();
        return;
    }

    cms_stream_progressive_download_source($resolved, DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY, $smartGalleryId, $imageId, $snapshot);
}


/**
 * Download a ZIP containing only Picture manager selected photos.
 */
function cms_picture_manager_download_selection(): void
{
    picture_manager_require_logged_in_user();
    verify_csrf();

    try {
        // $sourceGallery stores the gallery currently shown in the public manager.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $imageIds stores selected photo IDs from the public grid.
        $imageIds = picture_manager_image_ids_from_post();
        // $zip stores the generated transient archive path.
        $zip = build_selected_images_zip((int) $sourceGallery['id'], $imageIds);
        admin_log_event('info', 'picture_manager.selection_zip_downloaded', 'Picture manager prepared a selected-photo share fallback ZIP.', [
            'source_gallery_id' => (int) $sourceGallery['id'],
            'selected_count' => count($imageIds),
        ], ['category' => 'other', 'severity' => 'info']);
        send_download($zip, slugify((string) $sourceGallery['title']) . '-selected-photos.zip');
    } catch (Throwable $exception) {
        admin_log_event('error', 'picture_manager.selection_zip_failed', 'Picture manager selected-photo ZIP failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo t('download.selected_failed', 'Selected-photo download failed: {error}', ['error' => $exception->getMessage()]);
    }
}

/**
 * Download an admin ZIP containing all imported galleries.
 */
function cms_download_all(): void
{
    require_admin();
    // Variable $zip stores this steps working value.
    $zip = build_all_zip();
    send_download($zip, 'all-galleries.zip');
}
