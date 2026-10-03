/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_creation_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Preserve actual compact full-page creation and dynamic name-only panel completion.
 * Responsibilities: Delegate confined production rendering with captured requests and no live writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4]=process.argv[3];
process.argv[3]='gallery_creation.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
