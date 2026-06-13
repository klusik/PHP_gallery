<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/simbrief_descriptions.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Fetches the latest SimBrief OFP for a pilot identifier and turns the
 *   dispatch data into a safe Markdown gallery-description draft.
 *
 * Responsibilities:
 *   - Resolve either a SimBrief Pilot ID or a SimBrief pilot name
 *   - Fetch the latest OFP through the public SimBrief fetch endpoint
 *   - Extract commonly available route, aircraft, timing, and dispatch fields
 *   - Generate concise Markdown compatible with the existing gallery renderer
 *   - Keep generated output editable and safe for the current description flow
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
 *   2026-05-24
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Views\view_simbrief_description_markdown;

const SIMBRIEF_DESCRIPTION_ENDPOINT = 'https://www.simbrief.com/api/xml.fetcher.php';
const SIMBRIEF_DESCRIPTION_BASE_URL = 'https://www.simbrief.com';
const SIMBRIEF_DESCRIPTION_TIMEOUT_SECONDS = 18;
const SIMBRIEF_DESCRIPTION_PDF_TIMEOUT_SECONDS = 30;

/**
 * Return a translated service message while allowing standalone tests to run.
 *
 * @param string $key Translation key.
 * @param string $fallback English fallback text.
 * @param array $parameters Parameters value.
 * @return string Resolved message.
 */
function simbrief_description_t(string $key, string $fallback, array $parameters = []): string
{
    if (function_exists('Gallery\\Services\\t')) {
        return t($key, $fallback, $parameters);
    }

    foreach ($parameters as $name => $value) {
        $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
    }
    return $fallback;
}

/**
 * Decide which SimBrief identifier should be used for the import request.
 *
 * @param string $pilotId Submitted SimBrief Pilot ID.
 * @param string $pilotName Submitted SimBrief pilot name or Navigraph Alias.
 * @return array{kind: string, value: string, label: string} Normalized identifier metadata.
 */
function simbrief_description_identifier(string $pilotId, string $pilotName): array
{
    $pilotId = simbrief_description_identifier_text($pilotId, 32);
    $pilotName = simbrief_description_identifier_text($pilotName, 80);

    if ($pilotId !== '') {
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $pilotId) !== 1) {
            throw new RuntimeException(simbrief_description_t(
                'admin.simbrief.error_invalid_pilot_id',
                'Enter a valid SimBrief Pilot ID. Use only letters, numbers, underscores, or hyphens.'
            ));
        }
        return ['kind' => 'userid', 'value' => $pilotId, 'label' => 'Pilot ID'];
    }

    if ($pilotName !== '') {
        if (preg_match('/^[\p{L}\p{N} ._\-]{1,80}$/u', $pilotName) !== 1) {
            throw new RuntimeException(simbrief_description_t(
                'admin.simbrief.error_invalid_pilot_name',
                'Enter the SimBrief pilot name exactly as it appears in the SimBrief profile.'
            ));
        }
        return ['kind' => 'username', 'value' => $pilotName, 'label' => 'Pilot name'];
    }

    throw new RuntimeException(simbrief_description_t(
        'admin.simbrief.error_missing_identifier',
        'Enter either a SimBrief Pilot ID or a SimBrief pilot name.'
    ));
}

/**
 * Normalize a short SimBrief identifier string from form input.
 *
 * @param string $value Raw form value.
 * @param int $limit Maximum retained character count.
 * @return string Cleaned identifier text.
 */
function simbrief_description_identifier_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '');
    if ($limit > 0 && function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $limit, 'UTF-8');
    }
    if ($limit > 0) {
        return substr($value, 0, $limit);
    }
    return $value;
}

/**
 * Fetch the latest SimBrief OFP for the chosen identifier.
 *
 * @param array $identifier Identifier value.
 * @return array<string mixed> Decoded OFP payload.
 */
function simbrief_description_fetch_latest_ofp(array $identifier): array
{
    $kind = (string) ($identifier['kind'] ?? '');
    $value = (string) ($identifier['value'] ?? '');
    if (!in_array($kind, ['userid', 'username'], true) || $value === '') {
        throw new RuntimeException(simbrief_description_t(
            'admin.simbrief.error_missing_identifier',
            'Enter either a SimBrief Pilot ID or a SimBrief pilot name.'
        ));
    }

    $url = SIMBRIEF_DESCRIPTION_ENDPOINT . '?' . http_build_query([
        $kind => $value,
        'json' => 'v2',
    ], '', '&', PHP_QUERY_RFC3986);

    try {
        $body = function_exists('Gallery\\Services\\http_fetch_with_headers')
            ? http_fetch_with_headers($url, SIMBRIEF_DESCRIPTION_TIMEOUT_SECONDS, ['Accept: application/json'])
            : simbrief_description_basic_https_fetch($url, SIMBRIEF_DESCRIPTION_TIMEOUT_SECONDS);
    } catch (Throwable $exception) {
        throw new RuntimeException(simbrief_description_t(
            'admin.simbrief.error_fetch_failed',
            'SimBrief could not return a latest OFP for this identifier. Check the Pilot ID or pilot name and make sure the account has a generated flight plan.'
        ), 0, $exception);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(simbrief_description_t(
            'admin.simbrief.error_invalid_response',
            'SimBrief returned an unreadable response. Try again later or use the manual description field.'
        ));
    }

    $errorText = simbrief_description_first_text($decoded, [
        'error',
        'message',
        'fetch.error',
        'fetch.message',
    ]);
    if ($errorText !== '' && simbrief_description_first_text($decoded, ['origin.icao_code', 'destination.icao_code', 'general.route']) === '') {
        throw new RuntimeException(simbrief_description_t(
            'admin.simbrief.error_remote_message',
            'SimBrief did not provide usable flight data: {message}',
            ['message' => $errorText]
        ));
    }

    return $decoded;
}

/**
 * Fetch a trusted HTTPS resource when the shared HTTP helper is unavailable.
 *
 * @param string $url Trusted HTTPS URL.
 * @param int $timeoutSeconds Request timeout.
 * @return string Response body.
 */
