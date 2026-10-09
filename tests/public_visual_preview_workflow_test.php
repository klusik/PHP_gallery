<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_visual_preview_workflow_test.php
 * Module Type: Disposable Workflow Regression Test
 * Purpose: Exercise the visual CSS preview through real public HTTP routes and fixture persistence.
 * Responsibilities:
 *   - Verify preview principal, method, route, gallery and media authorization boundaries.
 *   - Verify same-origin Referer context, stylesheet inspection refusals, and private cache responses.
 *   - Prove preview reads do not write telemetry, thumbnail metadata or gallery assets.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

/**
 * Read the owner-guarded workflow snapshot through the disposable loopback server.
 * @param string $origin Literal loopback origin recorded by the fixture runner.
 * @param string $token Fixture ownership token used to select the private snapshot route.
 * @param ?string $diagnostic Optional bounded transport/snapshot status summary for failure output; never contains response data.
 * @return array{telemetry_events:int,telemetry_sessions:int,telemetry_hourly_metrics:int,thumbnail_variants:list<array{id:int|string,image_id:int|string,size_px:int|string,format:string,derivative_version:int|string,width:int|string|null,height:int|string|null,file_size:int|string|null,modified_at:string|null,status:string,status_reason:string,checked_at:string,created_at:string,updated_at:string}>,image_metadata:list<array{id:int|string,thumbnail_metadata_refreshed_at:string|null}>,gallery_files:array<string,array{size:int,modified:int,sha256:string}>} Snapshot of fixture telemetry, canonical compact thumbnail metadata, and gallery files.
 */
function visualPreviewWorkflowSnapshot(string $origin, string $token, ?string &$diagnostic = null): array
{
    $diagnostic = 'snapshot=transport_pending';
    $url = $origin . '/__workflow_' . $token . '/preview-state';
    $handle = curl_init($url);
    check($handle instanceof CurlHandle, 'Cannot open the isolated preview state endpoint.');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $failure = is_string($body) ? json_decode($body, true) : null;
    $failureSummary = '';
    $failureType = is_array($failure) ? (string) ($failure['type'] ?? '') : '';
    $failureTypeParts = explode('\\', $failureType);
    $failureTypeIsSafe = $failureType !== '' && count($failureTypeParts) <= 8;
    foreach ($failureTypeParts as $failureTypePart) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $failureTypePart) !== 1) {
            $failureTypeIsSafe = false;
        }
    }
    if (is_array($failure) && ($failure['error'] ?? null) === 'snapshot_failed'
        && preg_match('/^[a-z_]{1,40}$/D', (string) ($failure['stage'] ?? '')) === 1
        && $failureTypeIsSafe
        && preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', (string) ($failure['source'] ?? '')) === 1
        && is_int($failure['line'] ?? null) && $failure['line'] > 0) {
        $failureSummary = ';snapshot_failure=' . $failure['stage'] . ':' . $failure['type']
            . ':' . $failure['source'] . ':' . $failure['line'];
    }
    $diagnostic = 'snapshot_http=' . $status . ';snapshot_body=' . (is_string($body) ? 'received' : 'transport_failed')
        . $failureSummary;
    check(is_string($body) && $status === 200,
        'The owned preview state endpoint did not return a successful response.');
    $state = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $complete = is_array($state) && isset($state['telemetry_events'], $state['telemetry_sessions'],
        $state['telemetry_hourly_metrics'], $state['thumbnail_variants'], $state['image_metadata'], $state['gallery_files']);
    $diagnostic = 'snapshot_http=' . $status . ';snapshot_json=' . ($complete ? 'complete' : 'incomplete');
    check($complete, 'The preview state endpoint returned an incomplete persistence snapshot.');
    return $state;
}

/**
 * Read the owner-guarded exception classification for one safe public error reference.
 *
 * @param string $origin Literal loopback origin recorded by the fixture runner.
 * @param string $token Fixture ownership token for the diagnostic route.
 * @param string $reference Exact uppercase public error reference emitted by the application's safe 500 page.
 * @return array{available:false}|array{available:true,type:string,source:string,line:int} Bounded exception type and source location, or unavailable when no matching record exists.
 *   Namespace separators are represented as dots; fatal PHP errors use php-error-N.
 */
function visualPreviewWorkflowFailureSummary(string $origin, string $token, string $reference): array
{
    if (preg_match('/^[A-F0-9]{16}$/D', $reference) !== 1) {
        return ['available' => false];
    }
    $handle = curl_init($origin . '/__workflow_' . $token . '/preview-error?reference=' . rawurlencode($reference));
    if (!$handle instanceof CurlHandle) {
        return ['available' => false];
    }
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || $status !== 200) {
        return ['available' => false];
    }
    $summary = json_decode($body, true);
    if (!is_array($summary) || ($summary['available'] ?? null) !== true
        || !is_string($summary['type'] ?? null) || strlen($summary['type']) > 128
        || preg_match('/^(?:php-error-[0-9]+|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)$/D', $summary['type']) !== 1
        || !is_string($summary['source'] ?? null)
        || preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $summary['source']) !== 1
        || !is_int($summary['line'] ?? null) || $summary['line'] < 1) {
        return ['available' => false];
    }
    return ['available' => true, 'type' => $summary['type'], 'source' => $summary['source'], 'line' => $summary['line']];
}

/**
 * Require a visual-preview response to carry the private no-store cache policy.
 * @param array{status:int,body:string,headers:array<string,string>,json:array<string,mixed>|null} $response Actual application HTTP response.
 * @param string $label Short route description used in assertion output.
 * @return void Throws when a preview response can be shared or stored by a cache.
 */
function visualPreviewWorkflowAssertNoStore(array $response, string $label): void
{
    $cacheControl = strtolower((string) ($response['headers']['cache-control'] ?? ''));
    check(str_contains($cacheControl, 'private') && str_contains($cacheControl, 'no-store'),
        $label . ' must be private and non-storable.');
}

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " visual preview real HTTP coverage requires the disposable database runner\n";
    exit($required ? 1 : 0);
}

