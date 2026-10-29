<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_display_editor_layout_test.php
 * Module Type: Regression Test
 * Purpose: Verify gallery photo formats and compact Display controls.
 * Responsibilities:
 *   - Keep the grid before optional feature controls.
 *   - Keep independently flowing groups and preserve submitted fields.
 *   - Supply presentation-only markup for the registered browser fixture.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Escape fixture content as production markup does. @param string $value Raw label. @return string HTML-safe label. */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build deterministic fixture URLs. @param string $route Route name. @param array<string,int|string> $params Route parameters. @return string Fixture URL. */
    function url_for(string $route, array $params = []): string
    {
        return (
            '/?page='
                . rawurlencode($route)
                . ($params === [] ? '' : '&' . http_build_query($params))
        );
    }
}

namespace Gallery\Services {
    /** Read fixture-only settings without installation storage.
     * @param string $key Physical gallery preference key.
     * @param string|null $default Unset preference fallback.
     * @return string|null Stored fixture value or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['display_settings'][$key] ?? $default;
    }

    /** Persist a setting only in the disposable fixture map.
     * @param string $key Physical gallery preference key.
     * @param string $value Validated format identifier.
     * @return void Store the setting without database or filesystem access.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['display_settings'][$key] = $value;
    }
    /** Interpolate one fixture label. @param string $key Translation key. @param string $fallback English label. @param array<string,string> $params Placeholders. @return string Fixture text. */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        foreach ($params as $name => $value) {
            $fallback = str_replace('{' . $name . '}', $value, $fallback);
        }
        return $fallback;
    }
}

