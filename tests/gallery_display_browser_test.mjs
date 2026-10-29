/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_display_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify photo-card formats and the compact Display editor in Chromium.
 * Responsibilities: Exercise production views, styles and isolated autosave.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'gallery_display.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
