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

## Commit & Pull Request Guidelines
Git history uses short, imperative messages, often with a feature prefix, for example `feat(admin): add media renamer workflow` or `Feature selector`. Keep commits focused and descriptive. Pull requests should explain the behavioral change, mention any schema or file-system impact, and include screenshots for UI changes when relevant. Note any setup steps needed to verify the change.

## Security & Configuration Tips
Do not commit `config.php`, local caches, uploads, or generated deploy archives. Use `config.example.php` as the baseline for new environments. Keep access checks in `app/services/gallery_access.php` and route-sensitive logic centralized in controllers and services rather than duplicated in views.

## Patch Notes Guidelines
Patch note generation rules are documented in `PATCH_NOTES_TEMPLATE.md`. When creating release notes, follow that template and the existing `PATCH_NOTES.md` style. Do not edit existing historical entries unless explicitly requested.
