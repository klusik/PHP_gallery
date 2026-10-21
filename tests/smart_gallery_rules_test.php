<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Verify Smart Gallery rule validation and compilation.
 * Responsibilities:
 *   - Check allowlisted predicates and safe parameterized query construction.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/smart_gallery_rules_test.php
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
/** Regression tests for Smart Gallery validation and safe SQL compilation. */

declare(strict_types=1);

namespace Gallery\Services {
    require_once __DIR__ . '/support/php_source.php';

    require_once dirname(__DIR__) . '/app/models/smart_galleries.php';
    require_once dirname(__DIR__) . '/app/services/smart_galleries.php';

    /** Fail the standalone test with a useful message. */
    function smart_gallery_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * Assert that invalid input is rejected.
     *
     * @param callable $callback Validation operation expected to throw.
     * @param string $message Failure diagnostic.
     * @return void Throws a test failure when the callback accepts the input.
     */
    function smart_gallery_test_rejected(
        callable $callback,
        string $message,
    ): void {
        try {
            $callback();
        } catch (\InvalidArgumentException) {
            return;
        }
        smart_gallery_test_assert(false, $message);
    }

    $simple = [
        'version' => 1,
        'root' => [
            'type' => 'group',
            'operator' => 'AND',
            'children' => [
                [
                    'type' => 'condition',
                    'field' => 'tag',
                    'operator' => 'has_tag',
                    'value' => 7,
                ],
                [
                    'type' => 'condition',
                    'field' => 'rating',
                    'operator' => 'gte',
                    'value' => 4,
                ],
            ],
        ],
    ];
    $compiled = \Gallery\Models\smart_gallery_model_compile_rules(smart_gallery_validate_rules(
        $simple,
    ));
    smart_gallery_test_assert(
        str_contains($compiled['sql'], 'EXISTS')
            && str_contains($compiled['sql'], 'editorial_rating >= ?'),
        'AND rules compile to both predicates.',
    );
    smart_gallery_test_assert(
        $compiled['params'] === [7, 7, 4.0],
        'Tag IDs and comparison values remain bound parameters.',
    );
    smart_gallery_test_assert(
        str_contains($compiled['sql'], 'gallery_tags'),
        'Tag predicates include tags attached to the physical source gallery.',
    );
    smart_gallery_test_assert(
        str_contains($compiled['sql'], 'sg_tag_gallery.folder_path'),
        'Gallery tags apply to images in the tagged gallery branch.',
    );

    $allTags = \Gallery\Models\smart_gallery_model_compile_rules(smart_gallery_validate_rules([
        'version' => 1,
        'root' => [
            'type' => 'group',
            'operator' => 'AND',
            'children' => [
                [
                    'type' => 'condition',
                    'field' => 'tag',
                    'operator' => 'has_all_tags',
                    'value' => [7, 9],
                ],
            ],
        ],
    ]));
    smart_gallery_test_assert(
        $allTags['params'] === [7, 7, 9, 9],
        'All-tags matching binds each ID for image and gallery tag relations.',
    );
    smart_gallery_test_assert(
        substr_count($allTags['sql'], 'gallery_tags') === 2,
        'All-tags matching requires every selected tag across both supported relations.',
    );

