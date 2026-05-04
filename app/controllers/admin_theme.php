<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Admin theme controller.
 *
 * Renders and processes the visual theme configuration page. The code remains
 * intentionally close to the original controller so existing POST field names,
 * uploads, reset actions, and redirects keep behaving exactly as before.
 */

/**
 * Send validators for a streamed file and stop on a matching browser cache entry.
 */



/**
 * Render the public scroll helper next to a listing without joining the listing grid.
 */


/**
 * Public homepage showing top-level public galleries.
 */


/**
 * Public gallery detail page with breadcrumbs, subgalleries, images, tags, and votes.
 */


/**
 * Render gallery ancestor links for public navigation.
 */


/**
 * Render the password prompt for a protected public gallery.
 */


/**
 * Process a public protected-gallery password unlock.
 */


/**
 * Resolve a share token and redirect to its protected gallery.
 */


/**
 * Build the canonical copyable share URL for one gallery/token pair.
 */


/**
 * Render one gallery card, including direct cover or child-cover collage.
 */


/**
 * Render logged-in admin metadata controls directly on public gallery pages.
 */


/**
 * Render logged-in admin metadata controls for a public image card.
 */


/**
 * Render the lightbox shell used by public gallery JavaScript.
 */


/**
 * Stream a generated thumbnail after the same visibility checks as originals.
 */



/**
 * Stream a generated thumbnail addressed through the clean public image URL.
 */


/**
 * Stream an original image addressed through the clean public image URL.
 */


/**
 * Stream an uploaded gallery thumbnail asset.
 */


/**
 * Stream a protected image file after checking gallery/image visibility.
 */


/**
 * Serve robots.txt for search engines.
 */


/**
 * Serve sitemap.xml for public gallery pages.
 */


/**
 * Render and process the admin login form.
 */


/**
 * Log the admin out of the current session.
 */


/**
 * Render and process visual theme settings.
 */
