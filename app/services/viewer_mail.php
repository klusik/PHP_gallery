<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_mail.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Defines the dormant anti-abuse authorization boundary for future viewer security email.
 *
 * Responsibilities:
 *   - Treat email delivery as a separately rate-limited authority rather than a side effect of an HTTP request
 *   - Reserve per-address, per-client, and installation-wide budgets before any future mail transport is called
 *   - Reuse the bounded viewer rate-limit service instead of creating a second counter subsystem
 *   - Fail closed when subjects or limiter storage cannot be trusted
 *   - Provide one generic future public result code that does not reveal account or delivery state
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
 *   - This file intentionally sends no email and contains no mail(), SMTP, API, queue, or provider integration.
 *   - Existing administrator password-reset mail behavior remains untouched in Phase 0.5.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\cms_config;

const VIEWER_MAIL_ACTION_VERIFICATION = 'verification';
const VIEWER_MAIL_ACTION_PASSWORD_RESET = 'password_reset';
const VIEWER_MAIL_ACTION_INVITATION = 'invitation';
const VIEWER_MAIL_ACTION_EMAIL_CHANGE = 'email_change';


/**
 * Return the configured trusted base URL for future viewer security links.
 *
 * The authority is derived only from configuration. HTTP_HOST and other request host
 * headers are deliberately ignored so verification/reset links cannot be poisoned.
 *
 * @return ?string Validated absolute base URL, or null when no trustworthy origin exists.
 */
function viewer_security_base_url(): ?string
{
    $config = cms_config();
    $base = trim((string) ($config['base_url'] ?? ''));
    if ($base === '' || preg_match('/[\x00-\x1F\x7F]/', $base) === 1 || str_contains($base, '\\')) {
        return null;
    }

    $parts = parse_url($base);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        return null;
    }
    if ((bool) viewer_accounts_config()['require_https'] && $scheme !== 'https') {
        return null;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && !str_starts_with($path, '/')) {
        return null;
    }
    $path = rtrim($path, '/');
    $displayHost = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
    return $scheme . '://' . $displayHost . ($port === null ? '' : ':' . $port) . $path;
}

/**
 * Build one future viewer security URL from the trusted configured base URL only.
 *
 * @param string $relativePath Application-relative path without query or fragment.
 * @param array<string,int|string> $query Optional application-owned query parameters.
 * @return ?string Absolute security URL, or null when the trusted origin/path is invalid.
 */
function viewer_security_url(string $relativePath, array $query = []): ?string
{
    $base = viewer_security_base_url();
    $relativePath = trim($relativePath);
    if ($base === null
        || $relativePath === ''
        || str_contains($relativePath, '?')
        || str_contains($relativePath, '#')
        || str_contains($relativePath, '\\')
        || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1) {
        return null;
    }

    $url = $base . '/' . ltrim($relativePath, '/');
    if ($query !== []) {
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($encoded !== '') {
            $url .= '?' . $encoded;
        }
    }
    return $url;
}

/**
 * Return a generic external result code for future viewer mail-triggering requests.
 *
 * @return string Generic response code.
 */
function viewer_mail_public_result_code(): string
{
    return 'request_received';
}

/**
 * Return the fixed rate-limit plan for one future viewer security email action.
 *
 * @param string $action Allowlisted action.
 * @return array<int,array{bucket:string,kind:string}> Ordered budget dimensions.
 */
