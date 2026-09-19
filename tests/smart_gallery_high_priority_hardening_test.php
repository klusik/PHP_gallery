<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/smart_gallery_high_priority_hardening_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Regression contracts for Smart Gallery release hardening.
 *
 * These checks protect the three release-readiness items that previously
 * allowed stale Admin modules, unbounded graph source fetches, and per-card
 * Smart Gallery count/cover queries.
 */

declare(strict_types=1);

/** Fail this standalone test with one concise contract message. */
function smart_gallery_high_priority_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/services/smart_galleries.php');
$model = (string) file_get_contents($root . '/app/models/smart_galleries.php');
$cards = (string) file_get_contents($root . '/app/controllers/public_gallery_cards.php');
$home = (string) file_get_contents($root . '/app/controllers/public_gallery_home.php');
$page = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$galleryJs = (string) file_get_contents($root . '/public/assets/gallery.js');
$adminOperations = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-operations.js');
$sidePanel = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-side-panel.js');

smart_gallery_high_priority_assert(
    str_contains($galleryJs, 'admin-smart-galleries.js?v=20260919-smart-gallery-presentation-v1')
    && str_contains($galleryJs, 'admin-operations.js?v=20260919-smart-gallery-presentation-v1')
    && str_contains($adminOperations, 'admin-side-panel.js?v=20260919-smart-gallery-presentation-v1')
    && str_contains($sidePanel, 'admin-smart-galleries.js?v=20260919-smart-gallery-presentation-v1'),
    'Every Admin import edge uses the current cache-busting revision of the module it imports.'
);
smart_gallery_high_priority_assert(
    !str_contains($galleryJs, 'admin-smart-galleries.js?v=20260814-smart-galleries-v6')
    && !str_contains($adminOperations, 'admin-side-panel.js?v=20260815-upload-zip-import-v1'),
    'Known stale Smart Gallery and side-panel import revisions are no longer reachable.'
);

smart_gallery_high_priority_assert(
    str_contains($model, 'function smart_gallery_model_bounded_rows')
    && str_contains($model, '$limit = max(1, $maxRows) + 1;')
    && str_contains($model, "db()->query(\$sql . ' LIMIT ' . \$limit)->fetchAll()"),
    'Relationship graph source queries impose LIMIT ceiling+1 in the model before PDO materializes rows.'
);
smart_gallery_high_priority_assert(
    str_contains($service, 'smart_gallery_model_graph_gallery_rows(SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS)')
    && str_contains($service, 'smart_gallery_model_graph_definition_rows(SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS)')
    && str_contains($service, 'smart_gallery_model_graph_placement_rows(')
    && str_contains($model, "'SELECT id, parent_id FROM galleries ORDER BY id'")
    && str_contains($model, "'SELECT id, rules_json FROM smart_galleries ORDER BY id'"),
    'Physical hierarchy, Smart Gallery definitions, and placement rows all use model-owned pre-allocation graph bounds.'
);

smart_gallery_high_priority_assert(
    str_contains($service, "'accessible_gallery_ids_public'")
    && str_contains($service, "'accessible_gallery_ids_admin'")
    && str_contains($service, "\$cache['source_gallery_rows'] = \$sourceGalleryRows;"),
    'Viewer-accessible physical galleries are computed once per request and seed the reusable source-gallery row cache.'
);
smart_gallery_high_priority_assert(
    str_contains($service, 'const SMART_GALLERY_CARD_SUMMARY_BATCH_SIZE = 20;')
    && str_contains($service, 'function smart_gallery_card_summaries')
    && str_contains($service, 'array_chunk($missing, SMART_GALLERY_CARD_SUMMARY_BATCH_SIZE, true)')
    && str_contains($model, "implode(' UNION ALL ', \$selects)"),
    'Placed Smart Gallery card counts and covers are fetched in bounded model-owned SQL batches rather than once per card.'
);
smart_gallery_high_priority_assert(
    str_contains($home, 'home_smart_gallery_card_context_preload')
    && str_contains($home, 'smart_gallery_card_summaries($smartGalleries, true)')
    && str_contains($page, 'smart_gallery_card_summaries(array_merge($topSmartChildren, $bottomSmartChildren), true)'),
    'Home and physical-gallery pages preload Smart Gallery card summaries before entering card render loops.'
);

$renderStart = strpos($cards, 'function render_smart_gallery_card');
$renderEnd = strpos($cards, '/**', $renderStart === false ? 0 : $renderStart + 1);
$renderSource = $renderStart !== false
    ? substr($cards, $renderStart, $renderEnd !== false ? $renderEnd - $renderStart : null)
    : '';
smart_gallery_high_priority_assert(
    $renderSource !== ''
    && !str_contains($renderSource, 'smart_gallery_count_images(')
    && !str_contains($renderSource, 'smart_gallery_query_images(')
    && str_contains($renderSource, 'array $cardContext = []'),
    'Smart Gallery card rendering consumes preloaded context and no longer performs count/cover queries per card.'
);

fwrite(STDOUT, "Smart Gallery high-priority hardening tests passed.\n");
