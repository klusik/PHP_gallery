/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_upload_workspace_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise unified upload Settings in disposable Chromium.
 * Responsibilities: Run confined synthetic mobile and navigation mutations without installation data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_upload_workspace.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
