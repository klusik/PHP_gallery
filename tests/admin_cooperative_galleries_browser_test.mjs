/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_cooperative_galleries_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Run friendship management in the existing isolated Chromium harness.
 * Responsibilities: Exercise real delegated handlers and the shared drawer lifecycle.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_cooperative_galleries.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
