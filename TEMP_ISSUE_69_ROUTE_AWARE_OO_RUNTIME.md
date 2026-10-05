# TEMP: Issue #69 — Route-aware PHP bootstrap + small OO runtime kernel

> Working implementation tracker for GitHub issue #69  
> Repository: `klusik/PHP_gallery`  
> Issue: `[P1] Make PHP bootstrap route-aware and lazy-load runtime modules`  
> Status: **COMPLETE — full verification PASS**
> Temporary document: retained in the working tree at handoff as requested; permanent documentation remains authoritative after integration.

---

## 0. Purpose of this document

This file is the **working source of truth during implementation** of issue #69.

It is intentionally more detailed than the GitHub issue. Agents and subagents should use it to:

- track completed and remaining work,
- record architectural decisions,
- avoid duplicate work,
- keep route/lazy-loading migration incremental,
- preserve shared-hosting compatibility,
- record measured before/after performance,
- track changed files and test coverage,
- identify follow-up work that must not be silently pulled into this issue.

**Do not mark a section complete based only on code existing.** Mark it complete only after the relevant focused tests and central audit coverage pass.

---

# 1. Current baseline

The current architecture eagerly loads most of the PHP runtime before route-specific execution.

Current measured baseline after issue #68 work:

| Metric | Current baseline |
|---|---:|
| PHP files included before `cms_run()` | **~533** |
| Peak bootstrap memory | **~36.5 MiB** |
| Bootstrap wall time | observational only, environment-dependent |
| Existing hard included-file ceiling | **560** |
| Existing peak-memory ceiling | **64 MiB** |

The exact wall time is not a portable acceptance metric. The included-file count is the primary deterministic baseline.

The current boot path is conceptually:

```text
public/index.php
  -> app/early_runtime.php
  -> app/bootstrap.php
       -> configuration/core helpers
       -> database/security
       -> models.php
       -> services.php
       -> views.php
       -> controllers.php
       -> routing/session/request/etc.
  -> cms_run()
       -> request/route resolution
       -> dispatch
```

This means route resolution occurs **after most runtime modules have already been loaded**.

---

# 2. Primary goal

Change the runtime loading model from:

```text
LOAD MOST OF THE APPLICATION
  -> resolve request
  -> dispatch route
```

to:

```text
minimal runtime core
  -> resolve request
  -> determine route
  -> load only required runtime/domain modules
  -> dispatch route
```

The implementation must produce a **major reduction in unnecessary PHP includes**, especially for:

- simple public routes,
- lightweight structured/API routes,
- media/thumb routes,
- narrow admin routes,
- crawler/system routes such as `robots.txt`.

---

# 3. Architectural direction

Issue #69 should also establish a **small object-oriented runtime kernel**, while preserving existing procedural/function-oriented domain code.

This is deliberately **not** a full OOP rewrite.

Target principle:

> Objects own state, lifecycle, routing and dependencies.  
> Pure/stateless transformations may remain functions.

The implementation should allow the existing procedural application to continue working while new runtime infrastructure is object-oriented and autoloaded.

---

# 4. Zero-install runtime policy

This requirement is **non-negotiable**.

PHP Gallery must remain deployable to ordinary shared PHP hosting by uploading release files and configuring the application.

Production runtime must **not require**:

- Composer,
- npm,
- Node.js,
- Python,
- Docker,
- shell/SSH access,
- a long-running worker process,
- a framework runtime,
- an external dependency injection container,
- a package installation step on the server.

The production runtime should continue to require only normal shared-hosting capabilities:

- supported PHP version,
- normal required PHP extensions,
- MySQL/MariaDB,
- filesystem access,
- web server.

Additional facilities may be optional accelerators only if a normal fallback remains available.

### Composer policy

Do **not** introduce Composer merely for class autoloading.

Use a small repository-owned autoloader based on PHP language/runtime features such as:

```php
spl_autoload_register(...)
```

A future build-time Composer dependency is a separate architectural decision and is outside issue #69.

---

# 5. Explicit non-goals

Issue #69 must **not** become a big-bang rewrite.

Do not:

- convert all procedural functions to methods,
- convert all services to classes,
- convert all models to repositories,
- convert all views to objects,
- introduce Laravel, Symfony, Slim or another framework,
- add Composer solely for autoloading,
- introduce a large DI container,
- create interfaces for every class without a real abstraction need,
- change public URLs,
- change route names/contracts,
- change public API payloads,
- change gallery behavior unless required to preserve compatibility,
- optimize unrelated domain logic,
- implement unrelated issue #72 work beyond what is naturally required for the new runtime boundary,
- remove compatibility umbrella loaders until all dependent test/CLI use is understood.

---

# 6. Target runtime structure

The target should look conceptually like:

```text
public/index.php
  -> early_runtime.php
  -> minimal bootstrap
       -> repository-owned class autoloader
       -> minimal config/runtime primitives
       -> Request
       -> Router
       -> RouteRegistry
       -> RouteDefinition
       -> ModuleLoader
       -> Kernel
  -> resolve route
  -> load route/domain modules
  -> dispatch existing controller/function or migrated OO controller
  -> Response
```

Possible new runtime classes:

```text
Gallery\Core\Kernel
Gallery\Core\Request
Gallery\Core\Response
Gallery\Core\Router
Gallery\Core\RouteRegistry
Gallery\Core\RouteDefinition
Gallery\Core\RouteMatch
Gallery\Core\ModuleLoader
```

Exact naming may change after inspecting existing namespaces and conventions.

Do not create classes merely to satisfy a pattern. Each class must own a clear responsibility.

---

# 7. Autoloading design

## Goal

New OO runtime classes should be loaded only when they are referenced.

## Preferred implementation

A minimal project-owned namespace-to-path autoloader.

Conceptually:

```php
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Gallery\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = __DIR__ . '/' . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
);
```

The actual path mapping must match the repository layout and supported PHP versions.

## Requirements

- no Composer requirement,
- deterministic namespace/path mapping,
- no arbitrary filesystem traversal,
- no user-controlled class-path resolution,
- no silent fallback that hides missing class files,
- compatible with PHP 8.1+,
- testable independently,
- loaded very early but itself extremely small.

---

# 8. Route-aware loading model

A route must carry enough metadata to load its runtime dependencies before dispatch.

Conceptual example:

