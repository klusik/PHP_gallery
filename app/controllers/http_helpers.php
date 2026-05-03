<?php

declare(strict_types=1);

/**
 * HTTP controller helper model.
 * 
 * This module contains small response helpers shared by public and admin controllers, such as conditional file headers and the public back-to-top control renderer.
 */

function send_conditional_file_headers(string $path, string $cacheControl): void
{
    $mtime = (int) filemtime($path);
    $size = (int) filesize($path);
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: ' . $cacheControl);

    $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $clientModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if ($clientEtag === $etag || ($clientModifiedSince !== '' && (int) strtotime($clientModifiedSince) >= $mtime)) {
        http_response_code(304);
        exit;
    }
}

function render_back_to_top_button(): void
{
    echo '<button type="button" class="back-to-top-button" data-back-to-top-button hidden aria-label="Go back to top" title="Go back to top"><span aria-hidden="true">↑</span><span>Top</span></button>';
}

function cms_not_found(): void
{
    http_response_code(404);
    render_header('Not found');
    echo '<section class="panel"><h1>Not found</h1><p>The requested page was not found.</p></section>';
    render_footer();
}

