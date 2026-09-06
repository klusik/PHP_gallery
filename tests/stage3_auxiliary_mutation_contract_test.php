<?php

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';

/**
 * Focused Stage 3 source-boundary contracts for embedded persistent mutations.
 *
 * These assertions intentionally verify integration seams that cannot be run in
 * the CLI without a browser: canonical server envelopes, delegated/coordinator
 * bridges, workflow-owned fragments, and the absence of enhanced hard reloads.
 */

$root = dirname(__DIR__);

/** @return string */
function stage3_source(string $relative): string
{
    global $root;
    // module_source() also covers controllers that are split into part files.
    $source = module_source($root . '/' . $relative);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
}

/** Throw a concise exception when one Stage 3 boundary is absent. */
function stage3_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$canonicalControllers = [
    'app/controllers/admin_galleries_discovery.php' => ['gallery.create', 'admin_mutation_public_gallery_context', 'redirect_to('],
    'app/controllers/admin_galleries_edit_actions.php' => ['image.scan_import', 'image.update', 'image_visibility', 'admin_mutation_panel_metadata'],
    'app/controllers/admin_galleries_edit_metadata.php' => ['image.metadata_organize', 'admin_mutation_success_envelope', 'admin_mutation_public_gallery_context'],
    'app/controllers/admin_galleries_edit_page.php' => ['image.ai_metadata_reprocess', 'image.media_rename', 'background_queued', "admin_mutation_panel_metadata('gallery-edit'"],
    'app/controllers/admin_images_reorder.php' => ['image.reorder', 'admin_mutation_success_envelope', 'admin_mutation_public_gallery_context'],
    'app/controllers/admin_tags.php' => ['tag.update', 'admin_mutation_public_tag_context', 'tag_identity'],
    'app/controllers/smart_galleries.php' => ['admin_mutation_success_envelope', 'admin_mutation_error_envelope', 'smart_gallery.'],
    'app/controllers/admin_public_inline.php' => ['image.delete', 'image_absent', 'admin_mutation_public_gallery_context'],
    'app/controllers/upload_automation.php' => ["'upload_automation.token.'", "admin_mutation_panel_metadata('gallery-edit'", 'admin_mutation_error_envelope'],
    'app/controllers/gallery_migration.php' => ['gallery.migration.pull', 'admin_mutation_success_envelope', 'admin_mutation_error_envelope'],
    'app/controllers/admin_thumbnails.php' => ['thumbnail.', 'admin_mutation_success_envelope', 'admin_mutation_public_gallery_context'],
    'app/controllers/admin_gallery_dates.php' => ['gallery.date_range_update', 'gallery_updated_at', 'admin_mutation_public_gallery_context'],
    'app/controllers/admin_duplicate_photos.php' => ['duplicate_photo_ledger.ignore_pair', 'duplicate_photo_ledger.ignore_gallery', 'duplicate_photo_ledger.clear', 'admin_mutation_error_envelope'],
];

foreach ($canonicalControllers as $file => $contracts) {
    $source = stage3_source($file);
    foreach ($contracts as $contract) {
        stage3_expect(str_contains($source, $contract), $file . ' is missing Stage 3 contract: ' . $contract);
    }
}

$duplicateController = stage3_source('app/controllers/admin_duplicate_photos.php');
stage3_expect(str_contains($duplicateController, 'admin_wants_json()'), 'Duplicate detector must identify enhanced requests before classic fallbacks.');
stage3_expect(preg_match("/admin_mutation_panel_metadata\('duplicate-photo-detector'.*?\),\s*\[\],\s*\['redirect_url'/s", $duplicateController) === 1, 'Ledger-only mutations must not claim public presentation contexts.');

$workflowModules = [
    'public/assets/gallery-modules/admin-media-renamer.js' => ['replaceRenamerWorkspace', 'php-gallery:auxiliary-mutation-success'],
    'public/assets/gallery-modules/admin-metadata-organizer.js' => ['php-gallery:metadata-organizer-applied', 'mutation_envelope'],
    'public/assets/gallery-modules/admin-duplicate-photo-detector.js' => ['php-gallery:auxiliary-mutation-success'],
    'public/assets/gallery-modules/admin-image-reordering.js' => ['php-gallery:admin-image-order-saved'],
    'public/assets/gallery-modules/admin-gallery-migration.js' => ['php-gallery:auxiliary-mutation-success', 'refreshPanel'],
    'public/assets/gallery-modules/admin-thumbnail-progress.js' => ['php-gallery:auxiliary-mutation-success', "closest('[data-admin-side-panel]')"],
    'public/assets/gallery-modules/admin-gallery-date-suggestion.js' => ['php-gallery:auxiliary-mutation-success'],
];

foreach ($workflowModules as $file => $contracts) {
    $source = stage3_source($file);
    foreach ($contracts as $contract) {
        stage3_expect(str_contains($source, $contract), $file . ' is missing coordinator bridge or tool-owned fragment contract: ' . $contract);
    }
    stage3_expect(!str_contains($source, 'window.location.reload('), $file . ' must not use hard reload as enhanced recovery.');
}

$pictureManager = stage3_source('public/assets/gallery-modules/picture-manager.js');
stage3_expect(str_contains($pictureManager, 'window.location.href = refreshUrl'), 'Picture Manager standalone navigation is an intentional public-toolbar exception.');

$sidePanel = stage3_source('public/assets/gallery-modules/admin-side-panel.js');
stage3_expect(str_contains($sidePanel, "document.addEventListener('submit', async (event) => {"), 'Dynamic panel forms must remain intercepted by delegation.');
stage3_expect(str_contains($sidePanel, "document.addEventListener('php-gallery:auxiliary-mutation-success'"), 'Auxiliary durable mutations must converge on one side-panel completion bridge.');
stage3_expect(str_contains($sidePanel, 'completeAdminMutation(result,'), 'Side-panel completion bridge must call the canonical coordinator.');
stage3_expect(!str_contains($sidePanel, 'history.replaceState('), 'Side-panel synchronization must not rewrite history.');

echo "Stage 3 auxiliary mutation contracts passed.\n";
