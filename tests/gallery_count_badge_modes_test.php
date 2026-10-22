<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_count_badge_modes_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protect the administrator-only dual photo-counter presentation, its
 *   independent Theme mode/icon settings, and photo-card tag previews.
 *
 * Responsibilities:
 *   - Cover first-upgrade defaults, eight mode/icon combinations and inheritance
 *   - Preserve legacy show/hide overrides and the public-visitor privacy boundary
 *   - Verify accessible Admin eye/stacked-photo HTML and equal-count collapse
 *   - Reject malformed settings and protect hidden-mode icon persistence
 *   - Bound only photo-card tag links while preserving full tag inputs
 *
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Report that public tag browsing is active in the tag-preview fixture.
     * @param string $capability Feature key requested by the preview helper.
     * @return bool Whether the fixture exposes clickable tag names.
     */
    function feature_capability_effective_enabled(string $capability): bool
    {
        return $capability === 'public_tag_browsing';
    }

    /**
     * Fetch a transient Theme value or the requested canonical default.
     *
     * @param string $key Theme preference key.
     * @param ?string $default Default when no saved setting exists.
     * @return ?string Fixture-only resolved value.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['gallery_count_modes_test_settings'][$key] ?? $default;
    }

    /**
     * Advertise the existing enum schema without connecting to the database.
     *
     * @param string $table Database table identifier.
     * @param string $column Column identifier.
     * @return bool Whether the test fixture exposes the gallery override.
     */
    function db_column_exists(string $table, string $column): bool
    {
        return (
            $table === 'galleries'
                && $column === 'count_badge_visibility'
                && ($GLOBALS['gallery_count_modes_test_schema'] ?? true)
        );
    }

    /**
     * Resolve fixed English test strings using the real placeholder convention.
     *
     * @param string $key Translation key.
     * @param string $fallback English text when no catalog is loaded.
     * @param array<string,string|int> $params Interpolation values.
     * @return string Readable resolved label.
     */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        foreach ($params as $name => $value) {
            $fallback = str_replace(
                '{' . $name . '}',
                (string) $value,
                $fallback,
            );
        }
        return $fallback;
    }

    /** Record and return the fixture's descendant-preload request.
     * @param list<int> $galleryIds Requested root gallery IDs.
     * @param bool $publicOnly Apply public-listing restrictions.
     * @return void
     */
    function child_galleries_tree_preload(
        array $galleryIds,
        bool $publicOnly,
    ): void {
        $GLOBALS['gallery_count_modes_preload_calls'][] = [
            'gallery_ids' => $galleryIds,
            'public_only' => $publicOnly,
        ];
    }

    /** Resolve the test's protected-mode sentinel through a narrow access adapter.
     * @param array{id:int,access_mode?:string,parent_id?:int|null} $gallery Fixture gallery fields.
     * @return array{id:int,access_mode?:string,parent_id?:int|null}|null Protected gallery row or no requirement.
     */
    function gallery_access_requirement(array $gallery): ?array
    {
        return ($gallery['access_mode'] ?? 'normal') === 'password'
            ? $gallery
            : null;
    }

    /** Return one deterministic image-count batch for controller setup.
     * @param list<int> $galleryIds Countable gallery IDs.
     * @param bool $publicOnly Apply visitor listing restrictions.
     * @param bool $withoutAdminBypass Apply visitor access under Admin.
     * @return array<int,int> Fixture counts keyed by gallery ID.
     */
    function gallery_branch_image_counts(
        array $galleryIds,
        bool $publicOnly,
        bool $withoutAdminBypass = false,
    ): array {
        return array_fill_keys($galleryIds, 7);
    }

    /** Record one batched attachment projection read with deterministic rows.
     * @param list<int> $galleryIds Eligible root gallery IDs.
     * @param string $kind Requested PDF or music category.
     * @param bool $withoutAdminBypass Apply ordinary visitor access.
     * @return array<int,int> Prepared fixture totals keyed by gallery ID.
     */
    function gallery_branch_attachment_usage_counts(
        array $galleryIds,
        string $kind,
        bool $withoutAdminBypass = true,
    ): array {
        $GLOBALS['gallery_count_modes_usage_calls'][] = [
            'gallery_ids' => $galleryIds,
            'kind' => $kind,
            'without_admin_bypass' => $withoutAdminBypass,
        ];
        return array_intersect_key(
            $GLOBALS['gallery_count_modes_usage_rows'][$kind] ?? [],
            array_fill_keys($galleryIds, true),
        );
    }

    /** Provide an explicit fixture cover asset without image lookups.
     * @param array{id:int,access_mode?:string} $gallery Gallery identity.
     * @param bool $publicOnly Apply public cover policy.
     * @return string Stable fixture asset URL.
     */
    function gallery_cover_asset_url(array $gallery, bool $publicOnly): string
    {
        return '/fixture-cover.jpg';
    }

    /** Warm no cover images because the fixture always uses an asset.
     * @param list<array{id:int,gallery_id:int,relative_path:string}> $images Prepared thumbnail rows; this asset-backed fixture leaves the list empty.
     * @return void
     */
    function thumbnail_bundles_preload(array $images): void {}

    /** Report whether the fixture's current user is under the Admin age limit.
     * @return bool False for the adult-only badge case.
     */
    function current_user_is_known_under_18(): bool
    {
        return false;
    }
}

