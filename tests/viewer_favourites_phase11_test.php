<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_favourites_phase11_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 1.1 viewer-favourites HTTP and authorization boundary.
 *
 * Responsibilities:
 *   - Verify favourite writes remain viewer-owned, CSRF-protected, quota-bounded, and source-authorized
 *   - Verify favourite reads re-check current source-gallery/media authorization
 *   - Verify public card/lightbox wiring exposes only current viewer state and never grants gallery access
 *   - Verify the existing Phase 0 favourites schema is reused without collection/share scope creep
 *   - Verify favourites fail closed without making anonymous gallery browsing depend on viewer storage
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

/**
 * Throw when one Phase 1.1 expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_phase11_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Extract one named function declaration/body for focused static assertions.
 *
 * @param string $source Complete PHP source.
 * @param string $functionName Function name.
 * @return string Function declaration/body source.
 */
function viewer_phase11_function_source(string $source, string $functionName): string
{
    $needle = 'function ' . $functionName . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('Function not found: ' . $functionName);
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        throw new RuntimeException('Function body not found: ' . $functionName);
    }
    $depth = 0;
    $length = strlen($source);
    for ($index = $brace; $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $index - $start + 1);
            }
        }
    }
    throw new RuntimeException('Unterminated function body: ' . $functionName);
}


/**
 * Verify imported project functions in a new Phase 1.1 module resolve to a declared namespace/function.
 *
 * PHP lint does not catch a syntactically valid `use function` that points at the wrong namespace,
 * so this regression protects the same runtime failure class found during Phase 1.0 integration.
 *
 * @param string $root Repository root.
 * @param string $modulePath Module path relative to repository root.
 */