function simbrief_description_basic_https_fetch(string $url, int $timeoutSeconds): string
{
    if (!str_starts_with($url, SIMBRIEF_DESCRIPTION_ENDPOINT . '?')) {
        throw new RuntimeException('Unsupported SimBrief URL.');
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize SimBrief HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . (function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : 'dev'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'SimBrief HTTP request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: PHP-Gallery-CMS\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('SimBrief HTTP request failed. Enable curl or allow_url_fopen.');
    }
    return (string) $body;
}

/**
 * Fetch SimBrief data and generate one editable gallery-description draft.
 *
 * @param string $pilotId Submitted SimBrief Pilot ID.
 * @param string $pilotName Submitted SimBrief pilot name.
 * @return array{description: string, details: array<string, string>, identifier_type: string} Description and extracted metadata.
 */
function simbrief_description_generate_for_identifier(string $pilotId, string $pilotName): array
{
    $identifier = simbrief_description_identifier($pilotId, $pilotName);
    $payload = simbrief_description_fetch_latest_ofp($identifier);
    $details = simbrief_description_extract_details($payload);
    $description = function_exists('Gallery\\Views\\view_simbrief_description_markdown')
        ? view_simbrief_description_markdown($details)
        : simbrief_description_build_markdown($details);

    return [
        'description' => $description,
        'details' => $details,
        'identifier_type' => (string) $identifier['label'],
    ];
}

/**
 * Extract the flight details used by the prose generator.
 *
 * @param array $payload Payload value.
 * @return array<string string> Normalized dispatch details.
 */
function simbrief_description_extract_details(array $payload): array
{
    $originCode = simbrief_description_airport_code($payload, 'origin');
    $destinationCode = simbrief_description_airport_code($payload, 'destination');
    if ($originCode === '' || $destinationCode === '') {
        throw new RuntimeException(simbrief_description_t(
            'admin.simbrief.error_missing_route',
            'The latest SimBrief OFP does not contain enough origin and destination data to build a gallery description.'
        ));
    }

    $airline = strtoupper(simbrief_description_first_text($payload, ['general.icao_airline', 'general.airline']));
    $flightNumber = strtoupper(simbrief_description_first_text($payload, ['general.flight_number', 'general.fltnum', 'general.flight']));
    $callsign = strtoupper(simbrief_description_first_text($payload, ['general.callsign', 'general.atc_callsign']));
    $flightLabel = $callsign !== '' ? $callsign : trim($airline . $flightNumber);

    return [
        'origin_code' => $originCode,
        'origin_name' => simbrief_description_airport_name($payload, 'origin'),
        'origin_runway' => simbrief_description_runway(simbrief_description_first_text($payload, ['origin.plan_rwy', 'origin.runway', 'origin.rwy'])),
        'destination_code' => $destinationCode,
        'destination_name' => simbrief_description_airport_name($payload, 'destination'),
        'destination_runway' => simbrief_description_runway(simbrief_description_first_text($payload, ['destination.plan_rwy', 'destination.runway', 'destination.rwy'])),
        'alternate_code' => simbrief_description_alternate_code($payload),
        'flight_label' => $flightLabel,
        'aircraft' => simbrief_description_aircraft_label($payload),
        'route' => simbrief_description_first_text($payload, ['general.route', 'general.route_ifps', 'general.route_navigraph', 'params.route']),
        'cruise' => simbrief_description_altitude(simbrief_description_first_text($payload, ['general.initial_altitude', 'general.cruise_altitude', 'general.altitude'])),
        'ete' => simbrief_description_duration(simbrief_description_first_text($payload, ['times.est_time_enroute', 'times.ete', 'general.ete', 'general.est_time_enroute'])),
        'distance' => simbrief_description_distance(simbrief_description_first_text($payload, ['general.route_distance', 'general.air_distance', 'general.gc_distance'])),
        'fuel' => simbrief_description_weight(simbrief_description_first_text($payload, ['fuel.plan_ramp', 'fuel.plan_takeoff', 'fuel.plan_total', 'weights.fuel_total', 'weights.fuel_plan']), simbrief_description_weight_unit($payload)),
        'passengers' => simbrief_description_passenger_count($payload),
        'airac' => simbrief_description_first_text($payload, ['params.airac', 'general.airac']),
    ];
}


/**
 * Save the raw latest SimBrief OFP JSON and original PDF inside the gallery folder.
 *
 * The saved JSON intentionally keeps the SimBrief payload intact so future
 * features can re-read the same dispatch data without another network request.
 * When the OFP payload exposes a PDF document URL, the original PDF is saved
 * beside it for direct long-term reference.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $payload Payload value.
 * @param array $identifier Identifier value.
 * @param array $details Details value.
 * @param array $routeResult Route result value.
 * @return array{saved: bool, path: string, manifest_path: string, filename: string, pdf_saved: bool, pdf_path: string, pdf_filename: string, pdf_url: string, pdf_error: string, error: string}.
 */
function simbrief_description_save_ofp_for_gallery(array $gallery, array $payload, array $identifier, array $details, array $routeResult = []): array
{
    $folderPath = function_exists('Gallery\\Core\\normalize_relative_path')
        ? normalize_relative_path((string) ($gallery['folder_path'] ?? ''))
        : trim(str_replace('\\', '/', (string) ($gallery['folder_path'] ?? '')), '/');
    if ($folderPath === '' || !function_exists('Gallery\\Services\\gallery_abs_path') || !function_exists('Gallery\\Core\\path_inside')) {
        return ['saved' => false, 'path' => '', 'manifest_path' => '', 'filename' => 'simbrief-ofp.json', 'error' => 'Gallery path helpers are unavailable.'];
    }

    $galleryRoot = gallery_abs_path($folderPath);
    $galleriesRoot = function_exists('Gallery\\Services\\galleries_root') ? galleries_root() : dirname($galleryRoot);
    if (!is_dir($galleryRoot) || !path_inside($galleriesRoot, $galleryRoot)) {
        return ['saved' => false, 'path' => '', 'manifest_path' => '', 'filename' => 'simbrief-ofp.json', 'error' => 'Gallery folder is unavailable.'];
    }

    $ofpPath = $galleryRoot . DIRECTORY_SEPARATOR . 'simbrief-ofp.json';
    $manifestPath = $galleryRoot . DIRECTORY_SEPARATOR . 'simbrief-ofp-manifest.json';
    $pdfPath = $galleryRoot . DIRECTORY_SEPARATOR . 'simbrief-ofp.pdf';
    $pdfUrl = simbrief_description_pdf_url($payload);
    $pdfResult = $pdfUrl !== ''
        ? simbrief_description_save_pdf_for_gallery($pdfUrl, $pdfPath)
        : ['saved' => false, 'path' => '', 'filename' => 'simbrief-ofp.pdf', 'url' => '', 'error' => ''];
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    $ofpJson = json_encode($payload, $jsonFlags);
    if (!is_string($ofpJson)) {
        return ['saved' => false, 'path' => '', 'manifest_path' => '', 'filename' => 'simbrief-ofp.json', 'error' => 'SimBrief payload could not be encoded.'];
    }

    $now = function_exists('Gallery\\Core\\now_sql') ? now_sql() : gmdate('Y-m-d H:i:s');
    $manifest = [
        'format' => 'php_gallery_simbrief_ofp_manifest_v1',
        'saved_at' => $now,
        'identifier_type' => (string) ($identifier['label'] ?? ''),
        'identifier_kind' => (string) ($identifier['kind'] ?? ''),
        'origin' => (string) ($details['origin_code'] ?? ''),
        'destination' => (string) ($details['destination_code'] ?? ''),
        'flight' => (string) ($details['flight_label'] ?? ''),
        'aircraft' => (string) ($details['aircraft'] ?? ''),
        'airac' => (string) ($details['airac'] ?? ''),
        'route_text' => (string) ($routeResult['route_text'] ?? ''),
        'route_point_count' => (int) ($routeResult['point_count'] ?? 0),
        'route_unresolved_count' => (int) ($routeResult['unresolved_count'] ?? 0),
        'ofp_file' => 'simbrief-ofp.json',
        'ofp_pdf_file' => !empty($pdfResult['saved']) ? 'simbrief-ofp.pdf' : '',
        'ofp_pdf_url' => (string) ($pdfResult['url'] ?? ''),
    ];
    $manifestJson = json_encode($manifest, $jsonFlags);
    if (!is_string($manifestJson)) {
        return ['saved' => false, 'path' => '', 'manifest_path' => '', 'filename' => 'simbrief-ofp.json', 'error' => 'SimBrief manifest could not be encoded.'];
    }

    if (file_put_contents($ofpPath, $ofpJson . "\n", LOCK_EX) === false) {
        return ['saved' => false, 'path' => '', 'manifest_path' => '', 'filename' => 'simbrief-ofp.json', 'error' => 'Could not write simbrief-ofp.json.'];
    }
    if (file_put_contents($manifestPath, $manifestJson . "\n", LOCK_EX) === false) {
        return [
            'saved' => true,
            'path' => $ofpPath,
            'manifest_path' => '',
            'filename' => 'simbrief-ofp.json',
            'pdf_saved' => !empty($pdfResult['saved']),
            'pdf_path' => (string) ($pdfResult['path'] ?? ''),
            'pdf_filename' => 'simbrief-ofp.pdf',
            'pdf_url' => (string) ($pdfResult['url'] ?? ''),
            'pdf_error' => (string) ($pdfResult['error'] ?? ''),
            'error' => 'Could not write simbrief-ofp-manifest.json.',
        ];
    }

    return [
        'saved' => true,
        'path' => $ofpPath,
        'manifest_path' => $manifestPath,
        'filename' => 'simbrief-ofp.json',
        'pdf_saved' => !empty($pdfResult['saved']),
        'pdf_path' => (string) ($pdfResult['path'] ?? ''),
        'pdf_filename' => 'simbrief-ofp.pdf',
        'pdf_url' => (string) ($pdfResult['url'] ?? ''),
        'pdf_error' => (string) ($pdfResult['error'] ?? ''),
        'error' => '',
    ];
}


/**
 * Locate the original SimBrief PDF URL from the fetched OFP payload.
 *
 * SimBrief exposes document links in slightly different shapes depending on the
 * JSON version and OFP layout. This helper first checks likely file nodes, then
 * performs a conservative recursive scan for PDF-looking values.
 *
 * @param array $payload Payload value.
 * @return string Absolute HTTPS PDF URL, or an empty string when none is exposed.
 */
function simbrief_description_pdf_url(array $payload): string
{
    $candidates = [];
    foreach ([
        'files.pdf.link',
        'files.pdf.url',
        'files.pdf.href',
        'files.pdf.path',
        'files.pdf.filename',
        'files.pdf.file',
        'files.pdf',
        'files.ofp_pdf',
        'files.ofp_pdf.link',
        'files.ofp_pdf.url',
        'ofp.pdf',
        'ofp_pdf',
        'pdf',
        'pdf_url',
    ] as $path) {
        $value = simbrief_description_node($payload, $path);
        if (is_scalar($value)) {
            $candidates[] = (string) $value;
        }
    }

    $fileRoot = simbrief_description_first_text($payload, [
        'files.directory',
        'files.dir',
        'files.base_url',
        'files.baseurl',
        'files.root',
        'files.folder',
    ]);

    foreach (simbrief_description_collect_pdf_strings($payload) as $candidate) {
        $candidates[] = $candidate;
    }

    foreach ($candidates as $candidate) {
        $url = simbrief_description_normalize_pdf_url($candidate, $fileRoot);
        if ($url !== '') {
            return $url;
        }
    }

    return '';
}

/**
 * Collect possible PDF strings from nested SimBrief payload data.
 *
 * @param mixed $node Current JSON node.
 * @return array<int string> PDF-looking strings.
 */
function simbrief_description_collect_pdf_strings(mixed $node): array
{
    if (is_scalar($node)) {
        $value = trim((string) $node);
        return preg_match('/\.pdf(?:$|[?#])/i', $value) === 1 ? [$value] : [];
    }
    if (!is_array($node)) {
        return [];
    }

    $matches = [];
    foreach ($node as $value) {
        foreach (simbrief_description_collect_pdf_strings($value) as $match) {
            $matches[] = $match;
        }
    }
    return $matches;
}

/**
 * Convert one possible SimBrief PDF reference into a safe absolute URL.
 *
 * @param string $candidate Candidate value.
 * @param string $fileRoot File root value.
 * @return string Text result for the caller.
 */
function simbrief_description_normalize_pdf_url(string $candidate, string $fileRoot = ''): string
{
    $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($candidate === '' || preg_match('/\.pdf(?:$|[?#])/i', $candidate) !== 1) {
        return '';
    }

    if (preg_match('#^https://(?:www\.)?simbrief\.com/#i', $candidate) === 1) {
        return $candidate;
    }

    if (str_starts_with($candidate, '//')) {
        $candidate = 'https:' . $candidate;
        return preg_match('#^https://(?:www\.)?simbrief\.com/#i', $candidate) === 1 ? $candidate : '';
    }

    if (str_starts_with($candidate, '/')) {
        return SIMBRIEF_DESCRIPTION_BASE_URL . $candidate;
    }

    $root = trim($fileRoot);
    if ($root !== '') {
        if (preg_match('#^https://(?:www\.)?simbrief\.com/#i', $root) === 1) {
            return rtrim($root, '/') . '/' . ltrim($candidate, '/');
        }
        if (str_starts_with($root, '/')) {
            return SIMBRIEF_DESCRIPTION_BASE_URL . '/' . trim($root, '/') . '/' . ltrim($candidate, '/');
        }
    }

    if (preg_match('/^[A-Z0-9_-]+_PDF_\d+\.[A-Za-z0-9]+\.pdf$/i', $candidate) === 1) {
        return SIMBRIEF_DESCRIPTION_BASE_URL . '/ofp/flightplans/' . rawurlencode($candidate);
    }

    if (str_starts_with($candidate, 'ofp/flightplans/')) {
        return SIMBRIEF_DESCRIPTION_BASE_URL . '/' . $candidate;
    }

    return '';
}

/**
 * Download and save the original SimBrief PDF when a safe URL is available.
 *
 * @param string $pdfUrl Pdf url URL.
 * @param string $targetPath Target filesystem path.
 * @return array{saved: bool, path: string, filename: string, url: string, error: string}.
 */
function simbrief_description_save_pdf_for_gallery(string $pdfUrl, string $targetPath): array
{
    $safeUrl = simbrief_description_normalize_pdf_url($pdfUrl);
    if ($safeUrl === '') {
        return ['saved' => false, 'path' => '', 'filename' => 'simbrief-ofp.pdf', 'url' => '', 'error' => 'SimBrief PDF URL was not trusted.'];
    }

    try {
        $body = function_exists('Gallery\\Services\\http_fetch_with_headers')
            ? http_fetch_with_headers($safeUrl, SIMBRIEF_DESCRIPTION_PDF_TIMEOUT_SECONDS, ['Accept: application/pdf'])
            : simbrief_description_basic_pdf_fetch($safeUrl, SIMBRIEF_DESCRIPTION_PDF_TIMEOUT_SECONDS);
    } catch (Throwable $exception) {
        return ['saved' => false, 'path' => '', 'filename' => 'simbrief-ofp.pdf', 'url' => $safeUrl, 'error' => $exception->getMessage()];
    }

    if (!str_starts_with(ltrim($body), '%PDF-')) {
        return ['saved' => false, 'path' => '', 'filename' => 'simbrief-ofp.pdf', 'url' => $safeUrl, 'error' => 'SimBrief PDF response was not a PDF document.'];
    }

    if (file_put_contents($targetPath, $body, LOCK_EX) === false) {
        return ['saved' => false, 'path' => '', 'filename' => 'simbrief-ofp.pdf', 'url' => $safeUrl, 'error' => 'Could not write simbrief-ofp.pdf.'];
    }

    return ['saved' => true, 'path' => $targetPath, 'filename' => 'simbrief-ofp.pdf', 'url' => $safeUrl, 'error' => ''];
}

/**
 * Fetch a trusted SimBrief PDF URL when the shared HTTP helper is unavailable.
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function simbrief_description_basic_pdf_fetch(string $url, int $timeoutSeconds): string
{
    if (preg_match('#^https://(?:www\.)?simbrief\.com/#i', $url) !== 1) {
        throw new RuntimeException('Unsupported SimBrief PDF URL.');
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize SimBrief PDF client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . (function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : 'dev'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'SimBrief PDF request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: PHP-Gallery-CMS\r\nAccept: application/pdf\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('SimBrief PDF request failed. Enable curl or allow_url_fopen.');
    }
    return (string) $body;
}

/**
 * Extract a display-ready route from the SimBrief OFP and persist it for maps.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $payload Payload value.
 * @param array $details Details value.
 * @return array<string mixed> Route extraction and save summary.
 */
function simbrief_description_save_route_map_from_ofp(int $galleryId, array $payload, array $details): array
{
    $points = simbrief_description_extract_route_points($payload, $details);
    $routeText = simbrief_description_route_text_from_points($points, $details);
    $unresolved = [];

    if (count($points) < 2) {
        $unresolved[] = [
            'name' => 'SimBrief OFP',
            'reason' => 'not_enough_route_coordinates',
        ];
    }

    $saved = false;
    if (count($points) >= 2 && function_exists('Gallery\\Services\\save_gallery_flight_path_resolved_points')) {
        save_gallery_flight_path_resolved_points($galleryId, $routeText, $points, $unresolved);
        $saved = true;
    }

    return [
        'saved' => $saved,
        'route_text' => $routeText,
        'points' => $points,
        'point_count' => count($points),
        'unresolved' => $unresolved,
        'unresolved_count' => count($unresolved),
    ];
}

/**
 * Extract route geometry from SimBrief airport and navlog coordinates.
 *
 * @param array $payload Payload value.
 * @param array $details Details value.
 * @return array<int array<string, mixed>> Ordered route points.
 */
function simbrief_description_extract_route_points(array $payload, array $details): array
{
    $points = [];
    $origin = simbrief_description_airport_route_point($payload, 'origin', (string) ($details['origin_code'] ?? ''), 'start');
    if ($origin !== null) {
        $points[] = $origin;
    }

    foreach (simbrief_description_navlog_rows($payload) as $row) {
        $point = simbrief_description_navlog_route_point($row);
        if ($point === null) {
            continue;
        }
        if (!simbrief_description_route_point_duplicate($points, $point)) {
            $points[] = $point;
        }
    }

    $destination = simbrief_description_airport_route_point($payload, 'destination', (string) ($details['destination_code'] ?? ''), 'end');
    if ($destination !== null) {
        if (simbrief_description_route_point_duplicate($points, $destination)) {
            $lastIndex = count($points) - 1;
            if ($lastIndex >= 0) {
                $points[$lastIndex]['role'] = 'end';
                $points[$lastIndex]['kind'] = 'airport';
                $points[$lastIndex]['name'] = $destination['name'];
            }
        } else {
            $points[] = $destination;
        }
    }

    if (count($points) > 1) {
        $points[0]['role'] = 'start';
        $points[count($points) - 1]['role'] = 'end';
        foreach ($points as $index => $point) {
            if ($index > 0 && $index < count($points) - 1) {
                $points[$index]['role'] = 'via';
            }
        }
    }

    return array_values($points);
}

/**
 * Build one airport route point from an OFP airport section.
 *
 * @param array $payload Payload value.
 * @param string $section Airport section name.
 * @param string $fallbackCode ICAO or IATA fallback.
 * @param string $role Route role.
 * @return array<string mixed>|null.
 */
function simbrief_description_airport_route_point(array $payload, string $section, string $fallbackCode, string $role): ?array
{
    $node = simbrief_description_node($payload, $section);
    if (!is_array($node)) {
        $node = [];
    }

    $name = simbrief_description_airport_code($payload, $section);
    if ($name === '') {
        $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $fallbackCode) ?? '');
    }
    if ($name === '') {
        $name = strtoupper($section);
    }

    $latitude = simbrief_description_row_coordinate($node, ['lat', 'latitude', 'pos_lat', 'poslat', 'apt_lat', 'airport_lat'], 'lat');
    $longitude = simbrief_description_row_coordinate($node, ['lon', 'lng', 'long', 'longitude', 'pos_long', 'pos_lon', 'poslong', 'apt_lon', 'airport_lon'], 'lon');

    if (($latitude === null || $longitude === null) && function_exists('Gallery\\Services\\flight_route_lookup_nav_point') && $name !== '') {
        $fallback = flight_route_lookup_nav_point($name);
        if (is_array($fallback)) {
            $latitude = isset($fallback['latitude']) ? (float) $fallback['latitude'] : $latitude;
            $longitude = isset($fallback['longitude']) ? (float) $fallback['longitude'] : $longitude;
        }
    }

    return simbrief_description_route_point_from_values($name, $latitude, $longitude, 'airport', $role);
}

