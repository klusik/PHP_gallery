/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_trash_confirmation_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify native cancellation before delegated Trash AJAX mutations.
 * Responsibilities: Exercise production handlers against synthetic browser responses only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_trash_confirmation.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