```php
new RouteDefinition(
    name: 'gallery',
    handler: '\Gallery\Controllers\cms_gallery',
    modules: ['public-common', 'public-gallery'],
);
```

or equivalent.

The exact storage format is flexible.

Important invariant:

```text
resolve route
  -> load declared runtime modules
  -> verify handler availability
  -> dispatch
```

Do not create a global registry that simply lists every file and eagerly loads all of them. That would reproduce the old problem under a new name.

---

# 9. Module/bundle design

A runtime module/bundle is a **logical dependency group**, not a build artifact.

Likely module families may include:

```text
core
public-common
public-gallery
public-media
public-map
viewer-common
viewer-auth
admin-common
admin-gallery
admin-settings
admin-telemetry
admin-maintenance
admin-upload
downloads
cooperative-galleries
```

Do not blindly create all of these names. Derive real groups from actual route/domain dependencies.

Prefer composition:

```text
admin-telemetry
  depends on admin-common
  loads telemetry-specific model/service/controller/view files
```

Avoid route definitions containing giant raw file arrays.

Module definitions should own domain file loading, while routes own **which logical modules they need**.

---

# 10. Compatibility umbrella loaders

Existing files such as:

```text
app/models.php
app/services.php
app/views.php
app/controllers.php
```

must not be deleted early.

They may remain as **compatibility/full-load entrypoints** for:

- legacy tests,
- CLI tooling,
- maintenance scripts,
- diagnostics,
- temporary migration support.

The web request path should gradually stop using them.

Desired transition:

```text
web runtime:
  route-aware selective loading

test/CLI compatibility:
  optional full umbrella loading
```

Later cleanup can remove or reduce umbrella loaders only after usage is measured and migrated.

---

# 11. Implementation phases

Use these phases as the main progress tracker.

---

## Phase 0 — Reconnaissance and dependency map

Status: [x] COMPLETE (reconnaissance evidence recorded)

### Tasks

- [x] Read `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `TESTING.md`.
- [x] Inspect `app/bootstrap.php`.
- [x] Inspect `app/early_runtime.php`.
- [x] Inspect request initialization and routing.
- [x] Inspect dispatcher and route registry/table.
- [x] Inspect `models.php`, `services.php`, `views.php`, `controllers.php`.
- [x] Identify all files that execute code at include time.
- [x] Identify constants/global state that depend on include order.
- [x] Identify files with cross-layer `require_once`.
- [x] Identify tests/CLI scripts that directly require umbrella loaders.
- [x] Record current bootstrap probe output.
- [x] Add route-level probe plan before structural changes.

### Deliverables

- [x] Dependency map.
- [x] Known include-order hazards list.
- [x] Candidate runtime module groups.
- [x] Baseline measurements recorded below.
- [x] Explicit list of compatibility consumers of umbrella loaders.

### Notes

Reconnaissance (2026-10-05): bootstrap eagerly reaches 533 files. Umbrella lists directly own 71 model, 135 service, 70 view and 67 controller entrypoints; nested parts expand those counts. `services.php` itself requires `models.php`. Modules mostly declare functions/classes/constants; executable includes preserve split-module entrypoints and their constant order. Database connection/schema work occurs when APIs are called, rather than simply by including the model declarations.

The request graph is config -> session -> query/pretty-route normalization -> SEO/translation/viewer restoration/headers -> media session unlock/schema snapshot -> maintenance -> dispatcher preflight -> procedural handler. Pretty gallery pagination performs actual path lookups; it cannot become a name-only parser. Feature policy and visibility/access/NSFW preflight must remain ahead of the handler, including the centralized route-aware schema-unavailable response.

Compatibility consumers: the eight CLI tools `application_update`, `cooperative_renew`, `create_admin`, `migrate`, `reconcile_admin_operations`, `reconcile_image_moves`, `telemetry_maintenance`, `site_maintenance`; test/support seeds, move workers and selected standalone model/viewer tests. Umbrella source ordering is also a contract in existing MVC/source tests. The full-load path will be explicit and separate from the ordinary web bootstrap.

Runtime loading plans will be checked-in logical modules derived from actual function-level dependency analysis and reviewed dynamic edges. The analysis is development-only: production will not scan PHP, invoke a package manager, or discover paths from user input. Routes carry logical module IDs, never large per-route file arrays. The loader will own deduplication, declared dependency ordering and unknown/cycle refusal.

Baseline quick audit: 26 PHP passes and one existing process-tree cleanup failure under sandbox restrictions. The exact failing `tests/audit_runner_test.php` passed unchanged outside the sandbox (7.84 s); Windows process-termination coverage needs that execution context. Other baseline quick suites passed. No runner workaround or weakened assertion was introduced.

---

## Phase 1 — Route-level performance probes

Status: [x] COMPLETE — final full audit PASS

The current bootstrap-only probe is insufficient after lazy loading because work may simply move from pre-`cms_run()` to route dispatch.

### Required representative probes

At minimum evaluate realistic coverage for:

- [x] bootstrap only,
- [x] public home,
- [x] public gallery,
- [x] simple public/system route such as `robots.txt`,
- [x] public media/thumb route,
- [x] admin dashboard or equivalent shared admin route,
- [x] narrow admin route such as telemetry.

Each probe should measure:

- [x] total included PHP files at end of relevant route execution,
- [x] peak memory,
- [x] wall time as observational metric,
- [x] route name / probe identity,
- [x] stable machine-readable output.

### Rules

- included-file count may be a hard regression guard,
- memory may use a conservative ceiling,
- wall time should normally remain observational,
- probes must execute in isolated child processes,
- probes must not fabricate impossible request state,
- probes should be deterministic and safe.

### Deliverables

- [x] Probe implementation.
- [x] Baseline route measurements.
- [x] Tests for probe schema/contracts.

---

## Phase 2 — Minimal OO runtime foundation

Status: [x] COMPLETE — final full audit PASS

### Tasks

- [x] Add repository-owned namespace autoloader.
- [x] Add/define `Request` abstraction if not already suitable.
- [x] Review `Response`: existing controllers own status/headers/body; no extra abstraction is useful here.
- [x] Add `RouteDefinition`.
- [x] Add `RouteRegistry`.
- [x] Add `Router` / route resolution boundary.
- [x] Add `ModuleLoader`.
- [x] Add `Kernel` or equivalent runtime coordinator.
- [x] Keep responsibilities small and explicit.
- [x] Avoid a general-purpose DI container.
- [x] Preserve existing route behavior.

### Compatibility strategy

The new kernel may initially dispatch existing procedural handlers.

Example:

```text
OO Kernel
  -> route definition
  -> module loader
  -> existing function controller
