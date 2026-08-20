<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_collections_phase20_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 2.0 private viewer-collection ownership and live-authorization boundary.
 *
 * Responsibilities:
 *   - Verify collection CRUD remains viewer-owned, CSRF-protected, quota-bounded, and POST-only
 *   - Verify collection items are canonical image references and never stored authorization grants
 *   - Verify every collection read applies current no-admin-bypass source authorization
 *   - Verify reorder validation is bounded, owner-safe, duplicate-safe, and transactional
 *   - Verify private collection UI stays out of anonymous HTML and Phase 2 ownership logic remains separate from later sharing/public-profile surfaces
 *   - Verify new PHP imports resolve to real symbols so lint-only namespace failures are caught
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

/** Assert a Phase 2.0 regression condition and throw with its descriptive label. */
function viewer_phase20_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/** Extract one named function body from source text for focused contract assertions. */
function viewer_phase20_function_source(string $source, string $functionName): string
{
    $needle = 'function ' . $functionName . '(';
    $start = strpos($source, $needle);
    if ($start === false) throw new RuntimeException('Function not found: ' . $functionName);
    $brace = strpos($source, '{', $start);
    if ($brace === false) throw new RuntimeException('Function body not found: ' . $functionName);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $start, $i - $start + 1);
        }
    }
    throw new RuntimeException('Unterminated function body: ' . $functionName);
}

/** Verify every imported project function resolves to an actual declaration. */
function viewer_phase20_assert_function_imports_resolve(string $root, string $modulePath): void
{
    $source = (string) file_get_contents($root . '/' . $modulePath);
    preg_match_all('/^use function ([A-Za-z0-9_\\]+)\\([A-Za-z0-9_]+);$/m', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $namespace = (string) $match[1];
        $functionName = (string) $match[2];
        $resolved = false;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') continue;
            $candidate = (string) file_get_contents($fileInfo->getPathname());
            if (!preg_match('/namespace\s+' . preg_quote($namespace, '/') . '\s*;/', $candidate)) continue;
            if (preg_match('/function\s+' . preg_quote($functionName, '/') . '\s*\(/', $candidate)) {
                $resolved = true;
                break;
            }
        }
        viewer_phase20_assert($resolved, 'Imported function must resolve: ' . $modulePath . ' -> ' . $namespace . '\\' . $functionName);
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/services/viewer_collections.php');
$content = (string) file_get_contents($root . '/app/services/viewer_content_foundations.php');
$controller = (string) file_get_contents($root . '/app/controllers/viewer_collections.php');
$accountsController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$favouritesController = (string) file_get_contents($root . '/app/controllers/viewer_favourites.php');
$publicGallery = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$smartGalleries = (string) file_get_contents($root . '/app/controllers/smart_galleries.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
$servicesLoader = (string) file_get_contents($root . '/app/services.php');
$controllersLoader = (string) file_get_contents($root . '/app/controllers.php');
$galleryAccess = (string) file_get_contents($root . '/app/services/gallery_access.php');
$publicMedia = (string) file_get_contents($root . '/app/controllers/public_media.php');
$migration = (string) file_get_contents($root . '/database/migrations/202608180001_viewer_security_foundations.php');
$rateLimits = (string) file_get_contents($root . '/app/services/viewer_rate_limits.php');
$css = (string) file_get_contents($root . '/public/assets/styles/public-shared.css');

viewer_phase20_assert(is_file($root . '/app/services/viewer_collections.php'), 'Phase 2.0 collection service is required.');
viewer_phase20_assert(is_file($root . '/app/controllers/viewer_collections.php'), 'Phase 2.0 collection controller is required.');
viewer_phase20_assert_function_imports_resolve($root, 'app/services/viewer_collections.php');
viewer_phase20_assert_function_imports_resolve($root, 'app/controllers/viewer_collections.php');
viewer_phase20_assert(str_contains($servicesLoader, "'/services/viewer_collections.php'"), 'Collection service must be loaded.');
viewer_phase20_assert(str_contains($controllersLoader, "'/controllers/viewer_collections.php'"), 'Collection controller must be loaded.');

// Reuse the dormant Phase 0 schema exactly. Phase 2.0 adds no migration or parallel data model.
viewer_phase20_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_collections') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_collection_items'), 'Dormant collection schema must be reused.');
viewer_phase20_assert(str_contains($migration, 'PRIMARY KEY (viewer_collection_id, image_id)'), 'Collection-item duplicate prevention must remain database-enforced.');
viewer_phase20_assert(str_contains($migration, 'ON DELETE CASCADE'), 'Existing FK lifecycle/cascade semantics must remain available.');
viewer_phase20_assert(!str_contains($service, 'CREATE TABLE') && !str_contains($controller, 'CREATE TABLE'), 'Runtime collection code must not create schema.');
viewer_phase20_assert(!is_file($root . '/database/migrations/202608180005_viewer_collections_phase20.php'), 'Phase 2.0 must not add a redundant collection migration.');

