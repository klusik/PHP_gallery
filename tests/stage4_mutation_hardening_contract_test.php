<?php

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';

/**
 * Focused Stage 4 source-boundary contracts for mutation synchronization hardening.
 *
 * Runtime behavior for operation generations, stale-cache rejection, aborts, and
 * postcondition evaluation is covered by admin_mutation_stage4_hardening_test.mjs.
 * These assertions protect the server-render metadata and workflow wiring that the
 * browser coordinator depends on but cannot discover from an isolated DOM fixture.
 */

$root = dirname(__DIR__);

/** Read one repository source file or fail with a concise diagnostic. */
function stage4_source(string $relative): string
{
    global $root;
    // module_source() also covers controllers that are split into part files.
    $source = module_source($root . '/' . $relative);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
}

/** Throw a concise exception when one Stage 4 boundary is absent. */
function stage4_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$helper = stage4_source('app/helpers_mutation.php');
foreach (['gallery_membership', 'image_order', 'image_updated_at', 'gallery_image_revision', 'smart_gallery_presence'] as $postconditionType) {
    stage4_expect(str_contains($helper, "'" . $postconditionType . "'"), 'Canonical mutation helper is missing Stage 4 postcondition type ' . $postconditionType . '.');
}
stage4_expect(str_contains($helper, "'postcondition' => " . '$postcondition'), 'Gallery/tag contexts must serialize explicit null postconditions instead of omitting the field.');
stage4_expect(str_contains($helper, 'admin_mutation_gallery_context_count'), 'Physical gallery membership verification must use an authoritative full-context count.');
stage4_expect(str_contains($helper, 'admin_mutation_gallery_membership_postcondition'), 'Physical gallery workflows must share one pagination-safe membership postcondition builder.');

$galleryPage = stage4_source('app/controllers/public_gallery_page.php');
foreach ([
    'data-admin-mutation-canonical-url',
    'data-public-context-gallery-id',
    'data-public-subgallery-total-count',
    'data-public-subgallery-revision',
    'data-public-gallery-total-pages',
    'data-public-image-total-count',
    'data-public-image-revision',
    'data-public-image-total-pages',
    'data-public-image-updated-at',
    'data-public-smart-gallery-count',
] as $metadata) {
    stage4_expect(str_contains($galleryPage, $metadata), 'Gallery render is missing Stage 4 ownership/state metadata ' . $metadata . '.');
}

$galleryHome = stage4_source('app/controllers/public_gallery_home.php');
foreach (['data-public-root-gallery-count', 'data-public-root-gallery-revision', 'data-public-gallery-page', 'data-public-gallery-total-pages', 'data-public-root-smart-gallery-count', 'data-admin-mutation-canonical-url'] as $metadata) {
    stage4_expect(str_contains($galleryHome, $metadata), 'Root gallery index is missing Stage 4 ownership/state metadata ' . $metadata . '.');
}

$tags = stage4_source('app/controllers/public_tags.php');
stage4_expect(str_contains($tags, 'data-admin-mutation-canonical-url'), 'Tag landing pages must expose their canonical refresh owner URL.');

$cards = stage4_source('app/controllers/public_gallery_cards.php');
stage4_expect(str_contains($cards, 'data-smart-gallery-placement'), 'Smart Gallery cards must expose their rendered placement.');
stage4_expect(str_contains($cards, 'data-smart-gallery-placement-order'), 'Smart Gallery cards must expose their rendered placement order.');

$reorder = stage4_source('app/controllers/admin_images_reorder.php');
stage4_expect(str_contains($reorder, "admin_mutation_postcondition('image_order'"), 'Image reorder must verify the persisted order, not merely refresh the gallery.');

$editActions = stage4_source('app/controllers/admin_galleries_edit_actions.php');
stage4_expect(str_contains($editActions, 'gallery_lightbox_state_summary') && str_contains($editActions, "'revision' => " . '$imageStateRevision'), 'Image edit workflows must expose gallery-wide state for pagination-safe verification.');
stage4_expect(str_contains($editActions, "admin_mutation_gallery_membership_postcondition"), 'Reparent/move workflows must verify source and destination membership with full counts.');

$metadataOrganizer = stage4_source('app/controllers/admin_galleries_edit_metadata.php');
stage4_expect(str_contains($metadataOrganizer, "admin_mutation_postcondition('gallery_image_count'"), 'Metadata Organizer source/destination contexts must verify authoritative image counts.');

$renamer = stage4_source('app/controllers/admin_galleries_edit_page.php');
stage4_expect(str_contains($renamer, "admin_mutation_postcondition('gallery_image_revision'"), 'Media Renamer must verify a gallery-wide image revision so off-page renames remain observable.');

$smartGalleries = stage4_source('app/controllers/smart_galleries.php');
stage4_expect(str_contains($smartGalleries, 'smart_gallery_admin_context_postcondition'), 'Smart Gallery mutations must share an authoritative placement/presence postcondition builder.');
stage4_expect(str_contains($smartGalleries, "admin_mutation_postcondition('smart_gallery_presence'"), 'Smart Gallery mutations must verify placement/presence state.');

$thumbnails = stage4_source('app/controllers/admin_thumbnails.php');
stage4_expect(preg_match('/admin_mutation_public_gallery_context\([^;]*?,\s*null\s*\)/s', $thumbnails) === 1, 'Thumbnail repair is the intentional no-immediate-postcondition exception and must declare null explicitly.');

$coordinator = stage4_source('public/assets/gallery-modules/admin-mutation-completion.js');
foreach ([
    'beginAdminMutationOperation',
    'adminMutationOperationContextIsCurrent',
    'adminMutationPanelGuard',
    'mutationActiveRefreshByContext',
    'new AbortController()',
    "lastStatus = 'stale-response'",
    'adminMutationDiagnosticRecord',
    'safeAdminMutationDiagnosticPath',
    "lastStatus = 'refreshed-unverified'",
    'data-admin-mutation-canonical-url',
] as $contract) {
    stage4_expect(str_contains($coordinator, $contract), 'Coordinator is missing Stage 4 hardening contract: ' . $contract);
}

$sidePanel = stage4_source('public/assets/gallery-modules/admin-side-panel.js');
stage4_expect(str_contains($sidePanel, 'completionGuard?.signal || undefined'), 'Side-panel refresh fetches must participate in coordinator aborts.');
stage4_expect(str_contains($sidePanel, 'completionGuard.isCurrent()'), 'Side-panel replacements must reject superseded operation generations.');
stage4_expect(str_contains($sidePanel, 'admin-mutation-completion.js?v=20260902-create-delete-hotfix1'), 'Side-panel module must load the current coordinator cache version.');

$operations = stage4_source('public/assets/gallery-modules/admin-operations.js');
stage4_expect(str_contains($operations, 'admin-side-panel.js?v=20260903-oversized-single-batch-v1'), 'Admin operations must load the current side-panel cache version.');
$galleryJs = stage4_source('public/assets/gallery.js');
stage4_expect(str_contains($galleryJs, 'admin-operations.js?v=20260903-oversized-single-batch-v1'), 'Gallery entrypoint must load the current admin-operations cache version.');

echo "Stage 4 mutation hardening contracts passed.\n";