/**
 * Build one route point from a SimBrief navlog row.
 *
 * @param array $row Row data.
 * @return array<string mixed>|null.
 */
function simbrief_description_navlog_route_point(array $row): ?array
{
    $name = simbrief_description_row_first_text($row, [
        'ident',
        'fix_ident',
        'fix_id',
        'waypoint',
        'wp_ident',
        'icao_code',
        'icao',
        'name',
        'fix_name',
    ]);
    $name = strtoupper(preg_replace('/[^A-Za-z0-9_.-]/', '', $name) ?? '');
    if ($name === '') {
        $name = 'POINT';
    }

    $latitude = simbrief_description_row_coordinate($row, ['lat', 'latitude', 'pos_lat', 'poslat', 'fix_lat', 'wp_lat'], 'lat');
    $longitude = simbrief_description_row_coordinate($row, ['lon', 'lng', 'long', 'longitude', 'pos_long', 'pos_lon', 'poslong', 'fix_lon', 'fix_long', 'wp_lon'], 'lon');

    if ($latitude === null || $longitude === null) {
        $combined = simbrief_description_row_first_text($row, ['pos', 'position', 'coord', 'coords', 'coordinates', 'latlon', 'lat_lon']);
        $combinedPoint = $combined !== '' && function_exists('Gallery\\Services\\flight_route_parse_aviation_coordinate')
            ? flight_route_parse_aviation_coordinate($combined)
            : null;
        if (is_array($combinedPoint)) {
            $latitude = isset($combinedPoint['latitude']) ? (float) $combinedPoint['latitude'] : $latitude;
            $longitude = isset($combinedPoint['longitude']) ? (float) $combinedPoint['longitude'] : $longitude;
        }
    }

    return simbrief_description_route_point_from_values($name, $latitude, $longitude, 'waypoint', 'via');
}