$stage = 'fixture validation';
$stageDiagnostic = '';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $admin = new Http($origin);
    $anonymous = new Http($origin);
    $admin->login($seed);

    $installedCssPath = $directory . '/public/assets/custom.css';
    $overridesCssPath = $directory . '/public/assets/custom-overrides.css';
    $cssAssetState = static function (string $path): array {
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return ['kind' => 'unsafe'];
        }
        if (!file_exists($path)) {
            return ['kind' => 'absent'];
        }
        $contents = @file_get_contents($path);
        $permissions = @fileperms($path);
        $modified = @filemtime($path);
        if (!is_string($contents) || !is_int($permissions) || !is_int($modified)) {
            return ['kind' => 'unsafe'];
        }
        return [
            'kind' => 'file',
            'contents' => $contents,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'mode' => $permissions & 0777,
            'modified' => $modified,
        ];
    };
    $cssAssetStateLabel = static function (array $state): string {
        if (($state['kind'] ?? '') === 'absent') {
            return 'absent';
        }
        if (($state['kind'] ?? '') === 'file' && ($state['size'] ?? -1) === 0) {
            return 'empty';
        }
        return ($state['kind'] ?? '') === 'file' ? 'nonempty' : 'unsafe';
    };
    $initialInstalledCssState = $cssAssetState($installedCssPath);
    $initialOverridesCssState = $cssAssetState($overridesCssPath);

    $stage = 'public preview route and principal boundary';
    $stageDiagnostic = '';
    $stageDiagnostic = 'installed_css_initial=' . $cssAssetStateLabel($initialInstalledCssState)
        . ';overrides_css_initial=' . $cssAssetStateLabel($initialOverridesCssState);
    // Earlier workflow cases share this owned app copy and may leave reviewed CSS; retain its full exact state for no-write checks and cleanup.
    check(($initialInstalledCssState['kind'] ?? '') === 'absent'
        && (($initialOverridesCssState['kind'] ?? '') === 'absent'
            || ($initialOverridesCssState['kind'] ?? '') === 'file'),
        'The isolated preview fixture must start without installed CSS and with a safely snapshotable saved-override state.');
    $before = visualPreviewWorkflowSnapshot($origin, $token, $stageDiagnostic);
    $stageDiagnostic = 'home_request=pending';
    $home = $admin->request('/index.php?page=home&preview=visual');
    $stageDiagnostic = 'home_http=' . $home['status'] . ';home_seed=' . (str_contains($home['body'], 'Workflow seed') ? '1' : '0');
    check($home['status'] === 200 && str_contains($home['body'], 'Workflow seed'),
        'Administrator preview did not render the actual public home page.');
    $stageDiagnostic = 'home_redirect=' . (isset($home['headers']['location']) ? '1' : '0');
    check(!isset($home['headers']['location']), 'Administrator home preview must stay on its protected route without redirecting.');
    $homeCacheControl = strtolower((string) ($home['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'home_cache_private=' . (str_contains($homeCacheControl, 'private') ? '1' : '0')
        . ';home_cache_no_store=' . (str_contains($homeCacheControl, 'no-store') ? '1' : '0');
    visualPreviewWorkflowAssertNoStore($home, 'Administrator home preview');
    $stageDiagnostic = 'gallery_request=pending';
    $gallery = $admin->request('/index.php?page=gallery&public_path=seed&preview=visual');
    $stageDiagnostic = 'gallery_http=' . $gallery['status'] . ';gallery_seed=' . (str_contains($gallery['body'], 'Workflow seed') ? '1' : '0');
    check($gallery['status'] === 200 && str_contains($gallery['body'], 'Workflow seed'),
        'Administrator preview did not render the actual public gallery page.');
    $stageDiagnostic = 'gallery_redirect=' . (isset($gallery['headers']['location']) ? '1' : '0');
    check(!isset($gallery['headers']['location']), 'Administrator gallery preview must stay on its protected route without redirecting.');
    $galleryCacheControl = strtolower((string) ($gallery['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'gallery_cache_private=' . (str_contains($galleryCacheControl, 'private') ? '1' : '0')
        . ';gallery_cache_no_store=' . (str_contains($galleryCacheControl, 'no-store') ? '1' : '0');
    visualPreviewWorkflowAssertNoStore($gallery, 'Administrator gallery preview');
    $stageDiagnostic = 'anonymous_denial_request=pending';
    $deniedAnonymous = $anonymous->request('/index.php?page=home&preview=visual');
    $denialFailureSummary = '';
    if ($deniedAnonymous['status'] === 500
        && preg_match('/Reference:\s*([A-F0-9]{16})/', $deniedAnonymous['body'], $referenceMatch) === 1) {
        $errorSummary = visualPreviewWorkflowFailureSummary($origin, $token, $referenceMatch[1]);
        if (!empty($errorSummary['available'])) {
            $denialFailureSummary = ';anonymous_failure=' . $errorSummary['type'] . ':'
                . $errorSummary['source'] . ':' . $errorSummary['line'];
        }
    }
    $stageDiagnostic = 'anonymous_denial_http=' . $deniedAnonymous['status'] . $denialFailureSummary;
    check($deniedAnonymous['status'] === 404, 'A fresh visitor must not open an administrator preview route.');
    check(str_contains($deniedAnonymous['body'], '<section class="panel"><h1>'),
        'A denied preview request must render the canonical public not-found panel.');
    check(!str_contains($deniedAnonymous['body'], 'data-theme-background-visual-editor-target')
        && !str_contains($deniedAnonymous['body'], 'data-theme-background-visible-owner')
        && !str_contains($deniedAnonymous['body'], 'data-theme-background-visible-mode')
        && !str_contains($deniedAnonymous['body'], 'data-theme-background-visible-url'),
        'A denied preview response must not render privileged visual-editor background metadata.');
    $anonymousCacheControl = strtolower((string) ($deniedAnonymous['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'anonymous_denial_cache_private=' . (str_contains($anonymousCacheControl, 'private') ? '1' : '0')
        . ';anonymous_denial_cache_no_store=' . (str_contains($anonymousCacheControl, 'no-store') ? '1' : '0');
    visualPreviewWorkflowAssertNoStore($deniedAnonymous, 'Anonymous preview denial');
    $stageDiagnostic = 'admin_route_denial_request=pending';
    $deniedAdminRoute = $admin->request('/index.php?page=admin_theme&preview=visual');
    $stageDiagnostic = 'admin_route_denial_http=' . $deniedAdminRoute['status'];
    check($deniedAdminRoute['status'] === 404, 'The preview marker must not admit an administrator mutation/settings route.');
    $adminRouteCacheControl = strtolower((string) ($deniedAdminRoute['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'admin_route_cache_private=' . (str_contains($adminRouteCacheControl, 'private') ? '1' : '0')
        . ';admin_route_cache_no_store=' . (str_contains($adminRouteCacheControl, 'no-store') ? '1' : '0');
    visualPreviewWorkflowAssertNoStore($deniedAdminRoute, 'Non-public preview route denial');
    $stageDiagnostic = 'head_request=pending';
    $head = $admin->request('/index.php?page=home&preview=visual', null, false, 'HEAD');
    $stageDiagnostic = 'head_http=' . $head['status'] . ';head_body_empty=' . ($head['body'] === '' ? '1' : '0');
    check($head['status'] === 200 && $head['body'] === '', 'Authorized preview HEAD must return the GET status without a body.');
    $headCacheControl = strtolower((string) ($head['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'head_cache_private=' . (str_contains($headCacheControl, 'private') ? '1' : '0')
        . ';head_cache_no_store=' . (str_contains($headCacheControl, 'no-store') ? '1' : '0');
    visualPreviewWorkflowAssertNoStore($head, 'Preview HEAD');
    $stageDiagnostic = 'post_request=pending';
    $post = $admin->request('/index.php?page=home&preview=visual', [
        'css_override_action' => 'save', 'css_override_text' => 'body{color:red}', 'css_override_revision' => 'fixture',
    ]);
    $postAllow = strtoupper((string) ($post['headers']['allow'] ?? ''));
    $stageDiagnostic = 'post_http=' . $post['status'] . ';post_allow_get=' . (str_contains($postAllow, 'GET') ? '1' : '0')
        . ';post_allow_head=' . (str_contains($postAllow, 'HEAD') ? '1' : '0');
    check($post['status'] === 405 && str_contains(strtoupper((string) ($post['headers']['allow'] ?? '')), 'GET')
        && str_contains(strtoupper((string) ($post['headers']['allow'] ?? '')), 'HEAD'),
        'Preview POST must fail with 405 and advertise only its read methods.');
    $postCacheControl = strtolower((string) ($post['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'post_cache_private=' . (str_contains($postCacheControl, 'private') ? '1' : '0')
        . ';post_cache_no_store=' . (str_contains($postCacheControl, 'no-store') ? '1' : '0');
    visualPreviewWorkflowAssertNoStore($post, 'Preview POST denial');
    echo "PASS visual preview renders real home/gallery routes and rejects anonymous, admin-route, and mutation requests\n";

    $stage = 'anonymous gallery and media access';
    $stageDiagnostic = '';
    $anonymousRoot = $admin->request('/index.php?page=gallery&public_path=seed&preview=visual&view_as=anonymous');
    $stageDiagnostic = 'gallery_http=' . $anonymousRoot['status']
        . ';gallery_seed=' . (str_contains($anonymousRoot['body'], 'Workflow seed') ? '1' : '0');
    $galleryCacheControl = strtolower((string) ($anonymousRoot['headers']['cache-control'] ?? ''));
    $stageDiagnostic .= ';gallery_private=' . (str_contains($galleryCacheControl, 'private') ? '1' : '0')
        . ';gallery_no_store=' . (str_contains($galleryCacheControl, 'no-store') ? '1' : '0');
    check($anonymousRoot['status'] === 200 && str_contains($anonymousRoot['body'], 'Workflow seed'),
        'Signed-in anonymous simulation must render a genuinely public gallery.');
    visualPreviewWorkflowAssertNoStore($anonymousRoot, 'Anonymous audience gallery preview');
    $image = $pdo->prepare('SELECT id, relative_path FROM images WHERE gallery_id = ? ORDER BY id LIMIT 1');
    $image->execute([(int) $seed['root_id']]);
    $publicImage = $image->fetch(PDO::FETCH_ASSOC);
    check(is_array($publicImage), 'The real public gallery fixture has no image.');
    $media = $admin->request('/index.php?page=media&id=' . (int) $publicImage['id'] . '&preview=visual&view_as=anonymous');
    $mediaCacheControl = strtolower((string) ($media['headers']['cache-control'] ?? ''));
    $mediaContentType = strtolower((string) ($media['headers']['content-type'] ?? ''));
    $mediaReference = '';
    if ($media['status'] === 500 && preg_match('/Reference:\s*([A-F0-9]{16})/', $media['body'], $mediaReferenceMatch) === 1) {
        $mediaReference = $mediaReferenceMatch[1];
    }
    $mediaFailure = $mediaReference === '' ? ['available' => false]
        : visualPreviewWorkflowFailureSummary($origin, $token, $mediaReference);
    $stageDiagnostic = 'media_http=' . $media['status'] . ';media_has_bytes=' . ($media['body'] !== '' ? '1' : '0')
        . ';media_image_type=' . (str_starts_with($mediaContentType, 'image/') ? '1' : '0')
        . ';media_private=' . (str_contains($mediaCacheControl, 'private') ? '1' : '0')
        . ';media_no_store=' . (str_contains($mediaCacheControl, 'no-store') ? '1' : '0')
        . ($mediaFailure['available'] ? ';media_failure=' . $mediaFailure['type'] . ':' . $mediaFailure['source'] . ':' . $mediaFailure['line'] : '');
    check($media['status'] === 200 && $media['body'] !== '' && str_starts_with($mediaContentType, 'image/'),
        'Anonymous simulation must authorize a public original image response.');
    visualPreviewWorkflowAssertNoStore($media, 'Anonymous audience original media preview');
    $galleryDocument = new DOMDocument();
    check(@$galleryDocument->loadHTML($anonymousRoot['body']), 'The actual anonymous gallery response is not parseable HTML.');
    $photoCard = (new DOMXPath($galleryDocument))
        ->query('//article[@data-public-photo-order-item][@data-full-src][.//a[contains(concat(" ", normalize-space(@class), " "), " image-preview-link ")]//picture/img[@src]]')
        ->item(0);
    $thumbnailElement = $photoCard instanceof DOMElement
        ? (new DOMXPath($galleryDocument))->query('.//a[contains(concat(" ", normalize-space(@class), " "), " image-preview-link ")]//picture/img[@src]', $photoCard)->item(0)
        : null;
    $stageDiagnostic = 'gallery_photo_card_image=' . ($photoCard instanceof DOMElement && $thumbnailElement instanceof DOMElement ? '1' : '0');
    check($photoCard instanceof DOMElement && $thumbnailElement instanceof DOMElement,
        'The actual gallery response did not render a semantic photo-card image and original-media URL.');
    $originParts = parse_url($origin);
    check(is_array($originParts) && in_array(strtolower((string) ($originParts['scheme'] ?? '')), ['http', 'https'], true)
        && is_string($originParts['host'] ?? null), 'The owned fixture origin is not a valid HTTP(S) origin.');
    $renderedAssetRoute = static function (string $url) use ($originParts): string {
        $parts = parse_url($url);
        check(is_array($parts)
            && !array_key_exists('user', $parts) && !array_key_exists('pass', $parts)
            && !array_key_exists('fragment', $parts),
            'A rendered photo asset URL is not a supported same-origin URI.');
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $originScheme = strtolower((string) $originParts['scheme']);
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $originPort = (int) ($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
            check(in_array($scheme, ['http', 'https'], true) && $scheme === $originScheme
                && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) $originParts['host'])
                && $port === $originPort,
                'A rendered photo asset URL is not same-origin.');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            check(!isset($parts['host']) || isset($parts['scheme']),
                'A query-only photo asset URI must be relative to the current origin.');
            $path = '/index.php';
        }
        return $path . ((string) ($parts['query'] ?? '') === '' ? '' : '?' . $parts['query']);
    };
    $publicMediaUrl = html_entity_decode($photoCard->getAttribute('data-full-src'), ENT_QUOTES | ENT_HTML5);
    $renderedPublicMediaRoute = $renderedAssetRoute($publicMediaUrl);
    $publicMediaParts = parse_url($publicMediaUrl);
    $stageDiagnostic = 'original_media_uri=' . (is_array($publicMediaParts) ? 'valid' : 'invalid')
        . ';original_media_path_present=' . (is_array($publicMediaParts) && is_string($publicMediaParts['path'] ?? null) ? '1' : '0');
    check(is_array($publicMediaParts),
        'The rendered photo-card original-media URL is not a valid URI.');
    parse_str((string) ($publicMediaParts['query'] ?? ''), $publicMediaQuery);
    $emittedMediaQuery = $publicMediaQuery;
    $publicMediaPath = (string) ($publicMediaParts['path'] ?? '');
    $publicMediaSourceRoute = 'query';
    if (($emittedMediaQuery['page'] ?? null) === 'media') {
        check(is_string($emittedMediaQuery['id'] ?? null) && ctype_digit($emittedMediaQuery['id'])
            && (int) $emittedMediaQuery['id'] > 0,
            'The rendered legacy media route must carry a positive image ID.');
        $publicMediaRoute = '/index.php?' . http_build_query($emittedMediaQuery);
        $publicMediaSourceRoute = 'query_media';
    } elseif (($emittedMediaQuery['page'] ?? null) === 'public_media') {
        check(is_string($emittedMediaQuery['public_path'] ?? null) && $emittedMediaQuery['public_path'] !== '',
            'The rendered public-media route must carry its public path.');
        $publicMediaRoute = '/index.php?' . http_build_query($emittedMediaQuery);
        $publicMediaSourceRoute = 'query_public_media';
    } else {
        $galleryRouteOffset = strrpos($publicMediaPath, '/gallery/');
        $cleanMediaSuffix = $galleryRouteOffset === false ? '' : substr($publicMediaPath, $galleryRouteOffset + strlen('/gallery/'));
        check(preg_match('~^((?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+(?:/(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+)*)/media$~D',
            $cleanMediaSuffix, $cleanMediaMatch) === 1,
            'The rendered photo-card original-media route is neither public_media query routing nor canonical clean gallery/media routing.');
        $publicPath = rawurldecode($cleanMediaMatch[1]);
        $publicPathSegments = explode('/', $publicPath);
        check($publicPath !== '' && !array_filter($publicPathSegments, static fn (string $segment): bool =>
            $segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '\\')),
            'The rendered clean original-media path is not a valid relative public path.');
        $publicMediaQuery = $emittedMediaQuery;
        $publicMediaSourceRoute = 'clean';
        $publicMediaRoute = $renderedPublicMediaRoute;
    }
    $stageDiagnostic = 'original_media_route_source=' . $publicMediaSourceRoute
        . ';original_media_fixture_route=' . (str_starts_with($publicMediaRoute, '/index.php?') ? 'front_controller' : 'clean_asset')
        . ';original_media_preview_marker=' . (($emittedMediaQuery['preview'] ?? '') === 'visual' ? '1' : '0')
        . ';original_media_audience_marker=' . (($emittedMediaQuery['view_as'] ?? '') === 'anonymous' ? '1' : '0')
        . ';original_media_page=' . (in_array($emittedMediaQuery['page'] ?? null, ['media', 'public_media'], true) ? (string) $emittedMediaQuery['page'] : 'clean');
    check(($publicMediaQuery['preview'] ?? '') === 'visual' && ($publicMediaQuery['view_as'] ?? '') === 'anonymous',
        'The rendered photo-card original-media URL did not retain the protected preview audience contract.');
    $publicMedia = $admin->request($publicMediaRoute);
    $publicMediaCacheControl = strtolower((string) ($publicMedia['headers']['cache-control'] ?? ''));
    $publicMediaContentType = strtolower((string) ($publicMedia['headers']['content-type'] ?? ''));
    $publicMediaReference = '';
    if ($publicMedia['status'] === 500 && preg_match('/Reference:\s*([A-F0-9]{16})/', $publicMedia['body'], $publicMediaReferenceMatch) === 1) {
        $publicMediaReference = $publicMediaReferenceMatch[1];
    }
    $publicMediaFailure = $publicMediaReference === '' ? ['available' => false]
        : visualPreviewWorkflowFailureSummary($origin, $token, $publicMediaReference);
    $stageDiagnostic = 'public_media_http=' . $publicMedia['status'] . ';public_media_has_bytes=' . ($publicMedia['body'] !== '' ? '1' : '0')
        . ';public_media_image_type=' . (str_starts_with($publicMediaContentType, 'image/') ? '1' : '0')
        . ';public_media_private=' . (str_contains($publicMediaCacheControl, 'private') ? '1' : '0')
        . ';public_media_no_store=' . (str_contains($publicMediaCacheControl, 'no-store') ? '1' : '0')
        . ($publicMediaFailure['available'] ? ';public_media_failure=' . $publicMediaFailure['type'] . ':' . $publicMediaFailure['source'] . ':' . $publicMediaFailure['line'] : '');
    check($publicMedia['status'] === 200 && $publicMedia['body'] !== '' && str_starts_with($publicMediaContentType, 'image/'),
        'The public media URL emitted by the real gallery card must serve the authorized source image.');
    visualPreviewWorkflowAssertNoStore($publicMedia, 'Anonymous audience public media preview');

    $thumbnailUrl = html_entity_decode($thumbnailElement->getAttribute('src'), ENT_QUOTES | ENT_HTML5);
    $thumbnailParts = parse_url($thumbnailUrl);
    $thumbRoute = $renderedAssetRoute($thumbnailUrl);
    parse_str((string) ($thumbnailParts['query'] ?? ''), $thumbnailQuery);
    $stageDiagnostic = 'thumbnail_preview_marker=' . (($thumbnailQuery['preview'] ?? '') === 'visual' ? '1' : '0')
        . ';thumbnail_audience_marker=' . (($thumbnailQuery['view_as'] ?? '') === 'anonymous' ? '1' : '0');
    check(($thumbnailQuery['preview'] ?? '') === 'visual' && ($thumbnailQuery['view_as'] ?? '') === 'anonymous',
        'Rendered public thumbnail URL did not retain the protected preview audience contract.');
    $thumbnailPage = in_array(($thumbnailQuery['page'] ?? null), ['thumb', 'public_thumb', 'media', 'public_media'], true)
        ? (string) $thumbnailQuery['page'] : (($thumbnailQuery['page'] ?? null) === null ? 'clean' : 'other');
    $thumbnailRawPath = (string) ($thumbnailParts['path'] ?? '');
    $thumbnailRoutePath = match ($thumbnailRawPath) {
        '', '/index.php' => 'front_controller',
        default => str_starts_with($thumbnailRawPath, '/') ? 'clean_asset' : 'relative',
    };
    $thumbnailSizeValid = is_string($thumbnailQuery['size'] ?? null) && ctype_digit($thumbnailQuery['size'])
        && (int) $thumbnailQuery['size'] > 0;
    $thumbnailFormatValid = in_array(($thumbnailQuery['format'] ?? null), ['jpg', 'webp'], true);
    $thumbnailIdentityValid = in_array($thumbnailPage, ['public_thumb', 'public_media'], true)
        ? is_string($thumbnailQuery['public_path'] ?? null) && $thumbnailQuery['public_path'] !== ''
        : (in_array($thumbnailPage, ['thumb', 'media'], true) && is_string($thumbnailQuery['id'] ?? null)
            && ctype_digit($thumbnailQuery['id']) && (int) $thumbnailQuery['id'] > 0);
    $thumbnailCleanRouteValid = preg_match('~^/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/)*gallery/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/){2,}thumb-[1-9][0-9]*\.(?:jpg|webp)$~iD',
        $thumbnailRawPath) === 1;
    $thumbnailCleanMediaFallbackValid = preg_match('~^/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/)*gallery/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/){2,}media$~iD',
        $thumbnailRawPath) === 1;
    $thumbnailSegments = explode('/', trim($thumbnailRawPath, '/'));
    $thumbnailDecodedSegments = array_map('rawurldecode', $thumbnailSegments);
    $thumbnailUnsafeSegment = count(array_filter($thumbnailDecodedSegments, static fn (string $segment): bool =>
        $segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1)) > 0;
    $thumbnailPercentState = !str_contains($thumbnailRawPath, '%') ? 'none'
        : (preg_match('/%(?![A-Fa-f0-9]{2})/', $thumbnailRawPath) === 1 ? 'malformed' : 'encoded');
    $thumbnailFirstSegment = $thumbnailSegments[0] ?? '';
    $thumbnailPrefixKind = $thumbnailFirstSegment === 'gallery' ? 'gallery'
        : ($thumbnailFirstSegment === 'index.php' ? 'index' : 'other');
    $stageDiagnostic = 'thumbnail_page=' . $thumbnailPage . ';thumbnail_path=' . $thumbnailRoutePath
        . ';thumbnail_size=' . ($thumbnailSizeValid ? '1' : '0')
        . ';thumbnail_format=' . ($thumbnailFormatValid ? '1' : '0')
        . ';thumbnail_identity=' . ($thumbnailIdentityValid ? '1' : '0')
        . ';gallery_segment=' . (preg_match('~(?:^|/)gallery(?:/|$)~i', $thumbnailRawPath) === 1 ? '1' : '0')
        . ';thumb_suffix=' . (preg_match('~thumb-[1-9][0-9]*\.(?:jpg|webp)$~iD', $thumbnailRawPath) === 1 ? '1' : '0')
        . ';media_fallback=' . (preg_match('~/media$~iD', $thumbnailRawPath) === 1 ? '1' : '0')
        . ';trailing_slash=' . (str_ends_with($thumbnailRawPath, '/') ? '1' : '0')
        . ';empty_segment=' . (preg_match('~//~', $thumbnailRawPath) === 1 ? '1' : '0')
        . ';percent=' . $thumbnailPercentState . ';unsafe=' . ($thumbnailUnsafeSegment ? '1' : '0')
        . ';segments=' . min(99, count($thumbnailSegments)) . ';prefix=' . $thumbnailPrefixKind
        . ';thumbnail_clean_path=' . ($thumbnailCleanRouteValid ? '1' : '0')
        . ';thumbnail_clean_media_fallback=' . ($thumbnailCleanMediaFallbackValid ? '1' : '0');
    $thumbnailIsMediaFallback = false;
    if (in_array(($thumbnailQuery['page'] ?? null), ['thumb', 'public_thumb'], true)) {
        check(in_array((string) ($thumbnailParts['path'] ?? ''), ['', '/index.php'], true),
            'A query-mode thumbnail route must use the application front controller.');
        check(is_string($thumbnailQuery['size'] ?? null) && ctype_digit($thumbnailQuery['size'])
            && (int) $thumbnailQuery['size'] > 0
            && in_array(($thumbnailQuery['format'] ?? null), ['jpg', 'webp'], true),
            'A query-mode thumbnail route must carry a supported positive size and format.');
        if (($thumbnailQuery['page'] ?? null) === 'public_thumb') {
            check(is_string($thumbnailQuery['public_path'] ?? null) && $thumbnailQuery['public_path'] !== '',
                'The public thumbnail route must carry its public path.');
            $stageDiagnostic = 'thumbnail_route=query_public_thumb';
        } else {
            check(is_string($thumbnailQuery['id'] ?? null) && ctype_digit($thumbnailQuery['id'])
                && (int) $thumbnailQuery['id'] > 0,
                'The legacy thumbnail route must carry a positive image ID.');
            $stageDiagnostic = 'thumbnail_route=query_legacy_thumb';
        }
        $thumbRoute = '/index.php?' . (string) ($thumbnailParts['query'] ?? '');
    } elseif (in_array(($thumbnailQuery['page'] ?? null), ['media', 'public_media'], true)) {
        $thumbnailIsMediaFallback = true;
        check(in_array((string) ($thumbnailParts['path'] ?? ''), ['', '/index.php'], true),
            'A query-mode original-media fallback must use the application front controller.');
        if (($thumbnailQuery['page'] ?? null) === 'public_media') {
            $fallbackPublicPath = (string) ($thumbnailQuery['public_path'] ?? '');
            $fallbackPublicPathSegments = explode('/', trim($fallbackPublicPath, '/'));
            $fallbackPublicPathSegments = array_map('rawurldecode', $fallbackPublicPathSegments);
            check($fallbackPublicPath !== '' && $fallbackPublicPath === trim($fallbackPublicPath, '/')
                && count($fallbackPublicPathSegments) >= 2 && !array_filter($fallbackPublicPathSegments, static fn (string $segment): bool =>
                $segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1),
                'A query-mode public-media fallback must identify a canonical gallery and image path.');
            $stageDiagnostic = 'thumbnail_route=query_public_media_fallback';
        } else {
            check(is_string($thumbnailQuery['id'] ?? null) && ctype_digit($thumbnailQuery['id'])
                && (int) $thumbnailQuery['id'] > 0,
                'A legacy media fallback must carry a positive image ID.');
            $stageDiagnostic = 'thumbnail_route=query_media_fallback';
        }
        $thumbRoute = '/index.php?' . (string) ($thumbnailParts['query'] ?? '');
    } else {
        $cleanPath = (string) ($thumbnailParts['path'] ?? '');
        check($thumbnailPage === 'clean' && ($thumbnailCleanRouteValid || $thumbnailCleanMediaFallbackValid)
            && !$thumbnailUnsafeSegment,
            'Rendered thumbnail URL is neither a supported query route, canonical clean thumbnail route, nor canonical clean media fallback.');
        $thumbnailIsMediaFallback = $thumbnailCleanMediaFallbackValid;
        $routeKind = $thumbnailCleanRouteValid ? 'thumbnail' : 'media_fallback';
        $stageDiagnostic = 'thumbnail_route=canonical_clean_' . $routeKind . ';mount_prefix='
            . (preg_match('~^/(?:(?:[A-Za-z0-9._\~-]|%[A-Fa-f0-9]{2})+/)+gallery/~iD', $cleanPath) === 1 ? '1' : '0');
    }
    $thumb = $admin->request($thumbRoute);
    $thumbCacheControl = strtolower((string) ($thumb['headers']['cache-control'] ?? ''));
    $thumbContentType = strtolower((string) ($thumb['headers']['content-type'] ?? ''));
    $stageDiagnostic = 'thumbnail_http=' . $thumb['status'] . ';thumbnail_has_bytes=' . ($thumb['body'] !== '' ? '1' : '0')
        . ';thumbnail_image_type=' . (str_starts_with($thumbContentType, 'image/') ? '1' : '0')
        . ';thumbnail_media_fallback=' . ($thumbnailIsMediaFallback ? '1' : '0')
        . ';thumbnail_private=' . (str_contains($thumbCacheControl, 'private') ? '1' : '0')
        . ';thumbnail_no_store=' . (str_contains($thumbCacheControl, 'no-store') ? '1' : '0');
    $thumbnailImageDelivered = $thumb['status'] === 200 && $thumb['body'] !== '' && str_starts_with($thumbContentType, 'image/');
    // The fixture's seeded card has a known source image; only a missing derivative may use the opaque-miss allowance.
    check($thumbnailIsMediaFallback ? $thumbnailImageDelivered : ($thumb['status'] === 404 || $thumbnailImageDelivered),
        'Public thumbnail preview must deliver the known media fallback or return an opaque derivative miss.');
    visualPreviewWorkflowAssertNoStore($thumb, 'Anonymous audience thumbnail preview');

    $markedDocumentReferer = $origin . '/index.php?page=gallery&public_path=seed&preview=visual&view_as=anonymous';
    $markedStylesheetReferer = $origin . '/index.php?page=theme_css&preview=visual&view_as=anonymous';
    $stylesheet = $admin->request('/index.php?page=theme_css', null, false, 'GET', $markedDocumentReferer);
    $stylesheetCacheControl = strtolower((string) ($stylesheet['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'stylesheet_http=' . $stylesheet['status'] . ';stylesheet_has_bytes=' . ($stylesheet['body'] !== '' ? '1' : '0')
        . ';stylesheet_private=' . (str_contains($stylesheetCacheControl, 'private') ? '1' : '0')
        . ';stylesheet_no_store=' . (str_contains($stylesheetCacheControl, 'no-store') ? '1' : '0');
    check($stylesheet['status'] === 200 && $stylesheet['body'] !== '',
        'An unmarked same-origin stylesheet request from the marked document did not inherit preview context.');
    visualPreviewWorkflowAssertNoStore($stylesheet, 'Inherited preview stylesheet');
    $stripPreviewContext = static function (string $route): string {
        $parts = parse_url($route);
        check(is_array($parts) && is_string($parts['path'] ?? null), 'Rendered asset URL cannot be reduced to a same-origin route.');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['preview'], $query['view_as']);
        $encoded = http_build_query($query);
        return $parts['path'] . ($encoded === '' ? '' : '?' . $encoded);
    };
    $inheritedThumb = $admin->request($stripPreviewContext($thumbRoute), null, false, 'GET', $markedDocumentReferer);
    $inheritedThumbCacheControl = strtolower((string) ($inheritedThumb['headers']['cache-control'] ?? ''));
    $inheritedThumbContentType = strtolower((string) ($inheritedThumb['headers']['content-type'] ?? ''));
    $stageDiagnostic = 'inherited_thumbnail_http=' . $inheritedThumb['status']
        . ';inherited_thumbnail_has_bytes=' . ($inheritedThumb['body'] !== '' ? '1' : '0')
        . ';inherited_thumbnail_image_type=' . (str_starts_with($inheritedThumbContentType, 'image/') ? '1' : '0')
        . ';inherited_thumbnail_media_fallback=' . ($thumbnailIsMediaFallback ? '1' : '0')
        . ';inherited_thumbnail_private=' . (str_contains($inheritedThumbCacheControl, 'private') ? '1' : '0')
        . ';inherited_thumbnail_no_store=' . (str_contains($inheritedThumbCacheControl, 'no-store') ? '1' : '0');
    $inheritedThumbnailImageDelivered = $inheritedThumb['status'] === 200 && $inheritedThumb['body'] !== ''
        && str_starts_with($inheritedThumbContentType, 'image/');
    // The same known seeded source must remain available when preview context is inherited from its marked document.
    check($thumbnailIsMediaFallback ? $inheritedThumbnailImageDelivered
        : ($inheritedThumb['status'] === 404 || $inheritedThumbnailImageDelivered),
        'An inherited thumbnail must deliver the known media fallback or return an opaque derivative miss.');
    visualPreviewWorkflowAssertNoStore($inheritedThumb, 'Inherited preview thumbnail');
    $inheritedMedia = $admin->request('/index.php?page=media&id=' . (int) $publicImage['id'], null, false, 'GET', $markedStylesheetReferer);
    $inheritedMediaCacheControl = strtolower((string) ($inheritedMedia['headers']['cache-control'] ?? ''));
    $inheritedMediaContentType = strtolower((string) ($inheritedMedia['headers']['content-type'] ?? ''));
    $inheritedMediaReference = '';
    if ($inheritedMedia['status'] === 500 && preg_match('/Reference:\s*([A-F0-9]{16})/', $inheritedMedia['body'], $inheritedMediaReferenceMatch) === 1) {
        $inheritedMediaReference = $inheritedMediaReferenceMatch[1];
    }
    $inheritedMediaFailure = $inheritedMediaReference === '' ? ['available' => false]
        : visualPreviewWorkflowFailureSummary($origin, $token, $inheritedMediaReference);
    $stageDiagnostic = 'inherited_media_http=' . $inheritedMedia['status'] . ';inherited_media_has_bytes=' . ($inheritedMedia['body'] !== '' ? '1' : '0')
        . ';inherited_media_image_type=' . (str_starts_with($inheritedMediaContentType, 'image/') ? '1' : '0')
        . ';inherited_media_private=' . (str_contains($inheritedMediaCacheControl, 'private') ? '1' : '0')
        . ';inherited_media_no_store=' . (str_contains($inheritedMediaCacheControl, 'no-store') ? '1' : '0')
        . ($inheritedMediaFailure['available'] ? ';inherited_media_failure=' . $inheritedMediaFailure['type'] . ':' . $inheritedMediaFailure['source'] . ':' . $inheritedMediaFailure['line'] : '');
    check($inheritedMedia['status'] === 200 && $inheritedMedia['body'] !== '' && str_starts_with($inheritedMediaContentType, 'image/'),
        'An unmarked original-media request from a marked same-origin stylesheet did not inherit preview context.');
    visualPreviewWorkflowAssertNoStore($inheritedMedia, 'Inherited preview original media');
    $invalidExplicitMarker = $admin->request(
        '/index.php?page=media&id=' . (int) $publicImage['id'] . '&preview=other',
        null,
        false,
        'GET',
        $markedDocumentReferer
    );
    $invalidCacheControl = strtolower((string) ($invalidExplicitMarker['headers']['cache-control'] ?? ''));
    $stageDiagnostic = 'invalid_explicit_marker_http=' . $invalidExplicitMarker['status']
        . ';invalid_explicit_marker_private=' . (str_contains($invalidCacheControl, 'private') ? '1' : '0')
        . ';invalid_explicit_marker_no_store=' . (str_contains($invalidCacheControl, 'no-store') ? '1' : '0');
    check($invalidExplicitMarker['status'] === 404,
        'A valid Referer must not replace an explicit invalid resource preview marker.');
    visualPreviewWorkflowAssertNoStore($invalidExplicitMarker, 'Invalid explicit preview marker');
    $foreignReferer = $admin->request(
        '/index.php?page=admin_theme',
        null,
        false,
        'GET',
        'https://outside.example/index.php?page=home&preview=visual'
    );
    $stageDiagnostic = 'foreign_referer_admin_http=' . $foreignReferer['status'];
    check($foreignReferer['status'] === 200,
        'A foreign preview Referer must not convert an ordinary administrator route into a denied preview route.');

    $protectedQuery = $pdo->prepare('SELECT id FROM images WHERE gallery_id = ? ORDER BY id LIMIT 1');
    $protectedQuery->execute([(int) $seed['protected_id']]);
    $protectedImageId = (int) $protectedQuery->fetchColumn();
    check($protectedImageId > 0, 'The password-protected gallery fixture has no image.');
    $canonicalPathQuery = $pdo->prepare('SELECT url_path FROM galleries WHERE id = ?');
    $canonicalPathQuery->execute([(int) $seed['root_id']]);
    $canonicalSeedPath = trim((string) $canonicalPathQuery->fetchColumn(), '/');
    check($canonicalSeedPath !== '', 'The seeded root gallery has no canonical public URL path.');
    $canonicalCleanGalleryPath = '/gallery/' . implode('/', array_map('rawurlencode', explode('/', $canonicalSeedPath))) . '/';
    $ordinaryProtectedPage = $anonymous->request('/index.php?page=gallery&public_path=seed/protected');
    check($ordinaryProtectedPage['status'] === 200 && str_contains($ordinaryProtectedPage['body'], 'name="gallery_password"'),
        'A normal anonymous visitor must retain the existing password gate outside visual preview.');
    $previewFallbackRoute = static function (string $location, string $target) use ($origin, $canonicalSeedPath, $canonicalCleanGalleryPath): array {
        $originParts = parse_url($origin);
        $parts = parse_url($location);
        if (!is_array($originParts) || !is_array($parts)
            || !is_string($parts['path'] ?? null) || !str_starts_with($parts['path'], '/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || str_starts_with($location, '//') || isset($parts['scheme']) !== isset($parts['host'])) {
            return ['same_origin' => false, 'expected_route' => false, 'no_denied_identity' => false];
        }
        $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
        $locationScheme = strtolower((string) ($parts['scheme'] ?? $originScheme));
        $originPort = (int) ($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
        $locationPort = (int) ($parts['port'] ?? ($locationScheme === 'https' ? 443 : 80));
        $sameOrigin = in_array($locationScheme, ['http', 'https'], true)
            && $locationScheme === $originScheme
            && strtolower((string) ($parts['host'] ?? $originParts['host'] ?? '')) === strtolower((string) ($originParts['host'] ?? ''))
            && $locationPort === $originPort;
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $queryMode = $parts['path'] === '/index.php'
            && (($target === 'gallery' && ($query['page'] ?? '') === 'gallery' && ($query['public_path'] ?? '') === $canonicalSeedPath)
                || ($target === 'home' && ($query['page'] ?? '') === 'home' && !isset($query['public_path'])));
        $cleanMode = $target === 'gallery'
            ? ($parts['path'] === $canonicalCleanGalleryPath && !isset($query['page']) && !isset($query['public_path']))
            : ($target === 'home' && $parts['path'] === '/' && !isset($query['page']) && !isset($query['public_path']));
        $allowedQueryKeys = ['preview', 'view_as', 'visual_notice'];
        if ($queryMode) {
            $allowedQueryKeys[] = 'page';
            if ($target === 'gallery') {
                $allowedQueryKeys[] = 'public_path';
            }
        }
        $unexpectedQueryKeys = array_diff(array_keys($query), $allowedQueryKeys);
        $routeKind = $queryMode ? ($target === 'gallery' ? 'query_gallery' : 'query_home')
            : ($cleanMode ? ($target === 'gallery' ? 'clean_gallery' : 'clean_home') : 'other');
        return [
            'same_origin' => $sameOrigin,
            'expected_route' => ($queryMode || $cleanMode) && $unexpectedQueryKeys === [],
            'route_kind' => $routeKind,
            'no_denied_identity' => !str_contains(strtolower($location), 'protected'),
        ];
    };
    $previewDocumentFacts = static function (string $html, int $rootGalleryId): array {
        $document = new DOMDocument();
        if (!@$document->loadHTML($html)) {
            return ['public_body' => false, 'home_index' => false, 'root_hero' => false,
                'gallery_title_class' => false, 'root_gallery_title' => false, 'root_title_anywhere' => false];
        }
        $xpath = new DOMXPath($document);
        $bodyNodes = $xpath->query('//body[contains(concat(" ", normalize-space(@class), " "), " public-page ")]');
        $homeNodes = $xpath->query('//*[@data-public-gallery-index]');
        $heroNodes = $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " hero ") and @data-public-gallery-id="' . $rootGalleryId . '"]');
        $titleNodes = $xpath->query('//h1[contains(concat(" ", normalize-space(@class), " "), " gallery-title ")]');
        $galleryTitleClassFound = $titleNodes !== false && $titleNodes->length > 0;
        $rootTitleFound = false;
        if ($titleNodes !== false) {
            foreach ($titleNodes as $titleNode) {
                if (str_contains((string) $titleNode->textContent, 'Workflow seed')) {
                    $rootTitleFound = true;
                    break;
                }
            }
        }
        return [
            'public_body' => $bodyNodes !== false && $bodyNodes->length > 0,
            'home_index' => $homeNodes !== false && $homeNodes->length > 0,
            'root_hero' => $heroNodes !== false && $heroNodes->length > 0,
            'gallery_title_class' => $galleryTitleClassFound,
            'root_gallery_title' => $rootTitleFound,
            'root_title_anywhere' => str_contains($html, 'Workflow seed'),
        ];
    };
    $assertFollowedPreviewFallback = static function (array $response, string $documentType, string $label, bool $listedProtectedChildExpected = false) use ($seed, $previewDocumentFacts): void {
        $documentFacts = $previewDocumentFacts($response['body'], (int) $seed['root_id']);
        $protectedCardPattern = '~<article class="[^"]*\\bis-protected-gallery\\b[^"]*" data-gallery-id="'
            . (int) $seed['protected_id'] . '"[^>]*>(.*?)</article>~s';
        $protectedCardMatch = [];
        $hasListedProtectedChildCard = preg_match($protectedCardPattern, $response['body'], $protectedCardMatch) === 1;
        $protectedCardHtml = $hasListedProtectedChildCard ? $protectedCardMatch[0] : '';
        $protectedCardBody = $hasListedProtectedChildCard ? $protectedCardMatch[1] : '';
        $protectedChildListingIsSafe = $listedProtectedChildExpected
            ? ($hasListedProtectedChildCard
                && str_contains($protectedCardHtml, 'Workflow protected')
                && str_contains($protectedCardHtml, 'gallery-locked-preview')
                && preg_match('/<img\\b/i', $protectedCardBody) !== 1)
            : (!$hasListedProtectedChildCard && !str_contains($response['body'], 'Workflow protected'));
        check($response['status'] === 200
            && $documentFacts['public_body']
            && str_contains($response['body'], 'data-theme-background-visual-editor-target')
            && (($documentType === 'home' && $documentFacts['home_index'] && !$documentFacts['root_title_anywhere'])
                || ($documentType === 'gallery' && $documentFacts['root_hero']
                    && $documentFacts['gallery_title_class'] && $documentFacts['root_gallery_title']))
            && $protectedChildListingIsSafe
            && !str_contains(strtolower($response['body']), 'seed/protected'),
            $label . ' must render only the authorized preview document and, when listed, the locked child card without protected media.');
        visualPreviewWorkflowAssertNoStore($response, $label . ' followed document');
    };
    $protectedPage = $admin->request('/index.php?page=gallery&public_path=seed/protected&preview=visual&view_as=anonymous');
    $protectedLocation = (string) ($protectedPage['headers']['location'] ?? '');
    $protectedParts = parse_url($protectedLocation);
    $protectedQuery = [];
    parse_str((string) (is_array($protectedParts) ? ($protectedParts['query'] ?? '') : ''), $protectedQuery);
    $protectedFallbackRoute = $previewFallbackRoute($protectedLocation, 'gallery');
    $stageDiagnostic = 'protected_gallery_http=' . $protectedPage['status']
        . ';protected_gallery_destination=' . $protectedFallbackRoute['route_kind']
        . ';protected_gallery_target=' . ($protectedFallbackRoute['expected_route'] ? 'gallery' : 'other')
        . ';protected_gallery_same_origin=' . ($protectedFallbackRoute['same_origin'] ? '1' : '0')
        . ';protected_gallery_no_denied_identity=' . ($protectedFallbackRoute['no_denied_identity'] ? '1' : '0')
        . ';protected_gallery_fallback_notice=' . (($protectedQuery['visual_notice'] ?? '') === 'anonymous_fallback' ? '1' : '0')
        . ';protected_gallery_preview=' . (($protectedQuery['preview'] ?? '') === 'visual' ? '1' : '0')
        . ';protected_gallery_audience=' . (($protectedQuery['view_as'] ?? '') === 'anonymous' ? '1' : '0');
    check($protectedPage['status'] === 302
        && $protectedFallbackRoute['same_origin']
        && $protectedFallbackRoute['expected_route']
        && $protectedFallbackRoute['no_denied_identity']
        && ($protectedQuery['preview'] ?? '') === 'visual'
        && ($protectedQuery['view_as'] ?? '') === 'anonymous'
        && ($protectedQuery['visual_notice'] ?? '') === 'anonymous_fallback'
        && $protectedPage['body'] === '',
        'Anonymous visual preview must redirect a password-gated gallery to its nearest accessible ancestor without exposing the denied path.');
    visualPreviewWorkflowAssertNoStore($protectedPage, 'Password-protected gallery preview fallback');
    $protectedFollowed = $admin->request($protectedLocation);
    $protectedFollowedCacheControl = strtolower((string) ($protectedFollowed['headers']['cache-control'] ?? ''));
    $protectedFollowedFacts = $previewDocumentFacts($protectedFollowed['body'], (int) $seed['root_id']);
    $protectedFollowedChildCardPattern = '~<article class="[^"]*\\bis-protected-gallery\\b[^"]*" data-gallery-id="'
        . (int) $seed['protected_id'] . '"[^>]*>(.*?)</article>~s';
    $protectedFollowedChildCardMatch = [];
    $protectedFollowedChildCard = preg_match($protectedFollowedChildCardPattern, $protectedFollowed['body'], $protectedFollowedChildCardMatch) === 1;
    $protectedFollowedChildCardHtml = $protectedFollowedChildCard ? $protectedFollowedChildCardMatch[0] : '';
    $protectedFollowedChildCardBody = $protectedFollowedChildCard ? $protectedFollowedChildCardMatch[1] : '';
    $stageDiagnostic = 'protected_gallery_follow_http=' . $protectedFollowed['status']
        . ';protected_gallery_follow_root_hero=' . ($protectedFollowedFacts['root_hero'] ? '1' : '0')
        . ';protected_gallery_follow_public_body=' . ($protectedFollowedFacts['public_body'] ? '1' : '0')
        . ';protected_gallery_follow_seed=' . ($protectedFollowedFacts['root_gallery_title'] ? '1' : '0')
        . ';protected_gallery_follow_gallery_title=' . ($protectedFollowedFacts['gallery_title_class'] ? '1' : '0')
        . ';protected_gallery_follow_preview=' . (str_contains($protectedFollowed['body'], 'data-theme-background-visual-editor-target') ? '1' : '0')
        . ';protected_gallery_follow_child_card=' . ($protectedFollowedChildCard ? '1' : '0')
        . ';protected_gallery_follow_child_title=' . (str_contains($protectedFollowedChildCardHtml, 'Workflow protected') ? '1' : '0')
        . ';protected_gallery_follow_child_locked=' . (str_contains($protectedFollowedChildCardHtml, 'gallery-locked-preview') ? '1' : '0')
        . ';protected_gallery_follow_child_image=' . (preg_match('/<img\\b/i', $protectedFollowedChildCardBody) === 1 ? '1' : '0')
        . ';protected_gallery_follow_denied_path=' . (str_contains(strtolower($protectedFollowed['body']), 'seed/protected') ? '1' : '0')
        . ';protected_gallery_follow_private=' . (str_contains($protectedFollowedCacheControl, 'private') ? '1' : '0')
        . ';protected_gallery_follow_no_store=' . (str_contains($protectedFollowedCacheControl, 'no-store') ? '1' : '0');
    $assertFollowedPreviewFallback($protectedFollowed, 'gallery', 'Password-protected gallery fallback', true);
    $protectedMedia = $admin->request('/index.php?page=media&id=' . $protectedImageId . '&preview=visual&view_as=anonymous');
    $protectedMediaCacheControl = strtolower((string) ($protectedMedia['headers']['cache-control'] ?? ''));
    $protectedMediaReference = '';
    if ($protectedMedia['status'] === 500
        && preg_match('/Reference:\s*([A-F0-9]{16})/', $protectedMedia['body'], $protectedMediaReferenceMatch) === 1) {
        $protectedMediaReference = $protectedMediaReferenceMatch[1];
    }
    $protectedMediaFailure = $protectedMediaReference === '' ? ['available' => false]
        : visualPreviewWorkflowFailureSummary($origin, $token, $protectedMediaReference);
    $stageDiagnostic = 'protected_media_http=' . $protectedMedia['status']
        . ';protected_media_private=' . (str_contains($protectedMediaCacheControl, 'private') ? '1' : '0')
        . ';protected_media_no_store=' . (str_contains($protectedMediaCacheControl, 'no-store') ? '1' : '0')
        . ($protectedMediaFailure['available'] ? ';protected_media_failure=' . $protectedMediaFailure['type']
            . ':' . $protectedMediaFailure['source'] . ':' . $protectedMediaFailure['line'] : '');
    check($protectedMedia['status'] === 404, 'Administrator credentials must not bypass access to an actual protected-gallery media path in anonymous mode.');
    visualPreviewWorkflowAssertNoStore($protectedMedia, 'Protected media denial');

    $stage = 'private and NSFW visibility boundaries';
    $stageDiagnostic = '';
    $pdo->prepare("UPDATE galleries SET visibility = 'private' WHERE id = ?")->execute([(int) $seed['protected_id']]);
    try {
        $privatePage = $admin->request('/index.php?page=gallery&public_path=seed/protected&preview=visual&view_as=anonymous');
        $privateLocation = (string) ($privatePage['headers']['location'] ?? '');
        $privateParts = parse_url($privateLocation);
        $privateQuery = [];
        parse_str((string) (is_array($privateParts) ? ($privateParts['query'] ?? '') : ''), $privateQuery);
        $privateFallbackRoute = $previewFallbackRoute($privateLocation, 'gallery');
        $stageDiagnostic = 'private_gallery_http=' . $privatePage['status']
            . ';private_gallery_target=' . ($privateFallbackRoute['expected_route'] ? 'gallery' : 'other')
            . ';private_gallery_same_origin=' . ($privateFallbackRoute['same_origin'] ? '1' : '0')
            . ';private_gallery_no_denied_identity=' . ($privateFallbackRoute['no_denied_identity'] ? '1' : '0');
        check($privatePage['status'] === 302
            && $privateFallbackRoute['same_origin']
            && $privateFallbackRoute['expected_route']
            && $privateFallbackRoute['no_denied_identity']
            && ($privateQuery['preview'] ?? '') === 'visual'
            && ($privateQuery['view_as'] ?? '') === 'anonymous'
            && ($privateQuery['visual_notice'] ?? '') === 'anonymous_fallback'
            && $privatePage['body'] === '',
            'Anonymous visual preview must redirect a private nested gallery to its nearest accessible ancestor without exposing the denied path.');
        visualPreviewWorkflowAssertNoStore($privatePage, 'Private gallery preview fallback');
        $privateFollowed = $admin->request($privateLocation);
        $stageDiagnostic = 'private_gallery_follow_http=' . $privateFollowed['status']
            . ';private_gallery_follow_seed=' . (str_contains($privateFollowed['body'], 'Workflow seed') ? '1' : '0');
        $assertFollowedPreviewFallback($privateFollowed, 'gallery', 'Private gallery fallback');
        $pdo->prepare("UPDATE galleries SET visibility = 'private' WHERE id = ?")->execute([(int) $seed['root_id']]);
        $homeFallback = $admin->request('/index.php?page=gallery&public_path=seed/protected&preview=visual&view_as=anonymous');
        $homeLocation = (string) ($homeFallback['headers']['location'] ?? '');
        $homeParts = parse_url($homeLocation);
        $homeQuery = [];
        parse_str((string) (is_array($homeParts) ? ($homeParts['query'] ?? '') : ''), $homeQuery);
        $homeFallbackRoute = $previewFallbackRoute($homeLocation, 'home');
        $stageDiagnostic = 'private_home_http=' . $homeFallback['status']
            . ';private_home_target=' . ($homeFallbackRoute['expected_route'] ? 'home' : 'other')
            . ';private_home_same_origin=' . ($homeFallbackRoute['same_origin'] ? '1' : '0')
            . ';private_home_no_denied_identity=' . ($homeFallbackRoute['no_denied_identity'] ? '1' : '0');
        check($homeFallback['status'] === 302
            && $homeFallbackRoute['same_origin']
            && $homeFallbackRoute['expected_route']
            && $homeFallbackRoute['no_denied_identity']
            && ($homeQuery['preview'] ?? '') === 'visual'
            && ($homeQuery['view_as'] ?? '') === 'anonymous'
            && ($homeQuery['visual_notice'] ?? '') === 'anonymous_fallback'
            && $homeFallback['body'] === '',
            'Anonymous visual preview must fall back to Home when no ancestor is accessible.');
        visualPreviewWorkflowAssertNoStore($homeFallback, 'Private gallery Home fallback');
        $homeFollowed = $admin->request($homeLocation);
        $stageDiagnostic = 'private_home_follow_http=' . $homeFollowed['status']
            . ';private_home_follow_document=' . (str_contains($homeFollowed['body'], 'data-public-gallery-index') ? '1' : '0')
            . ';private_home_follow_private_root_absent=' . (!str_contains($homeFollowed['body'], 'Workflow seed') ? '1' : '0');
        $assertFollowedPreviewFallback($homeFollowed, 'home', 'Private gallery Home fallback');
        $cycleStateQuery = $pdo->prepare('SELECT id, parent_id, access_mode FROM galleries WHERE id IN (?, ?)');
        $cycleStateQuery->execute([(int) $seed['root_id'], (int) $seed['protected_id']]);
        $cycleStates = [];
        foreach ($cycleStateQuery->fetchAll(PDO::FETCH_ASSOC) as $cycleState) {
            $cycleStates[(int) $cycleState['id']] = $cycleState;
        }
        check(isset($cycleStates[(int) $seed['root_id']], $cycleStates[(int) $seed['protected_id']]),
            'The cyclic gallery fixture could not capture exact parent and access-mode state.');
        $rootState = $cycleStates[(int) $seed['root_id']];
        $protectedState = $cycleStates[(int) $seed['protected_id']];
        try {
            // Normal access modes force the test through the canonical inherited-policy walkers; the fallback must detect the structural cycle first.
            $pdo->prepare("UPDATE galleries SET parent_id = ?, access_mode = 'normal' WHERE id = ?")
                ->execute([(int) $seed['protected_id'], (int) $seed['root_id']]);
            $pdo->prepare("UPDATE galleries SET parent_id = ?, access_mode = 'normal' WHERE id = ?")
                ->execute([(int) $seed['root_id'], (int) $seed['protected_id']]);
            $cycleFallback = $admin->request('/index.php?page=gallery&public_path=seed/protected&preview=visual&view_as=anonymous');
            $cycleLocation = (string) ($cycleFallback['headers']['location'] ?? '');
            $cycleParts = parse_url($cycleLocation);
            $cycleQuery = [];
            parse_str((string) (is_array($cycleParts) ? ($cycleParts['query'] ?? '') : ''), $cycleQuery);
            $cycleFallbackRoute = $previewFallbackRoute($cycleLocation, 'home');
            $stageDiagnostic = 'cycle_home_http=' . $cycleFallback['status']
                . ';cycle_home_target=' . ($cycleFallbackRoute['expected_route'] ? 'home' : 'other')
                . ';cycle_home_same_origin=' . ($cycleFallbackRoute['same_origin'] ? '1' : '0')
                . ';cycle_home_no_denied_identity=' . ($cycleFallbackRoute['no_denied_identity'] ? '1' : '0');
            check($cycleFallback['status'] === 302
                && $cycleFallbackRoute['same_origin']
                && $cycleFallbackRoute['expected_route']
                && $cycleFallbackRoute['no_denied_identity']
                && ($cycleQuery['preview'] ?? '') === 'visual'
                && ($cycleQuery['view_as'] ?? '') === 'anonymous'
                && ($cycleQuery['visual_notice'] ?? '') === 'anonymous_fallback',
                'A corrupt cyclic gallery ancestry must fail closed to Home without looping or disclosing the denied gallery.');
            check($cycleFallback['body'] === '',
                'A corrupt cyclic gallery ancestry fallback must not render the denied gallery response before navigation.');
            visualPreviewWorkflowAssertNoStore($cycleFallback, 'Cyclic gallery Home fallback');
            $cycleFollowed = $admin->request($cycleLocation);
            $stageDiagnostic = 'cycle_home_follow_http=' . $cycleFollowed['status']
                . ';cycle_home_follow_document=' . (str_contains($cycleFollowed['body'], 'data-public-gallery-index') ? '1' : '0')
                . ';cycle_home_follow_private_root_absent=' . (!str_contains($cycleFollowed['body'], 'Workflow seed') ? '1' : '0');
            $assertFollowedPreviewFallback($cycleFollowed, 'home', 'Cyclic gallery Home fallback');
        } finally {
            $restoreGallery = $pdo->prepare('UPDATE galleries SET parent_id = ?, access_mode = ? WHERE id = ?');
            $restoreGallery->execute([$rootState['parent_id'], $rootState['access_mode'], (int) $seed['root_id']]);
            $restoreGallery->execute([$protectedState['parent_id'], $protectedState['access_mode'], (int) $seed['protected_id']]);
        }
        $pdo->prepare("UPDATE galleries SET visibility = 'public' WHERE id = ?")->execute([(int) $seed['root_id']]);
        $inheritedPrivateMedia = $admin->request(
            '/index.php?page=media&id=' . $protectedImageId . '&view_as=administrator',
            null,
            false,
            'GET',
            $markedStylesheetReferer
        );
        check($inheritedPrivateMedia['status'] === 404,
            'Anonymous preview context inherited from a stylesheet must deny private media despite a conflicting resource audience.');
        visualPreviewWorkflowAssertNoStore($inheritedPrivateMedia, 'Inherited anonymous private-media denial');

        $missingPath = 'seed/__workflow_missing_media_' . preg_replace('/[^A-Za-z0-9]/', '', $token);
        $missingMediaQuery = http_build_query([
            'page' => 'public_media',
            'public_path' => $missingPath,
            'preview' => 'visual',
            'view_as' => 'anonymous',
        ]);
        $missingPublicMedia = $admin->request('/index.php?' . $missingMediaQuery);
        $missingMediaReference = '';
        if ($missingPublicMedia['status'] === 500
            && preg_match('/Reference:\s*([A-F0-9]{16})/', $missingPublicMedia['body'], $missingReferenceMatch) === 1) {
            $missingMediaReference = $missingReferenceMatch[1];
        }
        $missingMediaFailure = $missingMediaReference === '' ? ['available' => false]
            : visualPreviewWorkflowFailureSummary($origin, $token, $missingMediaReference);
        $missingMediaCacheControl = strtolower((string) ($missingPublicMedia['headers']['cache-control'] ?? ''));
        $missingMediaReferenceLookup = $missingMediaReference === '' ? 'not_attempted'
            : (!empty($missingMediaFailure['available']) ? 'available' : 'unavailable');
        $missingMedia500Signature = $missingPublicMedia['status'] !== 500 ? 'not_500'
            : (preg_match('/Reference:\s*[A-F0-9]{16}/', $missingPublicMedia['body']) === 1 ? 'reference_page' : 'other_500');
        $stageDiagnostic = 'missing_public_media_http=' . $missingPublicMedia['status']
            . ';missing_public_media_private=' . (str_contains($missingMediaCacheControl, 'private') ? '1' : '0')
            . ';missing_public_media_no_store=' . (str_contains($missingMediaCacheControl, 'no-store') ? '1' : '0')
            . ';missing_public_media_reference=' . ($missingMediaReference === '' ? 'absent' : 'present')
            . ';missing_public_media_reference_lookup=' . $missingMediaReferenceLookup
            . ';missing_public_media_500_signature=' . $missingMedia500Signature
            . ($missingMediaFailure['available'] ? ';missing_public_media_failure=' . $missingMediaFailure['type']
                . ':' . $missingMediaFailure['source'] . ':' . $missingMediaFailure['line'] : '');
        check($missingPublicMedia['status'] === 404,
            'A missing public-media path must return the opaque not-found response.');
        visualPreviewWorkflowAssertNoStore($missingPublicMedia, 'Missing public media denial');
    } finally {
        $pdo->prepare("UPDATE galleries SET visibility = 'public' WHERE id = ?")->execute([(int) $seed['protected_id']]);
        $pdo->prepare("UPDATE galleries SET visibility = 'public' WHERE id = ?")->execute([(int) $seed['root_id']]);
    }
    $pdo->prepare('UPDATE images SET nsfw_enabled = 1 WHERE id = ?')->execute([(int) $publicImage['id']]);
    try {
        $nsfwMedia = $admin->request('/index.php?page=media&id=' . (int) $publicImage['id'] . '&preview=visual&view_as=anonymous');
        check($nsfwMedia['status'] === 404, 'Administrator credentials must not bypass the NSFW visitor grant in anonymous mode.');
        visualPreviewWorkflowAssertNoStore($nsfwMedia, 'NSFW media denial');
    } finally {
        $pdo->prepare('UPDATE images SET nsfw_enabled = 0 WHERE id = ?')->execute([(int) $publicImage['id']]);
    }
    echo "PASS visual preview anonymous audience preserves password, private, and NSFW media gates\n";

    $stage = 'restricted asset variants and no-write snapshot';
    $stageDiagnostic = '';
    $originalBackground = $admin->request('/index.php?page=theme_background_asset&variant=original&preview=visual');
    check($originalBackground['status'] === 404, 'The original background asset must remain unavailable in the preview workspace.');
    visualPreviewWorkflowAssertNoStore($originalBackground, 'Original theme background denial');
    $ofp = $admin->request('/index.php?page=media&id=' . (int) $publicImage['id'] . '&ofp=1&preview=visual');
    check($ofp['status'] === 404, 'OFP attachments must not be served from the visual preview route.');
    visualPreviewWorkflowAssertNoStore($ofp, 'OFP attachment denial');
    $after = visualPreviewWorkflowSnapshot($origin, $token);
    $afterInstalledCssState = $cssAssetState($installedCssPath);
    $afterOverridesCssState = $cssAssetState($overridesCssPath);
    $stageDiagnostic = 'gallery_snapshot_same=' . ($before === $after ? '1' : '0')
        . ';installed_css_before=' . $cssAssetStateLabel($initialInstalledCssState)
        . ';installed_css_after=' . $cssAssetStateLabel($afterInstalledCssState)
        . ';overrides_css_before=' . $cssAssetStateLabel($initialOverridesCssState)
        . ';overrides_css_after=' . $cssAssetStateLabel($afterOverridesCssState)
        . ';css_assets_same=' . ($initialInstalledCssState === $afterInstalledCssState
            && $initialOverridesCssState === $afterOverridesCssState ? '1' : '0');
    check($before === $after
        && $initialInstalledCssState === $afterInstalledCssState
        && $initialOverridesCssState === $afterOverridesCssState,
        'Read-only preview requests changed telemetry, thumbnail metadata, gallery files, or stylesheet assets.');
    echo "PASS visual preview public assets remain read-only with unchanged telemetry, gallery files, and stylesheet assets\n";

    $stage = 'stylesheet import fixture preparation';
    $stageDiagnostic = 'installed_css=' . $cssAssetStateLabel($cssAssetState($installedCssPath))
        . ';overrides_css=' . $cssAssetStateLabel($cssAssetState($overridesCssPath));
    check($cssAssetState($installedCssPath) === $initialInstalledCssState
        && $cssAssetState($overridesCssPath) === $initialOverridesCssState,
        'Read-only preview requests changed the isolated stylesheet fixture baseline.');
    $readEditorForm = static function () use ($admin, &$stageDiagnostic): array {
        $response = $admin->request('/index.php?page=admin_theme');
        $stageDiagnostic = 'saved_import_editor_http=' . $response['status']
            . ';saved_import_editor_body=' . ($response['body'] !== '' ? 'received' : 'empty');
        check($response['status'] === 200, 'The protected CSS editor form did not load for the import fixture.');
        $document = new DOMDocument();
        $stageDiagnostic = 'saved_import_editor_parse_pending';
        check(@$document->loadHTML($response['body']), 'The protected CSS editor form is not parseable HTML.');
        $xpath = new DOMXPath($document);
        $value = static function (string $name) use ($xpath): string {
            $field = $xpath->query('//input[@name="' . $name . '"]')->item(0);
            check($field instanceof DOMElement, 'The CSS editor form is missing ' . $name . '.');
            return $field->getAttribute('value');
        };
        return [
            'csrf' => $value('csrf_token'),
            'revision' => $value('css_override_revision'),
            'text' => $xpath->query('//textarea[@name="css_override_text"]')->item(0)?->textContent ?? '',
        ];
    };
    $submitEditorAction = static function (string $action, string $text, bool $confirmClear = false) use ($admin, $readEditorForm, &$stageDiagnostic): array {
        $form = $readEditorForm();
        $expectedStateText = $action === 'clear' ? '' : $text;
        $fields = [
            'csrf_token' => $form['csrf'],
            'css_override_action' => $action,
            'css_override_text' => $text,
            'css_override_revision' => $form['revision'],
        ];
        if ($confirmClear) {
            $fields['css_override_clear_confirm'] = '1';
        }
        $response = $admin->request('/index.php?page=admin_theme', $fields, true);
        $state = is_array($response['json']) ? ($response['json']['state'] ?? null) : null;
        $stageDiagnostic = 'saved_import_action=' . ($action === 'save' ? 'save' : ($action === 'clear' ? 'clear' : 'other'))
            . ';saved_import_action_http=' . $response['status']
            . ';saved_import_action_json=' . (is_array($response['json']) ? '1' : '0')
            . ';saved_import_action_ok=' . ((is_array($response['json']) && !empty($response['json']['ok'])) ? '1' : '0')
            . ';saved_import_action_state=' . (is_array($state) && array_key_exists('text', $state) ? 'present' : 'absent')
            . ';saved_import_action_text_match=' . (is_array($state) && ($state['text'] ?? null) === $expectedStateText ? '1' : '0');
        check($response['status'] === 200 && is_array($response['json']) && ($response['json']['ok'] ?? false) === true,
            'The protected CSS editor could not complete the disposable ' . $action . ' action.');
        return $response['json'];
    };
    $previewImportDocument = static function (string $label) use ($admin, &$stageDiagnostic): void {
        $response = $admin->request('/index.php?page=home&preview=visual');
        $cacheControl = strtolower((string) ($response['headers']['cache-control'] ?? ''));
        $stageDiagnostic = 'import_document_http=' . $response['status']
            . ';import_document_marker=' . (preg_match('/data-visual-preview-blocked="(import|inspection)"/', $response['body'], $markerMatch) === 1 ? $markerMatch[1] : 'none')
            . ';import_document_path_leak=' . ((str_contains($response['body'], 'custom.css') || str_contains($response['body'], 'custom-overrides.css')) ? '1' : '0')
            . ';import_document_private=' . (str_contains($cacheControl, 'private') ? '1' : '0')
            . ';import_document_no_store=' . (str_contains($cacheControl, 'no-store') ? '1' : '0');
        check($response['status'] === 409 && str_contains($response['body'], 'data-visual-preview-blocked="import"'),
            $label . ' must stop the marked document with a bounded import refusal.');
        check(!str_contains($response['body'], 'custom.css') && !str_contains($response['body'], 'custom-overrides.css'),
            $label . ' must not disclose an installation stylesheet path.');
        visualPreviewWorkflowAssertNoStore($response, $label);
        $head = $admin->request('/index.php?page=home&preview=visual', null, false, 'HEAD');
        $stageDiagnostic = 'import_head_http=' . $head['status'] . ';import_head_body=' . ($head['body'] === '' ? 'empty' : 'received');
        check($head['status'] === 409 && $head['body'] === '', $label . ' HEAD must return the refusal status without a body.');
        visualPreviewWorkflowAssertNoStore($head, $label . ' HEAD');
        $ordinary = $admin->request('/index.php?page=home');
        $stageDiagnostic = 'import_ordinary_http=' . $ordinary['status']
            . ';import_ordinary_refusal=' . (str_contains($ordinary['body'], 'data-visual-preview-blocked') ? '1' : '0');
        check($ordinary['status'] === 200 && !str_contains($ordinary['body'], 'data-visual-preview-blocked'),
            $label . ' must not block ordinary public page rendering.');
    };

    $stage = 'installed and saved stylesheet import refusal';
    $stageDiagnostic = '';
    $installedImportText = "@import url(\"/assets/site.css\");\n";
    $installedImportFailure = null;
    $installedImportFailureDiagnostic = '';
    $installedImportCleanupStatus = 'passed';
    try {
        check($cssAssetState($installedCssPath) === $initialInstalledCssState,
            'The installed stylesheet changed before the import fixture was prepared.');
        check(file_put_contents($installedCssPath, $installedImportText) === strlen($installedImportText)
            && @chmod($installedCssPath, 0644), 'Could not prepare the isolated installed import stylesheet.');
        $previewImportDocument('Installed CSS import');
    } catch (Throwable $exception) {
        $installedImportFailure = $exception;
        $installedImportFailureDiagnostic = $stageDiagnostic;
    } finally {
        try {
            $activeInstalledCssState = $cssAssetState($installedCssPath);
            if ($activeInstalledCssState !== $initialInstalledCssState) {
                check(($activeInstalledCssState['kind'] ?? '') === 'file'
                    && ($activeInstalledCssState['contents'] ?? null) === $installedImportText,
                    'The installed stylesheet changed unexpectedly during the import fixture.');
                check(@unlink($installedCssPath), 'Could not restore the fixture-installed stylesheet to its original absent state.');
            }
            check($cssAssetState($installedCssPath) === $initialInstalledCssState,
                'The installed stylesheet was not restored to its exact original state.');
        } catch (Throwable $cleanupException) {
            $installedImportCleanupStatus = 'failed';
            if ($installedImportFailure === null) {
                throw $cleanupException;
            }
        }
    }
    if ($installedImportFailure !== null) {
        $stageDiagnostic = $installedImportFailureDiagnostic . ';installed_import_cleanup=' . $installedImportCleanupStatus;
        throw $installedImportFailure;
    }

    $stage = 'saved override import refusal';
    $stageDiagnostic = '';
    $savedImportText = "@import url(\"/assets/override.css\");\n";
    $savedImportFailure = null;
    $savedImportFailureDiagnostic = '';
    $savedImportCleanupStatus = 'passed';
    try {
        $savedImport = $submitEditorAction('save', $savedImportText);
        check(($savedImport['state']['text'] ?? null) === $savedImportText,
            'The real CSS editor did not save the imported stylesheet fixture.');
        $previewImportDocument('Saved CSS import');
    } catch (Throwable $exception) {
        $savedImportFailure = $exception;
        $savedImportFailureDiagnostic = $stageDiagnostic;
    } finally {
        try {
            $activeOverridesCssState = $cssAssetState($overridesCssPath);
            if ($activeOverridesCssState !== $initialOverridesCssState) {
                check(($activeOverridesCssState['kind'] ?? '') === 'file'
                    && ($activeOverridesCssState['contents'] ?? null) === $savedImportText,
                    'The saved override changed unexpectedly during the import fixture.');
                $restored = $submitEditorAction('clear', $readEditorForm()['text'], true);
                check(($restored['state']['text'] ?? null) === '', 'The protected editor did not clear the disposable saved override.');
                $clearedOverridesState = $cssAssetState($overridesCssPath);
                check(($clearedOverridesState['kind'] ?? '') === 'file' && ($clearedOverridesState['size'] ?? -1) === 0,
                    'The protected editor clear did not leave only the empty disposable override asset.');
                if (($initialOverridesCssState['kind'] ?? '') === 'absent') {
                    check(@unlink($overridesCssPath), 'Could not restore the disposable saved override to its original absent state.');
                } else {
                    $initialOverrideText = (string) ($initialOverridesCssState['contents'] ?? '');
                    if ($initialOverrideText !== '') {
                        $baselineRestore = $submitEditorAction('save', $initialOverrideText);
                        check(($baselineRestore['state']['text'] ?? null) === $initialOverrideText,
                            'Could not restore the pre-existing saved override through the protected editor.');
                    }
                    check(@chmod($overridesCssPath, (int) ($initialOverridesCssState['mode'] ?? -1))
                        && @touch($overridesCssPath, (int) ($initialOverridesCssState['modified'] ?? -1)),
                        'Could not restore the original saved override file metadata.');
                }
            }
            check($cssAssetState($overridesCssPath) === $initialOverridesCssState,
                'The disposable saved override was not restored to its exact initial state.');
        } catch (Throwable $cleanupException) {
            $savedImportCleanupStatus = 'failed';
            if ($savedImportFailure === null) {
                throw $cleanupException;
            }
        }
    }
    if ($savedImportFailure !== null) {
        $stageDiagnostic = $savedImportFailureDiagnostic . ';saved_import_cleanup=' . $savedImportCleanupStatus;
        throw $savedImportFailure;
    }

    $stage = 'installed stylesheet inspection size limit';
    $stageDiagnostic = '';
    $oversizedCssText = str_repeat('a', 8_388_609);
    $oversizedCssFailure = null;
    $oversizedCssFailureDiagnostic = '';
    $oversizedCssCleanupStatus = 'passed';
    try {
        check($cssAssetState($installedCssPath) === $initialInstalledCssState
            && ($initialInstalledCssState['kind'] ?? '') === 'absent',
            'The installed stylesheet changed before the oversized fixture was prepared.');
        check(file_put_contents($installedCssPath, $oversizedCssText) === strlen($oversizedCssText)
            && @chmod($installedCssPath, 0644), 'Could not prepare the isolated oversized stylesheet.');
        $oversized = $admin->request('/index.php?page=home&preview=visual');
        check($oversized['status'] === 409 && str_contains($oversized['body'], 'data-visual-preview-blocked="inspection"'),
            'An installed stylesheet above 8 MiB must produce the generic inspection refusal.');
        check(!str_contains($oversized['body'], $directory) && !str_contains($oversized['body'], 'custom.css'),
            'Oversized stylesheet refusal must not disclose the fixture path.');
        visualPreviewWorkflowAssertNoStore($oversized, 'Oversized installed stylesheet refusal');
        $oversizedHead = $admin->request('/index.php?page=home&preview=visual', null, false, 'HEAD');
        check($oversizedHead['status'] === 409 && $oversizedHead['body'] === '',
            'Oversized stylesheet HEAD must return 409 without a response body.');
        visualPreviewWorkflowAssertNoStore($oversizedHead, 'Oversized stylesheet HEAD refusal');
        $ordinary = $admin->request('/index.php?page=home');
        check($ordinary['status'] === 200 && !str_contains($ordinary['body'], 'data-visual-preview-blocked'),
            'The preview inspection limit must not block ordinary public page rendering.');
    } catch (Throwable $exception) {
        $oversizedCssFailure = $exception;
        $oversizedCssFailureDiagnostic = $stageDiagnostic;
    } finally {
        try {
            $activeInstalledCssState = $cssAssetState($installedCssPath);
            if ($activeInstalledCssState !== $initialInstalledCssState) {
                check(($activeInstalledCssState['kind'] ?? '') === 'file'
                    && ($activeInstalledCssState['contents'] ?? null) === $oversizedCssText
                    && ($activeInstalledCssState['size'] ?? -1) === strlen($oversizedCssText)
                    && ($activeInstalledCssState['sha256'] ?? '') === hash('sha256', $oversizedCssText),
                    'The installed stylesheet changed unexpectedly during the oversized fixture.');
                check(@unlink($installedCssPath), 'Could not restore the fixture-installed stylesheet to its original absent state.');
            }
            check($cssAssetState($installedCssPath) === $initialInstalledCssState,
                'The installed stylesheet was not restored to its exact original absent state.');
        } catch (Throwable $cleanupException) {
            $oversizedCssCleanupStatus = 'failed';
            if ($oversizedCssFailure === null) {
                throw $cleanupException;
            }
        }
    }
    if ($oversizedCssFailure !== null) {
        $stageDiagnostic = $oversizedCssFailureDiagnostic . ';installed_css_cleanup=' . $oversizedCssCleanupStatus;
        throw $oversizedCssFailure;
    }
    echo "PASS visual preview refuses installed/saved imports and oversized CSS only on marked documents\n";

} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL visual preview workflow ' . $stage . ' at ' . basename($exception->getFile()) . ' line ' . $exception->getLine()
        . ($stageDiagnostic === '' ? '' : ' [' . $stageDiagnostic . ']') . "\n");
    exit(1);
}