    $photoRuleCatalog = smart_gallery_rule_catalog();
    smart_gallery_test_assert(
        ($photoRuleCatalog['photo_tag']['operators'] ?? []) === [
            'has_tag',
            'not_has_tag',
            'has_any_tags',
            'has_all_tags',
            'untagged',
        ],
        'Photo tag scope exposes the complete legacy operator set separately.',
    );
    $photoTagRule = static fn(string $operator, int|array|null $value = null): array => [
        'version' => 1,
        'root' => [
            'type' => 'condition',
            'field' => 'photo_tag',
            'operator' => $operator,
            ...($operator === 'untagged' ? [] : ['value' => $value]),
        ],
    ];
    $photoHasTag = \Gallery\Models\smart_gallery_model_compile_rules(
        $photoTagRule('has_tag', 7),
    );
    smart_gallery_test_assert(
        str_contains($photoHasTag['sql'], 'image_tags')
            && str_contains($photoHasTag['sql'], "sg_pt.media_type = 'image'")
            && !str_contains($photoHasTag['sql'], 'gallery_tags')
            && !str_contains($photoHasTag['sql'], 'music_song_tags')
            && $photoHasTag['params'] === [7],
        'Photo-tag has uses only the candidate image assignment and image category.',
    );
    foreach (['not_has_tag', 'untagged'] as $negativeOperator) {
        $photoNegative = \Gallery\Models\smart_gallery_model_compile_rules(
            $photoTagRule(
                $negativeOperator,
                $negativeOperator === 'untagged' ? null : 7,
            ),
        );
        smart_gallery_test_assert(
            str_contains($photoNegative['sql'], 'image_tags')
                && !str_contains($photoNegative['sql'], 'gallery_tags')
                && !str_contains($photoNegative['sql'], 'music_song_tags'),
            'Photo-tag negative and untagged operators ignore non-photo relations.',
        );
    }
    $photoAny = \Gallery\Models\smart_gallery_model_compile_rules(
        $photoTagRule('has_any_tags', [7, 9]),
    );
    smart_gallery_test_assert(
        $photoAny['params'] === [7, 9]
            && str_contains($photoAny['sql'], 'sg_pt.media_type = \'image\''),
        'Photo-tag has-any uses one parameterized direct-assignment set.',
    );
    $photoAll = \Gallery\Models\smart_gallery_model_compile_rules(
        $photoTagRule('has_all_tags', [7, 9]),
    );
    smart_gallery_test_assert(
        $photoAll['params'] === [7, 9]
            && substr_count($photoAll['sql'], 'sg_it.image_id = i.id') === 2
            && !str_contains($photoAll['sql'], 'gallery_tags'),
        'Photo-tag has-all requires each tag on the same candidate photo.',
    );
    $legacyPhotoRule = $photoTagRule('has_tag', 7);
    $legacyPhotoRule['_photo_tag_media_type_ready'] = false;
    $legacyPhoto = \Gallery\Models\smart_gallery_model_compile_rules(
        $legacyPhotoRule,
    );
    smart_gallery_test_assert(
        str_contains($legacyPhoto['sql'], 'image_tags')
            && !str_contains($legacyPhoto['sql'], 'JOIN tags')
            && !str_contains($legacyPhoto['sql'], 'gallery_tags'),
        'Confirmed legacy tag schemas retain image_tags-only photo scope.',
    );
    smart_gallery_test_assert(
        smart_gallery_photo_tag_ids([
            'type' => 'condition',
            'field' => 'photo_tag',
            'operator' => 'untagged',
        ]) === [],
        'Direct-photo untagged does not require a selected tag ID.',
    );

    $nestedPhotoNegation = [
        'type' => 'group',
        'operator' => 'NOT',
        'children' => [[
            'type' => 'group',
            'operator' => 'AND',
            'children' => [
                [
                    'type' => 'condition',
                    'field' => 'photo_tag',
                    'operator' => 'not_has_tag',
                    'value' => 7,
                ],
                [
                    'type' => 'condition',
                    'field' => 'photo_tag',
                    'operator' => 'untagged',
                ],
            ],
        ]],
    ];
    smart_gallery_test_assert(
        smart_gallery_rule_tree_uses_field(
            $nestedPhotoNegation,
            'photo_tag',
        ),
        'Nested NOT trees must retain photo-tag schema requirements.',
    );
    smart_gallery_test_rejected(
        static fn(): ?bool => smart_gallery_photo_tag_media_type_ready_for_rules(
            $nestedPhotoNegation,
            'unknown',
        ),
        'Unknown tag-category state must reject the entire nested photo-tag query before negative predicates compile.',
    );
    smart_gallery_test_assert(
        smart_gallery_photo_tag_media_type_ready_for_rules(
            $nestedPhotoNegation,
            'missing',
        ) === false
            && smart_gallery_photo_tag_media_type_ready_for_rules(
                $nestedPhotoNegation,
                'available',
            ) === true,
        'Only confirmed legacy absence may compile without typed image-category checks.',
    );

