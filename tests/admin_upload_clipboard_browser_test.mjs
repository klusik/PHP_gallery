/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_upload_clipboard_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise clipboard selection and panel submission in disposable Chromium.
 * Responsibilities: Reuse the confined runner without OS clipboard or installation access.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_upload_clipboard.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