namespace Gallery\Views {
    /** Capture a production-shaped tab panel. @param string $id Tab ID. @param string $contentHtml Rendered controls. @param bool $active Whether visible. @return void Emit fixture panel. */
    function view_render_admin_tab_panel(
        string $id,
        string $contentHtml,
        bool $active = false,
    ): void {
        echo
            '<section id="'
                . \Gallery\Core\e($id)
                . '"'
                . ($active ? ' class="is-active"' : '')
                . '>'
                . $contentHtml
                . '</section>';
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/gallery_grid.php';
    require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php';
    require_once dirname(__DIR__) . '/app/views/public_gallery_lightbox.php';
    require_once dirname(__DIR__) . '/app/views/admin_gallery_forms.php';

    /** Reject a missing Display editor postcondition. @param bool $condition Observed HTML result. @param string $message Failure explanation. @return void Throw on failure. */
    function display_editor_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $options = [
        ['value' => 'inherit', 'label' => 'Inherit'],
        ['value' => 'on', 'label' => 'On'],
        ['value' => 'off', 'label' => 'Off'],
    ];
    $model = [
        'photo_card_ratio' => [
            'current' => '4:5',
            'options' => \Gallery\Services\gallery_photo_card_ratio_options(),
            'label' => 'Photo card format',
            'help' => 'Crop previews in this gallery only. Originals stay unchanged.',
        ],
        'active' => true,
        'advanced_label' => 'Advanced display settings',
        'grid' => [
            'ready' => true,
            'title' => 'Display grid',
            'override_enabled' => true,
            'override_label' => 'Custom grid',
            'columns_label' => 'Columns',
            'rows_label' => 'Rows',
            'columns' => 3,
            'rows' => 2,
            'default_columns' => 4,
            'default_rows' => 5,
            'default_status_label' => 'Default settings',
            'custom_status_label' => 'Custom settings',
            'reset_default_label' => 'Reset to default',
            'max_columns' => 8,
            'max_rows' => 8,
            'use_for_subgalleries' => true,
            'recursive_label' => 'Use for subgalleries',
            'source_text' => 'Current source: gallery.',
            'help' => 'Inherit from a parent if unset.',
        ],
        'voting' => [
            'visible' => true,
            'checked' => false,
            'label' => 'Voting',
            'help' => 'Votes remain stored.',
        ],
        'filenames' => [
            'ready' => true,
            'checked' => true,
            'label' => 'File names',
            'help' => 'Raw names are optional.',
        ],
        'picture_game' => [
            'visible' => true,
            'checked' => false,
            'label' => 'Picture game',
        ],
        'gps' => [
            'state' => 'override',
            'current_mode' => 'inherit',
            'label' => 'EXIF / GPS',
            'options' => $options,
            'help' => 'Hide GPS coordinates.',
        ],
        'description_layout' => [
            'ready' => true,
            'current' => null,
            'label' => 'Card layout',
            'inherit_label' => 'Inherit',
            'options' => $options,
            'help' => 'Current effective layout.',
        ],
        'count_badge' => [
            'ready' => true,
            'current' => 'inherit',
            'label' => 'Card badge',
            'options' => $options,
            'help' => 'Shows the picture count.',
        ],
        'lightbox' => [
            'state' => 'ready',
            'current' => 'inherit',
            'label' => 'Lightbox',
            'inherit_label' => 'Inherit',
            'options' => $options,
            'help' => 'Choose a browsing mode.',
        ],
        'thumbnail_bounds' => [
            'ready' => true,
            'title' => 'Responsive thumbnail quality bounds',
            'help' => 'Keep both sides on Auto.',
            'control_html' => '<input name="gallery_thumbnail_min_size" value="0"><input name="gallery_thumbnail_max_size" value="1600">',
            'recursive_label' => 'Save to subgalleries',
            'recursive_help' => 'Affects descendants.',
        ],
    ];
    ob_start();
    \Gallery\Views\view_render_admin_gallery_display_tab($model);
    $html = (string) ob_get_clean();

    if (in_array('--render', $argv ?? [], true)) {
        require_once dirname(__DIR__) . '/app/controllers/shared_layout.php';
        require_once dirname(__DIR__) . '/app/views/public_gallery_pages.php';
        foreach (\Gallery\Controllers\shared_layout_admin_stylesheet_files() as $stylesheet) {
            echo '<link rel="stylesheet" href="/public/'
                . \Gallery\Core\e($stylesheet) . '">';
        }
        echo '<form class="admin-edit-gallery-form" action="/fixture-display">'
            . '<input type="hidden" name="id" value="42">'
            . '<input type="hidden" name="csrf_token" value="display-fixture">'
            . '<input type="hidden" name="edit_revision" value="1">'
            . '<label>Unsaved title <input name="title" value="Unsaved draft"></label>'
            . $html . '<button type="submit">Save gallery</button></form>';
        echo '<template id="fixture-public-markup"><div class="public-page">';
        foreach (\Gallery\Controllers\shared_layout_public_stylesheet_files() as $stylesheet) {
            echo '<link rel="stylesheet" href="/public/'
                . \Gallery\Core\e($stylesheet) . '">';
        }
        // The runtime header appends this responsive stylesheet separately
        // from shared_layout_public_stylesheet_files(); retain that full cascade.
        echo '<link rel="stylesheet" href="/public/assets/styles/mobile-gallery.css">';
        ob_start();
        foreach (['progressive', 'responsive'] as $renderer) {
            // Different intrinsic dimensions exercise cover cropping, while
            // source promotion remains covered by the renderer's own tests.
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320"'
                . ' height="180"><rect width="320" height="180" fill="#8ab"/></svg>';
            $source = 'data:image/svg+xml,' . rawurlencode($svg);
            \Gallery\Views\view_render_public_gallery_image_card([
                'class' => 'image-card',
                'image_id' => 1,
                'thumbnail_html' => '<picture data-fixture-renderer="' . $renderer
                    . '"><img src="' . \Gallery\Core\e($source)
                    . '" width="320" height="180" alt="Fixture photo"></picture>',
            ]);
        }
        $cards = (string) ob_get_clean();
        \Gallery\Views\view_render_public_gallery_image_section([
            'visible' => true,
            'gallery_id' => 42,
            'grid_class' => ' pagination-grid-columns-2',
            'cards_html' => $cards,
        ]);
        echo '</div></template>';
        exit;
    }

    foreach (\Gallery\Services\gallery_photo_card_ratio_options() as $format) {
        \Gallery\Services\gallery_photo_card_ratio_save(42, $format['value']);
        display_editor_assert(
            \Gallery\Services\gallery_photo_card_ratio(42) === $format['value']
                && \Gallery\Services\gallery_photo_card_css_ratio(42) === $format['ratio']
                && \Gallery\Services\gallery_photo_card_ratio(43) === 'default',
            'Photo format did not round-trip or leaked into a different gallery.',
        );
    }
    foreach (['invalid', '16 / 9', '', null, ['4:5'], 1] as $invalid) {
        try {
            \Gallery\Services\gallery_photo_card_ratio_save(42, $invalid);
            throw new \RuntimeException('Invalid explicit format was accepted.');
        } catch (\InvalidArgumentException) {
            display_editor_assert(
                \Gallery\Services\gallery_photo_card_ratio(42) === '9:16',
                'Rejected format changed the previous setting.',
            );
        }
        display_editor_assert(
            \Gallery\Services\gallery_photo_card_ratio_normalize($invalid) === 'default',
            'Unsupported legacy/imported metadata lost the historic fallback.',
        );
    }

    $gridPosition = strpos($html, 'name="grid_override_enabled"');
    $formatPosition = strpos($html, 'name="gallery_photo_card_ratio"');
    $pictureGamePosition = strpos($html, 'name="picture_game_enabled"');
    display_editor_assert(
        $gridPosition !== false
            && $formatPosition !== false
            && $pictureGamePosition !== false
            && $gridPosition < $formatPosition
            && $formatPosition < $pictureGamePosition,
        'Display group order lost the grid, photo formats or visitor controls.',
    );
    display_editor_assert(
        !str_contains($html, '<details class="admin-display-advanced"')
            && substr_count($html, 'admin-display-group') === 6
            && substr_count($html, 'name="gallery_photo_card_ratio"') === 7,
        'Display must expose flowing groups and all supported native format choices.',
    );
    display_editor_assert(
        str_contains($html, 'data-default-columns="4" data-default-rows="5"')
            && str_contains(
                $html,
                'data-default-label="Default settings" data-custom-label="Custom settings"',
            )
            && str_contains($html, 'data-gallery-grid-reset hidden')
            && str_contains(
                $html,
                'data-gallery-grid-override-enabled checked',
            ),
        'Grid status labels, inherited reset values, and the native checkbox fallback must be prepared.',
    );
    foreach ([
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'voting_enabled',
        'show_filenames',
        'gps_map_enabled',
        'description_layout',
        'count_badge_visibility',
        'lightbox_browsing_mode',
        'gallery_thumbnail_min_size',
        'gallery_thumbnail_max_size',
        'gallery_thumbnail_bounds_recursive',
    ] as $name) {
        display_editor_assert(
            str_contains($html, 'name="' . $name . '"'),
            'Display field disappeared: ' . $name,
        );
    }
    display_editor_assert(
        !str_contains($html, 'flight_route_text')
            && str_contains(
                $html,
                'class="admin-inline-help-content">Hide GPS coordinates.</div>',
            ),
        'Rare tools or their on-demand explanations are missing, or the route remained in Display.',
    );

    ob_start();
    \Gallery\Views\view_render_admin_simbrief_description_tool(
        45,
        [],
        [
            'state' => 'ready',
            'title' => 'Flight route map',
            'label' => 'Route text',
            'route_text' => 'LKPR DCT EDDM',
            'status' => 'Resolved points: 2.',
            'help' => 'Use airport codes.',
        ],
    );
    $simbriefHtml = (string) ob_get_clean();
    $identityModel = [
        'active' => true,
        'gallery' => [
            'title' => 'Flight',
            'description' => '',
            'slug' => 'flight',
            'sort_order' => 0,
        ],
        'simbrief_html' => $simbriefHtml,
    ];
    ob_start();
    \Gallery\Views\view_render_admin_gallery_identity_tab($identityModel);
    $identityHtml = (string) ob_get_clean();
    display_editor_assert(
        substr_count($identityHtml, 'name="flight_route_text"') === 1
            && str_contains(
                $identityHtml,
                'class="admin-gallery-route-disclosure"',
            )
            && str_contains($identityHtml, '<svg aria-hidden="true"')
            && str_contains($identityHtml, 'aria-label="Flight route map"')
            && strpos($identityHtml, 'data-simbrief-generate') < strpos(
                $identityHtml,
                'admin-gallery-route-disclosure',
            )
            && !str_contains(
                $identityHtml,
                '<details class="admin-gallery-route-disclosure" open',
            ),
        'Identity must expose one manually editable route in its compact icon disclosure.',
    );
    ob_start();
    \Gallery\Views\view_render_admin_gallery_route_disclosure([
        'state' => 'hidden',
    ]);
    $hiddenRouteHtml = (string) ob_get_clean();
    display_editor_assert(
        $hiddenRouteHtml === '',
        'A hidden route capability must not display a route field or migration notice.',
    );
    ob_start();
    \Gallery\Views\view_render_admin_gallery_route_disclosure([
        'state' => 'migration',
        'migration_message' => 'Migration required.',
    ]);
    $migrationHtml = (string) ob_get_clean();
    display_editor_assert(
        str_contains($migrationHtml, 'Migration required.')
            && !str_contains($migrationHtml, 'flight_route_text'),
        'A missing route schema must keep the migration explanation without exposing a nonfunctional route field.',
    );
    echo "gallery display editor layout: PASS\n";
}
