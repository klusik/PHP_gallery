<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/simbrief_ofp_public_http_test.php
 * Module Type: Standalone HTTP Regression Test
 * Purpose: Prove anonymous saved OFP PDFs work without admin cookies.
 * Responsibilities:
 *   - Exercise GET, HEAD, attachment and legacy routes through a real PHP HTTP server
 *   - Check public, private, unpublished, password, share, NSFW and missing PDFs
 *   - Test same-origin PDF links in root and non-root installations without URL rewriting
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Poll delay for the disposable loopback PDF fixture startup.
 * @var int
 * Units: microseconds.
 * Scope: This standalone OFP HTTP test process only.
 * Consumers: Server readiness loop in simbrief_ofp_public_http_test.php.
 * Rationale: A bounded small poll avoids excessive startup spin while ensuring
 * fast local readiness without changing production media scheduling.
 */
const OFP_HTTP_STARTUP_POLL_MICROSECONDS = 50000;

/**
 * Abort a failed HTTP assertion with a bounded diagnostic message.
 *
 * @param bool $ok Required postcondition.
 * @param string $message Assertion failure description.
 * @return void Throws for failed assertions.
 */
function ofp_http_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/**
 * Reserve an ephemeral loopback TCP port for this isolated fixture.
 *
 * @return int Free local port number.
 */
function ofp_http_free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    ofp_http_assert(is_resource($socket), 'Could not reserve OFP fixture port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    ofp_http_assert(is_string($address) && preg_match('/:(\d+)$/D', $address, $matches) === 1,
        'Could not parse OFP fixture listener address.');
    return (int) $matches[1];
}

/**
 * Make one bounded HTTP request with independent, explicitly selected cookies.
 *
 * @param string $url Full loopback fixture URL.
 * @param string $method GET, HEAD or POST.
 * @param string $cookie Optional exact fixture cookie header.
 * @return array{status:int,headers:list<string>,body:string} Response status, headers and bytes.
 */
