<?php

declare(strict_types=1);

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/check_admin_mutation_contracts.php
 * Module Type: Repository Contract Check
 *
 * Purpose:
 *   Protect the canonical Admin side-panel mutation completion architecture from
 *   common regressions that are difficult to spot in isolated workflow testing.
 *
 * Responsibilities:
 *   - Reject browser-side reconstruction of legacy mutation completion metadata
 *   - Protect side-panel success paths from hard reload/navigation/history hacks
 *   - Verify classic upload batching preserves canonical mutation metadata and IDs
 *   - Verify dynamic panel forms retain delegated/lifecycle-safe interception
 *   - Protect known AJAX bulk actions from redirect fall-through after persistence
 *   - Keep postcondition retry ownership centralized in the shared coordinator
 *   - Protect mutation-triggered lightbox teardown from early-return TDZ regressions
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
 *   - This is intentionally a focused source-contract check, not a JavaScript parser.
 *   - Legitimate direct-page navigation outside enhanced side-panel success paths is allowed.
 *   - Keep checks tied to architectural invariants rather than formatting trivia.
 *
 * Last Updated:
 *   2026-09-02
 */

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

/**
 * Read one repository file or record a failure.
 */
function contract_file(string $root, string $relative, array &$failures): string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        $failures[] = $relative . ': file could not be read.';
        return '';
    }

    // A module split into part files still forms one contract surface. Append the
    // parts in require_once order so before/after offset checks keep their meaning.
    $partDirectory = dirname($path) . DIRECTORY_SEPARATOR . basename($path, '.php');
    if (is_dir($partDirectory)) {
        $matches = [];
        preg_match_all("#require_once __DIR__ \. '/([^']+)';#", $contents, $matches);
        foreach ($matches[1] as $partRelative) {
            $partPath = dirname($path) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $partRelative);
            if (!str_starts_with($partPath, $partDirectory . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $part = @file_get_contents($partPath);
            if (is_string($part)) {
                $contents .= "\n" . $part;
            }
        }
    }

    // Source contracts describe code structure rather than repository line-ending style.
    // Normalize CRLF/CR so multiline literal checks behave identically on all platforms.
    return str_replace(["\r\n", "\r"], "\n", $contents);
}

/**
 * Require a literal source token.
 */
function contract_require(string $contents, string $needle, string $label, array &$failures, int &$checks): void
{
    $checks++;
    if (!str_contains($contents, $needle)) {
        $failures[] = $label;
    }
}

/**
 * Forbid a literal source token.
 */
function contract_forbid(string $contents, string $needle, string $label, array &$failures, int &$checks): void
{
    $checks++;
    if (str_contains($contents, $needle)) {
        $failures[] = $label;
    }
}

/**
 * Require an exact literal occurrence count.
 */
function contract_count(string $contents, string $needle, int $expected, string $label, array &$failures, int &$checks): void
{
    $checks++;
    $actual = substr_count($contents, $needle);
    if ($actual !== $expected) {
        $failures[] = $label . ' Expected ' . $expected . ', found ' . $actual . '.';
    }
}

/**
 * Require one source token to appear before another source token.
 */
function contract_require_before(string $contents, string $first, string $second, string $label, array &$failures, int &$checks): void
{
    $checks++;
    $firstOffset = strpos($contents, $first);
    $secondOffset = strpos($contents, $second);
    if ($firstOffset === false || $secondOffset === false || $firstOffset >= $secondOffset) {
        $failures[] = $label;
    }
}

/**
 * Return a bounded source section between two stable markers.
 */
function contract_section(string $contents, string $start, string $end): string
{
    $startOffset = strpos($contents, $start);
    if ($startOffset === false) {
        return '';
    }
    $endOffset = strpos($contents, $end, $startOffset + strlen($start));
    if ($endOffset === false) {
        return substr($contents, $startOffset);
    }
    return substr($contents, $startOffset, $endOffset - $startOffset);
}

