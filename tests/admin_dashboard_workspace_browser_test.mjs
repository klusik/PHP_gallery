/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_dashboard_workspace_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise deferred dashboard reads in disposable Chromium.
 * Responsibilities: Run a confined fixture without accessing installation data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_dashboard_workspace.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
