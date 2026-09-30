<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/outbound_http.php
 * Module Type: Service
 * Purpose: Provide bounded HTTPS JSON requests to public pinned IPv4 destinations.
 * Responsibilities: Centralize DNS address policy and keep credentials away from redirects and proxies.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Accept only routable IPv4 addresses, excluding shared and special-purpose networks.
 *
 * @param string $address Candidate IPv4 address.
 * @return bool Result described by the operation above.
 */
function outbound_http_ipv4_is_public(string $address): bool
{
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    $octets = array_map('intval', explode('.', $address));
    return $octets[0] < 224
        && !($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127)
        && !($octets[0] === 192 && $octets[1] === 0 && in_array($octets[2], [0, 2], true))
        && !($octets[0] === 192 && $octets[1] === 88 && $octets[2] === 99)
        && !($octets[0] === 198 && in_array($octets[1], [18, 19], true))
        && !($octets[0] === 198 && $octets[1] === 51 && $octets[2] === 100)
        && !($octets[0] === 203 && $octets[1] === 0 && $octets[2] === 113);
}

/** Reject mixed public/private DNS answers before selecting a pinned destination.
 *
 * @param list<string> $addresses Complete candidate IPv4 DNS answer set.
 * @return string|null Result described by the operation above.
 */
function outbound_http_choose_public_ipv4(array $addresses): ?string
{
    if ($addresses === [] || count($addresses) > 64) {
        return null;
    }
    foreach ($addresses as $address) {
        if (!is_string($address) || !outbound_http_ipv4_is_public($address)) {
            return null;
        }
    }
    return $addresses[0];
}

/**
 * Send one bounded JSON request; no automatic redirects, environment proxy or cookies.
 * IPv6-only destinations intentionally fail closed until equivalent pinning is supported.
 *
 * @param string $url HTTPS destination without embedded credentials.
 * @param array<string,mixed>|null $payload Internal structured payload; null selects discovery where supported.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
function outbound_http_json_request(string $url, ?array $payload, string $bearer = ''): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['fragment'])
        || preg_match('/[\x00-\x20\x7f]/', $url)
        || ($bearer !== '' && preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $bearer) !== 1)) {
        throw new \RuntimeException('Outbound destination rejected.');
    }
    $host = $parts['host'] ?? '';
    $ip = outbound_http_resolve_public_ipv4($host);
    if ($ip === null || !function_exists('curl_init')) {
        throw new \RuntimeException('Outbound transport unavailable.');
    }
    $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if ($body !== null && strlen($body) > 16384) {
        throw new \RuntimeException('Outbound message too large.');
    }
    $handle = curl_init($url);
    if ($handle === false) {
        throw new \RuntimeException('Outbound transport unavailable.');
    }
    $response = '';
    $headerBytes = 0;
    $headers = ['Accept: application/json', 'Accept-Encoding: identity', 'Content-Type: application/json', 'Expect:'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    $port = $parts['port'] ?? 443;
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'PHP-Gallery-Cooperative/1',
        CURLOPT_WRITEFUNCTION => /** Apply the local transition or bounded response callback.
         * @param \CurlHandle $handle Opaque cURL handle supplied to the callback.
         * @param string $chunk Next response body chunk.
         * @return int Transition result or accepted byte count.
         */ static function (\CurlHandle $handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 16384) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => /** Apply the local transition or bounded response callback.
         * @param \CurlHandle $handle Opaque cURL handle supplied to the callback.
         * @param string $line Next response header line.
         * @return int Transition result or accepted byte count.
         */ static function (\CurlHandle $handle, string $line) use (&$headerBytes): int {
            $headerBytes += strlen($line);
            return $headerBytes > 8192 ? 0 : strlen($line);
        },
    ];
    if ($body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $options);
    try {
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $type = strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
    } finally {
        curl_close($handle);
    }
    if ($success === false || $status !== 200 || trim(explode(';', $type, 2)[0]) !== 'application/json') {
        throw new \RuntimeException('Outbound peer response unavailable.');
    }
    $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new \RuntimeException('Outbound peer response invalid.');
    }
    return $decoded;
}

/** Resolve one hostname once and validate every IPv4 answer before pinning.
 *
 * @param string $host DNS hostname to resolve.
 * @return string|null Result described by the operation above.
 */
function outbound_http_resolve_public_ipv4(string $host): ?string
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return null;
    }

    $dnsHost = $host;
    if (function_exists('idn_to_ascii')) {
        $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
        $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
        $ascii = @idn_to_ascii($host, $flags, $variant);
        if (is_string($ascii) && $ascii !== '') {
            $dnsHost = $ascii;
        }
    }
    if (preg_match('/^[A-Za-z0-9.-]{1,253}$/', $dnsHost) !== 1) {
        return null;
    }

    $addresses = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($dnsHost, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                }
            }
        }
    }
    if ($addresses === []) {
        $fallback = @gethostbynamel($dnsHost);
        if (is_array($fallback)) {
            $addresses = array_values(array_filter(array_map('strval', $fallback)));
        }
    }
    $addresses = array_values(array_unique($addresses));
    if ($addresses === []) {
        return null;
    }

    return outbound_http_choose_public_ipv4($addresses);
}