$coordinator = contract_file($root, 'public/assets/gallery-modules/admin-mutation-completion.js', $failures);
$sidePanel = contract_file($root, 'public/assets/gallery-modules/admin-side-panel.js', $failures);
$browserUpload = contract_file($root, 'public/assets/gallery-modules/admin-browser-upload.js', $failures);
$browserUploadsService = contract_file($root, 'app/services/browser_uploads.php', $failures);
$metadataOrganizer = contract_file($root, 'public/assets/gallery-modules/admin-metadata-organizer.js', $failures);
$mediaRenamer = contract_file($root, 'public/assets/gallery-modules/admin-media-renamer.js', $failures);
$duplicateDetector = contract_file($root, 'public/assets/gallery-modules/admin-duplicate-photo-detector.js', $failures);
$bulkController = contract_file($root, 'app/controllers/admin_images_bulk.php', $failures);
$galleryEditActions = contract_file($root, 'app/controllers/admin_galleries_edit_actions.php', $failures);
$publicInlineController = contract_file($root, 'app/controllers/admin_public_inline.php', $failures);
$security = contract_file($root, 'app/security.php', $failures);
$lightbox = contract_file($root, 'public/assets/gallery-modules/lightbox.js', $failures);

// The coordinator must accept only the canonical successful envelope. Browser-side
// synthesis from old top-level gallery/image/url fields would hide a broken server contract.
contract_require($coordinator, "input.ok !== true || !mutation || !Array.isArray(input.contexts)", 'Coordinator no longer strictly validates the canonical success envelope.', $failures, $checks);
contract_forbid($coordinator, 'refresh_gallery_id', 'Coordinator contains workflow-specific refresh_gallery_id compatibility reconstruction.', $failures, $checks);
contract_forbid($coordinator, 'created_gallery', 'Coordinator contains create/upload compatibility reconstruction.', $failures, $checks);
contract_require($coordinator, 'ADMIN_MUTATION_RETRY_DELAYS_MS', 'Shared bounded retry policy is missing from the coordinator.', $failures, $checks);
contract_require($coordinator, 'Object.freeze([0, 150, 450, 1000, 2000])', 'Coordinator retry budget is too short for bounded shared-hosting read-after-write convergence.', $failures, $checks);
contract_require($coordinator, "'Cache-Control': 'no-cache'", 'Coordinator public refresh no longer sends an explicit no-cache request directive.', $failures, $checks);
contract_forbid($coordinator, "'X-Requested-With': 'XMLHttpRequest'", 'Coordinator public HTML refresh is incorrectly classified as an AJAX mutation request.', $failures, $checks);
contract_require($coordinator, 'mergeAdminMutationRenderViewState(persistedRenderUrl, currentUrl)', 'Coordinator no longer preserves visible parent pagination/filter state when resolving the authoritative render URL.', $failures, $checks);
contract_require($coordinator, "['page', 'public_path', 'gallery_path', 'slug', 'id', '_panel_refresh']", 'Coordinator visible-state merge no longer protects routing query parameters.', $failures, $checks);
contract_require($coordinator, "cache: 'no-store'", 'Coordinator refreshes must retain no-store semantics.', $failures, $checks);
contract_forbid($coordinator, 'window.location.reload', 'Coordinator must never hard reload the page.', $failures, $checks);
contract_forbid($coordinator, 'window.location.href =', 'Coordinator must never navigate after enhanced mutation success.', $failures, $checks);
contract_forbid($coordinator, 'history.replaceState', 'Coordinator must not rewrite browser history to mask canonical URL mismatch.', $failures, $checks);
// Public fragment replacement can re-run lightbox setup on parent gallery views that
// contain no photo cards. Cleanup state referenced by the registered teardown callback
// must therefore be initialized before setup can return early.
contract_count($lightbox, 'let lightboxHiddenCleanupTimer = 0;', 1, 'Lightbox hidden cleanup timer must have exactly one lifecycle declaration.', $failures, $checks);
contract_require_before(
    $lightbox,
    'let lightboxHiddenCleanupTimer = 0;',
    'galleryLightboxState.cleanup = () => {',
    'Lightbox hidden cleanup timer is initialized after cleanup registration, recreating a teardown TDZ failure.',
    $failures,
    $checks
);
contract_require_before(
    $lightbox,
    'let lightboxHiddenCleanupTimer = 0;',
    'if (!overlay || cards.length === 0) {',
    'Lightbox hidden cleanup timer is initialized after the no-lightbox early return.',
    $failures,
    $checks
);