/**
 * Return likely navlog rows from known SimBrief JSON shapes.
 *
 * @param array $payload Payload value.
 * @return array<int array<string, mixed>> Row list.
 */
function simbrief_description_navlog_rows(array $payload): array
{
    $paths = [
        'navlog.fix',
        'navlog.fixes',
        'navlog.waypoint',
        'navlog.waypoints',
        'navlog',
        'ofp.navlog.fix',
        'route.navlog.fix',
        'route.fixes',
    ];

    foreach ($paths as $path) {
        $node = simbrief_description_node($payload, $path);
        $rows = simbrief_description_rows_from_node($node);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

/**
 * Normalize a possible SimBrief row collection.
 *
 * @param mixed $node Candidate row collection.
 * @return array<int array<string, mixed>> Row list.
 */
function simbrief_description_rows_from_node(mixed $node): array
{
    if (!is_array($node)) {
        return [];
    }

    if (simbrief_description_array_has_coordinate_shape($node)) {
        return [$node];
    }

    $rows = [];
    foreach ($node as $value) {
        if (is_array($value) && simbrief_description_array_has_coordinate_shape($value)) {
            $rows[] = $value;
        }
    }
    return $rows;
}

/**
 * Return true when an array looks like one coordinate-bearing navlog row.
 *
 * @param array $row Row data.
 * @return bool True when the condition matches.
 */
function simbrief_description_array_has_coordinate_shape(array $row): bool
{
    $latKeys = ['lat', 'latitude', 'pos_lat', 'poslat', 'fix_lat', 'wp_lat'];
    $lonKeys = ['lon', 'lng', 'long', 'longitude', 'pos_long', 'pos_lon', 'poslong', 'fix_lon', 'fix_long', 'wp_lon'];
    $lat = simbrief_description_row_coordinate($row, $latKeys, 'lat');
    $lon = simbrief_description_row_coordinate($row, $lonKeys, 'lon');
    if ($lat !== null && $lon !== null) {
        return true;
    }

    if (simbrief_description_row_has_any_key($row, $latKeys) && simbrief_description_row_has_any_key($row, $lonKeys)) {
        return true;
    }

    return simbrief_description_row_first_text($row, ['pos', 'position', 'coord', 'coords', 'coordinates', 'latlon', 'lat_lon']) !== '';
}

/**
 * Return true when a SimBrief row contains at least one named key.
 *
 * @param array $row Row data.
 * @param array $keys Keys value.
 * @return bool True when the condition matches.
 */
function simbrief_description_row_has_any_key(array $row, array $keys): bool
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return true;
        }
    }
    return false;
}