namespace Gallery\Core {
    /** Build a deterministic URL for the public tag-preview fixture.
     * @param string $route Named route requested by the tag renderer.
     * @param array<string,string|int> $params Route parameters.
     * @return string Fixture URL for the requested tag.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/' . $route . '/' . (string) ($params['slug'] ?? '');
    }

    /** Return the explicitly selected fixture session.
     * @return array{id:int}|null Authenticated user or public visitor.
     */
    function current_user(): ?array
    {
        return $GLOBALS['gallery_count_modes_test_user'] ?? null;
    }

    /** Keep the isolated controller context outside anonymous preview mode.
     * @return bool False for this owned context fixture.
     */
    function admin_anonymous_preview_active(): bool
    {
        return false;
    }

    /**
     * Escape accessible HTML labels just as the application does.
     *
     * @param string $value Dynamic label text.
     * @return string Escaped HTML text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace {
    require_once __DIR__ . '/../app/services/gallery_count_badges.php';
    require_once __DIR__ . '/../app/views/gallery_descriptions.php';
    require_once __DIR__ . '/../app/views/public_gallery_cards.php';
    require_once __DIR__ . '/../app/views/public_tags.php';
    require_once __DIR__ . '/../app/views/public_gallery_pages.php';
    require_once __DIR__ . '/../app/controllers/public_gallery_cards.php';
    require_once __DIR__ . '/../app/controllers/public_tags.php';

    /** Reject an invalid counter contract without continuing to later cases. */
    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    $renderPublicCard = static function (array $overrides = []): string {
        ob_start();
        \Gallery\Views\view_render_public_gallery_card(array_merge([
            'gallery_id' => 42,
            'title' => 'Prepared media counts',
            'url' => '/gallery/42',
            'visibility' => 'public',
            'description' => '',
        ], $overrides));
        return (string) ob_get_clean();
    };

    $renderHero = static function (array $overrides = []): string {
        ob_start();
        \Gallery\Views\view_render_public_gallery_hero(array_merge([
            'gallery_id' => 42,
            'show_count_badge' => true,
            'branch_image_count' => 7,
            'media_counts' => ['music' => 2, 'pdf' => 3],
        ], $overrides));
        return (string) ob_get_clean();
    };

    $renderPhotoTagPreview = static function (array $tags): string {
        ob_start();
        \Gallery\Controllers\render_photo_card_tag_preview($tags);
        return (string) ob_get_clean();
    };