// Direct card observation is the strongest gallery-membership invariant. Aggregate
// counts are only a fallback for targets that may legitimately live off-page.
contract_require($coordinator, 'if (expectedPresent && cardPresent)', 'Created/moved gallery cards no longer win over auxiliary count metadata.', $failures, $checks);
contract_require($coordinator, 'if (!expectedPresent && cardPresent)', 'Gallery deletion no longer rejects a freshly rendered stale target card.', $failures, $checks);
contract_require($coordinator, 'if (!paginated)', 'Gallery membership verification no longer distinguishes direct unpaginated absence from off-page pagination.', $failures, $checks);

// Public card deletes must never leak HTML diagnostics or authentication redirects into
// an enhanced JSON mutation response. Buffered diagnostics remain observable in logs.
contract_require($publicInlineController, 'admin_public_inline_json_buffer_start($wantsJson)', 'Public gallery delete no longer protects its JSON response from accidental PHP output.', $failures, $checks);
contract_require($publicInlineController, "'gallery.public_json_output_discarded'", 'Discarded public mutation output is no longer recorded in the Admin log.', $failures, $checks);
contract_require($publicInlineController, '$diagnosticBufferLevel = ob_get_level();', 'Public mutation diagnostic logging can regress to corrupting the cleaned JSON response.', $failures, $checks);
contract_require($publicInlineController, 'admin_public_inline_json_response(admin_mutation_success_envelope(', 'Public gallery delete success no longer uses the clean JSON response boundary.', $failures, $checks);
contract_require($publicInlineController, 'admin_public_inline_json_response(admin_mutation_error_envelope(', 'Public gallery delete failure no longer uses the clean JSON response boundary.', $failures, $checks);
contract_require($security, 'function security_request_wants_json(): bool', 'Security boundary no longer detects enhanced JSON mutation requests.', $failures, $checks);
contract_require($security, "'error_code' => 'auth.admin_required'", 'Expired Admin AJAX sessions can regress to an HTML login redirect.', $failures, $checks);
contract_require($security, "'error_code' => 'security.invalid_csrf'", 'AJAX CSRF failures can regress to non-JSON output.', $failures, $checks);

// Side-panel ownership allows exactly two direct navigation fallbacks: the non-panel
// upload completion path and failure to mount a panel at all. No success handler may add another.
contract_forbid($sidePanel, 'window.location.reload', 'Side-panel module contains a hard reload.', $failures, $checks);
contract_forbid($sidePanel, 'history.replaceState', 'Side-panel module contains history.replaceState().', $failures, $checks);
contract_count($sidePanel, 'window.location.href =', 2, 'Unexpected side-panel window.location assignment count.', $failures, $checks);
contract_require($sidePanel, "if (galleryUploadCompletesInSidePanel(form)) {\n            dispatchAdminSidePanelSuccess(form, result);\n            return;\n        }\n        window.location.href =", 'Direct upload redirect is no longer guarded by panel completion ownership.', $failures, $checks);
contract_require($sidePanel, "if (!(body instanceof HTMLElement)) {\n        window.location.href = link.href;", 'Panel-mount failure navigation fallback changed or disappeared.', $failures, $checks);
contract_forbid($sidePanel, 'refreshCurrentGalleryContextFromServer', 'Obsolete duplicate gallery refresh helper is still present.', $failures, $checks);
contract_forbid($sidePanel, 'replaceAdminEditorMainFromParsedDocument', 'Obsolete full editor replacement helper is still present.', $failures, $checks);
contract_require($sidePanel, 'runAdminSidePanelSuccessTask', 'Side-panel async success reflections no longer have a rejection boundary.', $failures, $checks);
contract_require($sidePanel, "console.error('PHP Gallery side-panel success reflection failed after persistence.'", 'Side-panel post-persistence reflection failures are no longer surfaced for diagnostics.', $failures, $checks);
contract_require($sidePanel, 'function renderedFormActionRequestUrl(form)', 'Server-rendered mutation forms can regress to absolute-host fetches that lose the active Admin session.', $failures, $checks);
contract_count($sidePanel, 'fetch(renderedFormActionRequestUrl(form), {', 4, 'Expected current-origin normalization is missing from one or more side-panel form mutation fetches.', $failures, $checks);