/**
 * Read the first text value from a SimBrief navlog row.
 *
 * @param array $row Row data.
 * @param array $keys Keys value.
 * @return string Text result for the caller.
 */
function simbrief_description_row_first_text(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && is_scalar($row[$key])) {
            $value = simbrief_description_plain_text((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

/**
 * Read one latitude or longitude value from a SimBrief row.
 *
 * @param array $row Row data.
 * @param array $keys Keys value.
 * @param string $axis Coordinate axis, lat or lon.
 * @return ?float Numeric result for the caller.
 */
function simbrief_description_row_coordinate(array $row, array $keys, string $axis): ?float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || !is_scalar($row[$key])) {
            continue;
        }
        $coordinate = simbrief_description_coordinate_value((string) $row[$key], $axis);
        if ($coordinate !== null) {
            return $coordinate;
        }
    }
    return null;
}

/**
 * Normalize decimal, signed, and compact SimBrief coordinate strings.
 *
 * @param string $value Value to process.
 * @param string $axis Axis value.
 * @return ?float Numeric result for the caller.
 */
function simbrief_description_coordinate_value(string $value, string $axis): ?float
{
    $value = strtoupper(trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($value === '') {
        return null;
    }

    $value = str_replace(['°', "'", '"'], ['', '', ''], $value);
    $value = preg_replace('/\s+/', '', $value) ?? $value;

    if (is_numeric($value)) {
        $number = (float) $value;
        if (simbrief_description_coordinate_in_range($number, $axis)) {
            return $number;
        }
        // Out-of-range numeric strings can still be compact degrees/minutes,
        // for example 4326.2 for N43 26.2 or 00512.9 for E005 12.9.
    }

    $pattern = $axis === 'lat'
        ? '/^([NS])?(\d{2})(\d{2}(?:\.\d+)?)([NS])?$/'
        : '/^([EW])?(\d{3})(\d{2}(?:\.\d+)?)([EW])?$/';
    if (preg_match($pattern, $value, $match) === 1) {
        $hemisphere = (string) ($match[1] !== '' ? $match[1] : ($match[4] ?? ''));
        $number = (float) $match[2] + ((float) $match[3] / 60.0);
        if (in_array($hemisphere, ['S', 'W'], true)) {
            $number *= -1;
        }
        return simbrief_description_coordinate_in_range($number, $axis) ? $number : null;
    }

    if (preg_match('/^([NSWE])?(-?\d+(?:\.\d+)?)([NSWE])?$/', $value, $match) === 1) {
        $prefix = (string) ($match[1] ?? '');
        $number = (float) $match[2];
        $suffix = (string) ($match[3] ?? '');
        $hemisphere = $prefix !== '' ? $prefix : $suffix;
        if ($hemisphere !== '') {
            if (in_array($hemisphere, ['S', 'W'], true)) {
                $number = -abs($number);
            } elseif (in_array($hemisphere, ['N', 'E'], true)) {
                $number = abs($number);
            }
            return simbrief_description_coordinate_in_range($number, $axis) ? $number : null;
        }
        if (simbrief_description_coordinate_in_range($number, $axis)) {
            return $number;
        }
    }

    return null;
}

/**
 * Return whether a coordinate is valid for the requested axis.
 *
 * @param float $value Value to process.
 * @param string $axis Axis value.
 * @return bool True when the condition matches.
 */
function simbrief_description_coordinate_in_range(float $value, string $axis): bool
{
    if ($axis === 'lat') {
        return $value >= -90.0 && $value <= 90.0;
    }
    return $value >= -180.0 && $value <= 180.0;
}

/**
 * Build a normalized SimBrief route point.
 *
 * @param string $name Name value.
 * @param ?float $latitude Latitude value.
 * @param ?float $longitude Longitude value.
 * @param string $kind Kind value.
 * @param string $role Role value.
 * @return array<string mixed>|null.
 */
function simbrief_description_route_point_from_values(string $name, ?float $latitude, ?float $longitude, string $kind, string $role): ?array
{
    if ($latitude === null || $longitude === null || !simbrief_description_coordinate_in_range($latitude, 'lat') || !simbrief_description_coordinate_in_range($longitude, 'lon')) {
        return null;
    }

    $name = strtoupper(trim($name));
    if ($name === '') {
        $name = 'POINT';
    }

    return [
        'name' => substr($name, 0, 64),
        'latitude' => round($latitude, 7),
        'longitude' => round($longitude, 7),
        'kind' => $kind !== '' ? $kind : 'waypoint',
        'role' => in_array($role, ['start', 'end', 'via'], true) ? $role : 'via',
        'source' => 'simbrief_ofp',
    ];
}

/**
 * Return true when a candidate route point repeats the last stored point.
 *
 * @param array $points Points value.
 * @param array $candidate Candidate value.
 * @return bool True when the condition matches.
 */
function simbrief_description_route_point_duplicate(array $points, array $candidate): bool
{
    if ($points === []) {
        return false;
    }

    $last = $points[count($points) - 1];
    $latDiff = abs((float) ($last['latitude'] ?? 0.0) - (float) ($candidate['latitude'] ?? 0.0));
    $lonDiff = abs((float) ($last['longitude'] ?? 0.0) - (float) ($candidate['longitude'] ?? 0.0));
    if ($latDiff <= 0.00001 && $lonDiff <= 0.00001) {
        return true;
    }

    return strtoupper((string) ($last['name'] ?? '')) === strtoupper((string) ($candidate['name'] ?? ''));
}

/**
 * Build the human-readable route text shown in the gallery editor.
 *
 * @param array $points Points value.
 * @param array $details Details value.
 * @return string Text result for the caller.
 */
function simbrief_description_route_text_from_points(array $points, array $details): string
{
    $names = [];
    foreach ($points as $point) {
        $name = strtoupper(trim((string) ($point['name'] ?? '')));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));
    if (count($names) >= 2) {
        return implode(' ', $names);
    }

    return simbrief_description_first_text($details, ['route']);
}

