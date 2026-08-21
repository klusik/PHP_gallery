<?php

declare(strict_types=1);

/**
 * Regression contract for read-only media session concurrency.
 *
 * Public media routes complete session-dependent request initialization first,
 * then release the exclusive PHP session lock before authorization/path/database
 * resolution and long response streaming. Controller-level release calls remain
 * as defensive fallbacks for direct/legacy dispatch paths.
 */

$sourcePath = dirname(__DIR__) . '/app/controllers/public_media.php';
$source = file_get_contents($sourcePath);
$bootstrapSource = file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
$sessionSource = file_get_contents(dirname(__DIR__) . '/app/bootstrap/session.php');
$routingSource = file_get_contents(dirname(__DIR__) . '/app/bootstrap/routing.php');
$maintenanceSource = file_get_contents(dirname(__DIR__) . '/app/bootstrap/maintenance.php');
$requestSource = file_get_contents(dirname(__DIR__) . '/app/bootstrap/request.php');
if (!is_string($source) || !is_string($bootstrapSource) || !is_string($sessionSource) || !is_string($routingSource) || !is_string($maintenanceSource) || !is_string($requestSource)) {
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
    str_contains($routingSource, 'function cms_route_is_read_only_media_asset(string $page): bool')
        && str_contains($routingSource, "'public_thumb'")
        && str_contains($routingSource, "'public_media'")
        && str_contains($routingSource, "'gallery_cover_asset'")
        && str_contains($routingSource, "'gallery_branding_asset'"),
    'Read-only media route classification must cover protected thumbnail/media asset endpoints.'
);
$initializePosition = strpos($bootstrapSource, '$page = cms_initialize_request();');
$earlyReleasePosition = strpos($bootstrapSource, 'cms_release_read_only_media_session_lock($page);');
$schemaPrimePosition = strpos($bootstrapSource, 'cms_prime_read_only_media_schema_cache($page);');
$maintenancePosition = strpos($bootstrapSource, 'cms_run_request_maintenance($page);');
media_session_assert(
    $initializePosition !== false && $earlyReleasePosition !== false && $schemaPrimePosition !== false && $maintenancePosition !== false
        && $initializePosition < $earlyReleasePosition
        && $earlyReleasePosition < $schemaPrimePosition
        && $schemaPrimePosition < $maintenancePosition,
    'Media session release and schema priming must occur after request initialization but before maintenance/dispatch.'
);
media_session_assert(
    str_contains($sessionSource, 'function cms_release_read_only_media_session_lock(string $page): bool')
        && str_contains($sessionSource, 'session_write_close();')
        && str_contains($sessionSource, 'auth_remember_cookie_name')
        && str_contains($sessionSource, 'current_user();'),
    'Early media session release must preserve durable admin-login restoration before closing the writable session.'
);
$maintenanceSkip = strpos($maintenanceSource, 'cms_route_is_read_only_media_asset($page)');
$autoupdate = strpos($maintenanceSource, 'application_autoupdate_maybe_run();');
media_session_assert(
    $maintenanceSkip !== false && $autoupdate !== false && $maintenanceSkip < $autoupdate,
    'Read-only media requests must skip request-triggered updater/maintenance work before it begins.'
);
media_session_assert(
    str_contains($requestSource, 'function cms_prime_read_only_media_schema_cache(string $page): void')
        && str_contains($requestSource, 'schema_inspection_prime_table_snapshots'),
    'Read-only media requests must prime the bounded schema snapshot after releasing the session lock.'
);

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