```

This is expected and desirable.

### Tests

- [x] autoloader contract,
- [x] route definition validation,
- [x] route lookup,
- [x] module deduplication,
- [x] missing module behavior,
- [x] missing handler behavior,
- [x] existing route compatibility.

---

## Phase 3 — Lazy-load controllers and views

Status: [x] COMPLETE — final full audit PASS

This should be the first major runtime reduction because controllers/views are relatively easy to isolate.

### Tasks

- [x] Remove global controller umbrella loading from ordinary web bootstrap.
- [x] Remove global view umbrella loading from ordinary web bootstrap.
- [x] Associate controller/view domains with logical runtime modules.
- [x] Ensure route module loading occurs before handler dispatch.
- [x] Preserve compatibility loaders for tests/CLI.
- [x] Verify all route handlers resolve correctly.

### Acceptance checks

- [x] no undefined controller functions,
- [x] no missing view functions,
- [x] route contracts unchanged,
- [x] substantial reduction in bootstrap-only includes,
- [x] representative routes still pass.

### Measurements

Record after Phase 3:

| Probe | Before includes | After includes | Peak memory before | Peak memory after |
|---|---:|---:|---:|---:|
| bootstrap | 533 | 357 | 36 MiB | 24 MiB |
| robots | 536 | 367 | 38 MiB | 26 MiB |
| home | 536 | 386 | 38 MiB | 26 MiB |
| gallery | 536 | 401 | 38 MiB | 28 MiB |
| thumb | 536 | 374 | 38 MiB | 26 MiB |
| media | 536 | 374 | 40 MiB | 28 MiB |
| admin | 536 | 383 | 38 MiB | 28 MiB |
| admin_telemetry | 536 | 373 | 38 MiB | 26 MiB |

---

## Phase 4 — Lazy-load feature/domain services

Status: [x] COMPLETE — final full audit PASS

This is more sensitive than controller/view loading.

### Tasks

- [x] Classify services by domain.
- [x] Identify service-to-service dependencies.
- [x] Identify service-to-model dependencies.
- [x] Move relevant service includes into module ownership.
- [x] Prevent accidental fallback to global `services.php` on normal routes.
- [x] Keep shared/core services minimal.
- [x] Preserve compatibility service umbrella loader.
- [x] Add dependency cycle detection or explicit validation if useful.

### Important rule

Do not create a `public-common` or `admin-common` bundle so large that it becomes a new global bootstrap.

Shared bundles must remain intentionally small.

---

## Phase 5 — Reduce model/core umbrella loading

Status: [x] COMPLETE — final full audit PASS

This is expected to be the most sensitive phase.

### Tasks

- [x] Identify models genuinely required by minimal core.
- [x] Move feature-specific models behind domain modules.
- [x] Identify include-time DB/schema work and remove it where safe.
- [x] Preserve migration/bootstrap requirements.
- [x] Verify no hidden model-function assumptions remain.
- [x] Reduce remaining umbrella use on the web request path.

### Guardrails

- do not compromise schema inspection safety,
- do not weaken mutation-schema policy,
- do not move migrations into unsafe lazy execution,
- do not change database behavior merely for include-count gains.

---

## Phase 6 — Request/response integration

Status: [x] COMPLETE — final full audit PASS

Only perform the portion naturally required for the kernel.

### Tasks

- [x] Route new kernel through existing request adapter where possible.
- [x] Avoid introducing new direct `$_GET`/`$_POST`/`$_SERVER` access.
- [x] Ensure structured endpoints retain correct headers/status/body.
- [x] Ensure media/crawler routes retain their response semantics.
- [x] Keep issue #72 scope separate where possible.

### Non-goal

Do not convert every legacy controller to `Response` objects in this issue unless required for correctness.

---

## Phase 7 — Full compatibility and regression pass

Status: [x] COMPLETE — final full audit PASS

### Required validation

- [x] changed PHP syntax,
- [x] changed JS syntax if applicable,
- [x] focused new runtime tests,
- [x] quick audit,
- [x] full PHP regression,
- [x] full audit,
- [x] browser suite,
- [x] mutation/runtime contracts,
- [x] PHP 8.1 source compatibility reviewed; local execution is PHP 8.3.30, PHP 8.1 execution unavailable,
- [x] Git diff check,
- [x] no route contract changes,
- [x] no API payload changes,
- [x] no install/setup regression,
- [x] no updater regression,
- [x] no test/CLI compatibility regression.

### Required negative checks

- [x] no Composer runtime dependency,
- [x] no framework dependency,
- [x] no Node/Python production dependency,
- [x] no new shell requirement,
- [x] no global "load everything" fallback accidentally triggered on normal requests.

---

## Phase 8 — Documentation and cleanup

Status: [x] COMPLETE — final full audit PASS

### Update permanent documentation

- [x] `ARCHITECTURE.md`
- [x] `CODEMAP.md`
- [x] `README.md`
- [x] `TESTING.md`
- [x] `AGENTS.md`
- [x] Patch notes: not required for this implementation task; no release notes or version changes requested.
- [x] any routing/runtime documentation impacted by the new kernel

### Cleanup

- [x] remove temporary debugging code,
- [x] remove dead migration scaffolding created only during refactor,
- [x] keep useful compatibility loaders,
- [x] document any intentionally retained legacy path,
- [x] list follow-up issues,
- [x] Retain this TEMP tracker in the checkout at handoff, including full changed-files ledger and evidence references.

---

# 12. Worker coordination ledger

The orchestrating agent owns this table.

Do not allow two workers to modify the same high-risk core file concurrently unless the orchestrator explicitly plans the merge.

| Worker | Scope | Files/area | Status | Result / handoff |
|---|---|---|---|---|
| dependency_recon (gpt-6-luna/high) | graph/scanner, clean module tests, independent architecture review | runtime_dependencies; runtime tests; updater lookup split; kernel/loader | complete; frozen review found no concrete defect | 227 handlers/93 modules covered; removed false setting/route edges and eager updater lookup |
| routing_security_recon (gpt-6-luna/high) | security/OO/PHP8.1, request updater split, dynamic dependency policy | auth/viewer/schema/preflight; updates_request; lifecycle roots | complete; frozen source review found no concrete defect | preserved identity and active-job-first semantics; explicit optional diagnostics and shutdown dependencies |
| probe_compat_recon (gpt-6-luna/high) | probes/test coverage, CLI consumers, permanent docs, dynamic callback review | fixture/probes/validators; five docs; callback inventories | complete | baseline/phases3-5 PASS; strict nine-case matrix; verified both callback dispatchers; semantic marker recommendations |
| final_compatibility_review (gpt-6-luna/high) | independent CLI/full bootstrap/PHP8.1/updater snapshot review | bootstrap_full; eight CLI tools; runtime; updater archive preflight | complete; finding fixed and resolution confirmed | restored legacy migrations -> models/services -> views -> integrity -> controllers order |
| final_performance_review (gpt-6-luna/high) | independent lifecycle accounting, outcomes/ceilings and evidence review | before/after captures; probe; validator; fixture; kernel | complete; finding fixed and resolution confirmed | generic HTTP200 error-body false success now rejected; validation remains outside resource measurements |

---

# 13. Decision log

Record non-trivial decisions here before or when they are implemented.

| Decision | Rationale | Date/agent |
|---|---|---|
| No Composer runtime dependency | Preserve ordinary shared-hosting deployment | implemented |
| Small OO runtime kernel, not full OOP rewrite | Gain autoloading/state ownership without huge regression surface | implemented |
| Existing procedural handlers remain supported | Incremental migration, route contract stability | implemented |
| Umbrella loaders remain supported | Preserve tests/CLI and migration safety via explicit full bootstrap | implemented |
| Route-level probes required | Prevent fake improvement by moving load work after `cms_run()` | implemented; all phases measured |
| Full compatibility bootstrap is explicit | CLI/test tools may deliberately load all layers; ordinary web requests must never fall back to it | 2026-10-05 / root |
| Compiled logical loading plans; analysis stays development-only | Procedural functions cannot autoload; reviewed static roots, explicit dynamic edges and module-owned files make loading deterministic | 2026-10-05 / root |
| Keep controlled deferred updater/maintenance work | Preserve existing policy order and scheduled behavior without loading workers on every ineligible request | 2026-10-05 / root |

Add new decisions below.

---

# 14. Baseline and result measurements

## Before implementation

Fill with actual measured values.

| Probe | Included files | Peak memory | Wall time | Notes |
|---|---:|---:|---:|---|
| bootstrap-only | 533 | 36 MiB (37,748,736 bytes) | 230.9700 ms | fresh PHP 8.3 child; `cache/issue-69-bootstrap-before.json` |
| robots | 536 | 38 MiB | 287.9522 ms | actual anonymous / HTTP 200; `issue-69-routes-before.json` |
| home | 536 | 38 MiB | 302.2770 ms | actual anonymous / HTTP 200; `issue-69-routes-before.json` |
| gallery | 536 | 38 MiB | 327.9023 ms | actual anonymous / HTTP 200; `issue-69-routes-before.json` |
| thumb | 536 | 38 MiB | 294.5523 ms | actual anonymous / HTTP 200; `issue-69-routes-before.json` |
| media | 536 | 40 MiB | 270.3229 ms | actual anonymous / HTTP 200; `issue-69-routes-before.json` |
| admin | 536 | 38 MiB | 383.8353 ms | actual admin / HTTP 200; `issue-69-routes-before.json` |
| admin_telemetry | 536 | 38 MiB | 302.0991 ms | actual admin / HTTP 200; `issue-69-routes-before.json` |
| admin_denied | 536 | 38 MiB | 281.5534 ms | actual anonymous / HTTP 302; `issue-69-routes-before.json` |
| admin_telemetry_denied | 536 | 38 MiB | 283.0163 ms | actual anonymous / HTTP 302; `issue-69-routes-before.json` |

## After implementation

| Probe | Included files | Peak memory | Wall time | Improvement |
|---|---:|---:|---:|---:|
| bootstrap-only | 24 | 2 MiB | 6.9792 ms | 95.5% fewer includes |
| robots/system | 90 | 10 MiB | 76.6624 ms | 83.2% fewer includes |
| public home | 173 | 16 MiB | 143.5636 ms | 67.7% fewer includes |
| public gallery | 243 | 18 MiB | 217.6157 ms | 54.7% fewer includes |
| thumbnail | 140 | 12 MiB | 100.7661 ms | 73.9% fewer includes |
| media | 136 | 14 MiB | 118.0802 ms | 74.6% fewer includes |
| admin dashboard | 205 | 20 MiB | 271.2522 ms | 61.8% fewer includes |
| admin telemetry | 131 | 12 MiB | 155.6010 ms | 75.6% fewer includes |
| admin denied | 205 | 18 MiB | 164.8044 ms | 61.8% fewer includes |
| admin telemetry denied | 131 | 12 MiB | 101.2271 ms | 75.6% fewer includes |

Final evidence: full run `cache/test-audit/20261005-132331-51800`, with actual nine-route matrix in `cache/test-audit/issue-69-routes-after.json`. Seven successful requests returned HTTP 200 with nonempty bodies; two genuine anonymous Admin cases retained HTTP 302 denial. Measurements include the front controller, `cms_run()` and application shutdown tail in a fresh PHP child against a real migrated disposable MySQL fixture; authenticated cases reuse a session created by a real HTTP login. They exclude harness files, fixture/login startup, HTTP transport and TLS/network time. Before and after use the same application-file accounting. Wall time is observational, not a gate.

### Interpretation rules

A successful implementation should show:

- dramatic improvement for bootstrap-only,
- very large improvement for small/system/media/API routes,
- meaningful improvement for complex gallery/admin routes,
- no regression in observable behavior.

Do not declare success based only on the bootstrap-only metric.

---

# 15. Files changed ledger

Complete changed-file inventory from Git, including new files (78 total). No installer, release package or version marker is part of this change.

| File | Change | Owner worker | Status |
|---|---|---|---|
| `AGENTS.md` | Permanent runtime documentation or complete issue evidence/coordination tracker | root/probe worker | full PASS; handoff checked |
| `app/bootstrap.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/bootstrap/dispatch.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/bootstrap/maintenance.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/bootstrap/request.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/bootstrap/routing.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/bootstrap_full.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/core-manifest.json` | Fresh updater-managed source hashes | root | full PASS; handoff checked |
| `app/runtime/autoload.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/bridge.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/Kernel.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/ModuleLoader.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/modules.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/Request.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/RouteDefinition.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/Router.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/runtime/RouteRegistry.php` | Selective Core request/kernel/module ownership | root | full PASS; handoff checked |
| `app/services/admin_log_archives.php` | Deferred request/shutdown work and complete updater snapshot checks | root/security worker | full PASS; handoff checked |
| `app/services/site_maintenance.php` | Deferred request/shutdown work and complete updater snapshot checks | root/security worker | full PASS; handoff checked |
| `app/services/updates_filesystem.php` | Deferred request/shutdown work and complete updater snapshot checks | root/security worker | full PASS; handoff checked |
| `app/services/updates_install.php` | Deferred request/shutdown work and complete updater snapshot checks | root/security worker | full PASS; handoff checked |
| `app/services/updates_job_lookup.php` | Narrow lookup owner and preserved legacy worker API | dependency worker/root | full PASS; handoff checked |
| `app/services/updates_jobs.php` | Narrow lookup owner and preserved legacy worker API | dependency worker/root | full PASS; handoff checked |
| `app/services/updates_jobs/budget.php` | Narrow lookup owner and preserved legacy worker API | dependency worker/root | full PASS; handoff checked |
| `app/services/updates_jobs/state.php` | Narrow lookup owner and preserved legacy worker API | dependency worker/root | full PASS; handoff checked |
| `app/services/updates_request.php` | Deferred request/shutdown work and complete updater snapshot checks | root/security worker | full PASS; handoff checked |
| `ARCHITECTURE.md` | Permanent runtime documentation or complete issue evidence/coordination tracker | root/probe worker | full PASS; handoff checked |
| `CODEMAP.md` | Permanent runtime documentation or complete issue evidence/coordination tracker | root/probe worker | full PASS; handoff checked |
| `README.md` | Permanent runtime documentation or complete issue evidence/coordination tracker | root/probe worker | full PASS; handoff checked |
| `scripts/application_update.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/audit.php` | Authoritative registration, ceilings and compact performance reporting | root/probe worker | full PASS; handoff checked |
| `scripts/audit_lib.php` | Authoritative registration, ceilings and compact performance reporting | root/probe worker | full PASS; handoff checked |
| `scripts/audit_performance_registry.php` | Authoritative registration, ceilings and compact performance reporting | root/probe worker | full PASS; handoff checked |
| `scripts/audit_php_registry.php` | Authoritative registration, ceilings and compact performance reporting | root/probe worker | full PASS; handoff checked |
| `scripts/audit_registry.php` | Authoritative registration, ceilings and compact performance reporting | root/probe worker | full PASS; handoff checked |
| `scripts/audit_route_performance.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `scripts/audit_route_probe.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `scripts/audit_route_probe_registry.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `scripts/cooperative_renew.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/create_admin.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/gallery_workflow_mysql.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `scripts/gallery_workflow_run.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `scripts/generate_runtime_modules.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/migrate.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/reconcile_admin_operations.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/reconcile_image_moves.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/runtime_dependencies.php` | Non-executing source dependency analysis and regression contracts | dependency worker/root | full PASS; handoff checked |
| `scripts/runtime_dynamic_dependencies.php` | Reviewed dynamic/optional/lifecycle dependency policy | security worker/root | full PASS; handoff checked |
| `scripts/runtime_module_roots.php` | Reviewed dynamic/optional/lifecycle dependency policy | security worker/root | full PASS; handoff checked |
| `scripts/site_maintenance.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `scripts/telemetry_maintenance.php` | Explicit full-bootstrap CLI compatibility or deterministic module compilation | root | full PASS; handoff checked |
| `TEMP_ISSUE_69_ROUTE_AWARE_OO_RUNTIME.md` | Permanent runtime documentation or complete issue evidence/coordination tracker | root/probe worker | full PASS; handoff checked |
| `TESTING.md` | Permanent runtime documentation or complete issue evidence/coordination tracker | root/probe worker | full PASS; handoff checked |
| `tests/admin_log_archive_maintenance_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/admin_test_run_v11_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/autoupdate_request_latency_safety_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/gallery_workflow_safety_test.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `tests/public_media_session_release_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/runtime_dependencies_test.php` | Non-executing source dependency analysis and regression contracts | dependency worker/root | full PASS; handoff checked |
| `tests/runtime_kernel_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/runtime_module_plan_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/runtime_refactor_boundaries_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/runtime_route_probe_test.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `tests/stage13_loader_dependency_boundary_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/stage9_http_transport_boundary_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/support/audit_route_probe_fixture.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `tests/support/dispatch_kernel.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/support/gallery_image_move_worker.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/support/gallery_workflow_http.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `tests/support/gallery_workflow_seed.php` | Real disposable route evidence, owned auth/fixtures and strict matrix validation | probe worker/root | full PASS; handoff checked |
| `tests/support/nsfw_policy_dispatch_fixture.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/support/runtime_module_fixture.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/support/security_schema_policy_dispatch_fixture.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/support/session_contention_application_router.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/thumbnail_warmup_model_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/updater_resumable_state_machine_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/updater_safety_model_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |
| `tests/viewer_collections_phase20_test.php` | Runtime regression or existing contract adapted to canonical responsibility owner | root/workers | full PASS; handoff checked |