    $nested = [
        'version' => 1,
        'root' => [
            'type' => 'group',
            'operator' => 'AND',
            'children' => [
                [
                    'type' => 'group',
                    'operator' => 'OR',
                    'children' => [
                        [
                            'type' => 'condition',
                            'field' => 'camera_model',
                            'operator' => 'contains',
                            'value' => 'iPhone',
                        ],
                        [
                            'type' => 'condition',
                            'field' => 'camera_model',
                            'operator' => 'contains',
                            'value' => 'Sony',
                        ],
                    ],
                ],
                [
                    'type' => 'group',
                    'operator' => 'NOT',
                    'children' => [
                        [
                            'type' => 'condition',
                            'field' => 'description',
                            'operator' => 'contains',
                            'value' => 'screenshot',
                        ],
                    ],
                ],
            ],
        ],
    ];
    $nestedCompiled = \Gallery\Models\smart_gallery_model_compile_rules(smart_gallery_validate_rules(
        $nested,
    ));
    smart_gallery_test_assert(
        str_contains($nestedCompiled['sql'], ' OR ')
            && str_contains($nestedCompiled['sql'], 'NOT'),
        'Nested OR and NOT logic is preserved.',
    );

    $injection = "x%' OR 1=1 --";
    $injectionRules = [
        'version' => 1,
        'root' => [
            'type' => 'group',
            'operator' => 'AND',
            'children' => [
                [
                    'type' => 'condition',
                    'field' => 'filename',
                    'operator' => 'contains',
                    'value' => $injection,
                ],
            ],
        ],
    ];
    $injectionCompiled = \Gallery\Models\smart_gallery_model_compile_rules(smart_gallery_validate_rules(
        $injectionRules,
    ));
    smart_gallery_test_assert(
        !str_contains($injectionCompiled['sql'], 'OR 1=1'),
        'Injection text never enters SQL syntax.',
    );
    smart_gallery_test_assert(
        str_contains($injectionCompiled['params'][0], 'OR 1=1'),
        'Injection text remains a bound value.',
    );

    smart_gallery_test_rejected(static fn(): array => smart_gallery_validate_rules([
        'version' => 1,
        'root' => [
            'type' => 'condition',
            'field' => 'i.id; DROP TABLE images',
            'operator' => 'equals',
            'value' => 1,
        ],
    ]), 'Arbitrary fields must be rejected.');
    smart_gallery_test_rejected(static fn(): array => smart_gallery_validate_rules([
        'version' => 1,
        'root' => [
            'type' => 'condition',
            'field' => 'rating',
            'operator' => 'LIKE',
            'value' => 4,
        ],
    ]), 'Arbitrary operators must be rejected.');
    smart_gallery_test_rejected(static fn(): array => smart_gallery_validate_rules([
        'version' => 1,
        'root' => [
            'type' => 'condition',
            'field' => 'capture_date',
            'operator' => 'month',
            'value' => 13,
        ],
    ]), 'Invalid capture months must be rejected.');
    smart_gallery_test_rejected(static fn(): array => smart_gallery_rules_from_json(
        '{bad',
    ), 'Malformed JSON must be rejected.');
    smart_gallery_test_rejected(static fn(): array => smart_gallery_validate_rules([
        'version' => 99,
        'root' => [],
    ]), 'Unknown versions must fail safely.');
    smart_gallery_test_rejected(static fn(): array => smart_gallery_validate_rules([
        'version' => 1,
        'root' => ['type' => 'group', 'operator' => 'NOT', 'children' => []],
    ]), 'NOT must contain exactly one child.');

    $deep = ['type' => 'condition', 'field' => 'gps', 'operator' => 'exists'];
    for ($index = 0; $index < 7; $index++)
        $deep = ['type' => 'group', 'operator' => 'AND', 'children' => [$deep]];
    smart_gallery_test_rejected(static fn(): array => smart_gallery_validate_rules([
        'version' => 1,
        'root' => $deep,
    ]), 'Excessive nesting must be rejected.');

    $ruleBuilderSource = file_get_contents(
        dirname(__DIR__)
            . '/public/assets/gallery-modules/admin-smart-galleries.js',
    );
    smart_gallery_test_assert(
        is_string($ruleBuilderSource)
            && str_contains(
                $ruleBuilderSource,
                'parentRule.children.indexOf(node)',
            ),
        'Saved rule removal targets the owning rule group.',
    );
    smart_gallery_test_assert(
        !str_contains(
            (string) $ruleBuilderSource,
            'parent.children.indexOf(node)',
        ),
        'Rule removal must not treat DOM children as the persisted rule array.',
    );