function viewer_phase11_assert_function_imports_resolve(string $root, string $modulePath): void
{
    $source = (string) file_get_contents($root . '/' . $modulePath);
    preg_match_all('/^use function ([A-Za-z0-9_\\]+)\\([A-Za-z0-9_]+);$/m', $source, $matches, PREG_SET_ORDER);
    $phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($matches as $match) {
        $namespace = (string) $match[1];
        $functionName = (string) $match[2];
        $resolved = false;
        foreach ($phpFiles as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }
            $candidate = (string) file_get_contents($fileInfo->getPathname());
            if (!preg_match('/namespace\s+' . preg_quote($namespace, '/') . '\s*;/', $candidate)) {
                continue;
            }
            if (preg_match('/function\s+' . preg_quote($functionName, '/') . '\s*\(/', $candidate)) {
                $resolved = true;
                break;
            }
        }
        viewer_phase11_assert($resolved, 'Imported function must resolve: ' . $modulePath . ' -> ' . $namespace . '\\' . $functionName);
        $phpFiles->rewind();
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/services/viewer_favourites.php');
$contentFoundations = (string) file_get_contents($root . '/app/services/viewer_content_foundations.php');
$controller = (string) file_get_contents($root . '/app/controllers/viewer_favourites.php');
$accountController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
$servicesLoader = (string) file_get_contents($root . '/app/services.php');
$controllersLoader = (string) file_get_contents($root . '/app/controllers.php');
$galleryAccess = (string) file_get_contents($root . '/app/services/gallery_access.php');
$publicGallery = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$publicLightbox = (string) file_get_contents($root . '/app/controllers/public_gallery_lightbox.php');
$lightboxJson = (string) file_get_contents($root . '/app/controllers/gallery_lightbox.php');
$smartGalleries = (string) file_get_contents($root . '/app/controllers/smart_galleries.php');
$layout = (string) file_get_contents($root . '/app/views/layout.php');
$publicGalleryJs = (string) file_get_contents($root . '/public/assets/public-gallery.js');
$adminGalleryJs = (string) file_get_contents($root . '/public/assets/gallery.js');
$favouritesJs = (string) file_get_contents($root . '/public/assets/gallery-modules/viewer-favourites.js');
$lightboxJs = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$publicSharedCss = (string) file_get_contents($root . '/public/assets/styles/public-shared.css');
$migrationDefinitions = (string) file_get_contents($root . '/app/migration_definitions.php');

viewer_phase11_assert(is_file($root . '/app/services/viewer_favourites.php'), 'Phase 1.1 must have one dedicated viewer-favourites service.');
viewer_phase11_assert(is_file($root . '/app/controllers/viewer_favourites.php'), 'Phase 1.1 must have one dedicated viewer-favourites controller.');
viewer_phase11_assert_function_imports_resolve($root, 'app/services/viewer_favourites.php');
viewer_phase11_assert_function_imports_resolve($root, 'app/controllers/viewer_favourites.php');
viewer_phase11_assert(str_contains($servicesLoader, "'/services/viewer_favourites.php'"), 'Viewer favourites service must be loaded through the service bootstrap.');
viewer_phase11_assert(str_contains($controllersLoader, "'/controllers/viewer_favourites.php'"), 'Viewer favourites controller must be loaded through the controller bootstrap.');

// Reuse the existing Phase 0 schema. Phase 1.1 must not add a parallel favourites table or ownership model.
viewer_phase11_assert(str_contains($service, 'viewer_favourites'), 'Favourite service must use the existing viewer_favourites table.');
viewer_phase11_assert(str_contains($migrationDefinitions, 'viewer_favourites') || str_contains($contentFoundations, 'viewer_favourites'), 'Existing viewer favourites foundations must remain part of the established schema/service domain.');
viewer_phase11_assert(!str_contains($service, 'CREATE TABLE'), 'Phase 1.1 service must not create schema at runtime.');
viewer_phase11_assert(!str_contains($service, 'viewer_collection_items') && !str_contains($service, 'viewer_collection_share_tokens'), 'Favourite service must not absorb collection/share responsibilities.');

// Mutation boundary: authenticated viewer only, viewer CSRF, source authorization, account ownership, quota, and account lock.
$mutation = viewer_phase11_function_source($controller, 'cms_viewer_favourite');
viewer_phase11_assert(str_contains($mutation, "request_method() !== 'POST'"), 'Favourite mutation endpoint must be POST-only.');
viewer_phase11_assert(str_contains($mutation, 'current_viewer()'), 'Favourite mutation must require the viewer principal.');
viewer_phase11_assert(str_contains($mutation, "viewer_csrf_verify((string) (\$_POST['viewer_csrf_token'] ?? ''))") && str_contains($controller, 'name="viewer_csrf_token"'), 'Favourite mutation must use the established viewer CSRF namespace and field convention.');
viewer_phase11_assert(str_contains($mutation, 'viewer_favourite_set('), 'Controller must delegate favourite mutation to the service.');
viewer_phase11_assert(str_contains($mutation, 'viewer_http_no_store();'), 'Favourite mutation responses must be no-store.');
viewer_phase11_assert(!str_contains($mutation, 'current_user()') && !str_contains($mutation, "\$_SESSION['user_id']"), 'Favourite mutation must not depend on or write administrator identity.');
viewer_phase11_assert(!str_contains($mutation, 'INSERT INTO viewer_favourites') && !str_contains($mutation, 'DELETE FROM viewer_favourites'), 'Controller must not contain favourite SQL.');
viewer_phase11_assert(!str_contains($mutation, "\$_POST['return']") && !str_contains($controller, 'name="return"'), 'Favourite mutations must not copy arbitrary or secret-bearing gallery return URLs through the viewer endpoint.');
viewer_phase11_assert(str_contains($mutation, "'invalid' => 400"), 'Malformed favourite mutations must fail as a bounded client error rather than a service-availability error.');

$setFavourite = viewer_phase11_function_source($service, 'viewer_favourite_set');
viewer_phase11_assert(str_contains($setFavourite, 'viewer_source_image_can_reference($imageId)'), 'Every favourite write must re-check canonical source-image authorization.');
viewer_phase11_assert(str_contains($setFavourite, 'viewer_account_can_mutate_content('), 'Favourite write must require an active viewer account that may mutate content.');
viewer_phase11_assert(str_contains($setFavourite, 'security_version'), 'Favourite write must bind mutation authority to the current viewer security version.');
viewer_phase11_assert(str_contains($setFavourite, 'FOR UPDATE'), 'Favourite write must serialize account-owned quota admission.');
viewer_phase11_assert(str_contains($setFavourite, "max_viewer_favourites_per_account"), 'Favourite write must use the centralized favourites quota.');
viewer_phase11_assert(str_contains($setFavourite, 'COUNT(*) FROM viewer_favourites'), 'Favourite add must count owned rows while quota admission is serialized.');
viewer_phase11_assert(str_contains($setFavourite, 'INSERT INTO viewer_favourites') && str_contains($setFavourite, 'DELETE FROM viewer_favourites'), 'Favourite service must implement only the existing add/remove reference mutations.');
viewer_phase11_assert(!str_contains($setFavourite, 'UPDATE images') && !str_contains($setFavourite, 'UPDATE galleries'), 'Favourite writes must never alter source image/gallery authorization state.');
viewer_phase11_assert(!str_contains($setFavourite, "\$_SESSION['user_id']"), 'Favourite service must never create administrator session state.');

// Read boundary: ownership lookup is not an access grant. Current source authorization must be re-evaluated before rendering.
$listPage = viewer_phase11_function_source($controller, 'cms_viewer_favourites');
viewer_phase11_assert(str_contains($listPage, 'viewer_source_image_can_render_reference($imageId)'), 'Favourite list must explicitly re-check source render authorization.');
viewer_phase11_assert(str_contains($listPage, 'viewer_source_image_resolve_authorized($imageId)'), 'Favourite list must resolve render metadata through the canonical authorized source resolver.');
viewer_phase11_assert(!str_contains($listPage, 'visitor_can_access_gallery('), 'Favourite controller must not recreate gallery authorization logic.');

$batchRead = viewer_phase11_function_source($service, 'viewer_favourites_for_image_ids');
viewer_phase11_assert(str_contains($batchRead, 'catch (Throwable'), 'Favourite state decoration must fail closed when viewer storage is unavailable.');
viewer_phase11_assert(str_contains($batchRead, 'return [];'), 'Viewer storage failure must degrade to no favourite decoration rather than breaking public gallery rendering.');
viewer_phase11_assert(str_contains($batchRead, 'array_chunk(array_keys($ids), 200)') && !str_contains($batchRead, 'count($ids) >= 200'), 'Favourite state lookup must chunk large rendered pages rather than silently truncating state after 200 images.');
viewer_phase11_assert(!str_contains($batchRead, 'visitor_can_access_gallery('), 'Favourite state lookup must not be treated as source authorization.');

// Route surface is deliberately small and private. Collections/shares remain absent.
foreach (['viewer_favourites', 'viewer_favourite'] as $route) {
    viewer_phase11_assert(str_contains($dispatch, "'{$route}' =>"), 'Required Phase 1.1 route missing: ' . $route);
}
viewer_phase11_assert(str_contains($routing, "\$segments === ['viewer', 'favourites']") && str_contains($routing, "\$segments === ['viewer', 'favourite']"), 'Clean viewer favourites routes must follow the existing viewer router.');
foreach (['viewer_collection_share', 'viewer_profile', 'viewer_upload'] as $forbiddenRoute) {
    viewer_phase11_assert(!str_contains($dispatch, "'{$forbiddenRoute}' =>"), 'Later-phase route must remain absent: ' . $forbiddenRoute);
}

// Public gallery integration may decorate authorized cards, but must never change gallery authorization semantics.
viewer_phase11_assert(str_contains($publicGallery, 'viewer_favourites_for_image_ids('), 'Normal public gallery cards must batch-load current viewer favourite state.');
viewer_phase11_assert(str_contains($publicGallery, 'render_viewer_favourite_form_html('), 'Normal public gallery cards must expose the minimal favourite control for authenticated viewers.');
viewer_phase11_assert(str_contains($publicGallery, 'viewer_source_image_can_reference((int) $image[\'id\'])') && str_contains($publicGallery, '$viewerFavouriteRequiresSourceRecheck'), 'Dual Admin+viewer physical-gallery rendering must suppress favourite controls for rows visible only through administrator authority.');
viewer_phase11_assert(str_contains($smartGalleries, 'viewer_favourites_for_image_ids(') && str_contains($smartGalleries, 'render_viewer_favourite_form_html('), 'Smart Gallery cards must use the same favourite state/control model.');
viewer_phase11_assert(substr_count($smartGalleries, 'viewer_source_image_can_reference((int) $image[\'id\'])') >= 2 && str_contains($smartGalleries, 'current_user() !== null'), 'Dual Admin+viewer Smart Gallery cards and lightbox data must independently suppress favourite state for administrator-only source access.');
viewer_phase11_assert(str_contains($publicLightbox, 'render_viewer_favourite_lightbox_form_html()'), 'Lightbox toolbar must expose the same viewer favourite mutation control.');
viewer_phase11_assert(str_contains($lightboxJson, "'viewer_favourite'"), 'Normal gallery lightbox JSON must carry current viewer favourite state.');
viewer_phase11_assert(str_contains($lightboxJson, 'viewer_source_image_can_reference((int) $image[\'id\'])') && str_contains($lightboxJson, '$viewerFavouriteRequiresSourceRecheck'), 'Dual Admin+viewer lightbox data must suppress favourite state for rows visible only through administrator authority.');
viewer_phase11_assert(str_contains($smartGalleries, "'viewer_favourite'"), 'Smart Gallery lightbox JSON must carry current viewer favourite state.');
viewer_phase11_assert(!str_contains($galleryAccess, 'current_viewer()') && !str_contains($galleryAccess, 'viewer_favourite'), 'Canonical gallery authorization must remain completely viewer/favourite independent.');

// Browser code synchronizes a server-authorized state only. It has no gallery permission shortcut.
viewer_phase11_assert(str_contains($publicGalleryJs, 'viewer-favourites.js'), 'Anonymous/viewer public-gallery bundle must load the favourites module when controls exist.');
viewer_phase11_assert(str_contains($adminGalleryJs, 'viewer-favourites.js'), 'Administrator public-gallery bundle must also support a coexisting viewer principal.');
viewer_phase11_assert(str_contains($favouritesJs, 'fetch(') && str_contains($favouritesJs, "X-Requested-With"), 'Favourite browser mutation must use same-origin asynchronous POST wiring.');
viewer_phase11_assert(str_contains($favouritesJs, 'data-viewer-favourite-form') && str_contains($favouritesJs, 'data-current-image-id'), 'Favourite browser state must synchronize cards and the active lightbox image.');
viewer_phase11_assert(!str_contains($favouritesJs, 'gallery_password') && !str_contains($favouritesJs, 'share_token'), 'Favourite JavaScript must not emulate or carry gallery authorization credentials.');
viewer_phase11_assert(str_contains($lightboxJs, 'viewer_favourite'), 'Deferred lightbox cards must retain server-provided favourite state.');
viewer_phase11_assert(str_contains($layout, 'viewer.favourites.add') && str_contains($layout, 'viewer.favourites.remove'), 'Browser translations must include accessible favourite action labels.');
viewer_phase11_assert(str_contains($publicSharedCss, '.image-card.has-public-reorder-handle .viewer-favourite-card-overlay') && str_contains($publicSharedCss, '.image-card.has-picture-manager-select .viewer-favourite-card-overlay'), 'Viewer favourite controls must not overlap coexisting administrator card controls.');

// Viewer account page keeps the Phase 1.1 favourites destination while later phases may add separate private-content links.
viewer_phase11_assert(str_contains($accountController, "url_for('viewer_favourites')"), 'Viewer account page must provide a private favourites entry point.');
viewer_phase11_assert(!str_contains($accountController, "url_for('viewer_profile')"), 'Viewer account page must not expose a public viewer profile.');

// The core Phase 1 invariant remains explicit: authentication never grants gallery/media access.
viewer_phase11_assert(str_contains($contentFoundations, 'viewer_source_image_can_reference') && str_contains($contentFoundations, 'viewer_source_image_can_render_reference'), 'Canonical viewer source authorization primitives must remain centralized.');
viewer_phase11_assert(!str_contains($galleryAccess, "if (current_viewer())") && !str_contains($galleryAccess, 'viewer_accounts'), 'Viewer authentication must never become a gallery-access bypass.');

fwrite(STDOUT, "viewer favourites Phase 1.1 regression checks passed\n");