/**
 * Build a safe Markdown description from extracted SimBrief fields.
 *
 * @param array $details Details value.
 * @return string Editable Markdown description.
 */
function simbrief_description_build_markdown(array $details): string
{
    if (function_exists('Gallery\\Views\\view_simbrief_description_markdown')) {
        return view_simbrief_description_markdown($details);
    }
    $originCode = simbrief_description_markdown_code($details['origin_code'] ?? '');
    $destinationCode = simbrief_description_markdown_code($details['destination_code'] ?? '');
    $originLabel = simbrief_description_place_label((string) ($details['origin_name'] ?? ''), $originCode);
    $destinationLabel = simbrief_description_place_label((string) ($details['destination_name'] ?? ''), $destinationCode);
    $aircraft = simbrief_description_markdown_text((string) ($details['aircraft'] ?? ''));
    $flightLabel = simbrief_description_markdown_code((string) ($details['flight_label'] ?? ''));

    $openingParts = [];
    $opening = '**' . $originCode . ' to ' . $destinationCode . '** was planned as';
    if ($aircraft !== '') {
        $opening .= ' ' . simbrief_description_indefinite_article($aircraft) . ' ' . $aircraft . ' sector';
    } else {
        $opening .= ' a flight-sim sector';
    }
    $opening .= ' from ' . $originLabel . ' to ' . $destinationLabel . '.';
    $openingParts[] = $opening;

    $dispatchContext = [];
    if ($flightLabel !== '') {
        $dispatchContext[] = 'flight `' . $flightLabel . '`';
    }
    if (($details['cruise'] ?? '') !== '') {
        $dispatchContext[] = 'a planned cruise at `' . simbrief_description_markdown_code((string) $details['cruise']) . '`';
    }
    if (($details['ete'] ?? '') !== '') {
        $dispatchContext[] = 'an estimated en-route time of about ' . simbrief_description_markdown_text((string) $details['ete']);
    }
    if ($dispatchContext !== []) {
        $openingParts[] = 'The OFP gives it ' . simbrief_description_join_phrase($dispatchContext) . '.';
    }

    $paragraphs = [implode(' ', $openingParts)];

    $routeParts = [];
    if (($details['route'] ?? '') !== '') {
        $routeParts[] = 'SimBrief filed the route as `' . simbrief_description_markdown_code(simbrief_description_shorten((string) $details['route'], 300)) . '`.';
    }
    $runwayParts = [];
    if (($details['origin_runway'] ?? '') !== '') {
        $runwayParts[] = 'departure runway ' . simbrief_description_markdown_text((string) $details['origin_runway']);
    }
    if (($details['destination_runway'] ?? '') !== '') {
        $runwayParts[] = 'arrival runway ' . simbrief_description_markdown_text((string) $details['destination_runway']);
    }
    if ($runwayParts !== []) {
        $routeParts[] = 'The plan also notes ' . simbrief_description_join_phrase($runwayParts) . '.';
    }
    if (($details['alternate_code'] ?? '') !== '') {
        $routeParts[] = 'The listed alternate is `' . simbrief_description_markdown_code((string) $details['alternate_code']) . '`.';
    }
    if ($routeParts !== []) {
        $paragraphs[] = implode(' ', $routeParts);
    }

    $dispatchParts = [];
    if (($details['distance'] ?? '') !== '') {
        $dispatchParts[] = 'planned distance ' . simbrief_description_markdown_text((string) $details['distance']);
    }
    if (($details['passengers'] ?? '') !== '') {
        $dispatchParts[] = simbrief_description_markdown_text((string) $details['passengers']) . ' passengers';
    }
    if (($details['fuel'] ?? '') !== '') {
        $dispatchParts[] = 'dispatch fuel ' . simbrief_description_markdown_text((string) $details['fuel']);
    }
    if (($details['airac'] ?? '') !== '') {
        $dispatchParts[] = 'AIRAC ' . simbrief_description_markdown_text((string) $details['airac']);
    }
    if ($dispatchParts !== []) {
        $paragraphs[] = 'For the gallery, this gives the flight a clean dispatch-log frame: ' . simbrief_description_join_phrase($dispatchParts) . '. The photos can sit around that planned story rather than only showing a raw route string.';
    }

    return trim(implode("\n\n", $paragraphs));
}


