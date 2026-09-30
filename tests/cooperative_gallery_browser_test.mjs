/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_gallery_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Run public cooperative gallery behavior in isolated Chromium.
 * Responsibilities: Reuse the confined browser harness without application data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'cooperative_gallery.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
