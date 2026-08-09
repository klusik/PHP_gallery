# Repository Guidelines

## Project Structure & Module Organization
This repository is a plain PHP 8.0+ gallery CMS with no Composer or Node build. Core application code lives in `app/`, split into `controllers/`, `services/`, and `views/`. Database schema changes live in `database/migrations/` and must be added as new timestamped files. Public web assets are under `public/assets/`; writable runtime data is kept in `cache/`, `galleries/`, and `data/`. Standalone test scripts live in `tests/`. Deployment helpers and CLI utilities are in `scripts/`, with Windows-specific tooling in `winapp/`.

## Build, Test, and Development Commands
- `php -S localhost:8000 -t public public/index.php` - run the app locally with the `public/` directory as the web root.
- `php -S localhost:8000 index.php` - alternate local run mode using the repository root router.
- `php scripts/migrate.php` - apply pending database migrations.
- `php scripts/create_admin.php <username> <password>` - create the first admin account during setup.
- `php tests/gallery_visibility_model_test.php` - run a direct PHP test script.
- `php tests/gallery_branding_model_test.php` - run another standalone test script.

## Coding Style & Naming Conventions
Use `declare(strict_types=1);` in new PHP files and follow the existing 4-space indentation style. Keep functions small and explicit; prefer service helpers over duplicated controller logic. Name controller files by feature area, for example `admin_galleries_edit.php` or `public_media.php`. Migration files must be timestamp-prefixed, such as `202606040001_mobile_webdav_upload_tokens.php`. JavaScript and CSS assets should stay framework-free and be placed in `public/assets/`.

## Testing Guidelines
Tests are plain PHP scripts rather than PHPUnit cases. Keep new tests executable from the command line with `php tests/<name>_test.php`. Favor focused tests that validate a single behavior without requiring a browser or live database unless the feature truly depends on one. When changing schema logic, add or update a migration and include a test where practical.

## Admin Side-Panel Interaction Priority
Treat the existing Admin right-side panel as the primary interaction surface for every action launched from that panel. When JavaScript is enabled, panel forms and buttons must complete in place through the existing side-panel/AJAX workflow: keep the panel open, do not navigate to a standalone Admin route, do not change `window.location`, and do not reload the page. A normal POST/redirect route may remain only as a non-JavaScript or direct-page fallback; it must not be the normal behavior of an action initiated inside the panel.

Because side-panel content is injected dynamically, bind handlers in a way that also covers newly rendered panel fragments and prevent generic form handlers from taking over panel-owned actions. When a JavaScript module that owns a panel action changes, update its cache-busting import so deployed browsers cannot continue running an older handler. Add focused regression checks for panel persistence and in-place refresh whenever a side-panel action is added or changed.

Persistent mutations launched from the right-side panel, including review/ignore ledgers and reset actions, must use the JSON/AJAX path as the primary browser pipeline and replace only the owned panel fragment or affected page elements. Treat the POST/redirect implementation as fallback compatibility. For every new panel button, test that the browser URL is unchanged, the panel remains open, and a dynamically re-rendered copy of the same control is still intercepted without rebinding the whole page.

Do not invent browser confirmation dialogs or extra intermediate navigation for an explicitly requested one-click/in-place panel action unless the feature requirements specifically call for confirmation. Destructive operations must still use the existing authentication, CSRF, authorization, path-safety, and mutation services.

## Public Thumbnail Renderer Guidelines
Both public thumbnail renderers are permanent supported pipelines. `responsive` is the safe default and must keep complete server-rendered responsive srcsets plus no-JavaScript behavior. `progressive` is a permanent machine/architecture name even while the Admin control displays a Beta maturity label. Do not introduce temporary, experimental, numbered, or replacement-style renderer identifiers.

Changes to shared thumbnail bundle, bounds, HTML, manifest, warm-up, public-card, or browser lifecycle code must test both renderers. No renderer may weaken gallery/password/NSFW access checks, media authorization, semantic server-rendered image markup, useful alt text, or no-JavaScript navigation. Progressive larger candidates must remain inert until the bounded near-viewport scheduler activates them.

The selected-gallery mode is stored as `public_thumbnail_rendering_mode` with only `responsive` and `progressive` values. Invalid values must fall back to responsive. The Admin-facing Beta wording is UI status only and must not leak into setting keys, PHP/JavaScript identifiers, CSS classes, filenames, data attributes, test names, or architecture terms.

`PATCH_NOTES.md` is intentionally not changed as part of renderer implementation work unless a separate task explicitly requests release notes.

## Commit & Pull Request Guidelines
Git history uses short, imperative messages, often with a feature prefix, for example `feat(admin): add media renamer workflow` or `Feature selector`. Keep commits focused and descriptive. Pull requests should explain the behavioral change, mention any schema or file-system impact, and include screenshots for UI changes when relevant. Note any setup steps needed to verify the change.

## Security & Configuration Tips
Do not commit `config.php`, local caches, uploads, or generated deploy archives. Use `config.example.php` as the baseline for new environments. Keep access checks in `app/services/gallery_access.php` and route-sensitive logic centralized in controllers and services rather than duplicated in views.

## Patch Notes Guidelines
Patch note generation rules are documented in `PATCH_NOTES_TEMPLATE.md`. When creating release notes, follow that template and the existing `PATCH_NOTES.md` style. Do not edit existing historical entries unless explicitly requested.
