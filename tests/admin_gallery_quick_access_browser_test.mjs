/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_quick_access_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify delegated quick-access controls in a disposable synthetic browser fixture.
 * Responsibilities:
 *   - Run embedded gallery access controls against synthetic loopback endpoints only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_gallery_quick_access.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