---

# 16. Known hazards

Track discovered hazards here.

Potential categories:

- include-time side effects,
- order-dependent constants,
- functions declared conditionally,
- route handler existence checks,
- test fixtures directly requiring global loaders,
- migration code expecting all models/services loaded,
- shared global state,
- direct `$_SESSION`/request access,
- shutdown handlers,
- header/output side effects,
- duplicated helper names,
- circular domain dependencies,
- CLI scripts assuming bootstrap loads the entire runtime.

Add exact file/function references when found.

- `bootstrap/routing.php`: pretty gallery numeric/pagination ambiguity depends on `find_gallery_by_public_path` and `resolve_public_gallery_path`.
- `bootstrap/dispatch.php`: absent feature-policy functions must never make `function_exists` bypass capability checks; protected routes need visibility/access/NSFW before output.
- `security.php` / `bootstrap/session.php`: omitted auth persistence or lifetime helpers would silently change login/session policy.
- `bootstrap/viewer_identity_context.php` / `services/viewer_http.php`: disabled Viewer mode must still clear stale viewer authority; conditional loading must retain this cleanup.
- `updates_install.php`: active background jobs continue before the method/autoupdate preference gate; preserving that order is mandatory.
- `admin_log_archives.php`, `site_maintenance.php`, benchmark/test-run lifecycle: dependencies of registered shutdown callbacks must be available when those callbacks execute.
- `tests/route_reference_integrity_test.php` and `runtime_refactor_boundaries_test.php`: literal-table/lifecycle source contracts must follow the new owner rather than disappear.

