/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_smart_galleries_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Run Smart Gallery controls in the isolated Chromium drawer harness.
 * Responsibilities: Verify collapsed payloads, rules, in-place mutations and narrow layouts.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_smart_galleries.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