    $photoTags = [
        ['name' => 'Daník & <Friends>', 'slug' => 'danik-friends'],
        ['name' => 'Robert', 'slug' => 'robert'],
        ['name' => 'Marie', 'slug' => 'marie'],
        ['name' => 'Petr', 'slug' => 'petr'],
        ['name' => 'Lucie', 'slug' => 'lucie'],
    ];
    $photoPreviewLimit = \Gallery\Controllers\PHOTO_CARD_TAG_PREVIEW_LIMIT;
    $check(
        $photoPreviewLimit === 3,
        'Photo-card preview policy retains the requested three-tag limit.',
    );
    // These values straddle empty, below, exact-limit, and overflow states.
    foreach (
        [
            0,
            1,
            $photoPreviewLimit,
            $photoPreviewLimit + 1,
            $photoPreviewLimit + 2,
        ] as $tagCount
    ) {
        $previewHtml = $renderPhotoTagPreview(
            array_slice($photoTags, 0, $tagCount),
        );
        preg_match_all(
            '/<a class="tag" href="([^"]*)" title="([^"]*)">([^<]*)<\/a>/u',
            $previewHtml,
            $links,
            PREG_SET_ORDER,
        );
        $expectedLinkCount = min($tagCount, $photoPreviewLimit);
        $check(
            count($links) === $expectedLinkCount
                && ($tagCount === 0
                    ? $previewHtml === ''
                    : str_starts_with($previewHtml, '<p class="tag-list">')),
            'Photo-card tag previews preserve only the first three links.',
        );
        foreach ($links as $index => $link) {
            $expectedTag = $photoTags[$index];
            $check(
                $link[1] === '/tag/' . $expectedTag['slug']
                    && $link[3] === htmlspecialchars(
                        $expectedTag['name'],
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8',
                    ),
                'Visible photo-tag links retain order and escaped Unicode names.',
            );
        }
        $hasMore = $tagCount > $photoPreviewLimit;
        $ellipsis = '<span class="tag tag-more" aria-hidden="true">…</span>';
        $check(
            substr_count($previewHtml, $ellipsis) === ($hasMore ? 1 : 0)
                && !str_contains($previewHtml, '<details')
                && !str_contains($previewHtml, '<button'),
            'Photo-card overflow is one passive ellipsis with no expander control.',
        );
        $check(
            !str_contains($previewHtml, 'Petr')
                || $tagCount <= $photoPreviewLimit,
            'Hidden photo tags are not rendered in the card preview.',
        );
    }
    $check(
        count($photoTags) === 5,
        'Rendering a short card preview leaves the full assigned tag list available.',
    );

    $heroHtml = $renderHero();
    $heroPdfPosition = strpos($heroHtml, 'gallery-media-count-icon-pdf');
    $heroMusicPosition = strpos($heroHtml, 'gallery-media-count-icon-music');
    $heroPhotoPosition = strpos($heroHtml, 'subgallery-stack-icon');
    $check(
        $heroPdfPosition !== false
            && $heroMusicPosition !== false
            && $heroPhotoPosition !== false
            && $heroPdfPosition < $heroMusicPosition
            && $heroMusicPosition < $heroPhotoPosition
            && str_contains($heroHtml, '3 unique PDF files')
            && str_contains($heroHtml, '2 unique music files'),
        'Opened galleries place accessible prepared PDF and music counts before the photo badge.',
    );
    $check(
        !str_contains(
            $renderHero(['show_count_badge' => false]),
            'gallery-hero-count-badge',
        ),
        'Disabled gallery count policy suppresses every hero count badge.',
    );
    $emptyHero = $renderHero([
        'media_counts' => ['music' => 0, 'pdf' => 0],
    ]);
    $check(
        !str_contains($emptyHero, 'gallery-media-count-badge')
            && str_contains($emptyHero, 'subgallery-stack-icon'),
        'Empty or unavailable media counts leave the existing photo badge intact.',
    );
    foreach (['pdf', 'music'] as $onlyKind) {
        $onlyHero = $renderHero([
            'media_counts' => [
                'pdf' => $onlyKind === 'pdf' ? 3 : 0,
                'music' => $onlyKind === 'music' ? 2 : 0,
            ],
        ]);
        $otherKind = $onlyKind === 'pdf' ? 'music' : 'pdf';
        $check(
            str_contains($onlyHero, 'gallery-media-count-icon-' . $onlyKind)
                && !str_contains(
                    $onlyHero,
                    'gallery-media-count-icon-' . $otherKind,
                ),
            'Each positive hero media kind renders independently.',
        );
    }

    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'both';
    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_icons_enabled'] = '1';
    $GLOBALS['gallery_count_modes_usage_rows'] = [
        'pdf' => [41 => 3, 44 => 4],
        'music' => [41 => 2, 44 => 5],
    ];
    $contextGalleries = [
        ['id' => 41, 'access_mode' => 'normal'],
        ['id' => 42, 'access_mode' => 'normal', 'count_badge_visibility' => 'hide'],
        ['id' => 43, 'access_mode' => 'password'],
        ['id' => 44, 'access_mode' => 'normal'],
    ];
    $GLOBALS['gallery_count_modes_test_user'] = null;
    $GLOBALS['gallery_count_modes_usage_calls'] = [];
    $publicContexts =
        \Gallery\Controllers\public_gallery_card_rendering_contexts(
            $contextGalleries,
            true,
            true,
        );
    $expectedUsageCalls = [
        [
            'gallery_ids' => [41, 44],
            'kind' => 'pdf',
            'without_admin_bypass' => true,
        ],
        [
            'gallery_ids' => [41, 44],
            'kind' => 'music',
            'without_admin_bypass' => true,
        ],
    ];
    $check(
        $GLOBALS['gallery_count_modes_usage_calls'] === $expectedUsageCalls
            && $publicContexts[41]['media_counts'] === [
                'pdf' => 3,
                'music' => 2,
            ]
            && $publicContexts[44]['media_counts'] === [
                'pdf' => 4,
                'music' => 5,
            ]
            && $publicContexts[42]['media_counts'] === [
                'pdf' => 0,
                'music' => 0,
            ]
            && $publicContexts[43]['media_counts'] === [
                'pdf' => 0,
                'music' => 0,
            ],
        'Public batched contexts prepare visible media counts and keep disabled/protected cards at zero without projection reads.',
    );