function cms_admin_theme(): void
{
    require_admin();
    if (request_method() === 'POST') {
        verify_csrf();
        if (!empty($_POST['reset_custom_css'])) {
            if (is_file(custom_css_path())) {
                unlink(custom_css_path());
            }
            set_app_setting('custom_css_preset', '');
        } elseif (!empty($_POST['reset_favicon'])) {
            remove_stored_favicon();
        } elseif (!empty($_POST['reset_theme_background'])) {
            // $path stores an intermediate value used by the surrounding gallery workflow.
            $path = theme_background_path();
            if ($path !== null) {
                // $absolute stores an intermediate value used by the surrounding gallery workflow.
                $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }
            set_app_setting('theme_background_path', '');
        } elseif (!empty($_POST['reset_all_gallery_backgrounds'])) {
            if (gallery_background_source_schema_ready()) {
                db()->exec("UPDATE galleries SET background_source = NULL, updated_at = " . db()->quote(now_sql()) . " WHERE background_source IS NOT NULL");
            }
        } elseif (!empty($_POST['reset_theme_overrides'])) {
            clear_theme_overrides();
        } elseif (!empty($_POST['reset_all_gallery_grid_overrides'])) {
            // $resetResult stores how many custom gallery-grid settings were cleared from each persistence layer.
            $resetResult = reset_all_gallery_grid_overrides();
            // The redirect flag keeps the operation idempotent and avoids resubmitting the destructive reset on refresh.
            redirect_to(url_for('admin_theme', [
                'grid_reset' => 1,
                'db_rows' => (int) $resetResult['database_rows'],
                'sidecars' => (int) $resetResult['sidecars'],
            ]));
        } else {
            // Variable $siteName stores this steps working value.
            $siteName = trim((string) ($_POST['site_name'] ?? ''));
            set_app_setting('site_name', $siteName !== '' ? substr($siteName, 0, 120) : 'Gallery CMS');
            // $themeControlsChanged stores an intermediate value used by the surrounding gallery workflow.
            $themeControlsChanged = (string) ($_POST['theme_controls_changed'] ?? '') === '1';
            // Variable $preset stores this steps working value.
            $preset = (string) ($_POST['custom_css_preset'] ?? '');
            // Variable $presetPath stores this steps working value.
            $presetPath = custom_css_preset_path($preset);
            // $customCssChanged stores an intermediate value used by the surrounding gallery workflow.
            $customCssChanged = false;
            if ($presetPath !== null) {
                copy($presetPath, custom_css_path());
                set_app_setting('custom_css_preset', $preset);
                // $customCssChanged stores an intermediate value used by the surrounding gallery workflow.
                $customCssChanged = true;
            }
            if (!empty($_FILES['custom_css']['tmp_name']) && is_uploaded_file($_FILES['custom_css']['tmp_name'])) {
                // Variable $name stores this steps working value.
                $name = strtolower((string) ($_FILES['custom_css']['name'] ?? ''));
                if (str_ends_with($name, '.css')) {
                    move_uploaded_file($_FILES['custom_css']['tmp_name'], custom_css_path());
                    set_app_setting('custom_css_preset', 'uploaded');
                    // $customCssChanged stores an intermediate value used by the surrounding gallery workflow.
                    $customCssChanged = true;
                }
            }
            if (!empty($_FILES['favicon_source']['tmp_name']) && is_uploaded_file($_FILES['favicon_source']['tmp_name'])) {
                // $name stores an intermediate value used by the surrounding gallery workflow.
                $name = strtolower((string) ($_FILES['favicon_source']['name'] ?? ''));
                if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                    // $info stores an intermediate value used by the surrounding gallery workflow.
                    $info = @getimagesize((string) $_FILES['favicon_source']['tmp_name']);
                    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                        throw new RuntimeException('The uploaded favicon source is not a valid image.');
                    }
                    store_uploaded_favicon($_FILES['favicon_source'], (string) ($_POST['favicon_cropped_png'] ?? '') ?: null);
                }
            }
            if (!empty($_FILES['theme_background']['tmp_name']) && is_uploaded_file($_FILES['theme_background']['tmp_name'])) {
                // Variable $name stores this steps working value.
                $name = strtolower((string) ($_FILES['theme_background']['name'] ?? ''));
                if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                    // $info stores an intermediate value used by the surrounding gallery workflow.
                    $info = @getimagesize((string) $_FILES['theme_background']['tmp_name']);
                    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                        throw new RuntimeException('The uploaded theme background is not a valid image.');
                    }
                    store_uploaded_theme_background($_FILES['theme_background']);
                }
            }
            set_app_setting('theme_background_opacity', (string) max(0, min(100, (int) ($_POST['theme_background_opacity'] ?? 65))));
            // $themeBackgroundSource stores an intermediate value used by the surrounding gallery workflow.
            $themeBackgroundSource = (string) ($_POST['theme_background_source'] ?? '');
            set_app_setting('theme_background_source', in_array($themeBackgroundSource, ['upload', 'existing', 'collage'], true) ? $themeBackgroundSource : '');
            // Pagination settings are saved independently from color/font overrides so enabling pagination does not force a CSS override state.
            set_app_setting('pagination_enabled', !empty($_POST['pagination_enabled']) ? '1' : '0');
            set_app_setting('pagination_columns', (string) pagination_dimension_value($_POST['pagination_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS));
            set_app_setting('pagination_rows', (string) pagination_dimension_value($_POST['pagination_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS));
            set_app_setting('home_gallery_grid_columns', (string) pagination_dimension_value($_POST['home_gallery_grid_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS));
            set_app_setting('home_gallery_grid_rows', (string) pagination_dimension_value($_POST['home_gallery_grid_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS));
            if ($themeControlsChanged) {
                set_app_setting('theme_accent', sanitize_hex_color((string) $_POST['theme_accent'], '#a5481c'));
                set_app_setting('theme_accent_dark', sanitize_hex_color((string) $_POST['theme_accent_dark'], '#713414'));
                set_app_setting('theme_paper', sanitize_hex_color((string) $_POST['theme_paper'], '#f8f4ec'));
                set_app_setting('theme_panel', sanitize_hex_color((string) $_POST['theme_panel'], '#fffaf0'));
                set_app_setting('theme_gallery_panel', sanitize_hex_color((string) $_POST['theme_gallery_panel'], '#fffaf0'));
                set_app_setting('theme_header_text', sanitize_hex_color((string) $_POST['theme_header_text'], '#0f172a'));
                set_app_setting('theme_hero_text', sanitize_hex_color((string) $_POST['theme_hero_text'], '#0f172a'));
                set_app_setting('theme_radius', (string) max(0, min(32, (int) $_POST['theme_radius'])));
                set_app_setting('theme_font', in_array($_POST['theme_font'] ?? '', ['serif', 'sans'], true) ? (string) $_POST['theme_font'] : 'serif');
            } elseif ($customCssChanged) {
                clear_theme_overrides();
            }
        }
        redirect_to(url_for('admin_theme', ['saved' => 1]));
    }
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = pagination_global_settings();
    // Variable $homeGridSettings stores the separate public home-page gallery grid.
    $homeGridSettings = main_page_gallery_grid_settings();
    render_header('Theme');
    if (!empty($_GET['grid_reset'])) {
        // $databaseRows stores how many database gallery rows reported a custom-grid reset.
        $databaseRows = max(0, (int) ($_GET['db_rows'] ?? 0));
        // $sidecars stores how many gallery.json files had stale custom-grid metadata removed.
        $sidecars = max(0, (int) ($_GET['sidecars'] ?? 0));
        echo '<section class="panel notice"><p>Custom gallery grid settings were reset. Database rows changed: ' . $databaseRows . '. Sidecar files cleaned: ' . $sidecars . '.</p></section>';
    }
    // $themeBackgroundUrl stores the current global background asset so the live preview can mirror the public page before saving.
    $themeBackgroundUrl = theme_background_asset_url();
    echo '<section class="panel" id="admin-theme"><h1>Appearance</h1><form method="post" enctype="multipart/form-data" class="form-grid" data-theme-form>' . csrf_field();
    echo '<input type="hidden" name="theme_controls_changed" value="0" data-theme-controls-changed>';
    echo '<fieldset class="theme-appearance-editor" data-theme-preview-root data-theme-preview-background-url="' . e($themeBackgroundUrl) . '">';
    echo '<legend>Visual appearance</legend>';
    echo '<div class="theme-appearance-controls">';
    echo '<label>Site name<input name="site_name" value="' . e(site_name()) . '" maxlength="120" required data-theme-preview-site-name></label>';
    echo '<label class="theme-color-control">Accent color<input type="color" name="theme_accent" value="' . e((string) $theme['accent']) . '" data-theme-override-control data-theme-preview-color="accent"><span class="muted">Buttons, selected pagination, and important links.</span></label>';
    echo '<label class="theme-color-control">Dark accent<input type="color" name="theme_accent_dark" value="' . e((string) $theme['accent_dark']) . '" data-theme-override-control data-theme-preview-color="accent_dark"><span class="muted">Hover states, outlines, and secondary actions.</span></label>';
    echo '<label class="theme-color-control">Page background<input type="color" name="theme_paper" value="' . e((string) $theme['paper']) . '" data-theme-override-control data-theme-preview-color="paper"><span class="muted">The base page tone behind all content.</span></label>';
    echo '<label class="theme-color-control">Panel background<input type="color" name="theme_panel" value="' . e((string) $theme['panel']) . '" data-theme-override-control data-theme-preview-color="panel"><span class="muted">Cards, panels, and normal gallery tiles.</span></label>';
    echo '<label class="theme-color-control">Open gallery panel<input type="color" name="theme_gallery_panel" value="' . e((string) $theme['gallery_panel']) . '" data-theme-override-control data-theme-preview-color="gallery_panel"><span class="muted">Gallery-specific cards and image panels.</span></label>';
    echo '<label class="theme-color-control">Header title color<input type="color" name="theme_header_text" value="' . e((string) $theme['header_text']) . '" data-theme-override-control data-theme-preview-color="header_text"><span class="muted">Main site title in the public header.</span></label>';
    echo '<label class="theme-color-control">Gallery title color<input type="color" name="theme_hero_text" value="' . e((string) $theme['hero_text']) . '" data-theme-override-control data-theme-preview-color="hero_text"><span class="muted">Open gallery title and hero text.</span></label>';
    echo '<label>Rounded corners <span class="muted" data-theme-radius-display>' . (int) $theme['radius'] . 'px</span><input type="range" name="theme_radius" min="0" max="32" value="' . (int) $theme['radius'] . '" data-theme-override-control data-theme-preview-radius></label>';
    echo '<label>Font style<select name="theme_font" data-theme-override-control data-theme-preview-font><option value="serif"' . ($theme['font'] === 'serif' ? ' selected' : '') . '>Classic serif</option><option value="sans"' . ($theme['font'] === 'sans' ? ' selected' : '') . '>Clean sans-serif</option></select></label>';
    echo '</div>';
    echo '<aside class="theme-live-preview" aria-label="Live theme preview" data-theme-live-preview>';
    echo '<div class="theme-preview-page" data-theme-preview-page>';
    echo '<div class="theme-preview-background"><span data-theme-preview-background-image></span></div>';
    echo '<header class="theme-preview-header"><strong data-theme-preview-brand>' . e(site_name()) . '</strong><nav><span class="theme-preview-link">Home</span><span class="theme-preview-link">Galleries</span></nav></header>';
    echo '<section class="theme-preview-hero"><p>Open gallery</p><h2 data-theme-preview-hero-title>Aircraft Weekend</h2><span class="theme-preview-tag">travel</span></section>';
    echo '<div class="theme-preview-grid"><article class="theme-preview-card"><div></div><h3>Subgallery card</h3><p>Panel background</p></article><article class="theme-preview-card theme-preview-gallery-card"><div></div><h3>Photo card</h3><p>Open gallery panel</p></article></div>';
    echo '<div class="theme-preview-pagination"><span>1</span><span>2</span><span>3</span></div>';
    echo '</div>';
    echo '<p class="muted">Preview updates while editing. It is intentionally small, but uses the same colors, font mode, corner radius, and background transparency controls as the public theme.</p>';
    echo '</aside>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-favicon"><legend>Favicon</legend>';
    // $faviconUrl stores an intermediate value used by the surrounding gallery workflow.
    $faviconUrl = favicon_asset_url();
    if ($faviconUrl !== '') {
        // $faviconVersion stores an intermediate value used by the surrounding gallery workflow.
        $faviconVersion = (string) app_setting('favicon_version', '1');
        echo '<div class="favicon-current"><img src="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '" alt="Current favicon"><p class="muted">Current favicon is generated as 32px, 48px, and 180px PNG variants.</p></div>';
    } else {
        echo '<p class="muted">No favicon is stored yet. Browsers will use their default icon until one is saved.</p>';
    }
    echo '<label>Favicon source image<input type="file" name="favicon_source" accept="image/png,image/jpeg,image/gif,image/webp,image/*" data-favicon-input><span class="muted">Upload a square-friendly photo or logo. The cropper saves a browser-ready square PNG favicon.</span></label>';
    echo '<input type="hidden" name="favicon_cropped_png" value="" data-favicon-cropped>';
    echo '<div class="favicon-cropper" data-favicon-cropper hidden><div class="favicon-crop-stage"><canvas width="256" height="256" data-favicon-canvas></canvas></div><label>Zoom<input type="range" min="1" max="3" step="0.01" value="1" data-favicon-zoom></label><div class="favicon-preview-row"><canvas width="48" height="48" data-favicon-preview></canvas><span class="muted">Drag the image to place the square crop. The small preview shows the browser icon scale.</span></div></div>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-backgrounds"><legend>Background</legend>';
    echo '<label>Theme background image<input type="file" name="theme_background" accept="image/*"></label>';
    if ($themeBackgroundUrl !== '') {
        echo '<p class="muted">Current theme background: <a href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener">view stored image</a></p>';
    } else {
        echo '<p class="muted">No global theme background image is stored yet.</p>';
    }
    echo '<label>Background transparency <span data-theme-background-opacity-display>' . (int) ($theme['background_opacity'] ?? 65) . '%</span><input type="range" name="theme_background_opacity" min="0" max="100" value="' . (int) ($theme['background_opacity'] ?? 65) . '" data-theme-override-control data-theme-background-opacity><span class="muted">Higher means more visible image, lower means more of the color underneath.</span></label>';
    echo '<label>Gallery background fallback<select name="theme_background_source" data-theme-override-control><option value=""' . (theme_background_source() === null ? ' selected' : '') . '>No fallback set</option><option value="upload"' . (theme_background_source() === 'upload' ? ' selected' : '') . '>Upload new image</option><option value="existing"' . (theme_background_source() === 'existing' ? ' selected' : '') . '>Pick from existing gallery images</option><option value="collage"' . (theme_background_source() === 'collage' ? ' selected' : '') . '>Generate collage from public galleries</option></select><span class="muted">Used when a gallery does not set its own background source.</span></label>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_backgrounds" value="1" formnovalidate>Reset all gallery backgrounds</button></div>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-pagination"><legend>Pagination</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="pagination_enabled" value="1"' . (!empty($paginationSettings['enabled']) ? ' checked' : '') . '> Enable pagination</label>';
    echo '<label>Columns per page <span class="muted" data-pagination-columns-display>' . (int) $paginationSettings['columns'] . '</span><input type="range" name="pagination_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $paginationSettings['columns'] . '" data-pagination-columns></label>';
    echo '<label>Rows per page <span class="muted" data-pagination-rows-display>' . (int) $paginationSettings['rows'] . '</span><input type="range" name="pagination_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $paginationSettings['rows'] . '" data-pagination-rows></label>';
    echo '<p class="muted">Items per page preview: <span data-pagination-items-preview>' . (int) $paginationSettings['items_per_page'] . '</span></p>';
    echo '<p class="muted">These values remain the fallback for galleries that do not define or inherit a custom grid.</p>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-home-grid"><legend>Main page gallery grid</legend>';
    echo '<label>Main page columns <span class="muted" data-home-grid-columns-display>' . (int) $homeGridSettings['columns'] . '</span><input type="range" name="home_gallery_grid_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $homeGridSettings['columns'] . '" data-home-grid-columns></label>';
    echo '<label>Main page rows <span class="muted" data-home-grid-rows-display>' . (int) $homeGridSettings['rows'] . '</span><input type="range" name="home_gallery_grid_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $homeGridSettings['rows'] . '" data-home-grid-rows></label>';
    echo '<p class="muted">This affects only the front page where top-level galleries are listed. It can use a different grid than gallery pages and inherited subgallery pages.</p>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_grid_overrides" value="1" formnovalidate onclick="return confirm(&quot;Reset all custom per-gallery grid settings? The global Theme grid and main page grid will stay unchanged.&quot;);">Reset all custom gallery grids</button></div>';
    echo '<p class="muted">This clears every per-gallery custom grid and resets subgallery inheritance flags to default. It also removes matching grid keys from gallery.json files, so future scans cannot re-import stale custom grid settings.</p>';
    echo '</fieldset>';
    // Variable $selectedPreset stores this steps working value.
    $selectedPreset = (string) app_setting('custom_css_preset', '');
    echo '<div id="admin-custom-css"></div><label>Custom CSS skin<select name="custom_css_preset"><option value="">Keep current custom CSS</option>';
    foreach (custom_css_presets() as $filename => $path) {
        // Variable $label stores this steps working value.
        $label = ucwords(str_replace(['-', '_'], ' ', pathinfo((string) $filename, PATHINFO_FILENAME)));
        echo '<option value="' . e((string) $filename) . '"' . ($selectedPreset === $filename ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select><span class="muted">Selecting a skin copies it from <code>custom_css/</code> into the active custom stylesheet.</span></label>';
    echo '<label>Custom CSS file<input type="file" name="custom_css" accept=".css,text/css"></label>';
    echo '<p class="muted">Uploaded CSS is saved as <code>public/assets/custom.css</code> and loaded after the built-in stylesheet and theme controls.</p>';
    echo '<div class="bulk-row"><button type="submit">Save theme</button><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>Reset to CSS</button><button type="submit" class="secondary" name="reset_custom_css" value="1" formnovalidate>Reset custom CSS</button><button type="submit" class="secondary" name="reset_theme_background" value="1" formnovalidate>Remove theme background</button><button type="submit" class="secondary" name="reset_favicon" value="1" formnovalidate>Remove favicon</button></div></form></section>';
    render_footer();
}