// Unrelated gallery edits must not trigger remote favicon discovery after persistence.
// Visibility-only saves are the canonical regression case: the database row can be
// committed while the JSON response remains blocked behind external network latency.
contract_require($galleryEditActions, '$faviconDescriptionsBeforeSave = link_favicon_gallery_descriptions($galleryId);', 'Gallery save no longer snapshots description content before persistence.', $failures, $checks);
contract_require($galleryEditActions, 'if ($faviconDescriptionsBeforeSave !== $faviconDescriptionsAfterSave)', 'Gallery save can run favicon network discovery without a description-content change.', $failures, $checks);

// Visibility saves must verify the actual state the admin changed. updated_at remains
// useful for ordinary metadata invalidation, but it is an indirect and unnecessarily
// fragile completion invariant for Published/Unpublished transitions.
contract_require($galleryEditActions, '$visibilityChanged = $originalVisibility !== $galleryVisibility;', 'Gallery save no longer detects effective visibility transitions for completion.', $failures, $checks);
contract_require($galleryEditActions, "admin_mutation_postcondition('gallery_visibility', [", 'Gallery visibility saves no longer emit an exact visibility postcondition.', $failures, $checks);
contract_require($galleryEditActions, "'visibility' => \$galleryVisibility", 'Gallery visibility postcondition no longer carries the persisted effective visibility.', $failures, $checks);
contract_require($galleryEditActions, "'gallery_visibility_changed' => \$visibilityChanged", 'Gallery save response no longer exposes whether a visibility transition actually occurred.', $failures, $checks);
contract_require($sidePanel, 'updatePublicGalleryCardVisibilityFromResult(result);', 'Gallery edit no longer performs the bounded immediate visibility reflection before canonical refresh.', $failures, $checks);
contract_require($sidePanel, "./admin-mutation-completion.js?v=20260902-create-delete-hotfix1", 'Side-panel mutation coordinator cache-busting import was not advanced with the create/delete refresh fix.', $failures, $checks);

// Classic one-file batching must carry the same canonical metadata as browser-assisted
// ZIP batching. Stable image IDs must survive aggregation for mutation.entity_ids.
contract_require($sidePanel, 'requireCanonicalUploadMutationResult', 'Classic upload does not enforce the canonical mutation response.', $failures, $checks);
contract_require($sidePanel, 'const imageIds = [];', 'Classic upload no longer aggregates stable uploaded image IDs.', $failures, $checks);
contract_require($sidePanel, 'mutation: finalMutation', 'Classic upload final response drops mutation metadata.', $failures, $checks);
contract_require($sidePanel, 'contexts,', 'Classic upload final response drops affected contexts.', $failures, $checks);
contract_require($sidePanel, 'fallback: {...fallback, redirect_url: finalRedirectUrl}', 'Classic upload final response drops fallback metadata.', $failures, $checks);
contract_require($sidePanel, 'entity_ids: imageIds.slice()', 'Classic existing-gallery upload no longer preserves affected image IDs.', $failures, $checks);

