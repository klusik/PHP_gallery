<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_edit_writer_inventory.php
 * Module Type: Test Support
 * Purpose: Share the complete writer inventory between source and disposable MySQL checks.
 * Responsibilities:
 *   - Keep filesystem-writer coverage explicit and synchronized
 *   - Supply inert semantic arguments for early-refusal and wrapper-lifetime fixtures
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * List ordinary writer entry points that must acquire before any use-case reads.
 *
 * The image-move journal has an additional global-before-pair contract in the
 * source test. Its immutable journal lookup and read-only schema preflight are
 * intentionally not represented as ordinary delegation-only wrappers.
 *
 * @return array<string,list<string>> Module entry paths and guarded public functions.
 */
function gallery_edit_writer_fixture_inventory(): array
{
    return [
        'gallery_sidecars.php' => ['create_empty_gallery', 'create_gallery_row_for_folder', 'normalize_gallery_sidecar_tags_recursive'],
        'gallery_mutations.php' => ['delete_gallery_subtrees', 'delete_gallery_images', 'move_gallery_folder_to_parent', 'import_galleries', 'import_galleries_without_thumbnails'],
        'uploads.php' => ['store_uploaded_gallery_images', 'store_uploaded_gallery_cover', 'store_uploaded_gallery_branding_asset'],
        'browser_uploads.php' => ['browser_upload_store_prepared_zip_batch'],
        'mobile_webdav.php' => ['mobile_webdav_store_put'],
        'image_scanning.php' => ['scan_gallery_images', 'scan_gallery_selected_images', 'scan_gallery_selected_uploaded_images'],
        'gallery_trash.php' => ['move_gallery_subtrees_to_trash', 'restore_gallery_trash_entry', 'gallery_trash_reconcile_entry'],
        'picture_manager.php' => ['copy_gallery_images', 'picture_manager_copy_gallery_subtrees'],
        'media_renamer.php' => ['media_renamer_execute_gallery', 'media_renamer_execute_gallery_image_batch', 'media_renamer_execute_image_batch', 'media_renamer_execute_galleries', 'media_renamer_execute_plan'],
        'gallery_branding.php' => ['delete_gallery_branding_asset'],
        'gallery_migration.php' => ['gallery_migration_prepare_target_job', 'gallery_migration_install_package_file', 'gallery_migration_install_asset_file', 'gallery_migration_sync_received_assets', 'gallery_migration_job_status_response', 'gallery_migration_complete_job'],
        'gallery_editor_mutations.php' => ['gallery_editor_set_cover_image'],
        'gallery_dates.php' => ['gallery_date_save_range'],
        'thumbnail_bounds.php' => ['save_gallery_thumbnail_bounds'],
        'gallery_grid.php' => ['reset_all_gallery_grid_overrides'],
    ];
}

/**
 * Produce inert arguments without consulting application state or live configuration.
 *
 * Domain validation must never receive these arguments during an actual busy-lock
 * test. The source-lifetime test substitutes the delegated body with an immediate
 * result or exception, so it also cannot reach application storage.
 *
 * @param ReflectionFunction $function Actual writer or extracted wrapper closure.
 * @return list<mixed> Type-compatible fixture arguments.
 */
function gallery_edit_writer_fixture_arguments(ReflectionFunction $function): array
{
    $arguments = [];
    foreach ($function->getParameters() as $parameter) {
        $arguments[] = match ((string) $parameter->getType()) {
            'int', '?int' => 23,
            'string', '?string' => 'fixture-input',
            'array', '?array' => [],
            'bool', '?bool' => true,
            'mixed' => null,
            default => throw new RuntimeException('Uncovered gallery writer fixture parameter.'),
        };
    }
    return $arguments;
}
