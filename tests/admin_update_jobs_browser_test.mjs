/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_update_jobs_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Run updater presentation in the isolated Chromium drawer harness.
 * Responsibilities: Verify completion refresh and dynamically inserted update controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_update_jobs.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