// Ownership: every collection object is keyed by collection id + authenticated viewer id.
viewer_phase20_assert(str_contains($service, 'WHERE vc.id = ? AND vc.viewer_account_id = ?'), 'Collection reads must be owner-scoped.');
viewer_phase20_assert(str_contains($service, 'WHERE id = ? AND viewer_account_id = ? LIMIT 1 FOR UPDATE'), 'Collection mutation lock must be owner-scoped.');
viewer_phase20_assert(str_contains($service, 'UPDATE viewer_collections SET title = ?, updated_at = ? WHERE id = ? AND viewer_account_id = ?'), 'Rename must keep owner in the write predicate.');
viewer_phase20_assert(str_contains($service, 'DELETE FROM viewer_collections WHERE id = ? AND viewer_account_id = ?'), 'Delete must keep owner in the write predicate.');
viewer_phase20_assert(!str_contains($controller, 'owner_viewer_id') && !str_contains($controller, 'owner_id'), 'Controller must not accept client-supplied ownership.');
viewer_phase20_assert(!str_contains($service, 'current_user(') && !str_contains($controller, 'current_user('), 'Admin principal must not become collection ownership authority.');
viewer_phase20_assert(str_contains($controller, 'current_viewer()'), 'Collection routes must require the viewer principal.');

// Route surface and method integrity.
foreach (['viewer_collections','viewer_collection','viewer_collection_rename','viewer_collection_delete','viewer_collection_item_add','viewer_collection_item_remove','viewer_collection_reorder'] as $route) {
    viewer_phase20_assert(str_contains($dispatch, "'{$route}' =>"), 'Missing collection route: ' . $route);
}
foreach (['cms_viewer_collection_rename','cms_viewer_collection_delete','cms_viewer_collection_item_add','cms_viewer_collection_item_remove','cms_viewer_collection_reorder'] as $fn) {
    $source = viewer_phase20_function_source($controller, $fn);
    viewer_phase20_assert(str_contains($source, "request_method() !== 'POST'"), $fn . ' must be POST-only.');
    viewer_phase20_assert(str_contains($source, 'viewer_verify_csrf_or_render_error()'), $fn . ' must use viewer CSRF.');
    viewer_phase20_assert(str_contains($source, 'viewer_http_no_store()') || str_contains($controller, 'function viewer_collection_require_viewer'), $fn . ' must run behind no-store viewer handling.');
}
$collectionIndex = viewer_phase20_function_source($controller, 'cms_viewer_collections');
viewer_phase20_assert(str_contains($collectionIndex, 'viewer_verify_csrf_or_render_error()') && str_contains($collectionIndex, 'viewer_collection_create_nonce_consume('), 'Creation POST must use viewer CSRF and single-use submission nonce.');
viewer_phase20_assert(str_contains($controller, "viewer_collection_positive_id"), 'Collection/image ids must use strict positive integer parsing.');

