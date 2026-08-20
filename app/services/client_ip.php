<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/client_ip.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Resolves a trustworthy client IP for future server-side abuse controls.
 *
 * Responsibilities:
 *   - Default to REMOTE_ADDR when no trusted proxy is explicitly configured
 *   - Ignore forwarded-client headers received from untrusted direct peers
 *   - Support explicit trusted proxy IP/CIDR configuration for IPv4 and IPv6
 *   - Resolve only explicitly enabled forwarded header families
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
 *   - Existing visitor_hash()/admin throttling semantics intentionally remain unchanged.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\cms_config;

/**
 * Normalize one textual IP address into PHP's canonical printable representation.
 *
 * @param string $ip Candidate IPv4 or IPv6 address.
 * @return string Canonical address or an empty string when invalid.
 */
function request_client_ip_normalize(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '';
    }

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return '';
    }

    $normalized = @inet_ntop($packed);
    return is_string($normalized) ? $normalized : '';
}

/**
 * Return explicit trusted-proxy configuration with fail-closed defaults.
 *
 * @return array{proxies:array<int,string>,headers:array<int,string>} Trusted proxy settings.
 */
function request_trusted_proxy_config(): array
{
    $config = cms_config();
    $security = is_array($config['security'] ?? null) ? $config['security'] : [];
    $proxyValues = is_array($security['trusted_proxies'] ?? null) ? $security['trusted_proxies'] : [];
    $headerValues = is_array($security['trusted_proxy_headers'] ?? null) ? $security['trusted_proxy_headers'] : [];

    $proxies = [];
    foreach ($proxyValues as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $proxies[] = $value;
        }
    }

    $allowedHeaders = ['x-forwarded-for', 'x-real-ip', 'cf-connecting-ip'];
    $headers = [];
    foreach ($headerValues as $value) {
        $value = strtolower(trim((string) $value));
        if (in_array($value, $allowedHeaders, true) && !in_array($value, $headers, true)) {
            $headers[] = $value;
        }
    }

    return ['proxies' => $proxies, 'headers' => $headers];
}

/**
 * Return true when one canonical IP belongs to an explicitly trusted proxy entry.
 *
 * Entries may be exact IPv4/IPv6 addresses or CIDR networks. Invalid entries are
 * ignored rather than widening trust.
 *
 * @param string $ip Canonical or normalizable client address.
 * @param string $trustedProxy Exact IP or CIDR configuration entry.
 * @return bool True when the address is covered by the trusted entry.
 */
function request_ip_matches_trusted_proxy(string $ip, string $trustedProxy): bool
{
    $ip = request_client_ip_normalize($ip);
    $trustedProxy = trim($trustedProxy);
    if ($ip === '' || $trustedProxy === '') {
        return false;
    }

    if (!str_contains($trustedProxy, '/')) {
        return hash_equals(request_client_ip_normalize($trustedProxy), $ip);
    }

    [$networkText, $prefixText] = explode('/', $trustedProxy, 2);
    $network = request_client_ip_normalize($networkText);
    if ($network === '' || preg_match('/^\d+$/', trim($prefixText)) !== 1) {
        return false;
    }

    $packedIp = @inet_pton($ip);
    $packedNetwork = @inet_pton($network);
    if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
        return false;
    }

    $maxBits = strlen($packedIp) * 8;
    $prefix = (int) $prefixText;
    if ($prefix < 0 || $prefix > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
}

/**
 * Return true when one IP is covered by any explicitly configured trusted proxy.
 *
 * @param string $ip Candidate direct peer or forwarding-chain address.
 * @param array<int,string> $trustedProxies Trusted exact/CIDR entries.
 * @return bool True only when a configured entry covers the address.
 */
