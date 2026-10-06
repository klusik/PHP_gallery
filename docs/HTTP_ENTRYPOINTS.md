# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
#
# File: docs/HTTP_ENTRYPOINTS.md
# Module Type: Documentation
#
# Purpose:
#   Records supported browser entrypoints and rewrite-independent private trees.
#
# Responsibilities:
#   - Identify public front controllers and setup endpoints
#   - Inventory Apache-protected internal repository paths
#   - Explain hosting and query-string routing boundaries
#
# Author:
#   Rudolf Klusal
#
# Contact:
#   https://github.com/klusik
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Last Updated:
#   2026-10-05

# HTTP entrypoints and internal paths

This inventory describes the supported browser entrypoints and the repository paths
that must remain private when the checkout is served from Apache. It complements
the route and security tables in `app/bootstrap/dispatch.php` and
`app/bootstrap/routing.php`.

## Browser entrypoints

| Path | Purpose | HTTP policy |
| --- | --- | --- |
| `public/index.php` | Canonical public and Admin front controller; dispatches clean paths and query-string routes. | Public. Query-string requests such as `/index.php?page=gallery&slug=example-gallery` remain supported without URL rewriting. |
| `index.php` | Root compatibility entrypoint that delegates to `public/index.php`. | Public. |
| `install.php` | Browser installer used before the first Admin account and application setup are complete. | Public setup flow with its own setup checks and protections. |
| `setup-gallery.php` | Standalone first-install bootstrap for uploading the initial application. | Public one-time setup flow; it refuses an already-installed site. Remove it after setup as an additional hardening step. |
| `reset.php` | Emergency recovery endpoint for returning to the stable update branch. | Public recovery flow with the checks implemented by the endpoint. |

Application feature routes are not separate PHP entrypoints. The public front
controller resolves them through the route registry and dispatcher, which own
route normalization, authorization, capability and schema preflight, and controller
selection. Direct media access is subject to the same access policy as the route
that serves it; stored gallery media is not intended to be served as an unchecked
static tree.

## Internal repository trees

The following top-level trees are implementation, maintenance, generated data, or
development material. Apache denies requests within each tree with a directory-local
`.htaccess` authorization rule. The denial does not rely on `mod_rewrite`, so it
continues to apply when clean URL routing is disabled.

| Tree | Contents and entrypoint classification | Public access |
| --- | --- | --- |
| `app/` | Runtime modules, controllers, services, views, bootstrap and compatibility modules. These are loaded by the front controller or CLI tools, not standalone browser entrypoints. | Denied. |
| `database/` | Timestamped schema migrations and database support files. Migration PHP files are included by the migration runner. | Denied. |
| `scripts/` | CLI maintenance/update/migration tools, deploy/release/audit commands, and their PHP support libraries. A helper included by a CLI command is not itself a public endpoint. | Denied for every file, regardless of whether it is a CLI entrypoint, library, or development tool. |
| `cache/` | Disposable caches, locks, generated update state, and cached artifacts. | Denied. |
| `data/` | Durable runtime metadata, recovery/trash payloads, and operational archives. Only explicitly inventoried directory protection files ship; runtime data remains installation-owned. | Denied. |
| `logs/`, `tmp/` | Reserved runtime log and temporary-file trees. Guard files ship even when these directories do not exist in the source checkout, so a later-created directory inherits protection. | Denied. |
| `tests/` | Regression tests, fixtures, and support scripts. These are developer tooling, not production HTTP endpoints. | Denied. |
| `deploy/` | Local deployment staging and package output. | Denied. |
| `docs/` | Project, operator, and architecture documentation. | Denied. |
| `.github/`, `.agents/` | Repository workflow, community, and agent metadata. | Denied. |
| `winapp/` | Windows uploader, installer, and build source. | Denied. |

When the repository root is the document root, the project root also denies direct requests for `config.php` and
`config.example.php`, source-control metadata, hidden files other than the
explicit `.well-known/` exception, and common backup, archive, environment, and
configuration-file extensions. Root and public rewrite rules retain their existing
path-denial rules as additional defense in depth.

## Public static content and URL behavior

`public/assets/` contains browser scripts, stylesheets, icons, and images that are
intentionally served directly. `galleries/` stores uploaded originals and generated
derivatives, but its policy denies direct media-file reads so visibility, password,
share-link, and NSFW checks remain centralized in application routes. `custom_css/`
is a public presentation asset location when configured by the application.