// Plain-text/XSS policy stays centralized and output is escaped.
viewer_phase20_assert(str_contains($service, 'viewer_collection_title_validate($title)'), 'Create/rename must use canonical title validation.');
viewer_phase20_assert(str_contains($content, "'max_characters' => 120") && str_contains($content, "preg_match('//u'") && str_contains($content, 'ascii_control'), 'Existing title length/UTF-8/control policy must remain authoritative.');
viewer_phase20_assert(str_contains($controller, 'e((string) $collection[\'title\'])') && !str_contains($controller, 'innerHTML'), 'Collection titles must render through HTML escaping and never innerHTML.');
require_once $root . '/app/bootstrap.php';
foreach ([
    '<script>alert(1)</script>',
    '"><img src=x onerror=alert(1)>',
    '<svg/onload=alert(1)>',
    "\" ' < > &",
] as $xssTitle) {
    $prepared = \Gallery\Services\viewer_collection_title_prepare($xssTitle);
    viewer_phase20_assert(!empty($prepared['valid']), 'XSS-looking collection title should remain valid inert plain text.');
    $escaped = \Gallery\Core\e((string) $prepared['title']);
    viewer_phase20_assert(!str_contains($escaped, '<') && !str_contains($escaped, '>'), 'XSS-looking collection title must be HTML-escaped before rendering.');
}
viewer_phase20_assert(empty(\Gallery\Services\viewer_collection_title_prepare('')['valid']), 'Empty collection title must be rejected.');
viewer_phase20_assert(empty(\Gallery\Services\viewer_collection_title_prepare(str_repeat('a', 121))['valid']), 'Oversized collection title must be rejected.');
viewer_phase20_assert(empty(\Gallery\Services\viewer_collection_title_prepare("bad\x01title")['valid']), 'ASCII control characters must be rejected in collection titles.');
viewer_phase20_assert(empty(\Gallery\Services\viewer_collection_title_prepare("bad\xC3\x28")['valid']), 'Malformed UTF-8 must be rejected in collection titles.');

