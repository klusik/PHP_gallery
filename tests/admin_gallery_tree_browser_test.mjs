/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_tree_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise production gallery hierarchy interactions in disposable Chromium.
 * Responsibilities: Verify real rendered controls and complete synthetic reorder payloads.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
// Preserve the central runner's explicit PHP binary before selecting the fixture.
process.argv[4] = process.argv[3];
process.argv[3] = 'admin_gallery_tree.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