    $GLOBALS['gallery_count_modes_test_user'] = ['id' => 7];
    $GLOBALS['gallery_count_modes_usage_calls'] = [];
    $adminContexts =
        \Gallery\Controllers\public_gallery_card_rendering_contexts(
            $contextGalleries,
            false,
            true,
        );
    $check(
        $GLOBALS['gallery_count_modes_usage_calls'] === $expectedUsageCalls
            && $adminContexts[41]['media_counts'] === [
                'pdf' => 3,
                'music' => 2,
            ]
            && $adminContexts[43]['media_counts'] === [
                'pdf' => 0,
                'music' => 0,
            ],
        'Authenticated Admin contexts still batch visitor-safe counts and omit protected-card projections.',
    );

    $GLOBALS['gallery_count_modes_test_user'] = null;
    $GLOBALS['gallery_count_modes_usage_calls'] = [];
    $disabledContexts =
        \Gallery\Controllers\public_gallery_card_rendering_contexts(
            [$contextGalleries[1]],
            true,
            true,
        );
    $check(
        $GLOBALS['gallery_count_modes_usage_calls'] === []
            && $disabledContexts[42]['media_counts'] === [
                'pdf' => 0,
                'music' => 0,
            ],
        'A disabled card count setting produces zero media sentinels without projection reads.',
    );

    $GLOBALS['gallery_count_modes_usage_calls'] = [];
    $omittedCountContexts =
        \Gallery\Controllers\public_gallery_card_rendering_contexts(
            [$contextGalleries[0], $contextGalleries[3]],
            true,
            false,
        );
    $check(
        $GLOBALS['gallery_count_modes_usage_calls'] === []
            && $omittedCountContexts[41]['media_counts'] === [
                'pdf' => 0,
                'music' => 0,
            ]
            && $omittedCountContexts[44]['media_counts'] === [
                'pdf' => 0,
                'music' => 0,
            ],
        'includeCounts=false emits zero sentinels without any optional projection reads.',
    );
    $GLOBALS['gallery_count_modes_test_user'] = null;

    $renderedMediaCard = $renderPublicCard([
        'show_count_badge' => true,
        'branch_image_count' => 7,
        'count_badge' => \Gallery\Services\gallery_count_badge_presentation(
            ['count_badge_visibility' => null],
            true,
            7,
            11,
        ),
        'media_counts' => ['music' => 2, 'pdf' => 3],
        'cover_asset' => '/fixture-cover.jpg',
    ]);
    $photoBadgePosition = strpos(
        $renderedMediaCard,
        'gallery-count-badge-admin',
    );
    $pdfBadgePosition = strpos(
        $renderedMediaCard,
        'gallery-media-count-icon-pdf',
    );
    $musicBadgePosition = strpos(
        $renderedMediaCard,
        'gallery-media-count-icon-music',
    );
    $check(
        str_contains($renderedMediaCard, 'gallery-card-media-count-stack')
            && $photoBadgePosition !== false
            && $pdfBadgePosition !== false
            && $musicBadgePosition !== false
            && $photoBadgePosition < $pdfBadgePosition
            && $pdfBadgePosition < $musicBadgePosition,
        'Admin photo count remains first with PDF then music counts in one stack.',
    );
    $check(
        str_contains($renderedMediaCard, '3 unique PDF files')
            && str_contains($renderedMediaCard, '2 unique music files')
            && str_contains($renderedMediaCard, 'gallery-count-badge-admin'),
        'Card consumes prepared media counts and retains its accessible Admin photo counter.',
    );