---

# 17. OOP migration boundary

This issue introduces OOP only where it directly improves runtime ownership.

## Good candidates inside issue #69

- Kernel
- Router
- RouteRegistry
- RouteDefinition
- Request
- Response
- ModuleLoader
- loader/autoload infrastructure

## Usually outside issue #69

- converting every controller to a class,
- converting every service to a class,
- converting every model to a repository,
- converting stateless helper functions,
- introducing interfaces with only one implementation,
- adding a DI framework/container.

Any larger OOP migration should be proposed separately after #69 is stable.

---

# 18. Coding principles

- Keep existing docstrings.
- Preserve meaningful comments.
- Prefer explicit dependencies.
- Avoid hidden global registries.
- Prefer immutable route metadata where practical.
- Keep the autoloader minimal.
- Keep the kernel boring and understandable.
- Avoid speculative abstractions.
- Avoid generic infrastructure that has only one real use.
- Preserve current security boundaries.
- Preserve current schema-inspection policies.
- Preserve updater/install compatibility.
- Prefer incremental commits/steps internally even if the final user handoff is one change set.

---

# 19. Testing strategy

## Focused tests

Create or extend focused tests for:

- autoload path resolution,
- namespace rejection/out-of-prefix behavior,
- route definition validation,
- route registry lookup,
- duplicate route handling,
- module load deduplication,
- module dependency order,
- missing module behavior,
- circular dependencies if module dependencies are supported,
- handler resolution,
- procedural handler compatibility,
- request route parity,
- representative public/admin route loading,
- performance probe schema and thresholds.

