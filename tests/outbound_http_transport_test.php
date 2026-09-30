<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/outbound_http_transport_test.php
 * Module Type: Regression Test
 * Purpose: Verify bounded credential-bearing HTTPS transport without external networking.
 * Responsibilities: Exercise DNS refusal, cURL pinning, redirects, response limits and safe errors.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /** Supply only fixture DNS records.
     * @param string $host Requested hostname.
     * @param int $type DNS record type.
     * @return list<array{ip:string}> Controlled address set.
     */
    function dns_get_record(string $host, int $type): array
    {
        return $GLOBALS['http_dns'];
    }
    /** Prevent fallback from using the real resolver.
     * @param string $host Requested hostname.
     * @return bool Always false; no external resolution is permitted.
     */
    function gethostbynamel(string $host): bool { return false; }

    /** Allocate a real opaque handle without connecting.
     * @param string $url Validated target URL.
     * @return \CurlHandle Fixture handle.
     */
    function curl_init(string $url): \CurlHandle
    {
        $GLOBALS['http_connections']++;
        return \curl_init($url);
    }
    /** Capture options from the production transport.
     * @param \CurlHandle $handle Opaque fixture handle.
     * @param array<int,mixed> $options Production cURL configuration.
     * @return bool Successful capture.
     */
    function curl_setopt_array(\CurlHandle $handle, array $options): bool
    {
        $GLOBALS['http_options'] = $options;
        return true;
    }
    /** Drive bounded production sinks without invoking network I/O.
     * @param \CurlHandle $handle Opaque fixture handle.
     * @return bool Whether sinks accepted the simulated response.
     */
    function curl_exec(\CurlHandle $handle): bool
    {
        $options = $GLOBALS['http_options'];
        $header = $GLOBALS['http_header'];
        $body = $GLOBALS['http_body'];
        return $options[CURLOPT_HEADERFUNCTION]($handle, $header) === strlen($header)
            && $options[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
    }
    /** Return fixture status or media type.
     * @param \CurlHandle $handle Opaque fixture handle.
     * @param int $option Requested response metadata.
     * @return int|string Controlled response metadata.
     */
    function curl_getinfo(\CurlHandle $handle, int $option): int|string
    {
        return $option === CURLINFO_RESPONSE_CODE ? $GLOBALS['http_status'] : $GLOBALS['http_type'];
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/outbound_http.php';
    use Gallery\Services as S;

    /** Fail with a nonsecret diagnostic.
     * @param bool $value Expected invariant.
     * @param string $message Nonsecret failure description.
     * @return void No output on success.
     */
    function outbound_check(bool $value, string $message): void
    {
        if (!$value) { throw new \RuntimeException($message); }
    }
    /** Expect a safe refusal from the actual transport.
     * @return void Refusal must never include fixture credentials.
     */
    function outbound_refuses(): void
    {
        try {
            S\outbound_http_json_request('https://peer.example/index.php?page=cooperative_peer_api', ['protocol' => 1], str_repeat('s', 43));
        } catch (\RuntimeException $error) {
            outbound_check(!str_contains($error->getMessage(), str_repeat('s', 43)), 'Transport exception leaked bearer.');
            return;
        }
        throw new \RuntimeException('Expected transport refusal.');
    }

    $GLOBALS['http_connections'] = 0;
    $GLOBALS['http_dns'] = [['ip' => '8.8.8.8'], ['ip' => '127.0.0.1']];
    $GLOBALS['http_status'] = 200;
    $GLOBALS['http_type'] = 'application/json; charset=utf-8';
    $GLOBALS['http_header'] = "Content-Type: application/json\r\n";
    $GLOBALS['http_body'] = '{"ok":true}';
    outbound_refuses();
    outbound_check($GLOBALS['http_connections'] === 0, 'Unsafe DNS reached cURL.');

    $GLOBALS['http_dns'] = [['ip' => '8.8.8.8']];
    $response = S\outbound_http_json_request('https://peer.example/index.php?page=cooperative_peer_api', ['protocol' => 1], str_repeat('s', 43));
    outbound_check($response === ['ok' => true], 'Valid bounded JSON failed.');
    $options = $GLOBALS['http_options'];
    outbound_check($options[CURLOPT_RESOLVE] === ['peer.example:443:8.8.8.8']
        && $options[CURLOPT_IPRESOLVE] === CURL_IPRESOLVE_V4
        && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Validated destination was not pinned.');
    outbound_check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0
        && $options[CURLOPT_PROXY] === '' && $options[CURLOPT_NOPROXY] === '*', 'Redirect or proxy credential forwarding was enabled.');
    outbound_check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2
        && $options[CURLOPT_CONNECTTIMEOUT] > 0 && $options[CURLOPT_TIMEOUT] <= 15, 'TLS or bounded timeout policy regressed.');
    outbound_check(in_array('Authorization: Bearer ' . str_repeat('s', 43), $options[CURLOPT_HTTPHEADER], true)
        && !str_contains($options[CURLOPT_POSTFIELDS], str_repeat('s', 43)), 'Bearer escaped its header boundary.');
    $GLOBALS['http_status'] = 302;
    outbound_refuses();
    $GLOBALS['http_status'] = 200;
    $GLOBALS['http_type'] = 'application/json-injected';
    outbound_refuses();
    $GLOBALS['http_type'] = 'application/json';
    $GLOBALS['http_body'] = str_repeat('x', 16385);
    outbound_refuses();
    $GLOBALS['http_body'] = '{"ok":true}';
    $GLOBALS['http_header'] = str_repeat('x', 8193);
    outbound_refuses();
    echo "Pinned HTTPS transport contracts passed.\n";
}