    $pdfOnlyCard = $renderPublicCard([
        'show_count_badge' => true,
        'media_counts' => ['music' => 0, 'pdf' => 4],
    ]);
    $check(
        str_contains($pdfOnlyCard, 'gallery-card-media-count-stack')
            && str_contains($pdfOnlyCard, '4 unique PDF files')
            && !str_contains($pdfOnlyCard, 'gallery-media-count-icon-music')
            && !str_contains($pdfOnlyCard, 'gallery-count-badge-admin'),
        'A positive PDF count renders by itself while zero music stays absent.',
    );
    $check(
        !str_contains(
            $renderPublicCard([
                'show_count_badge' => false,
                'media_counts' => ['music' => 2, 'pdf' => 3],
            ]),
            'gallery-media-count-badge',
        ),
        'Disabled count-badge policy suppresses prepared media counts.',
    );
    $check(
        !str_contains(
            $renderPublicCard([
                'show_count_badge' => true,
                'is_protected' => true,
                'media_counts' => ['music' => 2, 'pdf' => 3],
            ]),
            'gallery-media-count-badge',
        ),
        'Protected cards suppress media counts even when the count setting is enabled.',
    );
    $check(
        !str_contains(
            $renderPublicCard([
                'show_count_badge' => false,
                'media_counts' => ['music' => 0, 'pdf' => 0],
            ]),
            'gallery-card-media-count-stack',
        ),
        'Cards without an enabled photo-count policy or positive media counts retain no badge stack.',
    );

    foreach ([
        ['cover_asset' => '/fixture-cover.jpg'],
        ['cover_picture_html' => '<picture><img src="/cover.jpg" alt=""></picture>'],
        ['collage_picture_html' => ['<span class="gallery-collage"></span>']],
        [],
    ] as $renderBranch) {
        $branchCard = $renderPublicCard(array_merge(
            [
                'show_count_badge' => true,
                'media_counts' => ['music' => 1, 'pdf' => 1],
            ],
            $renderBranch,
        ));
        $stackPosition = strpos(
            $branchCard,
            'gallery-card-media-count-stack',
        );
        $coverPosition = strpos($branchCard, '<img');
        $check(
            $stackPosition !== false
                && ($coverPosition === false || $stackPosition < $coverPosition),
            'Media counts use the same pre-cover position for every physical-card render branch.',
        );
    }

    $GLOBALS['gallery_count_modes_test_settings'] = [
        // This is the OLD boolean preference. It must not block the new first-upgrade default.
        'theme_gallery_count_badge_enabled' => '0',
    ];
    $GLOBALS['gallery_count_modes_test_schema'] = true;
    $inherit = ['count_badge_visibility' => null];
    $check(
        \Gallery\Services\theme_gallery_count_badge_mode() === 'both',
        'First upgrade must default to Both independently of the old disabled checkbox.',
    );
    $check(
        \Gallery\Services\theme_gallery_count_badge_icons_enabled(),
        'First upgrade must default icons to ON.',
    );
    $check(
        \Gallery\Services\gallery_count_badge_storage_value(['malformed'])
            === null
            && \Gallery\Services\gallery_count_badge_storage_value('unknown')
                === null,
        'Malformed gallery enum values should fail closed to inheritance.',
    );
    $check(
        \Gallery\Services\gallery_count_badge_storage_value('show') === 'show'
            && \Gallery\Services\gallery_effective_count_badge_mode([
                'count_badge_visibility' => 'show',
            ]) === 'both',
        'Legacy explicit Show must map to a visible Both counter.',
    );
    $check(\Gallery\Services\gallery_effective_count_badge_mode([
        'count_badge_visibility' => 'hide',
    ]) === 'hidden', 'Legacy explicit Hide must remain hidden.');
    $check(\Gallery\Services\gallery_effective_count_badge_mode([
        'count_badge_visibility' => 'public',
    ]) === 'public'
        && \Gallery\Services\gallery_effective_count_badge_mode([
            'count_badge_visibility' => 'all',
        ])
            === 'all', 'Explicit per-gallery Public/All overrides must not follow Theme mode.');

