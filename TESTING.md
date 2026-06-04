# Testing Guide

## Purpose
This project is a plain PHP gallery CMS without a formal browser automation stack. The most reliable testing approach is a mix of fast syntax checks, focused script-level checks, and a repeatable manual smoke-test scenario that exercises the core gallery lifecycle.

## Test Layers

### 1. Syntax Checks
Use these first when touching PHP or JavaScript:

```bash
php -l path/to/file.php
```

For JavaScript, use whatever local parser or linter is available in your environment. Syntax checks catch obvious breakage, but they do not prove the app still behaves correctly.

### 2. Script-Level Tests
The repository already uses direct PHP test scripts under `tests/`. Run them with plain PHP:

```bash
php tests/gallery_visibility_model_test.php
php tests/gallery_branding_model_test.php
```

These are best for pure logic, helper functions, and regression checks that do not require a browser session.

### 3. Manual Functional Smoke Tests
For feature work, use the same end-to-end scenario every time. Keep one dedicated test installation or local database so you can create and remove test content freely.

Recommended flow:

1. Log in as admin.
2. Open the dashboard and confirm it renders without errors.
3. Create a new gallery.
4. Edit gallery title, description, visibility, tags, and ordering settings.
5. Upload 2 to 3 images.
6. Open the gallery on the public site.
7. Open an image in the lightbox.
8. Reorder images if the change touches ordering.
9. Rename or move the gallery if the change touches file or path logic.
10. Delete the test gallery and confirm cleanup succeeds.

## What To Retest After A Change

### Low-Risk Changes
For CSS, text, layout polish, or small UI tweaks, test the affected page and one nearby page.

### Medium-Risk Changes
For controllers, forms, admin tools, or display logic, retest the touched feature plus the main gallery browse flow.

### High-Risk Changes
For routing, permissions, uploads, deletes, renames, migrations, public media serving, or feature flags, retest:

- admin login
- gallery create/edit/delete
- photo upload and public rendering
- lightbox and navigation
- any route or tool the change can disable
- schema migration or install flow, if database code changed

## Practical Rule
Ask these questions before and after the change:

- Can an admin still manage galleries?
- Can visitors still browse public galleries?
- Can media still upload, render, and delete?
- Did I touch a route, permission check, or database schema?

If the answer is yes to any of those, run the manual smoke test in addition to syntax checks.

## Recommended Habit
Keep a short test note for each significant change:

- what changed
- what you tested
- what you did not test
- any warning signs or follow-up work

That makes regressions easier to track and helps future changes focus on the highest-risk paths first.
