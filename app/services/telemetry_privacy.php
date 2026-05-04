<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/telemetry_privacy.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-05-04
 */

/**
 * Privacy normalization helpers for anonymous telemetry.
 *
 * These helpers are deliberately conservative. If a value is malformed,
 * over-specific, or unnecessary, it is reduced to a coarse bucket or dropped.
 */

/**
 * Return a short anonymous hash for a browser-generated session id.
 */
function telemetry_session_hash(?string $clientSessionId): ?string
{
    if ($clientSessionId === null || $clientSessionId === '') {
        return null;
    }
    if (strlen($clientSessionId) > 80 || preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $clientSessionId) !== 1) {
        return null;
    }
    // $config stores application configuration used to derive a local hashing secret.
    $config = cms_config();
    // $secret stores a local secret. The raw browser session id is never persisted.
    $secret = (string) ($config['telemetry_secret'] ?? $config['admin_session_name'] ?? 'php-gallery');
    return substr(hash_hmac('sha256', $clientSessionId, $secret, true), 0, 16);
}

/**
 * Normalize a referrer URL into a privacy-safe category.
 */
function telemetry_referrer_category(?string $referrer): string
{
    if ($referrer === null || trim($referrer) === '') {
        return 'direct';
    }
    // $host stores the parsed host only long enough to classify the referrer.
    $host = parse_url($referrer, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return 'unknown';
    }
    // $currentHost stores the current request host for internal/external classification.
    $currentHost = request_host_name();
    if ($currentHost !== '' && strcasecmp($host, $currentHost) === 0) {
        return 'internal';
    }
    // $lowerHost stores a lowercase host used only for category matching.
    $lowerHost = strtolower($host);
    foreach (['google.', 'bing.', 'duckduckgo.', 'seznam.', 'yahoo.'] as $searchHost) {
        if (str_contains($lowerHost, $searchHost)) {
            return 'search';
        }
    }
    foreach (['facebook.', 'instagram.', 'x.com', 'twitter.', 'reddit.', 'pinterest.', 'linkedin.'] as $socialHost) {
        if (str_contains($lowerHost, $socialHost)) {
            return 'social';
        }
    }
    return 'external';
}

/**
 * Normalize viewport width into a coarse class.
 */
function telemetry_viewport_class(?int $width): string
{
    if ($width === null || $width <= 0) {
        return 'unknown';
    }
    if ($width < 480) {
        return 'xs';
    }
    if ($width < 768) {
        return 'sm';
    }
    if ($width < 1024) {
        return 'md';
    }
    if ($width < 1280) {
        return 'lg';
    }
    if ($width < 1920) {
        return 'xl';
    }
    return 'xxl';
}

/**
 * Normalize locale into a primary language bucket.
 */
function telemetry_locale_bucket(?string $locale): ?string
{
    if ($locale === null || $locale === '') {
        return null;
    }
    // $lowerLocale stores a temporary lowercase locale string used for parsing.
    $lowerLocale = strtolower($locale);
    if (preg_match('/^[a-z]{2,3}/', $lowerLocale, $match) !== 1) {
        return null;
    }
    return $match[0];
}

/**
 * Return a safe enum value from an allowlist.
 */
function telemetry_enum(mixed $value, array $allowedValues, string $default): string
{
    // $stringValue stores the scalar candidate used for allowlist matching.
    $stringValue = is_scalar($value) ? (string) $value : '';
    return in_array($stringValue, $allowedValues, true) ? $stringValue : $default;
}

/**
 * Return a nullable positive integer for id/count fields.
 */
function telemetry_nullable_positive_int(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    // $integerValue stores the bounded integer candidate.
    $integerValue = (int) $value;
    return $integerValue > 0 ? $integerValue : null;
}

/**
 * Return a bounded short text identifier.
 */
function telemetry_short_identifier(mixed $value, int $maxLength = 80): ?string
{
    if (!is_scalar($value)) {
        return null;
    }
    // $stringValue stores the trimmed identifier candidate.
    $stringValue = trim((string) $value);
    if ($stringValue === '') {
        return null;
    }
    $stringValue = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $stringValue) ?? '';
    return substr($stringValue, 0, $maxLength);
}

/**
 * Return a safe metric bucket name.
 */
function telemetry_value_bucket(mixed $value): ?string
{
    return telemetry_short_identifier($value, 40);
}

/**
 * Return a safe error kind.
 */
function telemetry_error_kind(mixed $value): ?string
{
    return telemetry_short_identifier($value, 80);
}

/**
 * Return a bounded sampled rate value.
 */
function telemetry_sample_rate(mixed $value): float
{
    // $rate stores the normalized sampling rate in range 0.0001 to 1.0000.
    $rate = (float) $value;
    if ($rate <= 0) {
        return 1.0;
    }
    return max(0.0001, min(1.0, $rate));
}

/**
 * Return a SQL datetime derived from an event timestamp or now if invalid.
 */
function telemetry_datetime_from_event(mixed $value): string
{
    if (is_string($value) && $value !== '') {
        // $timestamp stores the parsed client timestamp.
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            // $lowerBound stores the oldest accepted client timestamp.
            $lowerBound = time() - 86400;
            // $upperBound stores the newest accepted client timestamp.
            $upperBound = time() + 300;
            if ($timestamp >= $lowerBound && $timestamp <= $upperBound) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
    }
    return now_sql();
}

/**
 * Return a privacy-safe JSON context for one event name.
 */
function telemetry_context_json(string $eventName, mixed $context): ?string
{
    if (!is_array($context)) {
        return null;
    }
    // $allowedKeys stores the context allowlist by event family.
    $allowedKeys = [
        'public.photo.visible_time' => ['lightbox_mode'],
        'public.photo.opened' => ['lightbox_mode'],
        'client.performance.image_decode' => ['display_width_bucket', 'natural_width_bucket'],
        'client.performance.image_display' => ['display_width_bucket', 'natural_width_bucket'],
        'client.error.javascript' => ['component'],
        'cache.lightbox.hit' => ['source_kind'],
        'cache.lightbox.miss' => ['source_kind'],
        'cache.lightbox.evicted' => ['source_kind'],
    ];
    // $safeContext stores only allowlisted scalar context values.
    $safeContext = [];
    foreach (($allowedKeys[$eventName] ?? []) as $key) {
        if (!array_key_exists($key, $context) || !is_scalar($context[$key])) {
            continue;
        }
        $safeContext[$key] = substr((string) $context[$key], 0, 80);
    }
    return $safeContext ? json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}