The rewrite-independent directory rules are anchored to the protected top-level
trees. A later public route component with one of those words, such as
`/gallery/travel/tests/`, remains eligible for normal route dispatch. Disabling URL
rewrites continues to support direct query-string requests through `index.php`.

The production/operator CLI commands include `scripts/application_update.php`,
`scripts/create_admin.php`, `scripts/generate_manifest.php`,
`scripts/migrate.php`, `scripts/site_maintenance.php`, and
`scripts/telemetry_maintenance.php`. Developer audit, release, and reconciliation
commands are also CLI-only. Shared PHP support modules under `scripts/` may be
included by other commands; a module with an optional direct-command mode gates
that mode only when the canonical `realpath()` of `SCRIPT_FILENAME` identifies
the module itself. For example, `scripts/runtime_dependencies.php` and
`scripts/release_qualification/cli.php` can be included by their owning tools and
also expose a guarded direct CLI mode. The shared CLI guard does not reject an
ordinary include.

## PHP command inventory

Paths below are relative to `scripts/`. Support directories contain include-only
implementations except the explicitly listed optional CLI dispatchers. None is a
browser endpoint.

| Classification | Files |
| --- | --- |
| Operator CLI commands | `application_update.php`, `cooperative_renew.php`, `create_admin.php`, `migrate.php`, `reconcile_admin_operations.php`, `reconcile_image_moves.php`, `recovery.php`, `site_maintenance.php`, `telemetry_maintenance.php` |
| Release/package CLI commands | `check_release.php`, `generate_manifest.php`, `generate_production_files.php`, `release_files.php`, `prepare_release.php`, `release_qualification.php` |
| Audit/workflow CLI commands | `audit.php`, `audit_route_probe.php`, `audit_runtime_probe.php`, `benchmark_title_completion.php`, `check_admin_mutation_contracts.php`, `generate_source_debt_baseline.php`, `gallery_workflow_ci.php`, `gallery_workflow_mysql.php`, `gallery_workflow_run.php` |
| Includeable tools with guarded direct CLI dispatch | `audit_route_performance.php`, `check_mvc_boundaries.php`, `check_policy_constants.php`, `check_python_import_policy.php`, `check_source_documentation.php`, `generate_runtime_modules.php`, `runtime_dependencies.php`, `runtime_dynamic_dependencies.php`, `recovery/cli.php`, `release_qualification/cli.php` |
| Include-only libraries and policy inputs | `audit_lib.php`, `audit_performance.php`, `audit_process.php`, `audit_performance_registry.php`, `audit_php_registry.php`, `audit_registry.php`, `audit_route_probe_registry.php`, `release_lib.php`, `release_qualification_lib.php`, `runtime_module_roots.php`; the remaining files in `recovery/`, `release_qualification/`, and `source_contracts/` |
| Shared CLI boundary | `cli_guard.php`; its own direct URL also rejects HTTP |

Root `config.example.php` is include data, not a command. `winapp/` has no PHP
entrypoints. Local `cache/generate_wizard_registry_translations.php` and
`cache/admin-updates-preview-router.php` are development artifacts outside the
production file set; the latter is an intentional disposable web preview adapter.
Shell/PowerShell deploy launchers run through their shell interpreters and are
also denied as HTTP files.

## Apache and hosting scope

Each protected directory uses `Require all denied` when Apache 2.4 authorization
modules are available and the Apache 2.2 `Order allow,deny` / `Deny from all`
fallback otherwise. This is designed for Apache and compatible LiteSpeed hosting
that honors `.htaccess` authorization directives. The site owner must configure
`AllowOverride` to permit authorization directives; a server that ignores
`.htaccess` needs equivalent server-level access rules. PHP's built-in development
server and non-Apache servers do not read `.htaccess`; their local tests do not
establish Apache authorization behavior.

The directory guards do not change PHP SAPI boundaries. Every true CLI command
must still reject web-SAPI execution before bootstrap or mutation work, because
some hosts may disable `.htaccess` or expose a script through a separate alias.
Conversely, running a guarded script from a shell is unaffected: Apache access
rules do not apply to CLI execution.
