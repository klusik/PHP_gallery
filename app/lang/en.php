<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/lang/en.php
 * Module Type: Language File
 *
 * Purpose:
 *   Stores default English translation strings for the gallery application.
 *
 * Responsibilities:
 *   - Provide stable translation keys for user-facing text
 *   - Act as the default fallback dictionary for missing translations
 *   - Keep language maintenance simple with native PHP arrays
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-10
 */

return [
    'gallery.actions' => 'Gallery actions',
    'gallery.download' => 'Download gallery',
    'gallery.show_map' => 'Show gallery map',
    'gallery.play_picture_game' => 'Play picture game',
    'gallery.add_here' => 'Add gallery here',
    'gallery.add_inside' => 'Add gallery inside {title}',
    'gallery.edit_current' => 'Edit current gallery',
    'gallery.edit_named' => 'Edit gallery {title}',
    'gallery.workflow' => 'Gallery workflow',
    'gallery.editor' => 'Gallery editor',
    'gallery.edit' => 'Edit gallery',
    'admin.gallery_editor.gallery_date' => 'Date',
    'admin.gallery_editor.gallery_date_help' => 'Optional manual gallery date, for example an event, trip, or shooting date.',
    'admin.gallery_editor.invalid_gallery_date' => 'Enter a valid gallery date.',
    'admin.menu.edit_tags' => 'Edit tags',
    'admin.tags.title' => 'Edit tags',
    'admin.tags.saved' => 'Tag saved.',
    'admin.tags.deleted' => 'Tag deleted.',
    'admin.tags.error_delete_failed' => 'Tag could not be deleted.',
    'gallery.tag_editor' => 'Tag editor',
    'gallery.edit_tag' => 'Edit tag',
    'gallery.edit_tag_named' => 'Edit tag {name}',
    'gallery.remove_tag_named' => 'Remove tag {name} from CMS',    'js.admin.date_picker.open' => 'Open calendar',    'js.admin.date_picker.today' => 'Today',    'js.admin.date_picker.delete' => 'Delete',
    'admin.theme.media.theme_background_image_hint' => 'Upload the image you want to keep as the original. The gallery can serve a smaller WebP copy for visitors.',
    'admin.theme.media.background_optimized_size' => 'Optimized display size',
    'admin.theme.media.background_optimized_size_hint' => 'Use 1920px for normal screens, 2560px or more for very large displays.',
    'admin.theme.media.view_served_image' => 'View used image',
    'admin.theme.media.view_original_image' => 'View original',
    'admin.theme.media.current_theme_background_alt' => 'Selected background preview',
    'admin.theme.media.background_selected' => 'Background selected',
    'admin.theme.media.background_not_selected' => 'No background selected',
    'admin.theme.media.background_optimized_ready' => 'Optimized WebP is active',
    'admin.theme.media.background_serving_original' => 'Serving the original image',
    'admin.theme.media.background_optimized_size_value' => '{size}px longest side',
    'admin.theme.media.generate_optimized_background' => 'Generate optimized background',
    'admin.theme.media.regenerate_optimized_background' => 'Regenerate optimized background',
    'admin.theme.media.delete_optimized_background' => 'Delete optimized copy',
    'admin.theme.media.optimized_webp_active' => 'optimized WebP active',
    'admin.theme.media.optimized_webp_unavailable' => 'optimized WebP unavailable, serving original',
];