function ofp_http_request(string $url, string $method = 'GET', string $cookie = ''): array
{
    $headers = ['Accept: application/pdf'];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'timeout' => 8,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    return [
        'status' => $status,
        'headers' => array_values($responseHeaders),
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * Check one case-insensitive response header against a required value fragment.
 *
 * @param array{status:int,headers:list<string>,body:string} $response HTTP result.
 * @param string $name Expected header name.
 * @param string $contains Expected value fragment.
 * @return bool Whether the header contains the expected value.
 */
function ofp_http_header_contains(array $response, string $name, string $contains): bool
{
    foreach ($response['headers'] as $header) {
        if (stripos($header, $name . ':') === 0 && stripos($header, $contains) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Write a fixture's local SimBrief manifest and optional saved PDF.
 *
 * @param string $root Fixture gallery storage directory.
 * @param string $folder Gallery-relative owned directory.
 * @param ?string $pdf Bytes to save, or null for an absent attachment.
 * @return void Create strictly temporary gallery contents.
 */
function ofp_http_prepare_gallery(string $root, string $folder, ?string $pdf): void
{
    $directory = $root . '/galleries/' . $folder;
    ofp_http_assert(is_dir($directory) || mkdir($directory, 0700, true), 'Could not create fixture gallery.');
    ofp_http_assert(file_put_contents($directory . '/simbrief-ofp-manifest.json', json_encode([
        'format' => 'php_gallery_simbrief_ofp_manifest_v1',
        'ofp_pdf_file' => 'simbrief-ofp.pdf',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) !== false, 'Cannot write OFP fixture manifest.');
    if ($pdf !== null) {
        ofp_http_assert(file_put_contents($directory . '/simbrief-ofp.pdf', $pdf) !== false,
            'Cannot write saved OFP fixture PDF.');
    }
}

/**
 * Remove only one exact temporary tree owned by the current test.
 *
 * @param string $root Temporary fixture root.
 * @param string $token Secret random owner token stored in its marker.
 * @return void Remove all owned files and directories.
 */
function ofp_http_cleanup(string $root, string $token): void
{
    $marker = $root . '/.ofp-http-owner';
    if (!is_file($marker) || !hash_equals($token, (string) @file_get_contents($marker))) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            @unlink($entry->getPathname());
        } elseif ($entry->isDir()) {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($root);
}

$token = bin2hex(random_bytes(12));
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-ofp-http-' . $token;
ofp_http_assert(mkdir($root, 0700), 'Cannot create owned OFP HTTP fixture.');
$process = null;
$log = $root . '/server.log';

try {
    ofp_http_assert(file_put_contents($root . '/.ofp-http-owner', $token) !== false,
        'Cannot write OFP fixture ownership marker.');
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    foreach (['public', 'unpublished', 'private', 'password', 'nsfw', 'parent',
        'parent/ofp-pages', 'share', 'unpublished-password', 'unlisted',
        'unpublished-nsfw', 'legacy-draft', 'private-share'] as $folder) {
        ofp_http_prepare_gallery($root, $folder, $pdf);
    }
    ofp_http_prepare_gallery($root, 'missing', null);
    ofp_http_prepare_gallery($root, 'invalid', "NOT_A_PDF\n");

    $port = ofp_http_free_port();
    $router = __DIR__ . '/fixtures/simbrief_ofp_public_http_router.php';
    $environment = array_merge(getenv() ?: [], ['PHP_GALLERY_OFP_HTTP_ROOT' => $root]);
    $process = proc_open([
        PHP_BINARY, '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port, '-t', $root, $router,
    ], [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'ab'],
        2 => ['file', $log, 'ab'],
    ], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
    ofp_http_assert(is_resource($process), 'Could not start OFP fixture HTTP server.');
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 80; $attempt++) {
        $info = proc_get_status($process);
        ofp_http_assert(!empty($info['running']), 'OFP fixture server terminated during startup.');
        $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(OFP_HTTP_STARTUP_POLL_MICROSECONDS);
    }
    ofp_http_assert($ready, 'OFP fixture HTTP server did not start.');

    $origin = 'http://127.0.0.1:' . $port;
    foreach (['', '/galerie'] as $base) {
        $links = ofp_http_request($origin . $base . '/index.php?fixture_links=1');
        ofp_http_assert($links['status'] === 200, 'Cannot retrieve URL fixture for ' . $base);
        $urls = json_decode($links['body'], true, 512, JSON_THROW_ON_ERROR);
        $expected = $base . '/index.php?page=gallery_ofp_pdf&id=1';
        ofp_http_assert($urls['view'] === $expected && $urls['download'] === $expected . '&download=1',
            'OFP links must stay same-origin, preserve the actual mount path and avoid overloaded media IDs.');

        // This request uses no Cookie header, reproducing a brand new incognito session.
        $public = ofp_http_request($origin . $urls['view']);
        ofp_http_assert($public['status'] === 200 && $public['body'] === $pdf,
            'Fresh anonymous viewer did not receive the real saved public OFP PDF.');
        ofp_http_assert(ofp_http_header_contains($public, 'Content-Type', 'application/pdf')
            && ofp_http_header_contains($public, 'Content-Disposition', 'inline')
            && ofp_http_header_contains($public, 'Cache-Control', 'no-store')
            && ofp_http_header_contains($public, 'X-Content-Type-Options', 'nosniff'),
            'Anonymous inline OFP response lacks protected PDF headers.');

        $download = ofp_http_request($origin . $urls['download']);
        ofp_http_assert($download['status'] === 200 && $download['body'] === $pdf
            && ofp_http_header_contains($download, 'Content-Disposition', 'attachment'),
            'Fresh anonymous PDF download must return the identical saved bytes.');

        $head = ofp_http_request($origin . $urls['view'], 'HEAD');
        ofp_http_assert($head['status'] === 200 && $head['body'] === ''
            && ofp_http_header_contains($head, 'Content-Length', (string) strlen($pdf)),
            'Anonymous PDF HEAD must return the same metadata without a body.');

        $legacy = ofp_http_request($origin . $base . '/index.php?page=media&id=1&ofp=1');
        ofp_http_assert($legacy['status'] === 200 && $legacy['body'] === $pdf,
            'Previously generated gallery media?ofp=1 URLs stopped working.');
        $legacyDownload = ofp_http_request($origin . $base . '/index.php?page=media&id=1&ofp=1&download=1');
        ofp_http_assert($legacyDownload['status'] === 200 && $legacyDownload['body'] === $pdf
            && ofp_http_header_contains($legacyDownload, 'Content-Disposition', 'attachment'),
            'Legacy PDF download must traverse the production SEO query guard.');

        // A previously omitted route parameter used to trigger the real
        // anonymous SEO guard's 404 before the PDF controller was reached.
        // Keep the guard strict: only supported OFP keys may pass preflight.
        foreach ([
            '/index.php?page=gallery_ofp_pdf&id=1&unexpected=1',
            '/index.php?page=media&id=1&ofp=1&unexpected=1',
        ] as $rejectedUrl) {
            $rejected = ofp_http_request($origin . $base . $rejectedUrl);
            ofp_http_assert($rejected['status'] === 404 && $rejected['body'] === "Not found.\n",
                'The production SEO query guard must still reject unknown anonymous parameters.');
        }

        // Unpublished is reachable by its direct gallery URL, hence its
        // original PDF must work on every matching route without cookies.
        // Also include the legacy draft and access_listing=unlisted variants.
        foreach ([2, 12, 14] as $unpublishedId) {
            $url = $origin . $base . '/index.php?page=gallery_ofp_pdf&id=' . $unpublishedId;
            $inline = ofp_http_request($url);
            $attachment = ofp_http_request($url . '&download=1');
            $head = ofp_http_request($url, 'HEAD');
            $legacy = ofp_http_request(
                $origin . $base . '/index.php?page=media&id=' . $unpublishedId . '&ofp=1'
            );
            ofp_http_assert($inline['status'] === 200 && $inline['body'] === $pdf
                && ofp_http_header_contains($inline, 'Content-Type', 'application/pdf')
                && ofp_http_header_contains($inline, 'Content-Disposition', 'inline'),
                'Unpublished direct-link OFP failed for an anonymous visitor: ' . $unpublishedId);
            ofp_http_assert($attachment['status'] === 200 && $attachment['body'] === $pdf
                && ofp_http_header_contains($attachment, 'Content-Disposition', 'attachment'),
                'Anonymous unpublished OFP download failed: ' . $unpublishedId);
            ofp_http_assert($head['status'] === 200 && $head['body'] === ''
                && ofp_http_header_contains($head, 'Content-Type', 'application/pdf'),
                'Anonymous unpublished OFP HEAD failed: ' . $unpublishedId);
            ofp_http_assert($legacy['status'] === 200 && $legacy['body'] === $pdf,
                'Old media?ofp=1 links must work for unpublished galleries: ' . $unpublishedId);
        }

        foreach ([3, 4, 5, 6, 7, 9, 10, 11, 13, 15, 999] as $id) {
            $denied = ofp_http_request($origin . $base . '/index.php?page=gallery_ofp_pdf&id=' . $id);
            ofp_http_assert($denied['status'] === 404
                && $denied['body'] === 'Flight plan unavailable.'
                && ofp_http_header_contains($denied, 'Content-Type', 'text/plain')
                && ofp_http_header_contains($denied, 'Cache-Control', 'no-store')
                && !str_contains($denied['body'], $root),
                'Unauthorized/missing/invalid PDF must return the identical opaque media 404: ' . $id);
        }

        $headDenied = ofp_http_request($origin . $base . '/index.php?page=gallery_ofp_pdf&id=3', 'HEAD');
        ofp_http_assert($headDenied['status'] === 404 && $headDenied['body'] === '',
            'Unauthorized HEAD must not return an HTML page or response body.');

        foreach ([2, 3] as $privateId) {
            $admin = ofp_http_request($origin . $base . '/index.php?page=gallery_ofp_pdf&id=' . $privateId,
                'GET', 'ofp_test_admin=1');
            ofp_http_assert($admin['status'] === 200 && $admin['body'] === $pdf,
                'Authenticated administrator must retain owner OFP access.');
            $preview = ofp_http_request(
                $origin . $base . '/index.php?page=gallery_ofp_pdf&id=' . $privateId . '&anonymous_preview=1',
                'GET', 'ofp_test_admin=1'
            );
            $expectedPreviewStatus = $privateId === 2 ? 200 : 404;
            ofp_http_assert($preview['status'] === $expectedPreviewStatus
                && ($expectedPreviewStatus !== 200 || $preview['body'] === $pdf),
                'Anonymous Admin preview must match ordinary direct-gallery access.');
        }

        foreach ([
            4 => 'ofp_test_password=1',
            5 => 'ofp_test_adult=1',
            11 => 'ofp_test_password=1',
            13 => 'ofp_test_adult=1',
        ] as $id => $cookie) {
            $authorized = ofp_http_request(
                $origin . $base . '/index.php?page=gallery_ofp_pdf&id=' . $id, 'GET', $cookie
            );
            ofp_http_assert($authorized['status'] === 200 && $authorized['body'] === $pdf,
                'The PDF must honor a valid visitor gallery access/NSFW grant: ' . $id);
        }
        $shared = ofp_http_request($origin . $base
            . '/index.php?page=gallery_ofp_pdf&id=10&share=fixture-allowed');
        ofp_http_assert($shared['status'] === 200 && $shared['body'] === $pdf,
            'An authorized share-token visit must retain PDF access.');
        $legacyShared = ofp_http_request($origin . $base
            . '/index.php?page=media&id=10&ofp=1&share=fixture-allowed');
        ofp_http_assert($legacyShared['status'] === 200 && $legacyShared['body'] === $pdf,
            'Legacy OFP routes must retain valid share-token access through the SEO guard.');
        $privateShare = ofp_http_request($origin . $base
            . '/index.php?page=gallery_ofp_pdf&id=15&share=fixture-allowed');
        ofp_http_assert($privateShare['status'] === 200 && $privateShare['body'] === $pdf,
            'Private gallery OFP may be read only with the same valid gallery share grant.');

        $parent = ofp_http_request($origin . $base . '/index.php?page=gallery_ofp_pdf&id=8');
        $child = ofp_http_request($origin . $base . '/index.php?page=gallery_ofp_pdf&id=9');
        ofp_http_assert($parent['status'] === 200 && $parent['body'] === $pdf && $child['status'] === 404,
            'The public source OFP must not grant access to its generated private child.');

        $post = ofp_http_request($origin . $base . '/index.php?page=gallery_ofp_pdf&id=1', 'POST');
        ofp_http_assert($post['status'] === 405
            && ofp_http_header_contains($post, 'Allow', 'GET, HEAD'),
            'PDF delivery must refuse mutation methods.');
    }

    echo "SimBrief OFP HTTP anonymous/root/subdirectory authorization: PASS\n";
} catch (Throwable $error) {
    $serverLog = is_file($log) ? (string) @file_get_contents($log) : '';
    throw new RuntimeException($error->getMessage() . "\nFixture server diagnostics:\n"
        . substr($serverLog, -3000), 0, $error);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    ofp_http_cleanup($root, $token);
}
