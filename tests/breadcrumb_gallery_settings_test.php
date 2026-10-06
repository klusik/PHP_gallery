<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/breadcrumb_gallery_settings_test.php
 * Module Type: Regression Test
 * Purpose: Verify per-gallery breadcrumb style validation, persistence, and editor preservation contracts.
 * Responsibilities:
 *   - Exercise the canonical breadcrumb style save service without a database.
 *   - Protect omitted editor-field, controller view-model, sidecar, trash, and cleanup wiring.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Persist a test setting in the fixture map. @param string $key Setting key. @param string $value Setting value. @return void Store the setting. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['breadcrumb_gallery_settings'][$key] = $value;
    }

    /** Resolve a test setting from the fixture map. @param string $key Setting key. @param ?string $default Fallback value. @return ?string Stored or fallback value. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['breadcrumb_gallery_settings'][$key] ?? $default;
    }

    /** Return fallback translation copy for the style registry. @param string $key Translation key. @param string $fallback Fallback text. @return string Fixture translation. */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/breadcrumbs.php';
    require_once dirname(__DIR__) . '/app/services/gallery_editor_mutations.php';

    /** Assert one breadcrumb gallery contract. @param bool $condition Observed result. @param string $message Failure explanation. @return void Throw when false. */
    function breadcrumb_gallery_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /** Read one repository source file. @param string $path Repository-relative path. @return string File contents. */
    function breadcrumb_gallery_source(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/' . $path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Could not read ' . $path . '.');
        }
        return $contents;
    }

    $GLOBALS['breadcrumb_gallery_settings'] = [];
    breadcrumb_gallery_assert(
        \Gallery\Services\BREADCRUMB_STYLE_DEFAULT === 'chevron'
            && \Gallery\Services\BREADCRUMB_STYLE_INHERIT === 'inherit',
        'Breadcrumb default and inheritance constants must preserve their stable setting values.'
    );
    $breadcrumbStyleIds = array_keys(\Gallery\Services\breadcrumb_style_registry());
    breadcrumb_gallery_assert(
        $breadcrumbStyleIds === ['minimal', 'chevron', 'pills', 'surface', 'ribbon', 'nodes', 'tabs', 'tiles', 'gradient'],
        'The gallery-setting contract must include all nine registered style identifiers in stable order.'
    );
    foreach ($breadcrumbStyleIds as $styleId) {
        breadcrumb_gallery_assert(
            \Gallery\Services\gallery_breadcrumb_style_save(27, $styleId) === $styleId
                && ($GLOBALS['breadcrumb_gallery_settings']['gallery_breadcrumb_style.27'] ?? null) === $styleId,
            'A registered gallery style was not validated and persisted: ' . $styleId
        );
    }
    breadcrumb_gallery_assert(
        \Gallery\Services\gallery_breadcrumb_style_save(27, 'inherit') === 'inherit'
            && ($GLOBALS['breadcrumb_gallery_settings']['gallery_breadcrumb_style.27'] ?? null) === 'inherit',
        'The inherited Theme fallback was not persisted for the selected gallery.'
    );

    $invalidCases = [
        ['gallery_id' => 27, 'value' => 'invalid'],
        ['gallery_id' => 27, 'value' => 27],
        ['gallery_id' => 0, 'value' => 'chevron'],
    ];
    foreach ($invalidCases as $invalidCase) {
        try {
            \Gallery\Services\gallery_breadcrumb_style_save($invalidCase['gallery_id'], $invalidCase['value']);
            throw new \RuntimeException('Invalid breadcrumb style input was accepted.');
        } catch (\InvalidArgumentException) {
        }
    }
    breadcrumb_gallery_assert(
        ($GLOBALS['breadcrumb_gallery_settings']['gallery_breadcrumb_style.27'] ?? null) === 'inherit',
        'Rejected input changed the previously saved gallery style.'
    );

    $actions = breadcrumb_gallery_source('app/controllers/admin_galleries_edit_actions.php');
    breadcrumb_gallery_assert(
        str_contains($actions, "array_key_exists('gallery_breadcrumb_style', \$input)")
            && strpos($actions, "gallery_breadcrumb_style_validate(\$input['gallery_breadcrumb_style'])") < strpos($actions, 'gallery_edit_begin($gallery,')
            && str_contains($actions, 'if ($submittedBreadcrumbStyle !== null)')
            && str_contains($actions, 'gallery_breadcrumb_style_save($galleryId, $submittedBreadcrumbStyle);'),
        'The editor must validate only a submitted style and preserve saved preferences when the field is omitted.'
    );

    $displayController = breadcrumb_gallery_source('app/controllers/admin_galleries_edit_page/tab_display.php');
    $displayView = breadcrumb_gallery_source('app/views/admin_gallery_edit_tabs.php');
    breadcrumb_gallery_assert(
        str_contains($displayController, 'gallery_breadcrumb_style_override(')
            && str_contains($displayController, 'breadcrumb_style_picker_options(true, theme_breadcrumb_style())')
            && str_contains($displayController, "'field_name' => 'gallery_breadcrumb_style'")
            && str_contains($displayView, 'view_render_breadcrumb_style_picker($breadcrumbStyle)'),
        'The Display tab must prepare the shared registry-driven picker with a Theme-inheritance option.'
    );

    $sidecars = breadcrumb_gallery_source('app/services/gallery_sidecars.php');
    $trash = breadcrumb_gallery_source('app/services/gallery_trash.php');
    $model = breadcrumb_gallery_source('app/models/gallery_mutations.php');
    $mutations = breadcrumb_gallery_source('app/services/gallery_mutations.php');
    breadcrumb_gallery_assert(
        str_contains($sidecars, "\$data['breadcrumb_style'] = \$breadcrumbStyle")
            && str_contains($sidecars, "'breadcrumb_style' => breadcrumb_style_normalize(\$metadata['breadcrumb_style'] ?? BREADCRUMB_STYLE_INHERIT, true)")
            && str_contains($sidecars, 'gallery_breadcrumb_style_save($createdGalleryId, $candidate[\'breadcrumb_style\'])'),
        'Gallery sidecars must export and re-import explicit breadcrumb styles.'
    );
    breadcrumb_gallery_assert(
        str_contains($trash, "'breadcrumb_style' => gallery_breadcrumb_style_override")
            && str_contains($trash, "breadcrumb_style_normalize(\$record['breadcrumb_style'] ?? BREADCRUMB_STYLE_INHERIT, true)")
            && str_contains($model, "'gallery_breadcrumb_style.' . \$galleryId")
            && str_contains($model, 'app_settings_model_delete($breadcrumbSettingKeys)')
            && str_contains($mutations, 'app_settings_reset_request_cache();')
            && str_contains($trash, 'app_settings_reset_request_cache();'),
        'Trash restore must recover breadcrumb styles, subtree deletion must clean old keys, and committed deletes must invalidate cached settings.'
    );

    fwrite(STDOUT, "Per-gallery breadcrumb settings: PASS\n");
}