## Central verification

Use the repository's canonical audit commands.

Do not invent a parallel test orchestration framework.

Final validation should include the strongest applicable central audit after focused tests pass.

---

# 20. Acceptance criteria

Issue #69 is complete only when all of these are true:

- [x] ordinary web bootstrap no longer loads almost all runtime modules,
- [x] route resolution happens before feature/domain module loading,
- [x] controllers/views are selectively loaded,
- [x] feature/domain services are selectively loaded where practical,
- [x] feature/domain models are selectively loaded where practical,
- [x] compatibility umbrella loaders remain available where still needed,
- [x] a small OO runtime kernel exists with clear responsibilities,
- [x] project-owned autoloading works without Composer,
- [x] production deployment still needs no external package manager,
- [x] route contracts remain unchanged,
- [x] public API behavior remains unchanged,
- [x] no hidden global load-all fallback runs on normal requests,
- [x] route-level probes prove the work was not merely moved after `cms_run()`,
- [x] bootstrap-only include count is dramatically lower than ~533,
- [x] simple routes show very large include-count reductions,
- [x] complex public/admin routes show meaningful reductions,
- [x] peak memory does not regress materially,
- [x] wall time is reported without flaky CI thresholds,
- [x] PHP 8.1+ compatibility is preserved,
- [x] focused tests pass,
- [x] quick audit passes,
- [x] full audit passes,
- [x] relevant browser tests pass,
- [x] mutation/runtime contracts pass,
- [x] documentation is updated,
- [x] final diff contains no unrelated feature work.

---

# 21. Follow-up candidates

No unresolved correctness defect or missing implementation requirement remains from issue #69 after the final full audit. The historical source inventory remains advisory migration debt; strict changed-source and MVC checks have zero findings.

Existing issue #72 remains the owner of wider request/session boundary cleanup; the new Request snapshot deliberately covers only the normalization needed by this kernel. A broad controller/service OOP migration or removal of compatibility umbrellas is not required by this change.

Verification limitation: PHP 8.1 is not installed locally. All new syntax was reviewed against that minimum; the actual audits ran on PHP 8.3.30. The repository's existing PHP 8.1 CI matrix remains the execution check for that version.

---

# 22. Agent handoff format

Every worker should return:

```text
Scope completed:
Files inspected:
Files changed:
Key findings:
Tests run:
Tests passed/failed/skipped:
Risks / unresolved questions:
Recommended next step:
```

Workers should not claim global completion of issue #69. Only the orchestrating agent may do that after integration and full verification.

---

# 23. Final implementation report template

The orchestrator should finish with:

## Architecture implemented
- new kernel components
- route loading model
- module loading model
- compatibility model

## Performance before/after
- bootstrap-only
- simple route
- public gallery
- media/thumb
- admin route

## OOP boundary
- what became object-oriented
- what deliberately remained procedural
- why

## Shared-hosting compatibility
- runtime dependencies
- confirmation that Composer/Node/Python/shell are not required

## Tests
- focused tests
- quick
- full
- browser
- runtime/mutation contracts

## Changed files
Complete list.

## Remaining follow-ups
Only real remaining work, not generic suggestions.

---

# 24. Current progress summary

Update this block continuously.

```text
Overall status: COMPLETE — verified handoff

Phase 0 reconnaissance:        100%
Phase 1 route probes:          100%
Phase 2 OO runtime core:       100%
Phase 3 controllers/views:     100%
Phase 4 services:              100%
Phase 5 models/core:           100%
Phase 6 request/response:      100%
Phase 7 regression pass:       100%
Phase 8 documentation:         100%

Current blocker: none recorded
Current owner: orchestrating agent
Last central audit: full 20261005-132331-51800 PASS (266.39 s): 306 PHP, 25 Node, 25 Chromium, 11/11 performance measurements, zero route SKIP; 134 WinApp PASS / 1 unrelated SKIP. Final compatibility and semantic-response review findings resolved and independently confirmed; owned database/files/private MySQL cleanup PASS.
```

## Integration journal (2026-10-05)

Phase 1 baseline: nine real isolated requests against a disposable migrated MySQL fixture; authenticated cases use a real Admin login/session. `cache/test-audit/issue-69-routes-before.json` preserves the pre-core baseline: all nine outcomes correct, 536 application includes each, 38 MiB peak (media 40 MiB). Three extra declaration files compared with the original 533 bootstrap baseline were already present for the narrow updater request split and probe/front-controller boundary.

