<?php

declare(strict_types=1);

/**
 * Regression contract for read-only media session concurrency.
 *
 * Public media routes need the PHP session while evaluating access policy, but
 * they must release the exclusive session lock before derivative generation or
 * long response streaming so the same visitor can paginate concurrently.
 */

$sourcePath = dirname(__DIR__) . '/app/controllers/public_media.php';
$source = file_get_contents($sourcePath);
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read public_media.php\n");
    exit(1);
}

/**
 * Fail the regression script with one precise contract message.
 */
function media_session_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

media_session_assert(
    str_contains($source, 'function cms_release_public_media_session_lock(): void'),
    'Public media session-release helper is missing.'
);
media_session_assert(
    str_contains($source, 'session_status() === PHP_SESSION_ACTIVE') && str_contains($source, 'session_write_close();'),
    'Public media session-release helper must close only an active PHP session.'
);

/**
 * Return one controller function body slice for source-order assertions.
 */
function media_session_function_source(string $source, string $functionName, ?string $nextFunctionName): string
{
    $start = strpos($source, 'function ' . $functionName . '(');
    if ($start === false) {
        return '';
    }
    if ($nextFunctionName === null) {
        return substr($source, $start);
    }
    $end = strpos($source, 'function ' . $nextFunctionName . '(', $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

$contracts = [
    ['cms_thumb', 'cms_public_thumb', 'cms_resolve_thumbnail_response_file'],
    ['cms_public_thumb', 'cms_public_media', 'cms_resolve_thumbnail_response_file'],
    ['cms_public_media', 'cms_gallery_cover_asset', 'image_public_display_file'],
    ['cms_gallery_cover_asset', 'cms_gallery_branding_asset', 'extension_loaded'],
    ['cms_gallery_branding_asset', 'cms_media', 'send_conditional_file_headers'],
    ['cms_media', 'cms_robots_txt', 'image_public_display_file'],
];

foreach ($contracts as [$functionName, $nextFunctionName, $longWorkMarker]) {
    $functionSource = media_session_function_source($source, $functionName, $nextFunctionName);
    media_session_assert($functionSource !== '', 'Missing media controller function: ' . $functionName);
    $releasePosition = strpos($functionSource, 'cms_release_public_media_session_lock();');
    $workPosition = strpos($functionSource, $longWorkMarker);
    media_session_assert($releasePosition !== false, $functionName . ' must release the PHP session lock.');
    media_session_assert($workPosition !== false, $functionName . ' long-work marker is missing from the regression contract.');
    media_session_assert($releasePosition < $workPosition, $functionName . ' must release the session before long media work begins.');
}

fwrite(STDOUT, "public media session release contract: OK\n");
