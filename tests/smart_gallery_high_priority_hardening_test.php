<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect Smart Gallery hardening regressions.
 * Responsibilities:
 *   - Check asset freshness, bounded graph fetches and batched count/cover lookups.
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

require_once __DIR__ . '/support/php_source.php';
require_once __DIR__ . '/support/gallery_edit_writer_source.php';
require_once dirname(__DIR__) . '/scripts/admin_import_contracts.php';

/**
 * Fail this standalone test with one concise contract message.
 *
 * @param bool $condition Whether the hardening contract holds.
 * @param string $message Failure diagnostic.
 * @return void Writes the diagnostic to stderr and exits on failure.
 */
function smart_gallery_high_priority_assert(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root
    . '/app/services/smart_galleries.php');
$model = (string) file_get_contents($root . '/app/models/smart_galleries.php');
$cards = (string) file_get_contents($root
    . '/app/controllers/public_gallery_cards.php');
$home = (string) file_get_contents($root
    . '/app/controllers/public_gallery_home.php');
$page = (string) file_get_contents($root
    . '/app/controllers/public_gallery_page.php');
$galleryJs = (string) file_get_contents($root . '/public/assets/gallery.js');
$adminOperations = (string) file_get_contents($root
    . '/public/assets/gallery-modules/admin-operations.js');
$sidePanel = (string) file_get_contents($root
    . '/public/assets/gallery-modules/admin-side-panel.js');

smart_gallery_high_priority_assert(
    \Gallery\Tools\AdminImports\admin_import_repository_failures($root) === [],
    'Smart Gallery and Admin import consumers must resolve coherent revisioned targets and invalidate changed module chains.',
);

smart_gallery_high_priority_assert(
    php_source_contains($model, 'function smart_gallery_model_bounded_rows')
        && php_source_contains($model, '$limit = max(1, $maxRows) + 1;')
        && php_source_contains(
            $model,
            "db()->query(\$sql . ' LIMIT ' . \$limit)->fetchAll()",
        ),
    'Relationship graph source queries impose LIMIT ceiling+1 in the model before PDO materializes rows.',
);
smart_gallery_high_priority_assert(
    php_source_contains(
        $service,
        "\\Gallery\\Models\\smart_gallery_model_graph_gallery_rows(SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS)",
    )
        && php_source_contains(
            $service,
            "\\Gallery\\Models\\smart_gallery_model_graph_definition_rows(SMART_GALLERY_GRAPH_MAX_SOURCE_ROWS)",
        )
        && php_source_contains(
            $service,
            '\\Gallery\\Models\\smart_gallery_model_graph_placement_rows(',
        )
        && php_source_contains(
            $model,
            "'SELECT id, parent_id FROM galleries ORDER BY id'",
        )
        && php_source_contains(
            $model,
            "'SELECT id, rules_json FROM smart_galleries ORDER BY id'",
        ),
    'Physical hierarchy, Smart Gallery definitions, and placement rows all use model-owned pre-allocation graph bounds.',
);

smart_gallery_high_priority_assert(
    php_source_contains($service, "'accessible_gallery_ids_public'")
        && php_source_contains($service, "'accessible_gallery_ids_admin'")
        && php_source_contains(
            $service,
            "\$cache['source_gallery_rows'] = \$sourceGalleryRows;",
        ),
    'Viewer-accessible physical galleries are computed once per request and seed the reusable source-gallery row cache.',
);
smart_gallery_high_priority_assert(
    php_source_contains($service, 'const SMART_GALLERY_CARD_SUMMARY_BATCH_SIZE = 20;')
        && php_source_contains($service, 'function smart_gallery_card_summaries')
        && php_source_contains(
            $service,
            'array_chunk($missing, SMART_GALLERY_CARD_SUMMARY_BATCH_SIZE, true)',
        )
        && php_source_contains($model, "implode(' UNION ALL ', \$selects)"),
    'Placed Smart Gallery card counts and covers are fetched in bounded model-owned SQL batches rather than once per card.',
);
smart_gallery_high_priority_assert(
    php_source_contains($home, 'home_smart_gallery_card_context_preload')
        && php_source_contains(
            $home,
            'smart_gallery_card_summaries($smartGalleries, true)',
        )
        && php_source_contains(
            $page,
            'smart_gallery_card_summaries(array_merge($topSmartChildren, $bottomSmartChildren), true)',
        ),
    'Home and physical-gallery pages preload Smart Gallery card summaries before entering card render loops.',
);

$renderFunction = gallery_writer_functions($cards)[
    'render_smart_gallery_card'
] ?? null;
$renderSource = is_array($renderFunction)
    ? $renderFunction['declaration'] . $renderFunction['body']
    : '';
smart_gallery_high_priority_assert(
    $renderSource !== ''
        && !php_source_contains($renderSource, 'smart_gallery_count_images(')
        && !php_source_contains($renderSource, 'smart_gallery_query_images(')
        && php_source_contains($renderSource, 'array $cardContext = []'),
    'Smart Gallery card rendering consumes preloaded context and no longer performs count/cover queries per card.',
);

fwrite(STDOUT, "Smart Gallery high-priority hardening tests passed.\n");
