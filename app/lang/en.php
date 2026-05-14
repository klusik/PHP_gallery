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
    'admin.theme.layout.description_layout_horizontal_summary' => 'Image first, then a compact story card below it.',
    'admin.theme.layout.description_layout_vertical_summary' => 'Image and text side by side, close to the classic gallery look.',
    'admin.theme.layout.description_preview_title' => 'Summer gallery',
    'admin.theme.layout.description_preview_meta' => '12 photos',
    'admin.dashboard.notice_url_rewrite_saved' => 'URL rewrite setting saved.',
    'admin.dashboard.url_rewrite_warning_unknown_reason' => 'Rewrite support was not detected.',
    'admin.dashboard.url_rewrite_warning_title' => 'URL rewrite is enabled, but support was not detected.',
    'admin.dashboard.url_rewrite_warning_hint' => 'Public links will fall back to index.php URLs where possible. Check .htaccess, mod_rewrite, or disable URL rewrite below if this hosting does not support it.',
    'admin.dashboard.url_rewrite_status_disabled' => 'Disabled intentionally',
    'admin.dashboard.url_rewrite_status_supported' => 'Supported',
    'admin.dashboard.url_rewrite_status_likely_supported' => 'Likely supported',
    'admin.dashboard.url_rewrite_status_unsupported' => 'Not detected',
    'admin.dashboard.url_rewrite_status_unknown' => 'Unknown',
    'admin.dashboard.url_rewrite_reason_unknown' => 'No detailed compatibility signal is available for this request.',
    'admin.dashboard.url_rewrite_title' => 'URL rewrite',
    'admin.dashboard.url_rewrite_hint' => 'Clean public URLs are enabled by default. Disable them only when your hosting cannot route rewritten paths.',
    'admin.dashboard.url_rewrite_enable_clean_urls' => 'Use clean rewritten public URLs',
    'admin.dashboard.url_rewrite_detected_status' => 'Detected status:',
    'admin.dashboard.url_rewrite_save' => 'Save URL rewrite',
    'gallery.card.unpublished_admin_hint' => 'Only logged-in admins can see this gallery in listings.',
    'admin.updates.force_check_button' => 'Force check',
    'admin.updates.force_check_completed' => 'Forced GitHub update check completed.',
    'admin.updates.force_check_completed_with_error' => 'Forced GitHub update check completed with a warning: {error}',
    'admin.updates.force_check_hint' => 'Bypass the local five-hour cache and ask GitHub now. GitHub rate-limit headers are still recorded and respected after the response.',
    'admin.updates.github_api_hint' => 'The updater uses response headers from normal GitHub API calls. It does not call /rate_limit just to inspect limits.',
    'admin.updates.github_api_kicker' => 'GitHub API policy',
    'admin.updates.github_api_last_checked' => 'Last GitHub API response',
    'admin.updates.github_api_next_allowed' => 'Next allowed check: {time}',
    'admin.updates.github_api_remaining' => 'Remaining quota',
    'admin.updates.github_api_reset' => 'Primary reset',
    'admin.updates.github_api_resource' => 'Resource',
    'admin.updates.github_api_title' => 'Rate-limit status',
    'admin.updates.github_api_used' => 'Used quota',
    'admin.updates.github_api_waiting' => 'Waiting',
    'admin.updates.autoupdate_next_check_label' => 'Next automatic check',
    'admin.updates.github_api_http_status' => 'Last HTTP status',
    'admin.updates.github_api_last_url' => 'Last endpoint',
    'admin.updates.github_api_secondary_backoff' => 'Secondary-limit backoff',
    'admin.updates.github_api_secondary_backoff_value' => '{seconds} second(s)',
    'admin.updates.github_cache_value' => '{time}, expires {expires}',
    'admin.updates.github_metadata_cache' => 'Update metadata cache',
    'admin.updates.github_passive_page_hint' => 'Reloading this page uses local cache only. It does not contact GitHub unless you use Force check or an automatic check is due.',
    'admin.updates.github_patch_notes_cache' => 'Patch notes cache',
];