Phase 2: namespace autoloader and six concrete runtime objects now exist; focused kernel tests passed and are registered in the central quick profile. Request wraps the existing normalized route; existing handlers retain response ownership. No Response abstraction is needed for this boundary.

Phase 3 measured complete request results (`cache/test-audit/issue-69-routes-phase3.json`): robots 367/26 MiB, home 386/26 MiB, gallery 401/28 MiB, thumb 374/26 MiB, media 374/28 MiB, Admin 383/28 MiB, telemetry 373/26 MiB. Anonymous Admin/telemetry refusals remain 302. All nine measurements PASS. Bootstrap-only: 357 includes / 24 MiB, down from 533 / 36 MiB. Controllers/views no longer load through their global umbrellas. Full compatibility entrypoint exists; real lifecycle now belongs to Kernel.

Phase 4 intermediate stage: services umbrella removed, temporarily retaining all models to isolate the next migration. Mandatory request/auth/schema policy loads before its calls; viewer restoration remains unconditional to preserve disabled-feature identity cleanup. Telemetry observer loads before the first query. Updater due work and archive/site shutdown work have explicit injected loader callbacks at their existing execution boundaries. The request diagnostics and gallery benchmark opt-ins remain supported.

Review findings being resolved: typed scanner symbol keys must remain compatible with compiler edges; unresolved callback sites require an explicit reviewed policy rather than silent omission. Security dispatcher fixtures inject their declared doubles through the same Kernel dispatcher seam and continue exercising the real preflight.
Phase 4 measured (`cache/test-audit/issue-69-routes-phase4.json`): robots 183/14 MiB, home 236/18, gallery 294/20, thumb 214/16, media 213/18, Admin 259/20, telemetry 208/16. Nine correct success/denial outcomes.

Phase 5 measured (`cache/test-audit/issue-69-routes-phase5.json`): robots 118/12 MiB, home 177/16, gallery 246/20, thumb 153/14, media 151/16, Admin 212/20, telemetry 147/14. Nine correct success/denial outcomes; each success body is nonempty. Bootstrap 24 files/2 MiB; models/migrations/integrity are selective and all former full-bootstrap CLI consumers now request bootstrap_full explicitly.

Central runtime-performance now owns both include-only probes and the nine real route cases. Absent an explicitly owned disposable fixture, the latter are visibly SKIP; no fabricated session/database result is accepted. Final full audit will supply the fixture. Hard guards now cover bootstrap 40 files/16 MiB and bounded per-route count/memory ceilings; wall time remains observational.

Additional review correction: loader realpath containment respects Unix case sensitivity while normalizing Windows casing. Updater archive preflight requires every new runtime entry/class so a partial deployment cannot activate.

### Current changed-files ledger

The complete inventory is maintained in section 15 above.

Final-audit repair record: first full audit executed all nine route cases and 25 Chromium checks successfully. Ten PHP failures exposed nine stale source/header contracts and one early-diagnostic shim redeclaration in the session fixture. Contracts now follow the same responsibilities in their new owners. The early diagnostics file remains front-controller-owned and absent from domain plans. The diagnosed actual-session test passes genuine login, invalid CSRF refusal, remember restoration, contention, late writes and logout. No production access assertion was weakened. Independent audit review additionally made the nine-case registry and list/schema/scope/status envelope mandatory.

Independent final review: security worker found no concrete defect in startup ordering, identity restoration, public preflight or zero-install/PHP8.1 source compatibility. Probe worker reviewed owned fixture identity, callbacks, cleanup and strengthened central matrix validation. Dependency worker isolated eight updater lookup functions into a narrow owner, preserving ID/path checks and legacy entrypoint compatibility. The remaining transitive optional-diagnostics dependency cut was then integrated and verified as recorded below.

Final source freeze: tokenizer distinguishes callable positions from same-named setting/route data. Same-namespace negative cases and first/second callback-argument cases pass. Request maintenance now owns only updates_filesystem, updates_job_lookup and updates_request; no updater installation/worker or Admin Test Run module is pulled by this eligibility path. Opted-in diagnostic recorder APIs are explicit roots, including cookie-free starter requests. Fresh central quick audit PASS: 31 PHP, 24 fast Node, zero strict documentation/MVC/policy findings; nine actual route cases explicitly SKIP without disposable inputs. Final full run now supplies owned MySQL/HTTP and GALLERY_WORKFLOW_BROWSER (the actual wrapper variable), with required coverage; manifest refreshed/check passed at 851 managed files.

First frozen full verification PASS (`20261005-130210-26276`, 275.77 s): 306 PHP, 25 Node, 25 Chromium, 11/11 performance measurements, 134 WinApp with one unrelated skip; zero strict changed-source/MVC/import/mutation findings. Actual database workflow browser journey ran successfully, and both disposable database/files and private MySQL were cleaned. An additional independent compatibility reviewer then identified the full CLI entrypoint's umbrella order differed from the original bootstrap: migrations belonged before models/services and controllers after integrity. `bootstrap_full.php` now preserves that order. Regenerated manifest and a renewed full audit are required for this last compatibility correction; ordinary web route plans are unaffected.

Second full verification PASS (`20261005-131414-54600`): compatibility order correction and private-resource cleanup passed. Independent performance review identified a possible false success from a generic error body with HTTP 200. The probe now validates robots directives, actual product-page markers and raster metadata, after capturing wall/peak measurements; `response_contract_matches` is mandatory in the validator. Focused positive/negative response-contract tests PASS, including generic error pages for all seven successful routes, missing/false semantic evidence, valid robots, image and HTML cases. TESTING.md documents the added guard. One final central run follows these specific review repairs.

# 25. Final implementation report

Issue #69 is implemented and verified in the current checkout. All phases are complete. No commit, tag, push, release package, installer rebuild or publication was performed. The application version remains 0.118.6.

## Architecture and loading

The ordinary path is public/index.php -> early runtime -> minimal bootstrap -> project autoloader/request-local Kernel -> shared observation/session policy -> existing request and pretty-URL normalization -> immutable Request and route metadata -> identity/access/schema preflight -> selected logical modules -> existing procedural controller -> response/shutdown completion. Pretty-path lookups load only their necessary lookup owner. Controllers and views are never globally loaded on this path.

