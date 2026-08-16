<?php

/** Regression tests for Smart Gallery validation and safe SQL compilation. */

declare(strict_types=1);

namespace Gallery\Services {
    require_once dirname(__DIR__) . '/app/services/smart_galleries.php';

    /** Fail the standalone test with a useful message. */
    function smart_gallery_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /** Assert that invalid input is rejected. */
    function smart_gallery_test_rejected(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException) {
            return;
        }
        smart_gallery_test_assert(false, $message);
    }

    $simple = ['version' => 1, 'root' => ['type' => 'group', 'operator' => 'AND', 'children' => [
        ['type' => 'condition', 'field' => 'tag', 'operator' => 'has_tag', 'value' => 7],
        ['type' => 'condition', 'field' => 'rating', 'operator' => 'gte', 'value' => 4],
    ]]];
    $compiled = smart_gallery_compile_rules($simple);
    smart_gallery_test_assert(str_contains($compiled['sql'], 'EXISTS') && str_contains($compiled['sql'], 'editorial_rating >= ?'), 'AND rules compile to both predicates.');
    smart_gallery_test_assert($compiled['params'] === [7, 7, 4.0], 'Tag IDs and comparison values remain bound parameters.');
    smart_gallery_test_assert(str_contains($compiled['sql'], 'gallery_tags'), 'Tag predicates include tags attached to the physical source gallery.');
    smart_gallery_test_assert(str_contains($compiled['sql'], 'sg_tag_gallery.folder_path'), 'Gallery tags apply to images in the tagged gallery branch.');

    $allTags = smart_gallery_compile_rules(['version' => 1, 'root' => ['type' => 'group', 'operator' => 'AND', 'children' => [
        ['type' => 'condition', 'field' => 'tag', 'operator' => 'has_all_tags', 'value' => [7, 9]],
    ]]]);
    smart_gallery_test_assert($allTags['params'] === [7, 7, 9, 9], 'All-tags matching binds each ID for image and gallery tag relations.');
    smart_gallery_test_assert(substr_count($allTags['sql'], 'gallery_tags') === 2, 'All-tags matching requires every selected tag across both supported relations.');

    $nested = ['version' => 1, 'root' => ['type' => 'group', 'operator' => 'AND', 'children' => [
        ['type' => 'group', 'operator' => 'OR', 'children' => [
            ['type' => 'condition', 'field' => 'camera_model', 'operator' => 'contains', 'value' => 'iPhone'],
            ['type' => 'condition', 'field' => 'camera_model', 'operator' => 'contains', 'value' => 'Sony'],
        ]],
        ['type' => 'group', 'operator' => 'NOT', 'children' => [
            ['type' => 'condition', 'field' => 'description', 'operator' => 'contains', 'value' => 'screenshot'],
        ]],
    ]]];
    $nestedCompiled = smart_gallery_compile_rules($nested);
    smart_gallery_test_assert(str_contains($nestedCompiled['sql'], ' OR ') && str_contains($nestedCompiled['sql'], 'NOT'), 'Nested OR and NOT logic is preserved.');

    $injection = "x%' OR 1=1 --";
    $injectionRules = ['version' => 1, 'root' => ['type' => 'group', 'operator' => 'AND', 'children' => [
        ['type' => 'condition', 'field' => 'filename', 'operator' => 'contains', 'value' => $injection],
    ]]];
    $injectionCompiled = smart_gallery_compile_rules($injectionRules);
    smart_gallery_test_assert(!str_contains($injectionCompiled['sql'], 'OR 1=1'), 'Injection text never enters SQL syntax.');
    smart_gallery_test_assert(str_contains($injectionCompiled['params'][0], 'OR 1=1'), 'Injection text remains a bound value.');

    smart_gallery_test_rejected(static fn () => smart_gallery_validate_rules(['version' => 1, 'root' => ['type' => 'condition', 'field' => 'i.id; DROP TABLE images', 'operator' => 'equals', 'value' => 1]]), 'Arbitrary fields must be rejected.');
    smart_gallery_test_rejected(static fn () => smart_gallery_validate_rules(['version' => 1, 'root' => ['type' => 'condition', 'field' => 'rating', 'operator' => 'LIKE', 'value' => 4]]), 'Arbitrary operators must be rejected.');
    smart_gallery_test_rejected(static fn () => smart_gallery_validate_rules(['version' => 1, 'root' => ['type' => 'condition', 'field' => 'capture_date', 'operator' => 'month', 'value' => 13]]), 'Invalid capture months must be rejected.');
    smart_gallery_test_rejected(static fn () => smart_gallery_rules_from_json('{bad'), 'Malformed JSON must be rejected.');
    smart_gallery_test_rejected(static fn () => smart_gallery_validate_rules(['version' => 99, 'root' => []]), 'Unknown versions must fail safely.');
    smart_gallery_test_rejected(static fn () => smart_gallery_validate_rules(['version' => 1, 'root' => ['type' => 'group', 'operator' => 'NOT', 'children' => []]]), 'NOT must contain exactly one child.');

    $deep = ['type' => 'condition', 'field' => 'gps', 'operator' => 'exists'];
    for ($index = 0; $index < 7; $index++) $deep = ['type' => 'group', 'operator' => 'AND', 'children' => [$deep]];
    smart_gallery_test_rejected(static fn () => smart_gallery_validate_rules(['version' => 1, 'root' => $deep]), 'Excessive nesting must be rejected.');

    $ruleBuilderSource = file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/admin-smart-galleries.js');
    smart_gallery_test_assert(is_string($ruleBuilderSource) && str_contains($ruleBuilderSource, 'parentRule.children.indexOf(node)'), 'Saved rule removal targets the owning rule group.');
    smart_gallery_test_assert(!str_contains((string) $ruleBuilderSource, 'parent.children.indexOf(node)'), 'Rule removal must not treat DOM children as the persisted rule array.');

    $placementMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/202608140002_smart_gallery_placement.php');
    smart_gallery_test_assert(is_string($placementMigration) && str_contains($placementMigration, "ENUM('unlisted', 'root', 'gallery')"), 'Placement migration defines the three supported listing modes.');
    $homeSource = file_get_contents(dirname(__DIR__) . '/app/controllers/public_gallery_home.php');
    $gallerySource = file_get_contents(dirname(__DIR__) . '/app/controllers/public_gallery_page.php');
    $serviceSource = file_get_contents(dirname(__DIR__) . '/app/services/smart_galleries.php');
    $adminControllerSource = file_get_contents(dirname(__DIR__) . '/app/controllers/smart_galleries.php');
    smart_gallery_test_assert(str_contains((string) $homeSource, 'smart_galleries_for_placement(null, true)'), 'Root Smart Galleries join homepage pagination input.');
    smart_gallery_test_assert(str_contains((string) $gallerySource, "smart_galleries_for_placement((int) \$gallery['id'], \$publicOnly)"), 'Placed Smart Galleries join physical child pagination input.');
    smart_gallery_test_assert(str_contains((string) $serviceSource, '$submittedSlug !== \'\' ? $submittedSlug : $title'), 'A blank submitted slug is generated from the Smart Gallery title.');
    smart_gallery_test_assert(str_contains((string) $adminControllerSource, "'smart_gallery.saved'") && str_contains((string) $adminControllerSource, "'smart_gallery.validation_failed'") && str_contains((string) $adminControllerSource, "'smart_gallery.action_failed'"), 'Smart Gallery Admin actions emit success, validation, and unexpected-failure diagnostics.');
    smart_gallery_test_assert(str_contains((string) $adminControllerSource, 'function smart_gallery_admin_log_context') && str_contains((string) $adminControllerSource, "'group_counts'") && str_contains((string) $adminControllerSource, "'condition_count'"), 'Smart Gallery logs include a bounded rule-structure and creation-intent summary.');
    smart_gallery_test_assert(!str_contains((string) $ruleBuilderSource, 'parentSelect.required = requiresParent'), 'Smart Gallery definition no longer enforces one physical parent in the browser.');
    $multiplePlacementMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/202608140003_smart_gallery_multiple_placements.php');
    smart_gallery_test_assert(is_string($multiplePlacementMigration) && str_contains($multiplePlacementMigration, 'CREATE TABLE smart_gallery_placements') && str_contains($multiplePlacementMigration, 'PRIMARY KEY (smart_gallery_id, gallery_id)'), 'Multiple-placement migration stores a many-to-many Smart Gallery relationship.');
    smart_gallery_test_assert(str_contains((string) $serviceSource, 'DELETE FROM smart_gallery_placements WHERE gallery_id = ?') && str_contains((string) $serviceSource, 'INSERT INTO smart_gallery_placements'), 'Physical gallery assignment replaces only that gallery placements without moving other parents.');
    smart_gallery_test_assert(str_contains((string) $serviceSource, 'function smart_gallery_remove_from_gallery') && str_contains((string) $adminControllerSource, 'value="remove_placement"'), 'Smart Gallery editor exposes a per-location removal action backed by the canonical placement service.');

    fwrite(STDOUT, "Smart Gallery rule tests passed.\n");
}