function viewer_mail_rate_limit_plan(string $action): array
{
    if (in_array($action, [VIEWER_MAIL_ACTION_VERIFICATION, VIEWER_MAIL_ACTION_EMAIL_CHANGE], true)) {
        return [
            ['bucket' => 'viewer_verify_mail_email_cooldown', 'kind' => 'identifier'],
            ['bucket' => 'viewer_verify_mail_email_hour', 'kind' => 'identifier'],
            ['bucket' => 'viewer_verify_mail_email_day', 'kind' => 'identifier'],
            ['bucket' => 'viewer_verify_mail_ip_hour', 'kind' => 'ip'],
            ['bucket' => 'viewer_verify_mail_ip_day', 'kind' => 'ip'],
            ['bucket' => 'viewer_verify_mail_subnet_hour', 'kind' => 'subnet'],
            ['bucket' => 'viewer_verify_mail_subnet_day', 'kind' => 'subnet'],
            ['bucket' => 'viewer_verify_mail_global_day', 'kind' => 'global'],
        ];
    }

    if ($action === VIEWER_MAIL_ACTION_PASSWORD_RESET) {
        return [
            ['bucket' => 'viewer_reset_mail_email_cooldown', 'kind' => 'identifier'],
            ['bucket' => 'viewer_reset_mail_email_hour', 'kind' => 'identifier'],
            ['bucket' => 'viewer_reset_mail_email_day', 'kind' => 'identifier'],
            ['bucket' => 'viewer_reset_mail_ip_hour', 'kind' => 'ip'],
            ['bucket' => 'viewer_reset_mail_ip_day', 'kind' => 'ip'],
            ['bucket' => 'viewer_reset_mail_subnet_hour', 'kind' => 'subnet'],
            ['bucket' => 'viewer_reset_mail_subnet_day', 'kind' => 'subnet'],
            ['bucket' => 'viewer_reset_mail_global_day', 'kind' => 'global'],
        ];
    }

    if ($action === VIEWER_MAIL_ACTION_INVITATION) {
        return [
            ['bucket' => 'viewer_invite_mail_email_day', 'kind' => 'identifier'],
            ['bucket' => 'viewer_invite_mail_global_day', 'kind' => 'global'],
        ];
    }

    throw new InvalidArgumentException('Unknown viewer mail action.');
}

/**
 * Reserve all abuse-control budgets required before one future viewer security email send.
 *
 * The function does not send mail. The future caller must invoke this before transport
 * delivery and must not attempt delivery when allowed is false. Narrow recipient/network
 * budgets are reserved before the installation-global budget so obviously suppressed attempts
 * cannot cheaply burn the global mail circuit breaker. A transport failure does not refund the
 * reservation because delivery attempts themselves consume bounded resources and automatic
 * refunding can create retry-amplification races.
 *
 * @param string $action Allowlisted viewer mail action.
 * @param string $email Recipient candidate.
 * @param ?string $clientIp Explicit client IP for tests/internal callers, otherwise trusted resolver result.
 * @return array{allowed:bool,reason:string,retry_after_seconds:int,public_result:string}
 */
function viewer_mail_authorize_send(string $action, string $email, ?string $clientIp = null): array
{
    $publicResult = viewer_mail_public_result_code();
    if (!viewer_accounts_enabled()) {
        return [
            'allowed' => false,
            'reason' => 'viewer_disabled',
            'retry_after_seconds' => 0,
            'public_result' => $publicResult,
        ];
    }
    if (!viewer_auth_storage_available()) {
        return [
            'allowed' => false,
            'reason' => 'storage_unavailable',
            'retry_after_seconds' => 0,
            'public_result' => $publicResult,
        ];
    }

    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return [
            'allowed' => false,
            'reason' => 'invalid_email',
            'retry_after_seconds' => 0,
            'public_result' => $publicResult,
        ];
    }

    $resolvedIp = $clientIp === null ? request_client_ip() : request_client_ip_normalize($clientIp);
    $plan = viewer_mail_rate_limit_plan($action);
    foreach ($plan as $dimension) {
        if (in_array($dimension['kind'], ['ip', 'subnet'], true) && $resolvedIp === '') {
            return [
                'allowed' => false,
                'reason' => 'client_ip_unavailable',
                'retry_after_seconds' => 0,
                'public_result' => $publicResult,
            ];
        }
    }

    try {
        foreach ($plan as $dimension) {
            $kind = $dimension['kind'];
            if ($kind === 'global') {
                $subject = 'global';
            } elseif (in_array($kind, ['ip', 'subnet'], true)) {
                $subject = $resolvedIp;
            } else {
                $subject = $normalized;
            }
            $decision = viewer_rate_limit_consume($dimension['bucket'], $kind, $subject);
            if (!$decision['allowed']) {
                return [
                    'allowed' => false,
                    'reason' => 'rate_limited',
                    'retry_after_seconds' => (int) $decision['retry_after_seconds'],
                    'public_result' => $publicResult,
                ];
            }
        }
    } catch (Throwable) {
        return [
            'allowed' => false,
            'reason' => 'limiter_unavailable',
            'retry_after_seconds' => 0,
            'public_result' => $publicResult,
        ];
    }

    return [
        'allowed' => true,
        'reason' => 'ok',
        'retry_after_seconds' => 0,
        'public_result' => $publicResult,
    ];
}