/**
 * Return a readable indefinite article for an aircraft label.
 *
 * @param string $label Aircraft label prepared for description text.
 * @return string The article to place before the aircraft label.
 */
function simbrief_description_indefinite_article(string $label): string
{
    $trimmed = trim($label);
    if ($trimmed === '') {
        return 'a';
    }

    $upper = strtoupper($trimmed);
    if (preg_match('/^(A|E|F|H|I|L|M|N|O|R|S|X)([^A-Z]|$)/', $upper) === 1) {
        return 'an';
    }

    $first = strtolower(substr($trimmed, 0, 1));
    return in_array($first, ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
}

/**
 * Return one airport ICAO or IATA code from the SimBrief payload.
 *
 * @param array $payload Payload value.
 * @param string $section Airport section name.
 * @return string Uppercase airport code.
 */
function simbrief_description_airport_code(array $payload, string $section): string
{
    $code = simbrief_description_first_text($payload, [
        $section . '.icao_code',
        $section . '.icao',
        $section . '.iata_code',
        $section . '.iata',
        'general.' . ($section === 'origin' ? 'orig' : 'dest'),
    ]);
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

/**
 * Return one airport display name from the SimBrief payload.
 *
 * @param array $payload Payload value.
 * @param string $section Airport section name.
 * @return string Clean airport name or city.
 */
function simbrief_description_airport_name(array $payload, string $section): string
{
    return simbrief_description_first_text($payload, [
        $section . '.name',
        $section . '.airport_name',
        $section . '.city',
    ]);
}

/**
 * Return the first alternate airport code from common SimBrief shapes.
 *
 * @param array $payload Payload value.
 * @return string Uppercase alternate code.
 */
function simbrief_description_alternate_code(array $payload): string
{
    $direct = simbrief_description_first_text($payload, [
        'alternate.icao_code',
        'alternate.icao',
        'alternate.iata_code',
        'general.alternate',
        'general.altn',
    ]);
    if ($direct !== '') {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $direct) ?? '');
    }

    $alternateNode = simbrief_description_node($payload, 'alternate');
    if (is_array($alternateNode)) {
        $first = reset($alternateNode);
        if (is_array($first)) {
            $code = simbrief_description_first_text($first, ['icao_code', 'icao', 'iata_code', 'iata']);
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        }
    }

    return '';
}

/**
 * Return a readable aircraft label.
 *
 * @param array $payload Payload value.
 * @return string Aircraft name and ICAO code when available.
 */
function simbrief_description_aircraft_label(array $payload): string
{
    $name = simbrief_description_first_text($payload, ['aircraft.name', 'aircraft.aircraft_name', 'aircraft.type_name']);
    $icao = strtoupper(simbrief_description_first_text($payload, ['aircraft.icao_code', 'aircraft.icao', 'aircraft.type']));
    $icao = preg_replace('/[^A-Za-z0-9]/', '', $icao) ?? '';

    if ($name !== '' && $icao !== '' && stripos($name, $icao) === false) {
        return $name . ' (' . $icao . ')';
    }
    if ($name !== '') {
        return $name;
    }
    return $icao;
}

/**
 * Read the SimBrief weight unit preference.
 *
 * @param array $payload Payload value.
 * @return string Display unit.
 */
function simbrief_description_weight_unit(array $payload): string
{
    $unit = strtoupper(simbrief_description_first_text($payload, ['params.units', 'params.weight_units', 'general.units']));
    if (str_contains($unit, 'LB')) {
        return 'lb';
    }
    if (str_contains($unit, 'KG') || str_contains($unit, 'KGS')) {
        return 'kg';
    }
    return 'kg';
}

/**
 * Return a clean passenger count.
 *
 * @param array $payload Payload value.
 * @return string Passenger count or an empty string.
 */
function simbrief_description_passenger_count(array $payload): string
{
    $value = simbrief_description_first_text($payload, [
        'weights.pax_count_actual',
        'weights.pax_count',
        'weights.pax',
        'general.passengers',
    ]);
    if ($value === '') {
        return '';
    }
    if (preg_match('/\d+/', $value, $match) === 1) {
        return (string) max(0, (int) $match[0]);
    }
    return '';
}

