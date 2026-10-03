/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_tags_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify server-first gallery-tag rendering and stable card geometry in Chromium.
 * Responsibilities: Delegate isolated rendering and browser lifecycle to the confined fixture runner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4] = process.argv[3];
process.argv[3] = 'gallery_tags.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