// Browser-assisted upload has two distinct fallback concepts. The literal boolean
// true is now reserved for an empty create-gallery submission with no media to
// prepare, while every successful canonical mutation envelope carries fallback
// metadata as an object. Selected photos with browser processing checked must never
// silently switch to PHP thumbnail generation after a client-side failure.
contract_require($sidePanel, 'result?.fallback === true', 'Browser upload success can regress to replaying the classic create/upload path.', $failures, $checks);
contract_forbid($sidePanel, 'if (!result || result.fallback) {', 'Browser upload fallback detection is using canonical fallback metadata as a boolean.', $failures, $checks);
contract_require($sidePanel, 'empty create-gallery submission where there is no media to prepare.', 'Create-with-upload fallback invariant is no longer documented next to the branch.', $failures, $checks);
contract_require($sidePanel, 'const useBrowserUpload = browserUploadRequested(form);', 'Upload path selection no longer snapshots the explicit browser-processing choice.', $failures, $checks);
contract_require($sidePanel, 'silently switch selected photos to server-side thumbnail generation.', 'Checked browser processing can regress to a silent server fallback.', $failures, $checks);
contract_require($browserUpload, "return {fallback: true, reason: 'no_files'};", 'Browser upload may no longer distinguish empty gallery creation from selected-media processing.', $failures, $checks);
contract_forbid($browserUpload, "return {fallback: true, reason: capability.reason};", 'Browser capability failure can silently fall back to server processing.', $failures, $checks);
contract_forbid($browserUpload, "return {fallback: true, reason: error instanceof Error ? error.message : 'preparation_failed'};", 'Browser preparation failure can silently fall back to server processing.', $failures, $checks);
contract_require($browserUpload, "body.set('prepared_thumbnails_required', preparedThumbnailsRequired ? '1' : '0');", 'Browser upload no longer tells the server to require prepared thumbnail coverage.', $failures, $checks);
contract_require($browserUpload, 'const maxBytes = Math.max(targetBytes, Number(config.uploadLimitBytes || targetBytes));', 'Browser upload can regress to treating the configured batch target as the hard limit for one oversized image package.', $failures, $checks);
contract_require($browserUpload, 'let the oversized image stand alone', 'Oversized-single-image batch behavior is no longer documented next to the packing branch.', $failures, $checks);
contract_require($browserUploadsService, 'browser_upload_parse_store_zip($tmpName, $uploadLimit);', 'Server browser-upload validation can regress to rejecting a singleton batch merely because it exceeds the soft ZIP target.', $failures, $checks);

// Metadata Organizer batching may aggregate counters, but completion semantics must be
// the canonical envelope from the final successful server batch, never URL reconstruction.
contract_require($metadataOrganizer, 'mergeApplyMutationEnvelope', 'Metadata Organizer does not preserve the canonical mutation envelope.', $failures, $checks);
contract_forbid($metadataOrganizer, 'aggregate.mutation_envelope || {', 'Metadata Organizer still synthesizes a legacy completion result.', $failures, $checks);
contract_forbid($metadataOrganizer, 'mergeApplyPayloadUrls', 'Obsolete Metadata Organizer URL compatibility adapter is still present.', $failures, $checks);