    $placementMigration = file_get_contents(
        dirname(__DIR__)
            . '/database/migrations/202608140002_smart_gallery_placement.php',
    );
    smart_gallery_test_assert(
        is_string($placementMigration)
            && str_contains(
                $placementMigration,
                "ENUM('unlisted', 'root', 'gallery')",
            ),
        'Placement migration defines the three supported listing modes.',
    );
    $homeSource = file_get_contents(
        dirname(__DIR__) . '/app/controllers/public_gallery_home.php',
    );
    $gallerySource = file_get_contents(
        dirname(__DIR__) . '/app/controllers/public_gallery_page.php',
    );
    $serviceSource = file_get_contents(
        dirname(__DIR__) . '/app/services/smart_galleries.php',
    );
    $modelSource = file_get_contents(
        dirname(__DIR__) . '/app/models/smart_galleries.php',
    );
    $adminControllerSource = file_get_contents(
        dirname(__DIR__) . '/app/controllers/smart_galleries.php',
    );
    $adminViewSource = file_get_contents(
        dirname(__DIR__) . '/app/views/smart_galleries.php',
    );
    smart_gallery_test_assert(
        php_source_contains(
            (string) $homeSource,
            'smart_galleries_for_placement(null, true)',
        ),
        'Root Smart Galleries join homepage pagination input.',
    );
    smart_gallery_test_assert(
        php_source_contains(
            (string) $gallerySource,
            "smart_gallery_attachment_groups((int) \$gallery['id'], \$publicOnly)",
        ),
        'Placed Smart Galleries are collected independently around physical gallery content.',
    );
    smart_gallery_test_assert(
        str_contains(
            (string) $serviceSource,
            '$submittedSlug !== \'\' ? $submittedSlug : $title',
        ),
        'A blank submitted slug is generated from the Smart Gallery title.',
    );
    smart_gallery_test_assert(
        str_contains((string) $adminControllerSource, "'smart_gallery.saved'")
            && str_contains(
                (string) $adminControllerSource,
                "'smart_gallery.validation_failed'",
            )
            && str_contains(
                (string) $adminControllerSource,
                "'smart_gallery.action_failed'",
            ),
        'Smart Gallery Admin actions emit success, validation, and unexpected-failure diagnostics.',
    );
    smart_gallery_test_assert(
        str_contains(
            (string) $adminControllerSource,
            'function smart_gallery_admin_log_context',
        )
            && str_contains((string) $adminControllerSource, "'group_counts'")
            && str_contains(
                (string) $adminControllerSource,
                "'condition_count'",
            ),
        'Smart Gallery logs include a bounded rule-structure and creation-intent summary.',
    );
    smart_gallery_test_assert(
        !str_contains(
            (string) $ruleBuilderSource,
            'parentSelect.required = requiresParent',
        ),
        'Smart Gallery definition no longer enforces one physical parent in the browser.',
    );
    $multiplePlacementMigration = file_get_contents(
        dirname(__DIR__)
            . '/database/migrations/202608140003_smart_gallery_multiple_placements.php',
    );
    smart_gallery_test_assert(
        is_string($multiplePlacementMigration)
            && str_contains(
                $multiplePlacementMigration,
                'CREATE TABLE smart_gallery_placements',
            )
            && str_contains(
                $multiplePlacementMigration,
                'PRIMARY KEY (smart_gallery_id, gallery_id)',
            ),
        'Multiple-placement migration stores a many-to-many Smart Gallery relationship.',
    );
    smart_gallery_test_assert(
        str_contains(
            (string) $serviceSource,
            'smart_gallery_model_replace_gallery_attachments',
        )
            && str_contains(
                (string) $modelSource,
                'DELETE FROM smart_gallery_placements WHERE gallery_id = ?',
            )
            && str_contains(
                (string) $modelSource,
                'INSERT INTO smart_gallery_placements',
            ),
        'Physical gallery assignment delegates an atomic per-parent replacement to the Smart Gallery model without moving other parents.',
    );
    smart_gallery_test_assert(
        str_contains(
            (string) $serviceSource,
            'function smart_gallery_remove_from_gallery',
        )
            && str_contains(
                (string) $adminViewSource,
                'value="remove_placement"',
            )
            && str_contains(
                (string) $adminControllerSource,
                'smart_gallery_admin_editor_view_model',
            ),
        'Smart Gallery editor exposes a per-location removal action backed by the canonical placement service through the MVC view contract.',
    );

    fwrite(STDOUT, "Smart Gallery rule tests passed.\n");
}