function request_ip_is_trusted_proxy(string $ip, array $trustedProxies): bool
{
    foreach ($trustedProxies as $trustedProxy) {
        if (request_ip_matches_trusted_proxy($ip, (string) $trustedProxy)) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve X-Forwarded-For by walking from the trusted direct peer toward the client.
 *
 * @param string $header Raw X-Forwarded-For header.
 * @param string $remoteAddress Canonical direct peer address.
 * @param array<int,string> $trustedProxies Trusted exact/CIDR entries.
 * @return string Resolved client address or an empty string when malformed.
 */
function request_client_ip_from_forwarded_for(string $header, string $remoteAddress, array $trustedProxies): string
{
    $chain = [];
    foreach (explode(',', $header) as $candidate) {
        $normalized = request_client_ip_normalize($candidate);
        if ($normalized === '') {
            return '';
        }
        $chain[] = $normalized;
    }
    if ($chain === []) {
        return '';
    }

    $chain[] = $remoteAddress;
    for ($index = count($chain) - 1; $index >= 0; $index--) {
        $candidate = $chain[$index];
        if (!request_ip_is_trusted_proxy($candidate, $trustedProxies)) {
            return $candidate;
        }
    }

    return $chain[0];
}

/**
 * Resolve the trustworthy client IP for future abuse controls.
 *
 * Forwarded headers are honored only when REMOTE_ADDR itself is trusted and the
 * corresponding header family is explicitly enabled. Without both conditions,
 * forwarded values are attacker-controlled input and are ignored.
 *
 * @return string Canonical client IP, or an empty string when REMOTE_ADDR is invalid.
 */
function request_client_ip(): string
{
    $remoteAddress = request_client_ip_normalize((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddress === '') {
        return '';
    }

    $config = request_trusted_proxy_config();
    if ($config['proxies'] === [] || !request_ip_is_trusted_proxy($remoteAddress, $config['proxies'])) {
        return $remoteAddress;
    }

    foreach ($config['headers'] as $header) {
        if ($header === 'x-forwarded-for') {
            $value = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            if ($value !== '') {
                $resolved = request_client_ip_from_forwarded_for($value, $remoteAddress, $config['proxies']);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
            continue;
        }

        $serverKey = $header === 'x-real-ip' ? 'HTTP_X_REAL_IP' : 'HTTP_CF_CONNECTING_IP';
        $resolved = request_client_ip_normalize((string) ($_SERVER[$serverKey] ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }
    }

    return $remoteAddress;
}

/**
 * Return explicitly enabled trusted proxy protocol-header families.
 *
 * These settings are intentionally separate from forwarded client-IP headers.
 *
 * @return array<int,string> Allowlisted protocol header names.
 */
function request_trusted_proxy_protocol_headers(): array
{
    $config = cms_config();
    $security = is_array($config['security'] ?? null) ? $config['security'] : [];
    $values = is_array($security['trusted_proxy_protocol_headers'] ?? null)
        ? $security['trusted_proxy_protocol_headers']
        : [];
    $allowed = ['x-forwarded-proto', 'x-forwarded-ssl'];
    $headers = [];
    foreach ($values as $value) {
        $value = strtolower(trim((string) $value));
        if (in_array($value, $allowed, true) && !in_array($value, $headers, true)) {
            $headers[] = $value;
        }
    }
    return $headers;
}

/**
 * Parse one trusted forwarded protocol header without accepting ambiguity.
 *
 * @param string $header Allowlisted forwarded protocol header family.
 * @return array{present:bool,valid:bool,https:bool} Parsed header state.
 */
function request_trusted_forwarded_protocol_state(string $header): array
{
    if ($header === 'x-forwarded-proto') {
        $value = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($value === '') {
            return ['present' => false, 'valid' => true, 'https' => false];
        }
        if (str_contains($value, ',')) {
            return ['present' => true, 'valid' => false, 'https' => false];
        }
        $value = strtolower($value);
        if (!in_array($value, ['http', 'https'], true)) {
            return ['present' => true, 'valid' => false, 'https' => false];
        }
        return ['present' => true, 'valid' => true, 'https' => $value === 'https'];
    }

    if ($header === 'x-forwarded-ssl') {
        $value = trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        if ($value === '') {
            return ['present' => false, 'valid' => true, 'https' => false];
        }
        if (str_contains($value, ',')) {
            return ['present' => true, 'valid' => false, 'https' => false];
        }
        $value = strtolower($value);
        if (!in_array($value, ['on', 'off'], true)) {
            return ['present' => true, 'valid' => false, 'https' => false];
        }
        return ['present' => true, 'valid' => true, 'https' => $value === 'on'];
    }

    return ['present' => false, 'valid' => false, 'https' => false];
}

/**
 * Resolve HTTPS for viewer authentication using only direct transport or explicitly trusted proxy metadata.
 *
 * The historical generic request_is_https() remains unchanged for compatibility. Forwarded
 * protocol headers are considered here only when REMOTE_ADDR is an explicitly configured
 * trusted proxy and the specific protocol-header family is explicitly enabled.
 *
 * @return bool True only for direct HTTPS or unambiguous trusted forwarded HTTPS.
 */
function viewer_request_is_https(): bool
{
    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    $remoteAddress = request_client_ip_normalize((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddress === '') {
        return false;
    }
    $proxyConfig = request_trusted_proxy_config();
    if ($proxyConfig['proxies'] === [] || !request_ip_is_trusted_proxy($remoteAddress, $proxyConfig['proxies'])) {
        return false;
    }

    $headers = request_trusted_proxy_protocol_headers();
    if ($headers === []) {
        return false;
    }

    $resolved = null;
    foreach ($headers as $header) {
        $state = request_trusted_forwarded_protocol_state($header);
        if (!$state['valid']) {
            return false;
        }
        if (!$state['present']) {
            continue;
        }
        if ($resolved !== null && $resolved !== $state['https']) {
            return false;
        }
        $resolved = $state['https'];
    }

    return $resolved === true;
}

/**
 * Return whether the current request satisfies the viewer authentication transport policy.
 *
 * @return bool True when HTTPS is not required or trusted HTTPS has been proven.
 */
function viewer_security_transport_allowed(): bool
{
    $config = viewer_accounts_config();
    return !(bool) $config['require_https'] || viewer_request_is_https();
}