// Quotas/races: account row serializes collection count, collection row serializes item count, unique PK backs duplicates.
viewer_phase20_assert(str_contains($service, 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'), 'Collection creation quota must lock the viewer account row.');
viewer_phase20_assert(str_contains($service, 'FROM viewer_collections WHERE id = ? AND viewer_account_id = ? LIMIT 1 FOR UPDATE'), 'Item quota/reorder must lock the owned collection row.');
viewer_phase20_assert(str_contains($service, 'max_viewer_collections_per_account') && str_contains($service, 'max_viewer_items_per_collection'), 'Configured collection/item quotas must be enforced.');
viewer_phase20_assert(str_contains($rateLimits, "'viewer_collection_create_account' => ['max_attempts' => 10"), 'Collection creation must use the dedicated 10/hour account limiter.');
viewer_phase20_assert(str_contains($service, "'reason' => 'already_present'"), 'Repeated image add must be idempotent.');
viewer_phase20_assert(str_contains($service, 'function viewer_collection_normalize_positions') && str_contains($service, 'viewer_collection_normalize_positions($pdo, $collectionId)'), 'Collection item positions must be kept dense so remove/add churn cannot grow ordering keys without bound.');

// Add stores intent/reference only and rechecks authoritative source authorization before insertion.
$add = viewer_phase20_function_source($service, 'viewer_collection_item_add');
viewer_phase20_assert(strpos($add, 'viewer_source_image_can_reference($imageId)') < strpos($add, 'INSERT INTO viewer_collection_items'), 'Source authorization must happen before a collection-item insert.');
viewer_phase20_assert(str_contains($add, 'INSERT INTO viewer_collection_items (viewer_collection_id, image_id, position, created_at)'), 'Items must store canonical image references plus ordering only.');
viewer_phase20_assert(!str_contains($add, 'viewer_favourites'), 'Adding a collection item must not modify favourite state.');
foreach (['relative_path','thumbnail_path','gallery_password','share_token','authorization_state','cached_access'] as $forbidden) {
    viewer_phase20_assert(!str_contains($add, $forbidden), 'Collection add must not store source authority/path data: ' . $forbidden);
}

// Live rendering authorization uses the explicit no-admin-bypass source policy every time.
$detail = viewer_phase20_function_source($controller, 'cms_viewer_collection');
$visibleState = viewer_phase20_function_source($controller, 'viewer_collection_visible_state');
viewer_phase20_assert(str_contains($detail, 'viewer_collection_visible_state(') && str_contains($visibleState, 'viewer_collection_item_references(') && str_contains($visibleState, 'viewer_source_images_resolve_authorized('), 'Collection detail must reauthorize stored references on every render.');
$batch = viewer_phase20_function_source($content, 'viewer_source_images_resolve_authorized');
viewer_phase20_assert(str_contains($batch, 'visitor_can_access_gallery_without_admin_bypass($gallery)') && str_contains($batch, 'visitor_can_access_nsfw_content_without_admin_bypass()'), 'Batch render authorization must explicitly ignore Admin bypass.');
viewer_phase20_assert(str_contains($batch, "visibility = ?") && str_contains($batch, "['public']"), 'Only current public image rows may be returned by collection rendering.');
viewer_phase20_assert(!str_contains($batch, 'current_user()'), 'Live source authorization must not consult the Admin principal.');
viewer_phase20_assert(str_contains($detail, '$hiddenCount') && str_contains($controller, 'hidden_unavailable'), 'Inaccessible references must be omitted with generic feedback only.');
viewer_phase20_assert(str_contains($controller, 'Some saved collection items are currently unavailable.') && !str_contains($controller, 'hidden because their source gallery'), 'Inaccessible-item feedback must not disclose a source-gallery denial reason.');
viewer_phase20_assert(!str_contains($controller, 'relative_path') && !str_contains($controller, 'filename') && !str_contains($controller, 'EXIF'), 'Collection controller must not render inaccessible source metadata fields.');
viewer_phase20_assert(!str_contains($galleryAccess, 'viewer_collection'), 'Canonical gallery access must remain independent from collection membership.');
viewer_phase20_assert(!str_contains($publicMedia, 'viewer_collection'), 'Direct media authorization must remain independent from collection membership.');

// Reordering rejects oversized/duplicate/foreign ids before a committed update and updates positions transactionally.
$reorder = viewer_phase20_function_source($service, 'viewer_collection_reorder');
foreach (['oversized','duplicate_item','foreign_item','invalid_order'] as $reason) {
    viewer_phase20_assert(str_contains($reorder, "'reason' => '{$reason}'"), 'Reorder must reject ' . $reason . '.');
}
viewer_phase20_assert(str_contains($reorder, 'FOR UPDATE') && str_contains($reorder, 'beginTransaction()') && str_contains($reorder, 'rollBack()') && str_contains($reorder, 'commit()'), 'Reorder must be transactional and row-locked.');
viewer_phase20_assert(str_contains($reorder, 'WHERE viewer_collection_id = ? AND image_id = ?'), 'Reorder updates must remain collection-scoped.');
viewer_phase20_assert(str_contains(viewer_phase20_function_source($controller, 'viewer_collection_render_reorder_form'), 'move_image_id') && !str_contains(viewer_phase20_function_source($controller, 'viewer_collection_render_reorder_form'), 'image_ids[]'), 'Move controls must submit constant-size forms instead of serializing the full collection into every image card.');

// Delete/remove are reference-only operations and leave source objects/favourites untouched.
$delete = viewer_phase20_function_source($service, 'viewer_collection_delete');
viewer_phase20_assert(str_contains($delete, 'DELETE FROM viewer_collections') && !preg_match('/DELETE\s+FROM\s+(images|galleries|viewer_favourites|gallery_share)/i', $delete), 'Collection delete must touch only the owned collection row.');
$remove = viewer_phase20_function_source($service, 'viewer_collection_item_remove');
viewer_phase20_assert(str_contains($remove, 'DELETE FROM viewer_collection_items') && !str_contains($remove, 'DELETE FROM images') && !str_contains($remove, 'viewer_favourites'), 'Item removal must not touch source media/favourites.');

// Personalized UI is viewer-gated and the feature switch/schema status fail closed without coupling public authorization.
viewer_phase20_assert(str_contains(viewer_phase20_function_source($service, 'viewer_collections_storage_available'), 'viewer_accounts_enabled()'), 'Collection storage must respect the existing viewer account feature switch.');
viewer_phase20_assert(str_contains(viewer_phase20_function_source($controller, 'viewer_collection_require_viewer'), 'viewer_http_auth_available()') && str_contains(viewer_phase20_function_source($controller, 'viewer_collection_require_viewer'), 'viewer_collections_storage_available()'), 'Private collection routes must fail closed on viewer/schema unavailability.');
viewer_phase20_assert(str_contains(viewer_phase20_function_source($accountsController, 'viewer_http_no_store'), "header('Cache-Control: private, no-store, max-age=0')"), 'Private collection pages must inherit the exact viewer private/no-store cache boundary.');
viewer_phase20_assert(str_contains($publicGallery, '$viewerPrincipal = current_viewer();') && str_contains($publicGallery, '$viewerCollectionControlsEnabled = $viewerPrincipal !== null'), 'Public gallery collection UI must be viewer-only.');
viewer_phase20_assert(str_contains($publicGallery, '$viewerCollections = $viewerCollectionControlsEnabled') && str_contains($publicGallery, ': [];'), 'Anonymous public gallery HTML must not query/render private collection metadata.');
viewer_phase20_assert(str_contains($smartGalleries, '$viewerCollectionRequiresSourceRecheck') && str_contains($smartGalleries, 'viewer_source_image_can_reference'), 'Dual Admin+viewer Smart Gallery controls must recheck source access without Admin authority.');
viewer_phase20_assert(str_contains($favouritesController, 'render_viewer_collection_add_control_html'), 'Favourites page should reuse the same collection-add control.');
viewer_phase20_assert(str_contains($accountsController, "url_for('viewer_collections')"), 'Viewer account page must expose private collections.');
viewer_phase20_assert(str_contains($css, '.viewer-collection-card-overlay'), 'Collection controls require scoped presentation styling.');

// Phase 2.0 ownership/storage remains isolated even after Phase 3 activates sharing in dedicated modules.
foreach (['viewer_profile','viewer_upload','viewer_signup','viewer_totp','viewer_oidc','viewer_passkey'] as $forbiddenRoute) {
    viewer_phase20_assert(!str_contains($dispatch, "'{$forbiddenRoute}' =>"), 'Out-of-scope route must remain absent: ' . $forbiddenRoute);
}
viewer_phase20_assert(!str_contains($service, 'viewer_collection_share_tokens'), 'Phase 2.0 collection service must remain independent from Phase 3 share storage.');
viewer_phase20_assert(!str_contains($controller, 'viewer_collection_share_tokens'), 'Phase 2.0 collection controller must not access Phase 3 share storage directly.');
viewer_phase20_assert(str_contains($controller, 'render_viewer_collection_share_owner_section'), 'Phase 2.0 detail may integrate the dedicated Phase 3 owner-share renderer without absorbing share authority logic.');

// Translation catalogs must remain aligned for the four selectable languages.
$languageKeys = null;
foreach (['en','cs','de','sv'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
    viewer_phase20_assert(isset($catalog['viewer.collections.title'], $catalog['viewer.collections.add'], $catalog['viewer.collections.invalid_order']), 'Collection translations missing for ' . $language . '.');
    $keys = array_keys($catalog);
    if ($languageKeys === null) $languageKeys = $keys;
    else viewer_phase20_assert($keys === $languageKeys, 'Selectable language keys must remain aligned: ' . $language);
}

fwrite(STDOUT, "Viewer collections Phase 2.0 regression checks passed\n");