// Dynamically injected panel forms must be caught by delegated or lifecycle-safe handlers.
$dynamicContracts = [
    ['app/views/admin_gallery_forms.php', 'data-gallery-panel-create-form', $sidePanel, "form.matches('[data-gallery-panel-create-form]')"],
    ['app/controllers/admin_uploads.php', 'data-gallery-upload-form', $sidePanel, "document.querySelectorAll('[data-gallery-upload-form]')"],
    ['app/controllers/admin_galleries_edit_page.php', 'data-admin-panel-scan-images-form', $sidePanel, "form.matches('[data-admin-panel-scan-images-form]')"],
    ['app/controllers/admin_galleries_edit_views.php', 'data-admin-panel-ai-reprocess-form', $sidePanel, "form.matches('[data-admin-panel-ai-reprocess-form]')"],
    ['app/controllers/admin_galleries_edit_page.php', 'data-admin-image-bulk-form', $sidePanel, "body.querySelector('[data-admin-image-bulk-form]')"],
    ['app/controllers/upload_automation.php', 'data-admin-upload-automation-token-form', $sidePanel, "form.matches('[data-admin-upload-automation-token-form]')"],
    ['app/controllers/smart_galleries.php', 'data-smart-gallery-panel-form', $sidePanel, "form.matches('[data-smart-gallery-panel-form]')"],
];
foreach ($dynamicContracts as [$serverFile, $serverMarker, $browserContents, $browserMarker]) {
    $serverContents = contract_file($root, $serverFile, $failures);
    contract_require($serverContents, $serverMarker, $serverFile . ': side-panel form marker is missing: ' . $serverMarker, $failures, $checks);
    contract_require($browserContents, $browserMarker, 'Browser interception is missing for dynamic marker: ' . $serverMarker, $failures, $checks);
}
contract_require($sidePanel, "document.addEventListener('submit'", 'Side-panel dynamic form interception is no longer delegated.', $failures, $checks);
contract_require($metadataOrganizer, "document.addEventListener('submit', handleMetadataOrganizerSubmit, true)", 'Metadata Organizer dynamic forms are no longer delegated.', $failures, $checks);
contract_require($mediaRenamer, "document.addEventListener('submit'", 'Media Renamer dynamic forms are no longer delegated.', $failures, $checks);
contract_require($duplicateDetector, "document.addEventListener('submit'", 'Duplicate Detector dynamic forms are no longer delegated.', $failures, $checks);

// The historic bulk visibility/NSFW defect was a successful AJAX write falling into
// classic redirect behavior. Protect those action families explicitly.
$visibilityStart = "if (in_array(\$action, ['draft', 'public', 'private'], true))";
$visibilityEnd = "if (in_array(\$action, ['nsfw_on', 'nsfw_off'], true) && !nsfw_guard_schema_ready())";
$visibilitySection = contract_section($bulkController, $visibilityStart, $visibilityEnd);
contract_require($visibilitySection, 'if (admin_wants_json())', 'Bulk visibility mutation no longer has an AJAX success branch.', $failures, $checks);
contract_require($visibilitySection, 'admin_bulk_images_success_response', 'Bulk visibility AJAX branch no longer returns the canonical mutation response.', $failures, $checks);
contract_require($visibilitySection, 'return;', 'Bulk visibility AJAX branch can fall through to redirect handling.', $failures, $checks);
$nsfwStart = "if (in_array(\$action, ['nsfw_on', 'nsfw_off'], true)) {";
$nsfwEnd = "if (\$action === 'cover')";
$nsfwSection = contract_section($bulkController, $nsfwStart, $nsfwEnd);
contract_require($nsfwSection, 'if (admin_wants_json())', 'Bulk NSFW mutation no longer has an AJAX success branch.', $failures, $checks);
contract_require($nsfwSection, 'admin_bulk_images_success_response', 'Bulk NSFW AJAX branch no longer returns the canonical mutation response.', $failures, $checks);
contract_require($nsfwSection, 'return;', 'Bulk NSFW AJAX branch can fall through to redirect handling.', $failures, $checks);

// Retry/backoff for postcondition synchronization belongs in the coordinator. The
// known workflow modules below may yield for UI work, but must not define retry loops.
foreach ([
    'public/assets/gallery-modules/admin-side-panel.js' => $sidePanel,
    'public/assets/gallery-modules/admin-metadata-organizer.js' => $metadataOrganizer,
    'public/assets/gallery-modules/admin-media-renamer.js' => $mediaRenamer,
    'public/assets/gallery-modules/admin-duplicate-photo-detector.js' => $duplicateDetector,
] as $relative => $contents) {
    contract_forbid($contents, 'retryDelays', $relative . ': workflow-specific retry delay table detected.', $failures, $checks);
    contract_forbid($contents, 'maxRetries', $relative . ': workflow-specific retry budget detected.', $failures, $checks);
    contract_forbid($contents, 'retryCount', $relative . ': workflow-specific retry counter detected.', $failures, $checks);
}

if ($failures !== []) {
    fwrite(STDERR, "Admin mutation contract check FAILED (" . count($failures) . " failure(s), " . $checks . " checks).\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo 'Admin mutation contract check passed (' . $checks . " checks).\n";