    $modes = ['public', 'all', 'both', 'hidden'];
    foreach ($modes as $mode) {
        foreach ([true, false] as $icons) {
            $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] =
                $mode;
            $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_icons_enabled'] =
                $icons ? '1' : '0';
            $badge = \Gallery\Services\gallery_count_badge_presentation(
                $inherit,
                true,
                12,
                18,
            );
            $check(
                $badge['visible'] === ($mode !== 'hidden'),
                'Wrong admin badge visibility in ' . $mode,
            );
            $check($badge['mode'] === $mode, 'Wrong admin mode in ' . $mode);
            $check(
                $badge['icons'] === $icons,
                'Icon setting unexpectedly depends on count mode.',
            );

            if ($badge['visible']) {
                ob_start();
                \Gallery\Views\view_gallery_count_admin_values($badge);
                $html = (string) ob_get_clean();
                $check(
                    str_contains($html, 'gallery-count-number'),
                    'Admin count is missing a number.',
                );
                $check(
                    ($mode === 'both') === str_contains(
                        $html,
                        'gallery-count-separator',
                    ),
                    'The separator must appear only for different Public/All counts.',
                );
                $check(
                    ($mode !== 'all') === str_contains($html, '>12</span>'),
                    'Public count incorrectly rendered or omitted.',
                );
                $check(
                    ($mode !== 'public') === str_contains($html, '>18</span>'),
                    'All count incorrectly rendered or omitted.',
                );
                $check(
                    ($icons && $mode !== 'all') === str_contains(
                        $html,
                        'gallery-count-eye-icon',
                    ),
                    'Eye icon not matched to enabled Public segment.',
                );
                $check(
                    ($icons && $mode !== 'public') === str_contains(
                        $html,
                        'subgallery-stack-icon',
                    ),
                    'Stacked-photo icon not matched to enabled All segment.',
                );
                $check(
                    str_contains(
                        \Gallery\Views\view_gallery_count_admin_label($badge),
                        'subgalleries',
                    ),
                    'Accessible description must mention descendants.',
                );
            }
            $guest = \Gallery\Services\gallery_count_badge_presentation(
                $inherit,
                false,
                12,
                18,
            );
            $check(
                $guest['mode'] === ($mode === 'hidden' ? 'hidden' : 'public')
                    && $guest['all_count'] === null
                    && $guest['icons'] === false,
                'Guest must never receive All or the dual counter for mode '
                    . $mode,
            );
            $check(
                $guest['visible'] === ($mode !== 'hidden'),
                'Guests should retain one public badge except when Hidden.',
            );
            $check(
                !\Gallery\Services\gallery_count_badge_needs_total(
                    $inherit,
                    false,
                ),
                'Guest should never cause an all-visibility branch query.',
            );
        }
    }

    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'both';
    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_icons_enabled'] = '1';
    foreach ([0, 18] as $equal) {
        $badge = \Gallery\Services\gallery_count_badge_presentation(
            $inherit,
            true,
            $equal,
            $equal,
        );
        $check(
            $badge['mode'] === 'all',
            'Both equal counts must collapse to All.',
        );
        ob_start();
        \Gallery\Views\view_gallery_count_admin_values($badge);
        $html = (string) ob_get_clean();
        $check(
            !str_contains($html, 'gallery-count-separator')
                && !str_contains($html, 'gallery-count-eye-icon')
                && str_contains($html, 'gallery-count-number'),
            'Collapsed count must be a single All segment.',
        );
    }
    $diff = \Gallery\Services\gallery_count_badge_presentation(
        $inherit,
        true,
        0,
        18,
    );
    $check(
        $diff['mode'] === 'both'
            && $diff['public_count'] === 0
            && $diff['all_count'] === 18,
        '0 / N must not be collapsed.',
    );
    $check(
        \Gallery\Services\gallery_count_badge_needs_total($inherit, true),
        'Administrator Both mode must request the full branch count.',
    );

    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'hidden';
    $check(
        \Gallery\Services\theme_gallery_count_badge_icons_enabled(),
        'Hidden mode must not clear the independent icon setting.',
    );
    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'public';
    $check(
        \Gallery\Services\theme_gallery_count_badge_icons_enabled(),
        'Unhiding the count must preserve the icon setting.',
    );

    // The identity check is deliberately performed in the server-side controllers,
    // not in a rendered HTML/JS attribute or the count formatting service itself.
    $cards = (string) file_get_contents(__DIR__
        . '/../app/controllers/public_gallery_cards.php');
    $page = (string) file_get_contents(__DIR__
        . '/../app/controllers/public_gallery_page.php');
    $check(
        str_contains($cards, 'current_user() !== null')
            && str_contains($cards, 'admin_anonymous_preview_active()'),
        'Card totals must remain bound to the historical authenticated Admin session.',
    );
    $check(
        str_contains(
            $page,
            'gallery_count_badge_needs_total($gallery, $isCountAdmin)',
        ),
        'Hero totals must remain gated on Admin mode and session.',
    );

    echo "gallery_count_badge_modes_test: PASS\n";
}