/**
 * Return a nested value by dot path.
 *
 * @param array $payload Payload value.
 * @param string $path Dot path.
 * @return mixed Matching node or null.
 */
function simbrief_description_node(array $payload, string $path): mixed
{
    $node = $payload;
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

/**
 * Return the first scalar text value from a list of dot paths.
 *
 * @param array $payload Payload value.
 * @param array $paths Paths filesystem path.
 * @return string Clean text value.
 */
function simbrief_description_first_text(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $value = simbrief_description_node($payload, $path);
        if (is_scalar($value)) {
            $text = simbrief_description_plain_text((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return '';
}

/**
 * Normalize one plain-text SimBrief value.
 *
 * @param string $value Raw value.
 * @return string Safe compact text.
 */
function simbrief_description_plain_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

/**
 * Escape text that will be inserted into Markdown prose.
 *
 * @param string $value Raw text.
 * @return string Markdown-safe text.
 */
function simbrief_description_markdown_text(string $value): string
{
    $value = simbrief_description_plain_text($value);
    $value = str_replace(['\\', '`', '*', '_', '[', ']', '<', '>'], ['\\\\', '\`', '\*', '\_', '\[', '\]', '', ''], $value);
    return trim($value);
}

/**
 * Escape text that will be placed inside Markdown inline code.
 *
 * @param string $value Raw text.
 * @return string Safe inline-code content.
 */
function simbrief_description_markdown_code(string $value): string
{
    $value = simbrief_description_plain_text($value);
    $value = str_replace('`', "'", $value);
    return trim($value);
}

/**
 * Build a human-readable place label with code fallback.
 *
 * @param string $name Airport name or city.
 * @param string $code Airport code.
 * @return string Markdown-safe place label.
 */
function simbrief_description_place_label(string $name, string $code): string
{
    $name = simbrief_description_markdown_text($name);
    $code = simbrief_description_markdown_code($code);
    if ($name !== '') {
        return $name . ' (`' . $code . '`)';
    }
    return '`' . $code . '`';
}

/**
 * Shorten long route text without breaking the generated description layout.
 *
 * @param string $value Raw route text.
 * @param int $limit Maximum character count.
 * @return string Shortened route text.
 */
function simbrief_description_shorten(string $value, int $limit): string
{
    $value = simbrief_description_plain_text($value);
    if ($limit <= 0) {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length <= $limit) {
        return $value;
    }
    $cut = function_exists('mb_substr') ? (string) mb_substr($value, 0, max(1, $limit - 6), 'UTF-8') : substr($value, 0, max(1, $limit - 6));
    $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;
    return rtrim($cut, ' ,;:') . ' (...)';
}

/**
 * Join short phrases using natural English punctuation.
 *
 * @param array $parts Parts value.
 * @return string Joined phrase.
 */
function simbrief_description_join_phrase(array $parts): string
{
    $parts = array_values(array_filter(array_map(static fn (string $part): string => trim($part), $parts), static fn (string $part): bool => $part !== ''));
    $count = count($parts);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return $parts[0];
    }
    if ($count === 2) {
        return $parts[0] . ' and ' . $parts[1];
    }
    $last = array_pop($parts);
    return implode(', ', $parts) . ', and ' . $last;
}

/**
 * Normalize a runway label.
 *
 * @param string $value Raw runway value.
 * @return string Readable runway label.
 */
function simbrief_description_runway(string $value): string
{
    $value = strtoupper(simbrief_description_plain_text($value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/^RWY\s*/i', '', $value) ?? $value;
    return 'RWY ' . $value;
}

/**
 * Normalize a cruise altitude.
 *
 * @param string $value Raw altitude value.
 * @return string Readable altitude label.
 */
function simbrief_description_altitude(string $value): string
{
    $value = strtoupper(simbrief_description_plain_text($value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^FL\s*(\d{2,3})$/', $value, $match) === 1) {
        return 'FL' . $match[1];
    }
    $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
    if ($digits === '') {
        return $value;
    }
    $number = (int) $digits;
    if ($number >= 1000) {
        return 'FL' . str_pad((string) (int) round($number / 100), 3, '0', STR_PAD_LEFT);
    }
    if ($number >= 100) {
        return 'FL' . $number;
    }
    return $value;
}

/**
 * Normalize an en-route duration.
 *
 * @param string $value Raw duration value.
 * @return string Readable duration.
 */
function simbrief_description_duration(string $value): string
{
    $value = simbrief_description_plain_text($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $match) === 1) {
        return simbrief_description_duration_parts((int) $match[1], (int) $match[2]);
    }
    if (preg_match('/^\d{3,4}$/', $value) === 1) {
        $hours = (int) substr($value, 0, -2);
        $minutes = (int) substr($value, -2);
        if ($minutes < 60) {
            return simbrief_description_duration_parts($hours, $minutes);
        }
    }
    if (preg_match('/^\d+$/', $value) === 1) {
        $minutesTotal = (int) $value;
        if ($minutesTotal > 0 && $minutesTotal < 3000) {
            return simbrief_description_duration_parts(intdiv($minutesTotal, 60), $minutesTotal % 60);
        }
    }
    return $value;
}

/**
 * Render hour and minute parts as compact text.
 *
 * @param int $hours Hour count.
 * @param int $minutes Minute count.
 * @return string Readable duration.
 */
function simbrief_description_duration_parts(int $hours, int $minutes): string
{
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' h';
    }
    if ($minutes > 0 || $parts === []) {
        $parts[] = $minutes . ' min';
    }
    return implode(' ', $parts);
}

/**
 * Normalize a nautical-mile distance.
 *
 * @param string $value Raw distance value.
 * @return string Readable distance.
 */
function simbrief_description_distance(string $value): string
{
    $value = simbrief_description_plain_text($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/\d+(?:\.\d+)?/', str_replace(',', '', $value), $match) === 1) {
        return number_format((float) $match[0], 0, '.', ' ') . ' NM';
    }
    return $value;
}

/**
 * Normalize a weight or fuel value.
 *
 * @param string $value Raw value.
 * @param string $unit Display unit.
 * @return string Readable weight or fuel value.
 */
function simbrief_description_weight(string $value, string $unit): string
{
    $value = simbrief_description_plain_text($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/\d+(?:\.\d+)?/', str_replace(',', '', $value), $match) !== 1) {
        return $value;
    }
    $number = (float) $match[0];
    if ($number <= 0) {
        return '';
    }
    return number_format($number, 0, '.', ' ') . ' ' . ($unit !== '' ? $unit : 'kg');
}
