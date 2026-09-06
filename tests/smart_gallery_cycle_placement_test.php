<?php

/** Regression tests for Smart Gallery relationship-cycle safety and per-parent placement ordering. */

declare(strict_types=1);

namespace Gallery\Services {
    require_once __DIR__ . '/support/module_source.php';
    require_once dirname(__DIR__) . '/app/services/smart_galleries.php';

    /** Fail the standalone cycle/placement test with one precise contract message. */
    function smart_gallery_cycle_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /** Assert that a pure validation helper rejects unsafe input. */
    function smart_gallery_cycle_test_rejected(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException) {
            return;
        }
        smart_gallery_cycle_test_assert(false, $message);
    }

    $edgeCount = 0;
    $selfAdjacency = [];
    smart_gallery_graph_add_edge($selfAdjacency, 's:7', 'g:5', $edgeCount);
    $selfDiagnostic = smart_gallery_relationship_diagnostic(7, 5, [
        'adjacency' => $selfAdjacency,
        'placements' => [],
        'smart_errors' => [],
        'edge_count' => $edgeCount,
    ]);
    smart_gallery_cycle_test_assert(!$selfDiagnostic['valid'] && $selfDiagnostic['code'] === 'cycle', 'A Smart Gallery that resolves back to its proposed physical parent is rejected as a direct attachment cycle.');

    $twoNode = ['s:1' => ['s:2' => true], 's:2' => ['s:1' => true]];
    $twoNodePath = smart_gallery_graph_find_path($twoNode, 's:1', 's:2');
    smart_gallery_cycle_test_assert($twoNodePath['found'] && $twoNodePath['path'] === ['s:1', 's:2'], 'The bounded graph walker detects a direct two-node Smart Gallery cycle shape.');

    $multiNode = ['s:1' => ['g:10' => true], 'g:10' => ['s:2' => true], 's:2' => ['g:20' => true], 'g:20' => ['s:1' => true]];
    $multiNodePath = smart_gallery_graph_find_path($multiNode, 's:1', 'g:20');
    smart_gallery_cycle_test_assert($multiNodePath['found'] && $multiNodePath['path'] === ['s:1', 'g:10', 's:2', 'g:20'], 'Multi-node mixed physical/Smart Gallery cycles are detected through stable IDs.');

    $acyclic = ['s:1' => ['g:10' => true], 'g:10' => ['s:2' => true], 's:2' => ['g:20' => true]];
    $acyclicPath = smart_gallery_graph_find_path($acyclic, 's:2', 'g:10');
    smart_gallery_cycle_test_assert(!$acyclicPath['found'] && !$acyclicPath['truncated'], 'A valid acyclic mixed relationship remains accepted.');

    $legacyCycle = ['s:1' => ['g:10' => true], 'g:10' => ['s:1' => true, 'g:11' => true], 'g:11' => ['g:12' => true]];
    $legacyTermination = smart_gallery_graph_find_path($legacyCycle, 's:1', 'g:99');
    smart_gallery_cycle_test_assert(!$legacyTermination['found'] && !$legacyTermination['truncated'] && $legacyTermination['visited'] === 4, 'A legacy cycle terminates through visited-node deduplication instead of recurring indefinitely.');

    $depthGraph = ['g:1' => ['g:2' => true], 'g:2' => ['g:3' => true], 'g:3' => ['g:4' => true]];
    $depthResult = smart_gallery_graph_find_path($depthGraph, 'g:1', 'g:99', 1, 50, 50);
    smart_gallery_cycle_test_assert($depthResult['truncated'], 'Maximum graph recursion depth is enforced deterministically.');

    $wideGraph = ['g:1' => ['g:2' => true, 'g:3' => true, 'g:4' => true]];
    $wideResult = smart_gallery_graph_find_path($wideGraph, 'g:1', 'g:99', 10, 2, 50);
    smart_gallery_cycle_test_assert($wideResult['truncated'], 'Maximum expanded relationship-node count is enforced.');

    $smartGraph = ['s:1' => ['s:2' => true], 's:2' => ['s:3' => true]];
    $smartLimitResult = smart_gallery_graph_find_path($smartGraph, 's:1', 'g:99', 10, 50, 1);
    smart_gallery_cycle_test_assert($smartLimitResult['truncated'], 'Maximum expanded Smart Gallery node count is enforced independently.');

    $diamond = ['s:1' => ['g:1' => true, 'g:2' => true], 'g:1' => ['s:2' => true], 'g:2' => ['s:2' => true], 's:2' => []];
    $diamondResult = smart_gallery_graph_find_path($diamond, 's:1', 'g:99');
    smart_gallery_cycle_test_assert(!$diamondResult['found'] && $diamondResult['visited'] === 4, 'Repeated traversal discoveries are deduplicated before expansion.');

    smart_gallery_cycle_test_assert(!smart_gallery_relationship_change_is_newly_invalid(true, ['valid' => false], ['valid' => false]), 'An existing legacy-invalid relationship may remain while an unrelated attachment field is edited.');
    smart_gallery_cycle_test_assert(smart_gallery_relationship_change_is_newly_invalid(true, ['valid' => true], ['valid' => false]), 'An edit that turns an existing valid relationship invalid is rejected.');
    smart_gallery_cycle_test_assert(smart_gallery_relationship_change_is_newly_invalid(false, ['valid' => true], ['valid' => false]), 'A newly attached invalid relationship is rejected even when the path existed before attachment.');

    $malformed = smart_gallery_relationship_diagnostic(0, 5, ['adjacency' => [], 'placements' => [], 'smart_errors' => [], 'edge_count' => 0]);
    smart_gallery_cycle_test_assert(!$malformed['valid'] && $malformed['code'] === 'malformed_relationship', 'Malformed relationship IDs fail closed with a stable diagnostic code.');
    $boundedFailure = smart_gallery_relationship_diagnostic(7, 5, ['adjacency' => [], 'placements' => [], 'smart_errors' => [], 'edge_count' => 0, 'graph_error' => 'graph_limit']);
    smart_gallery_cycle_test_assert(!$boundedFailure['valid'] && $boundedFailure['code'] === 'graph_limit', 'A relationship graph that exceeds runtime safety ceilings fails closed without recursive public expansion.');

    $catalog = smart_gallery_rule_catalog();
    smart_gallery_cycle_test_assert(!isset($catalog['smart_gallery']), 'The current rule schema has no Smart-Gallery-reference field, so no unsupported Smart-Gallery-to-Smart-Gallery rule edge is invented.');

    $galleryRules = ['version' => 1, 'root' => ['type' => 'group', 'operator' => 'AND', 'children' => [
        ['type' => 'condition', 'field' => 'gallery', 'operator' => 'under', 'value' => 12],
        ['type' => 'condition', 'field' => 'gallery', 'operator' => 'not_equals', 'value' => 13],
        ['type' => 'group', 'operator' => 'NOT', 'children' => [
            ['type' => 'condition', 'field' => 'gallery', 'operator' => 'not_under', 'value' => 14],
        ]],
    ]]];
    smart_gallery_cycle_test_assert(smart_gallery_rule_gallery_references($galleryRules) === [12, 14], 'Only gallery-rule branches that can positively include a physical gallery become relationship-graph edges.');
    $referenceSpecs = smart_gallery_rule_gallery_reference_specs($galleryRules);
    smart_gallery_cycle_test_assert($referenceSpecs === [['gallery_id' => 12, 'mode' => 'under'], ['gallery_id' => 14, 'mode' => 'under']], 'Gallery relationship references preserve descendant inclusion semantics after NOT normalization.');
    smart_gallery_cycle_test_assert(smart_gallery_graph_gallery_branch_ids(12, [12 => [13], 13 => [14]], false) === [12], 'An exact gallery rule does not incorrectly imply physical descendants in the cycle graph.');
    smart_gallery_cycle_test_assert(smart_gallery_graph_gallery_branch_ids(12, [12 => [13], 13 => [14]], true) === [12, 13, 14], 'An under-gallery rule expands the bounded physical descendant branch for cycle validation.');

    $legacyAttachment = smart_gallery_normalize_attachment_inputs([9]);
    smart_gallery_cycle_test_assert($legacyAttachment === [['smart_gallery_id' => 9, 'placement' => 'bottom', 'placement_order' => 0]], 'Omitted legacy placement defaults to bottom with deterministic order zero.');

    $structured = smart_gallery_normalize_attachment_inputs([
        9 => ['enabled' => '1', 'placement' => 'top', 'placement_order' => '20'],
        10 => ['enabled' => '1', 'placement' => 'invalid', 'placement_order' => '10'],
    ]);
    smart_gallery_cycle_test_assert($structured[0]['placement'] === 'top' && $structured[0]['placement_order'] === 20, 'Top placement and order are preserved after normalization.');
    smart_gallery_cycle_test_assert($structured[1]['placement'] === 'bottom' && $structured[1]['placement_order'] === 10, 'Invalid placement values fail safely to the bottom default.');

    $duplicates = smart_gallery_normalize_attachment_inputs([
        ['smart_gallery_id' => 22, 'placement' => 'top', 'placement_order' => 10],
        ['smart_gallery_id' => 22, 'placement' => 'bottom', 'placement_order' => 30],
    ]);
    smart_gallery_cycle_test_assert(count($duplicates) === 1 && $duplicates[0]['placement'] === 'bottom' && $duplicates[0]['placement_order'] === 30, 'Duplicate attachment input for one parent is safely normalized to one stable relationship row.');

    $tooMany = [];
    for ($id = 1; $id <= SMART_GALLERY_ATTACHMENT_MAX_PER_PARENT + 1; $id++) $tooMany[] = $id;
    smart_gallery_cycle_test_rejected(static fn () => smart_gallery_normalize_attachment_inputs($tooMany), 'Per-parent Smart Gallery attachment expansion is bounded.');
    smart_gallery_cycle_test_assert(SMART_GALLERY_QUERY_MAX_PAGE_SIZE === 200 && SMART_GALLERY_LIGHTBOX_MAX_WINDOW === 80, 'Smart Gallery result collection remains bounded for page and lazy-lightbox queries.');

    $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/202608170002_smart_gallery_attachment_ordering.php');
    smart_gallery_cycle_test_assert(is_string($migration) && str_contains($migration, "placement ENUM('top', 'bottom') NOT NULL DEFAULT 'bottom'"), 'The attachment migration preserves existing behavior with a bottom default.');
    smart_gallery_cycle_test_assert(str_contains((string) $migration, 'placement_order INT NOT NULL DEFAULT 0'), 'The attachment migration adds a per-parent deterministic order value.');

    $serviceSource = file_get_contents(dirname(__DIR__) . '/app/services/smart_galleries.php');
    smart_gallery_cycle_test_assert(str_contains((string) $serviceSource, "CASE sgp.placement WHEN 'top' THEN 0 ELSE 1 END, sgp.placement_order, sg.id"), 'Public attachment loading uses placement, configured order, then Smart Gallery ID as the stable tie-breaker.');
    smart_gallery_cycle_test_assert(str_contains((string) $serviceSource, 'WHERE smart_gallery_id = ? AND gallery_id = ?'), 'Per-parent placement updates address the composite relationship rather than globally changing a Smart Gallery.');
    $updatePlacementStart = strpos((string) $serviceSource, 'function smart_gallery_update_placement');
    $updatePlacementEnd = strpos((string) $serviceSource, '/** Remove one physical placement', $updatePlacementStart === false ? 0 : $updatePlacementStart);
    $updatePlacementSource = $updatePlacementStart !== false && $updatePlacementEnd !== false ? substr((string) $serviceSource, $updatePlacementStart, $updatePlacementEnd - $updatePlacementStart) : '';
    smart_gallery_cycle_test_assert($updatePlacementSource !== '' && !str_contains($updatePlacementSource, 'smart_gallery_validate_children_assignment'), 'Placement/order-only edits do not revalidate unrelated legacy graph edges because they cannot change graph connectivity.');
    smart_gallery_cycle_test_assert(str_contains((string) $serviceSource, 'function smart_gallery_graph_read_snapshot(): array') && str_contains((string) $serviceSource, "'graph_error' => 'graph_limit'"), 'Read-only Admin/public relationship rendering converts oversized legacy graphs into a bounded diagnostic instead of a public exception.');

    $multiplePlacementMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/202608140003_smart_gallery_multiple_placements.php');
    smart_gallery_cycle_test_assert(is_string($multiplePlacementMigration) && str_contains($multiplePlacementMigration, 'PRIMARY KEY (smart_gallery_id, gallery_id)'), 'The existing composite key prevents duplicate instances under the same physical parent while allowing multiple parents.');

    $publicSource = file_get_contents(dirname(__DIR__) . '/app/controllers/public_gallery_page.php');
    $topPosition = strpos((string) $publicSource, "render_public_smart_gallery_attachment_group(\$topSmartChildren, 'top'");
    $normalPosition = strpos((string) $publicSource, 'if ($children) {', $topPosition === false ? 0 : $topPosition);
    $bottomPosition = strpos((string) $publicSource, "render_public_smart_gallery_attachment_group(\$bottomSmartChildren, 'bottom'");
    smart_gallery_cycle_test_assert($topPosition !== false && $normalPosition !== false && $bottomPosition !== false && $topPosition < $normalPosition && $normalPosition < $bottomPosition, 'Public rendering places ordered top Smart Galleries before normal content and bottom Smart Galleries after it.');
    smart_gallery_cycle_test_assert(str_contains((string) $publicSource, 'if ($smartGalleries === []) return;'), 'Filtered or unavailable attachment groups leave no empty public layout block.');

    $galleryMutationSource = file_get_contents(dirname(__DIR__) . '/app/services/gallery_mutations.php');
    $reorderSource = file_get_contents(dirname(__DIR__) . '/app/controllers/admin_galleries_reorder.php');
    smart_gallery_cycle_test_assert(str_contains((string) $galleryMutationSource, 'smart_gallery_validate_gallery_parent_change'), 'Single physical gallery moves preflight Smart Gallery relationship cycles.');
    smart_gallery_cycle_test_assert(str_contains((string) $reorderSource, 'smart_gallery_validate_gallery_parent_map($submittedParentById);'), 'Admin drag-and-drop hierarchy moves validate the complete resulting relationship graph before the first filesystem move.');

    $adminGallerySource = module_source(dirname(__DIR__) . '/app/controllers/admin_galleries_edit_page.php');
    $adminSmartSource = file_get_contents(dirname(__DIR__) . '/app/controllers/smart_galleries.php');
    $sidePanelSource = file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/admin-side-panel.js');
    smart_gallery_cycle_test_assert(str_contains((string) $adminGallerySource, 'smart_gallery_children_present') && str_contains((string) $adminGallerySource, '[placement]') && str_contains((string) $adminGallerySource, '[placement_order]'), 'Physical gallery Admin editing exposes no-JavaScript attachment, placement, and order form fields.');
    smart_gallery_cycle_test_assert(str_contains((string) $adminSmartSource, 'value="update_placement"') && str_contains((string) $adminSmartSource, 'value="remove_placement"') && str_contains((string) $adminSmartSource, 'data-smart-gallery-panel-form'), 'Smart Gallery Admin editing exposes in-place update and detach forms with normal POST fallback.');
    smart_gallery_cycle_test_assert(str_contains((string) $sidePanelSource, "form.matches('[data-smart-gallery-panel-form]')") && str_contains((string) $sidePanelSource, 'submitAdminSmartGalleryPanelForm'), 'Existing delegated side-panel JavaScript remains the primary pipeline for dynamically injected Smart Gallery attachment actions.');

    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalogPath = dirname(__DIR__) . '/app/lang/' . $language . '.json';
        $catalogData = json_decode((string) file_get_contents($catalogPath), true);
        foreach (['attachment_placement', 'attachment_order', 'placement_top', 'placement_bottom', 'relationship_invalid', 'relationship_cycle_attachment_error', 'relationship_cycle_rule_error', 'relationship_cycle_move_error', 'public_group_top', 'public_group_bottom'] as $key) {
            smart_gallery_cycle_test_assert(is_array($catalogData) && trim((string) ($catalogData['smart_gallery.' . $key] ?? '')) !== '', strtoupper($language) . ' contains translated Smart Gallery cycle/placement accessibility label: ' . $key . '.');
        }
    }

    fwrite(STDOUT, "Smart Gallery cycle and placement tests passed.\n");
}