Six concrete Gallery\Core classes own state and boundaries: Kernel owns the lifecycle/current route and injected Router/ModuleLoader; Request snapshots the existing normalized route; Router resolves the registry; RouteRegistry owns canonical definitions and not-found fallback; RouteDefinition holds validated immutable handler/module metadata; ModuleLoader owns completed modules, dependency order and safe includes. The tiny spl_autoload_register implementation handles only validated Core class names from the trusted runtime directory. Existing controllers retain status/header/body ownership, so a new Response hierarchy is unnecessary.

The checked-in plan covers 227 handlers including fallback and 93 logical modules. Routes reference module IDs; modules own transitive controllers, views, services, models and split-module entrypoints. Static analysis and the reviewed dynamic-call inventory run only during development; production reads the shipped plan. Unknown modules, cycles, malformed plans, missing files and escaped paths fail explicitly before including the plan. require_once and request-local state prevent duplicate loading. Dependency class/function kinds remain distinct, and data strings do not create callable edges.

Shared lifecycle modules preserve the database observer, authentication/session policy, Viewer identity restoration and schema/security boundaries. Active-job lookup and automatic-update eligibility use lightweight updates_request/updates_job_lookup owners; only eligible work loads updater-work. Due archive/site maintenance loads its worker at the existing shutdown boundary. Diagnostics and benchmark modules remain opt-in. The request-maintenance closure has 29 files and contains no updater installation/job worker or Admin Test Run implementation.

Umbrella loaders remain supported. bootstrap_full explicitly preserves legacy migrations -> models/services -> views -> integrity -> controllers ordering for the eight CLI consumers and selected test tools. Ordinary web requests have no full-load fallback. Domain SQL, services, views, controllers and pure helpers remain procedural under their existing MVC ownership; this is a small OO runtime, not a domain rewrite.

## Shared-hosting compatibility

| Production prerequisite added by this change | Required |
|---|---|
| Composer | no |
| Node.js | no |
| npm | no |
| Python | no |
| shell / SSH | no |
| Docker | no |

No framework, DI container, daemon or new package installation is required. Existing PHP/extensions/database deployment requirements remain. New syntax is PHP 8.1 compatible by source review; local execution used PHP 8.3.30 because PHP 8.1 is unavailable here.

## Performance evidence

Section 14 contains the complete before/after tables and observational wall times. Bootstrap falls from 533 files/36 MiB to 24/2 MiB. Actual requests fall from 536 files/38–40 MiB to 90–243 files/10–20 MiB. The final captures include public/index.php, cms_run() and shutdown callbacks against a migrated disposable MySQL installation. Authenticated cases use a real login/session; anonymous Admin cases retain HTTP 302. The counted paths exclude harness files; timings exclude fixture/login/network/TLS startup. Body contract validation happens after resource capture. Counts/memory have conservative hard ceilings; wall time has no flaky threshold.

The probe checks route identity, context, outcomes, status, nonempty success bodies, stable product-page markers, robots grammar, recognized raster metadata, safe unique path/count inventory, finite wall time, digest shape and absence of fatal errors. A generic error page returned with HTTP 200 cannot satisfy the successful route contract. The exact nine-case matrix and report schema/scope/status are mandatory; no fixture produces explicit route SKIP, never invented PASS evidence.

## Executed verification

- php tests/runtime_route_probe_test.php: PASS during development of the new semantic response guard. Kernel, dependency, module-plan and probe regressions are registered and all PASS in the final central run.
- php scripts/audit.php --profile=quick: PASS (20261005-125954-34188), 31 PHP and 24 fast Node; actual routes explicitly SKIP without owned fixture.
- php scripts/gallery_workflow_mysql.php --audit: invokes the authoritative php scripts/audit.php --profile=full in the owned migrated fixture. Final run 20261005-132331-51800: PASS, 266.39 s; 306 PHP / 25 Node / 25 Chromium / 11 performance measurements, zero FAIL/BLOCKED or route SKIP. The real database browser journey and real-session contention tests also PASS. WinApp has 134 PASS and one unrelated SKIP.
- Full audit: PHP syntax for 1053 files, JavaScript syntax for 143 files, strict MVC, Admin mutation/runtime contracts, changed documentation/policy and whole-tree Python import policy PASS. There are zero strict changed-source findings; historical source inventory remains advisory debt.
- php scripts/generate_manifest.php and php scripts/generate_manifest.php --check: PASS, 851 managed files.
- git diff --check: PASS. Private MySQL, generated database and fixture files cleaned successfully.

The final wrapper used GALLERY_WORKFLOW_ENABLE=disposable-only, the recorded MySQL executable, GALLERY_WORKFLOW_BROWSER and PHP_GALLERY_BROWSER pointing to Chrome, both required-coverage flags set to 1, and PHP_GALLERY_ROUTE_PROBE_EVIDENCE=after. Evidence is retained in cache/test-audit/20261005-132331-51800/report.json and report.md, plus issue-69-routes-before.json and issue-69-routes-after.json.

## Independent workers and review repairs

Five distinct gpt-6-luna/high workers were used, with at most three concurrently alongside the orchestrator. Their exact scopes and handoffs are in section 12: dependency/architecture, routing/security, probes/test coverage/documentation, independent compatibility and independent performance. Important findings included false callable edges from setting/route strings, heavy updater lookup ownership, optional diagnostic edges, mandatory callback targets, strict nine-case evidence, legacy full-load order, and generic HTTP200 error-body acceptance. All concrete findings were fixed; compatibility and performance reviewers explicitly confirmed their repairs. Final source reviews reported no remaining material defect; execution evidence is the orchestrator's full audit.

## Changed files and follow-ups

Section 15 is the complete 78-file changed/addition ledger, including every runtime class, helper, compiler/probe, CLI consumer, regression fixture, manifest and document. The ledger was compared with git diff --name-only plus git ls-files --others --exclude-standard; there are no missing or extra entries. Permanent architecture/testing guidance is updated in AGENTS.md, ARCHITECTURE.md, CODEMAP.md, README.md and TESTING.md. This TEMP document is retained as requested.

No implementation requirement or newly discovered correctness defect remains from #69. Existing issue #72 still owns broader request/session cleanup. PHP 8.1 execution remains an environment limitation; the existing CI matrix is its runtime verification path. Larger OOP conversion and removal of legitimate compatibility umbrellas are outside this completed change.
