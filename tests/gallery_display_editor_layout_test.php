<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_display_editor_layout_test.php
 * Module Type: Regression Test
 * Purpose: Verify the Display editor prioritizes its grid without dropping saved controls.
 * Responsibilities:
 *   - Keep the grid before optional feature controls.
 *   - Keep advanced settings collapsed while preserving their submitted fields.
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
}

namespace Gallery\Services {
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
    function view_render_admin_tab_panel(string $id, string $contentHtml, bool $active = false): void
    {
        echo '<section id="' . \Gallery\Core\e($id) . '"' . ($active ? ' class="is-active"' : '') . '>' . $contentHtml . '</section>';
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php';

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
        'active' => true,
        'advanced_label' => 'Advanced display settings',
        'grid' => [
            'ready' => true, 'title' => 'Display grid', 'override_enabled' => true,
            'override_label' => 'Custom grid', 'columns_label' => 'Columns', 'rows_label' => 'Rows',
            'columns' => 3, 'rows' => 2, 'max_columns' => 8, 'max_rows' => 8,
            'use_for_subgalleries' => true, 'recursive_label' => 'Use for subgalleries',
            'source_text' => 'Current source: gallery.', 'help' => 'Inherit from a parent if unset.',
        ],
        'voting' => ['visible' => true, 'checked' => false, 'label' => 'Voting', 'help' => 'Votes remain stored.'],
        'filenames' => ['ready' => true, 'checked' => true, 'label' => 'File names', 'help' => 'Raw names are optional.'],
        'picture_game' => ['visible' => true, 'checked' => false, 'label' => 'Picture game'],
        'gps' => ['state' => 'override', 'current_mode' => 'inherit', 'label' => 'EXIF / GPS', 'options' => $options, 'help' => 'Hide GPS coordinates.'],
        'description_layout' => ['ready' => true, 'current' => null, 'label' => 'Card layout', 'inherit_label' => 'Inherit', 'options' => $options, 'help' => 'Current effective layout.'],
        'count_badge' => ['ready' => true, 'current' => 'inherit', 'label' => 'Card badge', 'options' => $options, 'help' => 'Shows the picture count.'],
        'lightbox' => ['state' => 'ready', 'current' => 'inherit', 'label' => 'Lightbox', 'inherit_label' => 'Inherit', 'options' => $options, 'help' => 'Choose a browsing mode.'],
        'thumbnail_bounds' => [
            'ready' => true, 'title' => 'Responsive thumbnail quality bounds',
            'help' => 'Keep both sides on Auto.', 'control_html' => '<input name="gallery_thumbnail_min_size" value="0"><input name="gallery_thumbnail_max_size" value="1600">',
            'recursive_label' => 'Save to subgalleries', 'recursive_help' => 'Affects descendants.',
        ],
        'flight_map' => ['state' => 'ready', 'title' => 'Flight route map', 'label' => 'Route text', 'route_text' => 'LKPR DCT EDDM', 'status' => 'Resolved points: 2.', 'help' => 'Use airport codes.'],
    ];
    ob_start();
    \Gallery\Views\view_render_admin_gallery_display_tab($model);
    $html = (string) ob_get_clean();

    $gridPosition = strpos($html, 'name="grid_override_enabled"');
    $advancedPosition = strpos($html, '<details class="admin-display-advanced">');
    $pictureGamePosition = strpos($html, 'name="picture_game_enabled"');
    display_editor_assert($gridPosition !== false && $advancedPosition !== false && $pictureGamePosition !== false
        && $gridPosition < $advancedPosition && $advancedPosition < $pictureGamePosition,
        'Grid is not first or picture game escaped Advanced.');
    display_editor_assert(!str_contains($html, '<details class="admin-display-advanced" open'),
        'Advanced display settings must be closed initially.');
    foreach ([
        'grid_columns', 'grid_rows', 'grid_use_for_subgalleries', 'voting_enabled', 'show_filenames',
        'gps_map_enabled', 'description_layout', 'count_badge_visibility', 'lightbox_browsing_mode',
        'gallery_thumbnail_min_size', 'gallery_thumbnail_max_size', 'gallery_thumbnail_bounds_recursive',
        'flight_route_text',
    ] as $name) {
        display_editor_assert(str_contains($html, 'name="' . $name . '"'), 'Display field disappeared: ' . $name);
    }
    display_editor_assert(substr_count($html, 'class="admin-display-subsection"') === 2
        && str_contains($html, 'class="admin-inline-help-content">Hide GPS coordinates.</div>'),
        'Rare tools or their on-demand explanations are missing.');
    echo "gallery display editor layout: PASS\n";
}
